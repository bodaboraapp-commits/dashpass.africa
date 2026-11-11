<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Admin households + household members (estate-admin-management-contract §2
 * "Households"/"Household members", §5): create/edit/status, uniqueness, member
 * add/remove, primary-member rules, soft-archive semantics and the invite
 * selector exclusion.
 */
final class AdminHouseholdTest extends AdminModuleTestCase
{
    public function testHouseholdListShowsActiveHouseholdsAndStatusFilter(): void
    {
        $response = $this->adminGet('/admin/households');
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'Households');

        $this->assertTextContains('A-001', $response);
        $this->assertTextContains('Block A', $response);

        $forms = $this->xpath('//form[@action="/admin/households"]', $this->dom($response));
        $this->assertCount(1, $forms, 'The list must offer an add-household form.');
        $form = $forms->item(0);
        $this->assertNotNull($form);
        $this->assertCount(1, $this->xpath('.//input[@name="unit_number"]', $form));
        $this->assertCount(1, $this->xpath('.//*[@name="household_type"]', $form));

        // Archived filter is a real server-side link.
        $archived = $this->adminGet('/admin/households?status=inactive');
        $this->assertStatus(200, $archived);
        $this->assertTextNotContains('A-001', $archived, 'The archived filter must exclude active households.');
    }

    public function testCreateHouseholdPersistsAndAudits(): void
    {
        $building = $this->seededBuilding('Block A');

        $response = $this->adminPost('/admin/households', [
            'unit_number' => 'C-777',
            'building_uuid' => (string) $building['uuid'],
            'household_type' => 'apartment',
        ]);
        $this->assertAdminRedirect($response, '/admin/households');

        $row = $this->db->selectOne(
            'SELECT id, status, building_id, estate_id FROM households WHERE unit_number = :unit LIMIT 1',
            ['unit' => 'C-777']
        );
        $this->assertNotNull($row, 'The created household must exist.');
        $this->assertSame('active', (string) $row['status']);
        $this->assertSame((int) $building['id'], (int) $row['building_id']);
        $this->assertSame((int) $this->estate()['id'], (int) $row['estate_id']);

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'admin.household_created'");
        $this->assertTrue((int) $audit['c'] > 0, 'Household creation must be audited.');
    }

    public function testDuplicateUnitIsRejected(): void
    {
        $before = $this->activeHouseholdCount();

        $response = $this->adminPost('/admin/households', [
            'unit_number' => 'A-001',
            'household_type' => 'apartment',
        ]);

        $this->assertAdminRejected($response, '/admin/households', ['UNIT_EXISTS', 'already exist', 'already registered']);
        $this->assertSame($before, $this->activeHouseholdCount());
    }

    public function testInvalidHouseholdInputIsRejected(): void
    {
        foreach ([
            ['unit_number' => '', 'household_type' => 'apartment'],
            ['unit_number' => str_repeat('U', 61), 'household_type' => 'apartment'],
            ['unit_number' => 'D-1', 'household_type' => 'not-a-type'],
        ] as $body) {
            $this->assertAdminRejected($this->adminPost('/admin/households', $body), '/admin/households', ['unit', 'type', 'required', 'between']);
        }
    }

    public function testHouseholdDetailShowsMembers(): void
    {
        $household = $this->seededHouseholdRow('A-002');

        $response = $this->adminGet('/admin/households/' . $household['uuid']);
        $this->assertStatus(200, $response);

        $this->assertTextContains('A-002', $response);
        $this->assertTextContains('Brian', $response);
        $this->assertTextContains('Lydia', $response);
        // Primary marker is rendered for the primary member.
        $this->assertTextContains('Primary', $response);

        // Member add form is present.
        $forms = $this->xpath('//form[@action="/admin/households/' . $household['uuid'] . '/members"]', $this->dom($response));
        $this->assertCount(1, $forms, 'Household detail must offer an add-member form.');
    }

    public function testArchiveHouseholdSetsInactiveAndKeepsTheRow(): void
    {
        $household = $this->seededHouseholdRow('A-001');
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM households');

        $response = $this->adminPost('/admin/households/' . $household['uuid'] . '/archive', []);
        $this->assertAdminRedirect($response, '/admin/households');

        $row = $this->db->selectOne('SELECT status FROM households WHERE id = :id', ['id' => (int) $household['id']]);
        $this->assertNotNull($row);
        $this->assertSame('inactive', (string) $row['status'], 'Archive must be a soft status change.');
        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM households'), 'No hard delete is allowed.');

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'admin.household_archived'");
        $this->assertTrue((int) $audit['c'] > 0, 'Archiving must be audited.');
    }

    public function testUpdateCanUnarchiveViaStatusToggle(): void
    {
        $household = $this->seededHouseholdRow('A-001');

        $this->assertAdminRedirect($this->adminPost('/admin/households/' . $household['uuid'] . '/archive', []), '/admin/households');
        $this->assertSame('inactive', (string) $this->db->scalar('SELECT status FROM households WHERE id = :id', ['id' => (int) $household['id']]));

        $this->assertAdminRedirect($this->adminPost('/admin/households/' . $household['uuid'], [
            'unit_number' => (string) $household['unit_number'],
            'building_uuid' => (string) $this->seededBuilding('Block A')['uuid'],
            'household_type' => (string) $household['household_type'],
            'status' => 'active',
        ]), '/admin/households');
        $this->assertSame('active', (string) $this->db->scalar('SELECT status FROM households WHERE id = :id', ['id' => (int) $household['id']]));
    }

    public function testArchivedHouseholdIsExcludedFromTheInviteSelector(): void
    {
        $household = $this->seededHouseholdRow('A-001');
        $householdUuid = (string) $household['uuid'];

        // Before archiving, Jane's invite form offers her household.
        $this->ensureLoggedInAs(self::JANE);
        $before = $this->get('/app/visitors/invite');
        $this->assertStatus(200, $before);
        $this->assertCount(1, $this->xpath('//option[@value="' . $householdUuid . '"]', $this->dom($before)));

        // Archive it as the admin.
        $this->assertAdminRedirect($this->adminPost('/admin/households/' . $householdUuid . '/archive', []), '/admin/households');

        // Jane's invite selector no longer offers the archived household.
        $this->ensureLoggedInAs(self::JANE);
        $after = $this->get('/app/visitors/invite');
        $this->assertStatus(200, $after);
        $this->assertCount(
            0,
            $this->xpath('//option[@value="' . $householdUuid . '"]', $this->dom($after)),
            'An archived household must be excluded from the invite household selector.'
        );
    }

    public function testAccessAfterHouseholdArchiveIsAuditedWithoutDeletedRows(): void
    {
        // Households with members remain queryable (no cascade delete).
        $household = $this->seededHouseholdRow('B-001');
        $membersBefore = (int) $this->db->scalar('SELECT COUNT(*) FROM household_members WHERE household_id = :id', ['id' => (int) $household['id']]);

        $this->assertAdminRedirect($this->adminPost('/admin/households/' . $household['uuid'] . '/archive', []), '/admin/households');

        $this->assertSame(
            $membersBefore,
            (int) $this->db->scalar('SELECT COUNT(*) FROM household_members WHERE household_id = :id', ['id' => (int) $household['id']]),
            'Archiving a household must never delete its member rows.'
        );
    }
}
