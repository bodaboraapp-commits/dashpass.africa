<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Vehicle register (contract §2 "Vehicles", §5): household-scoped CRUD, plate
 * normalization, duplicate protection, cross-tenant isolation, permissions and
 * audit trail.
 */
final class VehicleModuleTest extends ParkingModuleTestCase
{
    public function testResidentListsOnlyOwnHouseholdVehicles(): void
    {
        $data = $this->vehicleListApi(self::JANE);
        $plates = array_map(static fn (array $row): string => (string) $row['plate_number'], $data['vehicles']);

        $this->assertContains(self::PLATE_JANE, $plates, 'Jane must see her seeded household vehicle.');
        $this->assertNotContains(self::PLATE_BRIAN, $plates, 'Brian\'s vehicle must not be visible to Jane.');
        $this->assertNotContains(self::PLATE_AMINA, $plates, 'Amina\'s vehicle must not be visible to Jane.');
    }

    public function testHouseholdScopeFollowsTheAuthenticatedUser(): void
    {
        $brian = array_map(
            static fn (array $row): string => (string) $row['plate_number'],
            $this->vehicleListApi(self::BRIAN)['vehicles']
        );

        $this->assertContains(self::PLATE_BRIAN, $brian);
        $this->assertNotContains(self::PLATE_JANE, $brian);
    }

    public function testVehicleItemShape(): void
    {
        $vehicle = $this->vehicleListApi(self::JANE)['vehicles'][0] ?? null;
        $this->assertNotNull($vehicle, 'Jane must have at least one vehicle.');

        foreach (['uuid', 'plate_number', 'vehicle_type', 'colour', 'status'] as $key) {
            $this->assertArrayHasKey($key, $vehicle, 'Vehicle item must expose ' . $key . '.');
        }

        $this->assertSame(self::PLATE_JANE, (string) $vehicle['plate_number']);
        $this->assertSame('active', (string) $vehicle['status']);
        $this->assertMatches('/^[0-9a-f-]{36}$/', (string) $vehicle['uuid']);
    }

    public function testCreateVehicleNormalizesThePlate(): void
    {
        $created = $this->vehicleCreateApi([
            'plate_number' => ' kd 999 z ',
            'vehicle_type' => 'van',
            'colour' => 'Silver',
        ]);

        $this->assertSame('KD999Z', (string) $created['plate_number'], 'Plates must be uppercased with separators removed.');
        $this->assertSame('van', (string) $created['vehicle_type']);
        $this->assertSame('active', (string) $created['status']);

        $row = $this->db->selectOne(
            'SELECT plate_number, vehicle_type, colour, household_id FROM vehicles WHERE uuid = :uuid',
            ['uuid' => (string) $created['uuid']]
        );
        $this->assertNotNull($row);
        $this->assertSame('KD999Z', (string) $row['plate_number']);
        $this->assertSame(
            (int) $this->db->scalar(
                'SELECT id FROM households WHERE uuid = :uuid',
                ['uuid' => $this->householdUuid('A-001')]
            ),
            (int) $row['household_id'],
            'The vehicle must be created for the authenticated user\'s household.'
        );
    }

    public function testCreateVehicleDefaultsToActiveCar(): void
    {
        $created = $this->vehicleCreateApi(['plate_number' => 'KDX001']);

        $this->assertSame('car', (string) $created['vehicle_type']);
        $this->assertSame('active', (string) $created['status']);
    }

    public function testDuplicatePlateIsRejectedPerEstate(): void
    {
        // Different formatting of the same seeded plate normalizes to a duplicate.
        $this->assertErrorCode(
            'PLATE_ALREADY_REGISTERED',
            $this->post('/api/v1/resident/vehicles', [
                'plate_number' => 'kda-123a',
                'vehicle_type' => 'car',
            ], true),
            409
        );

        // Creating it twice is also a conflict on the second attempt.
        $this->assertSuccess($this->vehicleCreateApi(['plate_number' => 'KDV777']));
        $this->assertErrorCode(
            'PLATE_ALREADY_REGISTERED',
            $this->post('/api/v1/resident/vehicles', ['plate_number' => 'KDV 777'], true),
            409
        );
    }

    public function testInvalidPlatesAreRejected(): void
    {
        foreach (['AB1', 'A', 'ABCDEFGHIJKLM', '!!!', ''] as $plate) {
            $response = $this->post('/api/v1/resident/vehicles', ['plate_number' => $plate], true);

            $this->assertErrorCode('VALIDATION_FAILED', $response, 422, 'Plate "' . $plate . '" must be rejected.');
            $this->assertArrayHasKey('plate_number', $this->decodeErrors($response));
        }
    }

    public function testCreateWithForeignHouseholdIsRejected(): void
    {
        // Jane may not register a vehicle in Brian's household (A-002).
        $response = $this->post('/api/v1/resident/vehicles', [
            'plate_number' => 'KDY111',
            'household_uuid' => $this->householdUuid('A-002'),
        ], true);

        $this->assertErrorCode('HOUSEHOLD_NOT_FOUND', $response, 404);
        $this->assertSame(
            0,
            (int) $this->db->scalar("SELECT COUNT(*) FROM vehicles WHERE plate_number = 'KDY111'"),
            'A rejected cross-household create must not persist a vehicle.'
        );
    }

    public function testDeactivateOwnVehicle(): void
    {
        $created = $this->vehicleCreateApi(['plate_number' => 'KDZ321']);

        $this->assertSuccess($this->vehicleDeactivateApi((string) $created['uuid']));

        $row = $this->db->selectOne('SELECT status FROM vehicles WHERE uuid = :uuid', ['uuid' => (string) $created['uuid']]);
        $this->assertNotNull($row);
        $this->assertSame('inactive', (string) $row['status']);

        $plates = array_map(
            static fn (array $row): string => (string) $row['plate_number'],
            $this->vehicleListApi(self::JANE)['vehicles']
        );
        $this->assertNotContains('KDZ321', $plates, 'Inactive vehicles must not appear in the active vehicle list.');

        $audit = $this->db->selectOne(
            "SELECT action FROM audit_logs WHERE action = 'vehicle.deactivated' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit, 'Deactivation must be audited with action vehicle.deactivated.');
    }

    public function testDeactivateForeignVehicleIsNotFound(): void
    {
        $brian = $this->seededVehicle(self::PLATE_BRIAN);

        $this->assertErrorCode('VEHICLE_NOT_FOUND', $this->vehicleDeactivateApi((string) $brian['uuid']), 404);
        $this->assertSame('active', (string) $this->db->scalar(
            'SELECT status FROM vehicles WHERE id = :id',
            ['id' => (int) $brian['id']]
        ));
    }

    public function testVehicleCreateIsAudited(): void
    {
        $created = $this->vehicleCreateApi(['plate_number' => 'KDA555', 'vehicle_type' => 'motorcycle']);

        $audit = $this->db->selectOne(
            "SELECT entity_type, entity_id, metadata FROM audit_logs WHERE action = 'vehicle.created' ORDER BY id DESC LIMIT 1"
        );

        $this->assertNotNull($audit, 'Vehicle creation must be audited.');
        $this->assertSame('vehicle', (string) $audit['entity_type']);
        $this->assertSame((int) $this->db->scalar('SELECT id FROM vehicles WHERE uuid = :uuid', ['uuid' => (string) $created['uuid']]), (int) $audit['entity_id']);
        $this->assertContains('KDA555', (string) $audit['metadata']);
    }

    public function testGuardCannotManageVehicles(): void
    {
        $this->loginGuard();

        $this->assertStatus(403, $this->get('/app/vehicles'));
        $this->assertStatus(403, $this->get('/api/v1/resident/vehicles'));
        $this->assertStatus(403, $this->post('/api/v1/resident/vehicles', ['plate_number' => 'KDG001'], true));
    }

    public function testAdminCanListAllEstateVehicles(): void
    {
        $this->ensureLoggedInAs(self::GRACE);

        $page = $this->get('/app/vehicles');
        $this->assertStatus(200, $page);

        foreach ([self::PLATE_JANE, self::PLATE_BRIAN, self::PLATE_AMINA] as $plate) {
            $this->assertBodyContains($plate, $page, 'An estate admin must see ' . $plate . ' in the estate vehicle register.');
        }
    }

    public function testCrossTenantCannotReadOrDeactivateGreenviewVehicles(): void
    {
        $janeVehicle = $this->seededVehicle(self::PLATE_JANE);
        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);

        $list = $this->get('/api/v1/resident/vehicles');
        $this->assertTrue(in_array($list->status(), [200, 403], true), 'Cross-tenant vehicle list must be empty or denied.');
        $this->assertFalse(str_contains($list->body(), self::PLATE_JANE), 'Riverside must never see a Greenview plate.');

        $deactivate = $this->post('/api/v1/resident/vehicles/' . $janeVehicle['uuid'] . '/deactivate', [], true);
        $this->assertTrue(in_array($deactivate->status(), [403, 404], true), 'Cross-tenant deactivate must be denied.');
        $this->assertSame('active', (string) $this->db->scalar(
            'SELECT status FROM vehicles WHERE id = :id',
            ['id' => (int) $janeVehicle['id']]
        ));
    }

    public function testWebVehicleFormCreatesAndDeactivates(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $this->assertStatus(200, $this->get('/app/vehicles'));

        $create = $this->post('/app/vehicles', [
            'plate_number' => 'KDB 808 X',
            'vehicle_type' => 'truck',
            'colour' => 'Blue',
        ], true);
        $this->assertRedirect($create, '/app/vehicles');

        $row = $this->db->selectOne("SELECT uuid, plate_number, status FROM vehicles WHERE plate_number = 'KDB808X'");
        $this->assertNotNull($row, 'The web add-vehicle form must persist the normalized plate.');

        $deactivate = $this->post('/app/vehicles/' . $row['uuid'] . '/deactivate', [], true);
        $this->assertRedirect($deactivate, '/app/vehicles');
        $this->assertSame('inactive', (string) $this->db->scalar(
            'SELECT status FROM vehicles WHERE uuid = :uuid',
            ['uuid' => (string) $row['uuid']]
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeErrors(\App\Core\Response $response): array
    {
        $payload = json_decode((string) $response->body(), true);

        return is_array($payload['error']['errors'] ?? null) ? $payload['error']['errors'] : [];
    }
}
