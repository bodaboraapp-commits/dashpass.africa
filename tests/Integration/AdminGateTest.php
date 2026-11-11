<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Admin gates (estate-admin-management-contract §2 "Gates", §5): create/edit,
 * uniqueness, soft archive, selector exclusion and archived-gate rejection on
 * the visitor check-in path.
 */
final class AdminGateTest extends AdminModuleTestCase
{
    public function testGateListShowsGatesAndGuardCounts(): void
    {
        $response = $this->adminGet('/admin/gates');
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'Gates');

        $this->assertTextContains('Main Gate', $response);
        $this->assertTextContains('Service Gate', $response);

        $forms = $this->xpath('//form[@action="/admin/gates"]', $this->dom($response));
        $this->assertCount(1, $forms, 'The list must offer an add-gate form.');
        $form = $forms->item(0);
        $this->assertNotNull($form);
        $this->assertCount(1, $this->xpath('.//input[@name="name"]', $form));
    }

    public function testCreateAndEditGate(): void
    {
        $response = $this->adminPost('/admin/gates', [
            'name' => 'Admin North Gate',
            'location' => 'North perimeter',
        ]);
        $this->assertAdminRedirect($response, '/admin/gates');

        $gate = $this->db->selectOne(
            'SELECT id, uuid, status FROM gates WHERE name = :name LIMIT 1',
            ['name' => 'Admin North Gate']
        );
        $this->assertNotNull($gate, 'The created gate must exist.');
        $this->assertSame('active', (string) $gate['status']);

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'admin.gate_created'");
        $this->assertTrue((int) $audit['c'] > 0);

        // Edit name/location.
        $this->assertAdminRedirect($this->adminPost('/admin/gates/' . $gate['uuid'], [
            'name' => 'Admin North Gate Renamed',
            'location' => 'North-west perimeter',
        ]), '/admin/gates');

        $row = $this->db->selectOne('SELECT name, location FROM gates WHERE id = :id', ['id' => (int) $gate['id']]);
        $this->assertNotNull($row);
        $this->assertSame('Admin North Gate Renamed', (string) $row['name']);
        $this->assertSame('North-west perimeter', (string) $row['location']);

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'admin.gate_updated'");
        $this->assertTrue((int) $audit['c'] > 0);
    }

    public function testDuplicateGateNameIsRejected(): void
    {
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM gates');

        $response = $this->adminPost('/admin/gates', ['name' => 'Main Gate']);

        $this->assertAdminRejected($response, '/admin/gates', ['GATE_EXISTS', 'already exist', 'already registered']);
        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM gates'));
    }

    public function testArchiveGateIsSoftAndHidesItFromGuardAssignment(): void
    {
        $gate = $this->gate('Service Gate');
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM gates');

        $this->assertAdminRedirect($this->adminPost('/admin/gates/' . $gate['uuid'] . '/archive', []), '/admin/gates');

        $this->assertSame('inactive', (string) $this->db->scalar(
            'SELECT status FROM gates WHERE id = :id',
            ['id' => (int) $gate['id']]
        ), 'Archive must be a soft status change.');
        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM gates'), 'No hard delete is allowed.');

        // The archived gate is not offered in the guard gate-assignment UI.
        $guardsPage = $this->adminGet('/admin/guards');
        $this->assertCount(
            0,
            $this->xpath('//input[@value="' . $gate['uuid'] . '"]', $this->dom($guardsPage)),
            'An archived gate must not be offered as a new assignment option.'
        );

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'admin.gate_archived'");
        $this->assertTrue((int) $audit['c'] > 0);
    }

    public function testArchivedGateCannotBeAssignedToGuards(): void
    {
        $gate = $this->gate('Service Gate');
        $this->assertAdminRedirect($this->adminPost('/admin/gates/' . $gate['uuid'] . '/archive', []), '/admin/gates');

        $john = $this->membershipRow(self::GUARD_MAIN);
        $response = $this->adminPost('/admin/guards/' . $john['uuid'] . '/gates', [
            'gate_uuids' => [(string) $gate['uuid']],
        ]);

        $this->assertAdminRejected($response, '/admin/guards', ['gate', 'inactive', 'not available', 'invalid']);

        $count = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM guard_gate_assignments WHERE guard_user_id = :uid AND gate_id = :gid AND status = 'active'",
            ['uid' => (int) $john['user_id'], 'gid' => (int) $gate['id']]
        );
        $this->assertSame(0, $count, 'An archived gate must not gain new active assignments.');
    }

    public function testArchivedGateIsRejectedByTheVisitorCheckinPath(): void
    {
        $gate = $this->gate('Service Gate');

        // A visitor invitation for the live window, then archive David's gate.
        $data = $this->createInvitation();
        $this->assertAdminRedirect($this->adminPost('/admin/gates/' . $gate['uuid'] . '/archive', []), '/admin/gates');

        // Guard David (Service Gate) can no longer operate that gate context.
        $this->reloginAs(self::GUARD_SERVICE);
        $this->forceGateContext('Service Gate');
        $verify = $this->guardVerifyPin((string) $data['pin']);

        $this->assertTrue(
            in_array($verify->status(), [409, 403], true),
            'An archived gate must not be usable for visitor verification, got HTTP ' . $verify->status() . '.'
        );
    }

    public function testGatesAreEstateScoped(): void
    {
        $riverside = $this->seededEstate('riverside-court');
        $riversideGate = $this->db->selectOne(
            'SELECT uuid FROM gates WHERE estate_id = :id LIMIT 1',
            ['id' => (int) $riverside['id']]
        );

        if ($riversideGate !== null) {
            $this->ensureLoggedInAs(self::GRACE);
            $foreign = $this->post('/admin/gates/' . $riversideGate['uuid'], [
                'name' => 'Hijacked Gate',
                'location' => '',
            ], true);
            $this->assertStatus(404, $foreign);
        }

        // A Greenview gate uuid is a 404 for the Riverside admin.
        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);
        $greenviewGate = $this->gate('Main Gate');
        $this->assertStatus(404, $this->post('/admin/gates/' . $greenviewGate['uuid'] . '/archive', [], true));
        $this->assertSame('active', (string) $this->db->scalar(
            'SELECT status FROM gates WHERE id = :id',
            ['id' => (int) $greenviewGate['id']]
        ));
    }
}
