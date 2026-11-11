<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Integrations\Sms\MockSmsProvider;
use Database\Seeds\DemoVisitorSeeder;

/**
 * Visitor parking reservations created inside the invitation transaction
 * (contract §2 "Reservation", §5): success, rollback semantics, conflict
 * prevention, vehicle validation, allocation visibility and cancellation.
 */
final class ParkingReservationFlowTest extends ParkingModuleTestCase
{
    /**
     * A window that does not overlap the seeded V-01 reservation (tomorrow 10–14).
     *
     * @return array{from: string, to: string}
     */
    private function freeWindow(): array
    {
        return [
            'from' => $this->estateMoment('+1 day 15:00'),
            'to' => $this->estateMoment('+1 day 17:00'),
        ];
    }

    /**
     * Restrict the estate to a single visitor bay for deterministic conflicts.
     */
    private function keepOnlyVisitorBay(string $code): void
    {
        $bays = $this->db->select(
            "SELECT b.bay_code FROM parking_bays b
             INNER JOIN parking_zones z ON z.id = b.zone_id
             WHERE b.estate_id = :estate_id AND z.zone_type = 'visitor'",
            ['estate_id' => (int) $this->estate()['id']]
        );

        foreach ($bays as $bay) {
            if ((string) $bay['bay_code'] !== $code) {
                $this->setBayStatus((string) $bay['bay_code'], 'maintenance');
            }
        }
    }

    public function testInvitationWithParkingCreatesTheReservationInsideTheTransaction(): void
    {
        $window = $this->freeWindow();
        $vehicle = $this->seededVehicle(self::PLATE_JANE);

        $data = $this->createInvitation([
            'parking_required' => true,
            'vehicle_id' => (string) $vehicle['uuid'],
            'valid_from' => $window['from'],
            'valid_until' => $window['to'],
        ]);

        $this->assertNotNull($data['parking'] ?? null, 'A parking invitation must return the reservation summary.');
        $parking = $data['parking'];

        foreach (['reservation_uuid', 'bay_code', 'zone_name', 'starts_at', 'ends_at'] as $key) {
            $this->assertArrayHasKey($key, $parking, 'Parking summary must expose ' . $key . '.');
        }

        $this->assertMatches('/^V-\d{2}$/', (string) $parking['bay_code']);
        $this->assertSame('Visitor Zone V', (string) $parking['zone_name']);

        $reservation = $this->reservationForInvitation((string) $data['invitation_uuid']);
        $this->assertNotNull($reservation);
        $this->assertSame('reserved', (string) $reservation['status']);
        $this->assertSame((string) $parking['bay_code'], (string) $reservation['bay_code']);
        $this->assertSame((int) $vehicle['id'], (int) $reservation['vehicle_id']);
        $this->assertSame(self::PLATE_JANE, (string) $reservation['plate_number']);

        $audit = $this->db->selectOne("SELECT metadata FROM audit_logs WHERE action = 'parking.reserved' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit, 'Reservation creation must be audited with action parking.reserved.');

        // Dev SMS confirmation to the visitor, with the bay code but never the host plate.
        $notification = $this->db->selectOne(
            "SELECT recipient, template FROM notification_logs WHERE template = 'parking_confirmation' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($notification, 'A parking confirmation SMS must be recorded (dev SMS).');
        $this->assertNotEmpty((string) $notification['recipient']);

        $sms = '';
        foreach (MockSmsProvider::messages() as $message) {
            if (str_contains((string) $message['message'], (string) $parking['bay_code'])) {
                $sms = (string) $message['message'];
            }
        }
        $this->assertNotEmpty($sms, 'The mock SMS adapter must have received the parking confirmation.');
        $this->assertContains((string) $parking['bay_code'], $sms);
        $this->assertFalse(str_contains($sms, self::PLATE_JANE), 'The parking SMS must not include the host plate.');
    }

    public function testInvitationWithoutParkingCreatesNoReservation(): void
    {
        $data = $this->createInvitation(['parking_required' => false, 'vehicle_id' => null]);

        $this->assertTrue(!isset($data['parking']) || $data['parking'] === null, 'parking must be null when not requested.');
        $this->assertNull($this->reservationForInvitation((string) $data['invitation_uuid']), 'No parking reservation may be created.');
    }

    public function testNoParkingAvailableRollsBackTheWholeInvitation(): void
    {
        $window = $this->freeWindow();
        $this->keepOnlyVisitorBay(self::BAY_VISITOR);

        // First invitation takes the only free visitor bay.
        $first = $this->createInvitation([
            'parking_required' => true,
            'valid_from' => $window['from'],
            'valid_until' => $window['to'],
        ]);
        $this->assertSame(self::BAY_VISITOR, (string) $first['parking']['bay_code']);

        $invitationsBefore = (int) $this->db->scalar('SELECT COUNT(*) FROM visitor_invitations');
        $visitorsBefore = (int) $this->db->scalar('SELECT COUNT(*) FROM visitors');
        $reservationsBefore = (int) $this->db->scalar('SELECT COUNT(*) FROM parking_reservations');

        $second = $this->post('/api/v1/resident/invitations', [
            'visitor_name' => 'No Bay Visitor',
            'visitor_phone' => '+254700090777',
            'household_uuid' => $this->householdUuid('A-001'),
            'purpose' => 'Should be rolled back',
            'valid_from' => $window['from'],
            'valid_until' => $window['to'],
            'visitor_count' => 1,
            'parking_required' => true,
        ], true);

        $this->assertErrorCode('NO_PARKING_AVAILABLE', $second, 409);

        // Rollback: no invitation row, no reservation and no orphan visitor row.
        $this->assertSame($invitationsBefore, (int) $this->db->scalar('SELECT COUNT(*) FROM visitor_invitations'), 'A failed parking reservation must not leave an invitation row.');
        $this->assertSame($reservationsBefore, (int) $this->db->scalar('SELECT COUNT(*) FROM parking_reservations'), 'A failed parking reservation must not leave a reservation row.');
        $this->assertSame($visitorsBefore, (int) $this->db->scalar('SELECT COUNT(*) FROM visitors'), 'A rolled-back invitation must not leave an orphan visitor row.');
    }

    public function testSecondReservationForTheSameBayAndWindowIsRejected(): void
    {
        $window = $this->freeWindow();
        $this->keepOnlyVisitorBay(self::BAY_VISITOR);

        $first = $this->createInvitation([
            'parking_required' => true,
            'valid_from' => $window['from'],
            'valid_until' => $window['to'],
        ]);

        $this->assertNotNull($first['parking'] ?? null);

        $this->assertErrorCode('NO_PARKING_AVAILABLE', $this->post('/api/v1/resident/invitations', [
            'visitor_name' => 'Conflict Visitor',
            'visitor_phone' => '+254700090778',
            'household_uuid' => $this->householdUuid('A-001'),
            'purpose' => 'Conflicting window',
            'valid_from' => $window['from'],
            'valid_until' => $window['to'],
            'visitor_count' => 1,
            'parking_required' => true,
        ], true), 409);

        $this->assertSame(
            1,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM parking_reservations r
                 INNER JOIN parking_bays b ON b.id = r.bay_id
                 WHERE b.bay_code = :code AND r.starts_at = :starts",
                ['code' => self::BAY_VISITOR, 'starts' => $window['from']]
            ),
            'Only one reservation may exist for the contested bay/window.'
        );
    }

    public function testVehicleMustBelongToTheHostHousehold(): void
    {
        $brianVehicle = $this->seededVehicle(self::PLATE_BRIAN);
        $this->ensureLoggedInAs(self::JANE);

        $response = $this->post('/api/v1/resident/invitations', [
            'visitor_name' => 'Vehicle Scope Visitor',
            'visitor_phone' => '+254700090779',
            'household_uuid' => $this->householdUuid('A-001'),
            'purpose' => 'Foreign vehicle',
            'valid_from' => Clock::toDb(Clock::now()->modify('-10 minutes')),
            'valid_until' => Clock::toDb(Clock::now()->modify('+2 hours')),
            'visitor_count' => 1,
            'parking_required' => true,
            'vehicle_id' => (string) $brianVehicle['uuid'],
        ], true);

        $this->assertTrue(
            in_array($response->status(), [404, 422], true),
            'Using another household\'s vehicle must be rejected, got HTTP ' . $response->status() . '.'
        );
        $this->assertSame(
            0,
            (int) $this->db->scalar("SELECT COUNT(*) FROM visitor_invitations WHERE purpose = 'Foreign vehicle'"),
            'A rejected vehicle selection must not create an invitation.'
        );
    }

    public function testRevokingTheInvitationCancelsTheReservation(): void
    {
        $window = $this->freeWindow();
        $data = $this->createInvitation([
            'parking_required' => true,
            'valid_from' => $window['from'],
            'valid_until' => $window['to'],
        ]);

        $reservation = $this->reservationForInvitation((string) $data['invitation_uuid']);
        $this->assertNotNull($reservation);

        $this->assertSuccess($this->post(
            '/api/v1/resident/invitations/' . $data['invitation_uuid'] . '/revoke',
            [],
            true
        ));

        $after = $this->reservationForInvitation((string) $data['invitation_uuid']);
        $this->assertNotNull($after);
        $this->assertSame('cancelled', (string) $after['status'], 'Revoking the invitation must cancel its parking reservation.');

        $audit = $this->db->selectOne("SELECT COUNT(*) FROM audit_logs WHERE action = 'parking.reservation_cancelled'");
        $this->assertTrue((int) $audit > 0, 'Reservation cancellation must be audited.');
    }

    public function testParkingIndexShowsAllocationAndSeededReservation(): void
    {
        $data = $this->parkingIndexApi(self::JANE);

        $this->assertArrayHasKey('allocations', $data);
        $this->assertArrayHasKey('reservations', $data);

        $allocationBays = array_map(
            static fn (array $row): string => (string) $row['bay_code'],
            $data['allocations']
        );
        $this->assertContains(self::BAY_RESIDENT, $allocationBays, 'Jane must see her seeded R-01 allocation.');
        $this->assertNotContains('R-02', $allocationBays, 'Brian\'s R-02 allocation must not be visible to Jane.');

        $reservationBays = array_map(
            static fn (array $row): string => (string) $row['bay_code'],
            $data['reservations']
        );
        $this->assertContains(self::BAY_VISITOR, $reservationBays, 'Jane must see the seeded V-01 visitor reservation.');

        $allocation = $data['allocations'][0];
        foreach (['uuid', 'bay_code', 'zone_name', 'status', 'status_label', 'status_tone', 'vehicle'] as $key) {
            $this->assertArrayHasKey($key, $allocation, 'Allocation shape must expose ' . $key . '.');
        }
        $this->assertSame(self::PLATE_JANE, (string) ($allocation['vehicle']['plate_number'] ?? ''));
    }

    public function testPassDataIncludesParkingAndPublicPageShowsOnlyTheBayCode(): void
    {
        // The seeded upcoming invitation "Demo Visitor Three" has the V-01 reservation.
        $seeded = $this->seededInvitation(DemoVisitorSeeder::INVITATION_UPCOMING_UUID);

        $this->ensureLoggedInAs(self::JANE);
        $passScreen = $this->get('/app/passes/' . $seeded['uuid']);
        $this->assertStatus(200, $passScreen);
        $this->assertBodyContains(self::BAY_VISITOR, $passScreen, 'The resident pass screen must show the reserved bay.');

        $token = $this->db->scalar(
            'SELECT encrypted_secret FROM access_credentials WHERE invitation_id = :id AND credential_type = :type LIMIT 1',
            ['id' => (int) $seeded['id'], 'type' => 'qr']
        );
        $this->assertNotEmpty($token);

        // Public pass page: bay code only, never a plate or resident PII.
        $credential = $this->db->selectOne(
            "SELECT uuid, encrypted_secret FROM access_credentials WHERE invitation_id = :id AND credential_type = 'qr' LIMIT 1",
            ['id' => (int) $seeded['id']]
        );
        $this->assertNotNull($credential);
        $qrToken = $this->qrTokenFor((string) $credential['uuid']);

        $public = $this->get('/v/' . $qrToken);
        $this->assertStatus(200, $public);
        $this->assertBodyContains('Parking', $public);
        $this->assertBodyContains(self::BAY_VISITOR, $public);
        $this->assertBodyContains('Demo Visitor Three', $public);

        foreach ([self::PLATE_JANE, '+254700000010', 'Jane Smith'] as $forbidden) {
            $this->assertBodyNotContains($forbidden, $public, 'The public pass page must not expose ' . $forbidden . '.');
        }
    }
}
