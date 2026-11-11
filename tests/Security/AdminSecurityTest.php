<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Integration\AdminModuleTestCase;

/**
 * Admin portal security (estate-admin-management-contract §2 hard rules, §5):
 * CSRF on every admin POST, XSS escaping of every admin field, soft-archive only
 * (no hard deletes), and audit rows with the exact §2 actions and no PII.
 */
final class AdminSecurityTest extends AdminModuleTestCase
{
    /**
     * All 26 admin POST routes with safe placeholder ids.
     *
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    private function adminPostRoutes(): array
    {
        $building = $this->seededBuilding('Block A');
        $household = $this->seededHouseholdRow('A-001');
        $member = $this->db->selectOne(
            'SELECT uuid FROM household_members WHERE household_id = :id LIMIT 1',
            ['id' => (int) $household['id']]
        );
        $janeMembership = $this->membershipRow(self::JANE, self::ESTATE_SLUG);
        $john = $this->membershipRow(self::GUARD_MAIN, self::ESTATE_SLUG);
        $gate = $this->gate('Main Gate');

        return [
            ['/admin/buildings', ['name' => 'Csrf Block', 'building_type' => 'cluster']],
            ['/admin/buildings/' . $building['uuid'], ['name' => 'Csrf Block', 'building_type' => 'cluster']],
            ['/admin/households', ['unit_number' => 'CSRF-1', 'household_type' => 'apartment']],
            ['/admin/households/' . $household['uuid'], ['unit_number' => 'A-001', 'household_type' => 'apartment', 'status' => 'active']],
            ['/admin/households/' . $household['uuid'] . '/archive', []],
            ['/admin/households/' . $household['uuid'] . '/members', ['mode' => 'existing', 'phone' => '+254700000012', 'relationship' => 'family_member']],
            ['/admin/households/' . $household['uuid'] . '/members/' . ($member['uuid'] ?? '00000000-0000-4000-8000-000000000000') . '/remove', []],
            ['/admin/residents', ['full_name' => 'Csrf Resident', 'phone' => '+254700099999', 'role_slug' => 'resident']],
            ['/admin/residents/' . $janeMembership['uuid'] . '/suspend', []],
            ['/admin/residents/' . $janeMembership['uuid'] . '/reactivate', []],
            ['/admin/guards', ['full_name' => 'Csrf Guard', 'phone' => '+254700099998', 'gate_uuids' => [(string) $gate['uuid']]]],
            ['/admin/guards/' . $john['uuid'] . '/gates', ['gate_uuids' => [(string) $gate['uuid']]]],
            ['/admin/guards/' . $john['uuid'] . '/archive', []],
            ['/admin/gates', ['name' => 'Csrf Gate']],
            ['/admin/gates/' . $gate['uuid'], ['name' => 'Csrf Gate Renamed']],
            ['/admin/gates/' . $gate['uuid'] . '/archive', []],
            ['/admin/settings', ['visitor_validity_minutes' => '240']],
        ];
    }

    public function testCsrfIsEnforcedOnEveryAdminPost(): void
    {
        $before = [
            'buildings' => (int) $this->db->scalar('SELECT COUNT(*) FROM buildings'),
            'households' => (int) $this->db->scalar('SELECT COUNT(*) FROM households'),
            'gates' => (int) $this->db->scalar('SELECT COUNT(*) FROM gates'),
            'memberships_active' => (int) $this->db->scalar("SELECT COUNT(*) FROM estate_memberships WHERE estate_id = :id AND status = 'active'", ['id' => (int) $this->estate()['id']]),
        ];

        foreach ($this->adminPostRoutes() as [$path, $body]) {
            $this->ensureLoggedInAs(self::GRACE);
            $response = $this->post($path, $body, false);

            $this->assertStatus(403, $response, 'Admin POST ' . $path . ' must reject a missing CSRF token.');
            $this->assertBodyContains('CSRF_TOKEN_INVALID', $response);
        }

        $this->assertSame($before['buildings'], (int) $this->db->scalar('SELECT COUNT(*) FROM buildings'));
        $this->assertSame($before['households'], (int) $this->db->scalar('SELECT COUNT(*) FROM households'));
        $this->assertSame($before['gates'], (int) $this->db->scalar('SELECT COUNT(*) FROM gates'));
        $this->assertSame($before['memberships_active'], (int) $this->db->scalar("SELECT COUNT(*) FROM estate_memberships WHERE estate_id = :id AND status = 'active'", ['id' => (int) $this->estate()['id']]));
    }

    public function testAdminFieldsAreEscapedEverywhereTheyRender(): void
    {
        $payload = '<script>alert("admin-xss")</script>';
        $attrPayload = '<img src=x onerror="alert(\'admin-attr\')">';

        $this->assertAdminRedirect($this->adminPost('/admin/buildings', [
            'name' => 'XSS Building ' . bin2hex(random_bytes(3)),
            'building_type' => 'cluster',
        ]), '/admin/buildings');

        $newBuilding = $this->db->selectOne("SELECT uuid FROM buildings WHERE name LIKE 'XSS Building %' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($newBuilding);

        // Free-text fields that accept HTML-ish content are escaped on render.
        $this->assertAdminRedirect($this->adminPost('/admin/buildings/' . $newBuilding['uuid'], [
            'name' => $payload,
            'building_type' => 'cluster',
        ]), '/admin/buildings');

        $list = $this->get('/admin/buildings');
        $this->assertStatus(200, $list);
        $this->assertFalse(str_contains($list->body(), $payload), 'The admin buildings list must escape the building name.');
        $this->assertTrue(str_contains($list->body(), '&lt;script&gt;alert(&quot;admin-xss&quot;)&lt;/script&gt;'), 'The escaped payload must render as inert text.');

        // Detail page too.
        $detail = $this->get('/admin/buildings/' . $newBuilding['uuid']);
        $this->assertFalse(str_contains($detail->body(), $payload));

        // Gate location/name.
        $this->assertAdminRedirect($this->adminPost('/admin/gates', [
            'name' => 'XSS Gate',
            'location' => $attrPayload,
        ]), '/admin/gates');
        $gatePage = $this->get('/admin/gates');
        $this->assertFalse(str_contains($gatePage->body(), $attrPayload), 'The gate location must be attribute-safe.');
        $this->assertFalse(str_contains($gatePage->body(), 'onerror="alert('));

        // Guard full name.
        $this->assertAdminRedirect($this->adminPost('/admin/guards', [
            'full_name' => $payload,
            'phone' => '+2547000970' . random_int(10, 99),
            'gate_uuids' => [(string) $this->gate('Main Gate')['uuid']],
        ]), '/admin/guards');
        $guardsPage = $this->get('/admin/guards');
        $this->assertFalse(str_contains($guardsPage->body(), $payload), 'The guard name must be escaped on the admin list.');
    }

    public function testArchivesAndRemovalsAreSoftNoHardDeletes(): void
    {
        $counts = [
            'households' => (int) $this->db->scalar('SELECT COUNT(*) FROM households'),
            'gates' => (int) $this->db->scalar('SELECT COUNT(*) FROM gates'),
            'memberships' => (int) $this->db->scalar('SELECT COUNT(*) FROM estate_memberships'),
            'members' => (int) $this->db->scalar('SELECT COUNT(*) FROM household_members'),
        ];

        $household = $this->seededHouseholdRow('B-001');
        $gate = $this->gate('Service Gate');
        $john = $this->membershipRow(self::GUARD_MAIN);
        $lydiaHousehold = $this->seededHouseholdRow('A-002');
        $lydia = $this->db->selectOne("SELECT id FROM users WHERE phone = '+254700000012' LIMIT 1");
        $this->assertNotNull($lydia);
        $member = $this->db->selectOne(
            "SELECT uuid FROM household_members WHERE household_id = :hid AND user_id = :uid AND status = 'active' LIMIT 1",
            ['hid' => (int) $lydiaHousehold['id'], 'uid' => (int) $lydia['id']]
        );
        $this->assertNotNull($member);

        $this->assertAdminRedirect($this->adminPost('/admin/households/' . $household['uuid'] . '/archive', []), '/admin/households');
        $this->assertAdminRedirect($this->adminPost('/admin/gates/' . $gate['uuid'] . '/archive', []), '/admin/gates');
        $this->assertAdminRedirect($this->adminPost('/admin/guards/' . $john['uuid'] . '/archive', []), '/admin/guards');
        $this->assertAdminRedirect($this->adminPost('/admin/households/' . $lydiaHousehold['uuid'] . '/members/' . $member['uuid'] . '/remove', []), '/admin/households/');

        $this->assertSame($counts['households'], (int) $this->db->scalar('SELECT COUNT(*) FROM households'));
        $this->assertSame($counts['gates'], (int) $this->db->scalar('SELECT COUNT(*) FROM gates'));
        $this->assertSame($counts['memberships'], (int) $this->db->scalar('SELECT COUNT(*) FROM estate_memberships'));
        $this->assertSame($counts['members'], (int) $this->db->scalar('SELECT COUNT(*) FROM household_members'));

        // Statuses actually changed.
        $this->assertSame('inactive', (string) $this->db->scalar('SELECT status FROM households WHERE id = :id', ['id' => (int) $household['id']]));
        $this->assertSame('inactive', (string) $this->db->scalar('SELECT status FROM gates WHERE id = :id', ['id' => (int) $gate['id']]));
        $this->assertSame('inactive', (string) $this->db->scalar('SELECT status FROM estate_memberships WHERE id = :id', ['id' => (int) $john['id']]));
        $this->assertSame('inactive', (string) $this->db->scalar('SELECT status FROM household_members WHERE uuid = :uuid', ['uuid' => (string) $member['uuid']]));
    }

    public function testAuditActionsMatchTheContractAndContainNoPii(): void
    {
        $phone = '+2547000980' . random_int(10, 99);

        // Trigger a representative set of audited mutations.
        $this->assertAdminRedirect($this->adminPost('/admin/buildings', ['name' => 'Audit Block ' . bin2hex(random_bytes(2)), 'building_type' => 'cluster']), '/admin/buildings');
        $this->assertAdminRedirect($this->adminPost('/admin/residents', [
            'full_name' => 'Audit PII Person',
            'phone' => $phone,
            'role_slug' => 'resident',
        ]), '/admin/residents');
        $this->assertAdminRedirect($this->adminPost('/admin/gates', ['name' => 'Audit Gate ' . bin2hex(random_bytes(2))]), '/admin/gates');
        $this->assertAdminRedirect($this->adminPost('/admin/settings', ['visitor_validity_minutes' => '241']), '/admin/settings');

        $expected = [
            'admin.building_created',
            'admin.resident_created',
            'admin.gate_created',
            'admin.settings_updated',
        ];

        foreach ($expected as $action) {
            $count = (int) $this->db->scalar('SELECT COUNT(*) FROM audit_logs WHERE action = :action', ['action' => $action]);
            $this->assertTrue($count > 0, 'Audit action ' . $action . ' must be recorded.');
        }

        // No admin audit metadata may contain the resident phone, name, PIN or OTP.
        $rows = $this->db->select("SELECT action, metadata FROM audit_logs WHERE action LIKE 'admin.%'");
        $normalizedPhone = ltrim(\App\Core\Phone::normalize($phone), '+');

        foreach ($rows as $row) {
            $metadata = (string) ($row['metadata'] ?? '');
            $this->assertFalse(str_contains($metadata, $normalizedPhone), 'Audit metadata for ' . $row['action'] . ' must not contain a phone number.');
            $this->assertFalse(str_contains($metadata, 'Audit PII Person'), 'Audit metadata must not contain resident names.');
            $this->assertFalse(stripos($metadata, 'otp') !== false, 'Audit metadata must not contain OTP material.');
        }
    }

    public function testAdminErrorsDoNotLeakForeignDataOrPii(): void
    {
        $riverside = $this->seededEstate('riverside-court');
        $riversideBuilding = $this->db->selectOne(
            'SELECT uuid, name FROM buildings WHERE estate_id = :id LIMIT 1',
            ['id' => (int) $riverside['id']]
        );
        $this->assertNotNull($riversideBuilding);

        $this->ensureLoggedInAs(self::GRACE);
        $response = $this->get('/admin/buildings/' . $riversideBuilding['uuid']);

        $this->assertStatus(404, $response);
        $this->assertFalse(str_contains($response->body(), (string) $riversideBuilding['name']), 'A foreign-estate 404 must not leak the resource name.');
        $this->assertFalse(str_contains($response->body(), 'SQLSTATE'), 'Admin errors must never leak SQL details.');
        $this->assertFalse(str_contains($response->body(), 'Trace'), 'Admin errors must never leak stack traces.');
    }
}
