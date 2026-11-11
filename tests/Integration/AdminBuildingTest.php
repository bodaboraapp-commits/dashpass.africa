<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Admin buildings (estate-admin-management-contract §2 "Buildings", §5):
 * list/create/edit with uniqueness, tenant isolation and the documented
 * no-archive limitation.
 */
final class AdminBuildingTest extends AdminModuleTestCase
{
    public function testBuildingListShowsEstateBuildings(): void
    {
        $response = $this->adminGet('/admin/buildings');
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'Buildings');

        foreach (['Block A', 'Block B', 'Willow Court', 'Acacia Street'] as $name) {
            $this->assertTextContains($name, $response, 'Building ' . $name . ' must be listed.');
        }

        // Add form (plain POST) with the allowed building types.
        $forms = $this->xpath('//form[@action="/admin/buildings"]', $this->dom($response));
        $this->assertCount(1, $forms, 'The list must offer an add-building form.');
        $form = $forms->item(0);
        $this->assertNotNull($form);
        $this->assertCount(1, $this->xpath('.//input[@name="_token"]', $form));
        $this->assertCount(1, $this->xpath('.//input[@name="name"]', $form));
        $this->assertCount(1, $this->xpath('.//*[@name="building_type"]', $form));

        $typeOptions = $this->xpath('.//select[@name="building_type"]//option', $form);
        $this->assertTrue($typeOptions->length >= 4, 'All building types must be offered.');
    }

    public function testCreateBuildingPersistsAndAudits(): void
    {
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM buildings');

        $response = $this->adminPost('/admin/buildings', [
            'name' => 'Admin Test Block',
            'building_type' => 'cluster',
        ]);
        $this->assertAdminRedirect($response, '/admin/buildings');

        $row = $this->db->selectOne(
            'SELECT name, building_type, estate_id FROM buildings WHERE name = :name LIMIT 1',
            ['name' => 'Admin Test Block']
        );
        $this->assertNotNull($row, 'The created building must exist.');
        $this->assertSame('cluster', (string) $row['building_type']);
        $this->assertSame((int) $this->estate()['id'], (int) $row['estate_id']);
        $this->assertSame($before + 1, (int) $this->db->scalar('SELECT COUNT(*) FROM buildings'));

        $audit = $this->db->selectOne("SELECT action, metadata FROM audit_logs WHERE action = 'admin.building_created' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit, 'Building creation must be audited as admin.building_created.');
    }

    public function testDuplicateBuildingNameIsRejected(): void
    {
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM buildings');

        $response = $this->adminPost('/admin/buildings', [
            'name' => 'Block A',
            'building_type' => 'apartment_block',
        ]);

        $this->assertAdminRejected($response, '/admin/buildings', ['BUILDING_EXISTS', 'already exist', 'already registered']);
        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM buildings'));
    }

    public function testInvalidBuildingInputIsRejected(): void
    {
        foreach ([
            ['name' => 'X', 'building_type' => 'apartment_block'],
            ['name' => str_repeat('A', 121), 'building_type' => 'apartment_block'],
            ['name' => 'Valid Name', 'building_type' => 'not-a-type'],
            ['name' => '', 'building_type' => 'apartment_block'],
        ] as $body) {
            $response = $this->adminPost('/admin/buildings', $body);
            $this->assertAdminRejected($response, '/admin/buildings', ['name', 'building_type', 'required', 'invalid', 'between']);
        }
    }

    public function testEditBuildingNameAndType(): void
    {
        $building = $this->seededBuilding('Block B');

        $response = $this->adminPost('/admin/buildings/' . $building['uuid'], [
            'name' => 'Block B Renamed',
            'building_type' => 'cluster',
        ]);
        $this->assertAdminRedirect($response, '/admin/buildings');

        $row = $this->db->selectOne('SELECT name, building_type FROM buildings WHERE id = :id', ['id' => (int) $building['id']]);
        $this->assertNotNull($row);
        $this->assertSame('Block B Renamed', (string) $row['name']);
        $this->assertSame('cluster', (string) $row['building_type']);

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'admin.building_updated'");
        $this->assertTrue((int) $audit['c'] > 0, 'Building update must be audited.');

        // Renaming onto another existing name must be rejected.
        $other = $this->seededBuilding('Willow Court');
        $conflict = $this->adminPost('/admin/buildings/' . $building['uuid'], [
            'name' => (string) $other['name'],
            'building_type' => 'cluster',
        ]);
        $this->assertAdminRejected($conflict, '/admin/buildings/' . $building['uuid'], ['BUILDING_EXISTS', 'already exist']);
    }

    public function testBuildingDetailShowsHouseholds(): void
    {
        $building = $this->seededBuilding('Block A');
        $response = $this->adminGet('/admin/buildings/' . $building['uuid']);
        $this->assertStatus(200, $response);

        $this->assertTextContains('Block A', $response);
        // Household counts/units from real data.
        $this->assertTextContains('A-001', $response);
    }

    public function testBuildingsAreEstateScoped(): void
    {
        $riverside = $this->seededEstate('riverside-court');
        $riversideBuilding = $this->db->selectOne(
            'SELECT uuid, name FROM buildings WHERE estate_id = :id LIMIT 1',
            ['id' => (int) $riverside['id']]
        );
        $this->assertNotNull($riversideBuilding);

        // Riverside admin sees only Riverside buildings…
        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);
        $riversidePage = $this->get('/admin/buildings');
        $this->assertStatus(200, $riversidePage);
        $this->assertTextContains('Tower 1', $riversidePage);
        $this->assertTextNotContains('Block A', $riversidePage);

        // …and a Greenview building uuid is a 404 for them (no leak).
        $greenview = $this->seededBuilding('Block A');
        $foreign = $this->get('/admin/buildings/' . $greenview['uuid']);
        $this->assertStatus(404, $foreign);
        $this->assertFalse(str_contains($foreign->body(), 'Willow Court'), 'Foreign-estate 404 must not leak estate data.');

        // Greenview admin cannot touch a Riverside uuid either.
        $this->ensureLoggedInAs(self::GRACE);
        $this->assertStatus(404, $this->get('/admin/buildings/' . $riversideBuilding['uuid']));
    }

    public function testBuildingsHaveNoArchiveRoute(): void
    {
        $building = $this->seededBuilding('Block A');

        // Documented limitation: buildings have no status column, no archive route.
        $this->assertStatus(404, $this->adminPost('/admin/buildings/' . $building['uuid'] . '/archive', []));

        $row = $this->db->selectOne('SELECT name FROM buildings WHERE id = :id', ['id' => (int) $building['id']]);
        $this->assertNotNull($row);
        $this->assertSame('Block A', (string) $row['name']);
    }
}
