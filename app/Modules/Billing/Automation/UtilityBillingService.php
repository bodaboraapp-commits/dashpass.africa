<?php

declare(strict_types=1);

namespace App\Modules\Billing\Automation;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Database;
use App\Core\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Throwable;

/**
 * Inc-13 P3 utility generation (flat/metered/both) into the single ledger
 * (`billing_invoices kind=utility` + `utility_charges`), idempotent by
 * (schedule, household, period).
 */
final class UtilityBillingService
{
    private readonly AutomationPeriodCalculator $periods;

    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        ?AutomationPeriodCalculator $periods = null
    ) {
        $this->periods = $periods ?? new AutomationPeriodCalculator();
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function preview(DateTimeImmutable $asOf, ?string $estateUuid = null): array
    {
        return $this->candidates($asOf, $estateUuid);
    }

    /**
     * @return array{dry_run:bool,status:string,counts:array<string,int>,skips:array<string,int>,rows:array<int,array<string,mixed>>}
     */
    public function generate(DateTimeImmutable $asOf, ?string $estateUuid = null, bool $dryRun = false, int $limit = 50): array
    {
        $rows = $this->candidates($asOf, $estateUuid);
        $counts = ['scanned' => count($rows), 'generated' => 0, 'skipped' => 0, 'failed' => 0];
        $skips = [];

        foreach ($rows as $row) {
            if ($row['skip'] !== null) {
                $counts['skipped']++;
                $skips[$row['skip']] = ($skips[$row['skip']] ?? 0) + 1;

                continue;
            }

            if ($counts['generated'] >= $limit) {
                break;
            }

            if ($dryRun) {
                $counts['generated']++;

                continue;
            }

            try {
                $created = $this->createInvoice($row, $asOf);

                if ($created) {
                    $counts['generated']++;
                } else {
                    $counts['skipped']++;
                    $skips['duplicate'] = ($skips['duplicate'] ?? 0) + 1;
                }
            } catch (Throwable $exception) {
                $counts['failed']++;
                $this->context->audit->record('billing.automation_failed', 'utility_charge', null, [
                    'schedule_uuid' => (string) ($row['schedule_uuid'] ?? ''),
                    'period_key' => (string) ($row['period_key'] ?? ''),
                    'error_class' => get_class($exception),
                ], (int) ($row['estate_id'] ?? 0));
            }
        }

        return [
            'dry_run' => $dryRun,
            'status' => $counts['failed'] > 0 ? 'partial' : 'success',
            'counts' => $counts,
            'skips' => $skips,
            'rows' => array_slice($rows, 0, 100),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function candidates(DateTimeImmutable $asOf, ?string $estateUuid): array
    {
        $sql = "SELECT bs.*, e.uuid AS estate_uuid, e.name AS estate_name, e.account_id, e.status AS estate_status
                FROM billing_schedules bs
                INNER JOIN estates e ON e.id = bs.estate_id
                WHERE bs.scope = 'utility_bill' AND bs.status = 'active' AND bs.next_run_at <= :as_of";
        $params = ['as_of' => Clock::toDb($asOf)];

        if ($estateUuid !== null) {
            $sql .= ' AND e.uuid = :estate_uuid';
            $params['estate_uuid'] = $estateUuid;
        }

        $schedules = $this->db->select($sql . ' ORDER BY bs.id', $params);
        $candidates = [];

        foreach ($schedules as $schedule) {
            $estateId = (int) $schedule['estate_id'];
            $unit = (string) ($schedule['period_unit'] ?? 'month');
            $lead = max(0, (int) ($schedule['generate_lead_days'] ?? 0));
            $cursor = Clock::fromDb((string) $schedule['next_run_at']);
            $processed = 0;

            while ($cursor !== null && $cursor <= $asOf && $processed < 6) {
                $period = $this->periods->period($unit, $cursor->modify('+' . $lead . ' days'));
                $processed++;
                $households = $this->targetHouseholds($schedule);
                $basis = $this->basis($estateId, (string) ($schedule['utility_type'] ?? 'other'));

                if ($basis === null && (isset($schedule['flat_amount_cents']) || isset($schedule['tariff_cents_per_unit']))) {
                    $flat = isset($schedule['flat_amount_cents']) ? (int) $schedule['flat_amount_cents'] : null;
                    $rate = isset($schedule['tariff_cents_per_unit']) ? (int) $schedule['tariff_cents_per_unit'] : null;
                    $basis = [
                        'basis' => $flat !== null && $rate !== null ? 'both' : ($rate !== null ? 'metered' : 'flat'),
                        'flat' => $flat,
                        'rate' => $rate,
                    ];
                }
                $skip = null;

                if (in_array((string) ($schedule['estate_status'] ?? 'active'), ['archived', 'suspended'], true)) {
                    $skip = 'estate_' . (string) $schedule['estate_status'];
                    $households = [];
                } elseif ($households === []) {
                    $skip = 'no_active_households';
                } elseif ($basis === null) {
                    $skip = 'missing_basis';
                }

                foreach ($households as $household) {
                    $periodKey = (string) $period['key'];
                    $generationKey = 'util:' . (string) $schedule['uuid'] . ':' . (string) $household['uuid'] . ':' . $periodKey;
                    $calculation = $this->calculate($basis, $schedule, null);
                    $candidates[] = [
                        'skip' => $skip ?? ($calculation['amount_cents'] <= 0 ? 'zero_amount' : null),
                        'estate_id' => $estateId,
                        'estate_uuid' => (string) $schedule['estate_uuid'],
                        'account_id' => (int) $schedule['account_id'],
                        'schedule_id' => (int) $schedule['id'],
                        'schedule_uuid' => (string) $schedule['uuid'],
                        'household_id' => (int) $household['id'],
                        'household_uuid' => (string) $household['uuid'],
                        'unit_number' => (string) $household['unit_number'],
                        'period_key' => $periodKey,
                        'period_start' => $period['start']->format('Y-m-d'),
                        'period_end' => $period['end']->format('Y-m-d'),
                        'due_at' => Clock::toDb($period['start']->modify('+' . max(0, (int) ($schedule['due_days'] ?? 14)) . ' days')),
                        'generation_key' => $generationKey,
                        'basis' => $basis,
                        'calculation' => $calculation,
                    ];
                }

                $cursor = $this->periods->nextStart($period['start'], $unit)->modify('-' . $lead . ' days');
            }
        }

        return $candidates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function targetHouseholds(array $schedule): array
    {
        $estateId = (int) $schedule['estate_id'];
        $householdId = $schedule['household_id'] === null ? null : (int) $schedule['household_id'];

        if ($householdId !== null) {
            $row = $this->db->selectOne(
                "SELECT id, uuid, unit_number FROM households
                 WHERE id = :id AND estate_id = :estate_id AND status = 'active' LIMIT 1",
                ['id' => $householdId, 'estate_id' => $estateId]
            );

            return $row === null ? [] : [$row];
        }

        return $this->db->select(
            "SELECT id, uuid, unit_number FROM households
             WHERE estate_id = :estate_id AND status = 'active' ORDER BY id",
            ['estate_id' => $estateId]
        );
    }

    /**
     * @return array{basis:string,flat:?int,rate:?int}|null
     */
    private function basis(int $estateId, string $utilityType): ?array
    {
        $key = 'billing.utility_basis.' . $utilityType;
        $row = $this->db->selectOne(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :key LIMIT 1',
            ['estate_id' => $estateId, 'key' => $key]
        );
        $basis = $row === null ? null : json_decode((string) $row['setting_value'], true);
        $basis = is_string($basis) ? $basis : null;

        $flatRow = $this->db->selectOne(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :key LIMIT 1',
            ['estate_id' => $estateId, 'key' => 'billing.utility_flat_tariff_cents.' . $utilityType]
        );
        $rateRow = $this->db->selectOne(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :key LIMIT 1',
            ['estate_id' => $estateId, 'key' => 'billing.utility_unit_rate_cents.' . $utilityType]
        );

        return $basis === null ? null : [
            'basis' => $basis,
            'flat' => $flatRow === null ? null : (int) json_decode((string) $flatRow['setting_value'], true),
            'rate' => $rateRow === null ? null : (int) json_decode((string) $rateRow['setting_value'], true),
        ];
    }

    /**
     * @param array{basis:string,flat:?int,rate:?int} $basis
     * @return array{amount_cents:int,charge_model:string,flat:?int,rate:?int,units:?float}
     */
    private function calculate(array $basis, array $schedule, ?float $units): array
    {
        $flat = $basis['flat'];
        $rate = $basis['rate'];

        if ($flat === null && isset($schedule['flat_amount_cents'])) {
            $flat = (int) $schedule['flat_amount_cents'];
        }

        if ($rate === null && isset($schedule['tariff_cents_per_unit'])) {
            $rate = (int) $schedule['tariff_cents_per_unit'];
        }

        return match ($basis['basis']) {
            'metered' => [
                'amount_cents' => $units === null || $rate === null ? 0 : (int) round($units * $rate),
                'charge_model' => 'metered',
                'flat' => $flat,
                'rate' => $rate,
                'units' => $units,
            ],
            'both' => [
                'amount_cents' => (int) ($flat ?? 0) + ($units === null || $rate === null ? 0 : (int) round($units * $rate)),
                'charge_model' => 'both',
                'flat' => $flat,
                'rate' => $rate,
                'units' => $units,
            ],
            default => [
                'amount_cents' => (int) ($flat ?? 0),
                'charge_model' => 'flat',
                'flat' => $flat,
                'rate' => $rate,
                'units' => null,
            ],
        };
    }

    private function createInvoice(array $row, DateTimeImmutable $asOf): bool
    {
        $created = false;

        $this->db->transaction(function () use ($row, &$created): void {
            $existing = $this->db->selectOne(
                'SELECT id FROM billing_invoices WHERE estate_id = :estate_id AND generation_key = :key LIMIT 1',
                ['estate_id' => $row['estate_id'], 'key' => $row['generation_key']]
            );

            if ($existing !== null) {
                return;
            }

            $prefix = 'INV-' . gmdate('Ym') . '-';
            $latest = $this->db->selectOne(
                'SELECT invoice_number FROM billing_invoices WHERE estate_id = :estate_id AND invoice_number LIKE :prefix ORDER BY invoice_number DESC LIMIT 1 FOR UPDATE',
                ['estate_id' => $row['estate_id'], 'prefix' => $prefix . '%']
            );
            $next = 1;

            if ($latest !== null) {
                $parts = explode('-', (string) $latest['invoice_number']);
                $next = max(1, (int) end($parts) + 1);
            }

            $number = $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $now = Clock::nowDb();
            $uuid = Uuid::v4();
            $calc = $row['calculation'];

            try {
                $invoiceId = $this->db->insert('billing_invoices', [
                    'uuid' => $uuid,
                    'estate_id' => (int) $row['estate_id'],
                    'account_id' => (int) $row['account_id'],
                    'kind' => 'utility',
                    'household_id' => (int) $row['household_id'],
                    'source' => 'automation',
                    'generation_key' => (string) $row['generation_key'],
                    'subscription_id' => null,
                    'invoice_number' => $number,
                    'status' => 'open',
                    'period_start' => (string) $row['period_start'],
                    'period_end' => (string) $row['period_end'],
                    'period_key' => (string) $row['period_key'],
                    'pricing_snapshot' => json_encode($calc, JSON_UNESCAPED_SLASHES),
                    'amount_cents' => (int) $calc['amount_cents'],
                    'currency' => 'KES',
                    'due_at' => (string) $row['due_at'],
                    'created_by_user_id' => null,
                    'updated_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    return;
                }

                throw $exception;
            }

            $this->db->insert('utility_charges', [
                'uuid' => Uuid::v4(),
                'estate_id' => (int) $row['estate_id'],
                'invoice_id' => $invoiceId,
                'household_id' => (int) $row['household_id'],
                'billing_schedule_id' => (int) $row['schedule_id'],
                'period_key' => (string) $row['period_key'],
                'period_start' => (string) $row['period_start'],
                'period_end' => (string) $row['period_end'],
                'charge_model' => (string) $calc['charge_model'],
                'units' => $calc['units'],
                'flat_amount_cents' => $calc['flat'],
                'tariff_cents_per_unit' => $calc['rate'],
                'amount_cents' => (int) $calc['amount_cents'],
                'currency' => 'KES',
                'pricing_snapshot' => json_encode($calc, JSON_UNESCAPED_SLASHES),
                'created_by_user_id' => null,
                'updated_by_user_id' => null,
            ]);

            $created = true;
            $this->context->audit->record('billing.invoice_generated', 'billing_invoice', $invoiceId, [
                'invoice_uuid' => $uuid,
                'invoice_number' => $number,
                'kind' => 'utility',
                'estate_uuid' => (string) $row['estate_uuid'],
                'household_uuid' => (string) $row['household_uuid'],
                'schedule_uuid' => (string) $row['schedule_uuid'],
                'period_key' => (string) $row['period_key'],
                'amount_cents' => (int) $calc['amount_cents'],
                'currency' => 'KES',
            ], (int) $row['estate_id']);
        });

        return $created;
    }
}
