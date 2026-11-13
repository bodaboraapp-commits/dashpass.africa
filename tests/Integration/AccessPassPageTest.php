<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;
use Database\Seeds\DemoVisitorSeeder;

/**
 * "My access pass" full page (owner request 2026-11-13: the access QR is a real
 * page, not a modal dialog). Reached from the Home Access tile and the centre QR
 * button; shows the resident's next visitor pass (visitor, household, validity,
 * status, server-rendered QR, Open pass link, estate) or an honest empty state.
 */
final class AccessPassPageTest extends ResidentPwaTestCase
{
    private function accessPage(string $resident = self::JANE): Response
    {
        $this->loginAs($resident);

        return $this->get('/app/access');
    }

    private function passUuid(): string
    {
        return DemoVisitorSeeder::INVITATION_UPCOMING_UUID;
    }

    public function testAccessPassIsAFullPageNotADialog(): void
    {
        $response = $this->accessPage();

        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'My access pass');
        $dom = $this->dom($response);

        $this->assertCount(1, $this->xpath('//h1', $dom), 'The access page must render exactly one h1.');
        $h1 = $this->xpath('//h1', $dom)->item(0);
        $this->assertNotNull($h1);
        $this->assertSame('My access pass', $this->text($h1));

        // Owner request: this is a page, not a pop-up modal.
        $this->assertCount(0, $this->xpath('//dialog', $dom), 'The access QR must not render as a <dialog> any more.');
        $this->assertCount(0, $this->xpath('//*[@id="qr-sheet"]', $dom), 'The old QR sheet markup must be gone.');

        // Pushed-screen back affordance to Home.
        $back = $this->xpath('//header[contains(@class,"screen-bar")]/a[contains(@class,"screen-bar__action")]', $dom);
        $this->assertCount(1, $back, 'The access page must expose one back control.');
        $this->assertSame('/app', $back->item(0)?->getAttribute('href'));
    }

    public function testNextPassContentQrAndOpenPassLink(): void
    {
        $response = $this->accessPage();
        $this->assertStatus(200, $response);

        $this->assertTextContains('Next visitor', $response);
        $this->assertTextContains('Demo Visitor Three', $response);
        $this->assertTextContains('Show this QR at the gate', $response);
        $this->assertTextContains('Approved', $response);

        $dom = $this->dom($response);

        $qr = $this->xpath('//img[contains(@class,"access-card__qr")]', $dom)->item(0);
        $this->assertNotNull($qr, 'The access page must show the server-rendered QR image.');
        $this->assertTrue(str_starts_with((string) $qr->getAttribute('src'), '/app/passes/'));
        $this->assertTrue(str_ends_with((string) $qr->getAttribute('src'), '/qr.png'));

        $openPass = $this->xpath('//a[contains(normalize-space(.),"Open pass")]', $dom)->item(0);
        $this->assertNotNull($openPass);
        $this->assertSame('/app/passes/' . $this->passUuid(), $openPass->getAttribute('href'));

        $close = $this->xpath('//a[contains(@class,"btn--outline")][normalize-space(.)="Close"]', $dom)->item(0);
        $this->assertNotNull($close, 'A Close control must return to Home.');
        $this->assertSame('/app', $close->getAttribute('href'));

        $this->assertTextContains('Greenview Residences', $response);
    }

    public function testAccessPageNeverEmbedsTheBearerTokenOrPin(): void
    {
        $response = $this->accessPage();
        $dom = $this->dom($response);
        $section = $this->xpath('//section[contains(@class,"access-page")]', $dom)->item(0);
        $this->assertNotNull($section, 'The access pass card section must render.');
        // Scope to the access content: the document <head> legitimately carries the
        // 64-hex CSRF token meta, so scan only the rendered pass section.
        $markup = (string) $section->ownerDocument?->saveHTML($section);

        $this->assertFalse(str_contains($markup, '/v/'), 'The public bearer pass URL must not be embedded.');
        $this->assertFalse((bool) preg_match('/\b[0-9a-f]{64}\b/i', $markup), 'The QR token must not appear in the access content.');
        $this->assertStringNotContainsString(DemoVisitorSeeder::DEMO_PIN, $markup, 'The PIN must never appear on the access page.');
    }

    public function testHonestEmptyStateWhenResidentHasNoPass(): void
    {
        // Amina (B-001) has no seeded visitor invitations.
        $response = $this->accessPage(self::AMINA);
        $this->assertStatus(200, $response);
        $this->assertTextContains('No active visitor pass', $response);

        $invite = $this->xpath('//a[@href="/app/visitors/invite"]', $this->dom($response));
        $this->assertTrue($invite->length >= 1, 'The empty state must offer an invite shortcut.');
        $this->assertCount(0, $this->xpath('//img[contains(@class,"access-card__qr")]', $this->dom($response)), 'No QR should render without a pass.');
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
