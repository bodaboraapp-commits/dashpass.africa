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
        $this->assertSame('DashPASS: Visitor Pass Management System', $this->text($this->xpath('//h1', $dom)->item(0)));
        $this->assertCount(1, $this->xpath('//*[@data-theme-toggle]', $dom), 'Landing must keep exactly one theme toggle.');
        $this->assertBodyContains('ESTATE ACCESS & VISITOR MANAGEMENT', $response);
        $this->assertBodyContains('Get started', $response);
        $this->assertBodyContains('Scan QR', $response);
        $this->assertBodyContains('Sign in to scan', $response);
        $this->assertBodyContains('hello@dashpass.africa', $response);
        $this->assertStringContainsString('© 2026 DashPASS', $response->body());
        $this->assertCount(1, $this->xpath('//*[@id="about"]', $dom), 'Moved welcome content must live in the #about section.');
        $this->assertCount(0, $this->xpath('//*[@id="welcome-title"]', $dom), 'Landing must not reuse the old welcome heading.');

        $this->assertCount(1, $this->xpath('//nav[contains(@class,"guest-nav")]//a[@href="#about"]', $dom), 'Header must link to the #about section.');
        $this->assertCount(1, $this->xpath('//nav[contains(@class,"guest-nav")]//a[@href="/login"]', $dom), 'Header must link to Sign in.');
        $this->assertCount(1, $this->xpath('//a[contains(normalize-space(.),"Get started")][@href="/login"]', $dom), 'Get started must enter the existing sign-in flow.');
        $this->assertCount(1, $this->xpath('//a[contains(normalize-space(.),"Scan QR")][@href="/login?intent=scan"]', $dom), 'Scan QR must explain the sign-in-to-scan entry.');
        $this->assertCount(1, $this->xpath('//img[contains(@src,"/assets/img/estate-hero.png")]', $dom), 'Landing hero must reuse the estate hero image.');
        $this->assertBodyContains('About DashPASS', $response);
        $this->assertBodyContains('privacy notice', $response);
        $this->assertBodyContains('How sign-in works', $response, 'The moved welcome content must be on the single-scroll landing page.');
        $this->assertCount(1, $this->xpath('//a[@href="mailto:hello@dashpass.africa"]', $dom), 'Landing footer must use the contact email.');

        $css = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr)', $css, 'Hero must be a two-column layout.');
        $this->assertStringContainsString('max-width: 540px', $css, 'Hero image card must be capped at ~540px.');
        $this->assertStringContainsString('aspect-ratio: 4 / 3', $css, 'Hero image card must use a 4:3 aspect.');
        $this->assertStringContainsString('grid-template-columns: repeat(3, minmax(0, 1fr))', $css, 'Features must use a 3-column grid.');
        $this->assertStringContainsString('max-width: 65ch', $css, 'About copy must keep a readable measure.');
        $this->assertStringContainsString('order: 2', $css, 'Mobile hero must show text first, image after.');
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr)', $css, 'Mobile hero/features must collapse to one column.');
        $this->assertStringContainsString('landing-footer', $css);
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
