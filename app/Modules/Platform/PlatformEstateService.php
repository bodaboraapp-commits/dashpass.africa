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
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Estate provisioning + lifecycle (Increment 9 §2).
 *
 * `provision()` is a single all-or-nothing transaction: optional SaaS account,
 * estate, default settings, admin user (create-or-link by phone), estate_admin
 * membership and optional plan subscription. A test seam (`$afterEstateCreated`)
 * is invoked right after the estate row exists so a deliberate throw proves the
 * rollback leaves no partial rows. Admin phone is masked in every output.
 */
final class PlatformEstateService
{
    /** @var array<string, int> Default setting values written for a new estate. */
    private const DEFAULT_SETTINGS = [
        'visitor_validity_minutes' => 240,
        'visitor_parking_grace_minutes' => 30,
        'parking_overstay_grace_minutes' => 30,
    ];

    private const TRIAL_DAYS = 14;

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
    public function statusOptions(): array
    {
        return $this->presenter->estateStatuses();
    }

    /**
     * @return array<string, mixed>
     */
    public function list(?string $status = null): array
    {
        $status = $status !== null && in_array($status, PlatformPresenter::ESTATE_STATUSES, true)
            ? $status
            : null;
        $estates = $this->repo->estates($status);
        $estateIds = array_map(static fn (array $row): int => (int) $row['id'], $estates);
        $subscriptions = $this->subscriptionSummaries($estateIds);

        return [
            'estates' => array_map(
                function (array $row) use ($subscriptions): array {
                    $shape = PlatformPresenter::estate($row);
                    $summary = $subscriptions[(int) $row['id']] ?? null;

                    if ($summary !== null) {
                        $shape['plan_name'] = $summary['plan_name'];
                        $shape['subscription_status'] = $summary['status'];
                        $shape['subscription_status_label'] = PlatformPresenter::subscriptionStatusLabel($summary['status']);
                    }

                    return $shape;
                },
                $estates
            ),
            'filters' => ['status' => $status ?? 'all'],
            'status_options' => $this->statusOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        return [
            'accounts' => PlatformPresenter::accountOptions($this->repo->accounts()),
            'plans' => PlatformPresenter::planOptions($this->repo->activePlans()),
            'estate_types' => $this->presenter->estateTypes(),
            'timezones' => $this->presenter->timezones(),
        ];
    }

    /**
     * Frozen estate detail + admin + latest subscription + status options.
     *
     * @return array<string, mixed>
     */
    public function show(string $uuid): array
    {
        $estate = $this->repo->findEstate($uuid);

        if ($estate === null) {
            throw HttpException::notFound('ESTATE_NOT_FOUND', 'That estate is not available.');
        }

        $shape = PlatformPresenter::estate($estate);
        $subscription = null;
        $estateId = (int) $estate['id'];
        $subscriptionId = $estate['subscription_id'] === null ? null : (int) $estate['subscription_id'];

        if ($subscriptionId !== null) {
            $row = $this->repo->findSubscription((string) ($estate['subscription_uuid'] ?? ''));
            $subscription = $row === null ? null : PlatformPresenter::subscription($row);
        }

        return [
            'estate' => $shape,
            'account' => [
                'uuid' => (string) ($estate['account_uuid'] ?? ''),
                'name' => (string) ($estate['account_name'] ?? ''),
                'status' => (string) ($estate['account_status'] ?? 'active'),
            ],
            'admin' => [
                'uuid' => $estate['admin_uuid'] === null ? null : (string) $estate['admin_uuid'],
                'full_name' => (string) ($estate['admin_name'] ?? ''),
                'phone_masked' => (string) $shape['admin_phone_masked'],
            ],
            'subscription' => $subscription,
            'status_options' => $this->statusOptions(),
            'estate_id' => $estateId,
        ];
    }

    /**
     * Provision an estate atomically.
     *
     * @param array<string, mixed> $input
     * @param (callable(array<string, mixed>): void)|null $afterEstateCreated
     *        Test seam invoked after the estate row is inserted; throwing from it
     *        must roll the whole provisioning transaction back.
     * @return array<string, mixed>
     */
    public function provision(array $input, ?callable $afterEstateCreated = null): array
    {
        $clean = $this->validateProvision($input);
        $this->assertTimezone((string) $clean['timezone']);
        $slug = $this->slugify((string) $clean['estate_name']);

        if ($slug === '') {
            throw new ValidationException(['estate_name' => ['Enter an estate name that can form a web slug.']]);
        }

        if ($this->repo->estateBySlug($slug) !== null) {
            throw new HttpException(
                422,
                'ESTATE_EXISTS',
                'An estate with that name already exists.',
                ['errors' => ['estate_name' => ['An estate with that name already exists.']]]
            );
        }

        $account = null;

        if (isset($clean['account_uuid']) && is_string($clean['account_uuid']) && $clean['account_uuid'] !== '') {
            $account = $this->repo->account($clean['account_uuid']);

            if ($account === null) {
                throw new ValidationException(['account_uuid' => ['Select an existing account or create a new one.']]);
            }
        }

        $plan = null;

        if (isset($clean['plan_uuid']) && is_string($clean['plan_uuid']) && $clean['plan_uuid'] !== '') {
            $plan = $this->repo->findPlan($clean['plan_uuid']);

            if ($plan === null || (string) $plan['status'] !== 'active') {
                throw new ValidationException(['plan_uuid' => ['Select an active subscription plan.']]);
            }
        }

        $estateUuid = '';
        $now = Clock::nowDb();

        $this->db->transaction(function () use (
            $clean,
            $slug,
            $account,
            $plan,
            $afterEstateCreated,
            $now,
            &$estateUuid
        ): void {
            $accountId = null;
            $accountUuid = null;

            if (is_array($account)) {
                $accountId = (int) $account['id'];
                $accountUuid = (string) $account['uuid'];
            } else {
                $accountUuid = Uuid::v4();
                $accountId = $this->repo->insertAccount([
                    'uuid' => $accountUuid,
                    'name' => trim((string) $clean['account_name']),
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $estateUuid = Uuid::v4();
            $estateId = $this->repo->insertEstate([
                'uuid' => $estateUuid,
                'account_id' => $accountId,
                'name' => trim((string) $clean['estate_name']),
                'slug' => $slug,
                'timezone' => (string) $clean['timezone'],
                'estate_type' => (string) $clean['estate_type'],
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($afterEstateCreated !== null) {
                $afterEstateCreated([
                    'estate_id' => $estateId,
                    'estate_uuid' => $estateUuid,
                    'account_id' => $accountId,
                    'account_uuid' => $accountUuid,
                ]);
            }

            foreach (self::DEFAULT_SETTINGS as $key => $value) {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

                $this->repo->insertEstateSetting([
                    'estate_id' => $estateId,
                    'setting_key' => $key,
                    'setting_value' => $encoded === false ? (string) $value : $encoded,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $adminUser = $this->repo->findUserByPhone((string) $clean['admin_phone']);
            $adminUserUuid = '';
            $adminUserId = 0;

            if ($adminUser === null) {
                $adminUserUuid = Uuid::v4();
                $adminUserId = $this->repo->insertUser([
                    'uuid' => $adminUserUuid,
                    'full_name' => trim((string) $clean['admin_full_name']),
                    'phone' => (string) $clean['admin_phone'],
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $adminUserId = (int) $adminUser['id'];
                $adminUserUuid = (string) $adminUser['uuid'];
            }

            $roleId = $this->repo->roleId('estate', 'estate_admin');

            if ($roleId === null) {
                throw new HttpException(500, 'ROLE_MISSING', 'The estate-admin role is not configured.');
            }

            $this->repo->insertEstateMembership([
                'uuid' => Uuid::v4(),
                'estate_id' => $estateId,
                'user_id' => $adminUserId,
                'role_id' => $roleId,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (is_array($plan)) {
                if ($this->repo->estateHasSubscription($estateId)) {
                    throw new HttpException(422, 'SUBSCRIPTION_EXISTS', 'That estate already has a subscription.');
                }

                $trialEnds = Clock::now()->modify('+' . self::TRIAL_DAYS . ' days');
                $subscriptionUuid = Uuid::v4();
                $this->repo->insertSubscription([
                    'uuid' => $subscriptionUuid,
                    'estate_id' => $estateId,
                    'plan_id' => (int) $plan['id'],
                    'status' => 'trialing',
                    'billing_cycle' => (string) ($clean['billing_cycle'] ?? 'monthly'),
                    'starts_at' => $now,
                    'ends_at' => null,
                    'trial_ends_at' => Clock::toDb($trialEnds),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->audit('platform.subscription_created', 'subscription', [
                    'subscription_uuid' => $subscriptionUuid,
                    'estate_uuid' => $estateUuid,
                    'plan_code' => (string) $plan['code'],
                    'status' => 'trialing',
                ]);
            }

            $this->audit('platform.estate_created', 'estate', [
                'estate_uuid' => $estateUuid,
                'account_uuid' => $accountUuid,
                'slug' => $slug,
                'estate_type' => (string) $clean['estate_type'],
                'admin_user_uuid' => $adminUserUuid,
                'plan_code' => is_array($plan) ? (string) $plan['code'] : null,
            ]);
        });

        return $this->show($estateUuid);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateStatus(string $uuid, string $status): array
    {
        $estate = $this->repo->findEstate($uuid);

        if ($estate === null) {
            throw HttpException::notFound('ESTATE_NOT_FOUND', 'That estate is not available.');
        }

        $clean = Validator::validate(
            ['status' => $status],
            ['status' => 'required|in:' . implode(',', PlatformPresenter::ESTATE_STATUSES)],
            ['status' => 'status']
        );
        $target = (string) $clean['status'];
        $current = (string) $estate['status'];

        if ($target !== $current) {
            $this->repo->updateEstate((int) $estate['id'], [
                'status' => $target,
                'updated_at' => Clock::nowDb(),
            ]);

            $this->audit('platform.estate_status_changed', 'estate', [
                'estate_uuid' => (string) $estate['uuid'],
                'old_status' => $current,
                'new_status' => $target,
            ]);
        }

        return $this->show((string) $estate['uuid']);
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateProvision(array $input): array
    {
        $clean = Validator::validate($input, [
            'account_uuid' => 'nullable|uuid',
            'account_name' => 'nullable|string|min:2|max:180',
            'estate_name' => 'required|string|min:2|max:180',
            'timezone' => 'required|string|max:80',
            'estate_type' => 'required|in:' . implode(',', PlatformPresenter::ESTATE_TYPES),
            'admin_full_name' => 'required|string|min:2|max:180',
            'admin_phone' => 'required|phone',
            'plan_uuid' => 'nullable|uuid',
            'billing_cycle' => 'nullable|in:monthly,annual',
        ], [
            'account_uuid' => 'account',
            'account_name' => 'account name',
            'estate_name' => 'estate name',
            'timezone' => 'timezone',
            'estate_type' => 'estate type',
            'admin_full_name' => 'administrator name',
            'admin_phone' => 'administrator phone',
            'plan_uuid' => 'plan',
            'billing_cycle' => 'billing cycle',
        ]);

        $hasAccount = isset($clean['account_uuid']) && is_string($clean['account_uuid']) && $clean['account_uuid'] !== '';
        $hasAccountName = isset($clean['account_name']) && is_string($clean['account_name']) && trim($clean['account_name']) !== '';

        if (!$hasAccount && !$hasAccountName) {
            throw new ValidationException([
                'account_uuid' => ['Select an existing account or enter a new account name.'],
            ]);
        }

        return $clean;
    }

    private function assertTimezone(string $timezone): void
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new ValidationException(['timezone' => ['Select a valid timezone.']]);
        }
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return substr($slug, 0, 180);
    }

    /**
     * @param array<int, int> $estateIds
     * @return array<int, array{plan_name: string, status: string}>
     */
    private function subscriptionSummaries(array $estateIds): array
    {
        if ($estateIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($estateIds), '?'));
        $rows = $this->db->select(
            'SELECT s.estate_id, p.name AS plan_name, s.status
             FROM subscriptions s
             INNER JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.estate_id IN (' . $placeholders . ')
             ORDER BY s.starts_at DESC, s.id DESC',
            $estateIds
        );

        $summaries = [];

        foreach ($rows as $row) {
            $estateId = (int) $row['estate_id'];

            if (!isset($summaries[$estateId])) {
                $summaries[$estateId] = [
                    'plan_name' => (string) $row['plan_name'],
                    'status' => (string) $row['status'],
                ];
            }
        }

        return $summaries;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, string $entityType, array $metadata): void
    {
        $this->context->audit->record($action, $entityType, null, $metadata, null);
    }
}
