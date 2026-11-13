<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use Database\Seeds\DemoVisitorSeeder;
use DOMElement;

/**
 * Guard PWA / API gate context and verification entry points
 * (visitor-access-module-contract §4.4, §5, §6, §9).
 */
final class GuardGateTest extends VisitorModuleTestCase
{
    public function testGuardDashboardShapeWithSelectedGate(): void
    {
        $this->loginGuard('Main Gate');

        $payload = $this->assertSuccess($this->get('/api/v1/gate/dashboard'));
        $data = $payload['data'];

        foreach (['gates', 'selected_gate', 'stats', 'active', 'pending_walkins'] as $key) {
            $this->assertArrayHasKey($key, $data, 'Guard dashboard payload is missing ' . $key . '.');
        }

        $this->assertTrue(is_array($data['gates']));
        $this->assertNotEmpty($data['selected_gate']);
        $this->assertSame('Main Gate', (string) $data['selected_gate']['name']);

        foreach (['inside', 'expected', 'pending_walkins'] as $key) {
            $this->assertArrayHasKey($key, $data['stats'], 'Dashboard stats are missing ' . $key . '.');
            $this->assertTrue(is_int($data['stats'][$key]));
        }

        $this->assertTrue($data['stats']['inside'] >= 1, 'The seeded checked-in fixture must count as inside.');
        $this->assertTrue($data['stats']['pending_walkins'] >= 1, 'The seeded pending walk-in must count.');
        $this->assertNotEmpty($data['active'], 'The seeded checked-in fixture must appear in the active list.');
    }

    public function testVerifyWithoutGateContextReturns409(): void
    {
        $this->loginAs(self::GUARD_MAIN);
        Session::forget('guard_gate_id');

        $response = $this->guardVerifyPin(DemoVisitorSeeder::DEMO_PIN);

        $this->assertErrorCode('GATE_CONTEXT_REQUIRED', $response, 409);
    }

    public function testUnassignedGateIsRejectedServerSide(): void
    {
        // Guard John is assigned Main Gate only; force the Service Gate context.
        $this->loginAs(self::GUARD_MAIN);
        $this->forceGateContext('Service Gate');

        $this->assertErrorCode('GATE_NOT_ASSIGNED', $this->guardVerifyPin(DemoVisitorSeeder::DEMO_PIN), 403);

        // The failed resolve clears the invalid gate context, so re-force it to
        // prove the check-in path also enforces the assignment itself.
        $this->forceGateContext('Service Gate');
        $invitation = $this->seededInvitation();
        $this->assertErrorCode('GATE_NOT_ASSIGNED', $this->guardCheckin((string) $invitation['uuid']), 403);
    }

    public function testSelectGateSetsSessionForAssignedGate(): void
    {
        $this->loginAs(self::GUARD_MAIN);
        $gate = $this->gate('Main Gate');

        $response = $this->post('/gate/select-gate', ['gate_uuid' => $gate['uuid']], true);

        $this->assertTrue(in_array($response->status(), [200, 302], true));
        $this->assertSame((int) $gate['id'], (int) Session::get('guard_gate_id'), 'Selecting an assigned gate must set the guard gate context.');
    }

    public function testGuardWebScreensRender(): void
    {
        $this->loginGuard('Main Gate');

        foreach (['/gate', '/gate/verify', '/gate/active', '/gate/walkin'] as $path) {
            $response = $this->get($path);
            $this->assertStatus(200, $response, 'Guard screen ' . $path . ' must render for an assigned guard.');
            $this->assertBodyContains('MtaaPass', $response);
        }
    }

    public function testResidentWithoutCheckinPermissionIsForbiddenFromGuardScreens(): void
    {
        $this->loginAs(self::JANE);

        foreach (['/gate/verify', '/gate/active'] as $path) {
            $response = $this->get($path);
            $this->assertStatus(403, $response, $path . ' must be denied to a resident.');
            $this->assertBodyContains('PERMISSION_DENIED', $response);
        }
    }

    public function testPureGuardIsRedirectedFromResidentHomeToGate(): void
    {
        $this->loginAs(self::GUARD_MAIN);

        $response = $this->get('/app');

        $this->assertRedirect($response, '/gate', 302);
    }

    public function testNoscriptVerifyAndCheckinFormsWork(): void
    {
        $data = $this->createInvitation(['visitor_name' => 'Noscript Visitor']);
        $uuid = (string) $data['invitation_uuid'];

        $this->loginGuard('Main Gate');

        // Plain form post (no JSON) — the server must accept it and redirect/render.
        $verify = $this->post('/gate/verify', ['code' => (string) $data['pin']], true);
        $this->assertTrue(in_array($verify->status(), [200, 302], true), 'Noscript /gate/verify must be accepted (field name: code).');

        $checkin = $this->post('/gate/checkin', ['invitation_uuid' => $uuid], true);
        $this->assertTrue(in_array($checkin->status(), [200, 302], true), 'Noscript /gate/checkin must be accepted.');

        $this->assertSame('checked_in', (string) $this->db->scalar(
            'SELECT status FROM visitor_invitations WHERE uuid = :uuid',
            ['uuid' => $uuid]
        ));

        $checkout = $this->post('/gate/checkout', ['invitation_uuid' => $uuid], true);
        $this->assertTrue(in_array($checkout->status(), [200, 302], true), 'Noscript /gate/checkout must be accepted.');

        $this->assertSame('checked_out', (string) $this->db->scalar(
            'SELECT status FROM visitor_invitations WHERE uuid = :uuid',
            ['uuid' => $uuid]
        ));
    }

    public function testActiveVisitorsEndpointReturnsOnlyEstateVisits(): void
    {
        $this->loginGuard('Main Gate');

        $payload = $this->assertSuccess($this->get('/api/v1/gate/active-visitors'));
        $this->assertArrayHasKey('active', $payload['data']);

        foreach ($payload['data']['active'] as $item) {
            $this->assertArrayHasKey('uuid', $item);
            $this->assertArrayHasKey('visitor_name', $item);
            $this->assertArrayHasKey('status', $item);
        }
    }

    /**
     * The chevron-left icon partial appends `ico--chevron`; the left direction is
     * carried by the path starting `m14.4`.
     */
    private function isChevronLeft(DOMElement $svg): bool
    {
        $classes = preg_split('/\s+/', trim($svg->getAttribute('class'))) ?: [];

        if (!in_array('ico--chevron', $classes, true) && !in_array('ico--chevron-left', $classes, true)) {
            return false;
        }

        foreach ($this->xpath('.//path', $svg) as $path) {
            if ($path instanceof DOMElement && str_starts_with(strtolower(trim($path->getAttribute('d'))), 'm14.4')) {
                return true;
            }
        }

        return false;
    }

    public function testGuardNavigationMatrixHasConsistentBackAndActiveStates(): void
    {
        $this->loginGuard('Main Gate');

        // path => [logical parent (back target), expected active nav href]
        $screens = [
            '/gate/verify' => ['/gate', '/gate/verify'],
            '/gate/walkin' => ['/gate', '/gate/walkin'],
            '/gate/active' => ['/gate', '/gate/active'],
            '/gate/incidents' => ['/gate', '/gate/incidents'],
            '/gate/incidents/new' => ['/gate/incidents', '/gate/incidents'],
            '/gate/notices' => ['/gate', '/gate/notices'],
            '/gate/parking/violations' => ['/gate', '/gate'],
        ];

        foreach ($screens as $path => [$parent, $activeHref]) {
            $response = $this->get($path);
            $this->assertStatus(200, $response, 'Guard screen ' . $path . ' must render for an assigned guard.');
            $this->assertCleanMarkup($response, 'guard ' . $path);
            $dom = $this->dom($response);

            $this->assertCount(1, $this->xpath('//h1', $dom), $path . ' must render exactly one h1.');

            // Exactly one header back control: a plain anchor with a chevron-left
            // icon and an accessible "Back ..." name pointing at the logical parent.
            $backs = $this->xpath(
                '//header[contains(concat(" ", normalize-space(@class), " "), " g-head ")]'
                . '//a[contains(concat(" ", normalize-space(@class), " "), " g-head__link ")][starts-with(@aria-label, "Back")]',
                $dom
            );
            $this->assertCount(1, $backs, $path . ' must render exactly one guard header back control.');

            $back = $backs->item(0);
            $this->assertInstanceOf(DOMElement::class, $back);
            $this->assertSame('a', strtolower($back->tagName));
            $this->assertSame($parent, trim($back->getAttribute('href')), $path . ' back control must point at ' . $parent . '.');
            $this->assertSame(trim($back->getAttribute('aria-label')), trim($back->getAttribute('title')), $path . ' back aria-label/title must agree.');

            $icons = $this->xpath('.//svg', $back);
            $this->assertCount(1, $icons, $path . ' back control must carry one icon.');
            $icon = $icons->item(0);
            $this->assertInstanceOf(DOMElement::class, $icon);
            $this->assertTrue($this->isChevronLeft($icon), $path . ' back control must use the chevron-left icon.');

            // Exactly one active item in both the desktop sidebar and the tabbar.
            foreach (['guard-nav', 'guard-tabbar'] as $navClass) {
                $active = $this->xpath(
                    '//nav[contains(concat(" ", normalize-space(@class), " "), " ' . $navClass . ' ")]//a[@aria-current="page"]',
                    $dom
                );
                $this->assertCount(1, $active, $path . ' must mark exactly one active item in .' . $navClass . '.');

                $activeNode = $active->item(0);
                $this->assertInstanceOf(DOMElement::class, $activeNode);
                $this->assertSame($activeHref, trim($activeNode->getAttribute('href')), $path . ' must activate ' . $activeHref . ' in .' . $navClass . '.');
            }

            // No dead/placeholder navigation on guard pages.
            $this->assertCount(0, $this->xpath('//a[@href="#"]', $dom), $path . ' must not contain dead links.');
            $this->assertCount(0, $this->xpath('//*[@data-soon]', $dom), $path . ' must not contain "Soon" placeholders.');
        }

        // Dashboard is the guard root: no back control, dashboard nav active.
        $dashboard = $this->get('/gate');
        $this->assertStatus(200, $dashboard);
        $dashboardDom = $this->dom($dashboard);
        $this->assertCount(
            0,
            $this->xpath('//header[contains(concat(" ", normalize-space(@class), " "), " g-head ")]//a[starts-with(@aria-label, "Back")]', $dashboardDom),
            'The guard dashboard root must not render a back control.'
        );
        $dashboardActive = $this->xpath('//nav[contains(@class,"guard-nav")]//a[@aria-current="page"]', $dashboardDom);
        $this->assertCount(1, $dashboardActive);
        $this->assertSame('/gate', trim((string) $dashboardActive->item(0)?->getAttribute('href')));
    }
}
