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

    public function testGateEditActionsShareOneHorizontalRow(): void
    {
        $response = $this->adminGet('/admin/gates');
        $this->assertStatus(200, $response);

        $rows = $this->xpath('//div[contains(concat(" ", normalize-space(@class), " "), " form-actions--row ")]', $this->dom($response));
        $this->assertCount(1, $rows, 'Gate edit actions must share one action row.');
        $row = $rows->item(0);
        $this->assertNotNull($row);
        $buttons = $this->xpath('.//button[@type="submit"]', $row);
        $this->assertSame(2, $buttons->length, 'The row must contain Save changes then Archive gate.');
        $this->assertSame('Save changes', trim((string) $buttons->item(0)->textContent));
        $this->assertSame('Archive gate', trim((string) $buttons->item(1)->textContent));
        $this->assertStringContainsString('btn--primary', (string) $buttons->item(0)->getAttribute('class'));
        $this->assertStringContainsString('btn--danger-ghost', (string) $buttons->item(1)->getAttribute('class'));
        $this->assertNotSame('', (string) $buttons->item(0)->getAttribute('form'));
        $this->assertNotSame('', (string) $buttons->item(1)->getAttribute('form'));
        $this->assertSame('Archive this gate? It will no longer be offered for guard assignments.', (string) $this->xpath('//form[contains(@id,"gate-archive-")]', $this->dom($response))->item(0)->getAttribute('data-confirm'));

        $css = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertMatchesRegularExpression('/\.form-actions--row\s*\{[^}]*flex-direction:\s*row[^}]*flex-wrap:\s*wrap[^}]*gap:\s*0\.75rem/s', $css);
        $this->assertMatchesRegularExpression('/\.form-actions--row \.btn--danger-ghost\s*\{[^}]*margin-left:\s*1rem/s', $css);
        $this->assertMatchesRegularExpression('/\.form-actions--row \.btn\s*\{[^}]*min-height:\s*44px/s', $css);
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

        $this->assertAdminRejected($response, '/admin/gates', ['GATE_EXISTS', 'already used in this estate']);
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
            $before = (string) $this->db->scalar('SELECT name FROM gates WHERE uuid = :uuid', ['uuid' => (string) $riversideGate['uuid']]);

            $foreign = $this->adminPost('/admin/gates/' . $riversideGate['uuid'], [
                'name' => 'Hijacked Gate',
                'location' => '',
            ], self::GRACE);

            $this->assertAdminRejected($foreign, '/admin/gates', ['not available', 'NOT_FOUND']);
            $this->assertSame($before, (string) $this->db->scalar('SELECT name FROM gates WHERE uuid = :uuid', ['uuid' => (string) $riversideGate['uuid']]));
        }

        // A Greenview gate uuid is rejected for the Riverside admin (mutation ? PRG).
        $greenviewGate = $this->gate('Main Gate');
        $foreignArchive = $this->adminPost('/admin/gates/' . $greenviewGate['uuid'] . '/archive', [], self::DAVID_RIVERSIDE);
        $this->assertAdminRejected($foreignArchive, '/admin/gates', ['not available', 'NOT_FOUND']);
        $this->assertSame('active', (string) $this->db->scalar(
            'SELECT status FROM gates WHERE id = :id',
            ['id' => (int) $greenviewGate['id']]
        ));
    }
}
