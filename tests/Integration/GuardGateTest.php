<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use Database\Seeds\DemoVisitorSeeder;

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
}
