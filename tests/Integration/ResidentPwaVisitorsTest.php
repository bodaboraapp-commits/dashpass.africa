<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;
use DOMElement;

/**
 * Visitors screen after Increment 2 (visitor-access-module-contract §4.2, §7.1):
 * REAL seeded data replaces the old Layout-preview rows, plus pending walk-in
 * approvals and the invite CTA.
 */
final class ResidentPwaVisitorsTest extends VisitorModuleTestCase
{
    private const DEMO_UPCOMING = 'Demo Visitor Three';
    private const DEMO_HISTORY = ['Demo Visitor One', 'Demo Visitor Two'];
    private const DEMO_WALKIN = 'Demo Walk-in Four';

    private function visitors(string $query = '', string $resident = self::JANE): Response
    {
        $this->loginAs($resident);

        return $this->get('/app/visitors' . $query);
    }

    public function testUpcomingTabShowsRealSeededInvitations(): void
    {
        $response = $this->visitors('?tab=upcoming');
        $this->assertStatus(200, $response);

        $h1s = $this->xpath('//h1', $this->dom($response));
        $this->assertCount(1, $h1s);
        $h1 = $h1s->item(0);
        $this->assertNotNull($h1);
        $this->assertSame('Visitors', $this->text($h1));

        $this->assertTextContains(self::DEMO_UPCOMING, $response, 'The resident must see her seeded upcoming invitation.');

        foreach (self::DEMO_HISTORY as $name) {
            $this->assertTextNotContains($name, $response, 'History rows must not render on the Upcoming tab.');
        }

        // Increment 2 replaced the layout preview on this screen.
        $this->assertTextNotContains('Layout preview', $response, 'The Visitors screen must not use preview content any more.');
        $this->assertTextNotContains('Visitor passes ship in the Visitors increment.', $response);
    }

    public function testActivityTabCarriesCompletedVisitsFromHistory(): void
    {
        // Owner request (2026-11-13): the old History tab is gone; tab set is
        // Pending / Upcoming / Activity. Completed visits appear in the Activity
        // timeline as gate check-out events.
        $response = $this->visitors('?tab=activity');
        $this->assertStatus(200, $response);

        foreach (self::DEMO_HISTORY as $name) {
            $this->assertTextContains($name, $response, 'Completed visit ' . $name . ' must appear in the Activity timeline.');
        }

        $this->assertTextContains('Visitor timeline', $response);
        // Old ?tab=history links keep working (they land on the Activity tab).
        $this->assertStatus(200, $this->get('/app/visitors?tab=history'));
    }

    public function testActivityTabShowsTheVisitorTimeline(): void
    {
        // Owner request (2026-11-13): the Visitors tabs are Pending / Upcoming /
        // Activity (Activity also moved out of the bottom navigation).
        $response = $this->visitors('?tab=activity');
        $this->assertStatus(200, $response);
        $this->assertTextContains('Visitor timeline', $response);
        $this->assertTextContains('Activity', $response);

        $dom = $this->dom($response);
        $tab = $this->xpath('//a[@href="/app/visitors?tab=activity"]', $dom)->item(0);
        $this->assertNotNull($tab, 'The Activity tab must be a real server-side link.');
        $this->assertSame('page', strtolower($tab->getAttribute('aria-current')));

        // The activity list (or its honest empty state) renders in the tab.
        $hasList = $this->xpath('//*[@data-activity-list]', $dom)->length >= 1;
        $hasEmpty = $this->xpath('//*[@data-activity-empty]', $dom)->length >= 1;
        $this->assertTrue($hasList || $hasEmpty, 'The Activity tab must render the timeline or its empty state.');
    }

    public function testTabsAreServerSideLinksToTheCanonicalUrls(): void
    {
        $response = $this->visitors();
        $dom = $this->dom($response);

        $pending = $this->xpath('//a[@href="/app/visitors?tab=pending"]', $dom);
        $upcoming = $this->xpath('//a[@href="/app/visitors?tab=upcoming"]', $dom);
        $activity = $this->xpath('//a[@href="/app/visitors?tab=activity"]', $dom);

        $this->assertTrue($pending->length >= 1, 'Pending tab must be a real link to /app/visitors?tab=pending.');
        $this->assertTrue($upcoming->length >= 1, 'Upcoming tab must be a real link to /app/visitors?tab=upcoming.');
        $this->assertTrue($activity->length >= 1, 'Activity tab must be a real link to /app/visitors?tab=activity.');

        $pendingNode = $pending->item(0);
        $upcomingNode = $upcoming->item(0);
        $activityNode = $activity->item(0);
        $this->assertNotNull($pendingNode);
        $this->assertNotNull($upcomingNode);
        $this->assertNotNull($activityNode);
        $this->assertContains('Pending', $this->text($pendingNode));
        $this->assertContains('Upcoming', $this->text($upcomingNode));
        $this->assertContains('Activity', $this->text($activityNode));
    }

    public function testInvalidTabFallsBackToPending(): void
    {
        $this->loginAs(self::JANE);

        foreach (['?tab=bogus', '?tab=HISTORY', '?tab=', '?tab=0'] as $query) {
            $response = $this->get('/app/visitors' . $query);
            $this->assertStatus(200, $response, 'Invalid tab must not error: ' . $query);
            $this->assertTextContains('Pending', $response, 'Invalid tab must fall back to Pending: ' . $query);
            $this->assertTextNotContains(self::DEMO_HISTORY[0], $response);
        }
    }

    public function testPendingWalkInIsShownWithApproveAndRejectActions(): void
    {
        $response = $this->visitors();
        $dom = $this->dom($response);

        $this->assertTextContains(self::DEMO_WALKIN, $response, 'The seeded pending walk-in must be visible to Jane.');

        $pending = $this->db->selectOne(
            'SELECT uuid FROM walk_in_requests WHERE visitor_name = :name AND status = :status',
            ['name' => self::DEMO_WALKIN, 'status' => 'pending']
        );
        $this->assertNotNull($pending);
        $walkInUuid = (string) $pending['uuid'];

        $approveForms = $this->xpath('//form[@action="/app/walkins/' . $walkInUuid . '/approve"]', $dom);
        $rejectForms = $this->xpath('//form[@action="/app/walkins/' . $walkInUuid . '/reject"]', $dom);

        $this->assertTrue($approveForms->length >= 1, 'The pending walk-in must expose an approve form.');
        $this->assertTrue($rejectForms->length >= 1, 'The pending walk-in must expose a reject form.');
    }

    public function testInviteCtaLinksToTheInviteForm(): void
    {
        $response = $this->visitors();
        $dom = $this->dom($response);

        $this->assertTextContains('+ Invite Visitor', $response);

        $inviteLinks = $this->xpath('//a[@href="/app/visitors/invite"]', $dom);
        $this->assertTrue($inviteLinks->length >= 1, 'The CTA must link to /app/visitors/invite.');
    }

    public function testResidentWithoutInvitationsGetsAnHonestEmptyState(): void
    {
        // Amina (B-001) has no seeded visitor invitations.
        $response = $this->visitors('', self::AMINA);
        $this->assertStatus(200, $response);

        $this->assertTextNotContains(self::DEMO_UPCOMING, $response);
        $this->assertTextNotContains(self::DEMO_HISTORY[0], $response);

        $lower = strtolower($this->pageText($response));
        $this->assertTrue(
            str_contains($lower, 'no visitor') || str_contains($lower, 'nothing') || str_contains($lower, 'empty'),
            'A resident with no invitations must see an honest empty state.'
        );
    }

    public function testVisitorsPageIsCspSafeAndAccessible(): void
    {
        $response = $this->visitors();

        $this->assertCleanMarkup($response, 'Visitors');

        $h1s = $this->xpath('//h1', $this->dom($response));
        $this->assertCount(1, $h1s, 'Visitors must render exactly one h1.');

        $back = $this->xpath('//a[@href="/app"][@aria-label or @title]', $this->dom($response));
        $this->assertTrue($back->length >= 1, 'Visitors must offer a labelled back link to /app.');
    }

    public function testVisitorsDoesNotLeakAnotherHouseholdsVisitors(): void
    {
        // Amina (B-001) must never see Jane's (A-001) seeded visitors.
        $response = $this->visitors('?tab=history', self::AMINA);

        foreach (['Demo Visitor One', 'Demo Visitor Two', 'Demo Visitor Three'] as $name) {
            $this->assertTextNotContains($name, $response, 'Another household\'s visitor must never appear: ' . $name);
        }

        $cards = $this->xpath('//li[contains(@class,"visitor-card")]//*[contains(@class,"visitor-card__name")]', $this->dom($response));

        foreach ($cards as $card) {
            if ($card instanceof DOMElement && $this->text($card) !== '') {
                $this->fail('Unexpected visitor card for another household: ' . $this->text($card));
            }
        }
    }
}
