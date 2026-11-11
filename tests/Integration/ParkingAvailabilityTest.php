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
            'from' => $this->estateMoment('+1 day 14:00'),
            'to' => $this->estateMoment('+1 day 16:00'),
        ]));

        $this->assertTrue((bool) $bays[self::BAY_VISITOR]['available'], 'A reservation ending exactly at the window start must not block the bay.');
    }

    public function testWindowBoundaryIsHalfOpen(): void
    {
        // Starts 13:59 — overlaps the final minute of the 10:00–14:00 reservation.
        $before = $this->baysByCode($this->availabilityApi([
            'from' => $this->estateMoment('+1 day 13:59'),
            'to' => $this->estateMoment('+1 day 15:00'),
        ]));
        $this->assertFalse((bool) $before[self::BAY_VISITOR]['available'], 'A window overlapping the reservation must see it unavailable.');

        // Starts exactly at 15:00 — no overlap.
        $after = $this->baysByCode($this->availabilityApi([
            'from' => $this->estateMoment('+1 day 15:00'),
            'to' => $this->estateMoment('+1 day 16:00'),
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
            'from' => $this->estateMoment('-30 minutes'),
            'to' => $this->estateMoment('+30 minutes'),
        ]));

        $this->assertFalse((bool) $now[$bayCode]['available'], 'A bay with an active parking session must be unavailable.');

        // Re-establish the guard session before checking out.
        $this->loginGuard();
        $this->assertSuccess($this->guardCheckout((string) $data['invitation_uuid']));

        $after = $this->baysByCode($this->availabilityApi([
            'from' => $this->estateMoment('-30 minutes'),
            'to' => $this->estateMoment('+30 minutes'),
        ]));
        $this->assertTrue((bool) $after[$bayCode]['available'], 'Completing the session must free the bay again.');
    }

    public function testInvalidOrMissingWindowsFallBackToADefaultWindow(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        foreach ([[] , ['from' => 'not-a-date', 'to' => 'also-not-a-date'], ['from' => $this->estateMoment('+3 hours'), 'to' => $this->estateMoment('+1 hour')]] as $query) {
            $url = '/api/v1/parking/availability' . ($query === [] ? '' : '?' . http_build_query($query));
            $response = $this->get($url);
            $this->assertStatus(200, $response, 'Invalid/missing availability windows must fall back, never 500: ' . $url);

            $data = $this->assertSuccess($response)['data'];
            $this->assertArrayHasKey('bays', $data);
            $this->assertNotEmpty($data['from']);
            $this->assertNotEmpty($data['to']);
            $this->assertTrue(strtotime((string) $data['to']) > strtotime((string) $data['from']), 'A fallback window must be ordered from < to.');
        }
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

    public function testGuardCanReadAvailabilityButNeverSeesResidentBays(): void
    {
        // The guard role holds parking.view; availability must stay visitor-only.
        $this->loginGuard();

        $response = $this->get('/api/v1/parking/availability?' . http_build_query([
            'from' => $this->estateMoment('+1 day 10:00'),
            'to' => $this->estateMoment('+1 day 14:00'),
        ]));

        if ($response->status() === 200) {
            $body = $response->body();
            $this->assertFalse(str_contains($body, 'R-0'), 'Guard availability must never include resident bays.');
        } else {
            $this->assertStatus(403, $response);
        }
    }
}
