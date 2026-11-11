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
        $this->assertCount(1, $forms, 'The settings page must offer an update form.');
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
}
