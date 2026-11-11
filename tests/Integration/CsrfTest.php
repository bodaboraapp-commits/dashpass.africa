<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;

/**
 * CSRF protection (Blueprint §40/§60): every state-changing request needs the
 * session token via header or form field; safe methods and health are exempt;
 * tokens rotate on privilege change.
 */
final class CsrfTest extends IntegrationTestCase
{
    private const JANE = '+254700000010';

    public function testSafeMethodsDoNotRequireAToken(): void
    {
        $this->assertStatus(200, $this->get('/'));
        $this->assertStatus(200, $this->get('/login'));
        $this->assertStatus(200, $this->get('/health'));
        $this->assertStatus(200, $this->get('/api/v1/health'));
        $this->assertStatus(200, $this->get('/api/v1/auth/csrf'));
    }

    public function testApiRequestWithoutTokenIsRejected(): void
    {
        $this->loginAs(self::JANE);
        $greenview = $this->seededEstate('greenview-residences');

        $response = $this->post('/api/v1/estates/' . $greenview['uuid'] . '/switch', [], false);

        $this->assertErrorCode('CSRF_TOKEN_INVALID', $response, 403);
    }

    public function testApiRequestWithInvalidTokenIsRejected(): void
    {
        $this->loginAs(self::JANE);
        $greenview = $this->seededEstate('greenview-residences');

        $response = $this->post(
            '/api/v1/estates/' . $greenview['uuid'] . '/switch',
            [],
            false,
            ['X-CSRF-Token' => 'deadbeefdeadbeef']
        );

        $this->assertErrorCode('CSRF_TOKEN_INVALID', $response, 403);
    }

    public function testWebFormWithoutTokenIsRejected(): void
    {
        $this->loginAs(self::JANE);

        $response = $this->post('/logout', [], false);

        $this->assertStatus(403, $response);
        $this->assertBodyContains('CSRF_TOKEN_INVALID', $response);
    }

    public function testLoginPostRequiresToken(): void
    {
        $response = $this->post('/login/otp', ['phone' => self::JANE], false);

        $this->assertStatus(403, $response);
        $this->assertBodyContains('CSRF_TOKEN_INVALID', $response);
    }

    public function testWebFormAcceptsTokenField(): void
    {
        $this->loginAs(self::JANE);

        $response = $this->post('/logout', ['_token' => $this->csrfToken()], false);

        $this->assertRedirect($response, '/login');
    }

    public function testHealthEndpointsBypassCsrf(): void
    {
        $this->assertStatus(200, $this->get('/health', false));
        $this->assertStatus(200, $this->get('/api/v1/health', false));

        // No CSRF header is sent: the request must reach the router (405) rather
        // than being rejected by CSRF (403).
        $this->assertStatus(405, $this->post('/health', [], false));
        $this->assertStatus(405, $this->post('/api/v1/health', [], false));
    }

    public function testTokenRotatesOnLogin(): void
    {
        $this->resetSession();
        $before = $this->csrfToken();

        $request = $this->post('/api/v1/auth/request-otp', ['phone' => self::JANE], true);
        $payload = $this->assertSuccess($request);
        $code = (string) $payload['data']['dev_otp'];

        $this->assertSuccess(
            $this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => $code], true)
        );

        $after = $this->csrfToken();

        $this->assertNotSame($before, $after, 'Login must rotate the CSRF token (session fixation defence).');

        // The pre-login token must no longer authenticate a state-changing call.
        $stale = $this->post('/api/v1/auth/logout', [], false, ['X-CSRF-Token' => $before]);
        $this->assertErrorCode('CSRF_TOKEN_INVALID', $stale, 403);

        $fresh = $this->post('/api/v1/auth/logout', [], false, ['X-CSRF-Token' => $after]);
        $this->assertStatus(204, $fresh);
    }

    public function testTokenFromAnotherSessionIsRejected(): void
    {
        $this->loginAs(self::JANE);
        $token = $this->csrfToken();

        // A brand new session (e.g. attacker guessing/replaying a token).
        $this->resetSession();

        $response = $this->post('/api/v1/auth/logout', [], false, ['X-CSRF-Token' => $token]);

        $this->assertErrorCode('CSRF_TOKEN_INVALID', $response, 403);
    }

    public function testApiCsrfEndpointMatchesSessionToken(): void
    {
        $this->resetSession();

        $payload = $this->assertSuccess($this->get('/api/v1/auth/csrf'));
        $this->assertMatches('/^[0-9a-f]{64}$/', (string) ($payload['data']['csrf_token'] ?? ''));
        $this->assertSame($this->csrfToken(), (string) $payload['data']['csrf_token']);

        $flash = Session::get('_flash');
        $this->assertTrue($flash === null || is_array($flash));
    }
}
