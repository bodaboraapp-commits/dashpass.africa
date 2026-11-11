<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Admin guards (estate-admin-management-contract §2 "Guards", §5): create with
 * gate assignments, replace assignments, login access and archive.
 */
final class AdminGuardTest extends AdminModuleTestCase
{
    public function testGuardListShowsAssignedGates(): void
    {
        $response = $this->adminGet('/admin/guards');
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'Guards');

        $this->assertTextContains('John', $response);
        $this->assertTextContains('Main Gate', $response);
        $this->assertTextContains('Service Gate', $response);

        $forms = $this->xpath('//form[@action="/admin/guards"]', $this->dom($response));
        $this->assertCount(1, $forms, 'The list must offer an add-guard form.');
        $form = $forms->item(0);
        $this->assertNotNull($form);
        $this->assertCount(1, $this->xpath('.//input[@name="full_name"]', $form));
        $this->assertCount(1, $this->xpath('.//input[@name="phone"]', $form));
        $this->assertTrue($this->xpath('.//input[@name="gate_uuids[]"] | .//input[@name="gate_uuids"]', $form)->length >= 2, 'All active gates must be offered as checkboxes.');
    }

    public function testCreateGuardWithGatesAndLogin(): void
    {
        $phone = '+2547000950' . random_int(10, 99);
        $mainGate = $this->gate('Main Gate');

        $response = $this->adminPost('/admin/guards', [
            'full_name' => 'Admin Created Guard',
            'phone' => $phone,
            'gate_uuids' => [(string) $mainGate['uuid']],
        ]);
        $this->assertAdminRedirect($response, '/admin/guards');

        $user = $this->db->selectOne('SELECT id FROM users WHERE phone = :phone LIMIT 1', ['phone' => \App\Core\Phone::normalize($phone)]);
        $this->assertNotNull($user, 'A guard user must be created.');

        $membership = $this->db->selectOne(
            "SELECT m.status, r.name AS role_name
             FROM estate_memberships m
             INNER JOIN roles r ON r.id = m.role_id
             WHERE m.user_id = :uid AND m.estate_id = :eid",
            ['uid' => (int) $user['id'], 'eid' => (int) $this->estate()['id']]
        );
        $this->assertNotNull($membership);
        $this->assertSame('security_guard', (string) $membership['role_name']);
        $this->assertSame('active', (string) $membership['status']);

        $assignments = $this->db->select(
            "SELECT g.name, a.status FROM guard_gate_assignments a INNER JOIN gates g ON g.id = a.gate_id
             WHERE a.guard_user_id = :uid AND a.estate_id = :eid",
            ['uid' => (int) $user['id'], 'eid' => (int) $this->estate()['id']]
        );
        $this->assertCount(1, $assignments);
        $this->assertSame('Main Gate', (string) $assignments[0]['name']);
        $this->assertSame('active', (string) $assignments[0]['status']);

        // The new guard can log in and their gate dashboard shows the assignment.
        $this->reloginAs($phone);
        $dashboard = $this->get('/gate');
        $this->assertStatus(200, $dashboard);
        $this->assertBodyContains('Main Gate', $dashboard);

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'admin.guard_created'");
        $this->assertTrue((int) $audit['c'] > 0, 'Guard creation must be audited.');
    }

    public function testGuardCreateForExistingEstateMemberIsRejected(): void
    {
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM users');

        $response = $this->adminPost('/admin/guards', [
            'full_name' => 'Duplicate Guard',
            'phone' => self::JANE,
            'gate_uuids' => [(string) $this->gate('Main Gate')['uuid']],
        ]);

        $this->assertAdminRejected($response, '/admin/guards', ['GUARD_EXISTS', 'already belongs to an active member']);
        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM users'));
    }
    public function testGuardCreationRequiresAGate(): void
    {
        $phone = '+2547000960' . random_int(10, 99);

        $response = $this->adminPost('/admin/guards', [
            'full_name' => 'No Gate Guard',
            'phone' => $phone,
            'gate_uuids' => [],
        ]);

        $this->assertAdminRejected($response, '/admin/guards', ['gate', 'required']);
        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE phone = :phone', ['phone' => \App\Core\Phone::normalize($phone)])
        );
    }

    public function testAssignGatesReplacesTheSet(): void
    {
        $john = $this->membershipRow(self::GUARD_MAIN);
        $mainGate = $this->gate('Main Gate');
        $serviceGate = $this->gate('Service Gate');

        $this->assertAdminRedirect($this->adminPost('/admin/guards/' . $john['uuid'] . '/gates', [
            'gate_uuids' => [(string) $mainGate['uuid'], (string) $serviceGate['uuid']],
        ]), '/admin/guards');

        $active = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM guard_gate_assignments WHERE guard_user_id = :uid AND estate_id = :eid AND status = 'active'",
            ['uid' => (int) $john['user_id'], 'eid' => (int) $this->estate()['id']]
        );
        $this->assertSame(2, $active, 'Assign-gates must set exactly the requested gates.');

        // Replace with a single gate: the previous set is deactivated, not duplicated.
        $this->assertAdminRedirect($this->adminPost('/admin/guards/' . $john['uuid'] . '/gates', [
            'gate_uuids' => [(string) $serviceGate['uuid']],
        ]), '/admin/guards');

        $assigned = $this->db->select(
            "SELECT g.name FROM guard_gate_assignments a INNER JOIN gates g ON g.id = a.gate_id
             WHERE a.guard_user_id = :uid AND a.estate_id = :eid AND a.status = 'active'",
            ['uid' => (int) $john['user_id'], 'eid' => (int) $this->estate()['id']]
        );
        $this->assertCount(1, $assigned);
        $this->assertSame('Service Gate', (string) $assigned[0]['name']);

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'admin.guard_gates_updated'");
        $this->assertTrue((int) $audit['c'] > 0);
    }

    public function testArchiveGuardRemovesAccess(): void
    {
        $john = $this->membershipRow(self::GUARD_MAIN);

        $this->assertAdminRedirect($this->adminPost('/admin/guards/' . $john['uuid'] . '/archive', []), '/admin/guards');

        $this->assertSame('suspended', (string) $this->db->scalar(
            'SELECT status FROM estate_memberships WHERE id = :id',
            ['id' => (int) $john['id']]
        ), 'Archiving a guard must suspend the membership (soft).');

        // The guard loses access on the next request.
        $this->ensureLoggedInAs(self::GUARD_MAIN);
        $access = $this->get('/gate');
        $this->assertTrue(in_array($access->status(), [302, 403], true), 'An archived guard must lose access.');

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'admin.guard_archived'");
        $this->assertTrue((int) $audit['c'] > 0);
    }

    public function testGuardCannotReachTheAdminPortal(): void
    {
        $this->loginGuard();
        $this->assertStatus(403, $this->get('/admin'));
        $this->assertStatus(403, $this->get('/admin/guards'));
    }

    public function testGuardsAreEstateScoped(): void
    {
        $greenviewGuard = $this->membershipRow(self::GUARD_MAIN, 'greenview-residences');

        $foreign = $this->adminPost('/admin/guards/' . $greenviewGuard['uuid'] . '/archive', [], self::DAVID_RIVERSIDE);
        $this->assertAdminRejected($foreign, '/admin/guards', ['not available', 'NOT_FOUND']);
        $this->assertSame('active', (string) $this->db->scalar(
            'SELECT status FROM estate_memberships WHERE id = :id',
            ['id' => (int) $greenviewGuard['id']]
        ));
    }
}
