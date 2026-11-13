<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Modules\Billing\BillingPastDueService;

/**
 * Billing past-due sweep + Inc-10 migration/seed expectations
 * (docs/billing-invoices-contract.md Â§0-Â§1, Â§3, Â§5).
 *
 * PRE-WRITTEN by qa-mail (T79) for the Increment-10 gate; parked in
 * storage/temporary/pending-tests/ until T76/T77 land migration 10.
 *
 * T77 exposes the sweep as an in-process service (`BillingPastDueService::run()`)
 * with the CLI as a thin wrapper, so the behavioural cases run in-process (no
 * child-process/sandbox dependency); one small static test covers the CLI contract.
 */
final class BillingPastDueJobTest extends AdminModuleTestCase
{
    protected const PLATFORM_ADMIN = '+254700000020';

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function greenview(): array
    {
        return $this->seededEstate(self::ESTATE_SLUG);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createInvoice(array $overrides = [], string $estateSlug = self::ESTATE_SLUG): array
    {
        $estate = $this->seededEstate($estateSlug);
        $subscription = $this->db->selectOne(
            'SELECT * FROM subscriptions WHERE estate_id = :eid ORDER BY id DESC LIMIT 1',
            ['eid' => (int) $estate['id']]
        );
        $now = Clock::now();

        $payload = array_merge([
            'estate_uuid' => (string) $estate['uuid'],
            'estate_id' => (int) $estate['id'],
            'subscription_uuid' => (string) $subscription['uuid'],
            'subscription_id' => (int) $subscription['id'],
            'amount_cents' => 500000,
            'amount' => 500000,
            'currency' => 'KES',
            'period_start' => $now->modify('-1 month')->format('Y-m-d'),
            'period_end' => $now->format('Y-m-d'),
            'due_at' => Clock::toDb($now->modify('+14 days')),
            'notes' => 'Past-due test invoice',
        ], $overrides);

        $this->ensureLoggedInAs(self::PLATFORM_ADMIN);
        $this->assertStatus(302, $this->post('/platform/billing/invoices', $payload, true));

        $row = $this->db->selectOne(
            'SELECT * FROM billing_invoices WHERE estate_id = :eid ORDER BY id DESC LIMIT 1',
            ['eid' => (int) $estate['id']]
        );

        if ($row === null) {
            $this->fail('Invoice create must persist a row.');
        }

        return $row;
    }

    /**
     * Make an invoice overdue as a fixture (the create form only accepts a future
     * or omitted due date; the sweep is what flips real overdue rows).
     *
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    private function makeOverdue(array $invoice): array
    {
        $this->db->execute(
            'UPDATE billing_invoices SET due_at = :due WHERE id = :id',
            [
                'due' => Clock::toDb(Clock::now()->modify('-3 days')),
                'id' => (int) $invoice['id'],
            ]
        );

        return $this->invoiceById((int) $invoice['id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceById(int $id): array
    {
        $row = $this->db->selectOne('SELECT * FROM billing_invoices WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            $this->fail('Expected billing_invoices row ' . $id . '.');
        }

        return $row;
    }

    private function auditCount(string $action): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM audit_logs WHERE action = :action', ['action' => $action]);
    }

    /* ------------------------------------------------------------------ */
    /* Dry run vs apply                                                   */
    /* ------------------------------------------------------------------ */

    public function testDryRunReportsTheSweepButChangesNothing(): void
    {
        $overdue = $this->makeOverdue($this->createInvoice());
        $auditBefore = $this->auditCount('billing.invoice_status_changed');

        $result = BillingPastDueService::make()->run(true);

        $this->assertTrue((bool) $result['dry_run']);
        $this->assertTrue($result['scanned'] >= 1, 'The dry run must scan the overdue invoice.');
        $this->assertSame(0, (int) $result['flagged'], 'A dry run flags nothing.');

        $this->assertSame('open', (string) $this->invoiceById((int) $overdue['id'])['status'], 'A dry run must not flip any invoice.');
        $this->assertSame($auditBefore, $this->auditCount('billing.invoice_status_changed'), 'A dry run must not audit changes.');
        $this->assertSame(0, $this->auditCount('billing.past_due_swept'));
    }

    public function testApplyFlipsOnlyOverdueOpenInvoicesAndAudits(): void
    {
        $overdue = $this->makeOverdue($this->createInvoice());
        $dueLater = $this->createInvoice(['due_at' => Clock::toDb(Clock::now()->modify('+10 days'))]);
        $before = $this->auditCount('billing.invoice_status_changed');

        $result = BillingPastDueService::make()->run(false);

        $this->assertFalse((bool) $result['dry_run']);
        $this->assertSame(1, (int) $result['flagged'], 'Exactly the overdue invoice must be flagged.');

        $this->assertSame('past_due', (string) $this->invoiceById((int) $overdue['id'])['status'], 'An overdue open invoice must become past_due.');
        $this->assertSame('open', (string) $this->invoiceById((int) $dueLater['id'])['status'], 'A not-yet-due invoice must stay open.');

        $this->assertSame(
            $before + 1,
            $this->auditCount('billing.invoice_status_changed'),
            'Each flipped invoice must be audited as billing.invoice_status_changed.'
        );
        $this->assertSame(1, $this->auditCount('billing.past_due_swept'), 'A changed sweep writes one aggregate audit.');

        $audit = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action = 'billing.invoice_status_changed' ORDER BY id DESC LIMIT 1"
        );
        $metadata = (string) ($audit['metadata'] ?? '');
        $this->assertStringContainsString((string) $overdue['uuid'], $metadata);
        $this->assertStringContainsString('past_due', $metadata);
        $this->assertStringNotContainsString('+254', $metadata);
        $this->assertStringNotContainsString('@', $metadata);

        // Paid/void invoices are never touched by the sweep.
        $paid = $this->db->selectOne("SELECT id, status FROM billing_invoices WHERE status = 'paid' LIMIT 1");
        $this->assertNotNull($paid, 'The seeded paid invoice is required.');
        $this->assertSame('paid', (string) $this->invoiceById((int) $paid['id'])['status']);
    }

    public function testApplyIsIdempotentOnASecondRun(): void
    {
        $overdue = $this->makeOverdue($this->createInvoice());

        $first = BillingPastDueService::make()->run(false);
        $this->assertSame(1, (int) $first['flagged']);
        $afterFirst = $this->auditCount('billing.invoice_status_changed');
        $sweptAfterFirst = $this->auditCount('billing.past_due_swept');
        $this->assertSame('past_due', (string) $this->invoiceById((int) $overdue['id'])['status']);

        $second = BillingPastDueService::make()->run(false);
        $this->assertSame(0, (int) $second['flagged'], 'past_due rows are not scanned again.');
        $this->assertSame(0, (int) $second['scanned']);

        $this->assertSame($afterFirst, $this->auditCount('billing.invoice_status_changed'), 'An idempotent second run must not audit again.');
        $this->assertSame($sweptAfterFirst, $this->auditCount('billing.past_due_swept'));
        $this->assertSame('past_due', (string) $this->invoiceById((int) $overdue['id'])['status']);
    }

    public function testEstateScopeOptionScopesTheSweep(): void
    {
        $greenview = $this->greenview();
        $riverside = $this->seededEstate('riverside-court');

        $greenviewInvoice = $this->makeOverdue($this->createInvoice());
        $riversideInvoice = $this->makeOverdue($this->createInvoice([], 'riverside-court'));

        $result = BillingPastDueService::make()->run(false, (int) $greenview['id']);
        $this->assertSame(1, (int) $result['flagged'], 'A scoped run only flags the scoped estate.');

        $this->assertSame('past_due', (string) $this->invoiceById((int) $greenviewInvoice['id'])['status']);
        $this->assertSame('open', (string) $this->invoiceById((int) $riversideInvoice['id'])['status'], 'A scoped run must not touch other estates.');

        $result = BillingPastDueService::make()->run(false, (int) $riverside['id']);
        $this->assertSame(1, (int) $result['flagged']);
        $this->assertSame('past_due', (string) $this->invoiceById((int) $riversideInvoice['id'])['status']);
    }

    public function testCliContractIsACliOnlyThinWrapper(): void
    {
        $script = BASE_PATH . '/scripts/billing-past-due.php';
        $this->assertTrue(is_file($script), 'scripts/billing-past-due.php must exist.');
        $source = (string) file_get_contents($script);

        $this->assertStringContainsString("PHP_SAPI !== 'cli'", $source);
        $this->assertStringContainsString("'--dry-run'", $source);
        $this->assertStringContainsString("'--estate='", $source);
        $this->assertStringContainsString('BillingPastDueService', $source, 'The CLI must be a thin wrapper over the testable service.');
        $this->assertStringContainsString('run($dryRun, $estateId)', $source);
        $this->assertStringNotContainsString('var_dump', $source);
        $this->assertStringNotContainsString('print_r', $source);
        $this->assertStringContainsString('exit(2)', $source, 'Unknown arguments/estate uuids must exit 2.');
    }

    /* ------------------------------------------------------------------ */
    /* Migration 10 + seed expectations                                   */
    /* ------------------------------------------------------------------ */

    public function testMigrationTenStateAndCompositeTenantGuard(): void
    {
        $migrationFiles = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        $this->assertCount(12, $migrationFiles, 'Increments 12/13 plus the privacy centre leave 12 migration files.');

        $tables = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        );
        $this->assertSame(43, $tables, 'Increments 12/13 plus the privacy centre leave 43 tables.');

        $roleLinks = (int) $this->db->scalar('SELECT COUNT(*) FROM role_permissions');
        $this->assertSame(217, $roleLinks, 'Billing adds no permissions/links: role links stay 217.');

        $invoiceIndex = $this->db->selectOne(
            "SHOW INDEX FROM billing_invoices WHERE Key_name = 'uq_billing_invoices_id_estate'"
        );
        $this->assertNotNull($invoiceIndex, 'billing_invoices must expose UNIQUE(id, estate_id) for the composite FK.');

        $subscriptionsIndex = $this->db->selectOne(
            "SHOW INDEX FROM subscriptions WHERE Key_name = 'uq_subscriptions_id_estate'"
        );
        $this->assertNotNull($subscriptionsIndex, 'Migration 10 must add UNIQUE(id, estate_id) on subscriptions.');

        // Cross-tenant composite FK: a payment for another estate's invoice is
        // rejected by the database, not just by application code.
        $riversideInvoice = $this->createInvoice([], 'riverside-court');
        $greenview = $this->greenview();
        $admin = $this->seededUser(self::PLATFORM_ADMIN);

        $rejected = false;

        try {
            $this->db->insert('billing_payments', [
                'uuid' => $this->newUuid(),
                'estate_id' => (int) $greenview['id'],
                'invoice_id' => (int) $riversideInvoice['id'],
                'method' => 'cash',
                'reference' => 'CROSS-TENANT',
                'amount_cents' => 1000,
                'currency' => 'KES',
                'status' => 'confirmed',
                'recorded_by_user_id' => (int) $admin['id'],
                'created_at' => Clock::nowDb(),
                'updated_at' => Clock::nowDb(),
            ]);
        } catch (\Throwable) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite (invoice_id, estate_id) FK must reject cross-tenant payments.');
    }

    public function testBillingSeedFixturesArePresentAndIdempotent(): void
    {
        $greenview = $this->greenview();

        $invoices = $this->db->select(
            'SELECT * FROM billing_invoices WHERE estate_id = :eid ORDER BY id ASC',
            ['eid' => (int) $greenview['id']]
        );
        $this->assertCount(3, $invoices, 'Three demo invoices are seeded for Greenview (2 subscription + 1 utility).');
        $this->assertSame(0, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM billing_invoices i INNER JOIN estates e ON e.id = i.estate_id WHERE e.slug = :slug',
            ['slug' => 'riverside-court']
        ), 'Riverside must stay billing-empty for the empty-state demo.');

        $numbers = array_map(static fn (array $row): string => (string) $row['invoice_number'], $invoices);
        $this->assertTrue(in_array('INV-202610-0001', $numbers, true), 'The seeded paid invoice number must be stable (INV-202610-0001).');
        $this->assertTrue((bool) preg_grep('/^INV-\d{6}-0001$/', $numbers), 'A current-month -0001 subscription invoice is seeded.');
        $this->assertTrue((bool) preg_grep('/^INV-\d{6}-9001$/', $numbers), 'The Inc-13 utility demo invoice (-9001) is seeded.');

        $paid = $this->db->selectOne(
            "SELECT * FROM billing_invoices WHERE estate_id = :eid AND status = 'paid' LIMIT 1",
            ['eid' => (int) $greenview['id']]
        );
        $this->assertNotNull($paid, 'One demo invoice must be paid.');
        $this->assertNotNull($paid['paid_at']);

        $payment = $this->db->selectOne(
            'SELECT * FROM billing_payments WHERE invoice_id = :iid LIMIT 1',
            ['iid' => (int) $paid['id']]
        );
        $this->assertNotNull($payment, 'The paid invoice must have one confirmed M-Pesa demo payment.');
        $this->assertSame('mpesa', (string) $payment['method']);
        $this->assertSame('confirmed', (string) $payment['status']);
        $this->assertSame('DEMO-MPESA-001', (string) $payment['reference']);

        // Re-running the idempotent seeder must not duplicate or rewrite rows.
        $before = [
            'invoices' => (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices'),
            'payments' => (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments'),
        ];

        \Tests\TestDatabase::seed();

        $this->assertSame($before['invoices'], (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices'), 'The seeder must be idempotent (invoices).');
        $this->assertSame($before['payments'], (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments'), 'The seeder must be idempotent (payments).');
    }
}

