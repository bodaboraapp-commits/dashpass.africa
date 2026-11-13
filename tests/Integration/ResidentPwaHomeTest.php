<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;
use App\Core\View;
use DOMElement;
use DOMNode;

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

    public function testHouseholdServicesSectionRemoved(): void
    {
        $response = $this->home();
        $this->assertStatus(200, $response);
        $dom = $this->dom($response);

        $this->assertCount(0, $this->xpath('//section[@aria-labelledby="household-services-title"]', $dom), 'The resident Home must not render the Household services section.');
        $this->assertTextNotContains('Household services', $response, 'Household services heading must be gone from Home.');
        $this->assertTextNotContains('Household staff directory and security incident reporting.', $response, 'Household services subtitle must be gone from Home.');
        $this->assertCount(1, $this->xpath('//h1', $dom), 'Home keeps a single h1.');
        $this->assertCount(1, $this->xpath('//section[contains(@class,"hero-estate")]', $dom), 'Hero remains intact.');
        $this->assertCount(1, $this->xpath('//*[@id="announcements-title"]', $dom), 'Announcements section remains intact.');
        $this->assertTrue($this->xpath('//*[contains(@class,"quick-tile")]', $dom)->length >= 6, 'Quick-action tiles remain intact.');
        $this->assertCount(0, $this->xpath('//a[@href="/app/staff"]', $dom), 'Household staff shortcut is gone from Home.');
        $this->assertCount(0, $this->xpath('//a[@href="/app/incidents/new"]', $dom), 'Report incident shortcut is gone from Home.');
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

    public function testHomeHeroContainsGreetingAndBellWithRightAlignedFooter(): void
    {
        $response = $this->home();
        $this->assertStatus(200, $response);
        $dom = $this->dom($response);

        $hero = $this->xpath('//section[contains(@class,"hero-estate--home")]', $dom);
        $this->assertCount(1, $hero, 'Home hero must render the taller overlay variant.');

        $greeting = $this->xpath('//section[contains(@class,"hero-estate--home")]//p[contains(@class,"home-head__hello")]', $dom);
        $this->assertCount(1, $greeting, 'The Hello greeting must sit inside the hero.');
        $h1 = $this->xpath('//section[contains(@class,"hero-estate--home")]//h1[contains(@class,"home-head__title")]', $dom);
        $this->assertCount(1, $h1, 'The single h1 must sit inside the hero.');
        $bell = $this->xpath('//section[contains(@class,"hero-estate--home")]//a[contains(@class,"notification-bell")][@href="/app/notices"]', $dom);
        $this->assertCount(1, $bell, 'The single notices bell must sit inside the hero.');
        $this->assertCount(1, $this->xpath('//section[contains(@class,"hero-estate--home")]//header[contains(@class,"home-head--overlay")]', $dom), 'Hero must use the overlay header.');

        $identity = $this->xpath('//section[contains(@class,"hero-estate--home")]//div[contains(@class,"hero-estate__identity")]', $dom);
        $details = $this->xpath('//section[contains(@class,"hero-estate--home")]//a[contains(@class,"hero-estate__details")]', $dom);
        $this->assertCount(1, $identity);
        $this->assertCount(1, $details);
        $identityNode = $identity->item(0);
        $detailsNode = $details->item(0);
        $this->assertNotNull($identityNode);
        $this->assertNotNull($detailsNode);
        $this->assertTrue(
            $identityNode->getLineNo() < $detailsNode->getLineNo(),
            'Estate identity must appear immediately before the details chevron in the footer row.'
        );

        $css = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertMatchesRegularExpression('/\.hero-estate--home\s*\{[^}]*margin-top:\s*-4px/s', $css, 'Hero must start 4px higher.');
        $this->assertMatchesRegularExpression('/\.hero-estate--home\s*\{[^}]*min-height:/s', $css, 'Hero must have a taller minimum height.');
        $this->assertMatchesRegularExpression('/\.home-head--overlay\s*\{[^}]*position:\s*absolute/s', $css, 'Greeting/bell header must overlay the hero.');
        $this->assertStringContainsString('.hero-estate--home .hero-estate__shade', $css, 'Hero must include a top scrim for bell/greeting contrast.');
        $this->assertCount(1, $this->xpath('//section[contains(@class,"hero-estate--home")]//div[contains(@class,"hero-estate__top-scrim")][@aria-hidden="true"]', $dom), 'Hero must render a dedicated top scrim element behind the greeting/bell.');
        $this->assertMatchesRegularExpression('/\.hero-estate--home \.hero-estate__top-scrim\s*\{[^}]*linear-gradient\(/s', $css, 'Top scrim must use a dedicated gradient.');
        $this->assertMatchesRegularExpression('/\.home-head--overlay \.home-head__hello\s*\{[^}]*color:\s*#ffffff[^}]*text-shadow:/s', $css, 'Greeting must be pure white with a contrast text-shadow.');
        $this->assertMatchesRegularExpression('/\.hero-estate--home \.hero-estate__body\s*\{[^}]*flex-wrap:\s*nowrap[^}]*justify-content:\s*flex-end/s', $css, 'Footer identity + chevron must stay in one right-aligned row.');
        $this->assertMatchesRegularExpression('/\.hero-estate--home \.hero-estate__identity\s*\{[^}]*text-align:\s*right/s', $css, 'Footer identity text must be right-aligned beside the chevron.');
        $this->assertMatchesRegularExpression('/\.hero-estate--home\s*\{[^}]*--hero-greeting-size:\s*0\.92rem/s', $css, 'Hero must expose the shared greeting size variable.');
        $this->assertMatchesRegularExpression('/\.home-head--overlay \.home-head__hello\s*\{[^}]*font-size:\s*var\(--hero-greeting-size\)/s', $css, 'Greeting must use the shared hero greeting size.');
        $this->assertMatchesRegularExpression('/\.hero-estate--home \.hero-estate__name\s*\{[^}]*font-size:\s*var\(--hero-greeting-size\)/s', $css, 'Estate name must use the same greeting size.');
        $this->assertMatchesRegularExpression('/\.hero-estate--home\s*\{[^}]*min-height:\s*clamp\(206px,\s*calc\(34vw - 20px\),\s*280px\)/s', $css, 'Hero min-height must shrink by the removed wrapped-line height (~20px).');
        $this->assertMatchesRegularExpression('/\.hero-estate--home \.hero-estate__name\s*\{[^}]*white-space:\s*nowrap[^}]*text-overflow:\s*ellipsis/s', $css, 'Estate name must stay on one line with ellipsis fallback.');
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
        $this->assertCount(0, $this->xpath('//h2[@id="hero-estate-name"]//span[contains(@class,"hero-estate__line")]', $dom), 'Estate name must not be split into blockline spans.');
        $this->assertSame(1, $heroNameNode->childNodes->length, 'Estate name must render as one single text node.');
        $cssName = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertStringNotContainsString('.hero-estate__line', $cssName, 'Dead hero-estate__line CSS must be removed.');
        $this->assertMatchesRegularExpression('/\.hero-estate--home\s*\{[^}]*min-height:\s*clamp\(206px,\s*calc\(34vw - 20px\),\s*280px\)/s', $cssName, 'Reduced hero height must be unchanged.');

        $heroImage = $this->xpath('//img[starts-with(@src,"/assets/img/estate-hero.png")]', $dom);
        $this->assertCount(1, $heroImage, 'The hero must use the locally generated /assets/img/estate-hero.png image.');

        $this->assertTrue(
            is_file(BASE_PATH . '/public/assets/img/estate-hero.png'),
            'The hero asset must exist on disk: public/assets/img/estate-hero.png.'
        );
        $heroFile = BASE_PATH . '/public/assets/img/estate-hero.png';
        $heroInfo = getimagesize($heroFile);
        $this->assertNotFalse($heroInfo, 'The hero asset must be a valid image.');
        $this->assertSame(1200, $heroInfo[0], 'Hero width must be 1200px.');
        $this->assertSame(675, $heroInfo[1], 'Hero height must be 675px (16:9).');
        $this->assertSame(IMAGETYPE_PNG, $heroInfo[2], 'Hero must be a PNG.');
        $this->assertLessThan(300 * 1024, (int) filesize($heroFile), 'Hero PNG must stay under 300 KB.');
        $heroImgNode = $heroImage->item(0);
        $this->assertNotNull($heroImgNode);
        $this->assertSame('1200', $heroImgNode->getAttribute('width'), 'Hero img width hint prevents CLS.');
        $this->assertSame('675', $heroImgNode->getAttribute('height'), 'Hero img height hint prevents CLS.');

        // Estate identity is grouped with one full-details control at the bottom
        // of the hero: name/meta left, Details + chevron immediately beside it.
        $switch = $this->xpath('//section[contains(@class,"hero-estate")]//a[@href="/app/estates"]', $dom);
        $this->assertCount(1, $switch, 'The hero must expose one grouped estate details/switcher control.');
        $details = $this->xpath('//section[contains(@class,"hero-estate")]//a[@href="/app/estates"][contains(normalize-space(.),"Details")]', $dom);
        $this->assertCount(1, $details, 'Home must expose a visible Details link to /app/estates.');
        $detailsNode = $details->item(0);
        $this->assertNotNull($detailsNode);
        $this->assertSame('View full estate details', trim($detailsNode->getAttribute('aria-label')), 'The Details control must describe opening full estate details.');
        $grouped = $this->xpath(
            '//div[contains(@class,"hero-estate__body")][.//p[contains(@class,"hero-estate__meta")]][.//a[@href="/app/estates"][contains(normalize-space(.),"Details")]]',
            $dom
        );
        $this->assertCount(1, $grouped, 'Estate name/meta and the Details control must sit in the same hero group.');
        $this->assertCount(0, $this->xpath('//section[contains(@class,"hero-estate")]//a[contains(@class,"hero-estate__go")]', $dom), 'The old separated chevron affordance must be removed.');
        $css = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertMatchesRegularExpression('/\.hero-estate__details\s*\{[^}]*min-height:\s*44px/s', $css, 'The Details control must have a 44px tap target.');
        $this->assertMatchesRegularExpression('/\.hero-estate__details\s*\{[^}]*width:\s*44px[^}]*height:\s*44px/s', $css, 'The Details control must be a 44px square target.');
        $this->assertMatchesRegularExpression('/\.hero-estate__body\s*\{[^}]*justify-content:\s*flex-end/s', $css, 'Identity and Details must sit in the right-aligned hero footer group.');
        $this->assertCount(1, $this->xpath('//div[contains(@class,"hero-estate__body")]/a[contains(@class,"hero-estate__details")][last()]', $dom), 'Details control must be the right-hand element in the hero footer.');
        $this->assertMatchesRegularExpression('/\.hero-estate__identity\s*\{[^}]*text-align:\s*right/s', $css, 'Estate identity text must be right-aligned beside the Details control.');
    }

    public function testQuickActionsExactlySixWithExactLabels(): void
    {
        $response = $this->home();

        $grid = $this->quickActionGrid($response);
        $this->assertNotNull($grid, 'Home must render the "Quick actions" grid.');

        $tiles = $this->quickActionTiles($response);
        $this->assertCount(6, $tiles, 'Home must contain exactly six quick-action tiles.');

        $expected = [
            ['Access', 'My Pass'],
            ['Visitors', 'My Guest'],
            ['Vehicles', 'My Vehicles'],
            ['Nav', 'WayFinder'],
            ['Bookings', 'My Bookings'],
            ['Payments', 'My Bills'],
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

        // Owner request: the Visitors quick tile opens the Visitors list screen
        // (which carries the "Invite visitor" CTA), not the invite form directly.
        $visitors = $tiles[1];
        $this->assertSame('a', strtolower($visitors->tagName));
        $this->assertSame('/app/visitors', $visitors->getAttribute('href'));

        $vehicles = $tiles[2];
        $this->assertSame('a', strtolower($vehicles->tagName), 'The Vehicles tile must be a real link (Increment 3).');
        $this->assertSame('/app/vehicles', $vehicles->getAttribute('href'));

        // Owner request: the Bookings quick tile opens the "My Bookings" tab.
        $bookings = $tiles[4];
        $this->assertSame('a', strtolower($bookings->tagName));
        $this->assertSame('/app/bookings?tab=bookings', $bookings->getAttribute('href'));

        $navTile = $tiles[3];
        $this->assertSame('a', strtolower($navTile->tagName), 'The Nav tile must be a real link.');
        $this->assertSame('/app/navigation', $navTile->getAttribute('href'));

        $access = $tiles[0];
        $this->assertSame('button', strtolower($access->tagName), 'Access / My Pass must be a button, not a link.');
        $this->assertSame('dialog', strtolower($access->getAttribute('aria-haspopup')));
        $this->assertSame('qr-sheet', $access->getAttribute('aria-controls'));

        // Increment 13: Payments & Bills is now a real resident link.
        $payments = $tiles[5];
        $this->assertSame('a', strtolower($payments->tagName), 'Payments & Bills must be a real link.');
        $this->assertSame('/app/bills', $payments->getAttribute('href'));
        $this->assertSame('', trim((string) $payments->getAttribute('data-soon')), 'Payments must not remain a Soon action.');
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
        $section = $this->xpath('//section[contains(@class,"home-announcements")]', $dom);
        $this->assertCount(1, $section, 'Announcements section must carry the tightened spacing class.');
        $cssSpacing = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertMatchesRegularExpression('/\.home-announcements\s*\{[^}]*margin-top:\s*-0\.625rem/s', $cssSpacing, 'Announcements top gap must be reduced ~10px.');
        $this->assertMatchesRegularExpression('/\.home-announcements\s*\{[^}]*margin-bottom:\s*-1\.75rem/s', $cssSpacing, 'Announcements bottom gap must be reduced ~28px.');

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

        // The clone track repeats the three-card sequence ceil(10/3)=4 times so the
        // seamless loop always fills the viewport.
        $cloneCopies = (int) ceil(10 / 3);
        $cloneCards = $this->xpath('//*[@data-announce-clone]//article[contains(@class,"announce-card")]', $dom);
        $this->assertCount(3 * $cloneCopies, $cloneCards, 'The clone track repeats the three real cards to fill the loop.');

        foreach ($cloneCards as $cloneCard) {
            $this->assertSame('true', $cloneCard->getAttribute('data-announce-clone-card'));
        }

        $cloneLinks = $this->xpath('//*[@data-announce-clone]//a[contains(@class,"announce-card__link")]', $dom);
        $this->assertCount(3 * $cloneCopies, $cloneLinks);
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
    public function testCarouselIsStaticOnlyForASingleAnnouncement(): void
    {
        // T173 boundary: one card keeps the static scroll-snap row (no clone, no
        // animation); two or more cards animate with a repeated clone track.
        $two = View::capture('app/home', $this->minimalHomeData([
            ['title' => 'A', 'body' => 'Demo notice A'],
            ['title' => 'B', 'body' => 'Demo notice B'],
        ]));
        $this->assertStringContainsString('announce-carousel--auto', $two, 'Two cards animate the carousel.');
        $this->assertStringContainsString('data-announce-animate="true"', $two);
        $this->assertStringContainsString('data-announce-count="2"', $two);
        $this->assertStringContainsString('announce-track--clone', $two);
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
            $this->assertCount(3 + (3 * (int) ceil(10 / 3)), $this->xpath('//article[contains(@class,"announce-card--real")]', $dom), 'Real notices render in the visible track plus the repeated clone track.');
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
    public function testHomeNoLongerShowsTheHouseholdServicesSection(): void
    {
        // Owner request: the Home "Household services" shortcuts (Household staff /
        // Report incident) were removed; the rest of Home stays intact and the
        // destinations remain reachable from their own sections.
        $response = $this->home();
        $this->assertStatus(200, $response);
        $dom = $this->dom($response);

        $this->assertCount(0, $this->xpath('//*[@id="household-services-title"]', $dom), 'The removed Household services heading must not render.');
        $this->assertTextNotContains('Household services', $response);
        $this->assertCount(0, $this->xpath('//section[contains(@class,"resident-section")]//a[@href="/app/staff"]', $dom), 'Home must not link to the staff directory shortcut.');
        $this->assertCount(0, $this->xpath('//section[contains(@class,"resident-section")]//a[@href="/app/incidents/new"]', $dom), 'Home must not link to the incident-report shortcut.');
        $this->assertCount(0, $this->xpath('//*[contains(@class,"home-link")]', $dom));

        // The rest of Home remains: hero, six tiles, announcements carousel + notices link.
        $this->assertCount(1, $this->xpath('//h2[@id="announcements-title"]', $dom));
        $this->assertCount(6, $this->quickActionTiles($response));
        $this->assertCount(1, $this->xpath('//*[@data-announce-carousel]', $dom));
        $this->assertTrue($this->xpath('//a[@href="/app/notices"]', $dom)->length >= 1, 'The notices feed link remains on Home.');
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

        // Slot 1 — Inbox is now a real notification inbox link.
        $this->assertSame('a', strtolower($slots[1]->tagName));
        $this->assertSame('/notifications', $slots[1]->getAttribute('href'));
        $this->assertContains('Inbox', $this->text($slots[1]));

        // Slot 3 — Activity is now a real own-household visitor timeline screen.
        $this->assertSame('a', strtolower($slots[3]->tagName), 'Activity must be a real link now.');
        $this->assertSame('/app/activity', $slots[3]->getAttribute('href'));
        $this->assertContains('Activity', $this->text($slots[3]));
        $this->assertSame('', trim((string) $slots[3]->getAttribute('data-soon')), 'Activity must not remain a Soon action.');

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
        // The Home estate move (commit 73492a9) keeps one grouped Details control in
        // the hero plus the sidebar entry point.
        $this->assertSame(1, $this->xpath('//section[contains(@class,"hero-estate")]//a[@href="/app/estates"]', $this->dom($response))->length, 'Hero exposes one grouped Details control.');
        $this->assertTrue(
            $this->xpath('//a[@href="/app/estates"]', $this->dom($response))->length >= 1,
            'Home must keep the /app/estates entry point.'
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
