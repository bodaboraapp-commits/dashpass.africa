<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;
use DOMElement;

/**
 * Bookings screen (addendum §2C, §7.4): Facilities tab active by default with
 * four facility cards and text statuses, My Bookings honest empty state, valid
 * server-side tabs, CTA and preview-chip behaviour.
 */
final class ResidentPwaBookingsTest extends ResidentPwaTestCase
{
    private function bookings(string $query = ''): Response
    {
        $this->loginResident();

        return $this->get('/app/bookings' . $query);
    }

    private function assertActiveTab(Response $response, string $label): void
    {
        $active = $this->activeSegmentTab($response);
        $this->assertNotNull($active, 'The ' . $label . ' tab must be marked active.');
        $this->assertSame($label, $this->text($active));
        $this->assertSame('page', strtolower($active->getAttribute('aria-current')));
    }

    public function testFacilitiesTabIsDefaultWithFourCardsAndStatuses(): void
    {
        $response = $this->bookings();
        $this->assertStatus(200, $response);

        $h1s = $this->xpath('//h1', $this->dom($response));
        $this->assertCount(1, $h1s);
        $h1 = $h1s->item(0);
        $this->assertNotNull($h1);
        $this->assertSame('Bookings', $this->text($h1));

        $cards = $this->xpath('//li[contains(@class,"facility-card")]', $this->dom($response));
        $this->assertCount(4, $cards, 'Facilities must render four facility cards.');

        $names = [];

        foreach ($cards as $card) {
            if ($card instanceof DOMElement) {
                $names[] = $this->nodeText($card, './/*[contains(@class,"facility-card__name")]');
            }
        }

        $this->assertSame(self::FACILITIES, $names, 'Facility cards must render the four preview facilities in order.');

        $dom = $this->dom($response);
        $statuses = $this->xpath('//span[contains(@class,"facility-status") and not(contains(@class,"__"))]', $dom);
        $this->assertCount(4, $statuses, 'Each facility card must render a status line.');

        $ok = 0;
        $warn = 0;

        foreach ($statuses as $status) {
            if (!$status instanceof DOMElement) {
                continue;
            }

            $class = $status->getAttribute('class');
            $text = $this->text($status);

            if (str_contains($class, 'facility-status--warn')) {
                $warn++;
                $this->assertSame('Few Slots Left', $text, 'The warning status must pair colour with the text "Few Slots Left".');
            } elseif (str_contains($class, 'facility-status--ok')) {
                $ok++;
                $this->assertSame('Available', $text, 'The success status must pair colour with the text "Available".');
            }
        }

        $this->assertSame(3, $ok, 'Three facilities must be Available.');
        $this->assertSame(1, $warn, 'Exactly one facility must be few-slots-left.');

        $cta = $this->xpath('//button[contains(normalize-space(.),"+ New Booking")]', $dom);
        $this->assertCount(1, $cta, 'The sticky CTA must be "+ New Booking".');
        $ctaNode = $cta->item(0);
        $this->assertNotNull($ctaNode);
        $this->assertTrue(trim($ctaNode->getAttribute('data-soon')) !== '');
    }

    public function testDefaultActiveTabIsFacilities(): void
    {
        $response = $this->bookings();
        $this->assertActiveTab($response, 'Facilities');

        $dom = $this->dom($response);
        $myBookings = $this->xpath('//a[@href="/app/bookings?tab=bookings"]', $dom);
        $this->assertTrue($myBookings->length >= 1, 'My Bookings must be a real server-side link.');
        $facilities = $this->xpath('//a[@href="/app/bookings"]', $dom);
        $this->assertTrue($facilities->length >= 1, 'Facilities must be a real server-side link.');
    }

    public function testTabsAreRealServerSideLinks(): void
    {
        $response = $this->bookings();
        $tabs = $this->segmentTabs($response);
        $this->assertNotNull($tabs);

        $links = $this->xpath('.//a', $tabs);
        $this->assertCount(2, $links, 'The tab strip must contain exactly two real links.');

        $myBookings = $this->xpath('//a[@href="/app/bookings?tab=bookings"]', $this->dom($response));
        $myBookingsNode = $myBookings->item(0);
        $this->assertNotNull($myBookingsNode);
        $this->assertSame('My Bookings', $this->text($myBookingsNode));
    }

    public function testMyBookingsTabShowsEmptyState(): void
    {
        $response = $this->bookings('?tab=bookings');
        $this->assertStatus(200, $response);

        $dom = $this->dom($response);

        $this->assertCount(0, $this->xpath('//li[contains(@class,"facility-card")]', $dom), 'Facility cards must not render on the My Bookings tab.');

        foreach (self::FACILITIES as $facility) {
            $this->assertTextNotContains($facility, $response);
        }

        $this->assertActiveTab($response, 'My Bookings');

        $emptyTitle = $this->nodeText($dom, '//p[contains(@class,"resident-empty__title")]');
        $this->assertSame('No bookings yet', $emptyTitle, 'My Bookings must render an honest empty state.');

        $this->assertTextContains('+ New Booking', $response);
    }

    public function testInvalidTabFallsBackToFacilities(): void
    {
        $this->loginResident();

        foreach (['?tab=bogus', '?tab=BOOKINGS', '?tab=', '?tab=0'] as $query) {
            $response = $this->get('/app/bookings' . $query);
            $this->assertStatus(200, $response, 'Invalid tab must not error: ' . $query);

            $this->assertCount(
                4,
                $this->xpath('//li[contains(@class,"facility-card")]', $this->dom($response)),
                'Invalid tab must fall back to Facilities: ' . $query
            );
            $this->assertActiveTab($response, 'Facilities');
        }
    }

    public function testPreviewChipAndHonestNote(): void
    {
        $response = $this->bookings();
        $dom = $this->dom($response);

        $note = $this->xpath('//p[contains(@class,"preview-note")]', $dom);
        $this->assertCount(1, $note, 'Preview mode must render the preview note block.');
        $noteNode = $note->item(0);
        $this->assertNotNull($noteNode);

        $this->assertSame('Layout preview', $this->nodeText($noteNode, './/*[contains(@class,"chip")]'));
        $this->assertContains('Facility bookings ship in the Bookings increment.', $this->text($noteNode));
    }

    public function testPreviewDisabledShowsHonestEmptyState(): void
    {
        $this->loginResident();

        $this->withPreview(false, function (): void {
            $response = $this->get('/app/bookings');
            $this->assertStatus(200, $response);

            $dom = $this->dom($response);

            $this->assertCount(0, $this->xpath('//li[contains(@class,"facility-card")]', $dom), 'No preview facilities when the flag is off.');
            $this->assertCount(0, $this->xpath('//p[contains(@class,"preview-note")]', $dom), 'No preview chip when the flag is off.');

            foreach (self::FACILITIES as $facility) {
                $this->assertTextNotContains($facility, $response);
            }

            $emptyTitle = $this->nodeText($dom, '//p[contains(@class,"resident-empty__title")]');
            $this->assertSame('No facilities listed yet', $emptyTitle, 'Preview off must render the honest facilities empty state.');

            $this->assertTextContains('+ New Booking', $response);
        });
    }

    public function testBookingsPageIsCspSafeAndAccessible(): void
    {
        $response = $this->bookings();

        $this->assertCleanMarkup($response, 'Bookings');

        $back = $this->xpath('//a[@href="/app"][@aria-label]', $this->dom($response));
        $this->assertTrue($back->length >= 1, 'Bookings must offer a labelled back link to /app.');
    }
}
