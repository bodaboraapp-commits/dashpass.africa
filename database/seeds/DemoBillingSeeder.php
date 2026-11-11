<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Clock;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Seeds the Increment-10 demo billing fixtures (billing-invoices-contract §1).
 *
 * Greenview Residences only (Riverside stays empty so the empty-state can be
 * demonstrated), using the existing Greenview "growth" subscription plan:
 *   - 1 PAID invoice from last month, number `INV-202610-0001`, paid_at set,
 *     with one confirmed M-Pesa payment `REF DEMO-MPESA-001`;
 *   - 1 OPEN invoice for the current month, number `INV-<YYYYMM>-0001`,
 *     due 14 days after first insert.
 *
 * Rows are matched by stable UUIDs (invoices `5a…`, payments `5b…`) and, for
 * invoices, also by `(estate_id, invoice_number)` so a re-run can never create
 * a duplicate invoice number. Timestamps/numbers are create-only (never
 * rewritten), demo status state self-heals, and repeated runs are no-ops.
 */
final class DemoBillingSeeder extends Seeder
{
    public const ESTATE_SLUG = 'greenview-residences';
    public const PLAN_CODE = 'growth';

    public const INVOICE_PAID_UUID = '5a000001-0000-4000-8000-000000000001';
    public const INVOICE_OPEN_UUID = '5a000001-0000-4000-8000-000000000002';
    public const PAYMENT_MPESA_UUID = '5b000001-0000-4000-8000-000000000001';

    public const INVOICE_PAID_NUMBER = 'INV-202610-0001';
    public const PAYMENT_REFERENCE = 'DEMO-MPESA-001';
    public const FINANCE_PHONE = '+254700000020';

    /** @var array<int, string> */
    public const STAT_KEYS = [
        'invoices_created',
        'payments_created',
        'fixtures_updated',
    ];

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $this->resetCounters(self::STAT_KEYS);

        $estate = $this->db->selectOne(
            'SELECT id, timezone FROM estates WHERE slug = :slug LIMIT 1',
            ['slug' => self::ESTATE_SLUG]
        );

        if ($estate === null) {
            throw new RuntimeException(
                'Billing demo fixtures need the "' . self::ESTATE_SLUG . '" estate. Run the foundation demo seeder first.'
            );
        }

        $subscription = $this->db->selectOne(
            "SELECT s.id, s.estate_id, s.plan_id, p.code, p.monthly_price_cents, p.currency
             FROM subscriptions s
             JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.estate_id = :estate_id AND p.code = :plan_code
             ORDER BY s.starts_at DESC, s.id DESC
             LIMIT 1",
            ['estate_id' => (int) $estate['id'], 'plan_code' => self::PLAN_CODE]
        );

        if ($subscription === null) {
            throw new RuntimeException(
                'Billing demo fixtures need the Greenview "' . self::PLAN_CODE . '" subscription. Run the foundation demo seeder first.'
            );
        }

        $account = $this->db->selectOne(
            'SELECT account_id FROM estates WHERE id = :id LIMIT 1',
            ['id' => (int) $estate['id']]
        );
        $accountId = (int) ($account['account_id'] ?? 0);

        if ($accountId <= 0) {
            throw new RuntimeException('Billing demo fixtures need the Greenview SaaS account.');
        }

        $financeUserId = $this->requireId(
            'users',
            ['phone' => self::FINANCE_PHONE],
            'platform finance user Daniel Mwangi'
        );

        $estateId = (int) $estate['id'];
        $timezone = new DateTimeZone((string) ($estate['timezone'] ?? 'Africa/Nairobi'));
        $now = new DateTimeImmutable('now', $timezone);

        $amountCents = (int) ($subscription['monthly_price_cents'] ?? 0);
        $currency = (string) ($subscription['currency'] ?? 'KES');

        if ($amountCents <= 0) {
            throw new RuntimeException('Billing demo fixtures need a positive plan amount.');
        }

        $subscriptionId = (int) $subscription['id'];

        $paidInvoice = $this->ensureInvoice([
            'uuid' => self::INVOICE_PAID_UUID,
            'invoice_number' => self::INVOICE_PAID_NUMBER,
            'estate_id' => $estateId,
            'account_id' => $accountId,
            'subscription_id' => $subscriptionId,
            'status' => 'paid',
            'period_start' => $now->modify('first day of last month')->format('Y-m-d'),
            'period_end' => $now->modify('last day of last month')->format('Y-m-d'),
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'due_at' => Clock::toDb($now->modify('first day of last month')->setTime(8, 0, 0)),
            'paid_at' => Clock::toDb($now->modify('first day of last month')->setTime(10, 0, 0)),
            'notes' => 'Demo invoice - paid in full by M-Pesa (previous billing period).',
            'created_by_user_id' => $financeUserId,
        ]);

        $openInvoice = $this->ensureInvoice([
            'uuid' => self::INVOICE_OPEN_UUID,
            'invoice_number' => 'INV-' . $now->format('Ym') . '-0001',
            'estate_id' => $estateId,
            'account_id' => $accountId,
            'subscription_id' => $subscriptionId,
            'status' => 'open',
            'period_start' => $now->format('Y-m-01'),
            'period_end' => $now->format('Y-m-t'),
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'due_at' => Clock::toDb($now->modify('+14 days')->setTime(9, 0, 0)),
            'paid_at' => null,
            'notes' => 'Demo invoice - current billing period, due in 14 days.',
            'created_by_user_id' => $financeUserId,
        ]);

        $this->ensurePayment([
            'uuid' => self::PAYMENT_MPESA_UUID,
            'estate_id' => $estateId,
            'invoice_id' => $paidInvoice['id'],
            'method' => 'mpesa',
            'reference' => self::PAYMENT_REFERENCE,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'status' => 'confirmed',
            'paid_at' => $paidInvoice['paid_at'],
            'recorded_by_user_id' => $financeUserId,
            'metadata' => ['demo' => true, 'invoice_number' => self::INVOICE_PAID_NUMBER],
        ]);

        return $this->counters();
    }

    /**
     * @param array<string, mixed> $spec
     * @return array{id: int, paid_at: ?string}
     */
    private function ensureInvoice(array $spec): array
    {
        $existingId = $this->findId('billing_invoices', ['uuid' => $spec['uuid']]);

        if ($existingId === null) {
            $existingId = $this->findId('billing_invoices', [
                'estate_id' => (int) $spec['estate_id'],
                'invoice_number' => (string) $spec['invoice_number'],
            ]);
        }

        if ($existingId === null) {
            $invoiceId = $this->insert('billing_invoices', [
                'uuid' => $spec['uuid'],
                'estate_id' => $spec['estate_id'],
                'account_id' => $spec['account_id'],
                'subscription_id' => $spec['subscription_id'],
                'invoice_number' => $spec['invoice_number'],
                'status' => $spec['status'],
                'period_start' => $spec['period_start'],
                'period_end' => $spec['period_end'],
                'amount_cents' => $spec['amount_cents'],
                'currency' => $spec['currency'],
                'due_at' => $spec['due_at'],
                'paid_at' => $spec['paid_at'],
                'notes' => $spec['notes'],
                'created_by_user_id' => $spec['created_by_user_id'],
                'updated_by_user_id' => null,
            ]);
            $this->count('invoices_created');

            return ['id' => $invoiceId, 'paid_at' => $spec['paid_at']];
        }

        // Demo state self-heals; invoice number/period/due_at stay create-only.
        $expected = [
            'estate_id' => $spec['estate_id'],
            'account_id' => $spec['account_id'],
            'subscription_id' => $spec['subscription_id'],
            'status' => $spec['status'],
            'amount_cents' => $spec['amount_cents'],
            'currency' => $spec['currency'],
            'notes' => $spec['notes'],
            'paid_at' => $spec['paid_at'],
        ];

        $this->updateIfChanged('billing_invoices', $existingId, $expected);

        return ['id' => $existingId, 'paid_at' => $spec['paid_at']];
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function ensurePayment(array $spec): int
    {
        $existingId = $this->findId('billing_payments', ['uuid' => $spec['uuid']]);

        if ($existingId === null && $spec['reference'] !== null) {
            $existingId = $this->findId('billing_payments', [
                'invoice_id' => (int) $spec['invoice_id'],
                'reference' => (string) $spec['reference'],
            ]);
        }

        $expected = [
            'estate_id' => $spec['estate_id'],
            'invoice_id' => $spec['invoice_id'],
            'method' => $spec['method'],
            'reference' => $spec['reference'],
            'amount_cents' => $spec['amount_cents'],
            'currency' => $spec['currency'],
            'status' => $spec['status'],
            'paid_at' => $spec['paid_at'],
            'recorded_by_user_id' => $spec['recorded_by_user_id'],
            'metadata' => $this->json($spec['metadata']),
        ];

        if ($existingId === null) {
            $paymentId = $this->insert('billing_payments', ['uuid' => $spec['uuid']] + $expected);
            $this->count('payments_created');

            return $paymentId;
        }

        $this->updateIfChanged('billing_payments', $existingId, $expected);

        return $existingId;
    }

    /**
     * Update a row from expected values, counting one refresh only when
     * something actually changed. Keeps repeated seed runs a no-op.
     *
     * @param array<string, mixed> $expected
     */
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

    /**
     * @param array<string, mixed> $where
     */
    private function requireId(string $table, array $where, string $description): int
    {
        $id = $this->findId($table, $where);

        if ($id === null) {
            throw new RuntimeException(
                'Billing demo fixtures need ' . $description . '. Run the foundation demo seeder first.'
            );
        }

        return $id;
    }
}
