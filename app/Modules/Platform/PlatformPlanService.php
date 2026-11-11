<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Uuid;
use App\Core\Validator;
use App\Core\ValidationException;
use PDOException;

/**
 * Subscription plan management (Increment 9 §2).
 *
 * Plans are never deleted: `retire` sets status retired (existing subscriptions
 * keep working, retired plans are not selectable for new assignments).
 */
final class PlatformPlanService
{
    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly PlatformRepository $repo,
        private readonly PlatformPresenter $presenter
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db, new PlatformRepository($context->db), new PlatformPresenter());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return array_map(
            static fn (array $row): array => PlatformPresenter::plan($row),
            $this->repo->plans()
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $clean = Validator::validate($input, [
            'code' => 'required|string|min:3|max:40',
            'name' => 'required|string|min:2|max:120',
            'monthly_price_cents' => 'required|integer|between:0,4294967295',
            'currency' => 'required|string|min:3|max:3',
            'household_limit' => 'nullable|integer|between:0,4294967295',
            'gate_device_limit' => 'nullable|integer|between:0,4294967295',
        ], [
            'code' => 'plan code',
            'name' => 'plan name',
            'monthly_price_cents' => 'monthly price',
            'currency' => 'currency',
            'household_limit' => 'household limit',
            'gate_device_limit' => 'gate device limit',
        ]);

        $code = strtoupper(trim((string) $clean['code']));

        if (preg_match('/^[A-Z0-9_]{3,40}$/', $code) !== 1) {
            throw new ValidationException([
                'code' => ['Use 3-40 uppercase letters, numbers or underscores.'],
            ]);
        }

        if ($this->repo->findPlanByCode($code) !== null) {
            throw new HttpException(
                422,
                'PLAN_EXISTS',
                'A plan with that code already exists.',
                ['errors' => ['code' => ['A plan with that code already exists.']]]
            );
        }

        $currency = strtoupper(trim((string) $clean['currency']));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ValidationException(['currency' => ['Use a 3-letter currency code.']]);
        }

        $features = $this->normalizeFeatures($input['features'] ?? null);
        $featuresJson = $features === [] ? null : json_encode($features, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($featuresJson === false) {
            throw new ValidationException(['features' => ['The plan features could not be saved.']]);
        }

        $uuid = Uuid::v4();
        $now = Clock::nowDb();

        try {
            $this->repo->insertPlan([
                'uuid' => $uuid,
                'code' => $code,
                'name' => trim((string) $clean['name']),
                'monthly_price_cents' => (int) $clean['monthly_price_cents'],
                'currency' => $currency,
                'household_limit' => isset($clean['household_limit']) && $clean['household_limit'] !== null
                    ? (int) $clean['household_limit'] : null,
                'gate_device_limit' => isset($clean['gate_device_limit']) && $clean['gate_device_limit'] !== null
                    ? (int) $clean['gate_device_limit'] : null,
                'features' => $featuresJson,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            // UNIQUE(subscription_plans.code) backstop for a concurrent create.
            if ((string) $exception->getCode() === '23000') {
                throw new HttpException(
                    422,
                    'PLAN_EXISTS',
                    'A plan with that code already exists.',
                    ['errors' => ['code' => ['A plan with that code already exists.']]]
                );
            }

            throw $exception;
        }

        $this->audit('platform.plan_created', 'subscription_plan', [
            'plan_uuid' => $uuid,
            'code' => $code,
            'status' => 'active',
        ]);

        return $this->show($uuid);
    }

    /**
     * @return array<string, mixed>
     */
    public function retire(string $uuid): array
    {
        $plan = $this->repo->findPlan($uuid);

        if ($plan === null) {
            throw HttpException::notFound('PLAN_NOT_FOUND', 'That subscription plan is not available.');
        }

        if ((string) $plan['status'] !== 'retired') {
            $this->repo->updatePlan((int) $plan['id'], [
                'status' => 'retired',
                'updated_at' => Clock::nowDb(),
            ]);

            $this->audit('platform.plan_retired', 'subscription_plan', [
                'plan_uuid' => (string) $plan['uuid'],
                'code' => (string) $plan['code'],
                'status' => 'retired',
            ]);
        }

        return $this->show((string) $plan['uuid']);
    }

    /**
     * @return array<string, mixed>
     */
    private function show(string $uuid): array
    {
        $plan = $this->repo->findPlan($uuid);

        if ($plan === null) {
            throw HttpException::notFound('PLAN_NOT_FOUND', 'That subscription plan is not available.');
        }

        return PlatformPresenter::plan($plan);
    }

    /**
     * Normalise a features map from either an array or `key: value` textarea lines.
     *
     * @return array<string, scalar>
     */
    private function normalizeFeatures(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $pairs = [];

        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $pairs[trim($key)] = $value;
                }
            }
        } elseif (is_string($raw)) {
            foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $separator = strpos($line, ':');

                if ($separator === false) {
                    $separator = strpos($line, '=');
                }

                if ($separator === false) {
                    throw new ValidationException([
                        'features' => ['Each feature must be written as "key: value".'],
                    ]);
                }

                $key = trim(substr($line, 0, $separator));
                $value = trim(substr($line, $separator + 1));
                $pairs[$key] = $value;
            }
        } else {
            throw new ValidationException(['features' => ['Enter the plan features as "key: value" lines.']]);
        }

        foreach ($pairs as $key => $value) {
            if (preg_match('/^[a-z0-9_.-]{1,40}$/i', (string) $key) !== 1 || mb_strlen((string) $value) > 200) {
                throw new ValidationException(['features' => ['Feature names must be short keys with values under 200 characters.']]);
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, string $entityType, array $metadata): void
    {
        $this->context->audit->record($action, $entityType, null, $metadata, null);
    }
}
