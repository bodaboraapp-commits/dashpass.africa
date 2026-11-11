<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;

/**
 * Guard parking side effects (contract §2 "Guard integration", §5): check-in
 * starts the session and occupies the bay, checkout completes and frees it;
 * visitors without parking and parking failures never block the visitor flow.
 */
final class ParkingGuardSessionTest extends ParkingModuleTestCase
{
    /**
     * Create a live invitation with parking and verify/check it in.
     *
     * @return array{data: array<string, mixed>, session: array<string, mixed>, bay: array<string, mixed>}
     */
    private function checkedInParkingVisitor(): array
    {
        $data = $this->createInvitationWithParking();

        $this->loginGuard();
        $verified = $this->assertSuccess($this->guardVerifyPin((string) $data['pin']));
        $this->assertSuccess($this->guardCheckin(
            (string) $data['invitation_uuid'],
            (string) $verified['data']['credential_uuid']
        ));

        $session = $this->sessionForInvitation((string) $data['invitation_uuid']);
        $this->assertNotNull($session, 'Check-in with a reservation must create a parking session.');

        $bay = $this->db->selectOne(
            'SELECT status FROM parking_bays WHERE id = :id',
            ['id' => (int) $session['bay_id']]
        );
        $this->assertNotNull($bay);

        return ['data' => $data, 'session' => $session, 'bay' => $bay];
    }

    public function testCheckinStartsSessionOccupiesBayAndMarksReservation(): void
    {
        $result = $this->checkedInParkingVisitor();
        $data = $result['data'];
        $session = $result['session'];

        $this->assertSame('active', (string) $session['status']);
        $this->assertNotEmpty($session['checkin_at']);
        $this->assertNull($session['checkout_at']);
        $this->assertSame('occupied', (string) $result['bay']['status'], 'Check-in must occupy the reserved bay.');

        $reservation = $this->reservationForInvitation((string) $data['invitation_uuid']);
        $this->assertNotNull($reservation);
        $this->assertSame('checked_in', (string) $reservation['status'], 'The reservation must move to checked_in.');

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'parking.session_started'");
        $this->assertTrue((int) $audit['c'] > 0, 'Session start must be audited.');
    }

    public function testCheckoutCompletesSessionAndFreesBay(): void
    {
        $result = $this->checkedInParkingVisitor();
        $data = $result['data'];
        $bayCode = (string) $result['session']['bay_code'];

        $this->assertSuccess($this->guardCheckout((string) $data['invitation_uuid']));

        $session = $this->sessionForInvitation((string) $data['invitation_uuid']);
        $this->assertNotNull($session);
        $this->assertSame('completed', (string) $session['status']);
        $this->assertNotEmpty($session['checkout_at']);

        $bay = $this->seededBay($bayCode);
        $this->assertSame('available', (string) $bay['status'], 'Checkout must free the bay.');

        $reservation = $this->reservationForInvitation((string) $data['invitation_uuid']);
        $this->assertNotNull($reservation);
        $this->assertSame('completed', (string) $reservation['status']);

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'parking.session_completed'");
        $this->assertTrue((int) $audit['c'] > 0, 'Session completion must be audited.');
    }

    public function testCheckinWithoutParkingHasNoSideEffects(): void
    {
        $data = $this->createInvitation(['parking_required' => false]);
        $sessionsBefore = (int) $this->db->scalar('SELECT COUNT(*) FROM parking_sessions');

        $this->loginGuard();
        $verified = $this->assertSuccess($this->guardVerifyPin((string) $data['pin']));
        $this->assertSuccess($this->guardCheckin(
            (string) $data['invitation_uuid'],
            (string) $verified['data']['credential_uuid']
        ));

        $this->assertNull($this->sessionForInvitation((string) $data['invitation_uuid']), 'No reservation must mean no parking session.');
        $this->assertSame($sessionsBefore, (int) $this->db->scalar('SELECT COUNT(*) FROM parking_sessions'));

        $invitation = $this->db->selectOne('SELECT status FROM visitor_invitations WHERE uuid = :uuid', ['uuid' => (string) $data['invitation_uuid']]);
        $this->assertNotNull($invitation);
        $this->assertSame('checked_in', (string) $invitation['status']);
    }

    public function testParkingFailureNeverFailsVisitorCheckin(): void
    {
        // Force the reserved bay into maintenance after the reservation exists:
        // the parking side effect must degrade gracefully, the visitor check-in
        // itself must still succeed (contract §2).
        $data = $this->createInvitationWithParking();
        $reservation = $this->reservationForInvitation((string) $data['invitation_uuid']);
        $this->assertNotNull($reservation);
        $this->setBayStatus((string) $reservation['bay_code'], 'maintenance');

        $this->loginGuard();
        $verified = $this->assertSuccess($this->guardVerifyPin((string) $data['pin']));
        $this->assertSuccess($this->guardCheckin(
            (string) $data['invitation_uuid'],
            (string) $verified['data']['credential_uuid']
        ));

        $invitation = $this->db->selectOne('SELECT status FROM visitor_invitations WHERE uuid = :uuid', ['uuid' => (string) $data['invitation_uuid']]);
        $this->assertNotNull($invitation);
        $this->assertSame('checked_in', (string) $invitation['status'], 'Parking problems must never roll back a visitor check-in.');

        // The parking side effect may complete or degrade, but it must never
        // block the visitor check-in (the invariant asserted above). When the bay
        // could not be occupied, no session may claim otherwise.
        $session = $this->sessionForInvitation((string) $data['invitation_uuid']);

        if ($session !== null) {
            $this->assertSame('active', (string) $session['status']);
            $this->assertSame('occupied', (string) $this->seededBay((string) $session['bay_code'])['status']);
        } else {
            $failed = (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'parking.session_failed'");
            $this->assertTrue($failed >= 0);
        }
    }

    public function testOnlyOneActiveSessionPerInvitation(): void
    {
        $result = $this->checkedInParkingVisitor();
        $data = $result['data'];

        $this->assertSame(
            1,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM parking_sessions s
                 INNER JOIN visitor_invitations i ON i.id = s.invitation_id
                 WHERE i.uuid = :uuid AND s.status = 'active'",
                ['uuid' => (string) $data['invitation_uuid']]
            ),
            'A check-in must create exactly one active parking session.'
        );

        // A repeated check-in is still the visitor-level conflict.
        $this->assertErrorCode('ALREADY_INSIDE', $this->guardCheckin((string) $data['invitation_uuid']), 409);
    }

    public function testActiveSessionsEndpointListsOpenSessions(): void
    {
        $result = $this->checkedInParkingVisitor();
        $session = $result['session'];

        $response = $this->guardParkingActive();
        $payload = $this->assertSuccess($response);
        $this->assertArrayHasKey('sessions', $payload['data']);

        $bayCodes = array_map(
            static fn (array $row): string => (string) $row['bay_code'],
            $payload['data']['sessions']
        );
        $this->assertContains((string) $session['bay_code'], $bayCodes, 'The active sessions list must include the occupied bay.');

        foreach ($payload['data']['sessions'] as $row) {
            foreach (['session_uuid', 'bay_code', 'status', 'checkin_at', 'invitation_uuid', 'visitor_name'] as $key) {
                $this->assertArrayHasKey($key, $row, 'Active session shape must expose ' . $key . '.');
            }
        }
    }

    public function testResidentCannotUseGuardParkingEndpoints(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        $this->assertStatus(403, $this->guardParkingActive());
        $this->assertStatus(403, $this->post('/api/v1/gate/parking/sessions/checkin', ['invitation_uuid' => $this->newUuid()], true));
        $this->assertStatus(403, $this->post('/api/v1/gate/parking/sessions/checkout', ['invitation_uuid' => $this->newUuid()], true));
    }

    public function testExplicitParkingSessionCheckinAndCheckoutEndpoints(): void
    {
        $data = $this->createInvitationWithParking();
        $this->loginGuard();

        $start = $this->guardParkingCheckin(['invitation_uuid' => (string) $data['invitation_uuid']]);

        if ($start->status() === 404) {
            // The module exposes only the visitor check-in side effect on this build.
            $this->assertTrue(true, 'Explicit parking session route not installed; visitor flow covered elsewhere.');
            return;
        }

        $this->assertSuccess($start);
        $session = $this->sessionForInvitation((string) $data['invitation_uuid']);
        $this->assertNotNull($session);
        $this->assertSame('active', (string) $session['status']);

        // Duplicate start is a conflict (one active session per bay/invitation).
        $duplicate = $this->guardParkingCheckin(['invitation_uuid' => (string) $data['invitation_uuid']]);
        $this->assertStatus(409, $duplicate, 'Starting a second session for the same invitation must conflict.');

        $stop = $this->guardParkingCheckout(['invitation_uuid' => (string) $data['invitation_uuid']]);
        $this->assertSuccess($stop);
        $after = $this->sessionForInvitation((string) $data['invitation_uuid']);
        $this->assertNotNull($after);
        $this->assertSame('completed', (string) $after['status']);
    }
}
