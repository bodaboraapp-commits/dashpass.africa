<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Clock;
use App\Core\Context;
use App\Core\HttpException;
use App\Core\Uuid;
use App\Core\ValidationException;

/**
 * Platform billing schedule management (Inc-13 P2/P3 UI backend). Existing
 * billing_schedules table only; no new tables/permissions.
 */
final class PlatformBillingScheduleService
{
    private const SCOPES = ['estate_subscription', 'utility_bill'];
    private const PERIODS = ['month', 'quarter', 'year', 'week', 'once'];
    private const STATUSES = ['active', 'paused', 'archived'];

    public function __construct(private readonly Context $context)
    {
    }

    public static function make(Context $context): self
    {
        return new self($context);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $status = null, ?string $scope = null): array
    {
        $sql = "SELECT bs.*, e.name AS estate_name, e.uuid AS estate_uuid,
                       s.uuid AS subscription_uuid, p.name AS plan_name,
                       h.unit_number AS household_unit
                FROM billing_schedules bs
                INNER JOIN estates e ON e.id = bs.estate_id
                LEFT JOIN subscriptions s ON s.id = bs.subscription_id
                LEFT JOIN subscription_plans p ON p.id = s.plan_id
                LEFT JOIN households h ON h.id = bs.household_id
                WHERE 1=1";
        $params = [];

        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' AND bs.status = :status';
            $params['status'] = $status;
        }

        if ($scope !== null && in_array($scope, self::SCOPES, true)) {
            $sql .= ' AND bs.scope = :scope';
            $params['scope'] = $scope;
        }

        return $this->context->db->select($sql . ' ORDER BY bs.next_run_at ASC, bs.id ASC', $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function find(string $uuid): array
    {
        $row = $uuid === '' ? null : $this->context->db->selectOne(
            "SELECT bs.*, e.name AS estate_name, e.uuid AS estate_uuid,
                    s.uuid AS subscription_uuid, p.name AS plan_name, h.unit_number AS household_unit
             FROM billing_schedules bs
             INNER JOIN estates e ON e.id = bs.estate_id
             LEFT JOIN subscriptions s ON s.id = bs.subscription_id
             LEFT JOIN subscription_plans p ON p.id = s.plan_id
             LEFT JOIN households h ON h.id = bs.household_id
             WHERE bs.uuid = :uuid LIMIT 1",
            ['uuid' => $uuid]
        );

        if ($row === null) {
            throw HttpException::notFound('SCHEDULE_NOT_FOUND', 'That billing schedule is not available.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        return [
            'estates' => $this->context->db->select('SELECT id, uuid, name FROM estates ORDER BY name'),
            'subscriptions' => $this->context->db->select(
                "SELECT s.id, s.uuid, s.estate_id, e.name AS estate_name, p.name AS plan_name
                 FROM subscriptions s INNER JOIN estates e ON e.id=s.estate_id
                 INNER JOIN subscription_plans p ON p.id=s.plan_id ORDER BY e.name, p.name"
            ),
            'households' => $this->context->db->select(
                "SELECT h.id, h.uuid, h.estate_id, h.unit_number FROM households h WHERE h.status='active' ORDER BY h.unit_number"
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $data = $this->validate($input);
        $uuid = Uuid::v4();
        $now = Clock::nowDb();
        $this->context->db->insert('billing_schedules', $data + [
            'uuid' => $uuid,
            'created_by_user_id' => $this->context->auth->id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit('billing.schedule_created', $uuid, [
            'scope' => $data['scope'],
            'estate_id' => $data['estate_id'],
            'period_unit' => $data['period_unit'],
        ]);

        return $this->find($uuid);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(string $uuid, array $input): array
    {
        $row = $this->find($uuid);
        $data = $this->validate($input, $row);
        $this->context->db->update('billing_schedules', $data + [
            'updated_by_user_id' => $this->context->auth->id(),
            'updated_at' => Clock::nowDb(),
        ], ['id' => (int) $row['id']]);
        $this->audit('billing.schedule_updated', $uuid, ['scope' => $data['scope']]);

        return $this->find($uuid);
    }

    /**
     * @return array<string, mixed>
     */
    public function toggle(string $uuid): array
    {
        $row = $this->find($uuid);
        $status = (string) $row['status'] === 'active' ? 'paused' : 'active';
        $this->context->db->update('billing_schedules', [
            'status' => $status,
            'updated_by_user_id' => $this->context->auth->id(),
            'updated_at' => Clock::nowDb(),
        ], ['id' => (int) $row['id']]);
        $this->audit('billing.schedule_toggled', $uuid, ['status' => $status]);

        return $this->find($uuid);
    }

    /**
     * Dry-run: return what the next automation run would inspect; never writes
     * invoices or mutates the schedule.
     *
     * @return array<string, mixed>
     */
    public function runNowPreview(string $uuid): array
    {
        $row = $this->find($uuid);
        $this->audit('billing.schedule_run_preview', $uuid, ['scope' => (string) $row['scope']]);

        return [
            'schedule' => $row,
            'dry_run' => true,
            'next_run_at' => (string) $row['next_run_at'],
            'scope' => (string) $row['scope'],
            'message' => 'Dry-run only: no invoices were generated.',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validate(array $input, ?array $existing = null): array
    {
        $errors = [];
        $scope = (string) ($input['scope'] ?? $existing['scope'] ?? 'estate_subscription');
        $period = (string) ($input['period_unit'] ?? $existing['period_unit'] ?? 'month');
        $status = (string) ($input['status'] ?? $existing['status'] ?? 'active');

        if (!in_array($scope, self::SCOPES, true)) {
            $errors['scope'] = ['Choose a subscription or utility schedule.'];
        }

        if (!in_array($period, self::PERIODS, true)) {
            $errors['period_unit'] = ['Choose month, quarter, year, week or once.'];
        }

        if (!in_array($status, self::STATUSES, true)) {
            $errors['status'] = ['Choose active, paused or archived.'];
        }

        $estateUuid = (string) ($input['estate_uuid'] ?? '');
        $estate = $estateUuid === '' ? null : $this->context->db->selectOne('SELECT id FROM estates WHERE uuid=:u', ['u' => $estateUuid]);

        if ($estate === null) {
            $errors['estate_uuid'] = ['Choose a valid estate.'];
        }

        $ranges = ['generate_lead_days' => [0, 30], 'due_days' => [0, 60], 'reminder_before_days' => [0, 30]];

        foreach ($ranges as $field => [$min, $max]) {
            $value = $input[$field] ?? $existing[$field] ?? 0;

            if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
                $errors[$field] = [sprintf('Enter a value between %d and %d.', $min, $max)];
            }
        }

        $nextRun = (string) ($input['next_run_at'] ?? $existing['next_run_at'] ?? '');
        $nextRunAt = $nextRun === '' ? Clock::now()->modify('+1 day') : \App\Core\Clock::fromDb(str_replace('T', ' ', $nextRun));

        if ($nextRunAt === null) {
            $errors['next_run_at'] = ['Enter a valid next-run date/time.'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'scope' => $scope,
            'estate_id' => (int) $estate['id'],
            'subscription_id' => isset($input['subscription_id']) && $input['subscription_id'] !== '' ? (int) $input['subscription_id'] : null,
            'household_id' => isset($input['household_id']) && $input['household_id'] !== '' ? (int) $input['household_id'] : null,
            'name' => mb_substr(trim((string) ($input['name'] ?? '')), 0, 120),
            'utility_type' => in_array(($input['utility_type'] ?? null), ['water', 'electricity', 'gas', 'other'], true) ? (string) $input['utility_type'] : null,
            'flat_amount_cents' => (int) ($input['flat_amount_cents'] ?? 0),
            'tariff_cents_per_unit' => (int) ($input['tariff_cents_per_unit'] ?? 0),
            'currency' => 'KES',
            'period_unit' => $period,
            'generate_lead_days' => (int) ($input['generate_lead_days'] ?? 0),
            'due_days' => (int) ($input['due_days'] ?? 14),
            'reminder_before_days' => (int) ($input['reminder_before_days'] ?? 3),
            'reminder_on_due' => !empty($input['reminder_on_due']) ? 1 : 0,
            'status' => $status,
            'next_run_at' => \App\Core\Clock::toDb($nextRunAt),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, string $uuid, array $metadata): void
    {
        try {
            $this->context->audit->record($action, 'billing_schedule', null, ['schedule_uuid' => $uuid] + $metadata);
        } catch (\Throwable) {
        }
    }
}
