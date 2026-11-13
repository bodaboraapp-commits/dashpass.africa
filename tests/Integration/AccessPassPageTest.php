<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Context;
use App\Modules\Residents\HouseholdAccessService;
use Database\Seeds\DemoStaffIncidentSeeder;

/**
 * "My access pass" page (owner request 2026-11-13, revised):
 *  - /app/access shows ONLY the signed-in resident's own QR and pass code, plus
 *    a button that opens the household-member pass creation flow;
 *  - /app/access/member creates/displays a signed gate-scannable pass for a
 *    family member or domestic worker in the same household (never visitors);
 *  - /gate/verify accepts the household pass code/token with no visitor check-in.
 */
final class AccessPassPageTest extends ResidentPwaTestCase
{
    private const GUARD_MAIN = '+254700000004';

    private function loginAndGet(string $path, string $resident = self::JANE): \App\Core\Response
    {
        $this->loginAs($resident);

        return $this->get($path);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function passesFor(string $resident): array
    {
        $this->loginAs($resident);
        $this->assertStatus(200, $this->get('/app/access'));
        $context = Context::current();
        $this->assertNotNull($context);

        return HouseholdAccessService::make($context)->passes();
    }

    public function testPageShowsOnlyTheSignedInUsersOwnPass(): void
    {
        $response = $this->loginAndGet('/app/access');
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'My access pass');

        $dom = $this->dom($response);
        $this->assertCount(1, $this->xpath('//h1', $dom));
        $h1 = $this->xpath('//h1', $dom)->item(0);
        $this->assertNotNull($h1);
        $this->assertSame('My access pass', $this->text($h1));

        // Only the logged-in user's own pass card (QR) is rendered.
        $this->assertCount(1, $this->xpath('//div[@class="card access-card"]', $dom), 'The page must show exactly one personal pass card.');
        $this->assertCount(1, $this->xpath('//img[contains(@class,"access-card__qr")]', $dom), 'Only the personal pass has a QR image.');
        $this->assertTextContains('Jane Smith', $response);
        $this->assertTextContains('Tenant', $response);
        // Visitors never appear on this page.
        $this->assertStringNotContainsString('Demo Visitor Three', $response->body());
        $this->assertStringNotContainsString('Next visitor', $response->body());

        // Owner request: the household member list is shown, with View pass links.
        $this->assertTextContains('Household members', $response);
        $rows = $this->xpath('//li[contains(@class,"household-member")]', $dom);
        $this->assertCount(2, $rows, 'Jane\'s household list must show the Tenant and the domestic worker.');
        $selfRow = $this->xpath('//li[contains(@class,"household-member")][.//*[contains(@class,"household-member__self")]]', $dom);
        $this->assertCount(1, $selfRow, 'The signed-in resident must be marked as the owner of the pass.');
        $housekeeperRow = $this->xpath('//li[contains(@class,"household-member")][.//*[contains(normalize-space(.),"Demo Housekeeper")]]', $dom)->item(0);
        $this->assertNotNull($housekeeperRow, 'The domestic worker must appear in the household member list.');
        $viewPass = $this->xpath('.//a[contains(normalize-space(.),"View pass")]', $housekeeperRow)->item(0);
        $this->assertNotNull($viewPass, 'Other household members must link to their pass.');
        $this->assertStringContainsString('/app/access/member?type=staff&id=', (string) $viewPass->getAttribute('href'));

        // QR image for the personal pass.
        $qr = $this->xpath('//img[contains(@class,"access-card__qr")]', $dom)->item(0);
        $this->assertNotNull($qr);
        $this->assertStringContainsString('/app/access/qr.png?type=member', (string) $qr->getAttribute('src'));

        // Pass code is shown as a simple 4-digit number (owner request), not the token.
        $code = $this->xpath('//*[@data-pass-code]', $dom)->item(0);
        $this->assertNotNull($code, 'The personal pass code must be shown.');
        $this->assertMatchesRegularExpression('/^\d{4}$/', trim($this->text($code)));
        $this->assertStringNotContainsString('hpa1.', trim($this->text($code)));

        // Button to create a pass for a household member.
        $create = $this->xpath('//a[@href="/app/access/member"]', $dom)->item(0);
        $this->assertNotNull($create, 'A button must offer creating a household member pass.');
        $this->assertTextContains('Create pass for a household member', $response);
    }

    public function testMemberFlowListsFamilyAndDomesticWorkersNotVisitors(): void
    {
        $response = $this->loginAndGet('/app/access/member');
        $this->assertStatus(200, $response);
        $dom = $this->dom($response);

        $form = $this->xpath('//form[@action="/app/access/member"]', $dom)->item(0);
        $this->assertNotNull($form, 'The create-member page must render a form.');
        $this->assertCount(1, $this->xpath('.//input[@name="_token"]', $form), 'The form must carry the CSRF token.');

        $options = $this->xpath('.//select[@name="member"]/option[@value!=""]', $dom);
        $this->assertTrue($options->length >= 1, 'The select must offer at least one household person.');

        foreach ($options as $option) {
            $value = (string) $option->getAttribute('value');
            $this->assertTrue(
                str_starts_with($value, 'member:') || str_starts_with($value, 'staff:'),
                'Options must be household members or domestic workers: ' . $value
            );
        }

        $this->assertTextContains('Demo Housekeeper', $response);
        $this->assertTextContains('Domestic worker', $response);
        $this->assertStringNotContainsString('Demo Visitor Three', $response->body());
        // The signed-in user's own pass is not offered again.
        $this->assertStringNotContainsString('Jane Smith (Tenant)', $response->body());
    }

    public function testCreatingAMemberPassShowsItsQrAndCode(): void
    {
        $this->passesFor(self::JANE);
        $context = Context::current();
        $this->assertNotNull($context);
        $others = HouseholdAccessService::make($context)->othersPasses();
        $this->assertNotEmpty($others, 'Jane\'s household has a domestic worker pass to create.');
        $staffPass = $others[0];

        $created = $this->post('/app/access/member', [
            'member' => (string) $staffPass['type'] . ':' . (int) $staffPass['id'],
        ]);
        $this->assertStatus(302, $created);
        $location = (string) $created->header('Location');
        $this->assertStringContainsString('/app/access/member?type=staff&id=', $location);

        $page = $this->get($location);
        $this->assertStatus(200, $page);
        $dom = $this->dom($page);
        $this->assertNotNull($this->xpath('//div[@data-created-pass]', $dom)->item(0), 'The created member pass must render.');
        $this->assertTextContains('Demo Housekeeper', $page);
        $this->assertNotNull($this->xpath('//img[contains(@class,"access-card__qr")]', $dom)->item(0));
        $code = $this->xpath('//*[@data-pass-code]', $dom)->item(0);
        $this->assertNotNull($code);
        $this->assertMatchesRegularExpression('/^\d{4}$/', trim($this->text($code)));
    }

    public function testCreatingAPassForAForeignPersonIsRejected(): void
    {
        $foreign = $this->db->selectOne(
            'SELECT s.id FROM household_staff s WHERE s.uuid = :uuid LIMIT 1',
            ['uuid' => DemoStaffIncidentSeeder::STAFF_DRIVER_UUID]
        );
        $this->assertNotNull($foreign);

        $this->loginAs(self::JANE);
        $response = $this->post('/app/access/member', ['member' => 'staff:' . (int) $foreign['id']]);
        $this->assertStatus(302, $response);
        $this->assertStringContainsString('/app/access/member', (string) $response->header('Location'));

        $page = $this->get('/app/access/member');
        $this->assertStatus(200, $page);
        $this->assertTextContains('Choose an active household member or domestic worker.', $page);
        $this->assertCount(0, $this->xpath('//div[@data-created-pass]', $this->dom($page)));
    }

    public function testQrImageEndpointsServeOnlyTheCallersHousehold(): void
    {
        $passes = $this->passesFor(self::JANE);
        $this->assertNotEmpty($passes);

        foreach ($passes as $pass) {
            $response = $this->get('/app/access/qr.png?type=' . rawurlencode((string) $pass['type']) . '&id=' . (int) $pass['id']);
            $this->assertStatus(200, $response);
            $this->assertSame('image/png', (string) $response->header('Content-Type'));
            $this->assertTrue(str_starts_with($response->body(), "\x89PNG"));
        }

        $foreign = $this->db->selectOne(
            'SELECT s.id FROM household_staff s WHERE s.uuid = :uuid LIMIT 1',
            ['uuid' => DemoStaffIncidentSeeder::STAFF_DRIVER_UUID]
        );
        $this->assertNotNull($foreign);
        $this->assertStatus(404, $this->get('/app/access/qr.png?type=staff&id=' . (int) $foreign['id']));
        $this->assertStatus(404, $this->get('/app/access/qr.png?type=bogus&id=1'));
    }

    public function testGateVerifiesHouseholdPassCodeAndQrTokenWithoutVisitorCheckIn(): void
    {
        $passes = $this->passesFor(self::JANE);
        $this->assertNotEmpty($passes);
        $pass = $passes[0];
        $token = (string) $pass['token'];
        $passCode = (string) $pass['pass_code'];
        $name = (string) $pass['name'];
        $this->assertMatchesRegularExpression('/^\d{4}$/', $passCode);

        $this->loginAs(self::GUARD_MAIN);
        $gate = $this->db->selectOne(
            "SELECT g.uuid FROM gates g
             INNER JOIN guard_gate_assignments a ON a.gate_id = g.id AND a.guard_user_id = (SELECT id FROM users WHERE phone = :phone)
             WHERE g.name = 'Main Gate' AND g.status = 'active' LIMIT 1",
            ['phone' => self::GUARD_MAIN]
        );
        $this->assertNotNull($gate);
        $this->assertStatus(302, $this->post('/gate/select-gate', ['gate_uuid' => (string) $gate['uuid']]));

        // Owner request: the 4-digit code is the human pass code the guard types.
        $verifiedByCode = $this->post('/gate/verify', ['code' => $passCode]);
        $this->assertStatus(200, $verifiedByCode);
        $this->assertTextContains($name, $verifiedByCode);
        $this->assertTextContains('Household gate pass', $verifiedByCode);
        $this->assertStringNotContainsString('Check in visitor', $verifiedByCode->body());

        // The QR token still verifies (scanning path).
        $verifiedByQr = $this->post('/gate/verify', ['code' => $token]);
        $this->assertStatus(200, $verifiedByQr);
        $this->assertTextContains($name, $verifiedByQr);

        // A wrong 4-digit code is rejected, as is a tampered QR token.
        $wrong = str_pad((string) ((((int) $passCode) + 1) % 10000), 4, '0', STR_PAD_LEFT);
        $rejected = $this->post('/gate/verify', ['code' => $wrong]);
        $this->assertStatus(200, $rejected);
        $this->assertTextContains('invalid or inactive', $rejected);

        $tampered = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');
        $rejectedToken = $this->post('/gate/verify', ['code' => $tampered]);
        $this->assertStatus(200, $rejectedToken);
        $this->assertTextContains('invalid or inactive', $rejectedToken);
    }

    public function testHomeTileAndCentreButtonLinkToThePage(): void
    {
        $this->loginAs(self::JANE);
        $dom = $this->dom($this->get('/app'));

        $this->assertCount(1, $this->xpath('//a[contains(@class,"quick-tile")][@href="/app/access"]', $dom));
        $fab = $this->xpath('//a[contains(@class,"tab-fab")][@href="/app/access"]', $dom);
        $this->assertCount(1, $fab);
        $this->assertSame('Open my access pass', $fab->item(0)?->getAttribute('aria-label'));
    }
}
