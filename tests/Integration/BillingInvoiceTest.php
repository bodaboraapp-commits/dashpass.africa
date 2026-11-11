<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;

/**
 * Platform billing — invoices (docs/billing-invoices-contract.md §1-§3, §5).
 *
 * PRE-WRITTEN by qa-mail (T79) for the Increment-10 gate; parked in
 * storage/temporary/pending-tests/ until migration 10 + T77/T78 land.
 * The staged migration (storage/temporary/inc10) and the DemoBillingSeeder
 * constants freeze the DB shape and seed rows, so DB assertions are precise;
 * create/status form field names are sent canonically (+ aliases) because the
 * contract does not freeze them.
 *
 * Routes: 158 -> 165 (7 platform billing web routes, `['auth']` + controller
 * platform gates only — never estate-scoped `permission:` middleware).
 */
final class BillingInvoiceTest extends AdminModuleTestCase
{
    protected const PLATFORM_ADMIN = '+254700000020';

    private const SEEDED_OPEN_INVOICE = '5a000001-0000-4000-8000-000000000002';

    private const SEEDED_PAID_INVOICE = '5a000001-0000-4000-8000-000000000001';

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function platformGet(string $path, string $phone = self::PLATFORM_ADMIN): \App\Core\Response
    {
        $this->ensureLoggedInAs($phone);

        return $this->get($path);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function platformPost(
        string $path,
        array $body = [],
        string $phone = self::PLATFORM_ADMIN,
        bool $csrf = true
    ): \App\Core\Response {
        $this->ensureLoggedInAs($phone);

        return $this->post($path, $body, $csrf);
    }

    private function grantPlatformRole(string $phone, string $roleName): void
    {
        $user = $this->seededUser($phone);
        $roleId = (int) $this->db->scalar(
            "SELECT id FROM roles WHERE scope = 'platform' AND name = :name LIMIT 1",
            ['name' => $roleName]
        );
        $this->assertTrue($roleId > 0, 'Seeded platform role ' . $roleName . ' must exist.');

        $now = Clock::nowDb();
        $this->db->insert('platform_memberships', [
            'uuid' => $this->newUuid(),
            'user_id' => (int) $user['id'],
            'role_id' => $roleId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function greenview(): array
    {
        return $this->seededEstate(self::ESTATE_SLUG);
    }

    /**
     * @return array<string, mixed>
     */
    private function greenviewSubscription(): array
    {
        $estate = $this->greenview();
        $row = $this->db->selectOne(
            'SELECT * FROM subscriptions WHERE estate_id = :eid ORDER BY id DESC LIMIT 1',
            ['eid' => (int) $estate['id']]
        );

        if ($row === null) {
            $this->fail('The seeded Greenview subscription is required.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function riversideSubscription(): array
    {
        $estate = $this->seededEstate('riverside-court');
        $row = $this->db->selectOne(
            'SELECT * FROM subscriptions WHERE estate_id = :eid ORDER BY id DESC LIMIT 1',
            ['eid' => (int) $estate['id']]
        );

        if ($row === null) {
            $this->fail('The seeded Riverside subscription is required.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function invoicePayload(array $overrides = []): array
    {
        $estate = $this->greenview();
        $subscription = $this->greenviewSubscription();
        $now = Clock::now();

        return array_merge([
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
            'notes' => 'QA billing invoice',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> The stored invoice row.
     */
    private function createInvoice(array $overrides = []): array
    {
        $payload = $this->invoicePayload($overrides);
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices');

        $response = $this->platformPost('/platform/billing/invoices', $payload);
        $this->assertStatus(302, $response, 'A successful invoice create must redirect (PRG).');

        $this->assertSame($before + 1, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices'), 'Exactly one invoice must be created.');

        $row = $this->db->selectOne(
            'SELECT * FROM billing_invoices ORDER BY id DESC LIMIT 1'
        );

        if ($row === null) {
            $this->fail('The created invoice must be stored.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceByUuid(string $uuid): array
    {
        $row = $this->db->selectOne('SELECT * FROM billing_invoices WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);

        if ($row === null) {
            $this->fail('Expected billing_invoices row ' . $uuid . '.');
        }

        return $row;
    }

    private function auditCount(string $action): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM audit_logs WHERE action = :action', ['action' => $action]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestAudit(string $action): ?array
    {
        return $this->db->selectOne('SELECT * FROM audit_logs WHERE action = :action ORDER BY id DESC LIMIT 1', ['action' => $action]);
    }

    /**
     * @param array<int, string> $markers
     */
    private function assertRejected(\App\Core\Response $response, string $fallbackPath, array $markers): void
    {
        if (in_array($response->status(), [409, 422], true)) {
            $haystack = $response->body();
        } elseif ($response->status() === 302) {
            $location = (string) $response->header('Location');
            $follow = $this->get($location !== '' ? $location : $fallbackPath);
            $haystack = $follow->body();
        } else {
            $this->fail('Expected a billing rejection (409/422/PRG 302), got HTTP ' . $response->status() . '.');
        }

        foreach ($markers as $marker) {
            if (stripos($haystack, $marker) !== false) {
                return;
            }
        }

        $this->fail('The rejection must contain one of: ' . implode(', ', $markers) . '.');
    }

    private function nextInvoiceNumber(): string
    {
        $estate = $this->greenview();
        $prefix = 'INV-' . Clock::now()->format('Ym') . '-';
        $rows = $this->db->select(
            'SELECT invoice_number FROM billing_invoices WHERE estate_id = :eid AND invoice_number LIKE :prefix',
            ['eid' => (int) $estate['id'], 'prefix' => $prefix . '%']
        );

        $max = 0;

        foreach ($rows as $row) {
            $suffix = (int) substr((string) $row['invoice_number'], strlen($prefix));
            $max = max($max, $suffix);
        }

        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /* ------------------------------------------------------------------ */
    /* Platform gate                                                      */
    /* ------------------------------------------------------------------ */

    public function testBillingSurfaceIsPlatformFinanceOnly(): void
    {
        $this->assertStatus(200, $this->platformGet('/platform/billing'));
        $this->assertStatus(200, $this->platformGet('/platform/billing/invoices/new'));

        // platform_finance can read and manage billing.
        $this->grantPlatformRole(self::BRIAN, 'platform_finance');
        $this->assertStatus(200, $this->platformGet('/platform/billing', self::BRIAN));
        $this->assertStatus(200, $this->platformGet('/platform/billing/invoices/new', self::BRIAN));

        // Estate-only users are forbidden everywhere.
        foreach ([self::GRACE, self::JANE, self::DAVID_RIVERSIDE, self::GUARD_MAIN] as $phone) {
            $this->assertStatus(403, $this->platformGet('/platform/billing', $phone));
            $this->assertStatus(403, $this->platformGet('/platform/billing/invoices/new', $phone));
        }

        // Unauthenticated -> login redirect.
        $this->resetSession();
        $this->assertRedirect($this->get('/platform/billing'), '/login', 302);

        // Estate users cannot create invoices either.
        $this->assertStatus(403, $this->platformPost('/platform/billing/invoices', $this->invoicePayload(), self::GRACE));
    }

    /* ------------------------------------------------------------------ */
    /* Create + numbering                                                 */
    /* ------------------------------------------------------------------ */

    public function testCreateInvoiceUsesThePlanAmountAndTransactionalNumbering(): void
    {
        $expectedFirst = $this->nextInvoiceNumber();
        $this->assertMatches('/^INV-\d{6}-\d{4}$/', $expectedFirst);

        $first = $this->createInvoice(['amount_cents' => 500000, 'amount' => 500000]);
        $this->assertSame($expectedFirst, (string) $first['invoice_number']);

        $expectedSecond = $this->nextInvoiceNumber();
        $this->assertNotSame((string) $first['invoice_number'], $expectedSecond, 'A second invoice must get the next sequence number.');

        $second = $this->createInvoice();
        $this->assertSame($expectedSecond, (string) $second['invoice_number']);

        $estate = $this->greenview();
        $subscription = $this->greenviewSubscription();
        $this->assertSame((int) $estate['id'], (int) $first['estate_id']);
        $this->assertSame((int) $estate['account_id'], (int) $first['account_id']);
        $this->assertSame((int) $subscription['id'], (int) $first['subscription_id']);
        $this->assertSame(500000, (int) $first['amount_cents']);
        $this->assertSame('KES', (string) $first['currency']);
        $this->assertSame('open', (string) $first['status']);
        $this->assertNull($first['paid_at']);
        $this->assertSame((int) $this->seededUser(self::PLATFORM_ADMIN)['id'], (int) $first['created_by_user_id']);

        $this->assertSame(2, $this->auditCount('billing.invoice_created'), 'Both created invoices must be audited.');

        $audit = $this->latestAudit('billing.invoice_created');
        $this->assertNotNull($audit);
        $metadata = (string) $audit['metadata'];
        $this->assertStringContainsString((string) $first['uuid'], $metadata);
        $this->assertStringContainsString('500000', $metadata);
        $this->assertStringContainsString('KES', $metadata);
        $this->assertStringNotContainsString('+254', $metadata, 'Audit metadata must be PII-free.');
        $this->assertStringNotContainsString('@', $metadata, 'Audit metadata must never contain an email address.');
    }

    public function testNumberingIsProtectedByAUniqueConstraint(): void
    {
        $index = $this->db->selectOne(
            "SHOW INDEX FROM billing_invoices WHERE Key_name = 'uq_billing_invoices_estate_number'"
        );
        $this->assertNotNull($index, 'UNIQUE(estate_id, invoice_number) must exist (transactional numbering duplicate guard).');

        $composite = $this->db->selectOne(
            "SHOW INDEX FROM billing_invoices WHERE Key_name = 'uq_billing_invoices_id_estate'"
        );
        $this->assertNotNull($composite, 'UNIQUE(id, estate_id) parent index for the payments composite FK must exist.');

        $subscriptionIndex = $this->db->selectOne(
            "SHOW INDEX FROM subscriptions WHERE Key_name = 'uq_subscriptions_id_estate'"
        );
        $this->assertNotNull($subscriptionIndex, 'The additive subscriptions UNIQUE(id, estate_id) index must exist.');
    }

    public function testDraftCreationAndValidation(): void
    {
        $draft = $this->createInvoice(['save_draft' => 1, 'status' => 'draft']);
        $this->assertSame('draft', (string) $draft['status']);

        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices');

        $riverside = $this->riversideSubscription();

        $cases = [
            // Numeric bounds: the Validator `min` rule is LENGTH-based, so the
            // contract's amount>0 requires a numeric rule (between/gt). Zero and
            // negative amounts must never create an invoice.
            'zero amount' => ['amount_cents' => 0, 'amount' => 0],
            'negative amount' => ['amount_cents' => -100, 'amount' => -100],
            'bad currency' => ['currency' => 'KSH1'],
            'period reversed' => ['period_start' => '2026-12-31', 'period_end' => '2026-01-01'],
            'long notes' => ['notes' => str_repeat('n', 501)],
            // A subscription from another estate must be rejected (composite
            // tenant guard / app-level estate match), never silently dropped.
            'foreign subscription' => [
                'subscription_uuid' => (string) $riverside['uuid'],
                'subscription_id' => (int) $riverside['id'],
            ],
        ];

        foreach ($cases as $label => $overrides) {
            $response = $this->platformPost('/platform/billing/invoices', $this->invoicePayload($overrides));
            $this->assertRejected($response, '/platform/billing/invoices/new', [
                'amount', 'currency', 'period', 'notes', 'subscription', 'required', 'valid',
            ]);
        }

        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices'), 'Rejected payloads must not create invoices.');
    }

    public function testArchivedEstateCannotBeInvoiced(): void
    {
        $estate = $this->greenview();
        $this->assertStatus(302, $this->platformPost('/platform/estates/' . (string) $estate['uuid'] . '/status', [
            'status' => 'archived',
        ]));

        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices');
        $response = $this->platformPost('/platform/billing/invoices', $this->invoicePayload());

        $this->assertRejected($response, '/platform/billing/invoices/new', ['ESTATE_ARCHIVED', 'archived']);
        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices'));
    }

    /* ------------------------------------------------------------------ */
    /* Status transitions                                                 */
    /* ------------------------------------------------------------------ */

    public function testInvoiceStatusTransitionsIncludingTerminalVoid(): void
    {
        $invoice = $this->createInvoice(['due_at' => Clock::toDb(Clock::now()->modify('+14 days'))]);
        $uuid = (string) $invoice['uuid'];

        // open -> past_due (manual correction path) then -> open is not in the matrix.
        $this->assertStatus(302, $this->platformPost('/platform/billing/invoices/' . $uuid . '/status', ['status' => 'past_due']));
        $this->assertSame('past_due', (string) $this->invoiceByUuid($uuid)['status']);

        // past_due -> void is allowed.
        $this->assertStatus(302, $this->platformPost('/platform/billing/invoices/' . $uuid . '/status', ['status' => 'void']));
        $void = $this->invoiceByUuid($uuid);
        $this->assertSame('void', (string) $void['status']);

        // void is terminal: every further transition is INVOICE_CLOSED.
        foreach (['paid', 'open', 'past_due', 'draft'] as $status) {
            $this->assertRejected(
                $this->platformPost('/platform/billing/invoices/' . $uuid . '/status', ['status' => $status]),
                '/platform/billing/invoices/' . $uuid,
                ['INVOICE_CLOSED', 'closed', 'void']
            );
        }
        $this->assertSame('void', (string) $this->invoiceByUuid($uuid)['status']);

        $this->assertTrue($this->auditCount('billing.invoice_status_changed') >= 2);

        // No hard delete: the voided row is still there.
        $this->assertSame($uuid, (string) $this->invoiceByUuid($uuid)['uuid']);
    }

    public function testMarkingPaidRequiresACoveringConfirmedPayment(): void
    {
        $invoice = $this->createInvoice();
        $uuid = (string) $invoice['uuid'];

        // No payment at all -> PAYMENT_REQUIRED.
        $this->assertRejected(
            $this->platformPost('/platform/billing/invoices/' . $uuid . '/status', ['status' => 'paid']),
            '/platform/billing/invoices/' . $uuid,
            ['PAYMENT_REQUIRED', 'PAYMENT_AMOUNT_MISMATCH', 'payment', 'cover']
        );
        $this->assertSame('open', (string) $this->invoiceByUuid($uuid)['status']);

        // Partial payment -> amount mismatch.
        $this->assertStatus(302, $this->platformPost('/platform/billing/invoices/' . $uuid . '/payments', [
            'method' => 'mpesa',
            'reference' => 'PARTIAL-001',
            'amount_cents' => 200000,
            'amount' => 200000,
            'status' => 'confirmed',
        ]));
        $this->assertRejected(
            $this->platformPost('/platform/billing/invoices/' . $uuid . '/status', ['status' => 'paid']),
            '/platform/billing/invoices/' . $uuid,
            ['PAYMENT_REQUIRED', 'PAYMENT_AMOUNT_MISMATCH', 'payment', 'cover']
        );

        // Covering remainder -> the recording itself auto-marks paid.
        $this->assertStatus(302, $this->platformPost('/platform/billing/invoices/' . $uuid . '/payments', [
            'method' => 'bank',
            'reference' => 'BALANCE-001',
            'amount_cents' => 300000,
            'amount' => 300000,
            'status' => 'confirmed',
        ]));

        $paid = $this->invoiceByUuid($uuid);
        $this->assertSame('paid', (string) $paid['status']);
        $this->assertNotNull($paid['paid_at'], 'paid_at must be set when the covering payment lands.');
    }

    /* ------------------------------------------------------------------ */
    /* Reads, isolation, CSRF, XSS, no-delete                             */
    /* ------------------------------------------------------------------ */

    public function testListFilterDetailAndForeignNotFound(): void
    {
        $open = $this->createInvoice();
        $draft = $this->createInvoice(['save_draft' => 1]);

        $list = $this->platformGet('/platform/billing');
        $this->assertStatus(200, $list);
        $this->assertTextContains((string) $open['invoice_number'], $list);
        $this->assertTextContains((string) $draft['invoice_number'], $list);

        $draftFilter = $this->platformGet('/platform/billing?status=draft');
        $this->assertStatus(200, $draftFilter);
        $this->assertTextContains((string) $draft['invoice_number'], $draftFilter);
        $this->assertTextNotContains((string) $open['invoice_number'], $draftFilter);

        $detail = $this->platformGet('/platform/billing/invoices/' . (string) $open['uuid']);
        $this->assertStatus(200, $detail);
        $this->assertTextContains((string) $open['invoice_number'], $detail);
        $this->assertTextContains('5,000', $detail, 'The amount should render from integer cents.');

        // Unknown/foreign uuid -> 404 (never another estate's row).
        $this->assertStatus(404, $this->platformGet('/platform/billing/invoices/' . $this->newUuid()));

        // The seeded demo invoices are visible to platform finance.
        $this->assertStatus(200, $this->platformGet('/platform/billing/invoices/' . self::SEEDED_OPEN_INVOICE));
        $this->assertStatus(200, $this->platformGet('/platform/billing/invoices/' . self::SEEDED_PAID_INVOICE));
    }

    public function testCsrfAndXssOnInvoiceScreens(): void
    {
        // CSRF.
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices');
        $csrfless = $this->post('/platform/billing/invoices', $this->invoicePayload(), false);
        $this->assertStatus(403, $csrfless);
        $this->assertBodyContains('CSRF_TOKEN_INVALID', $csrfless);
        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices'));

        // XSS: notes are rendered escaped on the detail screen.
        $invoice = $this->createInvoice([
            'notes' => '<script>alert("billing xss")</script> note',
        ]);

        $detail = $this->platformGet('/platform/billing/invoices/' . (string) $invoice['uuid']);
        $this->assertStatus(200, $detail);
        $this->assertStringNotContainsString('<script>alert("billing xss")</script>', $detail->body());
        $this->assertTextContains('<script>alert("billing xss")</script>', $detail, 'The note must render as escaped text.');
        $this->assertCleanMarkup($detail, 'Billing invoice detail');

        $list = $this->platformGet('/platform/billing');
        $this->assertCleanMarkup($list, 'Billing dashboard');
    }

    public function testInvoicesAreNeverHardDeletedAndAuditIsPiiFree(): void
    {
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices');
        $invoice = $this->createInvoice();
        $uuid = (string) $invoice['uuid'];

        $this->assertStatus(302, $this->platformPost('/platform/billing/invoices/' . $uuid . '/status', ['status' => 'void']));

        $this->assertSame($before + 1, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices'));
        $this->assertSame('void', (string) $this->invoiceByUuid($uuid)['status']);

        $audit = $this->latestAudit('billing.invoice_status_changed');
        $this->assertNotNull($audit);
        $metadata = (string) $audit['metadata'];
        $this->assertStringContainsString((string) $uuid, $metadata);
        $this->assertStringContainsString('void', $metadata);
        $this->assertStringNotContainsString('+254', $metadata);
        $this->assertStringNotContainsString('@', $metadata);
    }
}
