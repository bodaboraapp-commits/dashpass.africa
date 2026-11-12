<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Admin estate settings (estate-admin-management-contract §2 "Settings", §5):
 * allowlist-only keys, range validation, persistence in the seeder's JSON
 * format and audit with old/new values.
 */
final class AdminSettingsTest extends AdminModuleTestCase
{
    public function testSettingsPageShowsTheAllowlist(): void
    {
        $response = $this->adminGet('/admin/settings');
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'Estate settings');

        $this->assertTextContains('visitor_validity_minutes', $response);
        $this->assertTextContains('visitor_parking_grace_minutes', $response);
        $this->assertTextContains('parking_overstay_grace_minutes', $response);

        // Seeded values are rendered from real data.
        $this->assertTextContains('240', $response);
        $this->assertTextContains('30', $response);

        $forms = $this->xpath('//form[@action="/admin/settings"]', $this->dom($response));
        $this->assertCount(3, $forms, 'The settings page offers estate defaults, appearance and billing forms.');
        $form = $forms->item(0);
        $this->assertNotNull($form);
        $this->assertCount(1, $this->xpath('.//input[@name="_token"]', $form));
    }

    public function testUpdateAllowlistedSettingPersistsJsonAndAudits(): void
    {
        $response = $this->adminPost('/admin/settings', [
            'visitor_validity_minutes' => '300',
            'visitor_parking_grace_minutes' => '45',
        ]);
        $this->assertAdminRedirect($response, '/admin/settings');

        $estateId = (int) $this->estate()['id'];
        $validity = $this->db->scalar(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :id AND setting_key = :key',
            ['id' => $estateId, 'key' => 'visitor_validity_minutes']
        );
        $grace = $this->db->scalar(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :id AND setting_key = :key',
            ['id' => $estateId, 'key' => 'visitor_parking_grace_minutes']
        );

        $this->assertNotFalse(json_decode((string) $validity, true), 'Setting values must stay JSON encoded.');
        $this->assertSame(300, (int) json_decode((string) $validity, true));
        $this->assertSame(45, (int) json_decode((string) $grace, true));

        $audit = $this->db->selectOne("SELECT action, metadata FROM audit_logs WHERE action = 'admin.settings_updated' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit, 'Settings updates must be audited.');
        $this->assertContains('visitor_validity_minutes', (string) $audit['metadata']);
    }

    public function testSettingsMapFormatIsAccepted(): void
    {
        $response = $this->adminPost('/admin/settings', [
            'settings' => [
                'visitor_validity_minutes' => '360',
                'visitor_parking_grace_minutes' => '15',
            ],
        ]);
        $this->assertAdminRedirect($response, '/admin/settings');

        $estateId = (int) $this->estate()['id'];
        $validity = (int) json_decode((string) $this->db->scalar(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :id AND setting_key = :key',
            ['id' => $estateId, 'key' => 'visitor_validity_minutes']
        ), true);

        $this->assertSame(360, $validity);
    }
    public function testUnknownSettingKeyIsRejected(): void
    {
        $response = $this->adminPost('/admin/settings', [
            'estate_name' => 'Hacked Estate',
            'visitor_validity_minutes' => '240',
        ]);

        $this->assertAdminRejected($response, '/admin/settings', ['SETTING_NOT_ALLOWED', 'not editable from the admin console']);
    }

    public function testOutOfRangeSettingIsRejected(): void
    {
        foreach ([
            ['visitor_validity_minutes' => '5'],
            ['visitor_validity_minutes' => '5000'],
            ['visitor_parking_grace_minutes' => '999'],
        ] as $body) {
            $response = $this->adminPost('/admin/settings', $body);
            $this->assertAdminRejected($response, '/admin/settings', ['between', 'invalid', 'must be', 'range', 'VALIDATION']);
        }

        // Stored values are unchanged after rejected attempts.
        $estateId = (int) $this->estate()['id'];
        $this->assertSame(240, (int) json_decode((string) $this->db->scalar(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :id AND setting_key = :key',
            ['id' => $estateId, 'key' => 'visitor_validity_minutes']
        ), true));
    }

    public function testSettingsAreEstateScoped(): void
    {
        $riverside = $this->seededEstate('riverside-court');
        $riversideValidity = $this->db->scalar(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :id AND setting_key = :key',
            ['id' => (int) $riverside['id'], 'key' => 'visitor_validity_minutes']
        );

        // David updates Riverside settings; Greenview stays untouched.
        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);
        $this->assertStatus(200, $this->get('/admin/settings'));
        $this->assertAdminRedirect($this->post('/admin/settings', ['visitor_validity_minutes' => '120'], true), '/admin/settings');

        $greenview = (int) $this->db->scalar(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :id AND setting_key = :key',
            ['id' => (int) $this->estate()['id'], 'key' => 'visitor_validity_minutes']
        );
        $this->assertSame(240, (int) json_decode((string) $greenview), 'A Riverside admin must never change Greenview settings.');

        $riversideAfter = (int) json_decode((string) $this->db->scalar(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :id AND setting_key = :key',
            ['id' => (int) $riverside['id'], 'key' => 'visitor_validity_minutes']
        ), true);
        $this->assertSame(120, $riversideAfter);
        $this->assertNotSame((int) json_decode((string) $riversideValidity, true), 120);
    }

    public function testAppearanceDefaultFallbackExplicitChoiceAndAudit(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $page = $this->adminGet('/admin/settings');
        $this->assertStatus(200, $page);
        $this->assertTextContains('Appearance', $page);
        $this->assertCount(1, $this->xpath('//select[@name="ui.appearance_default"]', $this->dom($page)), 'The appearance card exposes the estate default selector.');
        foreach (['default', 'light', 'dark'] as $choice) {
            $this->assertCount(1, $this->xpath('//select[@name="ui.appearance_default"]//option[@value="' . $choice . '"]', $this->dom($page)));
        }

        $estate = $this->seededEstate(self::ESTATE_SLUG);
        $this->db->execute('DELETE FROM estate_settings WHERE estate_id = :e AND setting_key = :k', ['e' => (int) $estate['id'], 'k' => 'ui.appearance_default']);

        // Fallback: no estate value ? built-in default, no forced dark theme.
        $this->ensureLoggedInAs('+254700000010');
        $fallback = $this->get('/app');
        $html = $this->dom($fallback)->documentElement;
        $this->assertNotNull($html);
        $this->assertTrue(in_array((string) $html->getAttribute('data-theme-default'), ['', 'default'], true), 'Without an estate override the app uses the built-in default.');

        // Explicit dark: PRG, persisted JSON, audited, and applied to resident pages.
        $this->ensureLoggedInAs(self::GRACE);
        $this->assertAdminRedirect($this->adminPost('/admin/settings', ['ui.appearance_default' => 'dark']), '/admin/settings');
        $stored = json_decode((string) $this->db->scalar(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :e AND setting_key = :k',
            ['e' => (int) $estate['id'], 'k' => 'ui.appearance_default']
        ), true);
        $this->assertSame('dark', $stored);
        $audit = $this->db->selectOne("SELECT metadata FROM audit_logs WHERE action = 'billing.settings_updated' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit);
        $this->assertStringContainsString('ui.appearance_default', (string) $audit['metadata']);

        $this->ensureLoggedInAs('+254700000010');
        $dark = $this->get('/app');
        $this->assertSame('dark', (string) $this->dom($dark)->documentElement?->getAttribute('data-theme-default') ?? '');

        // Invalid choice: PRG with a field error and no write.
        $this->ensureLoggedInAs(self::GRACE);
        $this->assertAdminRedirect($this->adminPost('/admin/settings', ['ui.appearance_default' => 'banana']), '/admin/settings');
        $errors = $this->adminGet('/admin/settings');
        $this->assertTextContains('Choose default, light or dark.', $errors);
        $after = json_decode((string) $this->db->scalar(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :e AND setting_key = :k',
            ['e' => (int) $estate['id'], 'k' => 'ui.appearance_default']
        ), true);
        $this->assertSame('dark', $after, 'An invalid appearance must not overwrite the stored value.');

        // Explicit light is accepted; restore the default afterwards.
        $this->assertAdminRedirect($this->adminPost('/admin/settings', ['ui.appearance_default' => 'light']), '/admin/settings');
        $this->assertSame('light', json_decode((string) $this->db->scalar(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :e AND setting_key = :k',
            ['e' => (int) $estate['id'], 'k' => 'ui.appearance_default']
        ), true));
        $this->assertAdminRedirect($this->adminPost('/admin/settings', ['ui.appearance_default' => 'default']), '/admin/settings');
    }

    public function testBillingSettingsMetaShowsValueSources(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $page = $this->adminGet('/admin/settings');
        $this->assertStatus(200, $page);

        // billing_meta drives the source badges: with no estate overrides the
        // built-in/platform defaults are labelled, and every row is overridable.
        $this->assertTextContains('overridable', $page);

        $qaContext = new \App\Core\Context(new \App\Core\Request('GET', '/qa-meta', [], []), 'qa-meta-settings');
        $meta = \App\Modules\Admin\EstateBillingSettingsService::make($qaContext)->metadata();
        $this->assertSame(false, $meta['billing.automation.enabled']['overridable'] ?? null, 'Platform-owned automation flag is locked.');
        $this->assertSame(false, $meta['billing.invoice.default_status']['overridable'] ?? null, 'Platform-owned invoice default is locked.');
        $this->assertSame(true, $meta['billing.payment_methods']['overridable'] ?? null, 'Estate-owned payment methods remain overridable.');
        $this->assertTrue(
            str_contains($page->body(), 'Platform default') || str_contains($page->body(), 'Default'),
            'Billing settings must show the inherited value source.'
        );
        $this->assertTextContains('Utility basis', $page);
        $this->assertTextContains('Notification channels', $page);
        $this->assertTextContains('Schedule defaults', $page);
    }
}
