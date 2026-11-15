<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Context;
use App\Core\Crypto;
use App\Modules\Notifications\WhatsAppEmbeddedSignupService;

/**
 * Meta Embedded Signup "Connect WhatsApp with Meta" (owner request 2026-11-15).
 *
 * The flow is an OAuth redirect (no third-party JavaScript, strict CSP intact),
 * then the code is exchanged server-side for a business token and stored
 * encrypted on the estate WhatsApp settings row.
 */
final class WhatsAppEmbeddedSignupTest extends IntegrationTestCase
{
    private const ESTATE_ADMIN = '+254700000001';

    public function setUp(): void
    {
        parent::setUp();

        Config::set('whatsapp.app_id', '1234567890');
        Config::set('whatsapp.app_secret', 'meta-app-secret-test');
        Config::set('whatsapp.es_config_id', '9876543210');
        Config::set('whatsapp.graph_version', 'v21.0');
    }

    public function testSettingsShowsTheConnectButton(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $page = $this->get('/admin/settings');

        $this->assertStatus(200, $page);
        $this->assertTextContains('Connect WhatsApp with Meta', $page);
        $this->assertStringContainsString('/admin/settings/whatsapp/connect', $page->body());
    }

    public function testConnectRedirectsToMetaOauthWithState(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $response = $this->get('/admin/settings/whatsapp/connect');

        $this->assertStatus(302, $response);
        $location = urldecode((string) $response->header('Location'));
        $this->assertStringContainsString('facebook.com/v21.0/dialog/oauth', $location);
        $this->assertStringContainsString('client_id=1234567890', $location);
        $this->assertStringContainsString('config_id=9876543210', $location);
        $this->assertStringContainsString('state=', $location);
        $this->assertStringContainsString('/admin/settings/whatsapp/callback', $location);
    }

    public function testCallbackRejectsAnExpiredState(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $this->get('/admin/settings/whatsapp/connect');

        $this->assertStatus(302, $this->get('/admin/settings/whatsapp/callback?code=abc&state=forged'));
        $page = $this->get('/admin/settings');
        $this->assertTextContains('expired', $page);
    }

    public function testServiceExchangesTheCodeAndSavesEncryptedCredentials(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $this->assertStatus(200, $this->get('/admin/settings'));
        $context = Context::current();
        $this->assertNotNull($context);
        $estateId = $this->estateId();

        $transport = static function (string $url, array $headers): array {
            if (str_contains($url, 'grant_type=fb_exchange_token')) {
                return ['status' => 200, 'body' => json_encode(['access_token' => 'EAAG-long-lived'])];
            }

            if (str_contains($url, '/oauth/access_token')) {
                return ['status' => 200, 'body' => json_encode(['access_token' => 'EAAG-short-lived'])];
            }

            if (str_contains($url, 'assigned_whatsapp_business_accounts')) {
                return ['status' => 200, 'body' => json_encode(['data' => [['id' => '987654321098765']]])];
            }

            if (str_contains($url, '/phone_numbers')) {
                return ['status' => 200, 'body' => json_encode(['data' => [['id' => '123456789012345']]])];
            }

            return ['status' => 404, 'body' => '{}'];
        };

        $result = WhatsAppEmbeddedSignupService::make($context, $transport)->connect($estateId, 'oauth-code-123');

        $this->assertTrue($result['connected']);
        $this->assertSame('987654321098765', $result['waba_id']);
        $this->assertSame('123456789012345', $result['phone_number_id']);

        $row = $this->db->selectOne(
            'SELECT * FROM estate_whatsapp_settings WHERE estate_id = :estate_id',
            ['estate_id' => $estateId]
        );
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['enabled']);
        $this->assertStringNotContainsString('EAAG-long-lived', (string) $row['access_token_encrypted']);
        $this->assertSame(
            'EAAG-long-lived',
            Crypto::decrypt((string) $row['access_token_encrypted'], 'estate-whatsapp-whatsapp_access_token:' . $estateId)
        );

        $audit = $this->db->selectOne("SELECT id FROM audit_logs WHERE action = 'whatsapp.connected' LIMIT 1");
        $this->assertNotNull($audit, 'The connection must be audited.');
    }

    public function testUnconfiguredConnectIsRejectedWithGuidance(): void
    {
        Config::set('whatsapp.app_id', '');

        $this->loginAs(self::ESTATE_ADMIN);
        $this->assertStatus(302, $this->get('/admin/settings/whatsapp/connect'));

        $page = $this->get('/admin/settings');
        $this->assertTextContains('Meta Embedded Signup is not configured', $page);
    }

    private function estateId(): int
    {
        $estate = $this->db->selectOne("SELECT id FROM estates WHERE slug = 'greenview-residences' LIMIT 1");
        $this->assertNotNull($estate);

        return (int) $estate['id'];
    }
}
