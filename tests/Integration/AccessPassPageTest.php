<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Context;
use App\Core\Request;
use App\Modules\Residents\HouseholdAccessService;
use Database\Seeds\DemoStaffIncidentSeeder;
use Database\Seeds\DemoVisitorSeeder;

/**
 * "My access pass" full page (owner request 2026-11-13).
 *
 * The page shows the household's people, not visitors: Tenant (primary
 * resident), family members and domestic workers, each with a signed,
 * gate-scannable household pass QR. Household passes are verified by the guard
 * at /gate/verify and never trigger a visitor check-in.
 */
final class AccessPassPageTest extends ResidentPwaTestCase
{
    private const GUARD_MAIN = '+254700000004';

    private const BRIAN_A002 = '+254700000011';

    private function accessPage(string $resident = self::JANE): \App\Core\Response
    {
        $this->loginAs($resident);

        return $this->get('/app/access');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function passesFor(string $resident): array
    {
        $this->loginAs($resident);
        // Establish a fresh in-process request context for this resident so the
        // service reads the right authenticated user and active estate.
        $this->assertStatus(200, $this->get('/app/access'));
        $context = Context::current();
        $this->assertNotNull($context, 'The in-process request context must be available.');

        return HouseholdAccessService::make($context)->passes();
    }

    public function testPageListsHouseholdPassesNotVisitorPasses(): void
    {
        $response = $this->accessPage();
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'My access pass');

        $dom = $this->dom($response);
        $this->assertCount(1, $this->xpath('//h1', $dom));
        $h1 = $this->xpath('//h1', $dom)->item(0);
        $this->assertNotNull($h1);
        $this->assertSame('My access pass', $this->text($h1));

        // Owner request: household people, not visitor passes.
        $this->assertCount(0, $this->xpath('//dialog', $dom), 'The access pass page must not be a dialog.');
        $this->assertStringNotContainsString('Demo Visitor Three', $response->body(), 'Visitor passes must not appear on this page.');
        $this->assertStringNotContainsString('Next visitor', $response->body());

        // Jane (A-001) is the Tenant; the household has a domestic worker.
        $this->assertTextContains('Jane Smith', $response);
        $this->assertTextContains('Tenant', $response);
        $this->assertTextContains('Demo Housekeeper', $response);
        $this->assertTextContains('Domestic worker', $response);
        $this->assertTextContains('A-001', $response);

        $cards = $this->xpath('//div[contains(@class,"access-card")][@data-household-pass]', $dom);
        $this->assertCount(2, $cards, 'Jane\'s household must render the Tenant and the domestic-worker pass.');
    }

    public function testFamilyMemberAndHouseholdWorkerAreListedToo(): void
    {
        // Brian Otieno is a non-primary family member in household A-002, which
        // also has a gardener in the household staff directory.
        $response = $this->accessPage(self::BRIAN_A002);
        $this->assertStatus(200, $response);

        $this->assertTextContains('Family member', $response);
        $this->assertTextContains('Tenant', $response);
        $this->assertTextContains('Demo Gardener', $response);
        $this->assertTextContains('Gardener', $response);
    }

    public function testQrImageEndpointsServeOnlyTheCallersHousehold(): void
    {
        $passes = $this->passesFor(self::JANE);
        $this->assertNotEmpty($passes);

        foreach ($passes as $pass) {
            $response = $this->get('/app/access/qr.png?type=' . rawurlencode((string) $pass['type']) . '&id=' . (int) $pass['id']);
            $this->assertStatus(200, $response);
            $this->assertSame('image/png', (string) $response->header('Content-Type'));
            $this->assertTrue(str_starts_with($response->body(), "\x89PNG"), 'The household pass endpoint must return a PNG.');
        }

        // IDOR: a different household's staff record must not be downloadable.
        $foreign = $this->db->selectOne(
            "SELECT s.id FROM household_staff s WHERE s.uuid = :uuid LIMIT 1",
            ['uuid' => DemoStaffIncidentSeeder::STAFF_DRIVER_UUID]
        );
        $this->assertNotNull($foreign);
        $foreignResponse = $this->get('/app/access/qr.png?type=staff&id=' . (int) $foreign['id']);
        $this->assertStatus(404, $foreignResponse);

        $this->assertStatus(404, $this->get('/app/access/qr.png?type=bogus&id=1'));
    }

    public function testGateVerifiesSignedHouseholdPassWithoutVisitorCheckIn(): void
    {
        $passes = $this->passesFor(self::JANE);
        $this->assertNotEmpty($passes);

        $pass = $passes[0];
        $token = (string) $pass['token'];
        $name = (string) $pass['name'];

        $this->loginAs(self::GUARD_MAIN);
        $gate = $this->db->selectOne(
            "SELECT g.uuid FROM gates g
             INNER JOIN guard_gate_assignments a ON a.gate_id = g.id AND a.guard_user_id = (SELECT id FROM users WHERE phone = :phone)
             WHERE g.name = 'Main Gate' AND g.status = 'active' LIMIT 1",
            ['phone' => self::GUARD_MAIN]
        );
        $this->assertNotNull($gate, 'The seeded main gate assignment is required.');
        $this->assertStatus(302, $this->post('/gate/select-gate', ['gate_uuid' => (string) $gate['uuid']]));

        $verified = $this->post('/gate/verify', ['code' => $token]);
        $this->assertStatus(200, $verified);
        $this->assertTextContains($name, $verified);
        $this->assertTextContains('Household gate pass', $verified);
        $this->assertStringNotContainsString('Check in visitor', $verified->body(), 'Household passes must not offer a visitor check-in.');

        // Tampered tokens are rejected.
        $tampered = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');
        $rejected = $this->post('/gate/verify', ['code' => $tampered]);
        $this->assertStatus(200, $rejected);
        $this->assertTextContains('invalid or inactive', $rejected);
    }

    public function testHomeTileAndCentreButtonLinkToThePage(): void
    {
        $this->loginAs(self::JANE);
        $response = $this->get('/app');
        $dom = $this->dom($response);

        $tile = $this->xpath('//a[contains(@class,"quick-tile")][@href="/app/access"]', $dom);
        $this->assertCount(1, $tile, 'The Access quick tile must link to /app/access.');

        $fab = $this->xpath('//a[contains(@class,"tab-fab")][@href="/app/access"]', $dom);
        $this->assertCount(1, $fab, 'The centre QR button must link to /app/access.');
        $this->assertSame('Open my access pass', $fab->item(0)?->getAttribute('aria-label'));
    }
}
