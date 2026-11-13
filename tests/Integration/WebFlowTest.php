<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;

/**
 * Browser (form) authentication flow from contract §8.2: phone step, OTP step,
 * intended-URL redirect, logout and the clearly labelled development OTP panel.
 */
final class WebFlowTest extends IntegrationTestCase
{
    private const JANE = '+254700000010';

    /**
     * Submit the phone step and resolve the development OTP that the web flow
     * exposes (session flash first, rendered development panel as fallback).
     *
     * The implementation redirects back to `/login`, which renders the verify
     * step whenever `login_phone` is in the session (the route table has no
     * separate `/login/verify` GET route; see the T4 report for this note).
     */
    private function submitPhoneStep(string $phone): string
    {
        $response = $this->post('/login/otp', ['phone' => $phone], true);

        $this->assertRedirect($response, '/login');
        $this->assertSame($phone, (string) Session::get('login_phone'), 'The phone step must remember login_phone in the session.');

        $flash = Session::get('_flash');
        $code = is_array($flash) ? ($flash['dev_otp'] ?? null) : null;

        if (is_string($code) && $code !== '') {
            return $code;
        }

        $page = $this->get('/login');
        $this->assertStatus(200, $page);

        if (preg_match('/\b(\d{6})\b/', $page->body(), $matches) === 1) {
            return $matches[1];
        }

        $this->fail('The development OTP is not available from the web login flow (flash and rendered panel both missing).');
    }

    public function testGuestWelcomePageRenders(): void
    {
        $this->resetSession();

        $response = $this->get('/');

        $this->assertStatus(200, $response);
        $this->assertBodyContains('MtaaPass', $response);
    }

    public function testLandingPageRendersSimpleMarketingStructure(): void
    {
        $this->resetSession();
        $response = $this->get('/');
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'Landing');
        $dom = $this->dom($response);
        $this->assertCount(1, $this->xpath('//h1', $dom), 'Landing must render exactly one h1.');
        $this->assertStringContainsString('DashPASS: Pass &amp; Pay', $response->body());
        $this->assertTextContains('DashPASS: Pass & Pay Management System', $response);
        $this->assertStringContainsString('Management System', $response->body());
        $this->assertStringNotContainsString('Visitor', $response->body(), 'Owner request: the landing page must not show the word "Visitor".');
        $this->assertCount(1, $this->xpath('//*[@data-theme-toggle]', $dom), 'Landing must keep exactly one theme toggle.');
        $this->assertBodyContains('PASS &amp; PAY AUTOMATION', $response);
        $this->assertBodyContains('Check in', $response);
        $this->assertBodyContains('Scan QR', $response);
        $this->assertBodyContains('hello@dashpass.africa', $response);
        $this->assertStringContainsString('Copyright © 2026 DashPASS', $response->body());
        $this->assertCount(0, $this->xpath('//*[@id="about"]', $dom), 'Landing must not carry the About section.');
        // Owner request: the old "Sign in to check in or scan a visitor pass." helper
        // is replaced by six short value-prop labels (icon + label, no descriptions).
        $this->assertCount(1, $this->xpath('//section[contains(@class,"landing-values")]', $dom), 'Landing must render the value-prop section.');
        $this->assertStringNotContainsString('Sign in to check in or scan a visitor pass.', $response->body(), 'The old landing helper sentence must be replaced by the value props.');
        $this->assertCount(6, $this->xpath('//section[contains(@class,"landing-values")]//div[contains(@class,"landing-value")]', $dom), 'Landing must render six value-prop items.');
        $this->assertCount(6, $this->xpath('//section[contains(@class,"landing-values")]//div[contains(@class,"landing-value")]/h2', $dom), 'Each value-prop item must carry a short label.');
        $this->assertCount(0, $this->xpath('//section[contains(@class,"landing-values")]//div[contains(@class,"landing-value")]/p', $dom), 'Owner request: the value props must stay label-only (no wordy description paragraphs).');
        $this->assertCount(6, $this->xpath('//section[contains(@class,"landing-values")]//span[contains(@class,"landing-value__icon")][@aria-hidden="true"]', $dom), 'Each value-prop item must carry a decorative icon tile.');
        // Owner request (2026-11-13): icons come from Google Fonts Icons (Material
        // Symbols, inlined as SVG by partials/icon.php) — never emoji glyphs.
        $this->assertCount(6, $this->xpath('//section[contains(@class,"landing-values")]//span[contains(@class,"landing-value__icon")]/svg[contains(@class,"ico")]', $dom), 'Each value-prop tile must render an inline Material icon SVG.');
        $this->assertFalse(
            (bool) preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $response->body()),
            'The landing page must not use emoji glyphs for its value-prop icons.'
        );
        foreach ([
            'Automated Access',
            'Frictionless Payment',
            'On spot Recognition',
            'Instant Alerts',
            'Financial & Security Logs',
            'Blacklisting',
        ] as $valueTitle) {
            $this->assertTextContains($valueTitle, $response);
        }
        $this->assertCount(0, $this->xpath('//*[@id="welcome-title"]', $dom), 'Landing must not reuse the old welcome heading.');

        // Owner request (2026-11-13): the top-nav labels are PASS / PAY / HELP? and
        // all three are rendered bold.
        $this->assertCount(1, $this->xpath('//nav[contains(@class,"guest-nav")]//a[contains(@class,"guest-nav__link")][normalize-space(.)="HELP?"][@href="mailto:hello@dashpass.africa"]', $dom), 'Header must carry the HELP? link.');
        $this->assertCount(1, $this->xpath('//nav[contains(@class,"guest-nav")]//a[contains(normalize-space(.),"Login")][@href="/login"]', $dom), 'Header must end with a Login pill.');
        // Owner request: the right-hand top nav carries PASS and PAY (PASS replaced
        // the old "Check in" label), both entering the existing sign-in flow.
        $this->assertCount(1, $this->xpath('//nav[contains(@class,"guest-nav")]//a[normalize-space(.)="PASS"][@href="/login"]', $dom), 'Top nav must carry a PASS link.');
        $this->assertCount(1, $this->xpath('//nav[contains(@class,"guest-nav")]//a[normalize-space(.)="PAY"][@href="/login"]', $dom), 'Top nav must carry a PAY link next to PASS.');
        $this->assertCount(3, $this->xpath('//nav[contains(@class,"guest-nav")]//a[contains(@class,"guest-nav__link")]', $dom), 'The top nav must carry exactly the three text links PASS / PAY / HELP?.');
        $this->assertCount(0, $this->xpath('//nav[contains(@class,"guest-nav")]//a[contains(normalize-space(.),"Need help?")]', $dom), 'The old "Need help?" label must be replaced by HELP?.');
        $this->assertCount(0, $this->xpath('//nav[contains(@class,"guest-nav")]//a[contains(normalize-space(.),"Check in")]', $dom), 'The top-nav "Check in" label must be replaced by PASS.');
        // Bold labels: the shared guest-nav class and the landing override must both
        // declare a bold weight (>= 700).
        $guestCss = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertMatchesRegularExpression(
            '/\.guest-nav__link\s*\{[^}]*font-weight:\s*(?:[789]00|bold)/s',
            $guestCss,
            'The PASS / PAY / HELP? nav labels must be bold.'
        );
        $this->assertMatchesRegularExpression(
            '/\.guest-body--landing \.guest-nav__link\s*\{[^}]*font-weight:\s*(?:[789]00|bold)/s',
            $guestCss,
            'The landing header PASS / PAY / HELP? labels must stay bold.'
        );
        $this->assertTrue($this->xpath('//a[contains(normalize-space(.),"Check in")][@href="/login"]', $dom)->length >= 1, 'Check in must enter the existing sign-in flow.');
        $this->assertCount(1, $this->xpath('//a[contains(normalize-space(.),"Scan QR")][@href="/login?intent=scan"]', $dom), 'Scan QR must explain the sign-in-to-scan entry.');
        // Owner request (2026-11-13): the hero photo is the DashPASS "It's Super
        // Simple!" scan-the-QR ad shot (flattened WebP), with descriptive alt text.
        $heroImg = $this->xpath('//img[contains(@class,"landing-hero__image")]', $dom);
        $this->assertCount(1, $heroImg, 'Landing hero must render exactly one hero image.');
        $heroImgNode = $heroImg->item(0);
        $this->assertNotNull($heroImgNode);
        $this->assertStringContainsString('/assets/img/landing-hero-dashpass.webp', (string) $heroImgNode->getAttribute('src'), 'Hero must use the new DashPASS landing-hero ad image.');
        $this->assertStringContainsString('QR', (string) $heroImgNode->getAttribute('alt'), 'Hero image needs descriptive alt text.');
        $this->assertCount(1, $this->xpath('//*[contains(@class,"landing-hero__content")]', $dom), 'Hero must expose its content wrapper.');
        $this->assertCount(1, $this->xpath('//a[@id="check-in"][@href="/login"]', $dom), 'Hero Check in CTA must be present.');
        $this->assertCount(1, $this->xpath('//a[@id="scan-qr"][@href="/login?intent=scan"]', $dom), 'Hero Scan QR CTA must be present.');
        $heroAsset = BASE_PATH . '/public/assets/img/landing-hero-dashpass.webp';
        $this->assertTrue(is_file($heroAsset), 'Hero image asset must exist.');
        $heroInfo = getimagesize($heroAsset);
        $this->assertNotFalse($heroInfo);
        $this->assertSame(1514, $heroInfo[0], 'Hero image width must be 1514px.');
        $this->assertSame(1332, $heroInfo[1], 'Hero image height must be 1332px.');
        $this->assertSame(IMAGETYPE_WEBP, $heroInfo[2], 'Hero image must be a WebP.');
        $this->assertLessThan(200 * 1024, (int) filesize($heroAsset), 'Hero image must stay optimized under 200 KB.');
        $this->assertCount(1, $this->xpath('//*[contains(@class,"landing-accent--diamond")]', $dom), 'Hero image needs its blue diamond accent.');
        $this->assertCount(1, $this->xpath('//*[contains(@class,"landing-accent--dot")]', $dom), 'Hero image needs its blue dot accent.');
        $this->assertTrue($this->xpath('//a[@href="mailto:hello@dashpass.africa"]', $dom)->length >= 1, 'Landing footer must use the contact email.');

        $css = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1.12fr) minmax(0, 0.88fr)', $css, 'Hero must use a 56/44 two-column layout.');
        $this->assertStringContainsString('max-width: 560px', $css, 'Hero image card must be capped at ~560px.');
        $this->assertStringContainsString('border-radius: 28px', $css, 'Hero image card must use a large rounded radius.');
        $this->assertStringContainsString('aspect-ratio: 1514 / 1332', $css, 'Hero image card must use the ad image ratio.');
        $this->assertStringContainsString('.landing-headline__line', $css, 'Headline must render as two controlled lines.');
        $this->assertStringContainsString('.landing-accent--diamond', $css);
        $this->assertStringContainsString('order: 2', $css, 'Mobile hero must show text first, image after.');
        $this->assertStringContainsString('flex: 1 1 100%', $css, 'Mobile hero buttons must full-width/wrap.');
    }

    public function testAboutRouteRedirectsToLandingSection(): void
    {
        $this->resetSession();
        $response = $this->get('/about');
        $this->assertRedirect($response, '/#about');
    }

    public function testLoginPageRendersPhoneStep(): void
    {
        $this->resetSession();

        $response = $this->get('/login');

        $this->assertStatus(200, $response);
        $this->assertBodyContains('name="phone"', $response);
        $this->assertBodyContains('MtaaPass', $response);
    }

    public function testWebOtpLoginLandingPageAndLogout(): void
    {
        $this->resetSession();

        $code = $this->submitPhoneStep(self::JANE);

        $verifyPage = $this->get('/login');
        $this->assertStatus(200, $verifyPage);
        $this->assertBodyContains('name="code"', $verifyPage);
        $this->assertBodyContains('Development', $verifyPage);

        $verify = $this->post('/login/verify', ['phone' => self::JANE, 'code' => $code], true);
        $this->assertRedirect($verify, '/app');

        $app = $this->get('/app');
        $this->assertStatus(200, $app);
        $this->assertBodyContains('Greenview', $app);

        $logout = $this->post('/logout', [], true);
        $this->assertRedirect($logout, '/login');

        $this->assertRedirect($this->get('/app'), '/login');
    }

    public function testIntendedUrlIsRestoredAfterLogin(): void
    {
        $this->resetSession();

        $this->assertRedirect($this->get('/app/estates'), '/login');

        $code = $this->submitPhoneStep(self::JANE);
        $verify = $this->post('/login/verify', ['phone' => self::JANE, 'code' => $code], true);

        $this->assertRedirect($verify, '/app/estates');

        $page = $this->get('/app/estates');
        $this->assertStatus(200, $page);
    }

    public function testInvalidWebCodeRedirectsBackToLogin(): void
    {
        $this->resetSession();

        $code = $this->submitPhoneStep(self::JANE);
        $wrong = ($code[0] === '9' ? '0' : (string) (((int) $code[0]) + 1)) . substr($code, 1);

        $response = $this->post('/login/verify', ['phone' => self::JANE, 'code' => $wrong], true);

        $this->assertRedirect($response, '/login');

        $page = $this->get('/login');
        $this->assertStatus(200, $page);
    }

    public function testUnknownPhoneStillAdvancesToVerificationStep(): void
    {
        $this->resetSession();

        $response = $this->post('/login/otp', ['phone' => '+254799999999'], true);

        $this->assertRedirect($response, '/login');
        $this->assertSame('+254799999999', (string) Session::get('login_phone'));

        // The verify step is rendered for an unknown number too: no enumeration.
        $page = $this->get('/login');
        $this->assertStatus(200, $page);
        $this->assertBodyContains('name="code"', $page);
    }

    public function testApiCsrfEndpointServesAToken(): void
    {
        $this->resetSession();

        $response = $this->get('/api/v1/auth/csrf');
        $payload = $this->assertSuccess($response);

        $this->assertMatches('/^[0-9a-f]{64}$/', (string) ($payload['data']['csrf_token'] ?? ''));
    }

    public function testAuthenticatedUserIsRedirectedAwayFromLogin(): void
    {
        $this->loginAs(self::JANE);

        $this->assertRedirect($this->get('/login'), '/app');
        $this->assertRedirect($this->get('/'), '/app');
    }
}
