<?php

declare(strict_types=1);

namespace App\Modules\Billing\Automation;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Database;
use App\Core\Uuid;
use App\Modules\Billing\BillingRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Throwable;

/**
 * Inc-13 P1: idempotent subscription invoice generation.
 *
 * Generates `billing_invoices` rows from `billing_schedules` (scope
 * estate_subscription) using the frozen UTC period keys, lead/due defaults,
 * plan price snapshots and generation keys. Sending/reminders/utility bills are
 * later slices. One run writes an audit-only `billing.automation_run` summary;
 * there is no automation-runs table.
 */
final class BillingAutomationService
{
    private readonly AutomationPeriodCalculator $periods;

    private readonly BillingRepository $billing;

    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        ?AutomationPeriodCalculator $periods = null,
        ?BillingRepository $billing = null
    ) {
        $this->periods = $periods ?? new AutomationPeriodCalculator();
        $this->billing = $billing ?? new BillingRepository($db);
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db);
    }

    /**
     * @param array{dry_run?:bool,date?:DateTimeImmutable,scope?:string,estate_uuid?:?string,limit?:?int,max_catchup?:?int} $options
     * @return array<string, mixed>
     */
    public function run(array $options): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $asOf = $options['date'] ?? Clock::now();
        $asOf = $asOf->setTimezone(new DateTimeZone('UTC'));
        $scope = (string) ($options['scope'] ?? 'all');
        $estateUuid = $options['estate_uuid'] ?? null;
        $limit = max(1, (int) ($options['limit'] ?? Config::get('billing.automation.max_generate', 50)));
        $maxCatchup = max(1, min(24, (int) ($options['max_catchup'] ?? Config::get('billing.automation.max_catchup', 6))));
        $runUuid = Uuid::v4();
        $started = microtime(true);

        $counts = [
            'scanned' => 0,
            'generated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $skips = [];

        if (!(bool) Config::get('billing.automation.enabled', false)) {
            return [
                'dry_run' => $dryRun,
                'run_uuid' => $runUuid,
                'date' => $asOf->format('Y-m-d'),
                'scope' => $scope,
                'status' => 'disabled',
                'counts' => $counts,
                'skips' => $skips,
                'lock' => 'acquired',
            ];
        }

        if (in_array($scope, ['send', 'deliveries', 'reminders'], true)) {
            // P2+ slices: no-op in P1 so the frozen CLI can be wired early.
            return [
                'dry_run' => $dryRun,
                'run_uuid' => $runUuid,
                'date' => $asOf->format('Y-m-d'),
                'scope' => $scope,
                'status' => 'success',
                'counts' => $counts,
                'skips' => $skips,
                'lock' => 'acquired',
            ];
        }

        $subscriptions = $this->activeSubscriptions($estateUuid);

        if ($dryRun) {
            $schedules = [];

            foreach ($subscriptions as $subscription) {
                $schedule = $this->existingSchedule((int) $subscription['id']);

                if ($schedule === null) {
                    $schedule = $this->virtualSchedule($subscription, $asOf);
                }

                $schedules[] = $schedule + ['subscription' => $subscription];
            }
        } else {
            $this->bootstrapSchedules($subscriptions, $asOf);
            $schedules = $this->dueSchedules($asOf, $estateUuid);
        }

        $generated = 0;
        $limitReached = false;
        $partial = false;

        if ($scope === 'utility') {
            return [
                'dry_run' => $dryRun,
                'run_uuid' => $runUuid,
                'date' => $asOf->format('Y-m-d'),
                'scope' => $scope,
                'status' => 'unsupported',
                'counts' => $counts,
                'skips' => $skips,
                'lock' => 'acquired',
            ];
        }

        foreach ($schedules as $schedule) {
            $subscription = $schedule['subscription'] ?? $this->subscriptionForSchedule($schedule);
            $estateId = (int) $schedule['estate_id'];
            $subscriptionId = (int) $schedule['subscription_id'];
            $counts['scanned']++;
            $lastGeneratedKey = null;

            if (($subscription['estate_status'] ?? '') === 'archived'
                || ($subscription['estate_status'] ?? '') === 'suspended') {
                $counts['skipped']++;
                $skips['estate_' . (string) ($subscription['estate_status'] ?? 'inactive')] =
                    ($skips['estate_' . (string) ($subscription['estate_status'] ?? 'inactive')] ?? 0) + 1;
                continue;
            }

            $subscriptionStatus = (string) ($subscription['status'] ?? '');

            if (!in_array($subscriptionStatus, ['active', 'past_due'], true)) {
                $counts['skipped']++;
                $key = 'subscription_' . $subscriptionStatus;
                $skips[$key] = ($skips[$key] ?? 0) + 1;
                continue;
            }

            $unit = $this->periods->unitForCycle((string) ($subscription['billing_cycle'] ?? 'monthly'));
            $leadDays = max(0, (int) ($schedule['generate_lead_days'] ?? Config::get('billing.automation.lead_days', 0)));
            $dueDays = max(0, (int) ($schedule['due_days'] ?? Config::get('billing.automation.due_days', 14)));
            $cursor = Clock::fromDb((string) $schedule['next_run_at']);
            $processed = 0;

            if ($cursor === null) {
                continue;
            }

            while ($cursor <= $asOf && $processed < $maxCatchup && !$limitReached) {
                $periodStart = $cursor->modify('+' . $leadDays . ' days')->setTime(0, 0);
                $period = $this->periods->period($unit, $periodStart);
                $key = $period['key'];
                $periodStart = $period['start'];
                $periodEnd = $period['end'];
                $processed++;

                $generationKey = 'sub:' . (string) $subscription['uuid'] . ':' . $key;
                $skipReason = $this->skipReason(
                    (int) $subscription['id'],
                    $estateId,
                    $generationKey,
                    $periodStart->format('Y-m-d'),
                    $periodEnd->format('Y-m-d')
                );

                if ($skipReason !== null) {
                    if ($skipReason !== 'exists') {
                        $counts['skipped']++;
                        $skips[$skipReason] = ($skips[$skipReason] ?? 0) + 1;
                    }

                    $cursor = $this->nextRun($periodStart, $unit, $leadDays);

                    continue;
                }

                $amount = (int) ($subscription['monthly_price_cents'] ?? 0);

                if ($amount <= 0) {
                    $counts['skipped']++;
                    $skips['zero_amount'] = ($skips['zero_amount'] ?? 0) + 1;
                    $cursor = $this->nextRun($periodStart, $unit, $leadDays);

                    continue;
                }

                if ($generated >= $limit) {
                    $limitReached = true;
                    $partial = true;
                    break;
                }

                if ($dryRun) {
                    $counts['generated']++;
                    $generated++;
                    $cursor = $this->nextRun($periodStart, $unit, $leadDays);

                    continue;
                }

                try {
                    $invoiceUuid = $this->generateInvoice(
                        $subscription,
                        $schedule,
                        $period,
                        $generationKey,
                        $amount,
                        $dueDays,
                        $asOf
                    );

                    if ($invoiceUuid === null) {
                        $counts['skipped']++;
                        $skips['duplicate'] = ($skips['duplicate'] ?? 0) + 1;
                    } else {
                        $counts['generated']++;
                        $generated++;
                        $lastGeneratedKey = $key;
                    }
                } catch (Throwable $exception) {
                    $counts['failed']++;
                    $partial = true;
                    $this->context->audit->record('billing.automation_failed', 'billing_invoice', null, [
                        'subscription_uuid' => (string) ($subscription['uuid'] ?? ''),
                        'period_key' => $key,
                        'error_class' => get_class($exception),
                    ], $estateId);
                }

                $cursor = $this->nextRun($periodStart, $unit, $leadDays);
            }

            if (!$dryRun && $processed > 0) {
                $this->db->update('billing_schedules', [
                    'next_run_at' => Clock::toDb($cursor),
                    'last_run_at' => Clock::nowDb(),
                    'last_period_key' => $lastGeneratedKey,
                    'updated_at' => Clock::nowDb(),
                ], ['id' => (int) $schedule['id']]);
            }

            if ($limitReached) {
                break;
            }
        }

        $status = $counts['failed'] > 0 || $partial ? 'partial' : 'success';
        $result = [
            'dry_run' => $dryRun,
            'run_uuid' => $runUuid,
            'date' => $asOf->format('Y-m-d'),
            'scope' => $scope,
            'status' => $status,
            'counts' => $counts,
            'skips' => $skips,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'lock' => 'acquired',
        ];

        if (!$dryRun) {
            $this->context->audit->record('billing.automation_run', 'billing_automation', null, [
                'run_uuid' => $runUuid,
                'scope' => $scope,
                'run_date' => $asOf->format('Y-m-d'),
                'status' => $status,
                'counts' => $counts,
                'skips' => $skips,
                'duration_ms' => $result['duration_ms'],
            ], $estateUuid !== null ? (int) $this->estateIdByUuid($estateUuid) : null);
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeSubscriptions(?string $estateUuid): array
    {
        $sql = "SELECT s.id, s.uuid, s.estate_id, s.plan_id, s.status, s.billing_cycle,
                       s.starts_at, s.trial_ends_at,
                       e.uuid AS estate_uuid, e.name AS estate_name, e.account_id, e.status AS estate_status,
                       p.code AS plan_code, p.name AS plan_name,
                       p.monthly_price_cents, p.currency AS plan_currency
                FROM subscriptions s
                INNER JOIN estates e ON e.id = s.estate_id
                INNER JOIN subscription_plans p ON p.id = s.plan_id
                WHERE s.status IN ('active', 'past_due', 'trialing')";
        $params = [];

        if ($estateUuid !== null) {
            $sql .= ' AND e.uuid = :estate_uuid';
            $params['estate_uuid'] = $estateUuid;
        }

        $sql .= ' ORDER BY s.id';

        return $this->db->select($sql, $params);
    }

    private function existingSchedule(int $subscriptionId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM billing_schedules
             WHERE scope = 'estate_subscription' AND subscription_id = :subscription_id
             LIMIT 1",
            ['subscription_id' => $subscriptionId]
        );
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>
     */
    private function virtualSchedule(array $subscription, DateTimeImmutable $asOf): array
    {
        $unit = $this->periods->unitForCycle((string) ($subscription['billing_cycle'] ?? 'monthly'));
        $leadDays = max(0, (int) Config::get('billing.automation.lead_days', 0));
        $startsAt = Clock::fromDb((string) ($subscription['starts_at'] ?? '')) ?? $asOf;
        $reference = $startsAt > $asOf ? $startsAt : $asOf;
        $period = $this->periods->period($unit, $reference);
        $nextRun = $period['start']->modify('-' . $leadDays . ' days');

        return [
            'id' => 0,
            'estate_id' => (int) $subscription['estate_id'],
            'subscription_id' => (int) $subscription['id'],
            'generate_lead_days' => $leadDays,
            'due_days' => (int) Config::get('billing.automation.due_days', 14),
            'next_run_at' => Clock::toDb($nextRun),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $subscriptions
     */
    private function bootstrapSchedules(array $subscriptions, DateTimeImmutable $asOf): void
    {
        foreach ($subscriptions as $subscription) {
            if ($this->existingSchedule((int) $subscription['id']) !== null) {
                continue;
            }

            $virtual = $this->virtualSchedule($subscription, $asOf);
            $now = Clock::nowDb();

            try {
                $this->db->insert('billing_schedules', [
                    'uuid' => Uuid::v4(),
                    'scope' => 'estate_subscription',
                    'estate_id' => (int) $subscription['estate_id'],
                    'subscription_id' => (int) $subscription['id'],
                    'household_id' => null,
                    'name' => 'Subscription',
                    'currency' => (string) ($subscription['plan_currency'] ?? 'KES'),
                    'period_unit' => $this->periods->unitForCycle((string) ($subscription['billing_cycle'] ?? 'monthly')),
                    'generate_lead_days' => (int) $virtual['generate_lead_days'],
                    'due_days' => (int) $virtual['due_days'],
                    'next_run_at' => (string) $virtual['next_run_at'],
                    'status' => 'active',
                    'created_by_user_id' => null,
                    'updated_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (PDOException $exception) {
                // Concurrent bootstrap: UNIQUE(subscription_id) means another
                // process won; the due-schedule reload below picks it up.
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dueSchedules(DateTimeImmutable $asOf, ?string $estateUuid): array
    {
        $sql = "SELECT bs.*, s.id AS subscription_id, s.uuid AS subscription_uuid,
                       s.status, s.billing_cycle, s.starts_at, s.trial_ends_at,
                       e.uuid AS estate_uuid, e.name AS estate_name, e.account_id, e.status AS estate_status,
                       p.code AS plan_code, p.name AS plan_name,
                       s.plan_id AS plan_id, p.monthly_price_cents, p.currency AS plan_currency
                FROM billing_schedules bs
                INNER JOIN subscriptions s ON s.id = bs.subscription_id AND s.estate_id = bs.estate_id
                INNER JOIN estates e ON e.id = bs.estate_id
                INNER JOIN subscription_plans p ON p.id = s.plan_id
                WHERE bs.scope = 'estate_subscription'
                  AND bs.status = 'active'
                  AND bs.next_run_at <= :as_of";
        $params = ['as_of' => Clock::toDb($asOf)];

        if ($estateUuid !== null) {
            $sql .= ' AND e.uuid = :estate_uuid';
            $params['estate_uuid'] = $estateUuid;
        }

        $sql .= ' ORDER BY bs.id';
        $rows = $this->db->select($sql, $params);

        return array_map(function (array $row): array {
            return $row + ['subscription' => [
                'id' => (int) $row['subscription_id'],
                'uuid' => (string) $row['subscription_uuid'],
                'estate_id' => (int) $row['estate_id'],
                'status' => (string) $row['status'],
                'billing_cycle' => (string) $row['billing_cycle'],
                'starts_at' => $row['starts_at'],
                'estate_status' => (string) $row['estate_status'],
                'estate_uuid' => (string) $row['estate_uuid'],
                'estate_name' => (string) $row['estate_name'],
                'account_id' => (int) $row['account_id'],
                'plan_code' => (string) $row['plan_code'],
                'plan_name' => (string) $row['plan_name'],
                'plan_id' => (int) ($row['plan_id'] ?? 0),
                'monthly_price_cents' => (int) $row['monthly_price_cents'],
                'plan_currency' => (string) $row['plan_currency'],
            ]];
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionForSchedule(array $schedule): array
    {
        return $schedule['subscription'] ?? [];
    }

    private function skipReason(int $subscriptionId, int $estateId, string $generationKey, string $periodStart, string $periodEnd): ?string
    {
        $existing = $this->db->selectOne(
            'SELECT id FROM billing_invoices WHERE estate_id = :estate_id AND generation_key = :generation_key LIMIT 1',
            ['estate_id' => $estateId, 'generation_key' => $generationKey]
        );

        if ($existing !== null) {
            return 'exists';
        }

        $manual = $this->db->selectOne(
            "SELECT id FROM billing_invoices
             WHERE estate_id = :estate_id AND subscription_id = :subscription_id
               AND status <> 'void'
               AND (generation_key IS NULL OR generation_key NOT LIKE 'sub:%')
               AND period_start IS NOT NULL AND period_end IS NOT NULL
               AND period_start <= :period_end AND period_end >= :period_start
             LIMIT 1",
            [
                'estate_id' => $estateId,
                'subscription_id' => $subscriptionId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        );

        return $manual === null ? null : 'manual_conflict';
    }

    private function nextRun(DateTimeImmutable $periodStart, string $unit, int $leadDays): DateTimeImmutable
    {
        return $this->periods->nextStart($periodStart, $unit)->modify('-' . $leadDays . ' days');
    }

    /**
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $schedule
     * @param array{start:DateTimeImmutable,end:DateTimeImmutable,key:string} $period
     */
    private function generateInvoice(
        array $subscription,
        array $schedule,
        array $period,
        string $generationKey,
        int $amountMonthly,
        int $dueDays,
        DateTimeImmutable $asOf
    ): ?string {
        $amount = (int) $amountMonthly * ((string) ($subscription['billing_cycle'] ?? 'monthly') === 'annual' ? 12 : 1);
        $currency = strtoupper((string) ($subscription['plan_currency'] ?? 'KES'));
        $estateId = (int) $subscription['estate_id'];
        $periodStart = $period['start']->format('Y-m-d');
        $periodEnd = $period['end']->format('Y-m-d');
        $dueAt = Clock::toDb($period['start']->modify('+' . $dueDays . ' days'));
        $snapshot = json_encode([
            'plan_id' => (int) ($subscription['plan_id'] ?? 0),
            'plan_code' => (string) ($subscription['plan_code'] ?? ''),
            'plan_name' => (string) ($subscription['plan_name'] ?? ''),
            'monthly_price_cents' => $amountMonthly,
            'billing_cycle' => (string) ($subscription['billing_cycle'] ?? 'monthly'),
            'currency' => $currency,
            'period_key' => $period['key'],
        ], JSON_UNESCAPED_SLASHES) ?: null;

        $invoiceUuid = null;

        $this->db->transaction(function () use (
            &$invoiceUuid,
            $estateId,
            $subscription,
            $period,
            $periodStart,
            $periodEnd,
            $dueAt,
            $generationKey,
            $amount,
            $currency,
            $snapshot,
            $asOf
        ): void {
            $prefix = 'INV-' . gmdate('Ym') . '-';
            $attempts = 0;

            do {
                $attempts++;
                $latest = $this->billing->latestInvoiceNumber($estateId, $prefix);
                $next = 1;

                if ($latest !== null) {
                    $parts = explode('-', (string) $latest['invoice_number']);
                    $next = max(1, (int) end($parts) + 1);
                }

                $number = $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
                $uuid = Uuid::v4();
                $now = Clock::nowDb();

                try {
                    $invoiceId = $this->db->insert('billing_invoices', [
                        'uuid' => $uuid,
                        'estate_id' => $estateId,
                        'account_id' => (int) $subscription['account_id'],
                        'kind' => 'subscription',
                        'household_id' => null,
                        'source' => 'automation',
                        'generation_key' => $generationKey,
                        'subscription_id' => (int) $subscription['id'],
                        'invoice_number' => $number,
                        'status' => 'open',
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'period_key' => $period['key'],
                        'pricing_snapshot' => $snapshot,
                        'amount_cents' => $amount,
                        'currency' => $currency,
                        'due_at' => $dueAt,
                        'paid_at' => null,
                        'sent_at' => null,
                        'reminder_sent_at' => null,
                        'last_reminder_stage' => null,
                        'notes' => 'Auto-generated for ' . $period['key'],
                        'created_by_user_id' => null,
                        'updated_by_user_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } catch (PDOException $exception) {
                    $isDuplicate = (string) $exception->getCode() === '23000'
                        && (int) ($exception->errorInfo[1] ?? 0) === 1062;

                    if (!$isDuplicate) {
                        throw $exception;
                    }

                    $duplicate = $this->db->selectOne(
                        'SELECT uuid FROM billing_invoices WHERE estate_id = :estate_id AND generation_key = :key LIMIT 1',
                        ['estate_id' => $estateId, 'key' => $generationKey]
                    );

                    if ($duplicate !== null) {
                        return;
                    }

                    if ($attempts >= 5) {
                        throw $exception;
                    }

                    continue;
                }

                $invoiceUuid = $uuid;
                $this->context->audit->record('billing.invoice_generated', 'billing_invoice', $invoiceId, [
                    'invoice_uuid' => $uuid,
                    'invoice_number' => $number,
                    'estate_uuid' => (string) ($subscription['estate_uuid'] ?? ''),
                    'subscription_uuid' => (string) ($subscription['uuid'] ?? ''),
                    'plan_code' => (string) ($subscription['plan_code'] ?? ''),
                    'period_key' => $period['key'],
                    'amount_cents' => $amount,
                    'currency' => $currency,
                    'generation_key' => $generationKey,
                ], $estateId);

                return;
            } while (true);
        });

        return $invoiceUuid;
    }

    private function estateIdByUuid(string $uuid): int
    {
        return (int) ($this->db->scalar('SELECT id FROM estates WHERE uuid = :uuid', ['uuid' => $uuid]) ?? 0);
    }
}
