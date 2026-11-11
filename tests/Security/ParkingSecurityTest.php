<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Clock;
use Tests\Integration\ParkingModuleTestCase;

/**
 * Vehicles & parking security (contract §2, §5): CSRF on every new POST, XSS on
 * new free-text fields, tenant isolation for vehicles/allocations/reservations/
 * sessions and replay/duplicate safety.
 */
final class ParkingSecurityTest extends ParkingModuleTestCase
{
    public function testCsrfIsEnforcedOnEveryNewPost(): void
    {
        $vehicle = $this->seededVehicle(self::PLATE_JANE);
        $this->ensureLoggedInAs(self::JANE);

        // Browser (HTML) posts answer with the rendered 403 error page…
        foreach ([
            ['/app/vehicles', ['plate_number' => 'KDC0001', 'vehicle_type' => 'car']],
            ['/app/vehicles/' . $vehicle['uuid'] . '/deactivate', []],
        ] as [$path, $body]) {
            $response = $this->post($path, $body, false);
            $this->assertStatus(403, $response);
            $this->assertBodyContains('CSRF_TOKEN_INVALID', $response);
        }

        // …while API posts answer with the JSON envelope.
        foreach ([
            ['/api/v1/resident/vehicles', ['plate_number' => 'KDC0002']],
            ['/api/v1/resident/vehicles/' . $vehicle['uuid'] . '/deactivate', []],
        ] as [$path, $body]) {
            $this->assertErrorCode('CSRF_TOKEN_INVALID', $this->post($path, $body, false), 403);
        }

        // Guard parking APIs too.
        $this->loginGuard();
        foreach ([
            ['/api/v1/gate/parking/sessions/checkin', ['invitation_uuid' => $this->newUuid()]],
            ['/api/v1/gate/parking/sessions/checkout', ['invitation_uuid' => $this->newUuid()]],
        ] as [$path, $body]) {
            $this->assertErrorCode('CSRF_TOKEN_INVALID', $this->post($path, $body, false), 403);
        }

        // Nothing was written by the rejected requests.
        $this->assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM vehicles WHERE plate_number LIKE 'KDC000%'"));
    }

    public function testVehicleFreeTextFieldsAreEscapedOnTheResidentScreen(): void
    {
        $payload = '<script>alert("vehicle-colour")</script>';
        $this->vehicleCreateApi([
            'plate_number' => 'KDE 123',
            'vehicle_type' => 'car',
            'colour' => $payload,
        ]);

        $this->ensureLoggedInAs(self::JANE);
        $page = $this->get('/app/vehicles');
        $this->assertStatus(200, $page);

        $this->assertFalse(str_contains($page->body(), $payload), 'The vehicle colour must be HTML-escaped.');
        $this->assertTrue(
            str_contains($page->body(), '&lt;script&gt;alert(&quot;vehicle-colour&quot;)&lt;/script&gt;')
            || str_contains($page->body(), 'alert(&quot;vehicle-colour&quot;)'),
            'The escaped colour payload should be rendered as inert text.'
        );
        $this->assertFalse(str_contains($page->body(), 'onerror="alert('), 'Attributes must stay attribute-safe.');
    }

    public function testTenantIsolationForVehicles(): void
    {
        $janeVehicle = $this->seededVehicle(self::PLATE_JANE);
        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);

        $list = $this->get('/api/v1/resident/vehicles');
        $this->assertTrue(in_array($list->status(), [200, 403, 404], true));
        $this->assertFalse(str_contains($list->body(), self::PLATE_JANE), 'Riverside must never see a Greenview plate.');

        $deactivate = $this->post('/api/v1/resident/vehicles/' . $janeVehicle['uuid'] . '/deactivate', [], true);
        $this->assertTrue(in_array($deactivate->status(), [403, 404], true), 'Cross-tenant deactivate must be denied.');

        $page = $this->get('/app/vehicles');
        $this->assertTrue(in_array($page->status(), [200, 403], true));
        $this->assertFalse(str_contains($page->body(), self::PLATE_JANE));
    }

    public function testTenantIsolationForParkingData(): void
    {
        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);

        $parking = $this->get('/api/v1/resident/parking');
        if ($parking->status() === 200) {
            $body = $parking->body();
            $this->assertFalse(str_contains($body, 'R-01'), 'Riverside must not see Greenview resident bays.');
            $this->assertFalse(str_contains($body, 'V-01'), 'Riverside must not see Greenview visitor bays.');
        } else {
            $this->assertTrue(in_array($parking->status(), [403, 404, 422], true));
        }

        $availability = $this->get('/api/v1/parking/availability?' . http_build_query([
            'from' => Clock::toDb(Clock::now()->modify('+1 day')->setTime(10, 0)),
            'to' => Clock::toDb(Clock::now()->modify('+1 day')->setTime(14, 0)),
        ]));
        if ($availability->status() === 200) {
            $this->assertFalse(str_contains($availability->body(), 'V-0'), 'Riverside must not see Greenview visitor bays.');
        } else {
            $this->assertTrue(in_array($availability->status(), [403, 404], true));
        }

        $this->assertStatus(403, $this->guardParkingActive());
    }

    public function testDuplicateVehicleCreationIsRejectedWithoutPartialWrites(): void
    {
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM vehicles');

        $this->assertSuccess($this->vehicleCreateApi(['plate_number' => 'KDF314']));

        $duplicate = $this->post('/api/v1/resident/vehicles', ['plate_number' => 'KDF-314'], true);
        $this->assertErrorCode('PLATE_ALREADY_REGISTERED', $duplicate, 409);

        $this->assertSame($before + 1, (int) $this->db->scalar('SELECT COUNT(*) FROM vehicles'), 'The duplicate attempt must not persist a second row.');
    }

    public function testReservationReplayIsRejectedWithoutLeakingOrphanRows(): void
    {
        // Occupy the only visitor bay for a fresh window, then replay the same
        // request: the second attempt must be rejected and leave no invitation.
        $windowFrom = Clock::toDb(Clock::now()->modify('+3 days')->setTime(9, 0));
        $windowTo = Clock::toDb(Clock::now()->modify('+3 days')->setTime(11, 0));

        $this->db->execute(
            "UPDATE parking_bays SET status = 'maintenance' WHERE estate_id = :estate_id AND bay_type = 'visitor' AND bay_code <> :keep",
            ['estate_id' => (int) $this->estate()['id'], 'keep' => self::BAY_VISITOR]
        );

        $body = [
            'visitor_name' => 'Replay Visitor',
            'visitor_phone' => '+254700090780',
            'household_uuid' => $this->householdUuid('A-001'),
            'purpose' => 'Replay',
            'valid_from' => $windowFrom,
            'valid_until' => $windowTo,
            'visitor_count' => 1,
            'parking_required' => true,
        ];

        $this->ensureLoggedInAs(self::JANE);
        $this->assertSuccess($this->post('/api/v1/resident/invitations', $body, true));

        $invitationsBefore = (int) $this->db->scalar('SELECT COUNT(*) FROM visitor_invitations');
        $this->assertErrorCode('NO_PARKING_AVAILABLE', $this->post('/api/v1/resident/invitations', $body, true), 409);
        $this->assertSame($invitationsBefore, (int) $this->db->scalar('SELECT COUNT(*) FROM visitor_invitations'));
    }
}
