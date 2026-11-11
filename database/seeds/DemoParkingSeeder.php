<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Clock;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Seeds the Vehicles & Parking demo fixtures (vehicles-parking-contract §1).
 *
 * Greenview Residences gets two zones, fourteen bays (eight resident, six
 * visitor with one accessible and one under maintenance), three household
 * vehicles with active resident allocations, one reserved visitor bay for the
 * seeded upcoming invitation and one completed visitor parking session on the
 * history invitation. Rows are matched by the stable UUID constants below, so
 * `php scripts/install.php` can run repeatedly without duplicating anything.
 *
 * Time anchors are derived from the seeded visitor fixtures
 * (DemoVisitorSeeder) rather than from "now", so a second seed run makes no
 * changes: the reservation window follows the refreshed upcoming invitation and
 * the completed session follows the fixed history invitation.
 */
final class DemoParkingSeeder extends Seeder
{
    public const ESTATE_SLUG = 'greenview-residences';

    public const ZONE_RESIDENT_UUID = '2a000001-0000-4000-8000-000000000001';
    public const ZONE_VISITOR_UUID = '2a000001-0000-4000-8000-000000000002';

    public const PLATE_JANE = 'KDA123A';
    public const PLATE_BRIAN = 'KCA456B';
    public const PLATE_AMINA = 'KDD789C';

    public const VEHICLE_JANE_UUID = '2c000001-0000-4000-8000-000000000001';
    public const VEHICLE_BRIAN_UUID = '2c000001-0000-4000-8000-000000000002';
    public const VEHICLE_AMINA_UUID = '2c000001-0000-4000-8000-000000000003';

    public const ALLOCATION_JANE_UUID = '2d000001-0000-4000-8000-000000000001';
    public const ALLOCATION_BRIAN_UUID = '2d000001-0000-4000-8000-000000000002';
    public const ALLOCATION_AMINA_UUID = '2d000001-0000-4000-8000-000000000003';

    /** Reserved visitor bay for "Demo Visitor Three" (upcoming invitation). */
    public const RESERVATION_UPCOMING_UUID = '2e000001-0000-4000-8000-000000000001';

    /** Completed visitor session for the first history invitation. */
    public const SESSION_HISTORY_UUID = '2f000001-0000-4000-8000-000000000001';

    public const BAY_RESERVED_CODE = 'V-01';
    public const BAY_HISTORY_SESSION_CODE = 'V-02';
    public const BAY_ACCESSIBLE_CODE = 'V-05';
    public const BAY_MAINTENANCE_CODE = 'V-06';

    /**
     * Bay code => stable public id.
     *
     * @var array<string, string>
     */
    public const BAY_UUIDS = [
        'R-01' => '2b000001-0000-4000-8000-000000000001',
        'R-02' => '2b000001-0000-4000-8000-000000000002',
        'R-03' => '2b000001-0000-4000-8000-000000000003',
        'R-04' => '2b000001-0000-4000-8000-000000000004',
        'R-05' => '2b000001-0000-4000-8000-000000000005',
        'R-06' => '2b000001-0000-4000-8000-000000000006',
        'R-07' => '2b000001-0000-4000-8000-000000000007',
        'R-08' => '2b000001-0000-4000-8000-000000000008',
        'V-01' => '2b000001-0000-4000-8000-000000000009',
        'V-02' => '2b000001-0000-4000-8000-00000000000a',
        'V-03' => '2b000001-0000-4000-8000-00000000000b',
        'V-04' => '2b000001-0000-4000-8000-00000000000c',
        'V-05' => '2b000001-0000-4000-8000-00000000000d',
        'V-06' => '2b000001-0000-4000-8000-00000000000e',
    ];

    /** @var array<int, string> */
    public const STAT_KEYS = [
        'zones_created',
        'bays_created',
        'vehicles_created',
        'allocations_created',
        'reservations_created',
        'sessions_created',
        'fixtures_updated',
    ];

    /**
     * Bay inventory. Resident Zone A has eight resident bays; Visitor Zone V has
     * six visitor bays (V-05 accessible, V-06 under maintenance).
     *
     * @var array<int, array{code: string, zone: string, bay_type: string, status: string}>
     */
    private const BAYS = [
        ['code' => 'R-01', 'zone' => 'resident', 'bay_type' => 'resident', 'status' => 'available'],
        ['code' => 'R-02', 'zone' => 'resident', 'bay_type' => 'resident', 'status' => 'available'],
        ['code' => 'R-03', 'zone' => 'resident', 'bay_type' => 'resident', 'status' => 'available'],
        ['code' => 'R-04', 'zone' => 'resident', 'bay_type' => 'resident', 'status' => 'available'],
        ['code' => 'R-05', 'zone' => 'resident', 'bay_type' => 'resident', 'status' => 'available'],
        ['code' => 'R-06', 'zone' => 'resident', 'bay_type' => 'resident', 'status' => 'available'],
        ['code' => 'R-07', 'zone' => 'resident', 'bay_type' => 'resident', 'status' => 'available'],
        ['code' => 'R-08', 'zone' => 'resident', 'bay_type' => 'resident', 'status' => 'available'],
        ['code' => 'V-01', 'zone' => 'visitor', 'bay_type' => 'visitor', 'status' => 'available'],
        ['code' => 'V-02', 'zone' => 'visitor', 'bay_type' => 'visitor', 'status' => 'available'],
        ['code' => 'V-03', 'zone' => 'visitor', 'bay_type' => 'visitor', 'status' => 'available'],
        ['code' => 'V-04', 'zone' => 'visitor', 'bay_type' => 'visitor', 'status' => 'available'],
        ['code' => 'V-05', 'zone' => 'visitor', 'bay_type' => 'accessible', 'status' => 'available'],
        ['code' => 'V-06', 'zone' => 'visitor', 'bay_type' => 'visitor', 'status' => 'maintenance'],
    ];

    /**
     * Demo vehicles (plate is normalised: uppercase, no separators).
     *
     * @var array<int, array{uuid: string, plate: string, household: string, type: string, colour: string}>
     */
    private const VEHICLES = [
        ['uuid' => self::VEHICLE_JANE_UUID, 'plate' => self::PLATE_JANE, 'household' => 'A-001', 'type' => 'car', 'colour' => 'White'],
        ['uuid' => self::VEHICLE_BRIAN_UUID, 'plate' => self::PLATE_BRIAN, 'household' => 'A-002', 'type' => 'van', 'colour' => 'Grey'],
        ['uuid' => self::VEHICLE_AMINA_UUID, 'plate' => self::PLATE_AMINA, 'household' => 'B-001', 'type' => 'car', 'colour' => 'Black'],
    ];

    /**
     * Active resident allocations, one per demo household.
     *
     * @var array<int, array{uuid: string, bay: string, household: string, vehicle: string}>
     */
    private const ALLOCATIONS = [
        ['uuid' => self::ALLOCATION_JANE_UUID, 'bay' => 'R-01', 'household' => 'A-001', 'vehicle' => self::VEHICLE_JANE_UUID],
        ['uuid' => self::ALLOCATION_BRIAN_UUID, 'bay' => 'R-02', 'household' => 'A-002', 'vehicle' => self::VEHICLE_BRIAN_UUID],
        ['uuid' => self::ALLOCATION_AMINA_UUID, 'bay' => 'R-03', 'household' => 'B-001', 'vehicle' => self::VEHICLE_AMINA_UUID],
    ];

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $this->resetCounters(self::STAT_KEYS);

        $estate = $this->db->selectOne(
            'SELECT id, timezone FROM estates WHERE slug = :slug LIMIT 1',
            ['slug' => self::ESTATE_SLUG]
        );

        if ($estate === null) {
            throw new RuntimeException(
                'Parking demo fixtures need the "' . self::ESTATE_SLUG . '" estate. Run the foundation demo seeder first.'
            );
        }

        $estateId = (int) $estate['id'];
        $timezone = new DateTimeZone((string) ($estate['timezone'] ?? 'Africa/Nairobi'));
        $now = new DateTimeImmutable('now', $timezone);

        $households = [
            'A-001' => $this->requireId('households', ['estate_id' => $estateId, 'unit_number' => 'A-001'], 'household A-001'),
            'A-002' => $this->requireId('households', ['estate_id' => $estateId, 'unit_number' => 'A-002'], 'household A-002'),
            'B-001' => $this->requireId('households', ['estate_id' => $estateId, 'unit_number' => 'B-001'], 'household B-001'),
        ];

        $zoneIds = [
            'resident' => $this->ensureZone(self::ZONE_RESIDENT_UUID, $estateId, 'Resident Zone A', 'resident'),
            'visitor' => $this->ensureZone(self::ZONE_VISITOR_UUID, $estateId, 'Visitor Zone V', 'visitor'),
        ];

        $bayIds = [];

        foreach (self::BAYS as $bay) {
            $bayIds[$bay['code']] = $this->ensureBay(
                self::BAY_UUIDS[$bay['code']],
                $estateId,
                $zoneIds[$bay['zone']],
                $bay['code'],
                $bay['bay_type'],
                $bay['status']
            );
        }

        $vehicleIds = [];

        foreach (self::VEHICLES as $vehicle) {
            $vehicleIds[$vehicle['uuid']] = $this->ensureVehicle($vehicle, $estateId, $households[$vehicle['household']]);
        }

        $startsAt = Clock::toDb($now->modify('-30 days')->setTime(0, 0, 0));

        foreach (self::ALLOCATIONS as $allocation) {
            $this->ensureAllocation(
                $allocation,
                $estateId,
                $bayIds[$allocation['bay']],
                $households[$allocation['household']],
                $vehicleIds[$allocation['vehicle']],
                $startsAt
            );
        }

        $this->ensureUpcomingReservation($estateId, $bayIds);
        $this->ensureHistorySession($estateId, $bayIds);

        // Physical bay state is derived, never reserved: a successful check-in
        // sets occupied and checkout frees the bay. Re-seeding restores the demo
        // bays so screenshots and acceptance always start from the same state.
        $this->updateIfChanged('parking_bays', $bayIds[self::BAY_RESERVED_CODE], ['status' => 'available']);
        $this->updateIfChanged('parking_bays', $bayIds[self::BAY_HISTORY_SESSION_CODE], ['status' => 'available']);

        return $this->counters();
    }

    private function ensureZone(string $uuid, int $estateId, string $name, string $zoneType): int
    {
        $zoneId = $this->findId('parking_zones', ['uuid' => $uuid]);

        if ($zoneId !== null) {
            return $zoneId;
        }

        $natural = $this->findId('parking_zones', ['estate_id' => $estateId, 'name' => $name]);

        if ($natural !== null) {
            return $natural;
        }

        $zoneId = $this->insert('parking_zones', [
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'name' => $name,
            'zone_type' => $zoneType,
        ]);
        $this->count('zones_created');

        return $zoneId;
    }

    private function ensureBay(
        string $uuid,
        int $estateId,
        int $zoneId,
        string $bayCode,
        string $bayType,
        string $status
    ): int {
        $bayId = $this->findId('parking_bays', ['uuid' => $uuid]);

        if ($bayId !== null) {
            return $bayId;
        }

        $natural = $this->findId('parking_bays', ['estate_id' => $estateId, 'bay_code' => $bayCode]);

        if ($natural !== null) {
            return $natural;
        }

        $bayId = $this->insert('parking_bays', [
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'zone_id' => $zoneId,
            'bay_code' => $bayCode,
            'bay_type' => $bayType,
            'status' => $status,
        ]);
        $this->count('bays_created');

        return $bayId;
    }

    /**
     * @param array{uuid: string, plate: string, household: string, type: string, colour: string} $vehicle
     */
    private function ensureVehicle(array $vehicle, int $estateId, int $householdId): int
    {
        $vehicleId = $this->findId('vehicles', ['uuid' => $vehicle['uuid']]);

        if ($vehicleId !== null) {
            return $vehicleId;
        }

        $natural = $this->findId('vehicles', ['estate_id' => $estateId, 'plate_number' => $vehicle['plate']]);

        if ($natural !== null) {
            return $natural;
        }

        $vehicleId = $this->insert('vehicles', [
            'uuid' => $vehicle['uuid'],
            'estate_id' => $estateId,
            'household_id' => $householdId,
            'plate_number' => $vehicle['plate'],
            'vehicle_type' => $vehicle['type'],
            'colour' => $vehicle['colour'],
            'status' => 'active',
        ]);
        $this->count('vehicles_created');

        return $vehicleId;
    }

    /**
     * @param array{uuid: string, bay: string, household: string, vehicle: string} $allocation
     */
    private function ensureAllocation(
        array $allocation,
        int $estateId,
        int $bayId,
        int $householdId,
        int $vehicleId,
        string $startsAt
    ): int {
        $allocationId = $this->findId('parking_allocations', ['uuid' => $allocation['uuid']]);

        if ($allocationId !== null) {
            return $allocationId;
        }

        $allocationId = $this->insert('parking_allocations', [
            'uuid' => $allocation['uuid'],
            'estate_id' => $estateId,
            'bay_id' => $bayId,
            'household_id' => $householdId,
            'vehicle_id' => $vehicleId,
            'starts_at' => $startsAt,
            'ends_at' => null,
            'status' => 'active',
        ]);
        $this->count('allocations_created');

        return $allocationId;
    }

    /**
     * @param array<string, int> $bayIds
     */
    private function ensureUpcomingReservation(int $estateId, array $bayIds): void
    {
        $invitation = $this->db->selectOne(
            'SELECT id, valid_from, valid_until FROM visitor_invitations WHERE uuid = :uuid LIMIT 1',
            ['uuid' => DemoVisitorSeeder::INVITATION_UPCOMING_UUID]
        );

        if ($invitation === null) {
            throw new RuntimeException('Parking demo reservation needs the seeded upcoming visitor invitation.');
        }

        $expected = [
            'estate_id' => $estateId,
            'invitation_id' => (int) $invitation['id'],
            'bay_id' => $bayIds[self::BAY_RESERVED_CODE],
            'vehicle_id' => null,
            'plate_number' => null,
            'starts_at' => (string) $invitation['valid_from'],
            'ends_at' => (string) $invitation['valid_until'],
            'status' => 'reserved',
        ];

        $reservationId = $this->findId('parking_reservations', ['uuid' => self::RESERVATION_UPCOMING_UUID]);

        if ($reservationId === null) {
            $this->insert('parking_reservations', ['uuid' => self::RESERVATION_UPCOMING_UUID] + $expected);
            $this->count('reservations_created');

            return;
        }

        $this->updateIfChanged('parking_reservations', $reservationId, $expected);
    }

    /**
     * @param array<string, int> $bayIds
     */
    private function ensureHistorySession(int $estateId, array $bayIds): void
    {
        $invitation = $this->db->selectOne(
            'SELECT id, valid_from, valid_until FROM visitor_invitations WHERE uuid = :uuid LIMIT 1',
            ['uuid' => DemoVisitorSeeder::INVITATION_HISTORY_ONE_UUID]
        );

        $checkinAt = null;
        $checkoutAt = null;

        if ($invitation !== null) {
            $validFrom = Clock::fromDb((string) $invitation['valid_from']);
            $validUntil = Clock::fromDb((string) $invitation['valid_until']);

            if ($validFrom !== null && $validUntil !== null) {
                $checkinAt = Clock::toDb($validFrom->modify('+20 minutes'));
                $checkoutAt = Clock::toDb($validUntil->modify('-55 minutes'));
            }
        }

        if ($checkinAt === null || $checkoutAt === null) {
            throw new RuntimeException('Parking demo session needs the seeded history visitor invitation.');
        }

        $expected = [
            'estate_id' => $estateId,
            'bay_id' => $bayIds[self::BAY_HISTORY_SESSION_CODE],
            'vehicle_id' => null,
            'invitation_id' => $invitation === null ? null : (int) $invitation['id'],
            'checkin_at' => $checkinAt,
            'checkout_at' => $checkoutAt,
            'status' => 'completed',
        ];

        $sessionId = $this->findId('parking_sessions', ['uuid' => self::SESSION_HISTORY_UUID]);

        if ($sessionId === null) {
            $this->insert('parking_sessions', ['uuid' => self::SESSION_HISTORY_UUID] + $expected + ['created_at' => $checkinAt]);
            $this->count('sessions_created');

            return;
        }

        $this->updateIfChanged('parking_sessions', $sessionId, $expected);
    }

    /**
     * Update a row from expected values, counting one refresh only when
     * something actually changed. Keeps repeated seed runs a no-op.
     *
     * @param array<string, mixed> $expected
     */
    private function updateIfChanged(string $table, int $id, array $expected): void
    {
        if ($id <= 0) {
            return;
        }

        $current = $this->db->selectOne('SELECT * FROM ' . $table . ' WHERE id = :id LIMIT 1', ['id' => $id]);

        if ($current === null) {
            return;
        }

        $changes = [];

        foreach ($expected as $column => $value) {
            if ((string) ($current[$column] ?? '') !== (string) ($value ?? '')) {
                $changes[$column] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        $this->update($table, $changes, ['id' => $id]);
        $this->count('fixtures_updated');
    }

    /**
     * @param array<string, mixed> $where
     */
    private function requireId(string $table, array $where, string $description): int
    {
        $id = $this->findId($table, $where);

        if ($id === null) {
            throw new RuntimeException(
                'Parking demo fixtures need ' . $description . '. Run the foundation demo seeder first.'
            );
        }

        return $id;
    }
}
