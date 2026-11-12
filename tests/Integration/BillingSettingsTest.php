<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Uuid;

/**
 * Inc-13 settings (pre-authored by tests; fold when the settings slice lands).
 *
 * Covers docs/automated-billing-contract.md §6/§9 + storage/temporary/inc13/
 * TEST-PLAN.md §9: super-admin-only global settings
 * (`/platform/settings/notifications`, `/platform/settings/payments`) with masked
 * secrets + validation, and estate-admin billing settings folded into the existing
 * `/admin/settings` route (utility basis/tariff, channel/method subset, defaults).
 *
 * Pre-authoring notes for the fold:
 *  - Paths are frozen from contract §8; adjust only if the landed patch differs.
 *  - Form posts are PRG (302 + flash) on this codebase; security invariants are
 *    asserted on status + persisted state so a PRG/422 delta does not force a rewrite.
 *  - Platform-area gate = at least one `platform.*` permission; global settings are
 *    super-admin-only (platform_finance/operations get 403).
 */
final class BillingSettingsTest extends AdminModuleTestCase
{
    private const SUPER = '+254700000020';
    private const GLOBAL_NOTIFICATIONS = '/platform/settings/notifications';
    private const GLOBAL_PAYMENTS = '/platform/settings/payments';
    private const BILLING_PERMISSIONS = ['billing.invoice.view', 'billing.payment.manage', 'billing.subscription.view', 'billing.subscription.manage'];

    private const SECRET_SENTINELS = [
        'test-webhook-secret-9f4c2a1b',
        'test-provider-secret-7d3e8f02',
        'test-sms-gateway-credential-42',
    ];

    private const SECRET_ENV_KEYS = [
        'PAYMENT_WEBHOOK_SECRET',
        'PAYMENTS_TEST_WEBHOOK_SECRET',
        'MPESA_CONSUMER_SECRET',
        'CARD_PROVIDER_SECRET',
        'SMS_API_KEY',
    ];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    public function setUp(): void
    {
        parent::setUp();

        foreach (self::SECRET_ENV_KEYS as $key) {
            $this->originalEnv[$key] = getenv($key);
        }

        // A secret sentinel is present in the environment: it must never surface.
        foreach (self::SECRET_ENV_KEYS as $index => $key) {
            putenv($key . '=' . self::SECRET_SENTINELS[$index % count(self::SECRET_SENTINELS)]);
        }
    }

    public function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function attachPlatformRole(string $phone, string $roleName): void
    {
        $user = $this->seededUser($phone);
        $role = $this->db->selectOne(
            "SELECT id FROM roles WHERE name = :name AND scope = 'platform' LIMIT 1",
            ['name' => $roleName]
        );
        $this->assertNotNull($role, 'Missing platform role ' . $roleName . '.');

        $this->db->execute(
            'INSERT INTO platform_memberships (uuid, user_id, role_id, status) VALUES (:uuid, :user, :role, :status)',
            ['uuid' => Uuid::v4(), 'user' => (int) $user['id'], 'role' => (int) $role['id'], 'status' => 'active']
        );
    }

    /** @param array<string, mixed> $overrides */
    private function platformSettingValue(string $key): mixed
    {
        $row = $this->db->selectOne('SELECT `value` FROM platform_settings WHERE `key` = :key', ['key' => $key]);

        if ($row === null) {
            return null;
        }

        return json_decode((string) $row['value'], true);
    }

    /** @return array<string, mixed> */
    private function latestSettingsAudit(): array
    {
        $row = $this->db->selectOne(
            "SELECT action, metadata FROM audit_logs WHERE action = 'billing.settings_updated' ORDER BY id DESC LIMIT 1"
        );

        if ($row === null) {
            return [];
        }

        $metadata = json_decode((string) $row['metadata'], true);

        return array_merge(['action' => (string) $row['action']], is_array($metadata) ? $metadata : []);
    }

    private function assertNoSecretLeak(string $haystack, string $context): void
    {
        foreach (self::SECRET_SENTINELS as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $haystack, $context . ' must never expose a secret value.');
        }

        $this->assertStringNotContainsString('BEGIN ' . 'PRIVATE KEY', $haystack);
        $this->assertStringNotContainsString('service_account', $haystack);
    }

    private function assertRejected(mixed $response, string $context): void
    {
        $status = $response->status();
        $this->assertTrue(
            in_array($status, [302, 422, 400, 403], true),
            $context . ' must be rejected (302 PRG, 400, 403 or 422), got HTTP ' . $status . '.'
        );
    }

    // ---------------------------------------------------------------------
    // Global settings: access + render
    // ---------------------------------------------------------------------

    public function testSuperAdminCanOpenBothGlobalSettingsPages(): void
    {
        $this->ensureLoggedInAs(self::SUPER);

        $notifications = $this->get(self::GLOBAL_NOTIFICATIONS);
        $this->assertStatus(200, $notifications);
        $this->assertCleanMarkup($notifications, 'global notification settings');
        $this->assertCount(1, $this->xpath('//h1', $this->dom($notifications)));

        $text = strtolower($this->pageText($notifications));
        foreach (['email', 'in-app', 'sms', 'whatsapp', 'channel'] as $needle) {
            $this->assertStringContainsString($needle, $text, 'The notification settings must mention ' . $needle . '.');
        }

        $payments = $this->get(self::GLOBAL_PAYMENTS);
        $this->assertStatus(200, $payments);
        $this->assertCleanMarkup($payments, 'global payment settings');
        $this->assertCount(1, $this->xpath('//h1', $this->dom($payments)));

        $payText = strtolower($this->pageText($payments));
        foreach (['m-pesa', 'card', 'test', 'provider', 'expiry'] as $needle) {
            $this->assertStringContainsString($needle, $payText, 'The payment settings must mention ' . $needle . '.');
        }

        // Secret sentinels from the environment must never be rendered.
        $this->assertNoSecretLeak($notifications->body(), 'Global notification settings');
        $this->assertNoSecretLeak($payments->body(), 'Global payment settings');
    }

    public function testGlobalSettingsAreSuperAdminOnly(): void
    {
        // Platform operations: platform area gate passes, but global settings are
        // super-admin only → 403.
        $this->attachPlatformRole('+254700000002', 'platform_operations');
        $this->ensureLoggedInAs('+254700000002');
        $this->assertStatus(403, $this->get(self::GLOBAL_NOTIFICATIONS));
        $this->assertStatus(403, $this->get(self::GLOBAL_PAYMENTS));

        // Estate users never reach the platform area.
        foreach ([self::GRACE, self::MANAGER, self::JANE, self::GUARD_MAIN] as $phone) {
            $this->ensureLoggedInAs($phone);
            $this->assertStatus(403, $this->get(self::GLOBAL_NOTIFICATIONS), 'Estate user ' . $phone . ' must get 403.');
            $this->assertStatus(403, $this->get(self::GLOBAL_PAYMENTS), 'Estate user ' . $phone . ' must get 403.');
        }

        $this->resetSession();
        foreach ([self::GLOBAL_NOTIFICATIONS, self::GLOBAL_PAYMENTS] as $path) {
            $response = $this->get($path);
            $this->assertStatus(302, $response, 'Unauthenticated access must redirect to /login.');
            $this->assertStringContainsString('/login', (string) $response->header('Location'));
        }
    }

    public function testGlobalSettingsPostRequiresCsrfAndSuperAdmin(): void
    {
        $before = $this->platformSettingValue('notifications.channels.available');

        $this->ensureLoggedInAs(self::SUPER);
        $noCsrf = $this->post(self::GLOBAL_NOTIFICATIONS, ['sms' => '1'], false);
        $this->assertStatus(403, $noCsrf, 'A global settings POST without CSRF must be rejected (web CSRF = 403).');
        $this->assertSame($before, $this->platformSettingValue('notifications.channels.available'), 'A CSRF-rejected POST must not persist settings.');

        // Non-super platform operator cannot save either.
        $this->attachPlatformRole('+254700000002', 'platform_operations');
        $this->ensureLoggedInAs('+254700000002');
        $this->assertStatus(403, $this->post(self::GLOBAL_NOTIFICATIONS, ['sms' => '1'], true));
        $this->assertSame($before, $this->platformSettingValue('notifications.channels.available'));
    }

    // ---------------------------------------------------------------------
    // Global settings: validation + invariants
    // ---------------------------------------------------------------------

    public function testGlobalSettingsNormaliseAndBoundTheirInputs(): void
    {
        $this->ensureLoggedInAs(self::SUPER);

        // Payment settings: a valid save persists the frozen non-secret shape and the
        // authoritative TTL key consumed by PaymentLinkService (1 day = 1440 minutes).
        $this->post(self::GLOBAL_PAYMENTS, [
            'provider' => 'mpesa',
            'mode' => 'test',
            'methods' => ['mpesa'],
            'link_expiry_days' => '1',
        ], true);

        $providers = $this->platformSettingValue('payments.providers');
        $this->assertTrue(is_array($providers));
        $this->assertSame(true, $providers['mpesa']['enabled'] ?? null);
        $this->assertSame('test', $providers['mpesa']['mode'] ?? null);
        $this->assertSame(false, $providers['card']['enabled'] ?? null);
        $this->assertSame(1440, (int) $this->platformSettingValue('payments.links.ttl_minutes'), 'The UI expiry writes the service TTL key.');

        // Notification channels: a subset is persisted, email + in-app stay forced on.
        $this->post(self::GLOBAL_NOTIFICATIONS, ['channels' => ['email', 'in_app']], true);
        $channels = $this->platformSettingValue('notifications.channels.available');
        $this->assertTrue(is_array($channels));
        $this->assertSame(['email', 'in_app', 'sms', 'whatsapp'], array_keys($channels));
        $this->assertSame(true, $channels['email'] ?? null);
        $this->assertSame(true, $channels['in_app'] ?? null);
        $this->assertSame(false, $channels['sms'] ?? null, 'SMS stays off without a real gateway.');
        $this->assertSame(false, $channels['whatsapp'] ?? null, 'WhatsApp stays off without a real gateway.');
    }
    public function testSmsAndWhatsAppCannotBeEnabledWithoutARealGateway(): void
    {
        $this->ensureLoggedInAs(self::SUPER);

        // No real gateway is configured in tests (log/mock SMS driver only).
        $this->post(self::GLOBAL_NOTIFICATIONS, ['channels' => ['sms', 'whatsapp']], true);

        $channels = $this->platformSettingValue('notifications.channels.available');
        $this->assertTrue(is_array($channels));
        $this->assertSame(false, $channels['sms'] ?? null, 'SMS must stay unavailable until a real gateway is configured.');
        $this->assertSame(false, $channels['whatsapp'] ?? null, 'WhatsApp must stay unavailable until a real gateway is configured.');
    }

    public function testEmailAndInAppRemainAlwaysAvailable(): void
    {
        $this->ensureLoggedInAs(self::SUPER);

        // Attempt to disable the always-on channels.
        $this->post(self::GLOBAL_NOTIFICATIONS, ['channels' => []], true);

        $channels = $this->platformSettingValue('notifications.channels.available');
        $this->assertTrue(is_array($channels));
        $this->assertSame(true, $channels['email'] ?? null, 'Email is always available.');
        $this->assertSame(true, $channels['in_app'] ?? null, 'In-app is always available.');
    }

    public function testPaymentSettingsValidationIsEnforced(): void
    {
        $this->ensureLoggedInAs(self::SUPER);
        $before = $this->platformSettingValue('payments.providers');

        foreach ([
            ['provider' => 'paypal', 'mode' => 'test'],
            ['provider' => 'mpesa', 'mode' => 'banana'],
            ['provider' => 'mpesa', 'mode' => 'test', 'link_expiry_days' => '999'],
            ['provider' => 'mpesa', 'mode' => 'test', 'methods' => ['carrier_pigeon']],
        ] as $invalid) {
            $response = $this->post(self::GLOBAL_PAYMENTS, $invalid, true);
            $this->assertTrue(
                in_array($response->status(), [302, 422], true),
                'Invalid payment settings must be rejected (302 PRG or 422), got HTTP ' . $response->status() . ' for ' . json_encode($invalid) . '.'
            );
        }

        $this->assertSame($before, $this->platformSettingValue('payments.providers'), 'Rejected settings must not persist.');
    }
    public function testValidGlobalSettingsSaveAuditsPiiFreeAndKeepsSecretsOut(): void
    {
        $this->ensureLoggedInAs(self::SUPER);

        $response = $this->post(self::GLOBAL_PAYMENTS, [
            'provider' => 'mpesa',
            'mode' => 'test',
            'methods' => ['mpesa'],
            'link_expiry_days' => '1',
            'webhook_secret' => self::SECRET_SENTINELS[0],
        ], true);

        // PRG is the normal path; a strict 422 for the extra secret-like field is
        // also acceptable and must still not persist anything.
        if ($response->status() === 302) {
            $this->assertStatus(302, $response);
        } else {
            $this->assertRejected($response, 'Payment settings with a secret field');
        }

        $providers = $this->platformSettingValue('payments.providers');
        $this->assertTrue(is_array($providers));
        $this->assertSame('test', $providers['mpesa']['mode'] ?? null);

        $audit = $this->latestSettingsAudit();
        if ($audit !== []) {
            $encoded = (string) json_encode($audit);
            $this->assertSame('billing.settings_updated', (string) ($audit['action'] ?? ''));
            $this->assertNoSecretLeak($encoded, 'Settings audit metadata');
            $this->assertStringNotContainsString('254700000020', $encoded, 'Settings audit metadata must not contain phone PII.');
        }

        // Secrets are env-only: no platform_settings value may contain a sentinel.
        $rows = $this->db->select('SELECT `value` FROM platform_settings');
        foreach ($rows as $row) {
            $this->assertNoSecretLeak((string) $row['value'], 'platform_settings values');
        }

        $this->assertNoSecretLeak($this->get(self::GLOBAL_PAYMENTS)->body(), 'Payment settings page after save');
    }

    public function testGlobalSettingsLabelsAreEscaped(): void
    {
        $this->ensureLoggedInAs(self::SUPER);

        $payload = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $response = $this->post(self::GLOBAL_PAYMENTS, ['provider' => 'mpesa', 'label' => $payload], true);
        $this->assertTrue(in_array($response->status(), [302, 422], true), 'Unsupported label payloads are rejected (PRG or 422).');

        $page = $this->get(self::GLOBAL_PAYMENTS);
        $this->assertStatus(200, $page);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page->body());
        $this->assertStringNotContainsString('onerror=alert(2)', $page->body());
        $this->assertCleanMarkup($page, 'global payment settings after XSS label');
    }


    public function testPaymentLinkExpiryOnlyAcceptsOneToSevenDays(): void
    {
        $this->ensureLoggedInAs(self::SUPER);
        $before = $this->platformSettingValue('payments.links.ttl_minutes');

        foreach (['8', '0', '30'] as $invalid) {
            $response = $this->post(self::GLOBAL_PAYMENTS, [
                'provider' => 'mpesa', 'mode' => 'test', 'methods' => ['mpesa'], 'link_expiry_days' => $invalid,
            ], true);
            $this->assertSame(422, $response->status(), 'link_expiry_days=' . $invalid . ' must be a 422 validation error.');
        }

        $this->assertSame($before, $this->platformSettingValue('payments.links.ttl_minutes'), 'Rejected expiry values must not persist.');
    }
}
