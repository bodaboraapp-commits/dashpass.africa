<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;

/**
 * Parking availability (contract §2 "Parking availability", §5): visitor bays in
 * the active estate excluding maintenance, overlapping reservations and active
 * sessions; window filtering and validation.
 */
final class ParkingAvailabilityTest extends ParkingModuleTestCase
{
    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function baysByCode(array $data): array
    {
        $map = [];

        foreach ($data['bays'] as $bay) {
            $map[(string) $bay['bay_code']] = $bay;
        }

        return $map;
    }

    public function testAvailabilityReturnsVisitorBaysWithTheCanonicalShape(): void
    {
        $data = $this->availabilityApi();

        $this->assertArrayHasKey('bays', $data);
        $this->assertArrayHasKey('from', $data);
        $this->assertArrayHasKey('to', $data);

        $bays = $this->baysByCode($data);
        $this->assertNotEmpty($bays, 'Visitor bays must be returned for the seeded estate.');

        foreach ($bays as $bay) {
            foreach (['uuid', 'bay_code', 'zone_name', 'bay_type', 'status', 'available'] as $key) {
                $this->assertArrayHasKey($key, $bay, 'Availability bay shape must expose ' . $key . '.');
            }

            $this->assertMatches('/^V-\d{2}$/', (string) $bay['bay_code'], 'Only visitor-zone bays are offered for visitor parking.');
            $this->assertTrue(is_bool($bay['available']), 'available must be a boolean.');
        }
    }

    public function testMaintenanceBaysAreExcluded(): void
    {
        $bays = $this->baysByCode($this->availabilityApi());

        $this->assertArrayNotHasKey(self::BAY_MAINTENANCE, $bays, 'A maintenance bay must never be offered.');
        $this->assertArrayHasKey(self::BAY_ACCESSIBLE, $bays, 'An accessible visitor bay must be offered.');
        $this->assertSame('accessible', (string) $bays[self::BAY_ACCESSIBLE]['bay_type']);
    }

    public function testResidentBaysAreNotOfferedForVisitorParking(): void
    {
        $codes = array_keys($this->baysByCode($this->availabilityApi()));

        $this->assertNotContains(self::BAY_RESIDENT, $codes, 'Resident bays must not appear in visitor availability.');
        $this->assertNotContains('R-02', $codes);
    }

    public function testOverlappingReservationMakesBayUnavailable(): void
    {
        // The seeder reserves V-01 for the upcoming demo invitation tomorrow 10:00–14:00.
        $bays = $this->baysByCode($this->availabilityApi());

        $this->assertArrayHasKey(self::BAY_VISITOR, $bays);
        $this->assertFalse((bool) $bays[self::BAY_VISITOR]['available'], 'A bay with an overlapping reservation must be unavailable.');
    }

    public function testNonOverlappingWindowSeesTheBayAvailable(): void
    {
        // The seeded reservation ends at 14:00; [starts_at, ends_at) is half-open.
        $bays = $this->baysByCode($this->availabilityApi([
            'from' => Clock::toDb(Clock::now()->modify('+1 day')->setTime(14, 0)),
            'to' => Clock::toDb(Clock::now()->modify('+1 day')->setTime(16, 0)),
        ]));

        $this->assertTrue((bool) $bays[self::BAY_VISITOR]['available'], 'A reservation ending exactly at the window start must not block the bay.');
    }

    public function testWindowBoundaryIsHalfOpen(): void
    {
        // Starts 13:59 — overlaps the final minute of the 10:00–14:00 reservation.
        $before = $this->baysByCode($this->availabilityApi([
            'from' => Clock::toDb(Clock::now()->modify('+1 day')->setTime(13, 59)),
            'to' => Clock::toDb(Clock::now()->modify('+1 day')->setTime(15, 0)),
        ]));
        $this->assertFalse((bool) $before[self::BAY_VISITOR]['available'], 'A window overlapping the reservation must see it unavailable.');

        // Starts exactly at 15:00 — no overlap.
        $after = $this->baysByCode($this->availabilityApi([
            'from' => Clock::toDb(Clock::now()->modify('+1 day')->setTime(15, 0)),
            'to' => Clock::toDb(Clock::now()->modify('+1 day')->setTime(16, 0)),
        ]));
        $this->assertTrue((bool) $after[self::BAY_VISITOR]['available']);
    }

    public function testActiveSessionMakesBayUnavailable(): void
    {
        // Create a live invitation with parking and check the visitor in.
        $data = $this->createInvitationWithParking();
        $bayCode = (string) $data['parking']['bay_code'];

        $this->loginGuard();
        $verified = $this->assertSuccess($this->guardVerifyPin((string) $data['pin']));
        $this->assertSuccess($this->guardCheckin(
            (string) $data['invitation_uuid'],
            (string) $verified['data']['credential_uuid']
        ));

        $now = $this->baysByCode($this->availabilityApi([
            'from' => Clock::toDb(Clock::now()->modify('-30 minutes')),
            'to' => Clock::toDb(Clock::now()->modify('+30 minutes')),
        ]));

        $this->assertFalse((bool) $now[$bayCode]['available'], 'A bay with an active parking session must be unavailable.');

        $this->assertSuccess($this->guardCheckout((string) $data['invitation_uuid']));

        $after = $this->baysByCode($this->availabilityApi([
            'from' => Clock::toDb(Clock::now()->modify('-30 minutes')),
            'to' => Clock::toDb(Clock::now()->modify('+30 minutes')),
        ]));
        $this->assertTrue((bool) $after[$bayCode]['available'], 'Completing the session must free the bay again.');
    }

    public function testAvailabilityRequiresAValidWindow(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        $missing = $this->get('/api/v1/parking/availability');
        $this->assertErrorCode('VALIDATION_FAILED', $missing, 422);

        $inverted = $this->get('/api/v1/parking/availability?' . http_build_query([
            'from' => Clock::toDb(Clock::now()->modify('+3 hours')),
            'to' => Clock::toDb(Clock::now()->modify('+1 hour')),
        ]));
        $this->assertErrorCode('VALIDATION_FAILED', $inverted, 422);

        $garbage = $this->get('/api/v1/parking/availability?' . http_build_query([
            'from' => 'not-a-date',
            'to' => 'also-not-a-date',
        ]));
        $this->assertErrorCode('VALIDATION_FAILED', $garbage, 422);
    }

    public function testAvailabilityIsEstateScoped(): void
    {
        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);

        $response = $this->get('/api/v1/parking/availability?' . http_build_query([
            'from' => Clock::toDb(Clock::now()->modify('+1 day')->setTime(10, 0)),
            'to' => Clock::toDb(Clock::now()->modify('+1 day')->setTime(14, 0)),
        ]));

        if ($response->status() === 200) {
            $data = json_decode((string) $response->body(), true);
            $codes = array_map(
                static fn (array $bay): string => (string) $bay['bay_code'],
                is_array($data['data']['bays'] ?? null) ? $data['data']['bays'] : []
            );

            $this->assertNotContains(self::BAY_VISITOR, $codes, 'A foreign tenant must never see Greenview bays.');
        } else {
            $this->assertTrue(in_array($response->status(), [403, 404], true));
        }
    }

    public function testGuardCannotReadResidentParkingAvailability(): void
    {
        $this->loginGuard();

        $this->assertStatus(403, $this->get('/api/v1/parking/availability?' . http_build_query([
            'from' => Clock::toDb(Clock::now()),
            'to' => Clock::toDb(Clock::now()->modify('+1 hour')),
        ])));
    }
}
