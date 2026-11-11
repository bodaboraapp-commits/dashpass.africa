<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Core\Uuid;

/**
 * Increment 8 — parking administration (docs/parking-administration-contract.md
 * §1–§2, §4, §6).
 *
 * Covers the admin parking overview + sub-pages, zone/bay CRUD with the
 * `ZONE_EXISTS` / `BAY_EXISTS` / `BAY_IN_USE` semantics, resident allocations
 * (`BAY_ALLOCATED` / `HOUSEHOLD_ALLOCATED` / `HOUSEHOLD_INACTIVE`, resident-bay
 * constraint, end-without-delete), the violations queue (create/list/filter/
 * resolve with notes, dismiss, terminal 409 `VIOLATION_CLOSED`), guard violation
 * reporting with own-only scoping, the permissions matrix, cross-tenant
 * isolation (David ↔ Greenview), no hard deletes, PII-free audit actions,
 * CSRF, XSS escaping and the `parking_overstay_grace_minutes` settings key.
 *
 * PRE-WRITTEN for the Inc-8 gate: these routes do not exist until T60/T61 land,
 * so this file is parked in storage/temporary/pending-tests/ and must not be
 * folded into tests/Integration/ before the Lead opens the gate.
 */
final class ParkingAdminTest extends AdminModuleTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $limiter = new RateLimiter($this->db);

        foreach (['parking:', 'violation:', 'otp:', 'throttle:'] as $prefix) {
            $limiter->clearPrefix($prefix);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures + helpers                                                 */
    /* ------------------------------------------------------------------ */

    private function countRows(string $table): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $table);
    }

    /**
     * @return array<string, mixed>
     */
    private function seededZone(string $name = 'Resident Zone A'): array
    {
        $row = $this->db->selectOne(
            'SELECT id, uuid, name, zone_type FROM parking_zones
             WHERE estate_id = :estate_id AND name = :name LIMIT 1',
            ['estate_id' => (int) $this->estate()['id'], 'name' => $name]
        );

        if ($row === null) {
            $this->fail('Seeded parking zone "' . $name . '" was not found (run the parking seeder).');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function seededBay(string $code): array
    {
        $row = $this->db->selectOne(
            'SELECT b.id, b.uuid, b.bay_code, b.bay_type, b.status, b.zone_id, z.name AS zone_name
             FROM parking_bays b
             INNER JOIN parking_zones z ON z.id = b.zone_id AND z.estate_id = b.estate_id
             WHERE b.estate_id = :estate_id AND b.bay_code = :code LIMIT 1',
            ['estate_id' => (int) $this->estate()['id'], 'code' => $code]
        );

        if ($row === null) {
            $this->fail('Seeded parking bay "' . $code . '" was not found (run the parking seeder).');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function bayByUuid(string $uuid): array
    {
        $row = $this->db->selectOne(
            'SELECT id, uuid, bay_code, bay_type, status, zone_id FROM parking_bays WHERE uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );

        if ($row === null) {
            $this->fail('Parking bay ' . $uuid . ' was not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function zoneByUuid(string $uuid): array
    {
        $row = $this->db->selectOne(
            'SELECT id, uuid, name, zone_type FROM parking_zones WHERE uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );

        if ($row === null) {
            $this->fail('Parking zone ' . $uuid . ' was not found.');
        }

        return $row;
    }

    private function householdId(string $unit): int
    {
        $id = $this->db->scalar(
            'SELECT id FROM households WHERE estate_id = :estate_id AND unit_number = :unit LIMIT 1',
            ['estate_id' => (int) $this->estate()['id'], 'unit' => $unit]
        );

        if (!is_numeric($id)) {
            $this->fail('Seeded household ' . $unit . ' was not found.');
        }

        return (int) $id;
    }

    /**
     * Direct fixture session (the check-in flow is covered by Increment 3 tests).
     */
    private function insertActiveSession(string $bayCode): int
    {
        $bay = $this->seededBay($bayCode);

        return $this->db->insert('parking_sessions', [
            'uuid' => Uuid::v4(),
            'estate_id' => (int) $this->estate()['id'],
            'bay_id' => (int) $bay['id'],
            'checkin_at' => Clock::nowDb(),
            'status' => 'active',
        ]);
    }

    /**
     * Dedicated archived household created through the real admin flow (the
     * seeded households are never mutated).
     */
    private function createArchivedHousehold(): string
    {
        $this->ensureLoggedInAs(self::GRACE);

        $unit = 'QA-PARK-' . random_int(100, 999);
        $building = $this->seededBuilding('Block A');

        $this->assertAdminRedirect(
            $this->adminPost('/admin/households', [
                'unit_number' => $unit,
                'building_uuid' => (string) $building['uuid'],
                'household_type' => 'apartment',
            ]),
            '/admin/households'
        );

        $householdUuid = (string) $this->db->scalar(
            'SELECT uuid FROM households WHERE estate_id = :estate_id AND unit_number = :unit LIMIT 1',
            ['estate_id' => (int) $this->estate()['id'], 'unit' => $unit]
        );

        if ($householdUuid === '') {
            $this->fail('The dedicated QA parking household was not created.');
        }

        $this->assertAdminRedirect(
            $this->adminPost('/admin/households/' . $householdUuid . '/archive', []),
            '/admin/households'
        );

        return $householdUuid;
    }

    private function assertSingleH1(Response $page, string $context): void
    {
        $this->assertCount(1, $this->xpath('//h1', $this->dom($page)), $context . ' must render exactly one h1.');
    }

    /**
     * Admin parking mutations are PRG (302). Validation/state failures may be an
     * inline 422/409 or a PRG redirect whose next page renders the flash error.
     *
     * @param array<int, string> $markers
     */
    private function assertParkingRejected(Response $response, string $fallbackPath, array $markers): void
    {
        if (in_array($response->status(), [422, 409], true)) {
            foreach ($markers as $marker) {
                if (stripos($response->body(), $marker) !== false) {
                    return;
                }
            }

            $this->fail('The ' . $response->status() . ' response must contain one of: ' . implode(', ', $markers));
        }

        if (in_array($response->status(), [302, 303], true)) {
            $location = (string) $response->header('Location');
            $follow = $this->get($location !== '' ? $location : $fallbackPath);

            foreach ($markers as $marker) {
                if (stripos($follow->body(), $marker) !== false) {
                    return;
                }
            }

            $this->fail(
                'The redirected page must render one of: ' . implode(', ', $markers) . ' (got ' . $location . ').'
            );
        }

        $this->fail('Expected a parking-admin rejection (422/409/PRG 302), got HTTP ' . $response->status() . '.');
    }

    /**
     * Foreign-estate UUIDs must never mutate: accept a 404 page or the admin
     * PRG-flash "not available / NOT_FOUND" pattern.
     */
    private function assertForeignDenied(Response $response, string $fallbackPath): void
    {
        if ($response->status() === 404) {
            $this->assertBodyContains('NOT_FOUND', $response);

            return;
        }

        if (in_array($response->status(), [302, 303], true)) {
            $location = (string) $response->header('Location');
            $follow = $this->get($location !== '' ? $location : $fallbackPath);
            $body = $follow->body();

            if (stripos($body, 'NOT_FOUND') !== false || stripos($body, 'not available') !== false) {
                return;
            }
        }

        $this->fail('A foreign-estate parking UUID must be denied with 404/NOT_FOUND, got HTTP ' . $response->status() . '.');
    }

    /**
     * @return array<string, mixed>
     */
    private function violationByPlate(string $plate): array
    {
        $row = $this->db->selectOne(
            'SELECT id, uuid, bay_id, plate_number, violation_type, status, notes, reported_by_user_id, resolved_at
             FROM parking_violations WHERE estate_id = :estate_id AND plate_number = :plate ORDER BY id DESC LIMIT 1',
            ['estate_id' => (int) $this->estate()['id'], 'plate' => strtoupper($plate)]
        );

        if ($row === null) {
            $this->fail('Parking violation with plate ' . $plate . ' was not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function adminParkingPost(string $path, array $body, string $phone = self::GRACE, bool $csrf = true): Response
    {
        return $this->adminPost($path, $body, $phone, $csrf);
    }

    /* ------------------------------------------------------------------ */
    /* Overview + zones                                                   */
    /* ------------------------------------------------------------------ */

    public function testParkingOverviewAndSubpagesRender(): void
    {
        $page = $this->adminGet('/admin/parking');
        $this->assertStatus(200, $page);
        $this->assertCleanMarkup($page, 'Parking overview');
        $this->assertSingleH1($page, 'Parking overview');

        foreach ([
            '/admin/parking/zones',
            '/admin/parking/bays',
            '/admin/parking/allocations',
            '/admin/parking/violations',
        ] as $path) {
            $this->assertBodyContains($path, $page, 'The parking overview must link to ' . $path . '.');
        }

        $this->assertTextContains('Parking', $page);

        foreach ([
            '/admin/parking/zones' => 'Parking zones',
            '/admin/parking/bays' => 'Parking bays',
            '/admin/parking/allocations' => 'Parking allocations',
            '/admin/parking/violations' => 'Parking violations',
        ] as $path => $context) {
            $subpage = $this->adminGet($path);
            $this->assertStatus(200, $subpage, $context . ' must render for an estate admin.');
            $this->assertCleanMarkup($subpage, $context);
            $this->assertSingleH1($subpage, $context);
        }
    }

    public function testZonesAreListedCreatedAndEdited(): void
    {
        $page = $this->adminGet('/admin/parking/zones');
        $this->assertStatus(200, $page);
        $this->assertTextContains('Resident Zone A', $page);
        $this->assertTextContains('Visitor Zone V', $page);

        $before = $this->countRows('parking_zones');

        $create = $this->adminParkingPost('/admin/parking/zones', [
            'name' => 'QA Zone X',
            'zone_type' => 'mixed',
        ]);
        $this->assertAdminRedirect($create, '/admin/parking/zones');
        $this->assertSame($before + 1, $this->countRows('parking_zones'), 'Creating a zone must add exactly one row.');

        $zone = $this->db->selectOne(
            'SELECT id, uuid, name, zone_type, estate_id FROM parking_zones WHERE name = :name LIMIT 1',
            ['name' => 'QA Zone X']
        );
        $this->assertNotNull($zone, 'The new zone must be persisted.');
        $this->assertSame('mixed', (string) $zone['zone_type']);
        $this->assertSame((int) $this->estate()['id'], (int) $zone['estate_id'], 'Zones must be estate scoped.');

        $edit = $this->adminParkingPost('/admin/parking/zones/' . (string) $zone['uuid'], [
            'name' => 'QA Zone X Renamed',
            'zone_type' => 'service',
        ]);
        $this->assertAdminRedirect($edit, '/admin/parking/zones');

        $edited = $this->zoneByUuid((string) $zone['uuid']);
        $this->assertSame('QA Zone X Renamed', (string) $edited['name']);
        $this->assertSame('service', (string) $edited['zone_type']);

        $audit = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM audit_logs WHERE action IN ('parking.zone_created', 'parking.zone_updated')"
        );
        $this->assertTrue((int) $audit['c'] >= 2, 'Zone create and update must be audited.');
    }

    public function testDuplicateZoneNameIsRejected(): void
    {
        $before = $this->countRows('parking_zones');

        $response = $this->adminParkingPost('/admin/parking/zones', [
            'name' => 'Resident Zone A',
            'zone_type' => 'resident',
        ]);

        $this->assertParkingRejected($response, '/admin/parking/zones', ['ZONE_EXISTS', 'already']);
        $this->assertSame($before, $this->countRows('parking_zones'), 'A duplicate zone must not persist a row.');
    }

    public function testZoneValidationRejectsBadInput(): void
    {
        $before = $this->countRows('parking_zones');

        foreach ([
            ['name' => '', 'zone_type' => 'resident'],
            ['name' => 'A', 'zone_type' => 'resident'],
            ['name' => str_repeat('Z', 101), 'zone_type' => 'resident'],
            ['name' => 'QA Bad Zone Type', 'zone_type' => 'not-a-zone-type'],
        ] as $body) {
            $this->assertParkingRejected(
                $this->adminParkingPost('/admin/parking/zones', $body),
                '/admin/parking/zones',
                ['VALIDATION', 'invalid', 'required', 'between', 'zone', 'name']
            );
        }

        $this->assertSame($before, $this->countRows('parking_zones'), 'Rejected zones must not persist.');
    }

    /* ------------------------------------------------------------------ */
    /* Bays                                                               */
    /* ------------------------------------------------------------------ */

    public function testBaysAreListedCreatedEditedAndArchived(): void
    {
        $page = $this->adminGet('/admin/parking/bays');
        $this->assertStatus(200, $page);

        foreach (['R-01', 'V-01', 'V-06'] as $code) {
            $this->assertTextContains($code, $page, 'The bay list must show ' . $code . '.');
        }

        $residentZone = $this->seededZone('Resident Zone A');
        $visitorZone = $this->seededZone('Visitor Zone V');
        $before = $this->countRows('parking_bays');

        $create = $this->adminParkingPost('/admin/parking/bays', [
            'bay_code' => 'QA-B1',
            'zone_uuid' => (string) $residentZone['uuid'],
            'bay_type' => 'resident',
        ]);
        $this->assertAdminRedirect($create, '/admin/parking/bays');
        $this->assertSame($before + 1, $this->countRows('parking_bays'));

        $bay = $this->seededBay('QA-B1');
        $this->assertSame('available', (string) $bay['status'], 'A new bay must start available.');

        $edit = $this->adminParkingPost('/admin/parking/bays/' . (string) $bay['uuid'], [
            'bay_code' => 'QA-B2',
            'zone_uuid' => (string) $visitorZone['uuid'],
            'bay_type' => 'visitor',
        ]);
        $this->assertAdminRedirect($edit, '/admin/parking/bays');

        $edited = $this->bayByUuid((string) $bay['uuid']);
        $this->assertSame('QA-B2', (string) $edited['bay_code']);
        $this->assertSame('visitor', (string) $edited['bay_type']);
        $this->assertSame((int) $visitorZone['id'], (int) $edited['zone_id']);

        $archive = $this->adminParkingPost('/admin/parking/bays/' . (string) $bay['uuid'] . '/archive', []);
        $this->assertAdminRedirect($archive, '/admin/parking/bays');

        $archived = $this->bayByUuid((string) $bay['uuid']);
        $this->assertSame('maintenance', (string) $archived['status'], 'Archiving a bay must be a soft maintenance status.');
        $this->assertSame($before + 1, $this->countRows('parking_bays'), 'Archiving must never delete the bay row.');

        $audit = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM audit_logs
             WHERE action IN ('parking.bay_created', 'parking.bay_updated', 'parking.bay_archived')"
        );
        $this->assertTrue((int) $audit['c'] >= 3, 'Bay create/edit/archive must be audited.');
    }

    public function testDuplicateBayCodeIsRejected(): void
    {
        $before = $this->countRows('parking_bays');
        $residentZone = $this->seededZone('Resident Zone A');

        $response = $this->adminParkingPost('/admin/parking/bays', [
            'bay_code' => 'R-01',
            'zone_uuid' => (string) $residentZone['uuid'],
            'bay_type' => 'resident',
        ]);

        $this->assertParkingRejected($response, '/admin/parking/bays', ['BAY_EXISTS', 'already']);
        $this->assertSame($before, $this->countRows('parking_bays'));
    }

    public function testBayArchiveIsBlockedWhileInUse(): void
    {
        // R-02 has an active resident allocation.
        $allocated = $this->adminParkingPost('/admin/parking/bays/' . (string) $this->seededBay('R-02')['uuid'] . '/archive', []);
        $this->assertParkingRejected($allocated, '/admin/parking/bays', ['BAY_IN_USE', 'allocation', 'in use']);
        $this->assertSame('available', (string) $this->bayByUuid((string) $this->seededBay('R-02')['uuid'])['status']);

        // V-01 has an active visitor reservation.
        $reserved = $this->adminParkingPost('/admin/parking/bays/' . (string) $this->seededBay('V-01')['uuid'] . '/archive', []);
        $this->assertParkingRejected($reserved, '/admin/parking/bays', ['BAY_IN_USE', 'reservation', 'in use']);

        // V-04 gets an active parking session.
        $this->insertActiveSession('V-04');
        $occupied = $this->adminParkingPost('/admin/parking/bays/' . (string) $this->seededBay('V-04')['uuid'] . '/archive', []);
        $this->assertParkingRejected($occupied, '/admin/parking/bays', ['BAY_IN_USE', 'session', 'in use']);

        // A genuinely free bay can be archived.
        $freeZone = $this->seededZone('Resident Zone A');
        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/bays', [
            'bay_code' => 'QA-FREE1',
            'zone_uuid' => (string) $freeZone['uuid'],
            'bay_type' => 'resident',
        ]), '/admin/parking/bays');

        $free = $this->seededBay('QA-FREE1');
        $this->assertAdminRedirect(
            $this->adminParkingPost('/admin/parking/bays/' . (string) $free['uuid'] . '/archive', []),
            '/admin/parking/bays'
        );
        $this->assertSame('maintenance', (string) $this->bayByUuid((string) $free['uuid'])['status']);
    }

    /* ------------------------------------------------------------------ */
    /* Allocations                                                        */
    /* ------------------------------------------------------------------ */

    public function testAllocationsAreListedCreatedAndEnded(): void
    {
        $page = $this->adminGet('/admin/parking/allocations');
        $this->assertStatus(200, $page);

        // Seeded active allocations for the demo households.
        foreach (['R-01', 'R-02', 'R-03'] as $code) {
            $this->assertTextContains($code, $page, 'The allocations list must show ' . $code . '.');
        }

        $bay = $this->seededBay('R-04');
        $household = $this->householdUuid('A-004');
        $before = $this->countRows('parking_allocations');

        $create = $this->adminParkingPost('/admin/parking/allocations', [
            'bay_uuid' => (string) $bay['uuid'],
            'household_uuid' => $household,
            'starts_at' => Clock::nowDb(),
        ]);
        $this->assertAdminRedirect($create, '/admin/parking/allocations');
        $this->assertSame($before + 1, $this->countRows('parking_allocations'));

        $allocation = $this->db->selectOne(
            "SELECT id, uuid, bay_id, household_id, status, ends_at FROM parking_allocations
             WHERE estate_id = :estate_id AND bay_id = :bay_id AND status = 'active' ORDER BY id DESC LIMIT 1",
            ['estate_id' => (int) $this->estate()['id'], 'bay_id' => (int) $bay['id']]
        );
        $this->assertNotNull($allocation, 'The allocation must be active.');
        $this->assertSame($this->householdId('A-004'), (int) $allocation['household_id']);
        $this->assertNull($allocation['ends_at']);

        $end = $this->adminParkingPost('/admin/parking/allocations/' . (string) $allocation['uuid'] . '/end', []);
        $this->assertAdminRedirect($end, '/admin/parking/allocations');

        $ended = $this->db->selectOne(
            'SELECT status, ends_at FROM parking_allocations WHERE id = :id',
            ['id' => (int) $allocation['id']]
        );
        $this->assertNotNull($ended);
        $this->assertSame('ended', (string) $ended['status'], 'Ending an allocation is a soft status change.');
        $this->assertNotNull($ended['ends_at'], 'Ending an allocation must stamp ends_at.');
        $this->assertSame($before + 1, $this->countRows('parking_allocations'), 'Ending must never delete the row.');

        $audit = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM audit_logs WHERE action IN ('parking.allocation_created', 'parking.allocation_ended')"
        );
        $this->assertTrue((int) $audit['c'] >= 2, 'Allocation create/end must be audited.');
    }

    public function testSingleActiveAllocationPerBayAndHousehold(): void
    {
        $bay = $this->seededBay('R-05');
        $household = $this->householdUuid('A-005');

        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/allocations', [
            'bay_uuid' => (string) $bay['uuid'],
            'household_uuid' => $household,
            'starts_at' => Clock::nowDb(),
        ]), '/admin/parking/allocations');

        // Same bay, another household → BAY_ALLOCATED.
        $this->assertParkingRejected(
            $this->adminParkingPost('/admin/parking/allocations', [
                'bay_uuid' => (string) $bay['uuid'],
                'household_uuid' => $this->householdUuid('A-006'),
                'starts_at' => Clock::nowDb(),
            ]),
            '/admin/parking/allocations',
            ['BAY_ALLOCATED', 'already allocated', 'in use']
        );

        // Same household, another bay → HOUSEHOLD_ALLOCATED.
        $this->assertParkingRejected(
            $this->adminParkingPost('/admin/parking/allocations', [
                'bay_uuid' => (string) $this->seededBay('R-06')['uuid'],
                'household_uuid' => $household,
                'starts_at' => Clock::nowDb(),
            ]),
            '/admin/parking/allocations',
            ['HOUSEHOLD_ALLOCATED', 'already has', 'household']
        );

        $this->assertSame(
            1,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM parking_allocations WHERE estate_id = :estate_id AND bay_id = :bay_id AND status = 'active'",
                ['estate_id' => (int) $this->estate()['id'], 'bay_id' => (int) $bay['id']]
            ),
            'Only one active allocation per bay is allowed.'
        );
    }

    public function testAllocationRequiresAnActiveHousehold(): void
    {
        $archivedHousehold = $this->createArchivedHousehold();
        $bay = $this->seededBay('R-07');
        $before = $this->countRows('parking_allocations');

        $this->assertParkingRejected(
            $this->adminParkingPost('/admin/parking/allocations', [
                'bay_uuid' => (string) $bay['uuid'],
                'household_uuid' => $archivedHousehold,
                'starts_at' => Clock::nowDb(),
            ]),
            '/admin/parking/allocations',
            ['HOUSEHOLD_INACTIVE', 'archived', 'inactive']
        );

        $this->assertSame($before, $this->countRows('parking_allocations'), 'An archived household must not receive a bay allocation.');
    }

    public function testAllocationIsLimitedToResidentBays(): void
    {
        // V-03 is a visitor bay: resident allocations are not allowed on it.
        $before = $this->countRows('parking_allocations');

        $response = $this->adminParkingPost('/admin/parking/allocations', [
            'bay_uuid' => (string) $this->seededBay('V-03')['uuid'],
            'household_uuid' => $this->householdUuid('A-004'),
            'starts_at' => Clock::nowDb(),
        ]);

        $this->assertParkingRejected($response, '/admin/parking/allocations', ['bay_type', 'resident', 'visitor', 'VALIDATION', 'allocat']);
        $this->assertSame($before, $this->countRows('parking_allocations'));
    }

    /* ------------------------------------------------------------------ */
    /* Violations                                                         */
    /* ------------------------------------------------------------------ */

    public function testViolationsAreListedFilteredCreatedAndResolved(): void
    {
        $page = $this->adminGet('/admin/parking/violations');
        $this->assertStatus(200, $page);
        $this->assertCleanMarkup($page, 'Violations queue');
        $this->assertSame(0, $this->countRows('parking_violations'), 'The demo seed has no parking violations.');

        $create = $this->adminParkingPost('/admin/parking/violations', [
            'bay_uuid' => (string) $this->seededBay('R-08')['uuid'],
            'type' => 'unauthorised_bay',
            'plate_number' => 'KDZ900',
            'notes' => 'QA violation unauthorised bay marker',
        ]);
        $this->assertAdminRedirect($create, '/admin/parking/violations');

        $violation = $this->violationByPlate('KDZ900');
        $this->assertSame('open', (string) $violation['status']);
        $this->assertSame('unauthorised_bay', (string) $violation['violation_type']);
        $this->assertSame($this->userId(self::GRACE), (int) $violation['reported_by_user_id'], 'The admin reporter must be recorded.');

        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/violations', [
            'bay_uuid' => (string) $this->seededBay('V-03')['uuid'],
            'type' => 'overstay',
            'plate_number' => 'KDZ901',
            'notes' => 'QA violation overstay marker',
        ]), '/admin/parking/violations');

        // Filters: status + type, and unknown values fall back to all.
        $open = $this->adminGet('/admin/parking/violations?status=open');
        $this->assertStatus(200, $open);
        $this->assertTextContains('KDZ900', $open);
        $this->assertTextContains('KDZ901', $open);

        $overstay = $this->adminGet('/admin/parking/violations?status=open&type=overstay');
        $this->assertStatus(200, $overstay);
        $this->assertTextContains('KDZ901', $overstay);
        $this->assertTextNotContains('KDZ900', $overstay, 'The type filter must exclude other violation types.');

        $resolved = $this->adminParkingPost('/admin/parking/violations/' . (string) $violation['uuid'] . '/resolve', [
            'status' => 'resolved',
            'notes' => 'QA resolved after review.',
        ]);
        $this->assertAdminRedirect($resolved, '/admin/parking/violations');

        $after = $this->violationByPlate('KDZ900');
        $this->assertSame('resolved', (string) $after['status']);
        $this->assertNotNull($after['resolved_at'], 'Resolving must stamp resolved_at.');

        $audit = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM audit_logs WHERE action IN ('parking.violation_created', 'parking.violation_resolved')"
        );
        $this->assertTrue((int) $audit['c'] >= 2, 'Violation create/resolve must be audited.');
    }

    public function testViolationResolveRequiresNotesAndTerminalStateIsClosed(): void
    {
        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/violations', [
            'bay_uuid' => (string) $this->seededBay('R-06')['uuid'],
            'type' => 'other',
            'plate_number' => 'KDZ902',
            'notes' => 'QA violation notes required marker',
        ]), '/admin/parking/violations');

        $violation = $this->violationByPlate('KDZ902');
        $beforeStatus = (string) $violation['status'];

        $missingNotes = $this->adminParkingPost('/admin/parking/violations/' . (string) $violation['uuid'] . '/resolve', [
            'status' => 'resolved',
        ]);
        $this->assertParkingRejected($missingNotes, '/admin/parking/violations', ['notes', 'required', 'VALIDATION', 'between', 'resolved']);
        $this->assertSame($beforeStatus, (string) $this->violationByPlate('KDZ902')['status'], 'A resolve without notes must not change status.');

        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/violations/' . (string) $violation['uuid'] . '/resolve', [
            'status' => 'resolved',
            'notes' => 'QA resolved notes.',
        ]), '/admin/parking/violations');

        $closed = $this->adminParkingPost('/admin/parking/violations/' . (string) $violation['uuid'] . '/resolve', [
            'status' => 'resolved',
            'notes' => 'QA second resolve attempt.',
        ]);
        $this->assertParkingRejected($closed, '/admin/parking/violations', ['VIOLATION_CLOSED', 'closed', 'terminal']);
        $this->assertSame('resolved', (string) $this->violationByPlate('KDZ902')['status'], 'A resolved violation is terminal.');
    }

    public function testViolationCanBeDismissedButNotReopened(): void
    {
        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/violations', [
            'bay_uuid' => (string) $this->seededBay('R-06')['uuid'],
            'type' => 'unregistered_vehicle',
            'plate_number' => 'KDZ903',
            'notes' => 'QA violation dismissal marker',
        ]), '/admin/parking/violations');

        $violation = $this->violationByPlate('KDZ903');

        // The contract keeps a single resolve route: dismissal is a resolve
        // decision (status=dismissed). Notes are optional for a dismissal.
        $dismiss = $this->adminParkingPost('/admin/parking/violations/' . (string) $violation['uuid'] . '/resolve', [
            'status' => 'dismissed',
            'notes' => 'QA dismissed as duplicate report.',
        ]);

        $this->assertAdminRedirect($dismiss, '/admin/parking/violations');
        $this->assertSame('dismissed', (string) $this->violationByPlate('KDZ903')['status'], 'Dismissal must soft-close the violation.');

        // Terminal: any further transition is rejected (409 VIOLATION_CLOSED).
        $closed = $this->adminParkingPost('/admin/parking/violations/' . (string) $violation['uuid'] . '/resolve', [
            'status' => 'resolved',
            'notes' => 'QA reopen attempt.',
        ]);
        $this->assertParkingRejected($closed, '/admin/parking/violations', ['VIOLATION_CLOSED', 'closed', 'terminal']);
    }

    public function testGuardReportsViolationAndOnlySeesOwnReports(): void
    {
        $this->loginGuard();

        $page = $this->get('/gate/parking/violations');
        $this->assertStatus(200, $page);
        $this->assertCleanMarkup($page, 'Guard parking violations');
        $this->assertSingleH1($page, 'Guard parking violations');

        $report = $this->post('/gate/parking/violations', [
            'bay_uuid' => (string) $this->seededBay('V-03')['uuid'],
            'type' => 'unauthorised_bay',
            'plate_number' => 'KCX111',
            'notes' => 'QA guard violation report marker',
        ], true);
        $this->assertStatus(302, $report, 'A guard violation report must redirect (PRG).');

        $guardViolation = $this->violationByPlate('KCX111');
        $this->assertSame($this->userId(self::GUARD_MAIN), (int) $guardViolation['reported_by_user_id']);
        $this->assertSame('open', (string) $guardViolation['status']);

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'parking.violation.reported'");
        $this->assertTrue((int) $audit['c'] > 0, 'A guard report must be audited as parking.violation.reported.');

        // Admin-created violations are not visible on the guard screen.
        $this->ensureLoggedInAs(self::GRACE);
        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/violations', [
            'bay_uuid' => (string) $this->seededBay('R-06')['uuid'],
            'type' => 'other',
            'plate_number' => 'KDZ904',
            'notes' => 'QA admin violation not for guard',
        ]), '/admin/parking/violations');

        $this->loginGuard();
        $guardPage = $this->get('/gate/parking/violations');
        $this->assertStatus(200, $guardPage);
        $this->assertTextContains('KCX111', $guardPage);
        $this->assertTextNotContains('KDZ904', $guardPage, 'Guards must only see violations they reported.');

        // No gate context → the report is rejected before any row is written.
        $this->loginAs(self::GUARD_MAIN);
        $before = $this->countRows('parking_violations');
        $noGate = $this->post('/gate/parking/violations', [
            'bay_uuid' => (string) $this->seededBay('V-04')['uuid'],
            'type' => 'other',
            'plate_number' => 'KCX112',
        ], true);

        $this->assertTrue(
            in_array($noGate->status(), [409, 302], true),
            'A gate-less guard report must be rejected, got HTTP ' . $noGate->status() . '.'
        );

        if ($noGate->status() === 409) {
            $this->assertBodyContains('GATE_CONTEXT_REQUIRED', $noGate);
        }

        $this->assertSame($before, $this->countRows('parking_violations'));
    }

    /* ------------------------------------------------------------------ */
    /* Permissions + tenant isolation                                     */
    /* ------------------------------------------------------------------ */

    public function testParkingAdminPermissionsMatrix(): void
    {
        // Estate admin: full access.
        foreach (['/admin/parking', '/admin/parking/zones', '/admin/parking/bays', '/admin/parking/allocations', '/admin/parking/violations'] as $path) {
            $this->assertStatus(200, $this->adminGet($path), 'An estate admin must read ' . $path . '.');
        }

        // Estate manager: parking.view + allocation manage, but no bay/violation manage.
        $this->ensureLoggedInAs(self::MANAGER);
        $this->assertStatus(200, $this->get('/admin/parking'));
        $this->assertStatus(403, $this->post('/admin/parking/zones', [
            'name' => 'Manager Forbidden Zone',
            'zone_type' => 'resident',
        ], true), 'An estate manager must not manage parking bays/zones.');
        $this->assertStatus(403, $this->post('/admin/parking/violations', [
            'bay_uuid' => (string) $this->seededBay('R-06')['uuid'],
            'type' => 'other',
        ], true), 'An estate manager must not manage violations.');

        // Guard: no admin parking area.
        $this->loginGuard();
        $this->assertStatus(403, $this->get('/admin/parking'), 'A guard must not reach the parking admin area.');
        $this->assertStatus(403, $this->post('/admin/parking/zones', ['name' => 'Guard Forbidden Zone', 'zone_type' => 'resident'], true));

        // Resident: no admin parking area.
        $this->ensureLoggedInAs(self::JANE);
        $this->assertStatus(403, $this->get('/admin/parking'), 'A resident must not reach the parking admin area.');
        $this->assertStatus(403, $this->post('/admin/parking/bays', [
            'bay_code' => 'QA-NOPE1',
            'zone_uuid' => (string) $this->seededZone('Resident Zone A')['uuid'],
            'bay_type' => 'resident',
        ], true));

        $this->assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM parking_zones WHERE name IN ('Manager Forbidden Zone', 'Guard Forbidden Zone')"));
        $this->assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM parking_bays WHERE bay_code = 'QA-NOPE1'"));
    }

    public function testRiversideAdminCannotSeeOrTriageGreenviewParking(): void
    {
        $greenviewZone = $this->seededZone('Resident Zone A');
        $greenviewBay = $this->seededBay('R-06');

        $page = $this->adminGet('/admin/parking', self::DAVID_RIVERSIDE);
        $this->assertStatus(200, $page);
        $this->assertBodyNotContains('Resident Zone A', $page, 'A Riverside admin must never see Greenview parking zones.');
        $this->assertBodyNotContains($greenviewBay['uuid'], $page);

        $zones = $this->adminGet('/admin/parking/zones', self::DAVID_RIVERSIDE);
        $this->assertStatus(200, $zones);
        $this->assertBodyNotContains('Resident Zone A', $zones);

        // Foreign-estate edits/archives are denied and do not mutate Greenview.
        $this->assertForeignDenied(
            $this->adminParkingPost('/admin/parking/zones/' . (string) $greenviewZone['uuid'], [
                'name' => 'Hijacked Zone',
                'zone_type' => 'resident',
            ], self::DAVID_RIVERSIDE),
            '/admin/parking/zones'
        );
        $this->assertSame('Resident Zone A', (string) $this->zoneByUuid((string) $greenviewZone['uuid'])['name']);

        $this->assertForeignDenied(
            $this->adminParkingPost('/admin/parking/bays/' . (string) $greenviewBay['uuid'] . '/archive', [], self::DAVID_RIVERSIDE),
            '/admin/parking/bays'
        );
        $this->assertSame('available', (string) $this->bayByUuid((string) $greenviewBay['uuid'])['status']);

        // A Riverside admin can still manage their own estate.
        $riverside = $this->seededEstate('riverside-court');
        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);

        $this->assertAdminRedirect($this->adminPost('/admin/parking/zones', [
            'name' => 'QA Riverside Zone',
            'zone_type' => 'resident',
        ], self::DAVID_RIVERSIDE), '/admin/parking/zones');

        $this->assertSame(
            1,
            (int) $this->db->scalar('SELECT COUNT(*) FROM parking_zones WHERE estate_id = :estate_id AND name = :name', [
                'estate_id' => (int) $riverside['id'],
                'name' => 'QA Riverside Zone',
            ]),
            'A Riverside admin must be able to create their own estate zone.'
        );

        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM parking_zones WHERE estate_id = :estate_id AND name = :name', [
                'estate_id' => (int) $this->estate()['id'],
                'name' => 'QA Riverside Zone',
            ]),
            'Riverside writes must never land in Greenview.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* No hard deletes, audit hygiene, CSRF, XSS                          */
    /* ------------------------------------------------------------------ */

    public function testParkingAdminMutationsNeverHardDelete(): void
    {
        $zones = $this->countRows('parking_zones');
        $bays = $this->countRows('parking_bays');
        $allocations = $this->countRows('parking_allocations');

        // Archive a dedicated free bay.
        $zone = $this->seededZone('Resident Zone A');
        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/bays', [
            'bay_code' => 'QA-NODEL1',
            'zone_uuid' => (string) $zone['uuid'],
            'bay_type' => 'resident',
        ]), '/admin/parking/bays');
        $bay = $this->seededBay('QA-NODEL1');
        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/bays/' . (string) $bay['uuid'] . '/archive', []), '/admin/parking/bays');

        // Create and then end a dedicated allocation (seeded allocations are read-only).
        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/allocations', [
            'bay_uuid' => (string) $this->seededBay('R-07')['uuid'],
            'household_uuid' => $this->householdUuid('A-006'),
            'starts_at' => Clock::nowDb(),
        ]), '/admin/parking/allocations');
        $allocation = $this->db->selectOne(
            "SELECT id, uuid FROM parking_allocations
             WHERE estate_id = :estate_id AND household_id = :household_id AND status = 'active' LIMIT 1",
            ['estate_id' => (int) $this->estate()['id'], 'household_id' => $this->householdId('A-006')]
        );
        $this->assertNotNull($allocation);
        $this->assertAdminRedirect(
            $this->adminParkingPost('/admin/parking/allocations/' . (string) $allocation['uuid'] . '/end', []),
            '/admin/parking/allocations'
        );

        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/violations', [
            'bay_uuid' => (string) $this->seededBay('R-08')['uuid'],
            'type' => 'other',
            'plate_number' => 'KDZ905',
        ]), '/admin/parking/violations');
        $violation = $this->violationByPlate('KDZ905');
        $this->assertAdminRedirect(
            $this->adminParkingPost('/admin/parking/violations/' . (string) $violation['uuid'] . '/resolve', [
                'status' => 'resolved',
                'notes' => 'QA no-delete resolve.',
            ]),
            '/admin/parking/violations'
        );

        $this->assertSame($zones, $this->countRows('parking_zones'), 'Parking zone writes must never delete rows.');
        $this->assertSame($bays + 1, $this->countRows('parking_bays'), 'Archiving must never delete bays.');
        $this->assertSame($allocations + 1, $this->countRows('parking_allocations'), 'Ending must never delete allocations.');
        $this->assertSame('maintenance', (string) $this->bayByUuid((string) $bay['uuid'])['status']);
        $this->assertSame('ended', (string) $this->db->scalar('SELECT status FROM parking_allocations WHERE id = :id', ['id' => (int) $allocation['id']]));
        $this->assertSame('resolved', (string) $this->violationByPlate('KDZ905')['status']);
    }

    public function testParkingAuditMetadataIsPiiFree(): void
    {
        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/zones', [
            'name' => 'QA Audit Zone',
            'zone_type' => 'resident',
        ]), '/admin/parking/zones');

        $zone = $this->db->selectOne("SELECT uuid FROM parking_zones WHERE name = 'QA Audit Zone' LIMIT 1");
        $this->assertNotNull($zone);

        $audit = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action = 'parking.zone_created' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit, 'Zone creation must be audited.');

        $metadata = (string) $audit['metadata'];
        $this->assertStringContainsString((string) $zone['uuid'], $metadata, 'Audit metadata must reference the zone uuid.');
        $this->assertFalse((bool) preg_match('/\+2547\d{6,9}/', $metadata), 'Audit metadata must never contain phone numbers.');
        $this->assertStringNotContainsString('@example', $metadata, 'Audit metadata must never contain email addresses.');
        $this->assertStringNotContainsString('QA Audit Zone', $metadata, 'Audit metadata must stay uuids/status only (no free text).');
    }

    public function testParkingAdminPostsRequireCsrf(): void
    {
        $zones = $this->countRows('parking_zones');
        $this->ensureLoggedInAs(self::GRACE);

        $noCsrf = $this->post('/admin/parking/zones', [
            'name' => 'QA CSRF Zone',
            'zone_type' => 'resident',
        ], false);

        $this->assertStatus(403, $noCsrf);
        $this->assertBodyContains('CSRF_TOKEN_INVALID', $noCsrf);
        $this->assertSame($zones, $this->countRows('parking_zones'));

        // Guard violation report is CSRF protected too.
        $this->loginGuard();
        $violations = $this->countRows('parking_violations');
        $guardNoCsrf = $this->post('/gate/parking/violations', [
            'bay_uuid' => (string) $this->seededBay('R-08')['uuid'],
            'type' => 'other',
        ], false);

        $this->assertStatus(403, $guardNoCsrf);
        $this->assertSame($violations, $this->countRows('parking_violations'));
    }

    public function testParkingAdminXssEscaping(): void
    {
        $zonePayload = 'QA <script>alert("zone")</script>';
        $notesPayload = 'QA notes <img src=x onerror="alert(1)">';

        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/zones', [
            'name' => $zonePayload,
            'zone_type' => 'resident',
        ]), '/admin/parking/zones');

        $zonesPage = $this->adminGet('/admin/parking/zones');
        $this->assertStatus(200, $zonesPage);
        $this->assertBodyNotContains($zonePayload, $zonesPage, 'Zone names must be escaped in the admin zone list.');
        $this->assertBodyContains('&lt;script&gt;', $zonesPage, 'The escaped zone name must still be visible as text.');
        $this->assertCleanMarkup($zonesPage, 'Admin parking zones (XSS)');

        $this->assertAdminRedirect($this->adminParkingPost('/admin/parking/violations', [
            'bay_uuid' => (string) $this->seededBay('R-08')['uuid'],
            'type' => 'other',
            'plate_number' => 'KDZ906',
            'notes' => $notesPayload,
        ]), '/admin/parking/violations');

        $violationsPage = $this->adminGet('/admin/parking/violations');
        $this->assertStatus(200, $violationsPage);
        $this->assertBodyNotContains($notesPayload, $violationsPage, 'Violation notes must be escaped in the admin queue.');
        $this->assertBodyContains('&lt;img', $violationsPage);
        $this->assertCleanMarkup($violationsPage, 'Admin violations queue (XSS)');
    }

    /* ------------------------------------------------------------------ */
    /* Overstay grace setting (allowlist)                                 */
    /* ------------------------------------------------------------------ */

    public function testOverstayGraceSettingIsAllowlistedAndRangeChecked(): void
    {
        $settings = $this->adminGet('/admin/settings');
        $this->assertStatus(200, $settings);
        $this->assertTextContains('parking_overstay_grace_minutes', $settings, 'The overstay grace setting must be exposed by the admin settings allowlist.');

        $response = $this->adminPost('/admin/settings', [
            'parking_overstay_grace_minutes' => '45',
            'visitor_validity_minutes' => '240',
            'visitor_parking_grace_minutes' => '30',
        ]);
        $this->assertAdminRedirect($response, '/admin/settings');

        $stored = (int) json_decode((string) $this->db->scalar(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :key',
            ['estate_id' => (int) $this->estate()['id'], 'key' => 'parking_overstay_grace_minutes']
        ), true);
        $this->assertSame(45, $stored, 'The overstay grace setting must persist as JSON.');

        foreach (['121', '-1'] as $invalid) {
            $this->assertParkingRejected(
                $this->adminPost('/admin/settings', ['parking_overstay_grace_minutes' => $invalid]),
                '/admin/settings',
                ['between', 'invalid', 'must be', 'range', 'VALIDATION']
            );
        }

        $this->assertSame(45, (int) json_decode((string) $this->db->scalar(
            'SELECT setting_value FROM estate_settings WHERE estate_id = :estate_id AND setting_key = :key',
            ['estate_id' => (int) $this->estate()['id'], 'key' => 'parking_overstay_grace_minutes']
        ), true), 'A rejected settings update must leave the stored value unchanged.');
    }
}
