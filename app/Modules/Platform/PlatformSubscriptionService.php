<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Core\ValidationException;

/**
 * Platform subscription management (Increment 9 §2).
 *
 * Plan changes require an active plan; status may move between non-terminal
 * values, cancelled/expired are terminal (409 SUBSCRIPTION_CLOSED). No deletes.
 */
final class PlatformSubscriptionService
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
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return [
            'subscriptions' => array_map(
                static fn (array $row): array => PlatformPresenter::subscription($row),
                $this->repo->subscriptions()
            ),
            'plans' => PlatformPresenter::planOptions($this->repo->activePlans()),
            'status_options' => $this->presenter->subscriptionStatuses(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(string $uuid, array $input): array
    {
        $subscription = $this->repo->findSubscription($uuid);

        if ($subscription === null) {
            throw HttpException::notFound('SUBSCRIPTION_NOT_FOUND', 'That subscription is not available.');
        }

        $currentStatus = (string) $subscription['status'];

        if (PlatformPresenter::isTerminalSubscription($currentStatus)) {
            throw new HttpException(409, 'SUBSCRIPTION_CLOSED', 'This subscription is closed and cannot be changed.', [
                'errors' => ['status' => ['This subscription is closed and cannot be changed.']],
            ]);
        }

        $clean = Validator::validate($input, [
            'status' => 'nullable|in:' . implode(',', PlatformPresenter::SUBSCRIPTION_STATUSES),
            'plan_uuid' => 'nullable|uuid',
        ], ['status' => 'status', 'plan_uuid' => 'plan']);

        $targetStatus = isset($clean['status']) && is_string($clean['status']) ? (string) $clean['status'] : null;
        $planUuid = isset($clean['plan_uuid']) && is_string($clean['plan_uuid']) && $clean['plan_uuid'] !== ''
            ? (string) $clean['plan_uuid']
            : null;

        if ($targetStatus === null && $planUuid === null) {
            throw new ValidationException(['status' => ['Choose a new plan or status to update.']]);
        }

        $fields = [];

        if ($planUuid !== null) {
            $plan = $this->repo->findPlan($planUuid);

            if ($plan === null || (string) $plan['status'] !== 'active') {
                throw new ValidationException(['plan_uuid' => ['Select an active subscription plan.']]);
            }

            $fields['plan_id'] = (int) $plan['id'];
        }

        $endsAt = null;

        if ($targetStatus !== null) {
            $fields['status'] = $targetStatus;

            if (PlatformPresenter::isTerminalSubscription($targetStatus)) {
                $endsAt = Clock::nowDb();
                $fields['ends_at'] = $endsAt;
            }
        }

        $fields['updated_at'] = Clock::nowDb();
        $this->repo->updateSubscription((int) $subscription['id'], $fields);

        $updated = $this->repo->findSubscription($uuid);

        $this->audit('platform.subscription_changed', 'subscription', [
            'subscription_uuid' => (string) $subscription['uuid'],
            'estate_uuid' => (string) $subscription['estate_uuid'],
            'old_status' => $currentStatus,
            'new_status' => $updated === null ? $currentStatus : (string) $updated['status'],
            'plan_code' => $updated === null ? (string) $subscription['plan_code'] : (string) $updated['plan_code'],
        ]);

        if ($updated === null) {
            throw HttpException::notFound('SUBSCRIPTION_NOT_FOUND', 'That subscription is not available.');
        }

        return PlatformPresenter::subscription($updated);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, string $entityType, array $metadata): void
    {
        $this->context->audit->record($action, $entityType, null, $metadata, null);
    }
}
