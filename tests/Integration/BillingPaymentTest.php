<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;

/**
 * Platform billing — payments ledger + invoice payment invariants
 * (docs/billing-invoices-contract.md §3, §5).
 *
 * PRE-WRITTEN by qa-mail (T79) for the Increment-10 gate; parked in
 * storage/temporary/pending-tests/ until migration 10 + T77/T78 land.
 *
 * The staged schema freezes the columns; create/status field names are sent
 * canonically (+ aliases) because the contract does not freeze the form keys.
 */
final class BillingPaymentTest extends AdminModuleTestCase
{
    protected const PLATFORM_ADMIN = '+254700000020';

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $body
     */
    private function platformPost(string $path, array $body = [], bool $csrf = true): \App\Core\Response
    {
        $this->ensureLoggedInAs(self::PLATFORM_ADMIN);

        return $this->post($path, $body, $csrf);
    }

    private function platformGet(string $path): \App\Core\Response
    {
        $this->ensureLoggedInAs(self::PLATFORM_ADMIN);

        return $this->get($path);
    }

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
    private function createInvoice(array $overrides = []): array
    {
        $estate = $this->greenview();
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
            'period_start' => $now->format('Y-m-d'),
            'period_end' => $now->modify('+1 month')->format('Y-m-d'),
            'due_at' => Clock::toDb($now->modify('+14 days')),
            'notes' => 'Payment test invoice',
        ], $overrides);

        $this->assertStatus(302, $this->platformPost('/platform/billing/invoices', $payload));

        $row = $this->db->selectOne('SELECT * FROM billing_invoices ORDER BY id DESC LIMIT 1');

        if ($row === null) {
            $this->fail('Invoice create must persist a row.');
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

    /**
     * @return array<string, mixed>
     */
    private function paymentByUuid(string $uuid): array
    {
        $row = $this->db->selectOne('SELECT * FROM billing_payments WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);

        if ($row === null) {
            $this->fail('Expected billing_payments row ' . $uuid . '.');
        }

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paymentsForInvoice(array $invoice): array
    {
        return $this->db->select(
            'SELECT * FROM billing_payments WHERE invoice_id = :iid ORDER BY id ASC',
            ['iid' => (int) $invoice['id']]
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> The payment row.
     */
    private function recordPayment(array $invoice, array $overrides = []): array
    {
        $payload = array_merge([
            'method' => 'mpesa',
            'reference' => 'REF-' . strtoupper(bin2hex(random_bytes(3))),
            'amount_cents' => (int) $invoice['amount_cents'],
            'amount' => (int) $invoice['amount_cents'],
            'currency' => 'KES',
            'status' => 'confirmed',
        ], $overrides);

        $this->assertStatus(302, $this->platformPost(
            '/platform/billing/invoices/' . (string) $invoice['uuid'] . '/payments',
            $payload
        ));

        $row = $this->db->selectOne('SELECT * FROM billing_payments ORDER BY id DESC LIMIT 1');

        if ($row === null) {
            $this->fail('Recording a payment must persist a ledger row.');
        }

        return $row;
    }

    private function setPaymentStatus(array $payment, string $status, bool $csrf = true): \App\Core\Response
    {
        return $this->platformPost('/platform/billing/payments/' . (string) $payment['uuid'] . '/status', [
            'status' => $status,
        ], $csrf);
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
            $this->fail('Expected a payment rejection (409/422/PRG 302), got HTTP ' . $response->status() . '.');
        }

        foreach ($markers as $marker) {
            if (stripos($haystack, $marker) !== false) {
                return;
            }
        }

        $this->fail('The rejection must contain one of: ' . implode(', ', $markers) . '.');
    }

    /* ------------------------------------------------------------------ */
    /* Recording payments                                                 */
    /* ------------------------------------------------------------------ */

    public function testFullPaymentAutoMarksTheInvoicePaid(): void
    {
        $invoice = $this->createInvoice();
        $this->assertSame('open', (string) $invoice['status']);

        $payment = $this->recordPayment($invoice, ['method' => 'mpesa', 'reference' => 'MPESA-FULL-001', 'status' => 'confirmed']);

        $this->assertSame('confirmed', (string) $payment['status']);
        $this->assertSame((int) $invoice['id'], (int) $payment['invoice_id']);
        $this->assertSame((int) $invoice['estate_id'], (int) $payment['estate_id']);
        $this->assertSame(500000, (int) $payment['amount_cents']);
        $this->assertNotNull($payment['paid_at']);
        $this->assertSame((int) $this->seededUser(self::PLATFORM_ADMIN)['id'], (int) $payment['recorded_by_user_id']);

        $paidInvoice = $this->invoiceByUuid((string) $invoice['uuid']);
        $this->assertSame('paid', (string) $paidInvoice['status'], 'A covering confirmed payment auto-marks the invoice paid.');
        $this->assertNotNull($paidInvoice['paid_at']);

        $this->assertSame(1, $this->auditCount('billing.payment_recorded'));
        $this->assertTrue($this->auditCount('billing.invoice_status_changed') >= 1, 'The automatic paid transition must be audited.');

        $metadata = (string) ($this->latestAudit('billing.payment_recorded')['metadata'] ?? '');
        $this->assertStringContainsString((string) $payment['uuid'], $metadata);
        $this->assertStringContainsString('500000', $metadata);
        $this->assertStringNotContainsString('+254', $metadata);
        $this->assertStringNotContainsString('@', $metadata);
    }

    public function testPartialPaymentsKeepTheInvoiceOpenThenTheLastPaymentCovers(): void
    {
        $invoice = $this->createInvoice();

        $first = $this->recordPayment($invoice, ['amount_cents' => 200000, 'amount' => 200000, 'reference' => 'PART-1']);
        $this->assertSame('confirmed', (string) $first['status']);

        $open = $this->invoiceByUuid((string) $invoice['uuid']);
        $this->assertSame('open', (string) $open['status'], 'A partial payment must not auto-mark the invoice paid.');
        $this->assertNull($open['paid_at']);

        $detail = $this->platformGet('/platform/billing/invoices/' . (string) $invoice['uuid']);
        $this->assertStatus(200, $detail);
        $this->assertTextContains('3,000', $detail, 'The remaining balance should be shown (500000-200000 cents).');

        $second = $this->recordPayment($invoice, ['amount_cents' => 300000, 'amount' => 300000, 'reference' => 'PART-2']);
        $this->assertSame(2, count($this->paymentsForInvoice($invoice)), 'Both ledger rows must be retained.');

        $paid = $this->invoiceByUuid((string) $invoice['uuid']);
        $this->assertSame('paid', (string) $paid['status']);
        $this->assertNotNull($paid['paid_at']);
        $this->assertSame(2, $this->auditCount('billing.payment_recorded'));
    }

    /* ------------------------------------------------------------------ */
    /* Payment status lifecycle                                           */
    /* ------------------------------------------------------------------ */

    public function testPendingPaymentDoesNotPayUntilConfirmed(): void
    {
        $invoice = $this->createInvoice();

        $pending = $this->recordPayment($invoice, ['status' => 'pending', 'reference' => 'PENDING-1']);
        $this->assertSame('pending', (string) $pending['status']);
        $this->assertSame('open', (string) $this->invoiceByUuid((string) $invoice['uuid'])['status']);

        $this->assertStatus(302, $this->setPaymentStatus($pending, 'confirmed'));

        $confirmed = $this->paymentByUuid((string) $pending['uuid']);
        $this->assertSame('confirmed', (string) $confirmed['status']);
        $this->assertSame('paid', (string) $this->invoiceByUuid((string) $invoice['uuid'])['status']);

        // confirmed -> refunded is allowed and re-opens the invoice.
        $this->assertStatus(302, $this->setPaymentStatus($confirmed, 'refunded'));
        $refunded = $this->paymentByUuid((string) $pending['uuid']);
        $this->assertSame('refunded', (string) $refunded['status']);

        $reopened = $this->invoiceByUuid((string) $invoice['uuid']);
        $this->assertTrue(
            in_array((string) $reopened['status'], ['open', 'past_due'], true),
            'Refunding the only covering payment must re-open the invoice.'
        );
        $this->assertNull($reopened['paid_at'], 'Refund must clear paid_at.');

        $this->assertSame(2, $this->auditCount('billing.payment_status_changed'));
        $this->assertNotNull($this->latestAudit('billing.invoice_status_changed'));
    }

    public function testPaymentTerminalStatusesCannotTransition(): void
    {
        $invoice = $this->createInvoice();

        // failed is terminal.
        $failed = $this->recordPayment($invoice, ['status' => 'pending', 'reference' => 'FAIL-1']);
        $this->assertStatus(302, $this->setPaymentStatus($failed, 'failed'));
        $this->assertSame('failed', (string) $this->paymentByUuid((string) $failed['uuid'])['status']);

        foreach (['confirmed', 'refunded', 'pending'] as $status) {
            $this->assertRejected(
                $this->setPaymentStatus($failed, $status),
                '/platform/billing/invoices/' . (string) $invoice['uuid'],
                ['PAYMENT_CLOSED', 'closed', 'terminal']
            );
        }
        $this->assertSame('failed', (string) $this->paymentByUuid((string) $failed['uuid'])['status']);

        // refunded is terminal too.
        $refundable = $this->recordPayment($invoice, ['status' => 'confirmed', 'reference' => 'REF-1']);
        $this->assertStatus(302, $this->setPaymentStatus($refundable, 'refunded'));
        $this->assertSame('refunded', (string) $this->paymentByUuid((string) $refundable['uuid'])['status']);

        $this->assertRejected(
            $this->setPaymentStatus($refundable, 'confirmed'),
            '/platform/billing/invoices/' . (string) $invoice['uuid'],
            ['PAYMENT_CLOSED', 'closed', 'terminal']
        );
        $this->assertSame('refunded', (string) $this->paymentByUuid((string) $refundable['uuid'])['status'], 'Payments are never hard-deleted.');
    }

    public function testRefundOfPastDueInvoiceRestoresPastDue(): void
    {
        // Invoice that is already past_due, paid by a confirmed payment, refunded.
        $invoice = $this->createInvoice(['due_at' => Clock::toDb(Clock::now()->modify('-2 days'))]);
        $this->assertStatus(302, $this->platformPost('/platform/billing/invoices/' . (string) $invoice['uuid'] . '/status', [
            'status' => 'past_due',
        ]));

        $payment = $this->recordPayment($invoice, ['status' => 'confirmed']);
        $this->assertSame('paid', (string) $this->invoiceByUuid((string) $invoice['uuid'])['status']);

        $this->assertStatus(302, $this->setPaymentStatus($payment, 'refunded'));

        $reopened = $this->invoiceByUuid((string) $invoice['uuid']);
        $this->assertSame('past_due', (string) $reopened['status'], 'A refunded overdue invoice must return to past_due.');
        $this->assertNull($reopened['paid_at']);
    }

    /* ------------------------------------------------------------------ */
    /* Rejections, CSRF, XSS, no-delete                                   */
    /* ------------------------------------------------------------------ */

    public function testPaymentsAreRejectedOnClosedInvoicesAndInvalidPayloads(): void
    {
        $invoice = $this->createInvoice();
        $url = '/platform/billing/invoices/' . (string) $invoice['uuid'] . '/payments';
        $detailPath = '/platform/billing/invoices/' . (string) $invoice['uuid'];

        // Validation on an OPEN invoice (the contract's amount>0 requires numeric
        // bounds; Validator `min` is length-based, so zero/negative must be fixed
        // server-side). No payment row may be created for any of these.
        $beforePayments = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments');

        foreach ([
            ['amount_cents' => 0, 'amount' => 0],
            ['amount_cents' => -100, 'amount' => -100],
            ['method' => 'crypto'],
            ['reference' => str_repeat('r', 81)],
        ] as $overrides) {
            $this->assertRejected($this->platformPost($url, array_merge([
                'method' => 'cash',
                'amount_cents' => 100000,
                'amount' => 100000,
                'status' => 'confirmed',
            ], $overrides)), $detailPath, [
                'amount', 'method', 'reference', 'required', 'valid', 'not allowed',
            ]);
        }

        $this->assertSame(
            $beforePayments,
            (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments'),
            'Invalid payment payloads must not create ledger rows.'
        );

        // A void invoice accepts no payments at all.
        $this->assertStatus(302, $this->platformPost($detailPath . '/status', ['status' => 'void']));
        $this->assertRejected($this->platformPost($url, [
            'method' => 'cash',
            'amount_cents' => 100000,
            'amount' => 100000,
            'status' => 'confirmed',
        ]), $detailPath, ['INVOICE_CLOSED', 'closed', 'void']);

        // Unknown invoice uuid -> 404 before any write.
        $this->assertStatus(404, $this->platformPost('/platform/billing/invoices/' . $this->newUuid() . '/payments', [
            'method' => 'cash',
            'amount_cents' => 100000,
            'status' => 'confirmed',
        ]));
    }

    public function testCsrfAndXssOnPaymentLedger(): void
    {
        $invoice = $this->createInvoice();

        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments');
        $csrfless = $this->post('/platform/billing/invoices/' . (string) $invoice['uuid'] . '/payments', [
            'method' => 'mpesa',
            'reference' => 'CSRF-PAY',
            'amount_cents' => 500000,
            'status' => 'confirmed',
        ], false);
        $this->assertStatus(403, $csrfless);
        $this->assertBodyContains('CSRF_TOKEN_INVALID', $csrfless);
        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments'));

        // A payment reference is escaped in the invoice ledger.
        $this->recordPayment($invoice, [
            'reference' => '<script>alert("pay xss")</script>',
        ]);

        $detail = $this->platformGet('/platform/billing/invoices/' . (string) $invoice['uuid']);
        $this->assertStatus(200, $detail);
        $this->assertStringNotContainsString('<script>alert("pay xss")</script>', $detail->body());
        $this->assertTextContains('<script>alert("pay xss")</script>', $detail);
        $this->assertCleanMarkup($detail, 'Billing payment ledger');
    }

    public function testPaymentsAreNeverHardDeleted(): void
    {
        $invoice = $this->createInvoice();
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments');

        $pending = $this->recordPayment($invoice, ['status' => 'pending', 'reference' => 'KEEP-1']);
        $this->assertStatus(302, $this->setPaymentStatus($pending, 'failed'));

        $this->assertSame($before + 1, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments'));
        $this->assertSame('failed', (string) $this->paymentByUuid((string) $pending['uuid'])['status']);
    }
}
