<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;
use DOMElement;

/**
 * Bookings screen after Increment 3 (vehicles-parking-contract §4): the Facilities
 * tab shows REAL visitor-bay availability and My Bookings shows REAL parking
 * reservations. No preview rows/chip remain.
 *
 * Frozen view data: `bookings_tab` ('facilities'|'bookings'),
 * `visitor_bays[]` {uuid,bay_code,zone_name,bay_type,status,available},
 * `availability_from`/`availability_to` (ISO), `reservations[]`; query
 * `?tab=facilities&from=&to=`.
 */
final class ResidentPwaBookingsTest extends ParkingModuleTestCase
{
    private function bookings(string $query = ''): Response
    {
        $this->ensureLoggedInAs(self::JANE);

        return $this->get('/app/bookings' . $query);
    }

    private function activeSegmentTab(Response $response): ?\DOMElement
    {
        $dom = $this->dom($response);
        $nodes = $this->xpath('//*[contains(@class,"seg-tab--active")]', $dom);

        if ($nodes->length === 0) {
            $nodes = $this->xpath('//*[contains(@class,"seg-tab") and @aria-current="page"]', $dom);
        }

        $node = $nodes->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    private function assertActiveTab(Response $response, string $label): void
    {
        $active = $this->activeSegmentTab($response);
        $this->assertNotNull($active, 'The ' . $label . ' tab must be marked active.');
        $this->assertSame($label, $this->text($active));
        $this->assertSame('page', strtolower($active->getAttribute('aria-current')));
    }

    public function testFacilitiesTabShowsRealVisitorBays(): void
    {
        $response = $this->bookings();
        $this->assertStatus(200, $response);

        $h1s = $this->xpath('//h1', $this->dom($response));
        $this->assertCount(1, $h1s);
        $h1 = $h1s->item(0);
        $this->assertNotNull($h1);
        $this->assertSame('Bookings', $this->text($h1));

        $this->assertActiveTab($response, 'Facilities');

        // Real seeded visitor bays, not preview facilities.
        foreach ([self::BAY_VISITOR, 'V-02', 'V-03', 'V-04', self::BAY_ACCESSIBLE] as $bayCode) {
            $this->assertTextContains($bayCode, $response, 'Visitor bay ' . $bayCode . ' must be rendered from real data.');
        }

        // Maintenance bays are not offered by the availability data.
        $this->assertTextNotContains(self::BAY_MAINTENANCE, $response, 'A maintenance bay must not be offered in Facilities.');

        // No preview rows/chip or fabricated facility content remains.
        $this->assertTextNotContains('Layout preview', $response);
        $this->assertTextNotContains('Few Slots Left', $response);
        $this->assertCount(0, $this->xpath('//p[contains(@class,"preview-note")]', $this->dom($response)));
        $this->assertCount(0, $this->xpath('//li[contains(@class,"facility-card")]', $this->dom($response)), 'Preview facility cards must be gone.');
    }

    public function testFacilitiesTabHasRealAvailabilityWindowControls(): void
    {
        $response = $this->bookings();
        $dom = $this->dom($response);

        // The availability window must be real (form controls or a form/GET link
        // carrying from/to), never a fake confirmation.
        $inputs = $this->xpath('//input[@type="datetime-local" or @type="date" or @type="time"]', $dom);
        $links = $this->xpath('//a[contains(@href,"from=") and contains(@href,"to=")]', $dom);
        $form = $this->xpath('//form[contains(@action,"/app/bookings")]', $dom);

        $this->assertTrue(
            $inputs->length > 0 || $links->length > 0 || $form->length > 0,
            'Facilities must expose a real availability window control.'
        );
    }

    public function testWindowQueryIsAcceptedAndReflected(): void
    {
        $response = $this->bookings('?tab=facilities&from=2026-11-12T10:00&to=2026-11-12T14:00');
        $this->assertStatus(200, $response);
        $this->assertTextContains('V-01', $response);

        // Invalid window values must not error — fall back to a sane default window.
        $invalid = $this->bookings('?tab=facilities&from=not-a-date&to=also-not-a-date');
        $this->assertStatus(200, $invalid);
        $this->assertTextContains('V-01', $invalid);
    }

    public function testMyBookingsTabShowsRealSeededReservation(): void
    {
        $response = $this->bookings('?tab=bookings');
        $this->assertStatus(200, $response);

        $this->assertActiveTab($response, 'My Bookings');

        // The seeder reserves V-01 for "Demo Visitor Three".
        $this->assertTextContains('Demo Visitor Three', $response, 'My Bookings must show the real seeded reservation.');
        $this->assertTextContains(self::BAY_VISITOR, $response, 'My Bookings must show the reserved bay code.');
        $this->assertTextContains('Visitor Zone V', $response);

        // No preview facility cards/handlers on this tab.
        $this->assertCount(0, $this->xpath('//li[contains(@class,"facility-card")]', $this->dom($response)));
        $this->assertTextNotContains('Layout preview', $response);
    }

    public function testTabsAreRealServerSideLinks(): void
    {
        $response = $this->bookings();
        $dom = $this->dom($response);

        $facilities = $this->xpath('//a[@href="/app/bookings" or contains(@href,"/app/bookings?tab=facilities")]', $dom);
        $myBookings = $this->xpath('//a[contains(@href,"/app/bookings?tab=bookings")]', $dom);

        $this->assertTrue($facilities->length >= 1, 'Facilities must be a real server-side link.');
        $this->assertTrue($myBookings->length >= 1, 'My Bookings must be a real server-side link.');

        $myBookingsNode = $myBookings->item(0);
        $this->assertNotNull($myBookingsNode);
        $this->assertSame('My Bookings', $this->text($myBookingsNode));
    }

    public function testInvalidTabFallsBackToFacilities(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        foreach (['?tab=bogus', '?tab=BOOKINGS', '?tab=', '?tab=0'] as $query) {
            $response = $this->get('/app/bookings' . $query);
            $this->assertStatus(200, $response, 'Invalid tab must not error: ' . $query);
            $this->assertActiveTab($response, 'Facilities');
            $this->assertTextContains('V-01', $response);
        }
    }

    public function testResidentWithoutReservationsGetsAnHonestEmptyState(): void
    {
        // Amina (B-001) has an allocation but no seeded visitor reservation.
        $this->ensureLoggedInAs(self::AMINA);

        $response = $this->get('/app/bookings?tab=bookings');
        $this->assertStatus(200, $response);

        $this->assertTextNotContains('Demo Visitor Three', $response);
        $this->assertTextNotContains(self::BAY_VISITOR, $response);

        $lower = strtolower($this->pageText($response));
        $this->assertTrue(
            str_contains($lower, 'no booking') || str_contains($lower, 'no reservation') || str_contains($lower, 'nothing'),
            'A resident without parking reservations must see an honest empty state.'
        );
    }

    public function testBookingsPageIsCspSafeAndAccessible(): void
    {
        $response = $this->bookings();

        $this->assertCleanMarkup($response, 'Bookings');

        $back = $this->xpath('//a[@href="/app"][@aria-label or @title]', $this->dom($response));
        $this->assertTrue($back->length >= 1, 'Bookings must offer a labelled back link to /app.');

        $cards = $this->xpath('//li[contains(@class,"bay-card") or contains(@class,"parking-card") or contains(@class,"reservation-card")]', $this->dom($response));
        if ($cards->length > 0) {
            foreach ($cards as $card) {
                if ($card instanceof DOMElement) {
                    $this->assertTrue(trim($this->text($card)) !== '', 'Parking cards must render readable text.');
                }
            }
        }
    }

}
