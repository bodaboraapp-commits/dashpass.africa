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
