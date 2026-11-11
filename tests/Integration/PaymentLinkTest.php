<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Uuid;

/**
 * Inc-13 payment links: HMAC-signed stateless tokens + public `/pay/{token}` page.
 *
 * Frozen behaviour (app/Modules/Payments/PaymentLinkService):
 *  - token = base64url(JSON payload) . '.' . base64url(HMAC-SHA256(payload, link secret));
 *  - payload carries invoice uuid, provider, amount, currency, unix expiry, nonce;
 *  - provider must be enabled (mode test|live) and a link secret configured, else 503;
 *  - invalid/expired tokens render a safe terminal page (404/410) with no pay action;
 *  - creation is platform/owner scoped (platform billing.payment.manage or own bill).
 */
final class PaymentLinkTest extends AdminModuleTestCase
{
    private const SECRET = 'qa-payment-link-secret-4e2f';

    private string|false $originalSecret = false;

    /** @var array<string, mixed> */
    private array $originalProviders = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalSecret = getenv('PAYMENT_LINK_SECRET');
        putenv('PAYMENT_LINK_SECRET=' . self::SECRET);

        $this->originalProviders = (array) json_decode((string) $this->db->scalar(
            "SELECT `value` FROM platform_settings WHERE `key` = 'payments.providers' LIMIT 1"
        ), true);

        $this->setProviders(true);
    }

    public function tearDown(): void
    {
        $this->setProviders(false);

        if ($this->originalSecret === false) {
            putenv('PAYMENT_LINK_SECRET');
        } else {
            putenv('PAYMENT_LINK_SECRET=' . $this->originalSecret);
        }

        parent::tearDown();
    }

    private function setProviders(bool $enabled): void
    {
        $value = [
            'mpesa' => ['enabled' => $enabled, 'mode' => 'test'],
            'card' => ['enabled' => $enabled, 'mode' => 'test'],
            'link_expiry_hours' => 24,
        ];

        $this->db->execute(
            "UPDATE platform_settings SET `value` = :value WHERE `key` = 'payments.providers'",
            ['value' => json_encode($value, JSON_UNESCAPED_SLASHES)]
        );
    }

    /** @return array<string, mixed> */
    private function payableInvoice(): array
    {
        $row = $this->db->selectOne(
            "SELECT * FROM billing_invoices WHERE status = 'open' AND amount_cents > 0 ORDER BY id ASC LIMIT 1"
        );
        $this->assertNotNull($row, 'An open invoice fixture is required.');

        return $row;
    }

    /** @param array<string, mixed> $payload */
    private function signToken(array $payload): string
    {
        $encoded = rtrim(strtr(base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, self::SECRET, true)), '+/', '-_'), '=');

        return $encoded . '.' . $signature;
    }

    private function createLink(array $invoice, string $provider = 'mpesa', ?string $as = null): \App\Core\Response
    {
        $this->ensureLoggedInAs($as ?? '+254700000020');

        return $this->post('/platform/billing/pay-links', [
            'invoice_uuid' => (string) $invoice['uuid'],
            'provider' => $provider,
        ], true);
    }

    // ---------------------------------------------------------------------
    // Creation + public page
    // ---------------------------------------------------------------------

    public function testPlatformCreatesSignedLinkAndPublicPageRendersAmountSafely(): void
    {
        $invoice = $this->payableInvoice();
        $response = $this->createLink($invoice);

        $this->assertStatus(200, $response);
        $payload = $this->json($response);
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $token = (string) ($payload['data']['token'] ?? '');
        $this->assertNotEmpty($token);
        $this->assertSame('/pay/' . $token, (string) ($payload['data']['url'] ?? ''));
        $this->assertSame('mpesa', (string) ($payload['data']['provider'] ?? ''));

        $parts = explode('.', $token);
        $this->assertCount(2, $parts, 'The token is payload.signature.');
        $decoded = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        $this->assertIsArray($decoded);
        $this->assertSame((string) $invoice['uuid'], $decoded['invoice_uuid'] ?? null);
        $this->assertSame((int) $invoice['amount_cents'], (int) ($decoded['amount_cents'] ?? 0));
        $this->assertSame('KES', $decoded['currency'] ?? null);
        $this->assertTrue((int) ($decoded['exp'] ?? 0) > Clock::now()->getTimestamp(), 'The token must carry a future expiry.');

        // Public pay page: no session, amount shown, no card data collection.
        $this->resetSession();
        $page = $this->get('/pay/' . $token);
        $this->assertStatus(200, $page);
        $this->assertCleanMarkup($page, 'public pay page');
        $this->assertStringNotContainsString('CVV', $page->body());
        $this->assertStringNotContainsString('card number', strtolower($page->body()));
        $this->assertStringNotContainsString('<input type="text" name="card', $page->body());

        // Creation is audited without leaking the token.
        $audit = $this->db->selectOne("SELECT metadata FROM audit_logs WHERE action = 'billing.payment_link_created' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit, 'Pay-link creation must be audited.');
        $this->assertStringNotContainsString($token, (string) $audit['metadata'], 'The signed token must never be audited/logged.');
    }

    public function testTamperedExpiredAndForeignTokensAreRejectedSafely(): void
    {
        $invoice = $this->payableInvoice();
        $token = (string) ($this->json($this->createLink($invoice))['data']['token'] ?? '');
        [$encoded, $signature] = explode('.', $token);

        // Payload swap keeps the old signature → invalid.
        $payload = json_decode((string) base64_decode(strtr($encoded, '-_', '+/'), true), true);
        $payload['amount_cents'] = 1;
        $tampered = rtrim(strtr(base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=') . '.' . $signature;

        // Correctly signed but expired token.
        $expiredPayload = [
            'invoice_uuid' => (string) $invoice['uuid'],
            'provider' => 'mpesa',
            'amount_cents' => (int) $invoice['amount_cents'],
            'currency' => 'KES',
            'exp' => Clock::now()->getTimestamp() - 60,
            'nonce' => bin2hex(random_bytes(6)),
        ];
        $expired = $this->signToken($expiredPayload);

        // Correctly signed token for an unknown invoice.
        $foreign = $this->signToken([
            'invoice_uuid' => Uuid::v4(),
            'provider' => 'card',
            'amount_cents' => 5000,
            'currency' => 'KES',
            'exp' => Clock::now()->getTimestamp() + 3600,
            'nonce' => bin2hex(random_bytes(6)),
        ]);

        $this->resetSession();

        foreach ([$tampered, $expired] as $bad) {
            $response = $this->get('/pay/' . $bad);
            $this->assertTrue(
                in_array($response->status(), [404, 410], true),
                'Tampered/expired tokens must show a safe terminal state, got ' . $response->status() . '.'
            );
            $this->assertStringNotContainsString('Pay now', $response->body());
        }

        $foreignResponse = $this->get('/pay/' . $foreign);
        $this->assertTrue(in_array($foreignResponse->status(), [404, 410], true), 'A token for an unknown invoice must be rejected.');
    }

    public function testDisabledProvidersFailClosed(): void
    {
        $invoice = $this->payableInvoice();
        $this->setProviders(false);

        $response = $this->createLink($invoice);
        $this->assertStatus(503, $response);
        $payload = $this->json($response);
        $this->assertSame('PAYMENT_LINK_NOT_CONFIGURED', (string) ($payload['error']['code'] ?? ''));
    }

    public function testProviderAndInvoiceValidation(): void
    {
        $invoice = $this->payableInvoice();

        $this->assertStatus(422, $this->createLink($invoice, 'paypal'));

        $this->ensureLoggedInAs('+254700000020');
        $unknown = $this->post('/platform/billing/pay-links', ['invoice_uuid' => Uuid::v4(), 'provider' => 'mpesa'], true);
        $this->assertStatus(404, $unknown);

        // A paid invoice is not payable.
        $this->db->execute('UPDATE billing_invoices SET status = :s WHERE id = :id', ['s' => 'paid', 'id' => (int) $invoice['id']]);
        $this->assertStatus(409, $this->createLink($invoice));
    }

    // ---------------------------------------------------------------------
    // Permissions / ownership
    // ---------------------------------------------------------------------

    public function testPayLinkCreationIsPermissioned(): void
    {
        $invoice = $this->payableInvoice();

        foreach ([self::GRACE, self::MANAGER, self::JANE, self::GUARD_MAIN] as $phone) {
            $this->ensureLoggedInAs($phone);
            $response = $this->post('/platform/billing/pay-links', ['invoice_uuid' => (string) $invoice['uuid'], 'provider' => 'mpesa'], true);
            $this->assertStatus(403, $response, $phone . ' must not create platform pay links.');
        }

        $this->resetSession();
        $unauth = $this->post('/platform/billing/pay-links', ['invoice_uuid' => (string) $invoice['uuid'], 'provider' => 'mpesa'], true);
        $this->assertStatus(302, $unauth);
    }

    public function testResidentCanCreateALinkOnlyForTheirOwnBill(): void
    {
        $invoice = $this->db->selectOne(
            "SELECT * FROM billing_invoices WHERE kind = 'utility' AND status = 'open' ORDER BY id ASC LIMIT 1"
        );
        $this->assertNotNull($invoice, 'The seeded A-001 utility bill is required.');

        $this->ensureLoggedInAs(self::JANE);
        $response = $this->post('/app/bills/' . $invoice['uuid'] . '/pay-link', ['provider' => 'mpesa'], true);
        $this->assertStatus(302, $response, 'The resident pay-link action redirects to the signed pay page.');
        $this->assertStringStartsWith('/pay/', (string) $response->header('Location'));

        // Another household cannot generate a link for Jane's bill.
        $this->ensureLoggedInAs(self::BRIAN);
        $this->assertStatus(404, $this->post('/app/bills/' . $invoice['uuid'] . '/pay-link', ['provider' => 'mpesa'], true));

        // Guard has no bills.
        $this->loginGuard();
        $this->assertStatus(404, $this->post('/app/bills/' . $invoice['uuid'] . '/pay-link', ['provider' => 'mpesa'], true));
    }
}
