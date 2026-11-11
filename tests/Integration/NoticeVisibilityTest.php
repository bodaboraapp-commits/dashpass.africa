<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Response;

/**
 * Estate notices visibility contract (docs/estate-notices-contract.md §3, §5).
 *
 * PRE-WRITTEN by qa-mail (T55) for the Increment-7 gate: parked in
 * storage/temporary/pending-tests/ until the notices routes, migration 9 and
 * home-feed wiring land; moves to tests/Integration/ at the gate.
 *
 * Rows are inserted directly (the public API has no "expired"/"foreign" create
 * path) so each visibility rule can be isolated. Read-only: every assertion uses
 * the seeded residents/guards and the real controllers.
 */
final class NoticeVisibilityTest extends AdminModuleTestCase
{
    private const RIVERSIDE = 'riverside-court';

    /** @var mixed */
    private $originalPreview = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalPreview = Config::get('ui.resident_pwa.preview_content', true);
    }

    public function tearDown(): void
    {
        Config::set('ui.resident_pwa.preview_content', $this->originalPreview);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Directly insert an estate notice (fixture) and return the row.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function insertNotice(array $overrides = [], string $estateSlug = self::ESTATE_SLUG): array
    {
        $estate = $this->seededEstate($estateSlug);
        $creator = $this->seededUser(self::GRACE);
        $now = Clock::nowDb();

        $row = array_merge([
            'uuid' => $this->newUuid(),
            'estate_id' => (int) $estate['id'],
            'title' => 'Fixture notice ' . bin2hex(random_bytes(3)),
            'body' => 'A fixture notice body used for visibility assertions.',
            'category' => 'general',
            'priority' => 'normal',
            'status' => 'published',
            'audience' => 'all',
            'published_at' => $now,
            'expires_at' => null,
            'created_by_user_id' => (int) $creator['id'],
            'updated_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $this->db->insert('estate_notices', $row);

        return $row;
    }

    private function preview(bool $enabled): void
    {
        Config::set('ui.resident_pwa.preview_content', $enabled);
    }

    /**
     * @return array<int, string>
     */
    private function texts(string $xpath): array
    {
        $texts = [];

        foreach ($this->xpath($xpath, $this->lastDom) as $node) {
            $texts[] = $this->text($node);
        }

        return $texts;
    }

    /** @var \DOMDocument */
    private \DOMDocument $lastDom;

    /**
     * @return array<int, string> Notice titles in rendered order.
     */
    private function residentTitles(Response $response): array
    {
        $this->assertStatus(200, $response, 'The resident notices list must render.');
        $this->lastDom = $this->dom($response);

        return $this->texts('//a[contains(@class,"notice-card__link")]');
    }

    /**
     * @return array<int, string>
     */
    private function guardTitles(Response $response): array
    {
        $this->assertStatus(200, $response, 'The guard notices list must render.');
        $this->lastDom = $this->dom($response);

        return $this->texts('//li[contains(@class,"g-notice")]//p[contains(@class,"g-notice__title")]');
    }

    /**
     * @return array<int, string>
     */
    private function homeCardTitles(Response $response): array
    {
        $this->assertStatus(200, $response, 'The resident Home screen must render.');
        $this->lastDom = $this->dom($response);

        return $this->texts('//article[contains(@class,"announce-card--real")]//a[contains(@class,"announce-card__link")]');
    }

    /**
     * @param array<int, string> $titles
     */
    private function assertAppearsBefore(array $titles, string $first, string $second): void
    {
        $firstIndex = PHP_INT_MAX;
        $secondIndex = PHP_INT_MAX;

        foreach ($titles as $index => $title) {
            if (str_contains($title, $first) && $firstIndex === PHP_INT_MAX) {
                $firstIndex = $index;
            }

            if (str_contains($title, $second) && $secondIndex === PHP_INT_MAX) {
                $secondIndex = $index;
            }
        }

        $this->assertTrue(
            $firstIndex < $secondIndex,
            sprintf('"%s" must render before "%s" (got order: %s).', $first, $second, json_encode($titles))
        );
    }

    /* ------------------------------------------------------------------ */
    /* Resident visibility                                                */
    /* ------------------------------------------------------------------ */

    public function testResidentListShowsOnlyPublishedAudienceMatchedUnexpiredNotices(): void
    {
        $visibleAll = $this->insertNotice(['title' => 'Visible all-audience notice ' . bin2hex(random_bytes(2))]);
        $visibleResidents = $this->insertNotice([
            'title' => 'Visible residents-audience notice ' . bin2hex(random_bytes(2)),
            'audience' => 'residents',
        ]);
        $staffOnly = $this->insertNotice([
            'title' => 'Hidden staff-only notice ' . bin2hex(random_bytes(2)),
            'audience' => 'staff',
        ]);
        $draft = $this->insertNotice([
            'title' => 'Hidden draft notice ' . bin2hex(random_bytes(2)),
            'status' => 'draft',
            'published_at' => null,
        ]);
        $archived = $this->insertNotice([
            'title' => 'Hidden archived notice ' . bin2hex(random_bytes(2)),
            'status' => 'archived',
        ]);
        $expired = $this->insertNotice([
            'title' => 'Hidden expired notice ' . bin2hex(random_bytes(2)),
            'expires_at' => Clock::toDb(Clock::now()->modify('-1 minute')),
        ]);
        $foreign = $this->insertNotice([
            'title' => 'Hidden foreign-estate notice ' . bin2hex(random_bytes(2)),
        ], self::RIVERSIDE);

        $this->ensureLoggedInAs(self::JANE);
        $response = $this->get('/app/notices');

        $this->assertTextContains((string) $visibleAll['title'], $response);
        $this->assertTextContains((string) $visibleResidents['title'], $response);

        foreach ([$staffOnly, $draft, $archived, $expired, $foreign] as $hidden) {
            $this->assertTextNotContains((string) $hidden['title'], $response, 'Hidden notices must not render in the resident list.');
            $this->assertStatus(404, $this->get('/app/notices/' . (string) $hidden['uuid']));
        }

        // Published + visible detail renders.
        $detail = $this->get('/app/notices/' . (string) $visibleAll['uuid']);
        $this->assertStatus(200, $detail);
        $this->assertTextContains((string) $visibleAll['title'], $detail);
    }

    public function testResidentListOrdersByPriorityThenPublishedAt(): void
    {
        $urgent = $this->insertNotice([
            'title' => 'Order urgent notice ' . bin2hex(random_bytes(2)),
            'priority' => 'urgent',
            'published_at' => Clock::toDb(Clock::now()->modify('-3 hours')),
        ]);
        $important = $this->insertNotice([
            'title' => 'Order important notice ' . bin2hex(random_bytes(2)),
            'priority' => 'important',
            'published_at' => Clock::toDb(Clock::now()->modify('-2 hours')),
        ]);
        $normalNewer = $this->insertNotice([
            'title' => 'Order normal newer notice ' . bin2hex(random_bytes(2)),
            'priority' => 'normal',
            'published_at' => Clock::toDb(Clock::now()->modify('-1 hour')),
        ]);
        $normalOlder = $this->insertNotice([
            'title' => 'Order normal older notice ' . bin2hex(random_bytes(2)),
            'priority' => 'normal',
            'published_at' => Clock::toDb(Clock::now()->modify('-5 hours')),
        ]);

        $this->ensureLoggedInAs(self::JANE);
        $titles = $this->residentTitles($this->get('/app/notices'));

        $this->assertAppearsBefore($titles, (string) $urgent['title'], (string) $important['title']);
        $this->assertAppearsBefore($titles, (string) $important['title'], (string) $normalNewer['title']);
        $this->assertAppearsBefore($titles, (string) $normalNewer['title'], (string) $normalOlder['title']);
    }

    /* ------------------------------------------------------------------ */
    /* Guard visibility                                                   */
    /* ------------------------------------------------------------------ */

    public function testGuardSeesOnlyStaffOrAllAudiencePublishedNotices(): void
    {
        $forAll = $this->insertNotice(['title' => 'Guard visible all notice ' . bin2hex(random_bytes(2))]);
        $forStaff = $this->insertNotice([
            'title' => 'Guard visible staff notice ' . bin2hex(random_bytes(2)),
            'audience' => 'staff',
            'priority' => 'urgent',
        ]);
        $forResidents = $this->insertNotice([
            'title' => 'Guard hidden residents notice ' . bin2hex(random_bytes(2)),
            'audience' => 'residents',
        ]);
        $expired = $this->insertNotice([
            'title' => 'Guard hidden expired notice ' . bin2hex(random_bytes(2)),
            'audience' => 'staff',
            'expires_at' => Clock::toDb(Clock::now()->modify('-30 minutes')),
        ]);

        $this->loginGuard();
        $titles = $this->guardTitles($this->get('/gate/notices'));

        $this->assertTrue(in_array((string) $forAll['title'], $titles, true), 'Audience=all notices must reach the gate.');
        $this->assertTrue(in_array((string) $forStaff['title'], $titles, true), 'Audience=staff notices must reach the gate.');
        $this->assertFalse(in_array((string) $forResidents['title'], $titles, true), 'Residents-only notices must stay off the gate.');
        $this->assertFalse(in_array((string) $expired['title'], $titles, true), 'Expired notices must stay off the gate.');

        $this->assertAppearsBefore($titles, (string) $forStaff['title'], (string) $forAll['title']);
    }

    /* ------------------------------------------------------------------ */
    /* Home announcements feed                                            */
    /* ------------------------------------------------------------------ */

    public function testHomeFeedShowsAtMostThreeRealPublishedNotices(): void
    {
        $this->preview(false);

        $inserted = [];

        foreach (['urgent', 'important', 'normal', 'normal'] as $index => $priority) {
            $inserted[] = $this->insertNotice([
                'title' => 'Home feed notice ' . $index . ' ' . bin2hex(random_bytes(2)),
                'priority' => $priority,
                'published_at' => Clock::toDb(Clock::now()->modify('-' . ($index + 1) . ' minutes')),
            ]);
        }

        $this->ensureLoggedInAs(self::JANE);
        $response = $this->get('/app');
        $titles = $this->homeCardTitles($response);

        // T173: the carousel repeats the visible cards in the aria-hidden clone
        // track, so the distinct set of rendered notice titles stays at three.
        $distinctTitles = array_values(array_unique($titles));
        $this->assertCount(3, $distinctTitles, 'The Home announcements feed must show at most three distinct real notices.');
        $this->assertStringNotContainsString('Preview content', $response->body(), 'Preview demo content must be gone when UI_PREVIEW_CONTENT=false.');
        $this->assertStringNotContainsString('Demo notice', $response->body());

        // The feed is a real notices feed: every card links to a notice detail.
        // T173: the Home feed is an auto-scrolling carousel ? the three real cards
        // are duplicated in the aria-hidden clone track, so the DOM carries 6 links.
        $realTrackLinks = $this->xpath('//*[@data-announce-track][not(contains(@class,"announce-track--clone"))]//a[contains(@class,"announce-card__link")]', $this->lastDom);
        $this->assertSame(3, $realTrackLinks->length, 'The visible track keeps three real notice links.');

        $links = $this->xpath('//article[contains(@class,"announce-card--real")]//a[contains(@class,"announce-card__link")]', $this->lastDom);
        $this->assertSame(6, $links->length, 'Real + clone carousel tracks duplicate the three real links.');

        foreach ($links as $link) {
            $href = $link instanceof \DOMElement ? (string) $link->getAttribute('href') : '';
            $this->assertMatches('#^/app/notices/[0-9a-f-]{36}$#', $href);
        }

        $this->assertStringNotContainsString('Demo notice', $response->body());
    }

    public function testHomeFeedPrefersRealNoticesEvenWhenPreviewFlagIsOn(): void
    {
        $this->preview(true);
        $notice = $this->insertNotice([
            'title' => 'Real home notice ' . bin2hex(random_bytes(2)),
            'priority' => 'urgent',
        ]);

        $this->ensureLoggedInAs(self::JANE);
        $response = $this->get('/app');

        $this->assertTextContains((string) $notice['title'], $response);
        $this->assertStringNotContainsString('Demo notice', $response->body(), 'Real notices must replace demo preview content.');
    }

    public function testHomeFeedShowsAHonestEmptyStateWhenNoNoticesAreVisible(): void
    {
        // Fixture reset: remove seeded Greenview notices to exercise the empty state.
        $this->db->execute('DELETE FROM estate_notices WHERE estate_id = :id', [
            'id' => (int) $this->seededEstate(self::ESTATE_SLUG)['id'],
        ]);

        $this->preview(false);
        $this->ensureLoggedInAs(self::JANE);

        $response = $this->get('/app');
        $this->assertStatus(200, $response);
        $this->assertTextContains('No estate notices yet', $response);
        $this->assertStringNotContainsString('Demo notice', $response->body());

        // With the preview flag on and no real notices, the labelled demo content
        // is allowed again (layout-review mode only).
        $this->preview(true);
        $withPreview = $this->get('/app');
        $this->assertTextContains('Demo notice', $withPreview);
        $this->assertTextContains('Preview content', $withPreview);
    }
}
