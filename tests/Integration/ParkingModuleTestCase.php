<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Integrations\Sms\MockSmsProvider;

/**
 * Shared helpers for the Vehicles & Parking module tests
 * (docs/vehicles-parking-contract.md §1–§3).
 *
 * Demo fixtures (contract §1): Greenview zones "Resident Zone A" (resident) and
 * "Visitor Zone V" (visitor); bays R-01..R-08 and V-01..V-06 (V-05 accessible,
 * V-06 maintenance); vehicles KDA123A (Jane/A-001/R-01), KCA456B (Brian/A-002/R-02),
 * KDD789C (Amina/B-001/R-03); a reserved visitor reservation for the seeded
 * upcoming invitation on V-01; a completed history session on V-02.
 */
abstract class ParkingModuleTestCase extends VisitorModuleTestCase
{
    protected const PLATE_JANE = 'KDA123A';
    protected const PLATE_BRIAN = 'KCA456B';
    protected const PLATE_AMINA = 'KDD789C';

    protected const BAY_RESIDENT = 'R-01';
    protected const BAY_VISITOR = 'V-01';
    protected const BAY_MAINTENANCE = 'V-06';
    protected const BAY_ACCESSIBLE = 'V-05';

    public function setUp(): void
    {
        parent::setUp();

        $limiter = new RateLimiter($this->db);
        foreach (['vehicle:', 'parking:', 'resident:vehicle:', 'gate:parking:'] as $prefix) {
            $limiter->clearPrefix($prefix);
        }

        MockSmsProvider::reset();
    }

    /**
     * A DB timestamp for a wall-clock moment in the estate timezone.
     */
    protected function estateMoment(string $modifier): string
    {
        return Clock::toDb(new \DateTimeImmutable($modifier, new \DateTimeZone('Africa/Nairobi')));
    }

    /* ------------------------------------------------------------------ */
    /* Seeded fixture lookups                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    protected function seededVehicle(string $plate): array
    {
        $row = $this->db->selectOne(
            'SELECT id, uuid, estate_id, household_id, plate_number, vehicle_type, colour, status
             FROM vehicles WHERE estate_id = :estate_id AND plate_number = :plate LIMIT 1',
            ['estate_id' => (int) $this->estate()['id'], 'plate' => strtoupper($plate)]
        );

        if ($row === null) {
            $this->fail('Seeded vehicle ' . $plate . ' was not found (run the parking seeder).');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function seededBay(string $code): array
    {
        $row = $this->db->selectOne(
            'SELECT b.id, b.uuid, b.bay_code, b.bay_type, b.status, z.name AS zone_name, z.zone_type
             FROM parking_bays b
             INNER JOIN parking_zones z ON z.id = b.zone_id
             WHERE b.estate_id = :estate_id AND b.bay_code = :code LIMIT 1',
            ['estate_id' => (int) $this->estate()['id'], 'code' => $code]
        );

        if ($row === null) {
            $this->fail('Seeded parking bay ' . $code . ' was not found (run the parking seeder).');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function setBayStatus(string $code, string $status): void
    {
        $bay = $this->seededBay($code);

        $this->db->execute(
            'UPDATE parking_bays SET status = :status, updated_at = :updated WHERE id = :id',
            ['status' => $status, 'updated' => Clock::nowDb(), 'id' => (int) $bay['id']]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function reservationForInvitation(string $invitationUuid): ?array
    {
        $row = $this->db->selectOne(
            'SELECT r.*, b.bay_code, z.name AS zone_name
             FROM parking_reservations r
             INNER JOIN visitor_invitations i ON i.id = r.invitation_id
             INNER JOIN parking_bays b ON b.id = r.bay_id
             INNER JOIN parking_zones z ON z.id = b.zone_id
             WHERE i.uuid = :uuid ORDER BY r.id DESC LIMIT 1',
            ['uuid' => $invitationUuid]
        );

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function sessionForInvitation(string $invitationUuid): ?array
    {
        return $this->db->selectOne(
            'SELECT s.*, b.bay_code
             FROM parking_sessions s
             INNER JOIN visitor_invitations i ON i.id = s.invitation_id
             INNER JOIN parking_bays b ON b.id = s.bay_id
             WHERE i.uuid = :uuid ORDER BY s.id DESC LIMIT 1',
            ['uuid' => $invitationUuid]
        );
    }

    /* ------------------------------------------------------------------ */
    /* API helpers                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    protected function vehicleListApi(string $resident = self::JANE): array
    {
        $this->ensureLoggedInAs($resident);

        return $this->assertSuccess($this->get('/api/v1/resident/vehicles'))['data'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected function vehicleCreateApi(array $body, string $resident = self::JANE): array
    {
        $this->ensureLoggedInAs($resident);

        return $this->assertSuccess($this->post('/api/v1/resident/vehicles', $body, true))['data'];
    }

    protected function vehicleDeactivateApi(string $uuid, string $resident = self::JANE): Response
    {
        $this->ensureLoggedInAs($resident);

        return $this->post('/api/v1/resident/vehicles/' . $uuid . '/deactivate', [], true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function parkingIndexApi(string $resident = self::JANE): array
    {
        $this->ensureLoggedInAs($resident);

        return $this->assertSuccess($this->get('/api/v1/resident/parking'))['data'];
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    protected function availabilityApi(array $query = [], string $resident = self::JANE): array
    {
        $this->ensureLoggedInAs($resident);

        $query += [
            'from' => $this->estateMoment('+1 day 10:00'),
            'to' => $this->estateMoment('+1 day 14:00'),
        ];

        return $this->assertSuccess($this->get('/api/v1/parking/availability?' . http_build_query($query)))['data'];
    }

    /**
     * Create an invitation that requires visitor parking (inside the same
     * transaction on the product side).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function createInvitationWithParking(array $overrides = [], bool $parkingRequired = true): array
    {
        return $this->createInvitation(array_merge([
            'parking_required' => $parkingRequired,
        ], $overrides));
    }

    protected function guardParkingCheckin(array $body): Response
    {
        return $this->post('/api/v1/gate/parking/sessions/checkin', $body, true);
    }

    protected function guardParkingCheckout(array $body): Response
    {
        return $this->post('/api/v1/gate/parking/sessions/checkout', $body, true);
    }

    protected function guardParkingActive(): Response
    {
        return $this->get('/api/v1/gate/parking/active');
    }

    /**
     * Count rows in a table for the active estate.
     */
    protected function countRows(string $table, string $where = '', array $params = []): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $table;
        $params['estate_id'] = (int) $this->estate()['id'];

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return (int) $this->db->scalar($sql, $params);
    }
}
