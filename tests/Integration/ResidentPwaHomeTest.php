<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;
use App\Core\View;
use DOMElement;

/**
 * Resident Home layout refresh (addendum §2A, §7.2).
 *
 * Asserts the exact greeting, hero estate card, six quick-action tiles with the
 * precise primary/secondary labels, announcements with pagination dots, the
 * compact estate-details card and the five-slot bottom navigation whose centre
 * slot is the QR FAB.
 */
final class ResidentPwaHomeTest extends ResidentPwaTestCase
{
    private function home(): Response
    {
        $this->loginResident();

        return $this->get('/app');
    }

    public function testGreetingAndSingleH1(): void
    {
        $response = $this->home();
        $this->assertStatus(200, $response);

        // Time-based greeting (email-pwa-greeting contract §3), rendered in the
        // estate timezone with a neutral "Hello," fallback for unknown timezones.
        $greeting = $this->nodeText($this->dom($response), '//*[contains(@class,"home-head__hello")]');
        $this->assertContains(
            $greeting,
            ['Good morning,', 'Good afternoon,', 'Good evening,', 'Hello,'],
            'The Home greeting must be one of the documented time-based greetings.'
        );

        $h1s = $this->xpath('//h1', $this->dom($response));
        $this->assertCount(1, $h1s, 'Home must render exactly one h1.');
        $h1 = $h1s->item(0);
        $this->assertNotNull($h1);
        $this->assertSame('Welcome Home', $this->text($h1));
    }

    public function testHeroEstateCard(): void
    {
        $response = $this->home();
        $dom = $this->dom($response);

        $hero = $this->xpath('//section[contains(@class,"hero-estate")]', $dom);
        $this->assertCount(1, $hero, 'Home must render the hero estate card.');

        $heroName = $this->xpath('//*[@id="hero-estate-name"]', $dom);
        $this->assertCount(1, $heroName);
        $heroNameNode = $heroName->item(0);
        $this->assertNotNull($heroNameNode);
        $this->assertSame('Greenview Residences', $this->text($heroNameNode), 'The hero must show the active estate name.');

        $heroImage = $this->xpath('//img[starts-with(@src,"/assets/img/estate-hero.png")]', $dom);
        $this->assertCount(1, $heroImage, 'The hero must use the locally generated /assets/img/estate-hero.png image.');

        $this->assertTrue(
            is_file(BASE_PATH . '/public/assets/img/estate-hero.png'),
            'The hero asset must exist on disk: public/assets/img/estate-hero.png.'
        );

        // T97: the hero now has two estate links (the visible "Details" text action
        // plus the chevron/go affordance), both pointing at /app/estates.
        $switch = $this->xpath('//section[contains(@class,"hero-estate")]//a[@href="/app/estates"]', $dom);
        $this->assertCount(2, $switch, 'The hero card must link to the estate details/switcher.');
        $details = $this->xpath('//section[contains(@class,"hero-estate")]//a[@href="/app/estates"][contains(normalize-space(.),"Details")]', $dom);
        $this->assertCount(1, $details, 'Home must expose a visible Details link to /app/estates.');
        $named = false;
        foreach ($switch as $switchNode) {
            if ($switchNode instanceof DOMElement && trim($switchNode->getAttribute('aria-label')) !== '') {
                $named = true;
            }
        }
        $this->assertTrue($named, 'The hero switcher affordance must have an accessible name.');
    }

    public function testQuickActionsExactlySixWithExactLabels(): void
    {
        $response = $this->home();

        $grid = $this->quickActionGrid($response);
        $this->assertNotNull($grid, 'Home must render the "Quick actions" grid.');

        $tiles = $this->quickActionTiles($response);
        $this->assertCount(6, $tiles, 'Home must contain exactly six quick-action tiles.');

        $expected = [
            ['Access', 'My QR'],
            ['Visitors', 'Pre-Register'],
            ['Vehicles', 'My Vehicles'],
            ['Nav', 'Navigation'],
            ['Bookings', 'Facilities'],
            ['Payments', '& Bills'],
        ];

        foreach ($expected as $index => [$primary, $secondary]) {
            $tile = $tiles[$index];

            $this->assertSame(
                $primary,
                $this->nodeText($tile, './/*[contains(@class,"quick-tile__primary")]'),
                'Wrong tile primary label at position ' . $index . '.'
            );
            $this->assertSame(
                $secondary,
                $this->nodeText($tile, './/*[contains(@class,"quick-tile__secondary")]'),
                'Wrong tile secondary label for ' . $primary . '.'
            );
        }

        // Navigation tiles are real links; Access opens the QR sheet; the rest are Soon actions.
        $visitors = $tiles[1];
        $this->assertSame('a', strtolower($visitors->tagName));
        $this->assertSame('/app/visitors/invite', $visitors->getAttribute('href'));

        $vehicles = $tiles[2];
        $this->assertSame('a', strtolower($vehicles->tagName), 'The Vehicles tile must be a real link (Increment 3).');
        $this->assertSame('/app/vehicles', $vehicles->getAttribute('href'));

        $bookings = $tiles[4];
        $this->assertSame('a', strtolower($bookings->tagName));
        $this->assertSame('/app/bookings', $bookings->getAttribute('href'));

        $navTile = $tiles[3];
        $this->assertSame('a', strtolower($navTile->tagName), 'The Nav tile must be a real link.');
        $this->assertSame('/app/navigation', $navTile->getAttribute('href'));

        $access = $tiles[0];
        $this->assertSame('button', strtolower($access->tagName), 'Access / My QR must be a button, not a link.');
        $this->assertSame('dialog', strtolower($access->getAttribute('aria-haspopup')));
        $this->assertSame('qr-sheet', $access->getAttribute('aria-controls'));

        // Increment 7: Notices is now a real link to the estate notices feed;
        // only Payments remains a "soon" action.
        $payments = $tiles[5];
        $this->assertSame('button', strtolower($payments->tagName), 'Payments must stay a Soon action.');
        $this->assertTrue(trim($payments->getAttribute('data-soon')) !== '', 'The Soon tile must carry a data-soon message.');
    }

    public function testAnnouncementsRenderAsAnAutoScrollingCarousel(): void
    {
        // T173: the three real seeded notices render as a seamless carousel: one
        // visible track + one aria-hidden clone track, no pagination dots.
        $response = $this->home();
        $dom = $this->dom($response);

        $title = $this->xpath('//h2[@id="announcements-title"]', $dom);
        $this->assertCount(1, $title, 'Home must render the Announcements section heading.');
        $titleNode = $title->item(0);
        $this->assertNotNull($titleNode);
        $this->assertSame('Announcements', $this->text($titleNode));

        $viewAll = $this->xpath('//a[@href="/app/notices"]', $dom);
        $this->assertTrue($viewAll->length >= 1, 'The announcements header must link to the notices feed.');

        $carousel = $this->xpath('//*[@data-announce-carousel]', $dom);
        $this->assertCount(1, $carousel, 'Home must render the announcements carousel.');
        $carouselNode = $carousel->item(0);
        $this->assertNotNull($carouselNode);
        $this->assertStringContainsString('announce-carousel--auto', $carouselNode->getAttribute('class'), 'Three notices enable the auto-scrolling carousel.');
        $this->assertSame('3', $carouselNode->getAttribute('data-announce-count'));
        $this->assertSame('true', $carouselNode->getAttribute('data-announce-animate'));
        $this->assertSame('32', $carouselNode->getAttribute('data-announce-duration'));
        $this->assertSame('carousel', $carouselNode->getAttribute('aria-roledescription'));
        $this->assertSame('0', $carouselNode->getAttribute('tabindex'), 'The carousel must be keyboard focusable.');

        $tracks = $this->xpath('//*[@data-announce-carousel]//div[contains(@class,"announce-track")]', $dom);
        $this->assertCount(2, $tracks, 'Three notices need the real track plus a clone track.');

        $clone = $this->xpath('//*[@data-announce-carousel]//div[contains(@class,"announce-track--clone")]', $dom);
        $this->assertCount(1, $clone, 'The clone track must be marked with announce-track--clone.');

        $cloneNode = $clone->item(0);
        $this->assertNotNull($cloneNode);
        $this->assertSame('true', strtolower($cloneNode->getAttribute('aria-hidden')), 'The clone track must be hidden from assistive tech.');

        $cloneCards = $this->xpath('//*[@data-announce-clone]//article[contains(@class,"announce-card")]', $dom);
        $this->assertCount(3, $cloneCards, 'The clone track duplicates the three real cards.');

        foreach ($cloneCards as $cloneCard) {
            $this->assertSame('true', $cloneCard->getAttribute('data-announce-clone-card'));
        }

        $cloneLinks = $this->xpath('//*[@data-announce-clone]//a[contains(@class,"announce-card__link")]', $dom);
        $this->assertCount(3, $cloneLinks);
        foreach ($cloneLinks as $link) {
            $this->assertSame('-1', $link->getAttribute('tabindex'), 'Clone links must be removed from the tab order.');
            $this->assertMatches('#^/app/notices/[0-9a-f-]{36}$#', (string) $link->getAttribute('href'));
        }

        $realTrackCards = $this->xpath('//*[@data-announce-carousel]//div[contains(@class,"announce-track")][not(contains(@class,"announce-track--clone"))]/article[contains(@class,"announce-card--real")]', $dom);
        $this->assertCount(3, $realTrackCards, 'The visible track keeps exactly the three real notice cards.');

        $this->assertCount(0, $this->xpath('//*[contains(@class,"announcement-dots")]', $dom), 'Pagination dots were replaced by the carousel.');

        $text = $this->pageText($response);
        $this->assertStringNotContainsString('Demo notice', $text, 'Real notices must replace the demo preview text.');
        $this->assertStringNotContainsString('Preview content', $text);
    }
    public function testCarouselIsStaticForOneOrTwoAnnouncements(): void
    {
        // One or two cards keep a static scroll-snap row: no clone track, no
        // animation flag, no pagination dots (T173 boundary).
        $two = View::capture('app/home', $this->minimalHomeData([
            ['title' => 'A', 'body' => 'Demo notice A'],
            ['title' => 'B', 'body' => 'Demo notice B'],
        ]));
        $this->assertStringContainsString('announce-carousel--static', $two);
        $this->assertStringContainsString('data-announce-animate="false"', $two);
        $this->assertStringContainsString('data-announce-count="2"', $two);
        $this->assertStringNotContainsString('announce-track--clone', $two, 'Two cards must not render a clone track.');
        $this->assertCount(0, $this->dotsInHtml($two));

        $one = View::capture('app/home', $this->minimalHomeData([
            ['title' => 'A', 'body' => 'Demo notice A'],
        ]));
        $this->assertStringContainsString('announce-carousel--static', $one);
        $this->assertStringContainsString('data-announce-count="1"', $one);
        $this->assertStringNotContainsString('announce-track--clone', $one);
        $this->assertCount(0, $this->dotsInHtml($one));

        $none = View::capture('app/home', $this->minimalHomeData([]));
        $this->assertStringNotContainsString('announce-carousel', $none);
        $this->assertContains('No estate notices yet', $none);
    }
    public function testPreviewFlagDoesNotHideRealNotices(): void
    {
        $this->loginResident();

        $this->withPreview(false, function (): void {
            $response = $this->get('/app');
            $this->assertStatus(200, $response);

            $dom = $this->dom($response);

            // Real published notices still render when the legacy preview flag is off.
            $this->assertCount(6, $this->xpath('//article[contains(@class,"announce-card--real")]', $dom), 'Real notices render in the visible + clone tracks with UI_PREVIEW_CONTENT=false.');
            $this->assertTextNotContains('Demo notice', $response);
            $this->assertTextNotContains('Preview content', $response);
        });

        // With no real notices and preview off, the honest empty state renders.
        $this->db->execute('DELETE FROM estate_notices WHERE estate_id = :id', [
            'id' => (int) $this->seededEstate('greenview-residences')['id'],
        ]);

        $this->withPreview(false, function (): void {
            $response = $this->get('/app');
            $this->assertStatus(200, $response);
            $this->assertCount(0, $this->xpath('//article[contains(@class,"announce-card")]', $this->dom($response)));
            $this->assertCount(0, $this->xpath('//*[@data-announce-carousel]', $this->dom($response)));
            $this->assertTextContains('No estate notices yet', $response, 'No visible notices must render the honest empty state.');
            $this->assertTextNotContains('Demo notice', $response);
        });
    }
    public function testBottomNavigationHasFiveSlotsWithCentreQrFab(): void
    {
        $response = $this->home();

        $nav = $this->bottomNav($response);
        $this->assertNotNull($nav, 'Home must render the bottom navigation.');
        $this->assertSame('Primary', $nav->getAttribute('aria-label'));

        $slots = $this->navSlots($response);
        $this->assertCount(5, $slots, 'The bottom navigation must have five slots: Home, Inbox, QR FAB, Activity, Account.');

        // Slot 0 — Home (active).
        $this->assertSame('a', strtolower($slots[0]->tagName));
        $this->assertSame('/app', $slots[0]->getAttribute('href'));
        $this->assertSame('page', strtolower($slots[0]->getAttribute('aria-current')));
        $this->assertContains('Home', $this->text($slots[0]));

        // Slots 1 and 3 — Soon actions.
        foreach ([1, 3] as $index) {
            $this->assertSame('button', strtolower($slots[$index]->tagName));
            $this->assertTrue(trim($slots[$index]->getAttribute('data-soon')) !== '');
        }
        $this->assertContains('Inbox', $this->text($slots[1]));
        $this->assertContains('Activity', $this->text($slots[3]));

        // Slot 2 — the raised centre QR FAB.
        $fab = $slots[2];
        $this->assertSame('button', strtolower($fab->tagName));
        $this->assertSame('Show my access QR', $fab->getAttribute('aria-label'));
        $this->assertSame('dialog', strtolower($fab->getAttribute('aria-haspopup')));
        $this->assertSame('qr-sheet', $fab->getAttribute('aria-controls'));
        $this->assertTrue(str_contains($fab->getAttribute('class'), 'tab-fab'), 'The centre slot must be the raised FAB.');

        // Slot 4 — Account.
        $this->assertSame('a', strtolower($slots[4]->tagName));
        $this->assertSame('/app/account', $slots[4]->getAttribute('href'));
        $this->assertContains('Account', $this->text($slots[4]));

        $active = $this->xpath('.//*[@aria-current="page"]', $nav);
        $this->assertCount(1, $active, 'Exactly one bottom-nav slot may carry aria-current="page".');
    }

    public function testHomeNoLongerCarriesTheEstateDetailsCard(): void
    {
        // T97: the "Your estate" details section moved to /app/estates; Home keeps
        // the hero + a visible Details link and must not duplicate household/role/gate data.
        $response = $this->home();
        $this->assertStatus(200, $response);

        $this->assertTextContains('Greenview Residences', $response, 'Home must keep the hero estate name.');
        $this->assertTextContains('Details', $response, 'Home must expose the Details link to /app/estates.');
        // Hero carries two estate links (Details + go) and the sidebar carries one.
        $this->assertSame(2, $this->xpath('//section[contains(@class,"hero-estate")]//a[@href="/app/estates"]', $this->dom($response))->length);
        $this->assertTrue(
            $this->xpath('//a[@href="/app/estates"]', $this->dom($response))->length >= 3,
            'Home must link to /app/estates from the hero (two links) and the sidebar.'
        );

        $this->assertTextNotContains('Your household', $response, 'The household details card must be gone from Home.');
        $this->assertTextNotContains('Your role', $response, 'The role details line must be gone from Home.');
        $this->assertCount(0, $this->xpath('//*[@id="details-title"]', $this->dom($response)), 'Home must not render the details card.');

        // No fabricated operational data for modules that are not installed.
        $lower = strtolower($this->pageText($response));
        $this->assertFalse(str_contains($lower, 'visitors today'), 'Home must not fabricate visitor counts.');
        $this->assertFalse(str_contains($lower, 'occupancy'), 'Home must not fabricate parking occupancy.');
        $this->assertFalse(str_contains($lower, 'unpaid'), 'Home must not fabricate payment data.');
    }
    public function testHomeMarkupIsCspSafeAndAccessible(): void
    {
        $response = $this->home();

        $this->assertCleanMarkup($response, 'Home');

        $h1s = $this->xpath('//h1', $this->dom($response));
        $this->assertCount(1, $h1s, 'Home must render exactly one h1.');

        foreach ($this->xpath('//button', $this->dom($response)) as $button) {
            if (!$button instanceof DOMElement) {
                continue;
            }

            $label = trim($button->getAttribute('aria-label') . ' ' . $button->getAttribute('title'));

            if ($this->text($button) === '' && $label === '') {
                $this->fail('Home contains an icon-only button without aria-label/title: ' . $button->getAttribute('class'));
            }
        }

        $nav = $this->bottomNav($response);
        $this->assertNotNull($nav);
        $this->assertCount(1, $this->xpath('.//*[@aria-current="page"]', $nav));
    }

    /**
     * Minimal but valid view data for direct view rendering.
     *
     * @param array<int, array{title: string, body: string}> $announcements
     * @return array<string, mixed>
     */
    private function minimalHomeData(array $announcements): array
    {
        return [
            'title' => 'Home',
            'user' => ['full_name' => 'Jane Smith', 'phone' => '+254700000010', 'email' => null],
            'estate' => ['estate_name' => 'Greenview Residences', 'role_name' => 'resident'],
            'memberships' => [],
            'activeNav' => 'home',
            'csrfToken' => str_repeat('a', 64),
            'appVersion' => '0.1.0-foundation',
            'isDev' => true,
            'flashError' => null,
            'flashSuccess' => null,
            'permissions' => [],
            'dashboard' => [
                'estate' => ['estate_name' => 'Greenview Residences', 'role_name' => 'resident'],
                'households' => [],
                'gates' => [],
                'memberships' => [],
            ],
            'preview' => true,
            'announcements' => $announcements,
        ];
    }

    /**
     * @return array<int, DOMElement>
     */
    private function dotsInHtml(string $html): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $dots = [];

        foreach ((new \DOMXPath($dom))->query('//div[contains(@class,"announcement-dots")]/span[contains(@class,"dot")]') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $dots[] = $node;
            }
        }

        return $dots;
    }
}
