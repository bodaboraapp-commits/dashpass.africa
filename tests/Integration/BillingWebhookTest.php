<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Uuid;

/**
 * Inc-13 provider payment webhooks (`/webhooks/payments/mpesa|card`).
 *
 * Frozen behaviour (app/Modules/Payments/PaymentLinkService::handleWebhook):
 *  - CSRF-exempt only for these 2 routes; authentication = provider HMAC-SHA256
 *    over the raw body + a timestamp inside a 5 minute replay window;
 *  - payload {invoice_uuid, provider_txn_id, amount_cents, currency, status};
 *  - confirmation is idempotent on the provider/provider_txn_id unique key;
 *  - confirmed payments auto-pay the invoice once the sum covers the amount;
 *    partial payments leave it open; failures never mark it paid.
 */
final class BillingWebhookTest extends AdminModuleTestCase
{
    private const SECRET = 'qa-webhook-secret-8b1d';

    private string|false $originalSecret = false;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalSecret = getenv('PAYMENT_WEBHOOK_SECRET');
        putenv('PAYMENT_WEBHOOK_SECRET=' . self::SECRET);
    }

    public function tearDown(): void
    {
        if ($this->originalSecret === false) {
            putenv('PAYMENT_WEBHOOK_SECRET');
        } else {
            putenv('PAYMENT_WEBHOOK_SECRET=' . $this->originalSecret);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function openInvoice(): array
    {
        $row = $this->db->selectOne(
            "SELECT * FROM billing_invoices WHERE status = 'open' AND amount_cents > 0 ORDER BY id ASC LIMIT 1"
        );
        $this->assertNotNull($row, 'An open invoice fixture is required.');

        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sendWebhook(
        array $payload,
        string $provider = 'mpesa',
        ?string $signature = null,
        ?int $timestamp = null
    ): array {
        $raw = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp ??= Clock::now()->getTimestamp();
        $signature ??= hash_hmac('sha256', $raw, self::SECRET);

        $this->resetSession();

        $response = $this->request(
            'POST',
            '/webhooks/payments/' . $provider,
            [],
            [
                'Content-Type' => 'application/json',
                'X-Payment-Signature' => $signature,
                'X-Payment-Timestamp' => (string) $timestamp,
            ],
            false,
            null,
            [],
            $raw
        );

        return ['status' => $response->status(), 'body' => $response->body(), 'json' => $this->json($response)];
    }

    /** @param array<string, mixed> $invoice */
    private function paymentPayload(array $invoice, string $txn, ?int $amount = null, string $status = 'confirmed', ?string $currency = null): array
    {
        return [
            'invoice_uuid' => (string) $invoice['uuid'],
            'provider_txn_id' => $txn,
            'amount_cents' => $amount ?? (int) $invoice['amount_cents'],
            'currency' => $currency ?? (string) $invoice['currency'],
            'status' => $status,
        ];
    }

    // ---------------------------------------------------------------------
    // Happy path + idempotency
    // ---------------------------------------------------------------------

    public function testValidSignatureConfirmsPaymentAndPaysTheInvoice(): void
    {
        $invoice = $this->openInvoice();
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments');

        $result = $this->sendWebhook($this->paymentPayload($invoice, 'QA-TXN-0001'));

        $this->assertSame(200, $result['status']);
        $this->assertTrue((bool) ($result['json']['success'] ?? false));
        $this->assertSame(false, (bool) ($result['json']['duplicate'] ?? false));
        $this->assertSame($before + 1, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments'));

        $payment = $this->db->selectOne(
            'SELECT provider, provider_txn_id, status, amount_cents, invoice_id FROM billing_payments WHERE provider_txn_id = :txn',
            ['txn' => 'QA-TXN-0001']
        );
        $this->assertNotNull($payment, 'The webhook must record the payment.');
        $this->assertSame('mpesa', (string) $payment['provider']);
        $this->assertSame('confirmed', (string) $payment['status']);
        $this->assertSame((int) $invoice['id'], (int) $payment['invoice_id']);

        $updated = $this->db->selectOne('SELECT status, paid_at FROM billing_invoices WHERE id = :id', ['id' => (int) $invoice['id']]);
        $this->assertSame('paid', (string) $updated['status'], 'A full confirmed payment auto-pays the invoice.');
        $this->assertNotEmpty($updated['paid_at']);
    }

    public function testReplayedEventIsANoOp(): void
    {
        $invoice = $this->openInvoice();
        $payload = $this->paymentPayload($invoice, 'QA-TXN-REPLAY');

        $first = $this->sendWebhook($payload);
        $this->assertSame(200, $first['status']);
        $this->assertSame(false, (bool) ($first['json']['duplicate'] ?? false));

        $countAfterFirst = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments');
        $second = $this->sendWebhook($payload);

        $this->assertSame(200, $second['status']);
        $this->assertTrue((bool) ($second['json']['duplicate'] ?? false), 'A replay is reported as a duplicate.');
        $this->assertSame($countAfterFirst, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments'), 'A replay must not create a second payment.');

        // Same txn id under the other provider would be a different provider key and
        // must still be unique per provider+txn (frozen unique key).
        $this->sendWebhook($this->paymentPayload($invoice, 'QA-TXN-REPLAY'), 'card');
        $this->assertSame(
            1,
            (int) $this->db->scalar("SELECT COUNT(*) FROM billing_payments WHERE provider = 'mpesa' AND provider_txn_id = 'QA-TXN-REPLAY'")
        );
    }

    public function testPartialPaymentKeepsInvoiceOpenThenFullCoveragePaysIt(): void
    {
        $invoice = $this->openInvoice();
        $amount = (int) $invoice['amount_cents'];
        $partial = intdiv($amount, 2);

        $first = $this->sendWebhook($this->paymentPayload($invoice, 'QA-PART-1', $partial));
        $this->assertSame(200, $first['status']);

        $state = $this->db->selectOne('SELECT status, paid_at FROM billing_invoices WHERE id = :id', ['id' => (int) $invoice['id']]);
        $this->assertSame('open', (string) $state['status'], 'A partial payment leaves the invoice open.');
        $this->assertNull($state['paid_at']);

        $second = $this->sendWebhook($this->paymentPayload($invoice, 'QA-PART-2', $amount - $partial));
        $this->assertSame(200, $second['status']);

        $paid = $this->db->selectOne('SELECT status, paid_at FROM billing_invoices WHERE id = :id', ['id' => (int) $invoice['id']]);
        $this->assertSame('paid', (string) $paid['status'], 'Covering payments auto-pay the invoice.');
        $this->assertNotEmpty($paid['paid_at']);
    }

    public function testFailedProviderStatusRecordsFailureButNeverPays(): void
    {
        $invoice = $this->openInvoice();
        $result = $this->sendWebhook($this->paymentPayload($invoice, 'QA-TXN-FAILED', null, 'failed'));
        $this->assertSame(200, $result['status']);

        $payment = $this->db->selectOne('SELECT status FROM billing_payments WHERE provider_txn_id = :txn', ['txn' => 'QA-TXN-FAILED']);
        $this->assertNotNull($payment);
        $this->assertSame('failed', (string) $payment['status']);

        $state = $this->db->selectOne('SELECT status FROM billing_invoices WHERE id = :id', ['id' => (int) $invoice['id']]);
        $this->assertSame('open', (string) $state['status'], 'A failed payment must never mark the invoice paid.');
    }

    // ---------------------------------------------------------------------
    // Authentication / validation
    // ---------------------------------------------------------------------

    public function testMissingOrInvalidSignatureIsRejectedWithoutWrites(): void
    {
        $invoice = $this->openInvoice();
        $payload = $this->paymentPayload($invoice, 'QA-TXN-BADSIG');
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments');

        $missing = $this->sendWebhook($payload, 'mpesa', '');
        $this->assertSame(401, $missing['status']);
        $this->assertSame('WEBHOOK_SIGNATURE_INVALID', (string) ($missing['json']['error']['code'] ?? ''));

        $wrong = $this->sendWebhook($payload, 'mpesa', str_repeat('a', 64));
        $this->assertSame(401, $wrong['status']);
        $this->assertSame('WEBHOOK_SIGNATURE_INVALID', (string) ($wrong['json']['error']['code'] ?? ''));

        // Stale timestamp (outside the 5 minute replay window).
        $stale = $this->sendWebhook($payload, 'mpesa', null, Clock::now()->getTimestamp() - 3600);
        $this->assertSame(401, $stale['status']);
        $this->assertSame('WEBHOOK_TIMESTAMP_INVALID', (string) ($stale['json']['error']['code'] ?? ''));

        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments'), 'Rejected webhooks must never write a payment.');
    }

    public function testUnknownInvoiceAndInvalidPayloadsAreRejectedWithoutWrites(): void
    {
        $invoice = $this->openInvoice();
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments');

        $unknown = $this->sendWebhook([
            'invoice_uuid' => Uuid::v4(),
            'provider_txn_id' => 'QA-TXN-UNKNOWN',
            'amount_cents' => 100,
            'currency' => 'KES',
            'status' => 'confirmed',
        ]);
        $this->assertSame(404, $unknown['status']);

        $mismatch = $this->sendWebhook($this->paymentPayload($invoice, 'QA-TXN-USD', null, 'confirmed', 'USD'));
        $this->assertSame(422, $mismatch['status']);
        $this->assertSame('PAYMENT_AMOUNT_MISMATCH', (string) ($mismatch['json']['error']['code'] ?? ''));

        $missing = $this->sendWebhook(['invoice_uuid' => (string) $invoice['uuid']]);
        $this->assertSame(422, $missing['status']);
        $this->assertSame('WEBHOOK_PAYLOAD_INVALID', (string) ($missing['json']['error']['code'] ?? ''));

        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_payments'));
    }

    public function testWebhookRoutesAreCsrfExemptWhileOtherPostsStayProtected(): void
    {
        $invoice = $this->openInvoice();

        // CSRF-exempt: without any CSRF token the handler still authenticates by
        // signature (bad signature → 401, never the web-form CSRF 403 page).
        $result = $this->sendWebhook($this->paymentPayload($invoice, 'QA-TXN-NOCSRF'), 'mpesa', '');
        $this->assertSame(401, $result['status']);
        $this->assertStringNotContainsString('CSRF', $result['body']);

        // The resident pay-link POST is still CSRF-protected.
        $this->ensureLoggedInAs(self::JANE);
        $bill = $this->db->selectOne("SELECT uuid FROM billing_invoices WHERE kind = 'utility' LIMIT 1");
        $csrf = $this->post('/app/bills/' . $bill['uuid'] . '/pay-link', [], false);
        $this->assertSame(403, $csrf->status(), 'Non-webhook POSTs keep the CSRF middleware.');
    }
}
