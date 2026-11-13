<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Context;
use App\Core\Crypto;
use App\Integrations\WhatsApp\WhatsAppCloudProvider;
use App\Integrations\WhatsApp\WhatsAppSendException;
use App\Modules\Notifications\BillingNotificationService;
use App\Modules\Notifications\WhatsAppSettingsService;

/**
 * WhatsApp comms/notification channel with estate-managed Meta credentials
 * (owner request 2026-11-13).
 *
 * The Estate Admin enters the Meta WhatsApp Cloud API details on
 * /admin/settings: phone number id, WABA id, access token, app secret, webhook
 * verify token, template name/language and an enabled switch. Secrets are
 * encrypted at rest and never shown again. Once configured, the channel becomes
 * available for the estate and billing notifications are delivered as WhatsApp
 * Cloud API template messages.
 */
final class WhatsAppChannelTest extends IntegrationTestCase
{
    private const ESTATE_ADMIN = '+254700000001'; // Grace, estate_admin @ Greenview Residences

    private const RESIDENT = '+254700000010'; // Jane

    private const ACCESS_TOKEN = 'EAAG-test-whatsapp-token-123';

    private const APP_SECRET = 'meta-app-secret-test';

    private const VERIFY_TOKEN = 'verify-token-test';

    private int $estateId = 0;

    public function testAdminSettingsShowsWhatsAppCredentialsSection(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $response = $this->get('/admin/settings');

        $this->assertStatus(200, $response);
        $this->assertTextContains('WhatsApp channel', $response);
        $this->assertTextContains('Meta phone number ID', $response);
        $this->assertTextContains('Permanent access token', $response);
        $this->assertTextContains('Enable WhatsApp for this estate', $response);
        $this->assertTextContains('encrypted at rest', $response);
    }

    public function testEstateAdminSavesMetaCredentialsEncryptedAndChannelBecomesAvailable(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $this->estateId = $this->estateId();

        $response = $this->post('/admin/settings', [
            'whatsapp_form' => '1',
            'whatsapp_phone_number_id' => '123456789012345',
            'whatsapp_waba_id' => '987654321098765',
            'whatsapp_access_token' => self::ACCESS_TOKEN,
            'whatsapp_app_secret' => self::APP_SECRET,
            'whatsapp_verify_token' => self::VERIFY_TOKEN,
            'whatsapp_template_name' => 'estate_notification',
            'whatsapp_template_language' => 'en',
            'whatsapp_enabled' => '1',
        ]);

        try {
            $this->assertStatus(302, $response);

            $row = $this->db->selectOne(
                'SELECT * FROM estate_whatsapp_settings WHERE estate_id = :estate_id LIMIT 1',
                ['estate_id' => $this->estateId]
            );
            $this->assertNotNull($row, 'The estate WhatsApp settings row must be saved.');
            $this->assertSame('123456789012345', (string) $row['phone_number_id']);
            $this->assertSame(1, (int) $row['enabled']);

            // Secrets are encrypted at rest, never plaintext.
            $this->assertStringNotContainsString(self::ACCESS_TOKEN, (string) $row['access_token_encrypted']);
            $this->assertStringNotContainsString(self::APP_SECRET, (string) $row['app_secret_encrypted']);
            $this->assertStringNotContainsString(self::VERIFY_TOKEN, (string) $row['verify_token_encrypted']);

            $this->assertSame(
                self::ACCESS_TOKEN,
                Crypto::decrypt((string) $row['access_token_encrypted'], 'estate-whatsapp-whatsapp_access_token:' . $this->estateId)
            );

            // The settings screen only reports the secrets as set.
            $page = $this->get('/admin/settings');
            $this->assertStatus(200, $page);
            $this->assertTextContains('Configured', $page);
            $this->assertStringContainsString('Saved - leave blank to keep it', $page->body());
            $this->assertStringNotContainsString(self::ACCESS_TOKEN, $page->body());

            // Channel availability + decrypted credentials for the sender.
            $context = Context::current();
            $this->assertNotNull($context);
            $service = WhatsAppSettingsService::make($context);
            $this->assertTrue($service->isConfigured($this->estateId));
            $credentials = $service->credentials($this->estateId);
            $this->assertNotNull($credentials);
            $this->assertSame(self::ACCESS_TOKEN, $credentials['access_token']);
            $this->assertSame(self::APP_SECRET, $credentials['app_secret']);
            $this->assertSame(self::VERIFY_TOKEN, $credentials['verify_token']);
        } finally {
            $this->cleanupWhatsApp();
        }
    }

    public function testEnablingWhatsAppWithoutCredentialsIsRejected(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $this->estateId = $this->estateId();

        try {
            $response = $this->post('/admin/settings', [
                'whatsapp_form' => '1',
                'whatsapp_phone_number_id' => '',
                'whatsapp_access_token' => '',
                'whatsapp_enabled' => '1',
            ]);
            $this->assertStatus(302, $response);

            $page = $this->get('/admin/settings');
            $this->assertStatus(200, $page);
            $this->assertTextContains('Add the phone number ID and access token before enabling WhatsApp.', $page);

            $row = $this->db->selectOne(
                'SELECT * FROM estate_whatsapp_settings WHERE estate_id = :estate_id LIMIT 1',
                ['estate_id' => $this->estateId]
            );
            $this->assertNull($row, 'An invalid WhatsApp form must not create a settings row.');
        } finally {
            $this->cleanupWhatsApp();
        }
    }

    public function testResidentCannotManageWhatsAppSettings(): void
    {
        $this->loginAs(self::RESIDENT);
        $this->assertStatus(403, $this->get('/admin/settings'));
    }

    public function testCloudProviderSendsTemplateThroughTransport(): void
    {
        $captured = [];
        $provider = new WhatsAppCloudProvider(function (string $url, array $headers, string $body) use (&$captured): array {
            $captured = ['url' => $url, 'headers' => $headers, 'body' => $body];

            return [
                'status' => 200,
                'body' => json_encode(['messages' => [['id' => 'wamid.TEST123']]]),
            ];
        });

        $result = $provider->sendTemplate(
            [
                'phone_number_id' => '123456789012345',
                'access_token' => self::ACCESS_TOKEN,
                'template_name' => 'estate_notification',
                'template_language' => 'en',
            ],
            '+254 700 000 010',
            ['Invoice INV-1 is ready', 'Due 13 Nov 2026']
        );

        $this->assertSame('wamid.TEST123', $result['message_id']);
        $this->assertStringContainsString('/v21.0/123456789012345/messages', $captured['url']);
        $this->assertSame('Bearer ' . self::ACCESS_TOKEN, $captured['headers']['Authorization']);

        $payload = json_decode($captured['body'], true);
        $this->assertSame('whatsapp', $payload['messaging_product']);
        $this->assertSame('254700000010', $payload['to']);
        $this->assertSame('template', $payload['type']);
        $this->assertSame('estate_notification', $payload['template']['name']);
        $this->assertSame('en', $payload['template']['language']['code']);
        $this->assertCount(2, $payload['template']['components'][0]['parameters']);
        $this->assertStringNotContainsString(self::ACCESS_TOKEN, (string) $captured['body']);
    }

    public function testCloudProviderRejectsProviderErrorsAndBadPhones(): void
    {
        $provider = new WhatsAppCloudProvider(
            static fn (string $url, array $headers, string $body): array => [
                'status' => 401,
                'body' => json_encode(['error' => ['code' => 190, 'message' => 'Invalid token']]),
            ]
        );

        try {
            $provider->sendTemplate(
                ['phone_number_id' => '123456789012345', 'access_token' => self::ACCESS_TOKEN],
                '+254700000010'
            );
            $this->assertTrue(false, 'A provider error must throw.');
        } catch (WhatsAppSendException $exception) {
            $this->assertSame(401, $exception->providerStatus());
        }

        try {
            (new WhatsAppCloudProvider(static fn (): array => ['status' => 200, 'body' => '{}']))
                ->sendTemplate(['phone_number_id' => '1', 'access_token' => 't'], 'abc');
            $this->assertTrue(false, 'An invalid phone number must throw.');
        } catch (WhatsAppSendException $exception) {
            $this->assertStringContainsString('invalid_phone', $exception->getMessage());
        }
    }

    public function testBillingNotificationDeliversWhatsAppThroughEstateCredentials(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $this->estateId = $this->estateId();
        $this->saveWhatsAppCredentials();
        $this->allowWhatsAppChannel();

        $captured = [];
        $transport = function (string $url, array $headers, string $body) use (&$captured): array {
            $captured[] = ['url' => $url, 'body' => $body];

            return ['status' => 200, 'body' => json_encode(['messages' => [['id' => 'wamid.BILLING']]])];
        };

        $context = Context::current();
        $this->assertNotNull($context);

        $invoiceUuid = bin2hex(random_bytes(8));
        $invoice = [
            'id' => 0,
            'uuid' => $invoiceUuid,
            'estate_id' => $this->estateId,
            'kind' => 'subscription',
            'invoice_number' => 'INV-WA-1',
            'currency' => 'KES',
            'amount_cents' => 150000,
            'due_at' => '2026-11-30 00:00:00',
        ];

        try {
            $result = BillingNotificationService::make($context, null, $transport)->deliverInvoiceEvent($invoice, 'generated');
            $this->assertSame('sent', $result['channels']['whatsapp'] ?? null, 'WhatsApp must be delivered through the estate credentials.');
            $this->assertNotEmpty($captured, 'The WhatsApp Cloud API must be called.');

            $row = $this->db->selectOne(
                'SELECT status, channel FROM notification_logs WHERE dedupe_key = :key LIMIT 1',
                ['key' => 'billing:generated:' . $invoiceUuid . ':' . $this->adminUserId() . ':whatsapp']
            );
            $this->assertNotNull($row, 'The WhatsApp delivery must be recorded in notification_logs.');
            $this->assertSame('whatsapp', (string) $row['channel']);
            $this->assertSame('sent', (string) $row['status']);
        } finally {
            $this->cleanupWhatsApp();
            $this->db->execute('DELETE FROM notification_logs WHERE dedupe_key = :key', ['key' => 'billing:generated:' . $invoiceUuid . ':' . $this->adminUserId() . ':whatsapp']);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function estateId(): int
    {
        $estate = $this->db->selectOne(
            "SELECT id FROM estates WHERE slug = 'greenview-residences' LIMIT 1"
        );
        $this->assertNotNull($estate, 'The seeded Greenview estate is required.');

        return (int) $estate['id'];
    }

    private function adminUserId(): int
    {
        $user = $this->db->selectOne('SELECT id FROM users WHERE phone = :phone LIMIT 1', ['phone' => self::ESTATE_ADMIN]);
        $this->assertNotNull($user);

        return (int) $user['id'];
    }

    private function saveWhatsAppCredentials(): void
    {
        $this->post('/admin/settings', [
            'whatsapp_form' => '1',
            'whatsapp_phone_number_id' => '123456789012345',
            'whatsapp_waba_id' => '987654321098765',
            'whatsapp_access_token' => self::ACCESS_TOKEN,
            'whatsapp_app_secret' => self::APP_SECRET,
            'whatsapp_verify_token' => self::VERIFY_TOKEN,
            'whatsapp_template_name' => 'estate_notification',
            'whatsapp_template_language' => 'en',
            'whatsapp_enabled' => '1',
        ]);
    }

    private function allowWhatsAppChannel(): void
    {
        $existing = $this->db->selectOne(
            'SELECT id FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :key LIMIT 1',
            ['estate_id' => $this->estateId, 'key' => 'billing.notification_channels']
        );
        $value = json_encode(['email', 'in_app', 'whatsapp']);

        if ($existing === null) {
            $this->db->insert('estate_settings', [
                'estate_id' => $this->estateId,
                'setting_key' => 'billing.notification_channels',
                'setting_value' => $value,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $this->db->update('estate_settings', ['setting_value' => $value], ['id' => (int) $existing['id']]);
        }
    }

    private function cleanupWhatsApp(): void
    {
        if ($this->estateId > 0) {
            $this->db->execute(
                'DELETE FROM estate_whatsapp_settings WHERE estate_id = :estate_id',
                ['estate_id' => $this->estateId]
            );
        }
    }
}
