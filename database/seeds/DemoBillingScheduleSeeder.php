<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Clock;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Seeds the Increment-13 demo automation fixtures (frozen T159/T165 contract):
 *
 *  - 1 `estate_subscription` schedule for the Greenview "growth" subscription
 *    (monthly, lead 3, due 14, reminders 3 before + on due + [7,14,30]);
 *  - 1 `utility_bill` schedule for household A-001 water (monthly flat KES 1,200);
 *  - 1 demo utility invoice (`INV-<YYYYMM>-9001`, reserved demo range) linked to the
 *    utility schedule, household A-001, `kind='utility'`, plus exactly one
 *    `utility_charges` row for the current period (frozen per-household/period detail).
 *
 * Rows are matched by stable UUIDs and natural keys (subscription_id / (estate, scope,
 * name) / (billing_schedule_id, household_id, period_key) / (estate_id, invoice_number)),
 * timestamps and numbers are create-only, demo status self-heals, and repeated runs are
 * no-ops.
 */
final class DemoBillingScheduleSeeder extends Seeder
{
    public const ESTATE_SLUG = 'greenview-residences';
    public const PLAN_CODE = 'growth';

    public const SUBSCRIPTION_SCHEDULE_UUID = '7a000001-0000-4000-8000-000000000001';
    public const UTILITY_SCHEDULE_UUID = '7a000001-0000-4000-8000-000000000002';
    public const UTILITY_INVOICE_UUID = '7b000001-0000-4000-8000-000000000001';
    public const UTILITY_CHARGE_UUID = '7c000001-0000-4000-8000-000000000001';

    public const UTILITY_NAME = 'Water bill - A-001';
    public const UTILITY_FLAT_CENTS = 120000;
    public const FINANCE_PHONE = '+254700000020';

    /** @var array<int, string> */
    public const STAT_KEYS = [
        'schedules_created',
        'invoices_created',
        'charges_created',
        'fixtures_updated',
    ];

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $this->resetCounters(self::STAT_KEYS);

        $estate = $this->db->selectOne(
            'SELECT id, account_id, timezone FROM estates WHERE slug = :slug LIMIT 1',
            ['slug' => self::ESTATE_SLUG]
        );

        if ($estate === null) {
            throw new RuntimeException(
                'Billing schedule fixtures need the "' . self::ESTATE_SLUG . '" estate. Run the foundation demo seeder first.'
            );
        }

        $estateId = (int) $estate['id'];
        $accountId = (int) ($estate['account_id'] ?? 0);
        $timezone = new DateTimeZone((string) ($estate['timezone'] ?? 'Africa/Nairobi'));
        $now = new DateTimeImmutable('now', $timezone);
        $periodKey = $now->format('Ym');
        $nextMonth = $now->modify('first day of next month')->setTime(0, 0, 0);

        $financeUserId = $this->requireId(
            'users',
            ['phone' => self::FINANCE_PHONE],
            'platform finance user Daniel Mwangi'
        );

        $subscription = $this->db->selectOne(
            "SELECT s.id, s.estate_id, s.plan_id, p.code, p.monthly_price_cents, p.currency
             FROM subscriptions s
             JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.estate_id = :estate_id AND p.code = :plan_code
             ORDER BY s.starts_at DESC, s.id DESC
             LIMIT 1",
            ['estate_id' => $estateId, 'plan_code' => self::PLAN_CODE]
        );

        if ($subscription === null) {
            throw new RuntimeException(
                'Billing schedule fixtures need the Greenview "' . self::PLAN_CODE . '" subscription.'
            );
        }

        $subscriptionId = (int) $subscription['id'];

        $householdId = $this->findId('households', ['estate_id' => $estateId, 'unit_number' => 'A-001']);

        if ($householdId === null) {
            throw new RuntimeException('Billing schedule fixtures need household A-001 on Greenview.');
        }

        /* ------------------------------------------------------------------ */
        /* 1. Subscription schedule (one per subscription; unique subscription_id) */
        /* ------------------------------------------------------------------ */
        $this->ensureSchedule(self::SUBSCRIPTION_SCHEDULE_UUID, [
            'scope' => 'estate_subscription',
            'estate_id' => $estateId,
            'subscription_id' => $subscriptionId,
            'household_id' => null,
            'name' => null,
            'utility_type' => null,
            'flat_amount_cents' => null,
            'tariff_cents_per_unit' => null,
            'unit_label' => null,
            'currency' => 'KES',
            'period_unit' => 'month',
            'generate_lead_days' => 3,
            'due_days' => 14,
            'reminder_before_days' => 3,
            'reminder_on_due' => 1,
            'proration_policy' => 'none',
            'send_on_generate' => 1,
            'status' => 'active',
            'next_run_at' => Clock::toDb($nextMonth),
        ], $financeUserId);

        /* ------------------------------------------------------------------ */
        /* 2. Utility schedule (A-001 water, monthly flat)                     */
        /* ------------------------------------------------------------------ */
        $utilityScheduleId = $this->ensureSchedule(self::UTILITY_SCHEDULE_UUID, [
            'scope' => 'utility_bill',
            'estate_id' => $estateId,
            'subscription_id' => null,
            'household_id' => $householdId,
            'name' => self::UTILITY_NAME,
            'utility_type' => 'water',
            'flat_amount_cents' => self::UTILITY_FLAT_CENTS,
            'tariff_cents_per_unit' => null,
            'unit_label' => null,
            'currency' => 'KES',
            'period_unit' => 'month',
            'generate_lead_days' => 2,
            'due_days' => 14,
            'reminder_before_days' => 3,
            'reminder_on_due' => 1,
            'proration_policy' => 'none',
            'send_on_generate' => 1,
            'status' => 'active',
            'next_run_at' => Clock::toDb($nextMonth),
        ], $financeUserId);

        /* ------------------------------------------------------------------ */
        /* 3. Demo utility invoice + the single per-period charge row          */
        /* ------------------------------------------------------------------ */
        if ($accountId <= 0) {
            throw new RuntimeException('Billing schedule fixtures need the Greenview SaaS account.');
        }

        $invoiceNumber = $this->reserveDemoUtilityNumber($estateId, $periodKey);
        $dueAt = Clock::toDb($now->modify('+14 days')->setTime(9, 0, 0));
        $periodStart = $now->format('Y-m-01');
        $periodEnd = $now->format('Y-m-t');
        $snapshot = $this->json([
            'basis' => 'flat',
            'flat_amount_cents' => self::UTILITY_FLAT_CENTS,
            'unit_label' => null,
            'tariff_cents_per_unit' => null,
            'period_key' => $periodKey,
            'demo' => true,
        ]);

        $existingInvoice = $this->findId('billing_invoices', ['uuid' => self::UTILITY_INVOICE_UUID])
            ?? $this->findId('billing_invoices', ['estate_id' => $estateId, 'invoice_number' => $invoiceNumber]);

        if ($existingInvoice === null) {
            $invoiceId = $this->insert('billing_invoices', [
                'uuid' => self::UTILITY_INVOICE_UUID,
                'estate_id' => $estateId,
                'account_id' => $accountId,
                'subscription_id' => null,
                'invoice_number' => $invoiceNumber,
                'status' => 'open',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'amount_cents' => self::UTILITY_FLAT_CENTS,
                'currency' => 'KES',
                'due_at' => $dueAt,
                'paid_at' => null,
                'notes' => 'Demo utility bill - household A-001 water (current period).',
                'created_by_user_id' => $financeUserId,
                'updated_by_user_id' => null,
                'kind' => 'utility',
                'household_id' => $householdId,
                'source' => 'manual',
                'generation_key' => null,
                'period_key' => $periodKey,
                'pricing_snapshot' => $snapshot,
            ]);
            $this->count('invoices_created');
        } else {
            $invoiceId = $existingInvoice;
            $this->updateIfChanged('billing_invoices', $invoiceId, [
                'status' => 'open',
                'amount_cents' => self::UTILITY_FLAT_CENTS,
                'currency' => 'KES',
                'kind' => 'utility',
                'household_id' => $householdId,
                'period_key' => $periodKey,
                'pricing_snapshot' => $snapshot,
            ]);
        }

        $existingCharge = $this->findId('utility_charges', [
            'billing_schedule_id' => $utilityScheduleId,
            'household_id' => $householdId,
            'period_key' => $periodKey,
        ]) ?? $this->findId('utility_charges', ['uuid' => self::UTILITY_CHARGE_UUID]);

        $chargeValues = [
            'estate_id' => $estateId,
            'invoice_id' => $invoiceId,
            'household_id' => $householdId,
            'billing_schedule_id' => $utilityScheduleId,
            'period_key' => $periodKey,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'charge_model' => 'flat',
            'units' => null,
            'reading_start_units' => null,
            'reading_end_units' => null,
            'reading_source' => null,
            'flat_amount_cents' => self::UTILITY_FLAT_CENTS,
            'tariff_cents_per_unit' => null,
            'amount_cents' => self::UTILITY_FLAT_CENTS,
            'currency' => 'KES',
            'pricing_snapshot' => $snapshot,
            'created_by_user_id' => $financeUserId,
            'updated_by_user_id' => null,
        ];

        if ($existingCharge === null) {
            $this->insert('utility_charges', ['uuid' => self::UTILITY_CHARGE_UUID] + $chargeValues);
            $this->count('charges_created');
        } else {
            $this->updateIfChanged('utility_charges', $existingCharge, $chargeValues);
        }

        return $this->counters();
    }

    /**
     * Insert or self-heal one schedule (create-only timestamps/next_run; status refreshed).
     *
     * @param array<string, mixed> $values
     */
    private function ensureSchedule(string $uuid, array $values, int $financeUserId): int
    {
        if ($values['scope'] === 'estate_subscription') {
            $existing = $this->findId('billing_schedules', ['subscription_id' => $values['subscription_id']])
                ?? $this->findId('billing_schedules', ['uuid' => $uuid]);
        } else {
            $existing = $this->findId('billing_schedules', [
                'estate_id' => $values['estate_id'],
                'scope' => $values['scope'],
                'name' => $values['name'],
            ]) ?? $this->findId('billing_schedules', ['uuid' => $uuid]);
        }

        if ($existing === null) {
            $id = $this->insert('billing_schedules', ['uuid' => $uuid] + $values + [
                'last_run_at' => null,
                'last_period_key' => null,
                'created_by_user_id' => $financeUserId,
                'updated_by_user_id' => null,
            ]);
            $this->count('schedules_created');

            return $id;
        }

        $this->updateIfChanged('billing_schedules', $existing, [
            'status' => $values['status'],
        ]);

        return $existing;
    }

    /**
     * First free demo invoice number in the reserved 9001+ range for the estate/month.
     */
    private function reserveDemoUtilityNumber(int $estateId, string $periodKey): string
    {
        for ($sequence = 9001; $sequence <= 9099; $sequence++) {
            $candidate = 'INV-' . $periodKey . '-' . $sequence;
            $taken = $this->db->selectOne(
                'SELECT id FROM billing_invoices WHERE estate_id = :estate_id AND invoice_number = :number LIMIT 1',
                ['estate_id' => $estateId, 'number' => $candidate]
            );

            if ($taken === null || (int) $taken['id'] === 0) {
                return $candidate;
            }
        }

        throw new RuntimeException('No free reserved demo invoice number (INV-' . $periodKey . '-9001..9099).');
    }

    /**
     * @param array<string, mixed> $where
     */
    private function requireId(string $table, array $where, string $description): int
    {
        $id = $this->findId($table, $where);

        if ($id === null) {
            throw new RuntimeException(
                'Billing schedule fixtures need ' . $description . '. Run the foundation demo seeder first.'
            );
        }

        return $id;
    }

    private function updateIfChanged(string $table, int $id, array $expected): void
    {
        if ($id <= 0) {
            return;
        }

        $current = $this->db->selectOne('SELECT * FROM ' . $table . ' WHERE id = :id LIMIT 1', ['id' => $id]);

        if ($current === null) {
            return;
        }

        $changes = [];

        foreach ($expected as $column => $value) {
            if ((string) ($current[$column] ?? '') !== (string) ($value ?? '')) {
                $changes[$column] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        $this->update($table, $changes, ['id' => $id]);
        $this->count('fixtures_updated');
    }
}
