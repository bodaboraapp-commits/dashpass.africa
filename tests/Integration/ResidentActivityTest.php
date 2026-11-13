<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Response;
use App\Core\Uuid;
use App\Modules\Residents\ResidentActivityService;
use Database\Seeds\DemoVisitorSeeder;
use DOMElement;

/**
 * Resident Activity screen — own-household visitor timeline.
 *
 * The screen replaces the last resident "Soon" navigation item. It merges gate
 * access events (check in/out, denied, manual override) with invitation issue
 * events for the authenticated resident's own households, newest first, and is
 * strictly household-scoped: a resident of household B-001 must never see
 * household A-001's activity and a user without households gets the honest empty
 * state.
 */
final class ResidentActivityTest extends ResidentPwaTestCase
{
    /** Stable seeded invitations for Jane Smith's household A-001. */
    private const JANE_INVITATIONS = [
        DemoVisitorSeeder::INVITATION_HISTORY_ONE_UUID,
        DemoVisitorSeeder::INVITATION_HISTORY_TWO_UUID,
        DemoVisitorSeeder::INVITATION_UPCOMING_UUID,
    ];

    private function activity(): Response
    {
        $this->loginResident();

        return $this->get('/app/activity');
    }

    /**
     * @return array<int, DOMElement>
     */
    private function activityItems(Response $response): array
    {
        $items = [];

        foreach ($this->xpath('//*[@data-activity-list]/li[@data-activity-kind]', $this->dom($response)) as $item) {
            if ($item instanceof DOMElement) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private function activityKinds(Response $response): array
    {
        return array_map(
            static fn (DOMElement $item): string => (string) $item->getAttribute('data-activity-kind'),
            $this->activityItems($response)
        );
    }

    /**
     * @return array<int, string>
     */
    private function activityHrefs(Response $response): array
    {
        $hrefs = [];

        foreach ($this->xpath('//*[@data-activity-list]//a[starts-with(@href,"/app/passes/")]', $this->dom($response)) as $link) {
            if ($link instanceof DOMElement) {
                $hrefs[] = (string) $link->getAttribute('href');
            }
        }

        return $hrefs;
    }

    public function testResidentSeesOwnHouseholdTimelineNewestFirst(): void
    {
        $response = $this->activity();
        $this->assertStatus(200, $response, 'GET /app/activity must render for an authenticated resident with an estate.');
        $this->assertCleanMarkup($response, 'Activity');

        $dom = $this->dom($response);

        $h1s = $this->xpath('//h1', $dom);
        $this->assertCount(1, $h1s, 'The Activity screen must render exactly one h1.');
        $h1 = $h1s->item(0);
        $this->assertNotNull($h1);
        $this->assertSame('Activity', $this->text($h1));

        // 3 seeded invitation issue events + 4 gate events (check in/out twice).
        $items = $this->activityItems($response);
        $this->assertTrue(count($items) >= 7, 'Jane must see invitation issue events and gate events for household A-001 (got ' . count($items) . ').');
        $this->assertCount(0, $this->xpath('//*[@data-activity-empty]', $dom), 'A resident with activity must not get the empty state.');

        // Newest first: the raw DB datetimes must be non-increasing.
        $previous = null;
        foreach ($items as $item) {
            $at = (string) $item->getAttribute('data-activity-at');
            $this->assertTrue($at !== '', 'Every timeline item must carry its DB timestamp.');
            if ($previous !== null) {
                $this->assertTrue(strcmp($previous, $at) >= 0, 'Timeline items must be ordered newest first (' . $previous . ' < ' . $at . ').');
            }
            $previous = $at;
        }

        $kinds = $this->activityKinds($response);
        $this->assertContains('invitation', $kinds, 'Invitation issue events must be part of the timeline.');
        $this->assertContains('check_in', $kinds, 'Gate check-in events must be part of the timeline.');
        $this->assertContains('check_out', $kinds, 'Gate check-out events must be part of the timeline.');

        // Visitor, household and gate labels (escaped output of the joined rows).
        $text = $this->text($dom->documentElement);
        foreach (['Demo Visitor One', 'Demo Visitor Two', 'Demo Visitor Three', 'A-001 · Block A', 'Main Gate', 'Checked in', 'Checked out', 'Invitation issued'] as $needle) {
            $this->assertTrue(str_contains($text, $needle), 'The Activity timeline must contain "' . $needle . '".');
        }

        // Pass links resolve to the invitation UUIDs of Jane's own household.
        $hrefs = $this->activityHrefs($response);
        foreach (self::JANE_INVITATIONS as $invitationUuid) {
            $this->assertContains('/app/passes/' . $invitationUuid, $hrefs, 'The timeline must link to pass ' . $invitationUuid . '.');
        }

        // The A-002 checked-in demo visit must never appear on Jane's timeline.
        $this->assertNotContains('/app/passes/' . DemoVisitorSeeder::INVITATION_INSIDE_UUID, $hrefs, 'Another household\'s invitation must not leak into the timeline.');
        $this->assertTrue(!str_contains($text, 'Demo Visitor Five'), 'Another household\'s visitor must not appear on Jane\'s timeline.');
    }

    public function testHouseholdIsolationHidesOtherHouseholdsActivity(): void
    {
        // Amina Hassan is a resident of household B-001; it has no visitor activity.
        $this->loginAs(self::AMINA);
        $response = $this->get('/app/activity');
        $this->assertStatus(200, $response, 'GET /app/activity must render for a resident without activity.');

        $dom = $this->dom($response);
        $this->assertCount(0, $this->xpath('//*[@data-activity-list]', $dom), 'B-001 must not list A-001 timeline items.');
        $this->assertCount(1, $this->xpath('//*[@data-activity-empty]', $dom), 'B-001 must get the honest empty state.');

        $text = $this->pageText($response);
        foreach (['Demo Visitor One', 'Demo Visitor Two', 'Demo Visitor Three', 'Demo Visitor Five', 'Main Gate', 'A-001'] as $needle) {
            $this->assertStringNotContainsString($needle, $text, 'A-001 activity must not leak to the B-001 resident: "' . $needle . '".');
        }

        $this->assertCount(0, $this->xpath('//a[starts-with(@href,"/app/passes/")]', $dom), 'B-001 must not receive pass links for A-001 invitations.');

        // The service itself is scoped from Context, not only the view.
        $context = Context::current();
        $this->assertNotNull($context);
        $this->assertSame([], ResidentActivityService::make($context)->timeline(50), 'The service must return no items for a household without activity.');
    }

    public function testHouseholdWithoutActivitySeesEmptyStateWithInviteCta(): void
    {
        // Grace Wanjiku is an estate_admin: she has an active estate but no household.
        $this->loginAs(self::GRACE);
        $response = $this->get('/app/activity');
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'Activity (empty state)');

        $dom = $this->dom($response);
        $this->assertCount(1, $this->xpath('//*[@data-activity-empty]', $dom), 'A user without households must get the empty state.');
        $this->assertTextContains('No activity yet', $response);
        $this->assertCount(1, $this->xpath('//*[@data-activity-empty]//a[@href="/app/visitors/invite"]', $dom), 'The empty state must link to the invite screen.');
        $this->assertCount(1, $this->xpath('//h1', $dom), 'The empty state keeps a single h1.');
    }

    public function testDeniedAndManualOverrideEventsRender(): void
    {
        $this->loginResident();

        $invitation = $this->db->selectOne(
            'SELECT id, estate_id FROM visitor_invitations WHERE uuid = :uuid LIMIT 1',
            ['uuid' => DemoVisitorSeeder::INVITATION_HISTORY_ONE_UUID]
        );
        $this->assertNotNull($invitation, 'The seeded A-001 invitation must exist.');

        $estateId = (int) $invitation['estate_id'];
        $gate = $this->db->selectOne(
            'SELECT id FROM gates WHERE estate_id = :estate_id AND name = :name LIMIT 1',
            ['estate_id' => $estateId, 'name' => 'Main Gate']
        );
        $this->assertNotNull($gate, 'The seeded Main Gate must exist.');
        $guard = $this->seededUser('+254700000004');
        $now = Clock::nowDb();

        foreach (['denied' => $now, 'manual_override' => Clock::toDb(Clock::now()->modify('-1 minute'))] as $eventType => $occurredAt) {
            $this->db->insert('visitor_access_events', [
                'uuid' => Uuid::v4(),
                'event_uuid' => Uuid::v4(),
                'estate_id' => $estateId,
                'invitation_id' => (int) $invitation['id'],
                'gate_id' => (int) $gate['id'],
                'event_type' => $eventType,
                'actor_id' => (int) $guard['id'],
                'occurred_at' => $occurredAt,
                'source' => 'online',
                'created_at' => $occurredAt,
            ]);
        }

        $response = $this->get('/app/activity');
        $this->assertStatus(200, $response);
        $this->assertTextContains('Denied', $response, 'A denied gate event must render.');
        $this->assertTextContains('Manual override', $response, 'A manual override gate event must render.');

        $kinds = $this->activityKinds($response);
        $this->assertSame('denied', $kinds[0] ?? null, 'The newest inserted event (denied) must be the first timeline item.');
        $this->assertContains('manual_override', $kinds);
    }

    public function testTimelineIsCappedAtTheRequestedLimit(): void
    {
        $response = $this->activity();
        $this->assertStatus(200, $response);
        $this->assertTrue(count($this->activityItems($response)) <= 50, 'The Activity page renders at most 50 items.');

        $context = Context::current();
        $this->assertNotNull($context);
        $service = ResidentActivityService::make($context);
        $this->assertCount(3, $service->timeline(3), 'The service honours an explicit item limit.');
        $this->assertTrue(count($service->timeline()) <= 50, 'The default service limit is 50.');
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->resetSession();

        $this->assertRedirect($this->get('/app/activity'), '/login', 302);
    }

    public function testActivityNavigationItemIsActive(): void
    {
        $response = $this->activity();
        $this->assertStatus(200, $response);
        $dom = $this->dom($response);

        $nav = $this->bottomNav($response);
        $this->assertNotNull($nav, 'The Activity screen must keep the bottom navigation.');

        $slots = $this->navSlots($response);
        $this->assertCount(5, $slots, 'The bottom navigation must keep exactly five slots.');

        // Owner request (2026-11-13): Activity moved out of the bottom nav into
        // the Visitors tabs, so slot 3 is now Goods on every resident screen.
        $this->assertSame('a', strtolower($slots[3]->tagName), 'Goods must be an anchor.');
        $this->assertSame('/app/goods', $slots[3]->getAttribute('href'));
        $this->assertContains('Goods', $this->text($slots[3]));
        $this->assertSame('', trim((string) $slots[3]->getAttribute('data-soon')), 'Goods must not remain a Soon action.');

        $this->assertCount(0, $this->xpath('.//*[@aria-current="page"]', $nav), 'No bottom-nav slot is active on the Activity screen (Activity lives in the Visitors tabs).');

        // The desktop sidebar exposes the same real link and active state.
        $sidebar = $this->xpath('//nav[@aria-label="Sections"]//a[@href="/app/activity"]', $dom);
        $this->assertCount(1, $sidebar, 'The sidebar must render one real Activity link.');
        $sidebarNode = $sidebar->item(0);
        $this->assertNotNull($sidebarNode);
        $this->assertSame('page', strtolower($sidebarNode->getAttribute('aria-current')));
        $this->assertContains('nav-item--active', $sidebarNode->getAttribute('class'));

        // No Activity "Soon" affordance survives anywhere on the screen.
        $this->assertCount(0, $this->xpath('//*[@data-soon and contains(@data-soon,"Activity")]', $dom), 'No Activity Soon affordance may remain.');
    }
}
