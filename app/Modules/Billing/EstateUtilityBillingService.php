<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\Uuid;
use App\Modules\Billing\Automation\AutomationPeriodCalculator;
use App\Modules\Notifications\BillingNotificationService;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Throwable;

/**
 * Estate-manager utility billing cockpit (owner-approved workflow, 2026-11-13).
 *
 * Wraps the frozen automation engine (billing_schedules / utility_charges /
 * billing_invoices / BillingNotificationService) with an estate-scoped, human
 * driven run: readings → issue → send → collect → nudge.
 *
 * Safety invariants:
 *  - every query is estate-scoped by the caller's estate id;
 *  - issuing is idempotent per (schedule, household, period) via generation_key;
 *  - readings never overwrite an existing charge (issued bills are immutable);
 *  - zero/negative amounts are skipped, not invoiced;
 *  - every mutation is audited (no PII in audit metadata).
 */
final class EstateUtilityBillingService
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

    /* ------------------------------------------------------------------ */
    /* Period helpers                                                     */
    /* ------------------------------------------------------------------ */

    public function currentMonth(): string
    {
        return Clock::now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m');
    }

    public function normaliseMonth(string $month): string
    {
        $month = trim($month);

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month) === 1) {
            return $month;
        }

        if (preg_match('/^(\d{4})(0[1-9]|1[0-2])$/', $month, $match) === 1) {
            return $match[1] . '-' . $match[2];
        }

        return $this->currentMonth();
    }

    private function reference(string $month): DateTimeImmutable
    {
        return new DateTimeImmutable($this->normaliseMonth($month) . '-01 00:00:00', new DateTimeZone('UTC'));
    }

    /* ------------------------------------------------------------------ */
    /* Run data (readings cockpit)                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{month:string,schedules:array<int,array<string,mixed>>,totals:array<string,int>}
     */
    public function run(int $estateId, string $month): array
    {
        $month = $this->normaliseMonth($month);
        $reference = $this->reference($month);

        $estate = $this->db->selectOne(
            'SELECT id, uuid, name, account_id FROM estates WHERE id = :id LIMIT 1',
            ['id' => $estateId]
        );
        $schedules = $this->db->select(
            "SELECT * FROM billing_schedules
             WHERE estate_id = :estate_id AND scope = 'utility_bill' AND status = 'active'
             ORDER BY id",
            ['estate_id' => $estateId]
        );
        $households = $this->db->select(
            "SELECT h.id, h.uuid, h.unit_number, b.name AS building_name
             FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id AND h.status = 'active'
             ORDER BY h.unit_number",
            ['estate_id' => $estateId]
        );

        $rows = [];
        $totals = [
            'schedules' => 0,
            'households' => 0,
            'issued' => 0,
            'readings_required' => 0,
            'bills' => 0,
            'amount_cents' => 0,
            'exceptions' => 0,
        ];

        foreach ($schedules as $schedule) {
            $basis = $this->scheduleBasis($schedule);
            $period = $this->periods->period((string) ($schedule['period_unit'] ?? 'month'), $reference);
            $periodKey = (string) $period['key'];
            $dueAt = Clock::toDb($period['start']->modify('+' . max(0, (int) ($schedule['due_days'] ?? 14)) . ' days'));
            $scheduleHouseholds = [];

            foreach ($this->targetHouseholds($schedule, $households) as $household) {
                $last = $this->lastCharge($estateId, (int) $schedule['id'], (int) $household['id']);
                $existing = $this->existingInvoice($estateId, (string) $schedule['uuid'], (string) $household['uuid'], $periodKey);

                $scheduleHouseholds[] = [
                    'uuid' => (string) $household['uuid'],
                    'household_id' => (int) $household['id'],
                    'unit_number' => (string) $household['unit_number'],
                    'building_name' => (string) ($household['building_name'] ?? ''),
                    'last_reading' => $last['reading'],
                    'previous_units' => $this->previousUnits($estateId, (int) $schedule['id'], (int) $household['id']),
                    'first_bill' => $last['reading'] === null,
                    'existing' => $existing,
                ];

                $totals['households']++;

                if ($existing !== null) {
                    $totals['issued']++;
                    $totals['bills']++;
                    $totals['amount_cents'] += (int) $existing['amount_cents'];
                } elseif ($basis['basis'] !== 'flat') {
                    $totals['readings_required']++;
                }
            }

            $rows[] = [
                'uuid' => (string) $schedule['uuid'],
                'billing_schedule_id' => (int) $schedule['id'],
                'estate_id' => $estateId,
                'name' => trim((string) ($schedule['name'] ?? '')) !== ''
                    ? (string) $schedule['name']
                    : ucfirst((string) ($schedule['utility_type'] ?? 'utility')),
                'utility_type' => (string) ($schedule['utility_type'] ?? 'other'),
                'basis' => $basis['basis'],
                'charge_model' => $basis['basis'],
                'flat_cents' => $basis['flat'],
                'rate_cents' => $basis['rate'],
                'unit_label' => $basis['unit_label'],
                'period_key' => $periodKey,
                'period_start' => $period['start']->format('Y-m-d'),
                'period_end' => $period['end']->format('Y-m-d'),
                'due_at' => $dueAt,
                'households' => $scheduleHouseholds,
                'issued_count' => count(array_filter($scheduleHouseholds, static fn (array $row): bool => $row['existing'] !== null)),
                'household_count' => count($scheduleHouseholds),
                'account_id' => (int) ($estate['account_id'] ?? 0),
            ];
            $totals['schedules']++;
        }

        return ['month' => $month, 'schedules' => $rows, 'totals' => $totals];
    }

    /**
     * Issue one invoice per (schedule, household, period) from the manager's readings.
     *
     * @param array<string, array<string, mixed>> $readings readings[scheduleUuid][householdUuid] = reading
     * @return array{ok:bool,errors:array<string,string>,issued:int,skipped:int,amount_cents:int}
     */
    public function issue(int $estateId, string $month, array $readings, int $actorId): array
    {
        $run = $this->run($estateId, $month);
        $errors = [];
        $candidates = [];

        foreach ($run['schedules'] as $schedule) {
            foreach ($schedule['households'] as $household) {
                if ($household['existing'] !== null) {
                    continue;
                }

                $basis = [
                    'basis' => (string) $schedule['basis'],
                    'flat' => $schedule['flat_cents'] === null ? null : (int) $schedule['flat_cents'],
                    'rate' => $schedule['rate_cents'] === null ? null : (int) $schedule['rate_cents'],
                ];
                $reading = null;
                $key = $schedule['uuid'] . ':' . $household['uuid'];

                if ($basis['basis'] !== 'flat') {
                    $raw = $readings[$schedule['uuid']][$household['uuid']] ?? null;
                    $raw = is_scalar($raw) ? trim((string) $raw) : '';

                    if ($raw === '') {
                        $errors[$key] = $household['unit_number'] . ': a meter reading is required.';
                        continue;
                    }

                    if (!is_numeric($raw)) {
                        $errors[$key] = $household['unit_number'] . ': the reading must be a number.';
                        continue;
                    }

                    $reading = (float) $raw;

                    if ($reading < 0) {
                        $errors[$key] = $household['unit_number'] . ': the reading cannot be negative.';
                        continue;
                    }

                    if ($household['last_reading'] !== null && $reading < (float) $household['last_reading']) {
                        $errors[$key] = $household['unit_number'] . ': the reading is lower than the last reading ('
                            . $this->number($household['last_reading']) . ') — check the meter for a rollover.';
                        continue;
                    }
                }

                $units = $reading === null ? null : max(0.0, $reading - (float) ($household['last_reading'] ?? 0.0));
                $calculation = $this->calculate($basis, $units);

                if ((int) $calculation['amount_cents'] <= 0) {
                    continue;
                }

                $candidates[] = [
                    'schedule_uuid' => $schedule['uuid'],
                    'billing_schedule_id' => $schedule['billing_schedule_id'],
                    'estate_id' => $schedule['estate_id'],
                    'household_uuid' => $household['uuid'],
                    'household_id' => $household['household_id'],
                    'unit_number' => $household['unit_number'],
                    'unit_label' => $schedule['unit_label'],
                    'period_key' => $schedule['period_key'],
                    'period_start' => $schedule['period_start'],
                    'period_end' => $schedule['period_end'],
                    'due_at' => $schedule['due_at'],
                    'account_id' => (int) $schedule['account_id'],
                    'basis' => $basis,
                    'calculation' => $calculation,
                    'reading' => $reading,
                    'reading_start' => $household['last_reading'],
                    'units' => $units,
                ];
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'issued' => 0, 'skipped' => 0, 'amount_cents' => 0];
        }

        $issued = 0;
        $skipped = 0;
        $amountCents = 0;

        $this->db->transaction(function () use ($candidates, $actorId, $estateId, &$issued, &$skipped, &$amountCents): void {
            foreach ($candidates as $candidate) {
                $generationKey = 'util:' . $candidate['schedule_uuid'] . ':' . $candidate['household_uuid'] . ':' . $candidate['period_key'];

                if ($this->db->selectOne(
                    'SELECT id FROM billing_invoices WHERE estate_id = :estate_id AND generation_key = :key LIMIT 1',
                    ['estate_id' => $estateId, 'key' => $generationKey]
                ) !== null) {
                    $skipped++;

                    continue;
                }

                try {
                    $this->insertInvoice($candidate, $generationKey, $actorId);
                    $issued++;
                    $amountCents += (int) $candidate['calculation']['amount_cents'];
                } catch (PDOException $exception) {
                    if ((string) $exception->getCode() === '23000') {
                        $skipped++;

                        continue;
                    }

                    throw $exception;
                }
            }
        });

        $this->context->audit->record('billing.utility_run_issued', 'billing_invoice', null, [
            'estate_id' => $estateId,
            'issued' => $issued,
            'skipped' => $skipped,
            'amount_cents' => $amountCents,
            'actor_user_id' => $actorId,
        ], $estateId);

        return ['ok' => true, 'errors' => [], 'issued' => $issued, 'skipped' => $skipped, 'amount_cents' => $amountCents];
    }

    /**
     * Send every issued-but-unsent utility invoice for the month's periods.
     *
     * @return array{ok:bool,invoices:int,sent:int,skipped:int,failed:int,channels:array<string,string>}
     */
    public function send(int $estateId, string $month, int $actorId): array
    {
        $run = $this->run($estateId, $month);
        $periodKeys = array_values(array_unique(array_map(
            static fn (array $schedule): string => (string) $schedule['period_key'],
            $run['schedules']
        )));

        if ($periodKeys === []) {
            return ['ok' => true, 'invoices' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'channels' => []];
        }

        $placeholders = implode(',', array_fill(0, count($periodKeys), '?'));
        $invoices = $this->db->select(
            "SELECT i.* FROM billing_invoices i
             WHERE i.estate_id = ? AND i.kind = 'utility' AND i.status IN ('open', 'past_due')
               AND i.sent_at IS NULL AND i.period_key IN (" . $placeholders . ')
             ORDER BY i.id',
            array_merge([$estateId], $periodKeys)
        );

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $channels = [];
        $service = BillingNotificationService::make($this->context);

        foreach ($invoices as $invoice) {
            try {
                $result = $service->deliverInvoiceEvent($invoice, 'generated');
                $sent += (int) $result['sent'];
                $skipped += (int) $result['skipped'];
                $failed += (int) $result['failed'];

                foreach ((array) $result['channels'] as $channel => $outcome) {
                    $channels[$channel . ':' . $outcome] = (string) (((int) ($channels[$channel . ':' . $outcome] ?? 0)) + 1);
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        $this->context->audit->record('billing.utility_bills_sent', 'billing_invoice', null, [
            'estate_id' => $estateId,
            'invoices' => count($invoices),
            'sent' => $sent,
            'failed' => $failed,
            'actor_user_id' => $actorId,
        ], $estateId);

        return ['ok' => true, 'invoices' => count($invoices), 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed, 'channels' => $channels];
    }

    /* ------------------------------------------------------------------ */
    /* Collections                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{invoices:array<int,array<string,mixed>>,buckets:array<string,array{count:int,amount_cents:int}>,totals:array{billed:int,collected:int,outstanding:int,rate:int}}
     */
    public function collections(int $estateId): array
    {
        $invoices = $this->db->select(
            "SELECT i.id, i.uuid, i.invoice_number, i.status, i.amount_cents, i.currency, i.due_at,
                    i.period_key, i.reminder_sent_at, h.unit_number, b.name AS building_name
             FROM billing_invoices i
             LEFT JOIN households h ON h.id = i.household_id AND h.estate_id = i.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE i.estate_id = :estate_id AND i.kind = 'utility' AND i.status IN ('open', 'past_due')
             ORDER BY i.due_at ASC, i.id ASC",
            ['estate_id' => $estateId]
        );

        $paidRows = $this->db->select(
            "SELECT p.invoice_id, SUM(p.amount_cents) AS paid_cents
             FROM billing_payments p
             INNER JOIN billing_invoices i ON i.id = p.invoice_id AND i.estate_id = p.estate_id
             WHERE p.estate_id = :estate_id AND p.status = 'confirmed' AND i.kind = 'utility'
             GROUP BY p.invoice_id",
            ['estate_id' => $estateId]
        );
        $paid = [];
        foreach ($paidRows as $row) {
            $paid[(int) $row['invoice_id']] = (int) $row['paid_cents'];
        }

        $buckets = [
            'due_soon' => ['count' => 0, 'amount_cents' => 0],
            'd1_7' => ['count' => 0, 'amount_cents' => 0],
            'd8_30' => ['count' => 0, 'amount_cents' => 0],
            'd31_plus' => ['count' => 0, 'amount_cents' => 0],
        ];
        $now = Clock::now()->getTimestamp();
        $rows = [];
        $outstanding = 0;

        foreach ($invoices as $invoice) {
            $paidCents = $paid[(int) $invoice['id']] ?? 0;
            $balance = max(0, (int) $invoice['amount_cents'] - $paidCents);

            if ($balance === 0) {
                continue;
            }

            $dueAt = Clock::fromDb((string) $invoice['due_at']);
            $daysLate = $dueAt === null ? 0 : (int) floor(($now - $dueAt->getTimestamp()) / 86400);
            $bucket = $daysLate <= 0 ? 'due_soon' : ($daysLate <= 7 ? 'd1_7' : ($daysLate <= 30 ? 'd8_30' : 'd31_plus'));
            $buckets[$bucket]['count']++;
            $buckets[$bucket]['amount_cents'] += $balance;
            $outstanding += $balance;

            $rows[] = [
                'uuid' => (string) $invoice['uuid'],
                'invoice_number' => (string) $invoice['invoice_number'],
                'status' => (string) $invoice['status'],
                'unit_number' => (string) ($invoice['unit_number'] ?? 'Household'),
                'building_name' => (string) ($invoice['building_name'] ?? ''),
                'amount_cents' => (int) $invoice['amount_cents'],
                'paid_cents' => $paidCents,
                'balance_cents' => $balance,
                'currency' => (string) $invoice['currency'],
                'due_at' => (string) $invoice['due_at'],
                'period_key' => (string) ($invoice['period_key'] ?? ''),
                'days_late' => max(0, $daysLate),
                'last_reminder' => $invoice['reminder_sent_at'] === null ? '' : (string) $invoice['reminder_sent_at'],
            ];
        }

        $billed = (int) $this->db->scalar(
            "SELECT COALESCE(SUM(amount_cents), 0) FROM billing_invoices
             WHERE estate_id = :estate_id AND kind = 'utility' AND status <> 'void'",
            ['estate_id' => $estateId]
        );
        $collected = (int) $this->db->scalar(
            "SELECT COALESCE(SUM(p.amount_cents), 0)
             FROM billing_payments p
             INNER JOIN billing_invoices i ON i.id = p.invoice_id AND i.estate_id = p.estate_id
             WHERE p.estate_id = :estate_id AND p.status = 'confirmed' AND i.kind = 'utility'",
            ['estate_id' => $estateId]
        );

        return [
            'invoices' => $rows,
            'buckets' => $buckets,
            'totals' => [
                'billed' => $billed,
                'collected' => $collected,
                'outstanding' => $outstanding,
                'rate' => $billed > 0 ? (int) round($collected / $billed * 100) : 0,
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function findInvoice(int $estateId, string $uuid): ?array
    {
        $invoice = $this->db->selectOne(
            "SELECT i.* FROM billing_invoices i
             WHERE i.uuid = :uuid AND i.estate_id = :estate_id AND i.kind = 'utility' LIMIT 1",
            ['uuid' => $uuid, 'estate_id' => $estateId]
        );

        return $invoice === null ? null : (array) $invoice;
    }

    /** @return array<string,mixed> */
    public function remind(int $estateId, string $uuid, int $actorId): array
    {
        $invoice = $this->findInvoice($estateId, $uuid);
        $result = ['ok' => false, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        if ($invoice === null || !in_array((string) $invoice['status'], ['open', 'past_due'], true)) {
            return $result;
        }

        try {
            $delivery = BillingNotificationService::make($this->context)->deliverInvoiceEvent($invoice, 'reminder');
            $result = ['ok' => (int) $delivery['sent'] > 0, 'sent' => (int) $delivery['sent'], 'skipped' => (int) $delivery['skipped'], 'failed' => (int) $delivery['failed']];

            if ((int) $delivery['sent'] > 0) {
                $this->db->update('billing_invoices', ['reminder_sent_at' => Clock::nowDb(), 'updated_at' => Clock::nowDb()], ['id' => (int) $invoice['id']]);
            }

            $this->context->audit->record('billing.utility_reminder_sent', 'billing_invoice', (int) $invoice['id'], [
                'invoice_uuid' => (string) $invoice['uuid'],
                'sent' => $result['sent'],
                'actor_user_id' => $actorId,
            ], $estateId);
        } catch (Throwable) {
            $result['ok'] = false;
        }

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /* Basis / calculation / inserts                                      */
    /* ------------------------------------------------------------------ */

    /** @return array{basis:string,flat:?int,rate:?int,unit_label:string} */
    private function scheduleBasis(array $schedule): array
    {
        $estateId = (int) $schedule['estate_id'];
        $type = (string) ($schedule['utility_type'] ?? 'other');

        $flat = $schedule['flat_amount_cents'] === null ? null : (int) $schedule['flat_amount_cents'];
        $rate = $schedule['tariff_cents_per_unit'] === null ? null : (int) $schedule['tariff_cents_per_unit'];

        $settingBasis = $this->estateSetting($estateId, 'billing.utility_basis.' . $type);
        $settingFlat = $this->estateSetting($estateId, 'billing.utility_flat_tariff_cents.' . $type);
        $settingRate = $this->estateSetting($estateId, 'billing.utility_unit_rate_cents.' . $type);

        if (is_string($settingBasis) && in_array($settingBasis, ['flat', 'metered', 'both'], true)) {
            $basis = $settingBasis;
        } else {
            $basis = $flat !== null && $rate !== null ? 'both' : ($rate !== null ? 'metered' : 'flat');
        }

        if ($settingFlat !== null) {
            $flat = (int) $settingFlat;
        }

        if ($settingRate !== null) {
            $rate = (int) $settingRate;
        }

        $unitLabel = trim((string) ($schedule['unit_label'] ?? ''));

        if ($unitLabel === '') {
            $unitLabel = match ($type) {
                'water' => 'm³',
                'electricity' => 'kWh',
                'gas' => 'units',
                default => 'units',
            };
        }

        return ['basis' => $basis, 'flat' => $flat, 'rate' => $rate, 'unit_label' => $unitLabel];
    }

    private function estateSetting(int $estateId, string $key): mixed
    {
        $row = $this->db->selectOne(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :key LIMIT 1',
            ['estate_id' => $estateId, 'key' => $key]
        );

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $row['setting_value'], true);

        return $decoded === null ? (string) $row['setting_value'] : $decoded;
    }

    /**
     * @param array{basis:string,flat:?int,rate:?int} $basis
     * @return array{amount_cents:int,charge_model:string,flat:?int,rate:?int,units:?float}
     */
    private function calculate(array $basis, ?float $units): array
    {
        $flat = $basis['flat'];
        $rate = $basis['rate'];

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

    /** @param array<int,array<string,mixed>> $households */
    private function targetHouseholds(array $schedule, array $households): array
    {
        $householdId = $schedule['household_id'] === null ? null : (int) $schedule['household_id'];

        if ($householdId === null) {
            return $households;
        }

        return array_values(array_filter(
            $households,
            static fn (array $household): bool => (int) $household['id'] === $householdId
        ));
    }

    /** @return array{reading:?float,units:?float} */
    private function lastCharge(int $estateId, int $scheduleId, int $householdId): array
    {
        $row = $this->db->selectOne(
            'SELECT reading_end_units, units FROM utility_charges
             WHERE estate_id = :estate_id AND billing_schedule_id = :schedule_id AND household_id = :household_id
             ORDER BY id DESC LIMIT 1',
            ['estate_id' => $estateId, 'schedule_id' => $scheduleId, 'household_id' => $householdId]
        );

        return [
            'reading' => $row === null || $row['reading_end_units'] === null ? null : (float) $row['reading_end_units'],
            'units' => $row === null || $row['units'] === null ? null : (float) $row['units'],
        ];
    }

    /** @return array<int, float> */
    private function previousUnits(int $estateId, int $scheduleId, int $householdId): array
    {
        $rows = $this->db->select(
            'SELECT units FROM utility_charges
             WHERE estate_id = :estate_id AND billing_schedule_id = :schedule_id AND household_id = :household_id
               AND units IS NOT NULL
             ORDER BY id DESC LIMIT 3',
            ['estate_id' => $estateId, 'schedule_id' => $scheduleId, 'household_id' => $householdId]
        );

        return array_values(array_map(static fn (array $row): float => (float) $row['units'], $rows));
    }

    /** @return array<string,mixed>|null */
    private function existingInvoice(int $estateId, string $scheduleUuid, string $householdUuid, string $periodKey): ?array
    {
        $row = $this->db->selectOne(
            "SELECT uuid, invoice_number, status, amount_cents, currency, sent_at
             FROM billing_invoices
             WHERE estate_id = :estate_id AND generation_key = :generation_key LIMIT 1",
            [
                'estate_id' => $estateId,
                'generation_key' => 'util:' . $scheduleUuid . ':' . $householdUuid . ':' . $periodKey,
            ]
        );

        return $row === null ? null : (array) $row;
    }

    private function insertInvoice(array $candidate, string $generationKey, int $actorId): void
    {
        $calc = $candidate['calculation'];
        $prefix = 'INV-' . gmdate('Ym') . '-';
        $latest = $this->db->selectOne(
            'SELECT invoice_number FROM billing_invoices
             WHERE estate_id = :estate_id AND invoice_number LIKE :prefix
             ORDER BY invoice_number DESC LIMIT 1 FOR UPDATE',
            ['estate_id' => (int) $candidate['estate_id'], 'prefix' => $prefix . '%']
        );
        $next = 1;

        if ($latest !== null) {
            $parts = explode('-', (string) $latest['invoice_number']);
            $next = max(1, (int) end($parts) + 1);
        }

        $number = $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        $now = Clock::nowDb();
        $uuid = Uuid::v4();
        $snapshot = json_encode([
            'basis' => $candidate['basis']['basis'],
            'flat_amount_cents' => $calc['flat'],
            'tariff_cents_per_unit' => $calc['rate'],
            'unit_label' => $candidate['unit_label'] ?? null,
            'units' => $calc['units'],
            'period_key' => $candidate['period_key'],
            'source' => 'estate_manager',
        ], JSON_UNESCAPED_SLASHES);

        $invoiceId = $this->db->insert('billing_invoices', [
            'uuid' => $uuid,
            'estate_id' => (int) $candidate['estate_id'],
            'account_id' => (int) $candidate['account_id'],
            'kind' => 'utility',
            'household_id' => (int) $candidate['household_id'],
            'source' => 'manual',
            'generation_key' => $generationKey,
            'subscription_id' => null,
            'invoice_number' => $number,
            'status' => 'open',
            'period_start' => (string) $candidate['period_start'],
            'period_end' => (string) $candidate['period_end'],
            'period_key' => (string) $candidate['period_key'],
            'pricing_snapshot' => $snapshot,
            'amount_cents' => (int) $calc['amount_cents'],
            'currency' => 'KES',
            'due_at' => (string) $candidate['due_at'],
            'created_by_user_id' => $actorId,
            'updated_by_user_id' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db->insert('utility_charges', [
            'uuid' => Uuid::v4(),
            'estate_id' => (int) $candidate['estate_id'],
            'invoice_id' => $invoiceId,
            'household_id' => (int) $candidate['household_id'],
            'billing_schedule_id' => (int) $candidate['billing_schedule_id'],
            'period_key' => (string) $candidate['period_key'],
            'period_start' => (string) $candidate['period_start'],
            'period_end' => (string) $candidate['period_end'],
            'charge_model' => (string) $calc['charge_model'],
            'units' => $calc['units'],
            'reading_start_units' => $candidate['reading_start'],
            'reading_end_units' => $candidate['reading'],
            'reading_source' => 'manual',
            'flat_amount_cents' => $calc['flat'],
            'tariff_cents_per_unit' => $calc['rate'],
            'amount_cents' => (int) $calc['amount_cents'],
            'currency' => 'KES',
            'pricing_snapshot' => $snapshot,
            'created_by_user_id' => $actorId,
            'updated_by_user_id' => $actorId,
        ]);

        $this->context->audit->record('billing.invoice_generated', 'billing_invoice', $invoiceId, [
            'invoice_uuid' => $uuid,
            'invoice_number' => $number,
            'kind' => 'utility',
            'source' => 'estate_manager',
            'household_uuid' => (string) $candidate['household_uuid'],
            'schedule_uuid' => (string) $candidate['schedule_uuid'],
            'period_key' => (string) $candidate['period_key'],
            'amount_cents' => (int) $calc['amount_cents'],
            'currency' => 'KES',
        ], (int) $candidate['estate_id']);
    }

    private function number(mixed $value): string
    {
        $float = (float) $value;

        return rtrim(rtrim(number_format($float, 3, '.', ''), '0'), '.');
    }
}
