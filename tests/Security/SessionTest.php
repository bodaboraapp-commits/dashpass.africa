<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Clock;
use App\Core\Session;

/**
 * Session hardening (Blueprint §41, §60): fixation protection, fingerprint
 * binding, lifetime expiry, privilege revocation and clean logout.
 */
final class SessionTest extends SecurityTestCase
{
    private const JANE = '+254700000010';

    public function testSessionIsRegeneratedOnLogin(): void
    {
        $this->resetSession();

        $this->assertNull(Session::get('_session_regenerated_at'));

        $this->loginAs(self::JANE);

        $regeneratedAt = Session::get('_session_regenerated_at');

        $this->assertNotNull($regeneratedAt, 'Login must regenerate the session id (session fixation defence).');
        $this->assertTrue(is_int($regeneratedAt) || is_numeric($regeneratedAt));
    }

    public function testLoginUpdatesLastLoginTimestamp(): void
    {
        $this->loginAs(self::JANE);

        $lastLogin = $this->db->scalar(
            'SELECT last_login_at FROM users WHERE id = :id',
            ['id' => (int) $this->seededUser(self::JANE)['id']]
        );

        $this->assertNotNull($lastLogin, 'Successful authentication must record users.last_login_at.');

        $moment = Clock::fromDb((string) $lastLogin);
        $this->assertNotNull($moment);
        $this->assertTrue(abs(Clock::now()->getTimestamp() - $moment->getTimestamp()) < 120, 'last_login_at must be current.');
    }

    public function testLogoutClearsAuthenticationAndRotatesCsrf(): void
    {
        $this->loginAs(self::JANE);

        $tokenBefore = $this->csrfToken();

        $this->logout();

        $this->assertNull(Session::get('auth_user_id'), 'Logout must remove the authenticated user id from the session.');
        $this->assertNull(Session::get('_fingerprint'));

        $tokenAfter = $this->csrfToken();
        $this->assertNotSame($tokenBefore, $tokenAfter, 'Logout must rotate the CSRF token.');

        $this->assertErrorCode('UNAUTHENTICATED', $this->get('/api/v1/auth/me'), 401);
    }

    public function testSessionFingerprintMismatchInvalidatesTheSession(): void
    {
        $this->loginAs(self::JANE);

        $response = $this->request('GET', '/api/v1/auth/me', [], [], false, 'Different-Agent/2.0');

        $this->assertErrorCode('UNAUTHENTICATED', $response, 401);

        // The session was destroyed, so even the original user agent is now out.
        $this->assertErrorCode('UNAUTHENTICATED', $this->get('/api/v1/auth/me'), 401);
    }

    public function testSuspendedUserLosesAccessImmediately(): void
    {
        $this->loginAs(self::JANE);

        $this->assertSuccess($this->get('/api/v1/auth/me'));

        $this->db->execute(
            "UPDATE users SET status = 'suspended' WHERE phone = :phone",
            ['phone' => self::JANE]
        );

        $this->assertErrorCode('UNAUTHENTICATED', $this->get('/api/v1/auth/me'), 401);
        $this->assertNull(Session::get('auth_user_id'), 'A suspended user must be removed from the session.');
    }

    public function testIdleSessionExpires(): void
    {
        $this->loginAs(self::JANE);

        // Force the last activity well beyond the idle lifetime (120 minutes).
        Session::put('_last_activity', time() - 8000);

        $this->assertErrorCode('UNAUTHENTICATED', $this->get('/api/v1/auth/me'), 401);
    }

    public function testAbsoluteSessionLifetimeExpires(): void
    {
        $this->loginAs(self::JANE);

        // Idle is recent, but the session as a whole is older than 12 hours.
        Session::put('_created_at', time() - 50000);
        Session::put('_last_activity', time());

        $this->assertErrorCode('UNAUTHENTICATED', $this->get('/api/v1/auth/me'), 401);
    }

    public function testSessionCookieConfigurationIsHardened(): void
    {
        $config = (array) config('security.session');

        $this->assertTrue(is_string($config['name'] ?? null) && $config['name'] !== '', 'A named session cookie is configured.');
        $this->assertContains((string) ($config['same_site'] ?? ''), ['Lax', 'Strict', 'None']);
        $this->assertTrue(((int) ($config['idle_lifetime_minutes'] ?? 0)) > 0);
        $this->assertTrue(
            ((int) ($config['absolute_lifetime_minutes'] ?? 0)) >= ((int) ($config['idle_lifetime_minutes'] ?? 0)),
            'The absolute lifetime must be at least the idle lifetime.'
        );
    }

    public function testNewSessionHasNoAuthentication(): void
    {
        $this->resetSession();

        $this->assertNull(Session::get('auth_user_id'));
        $this->assertErrorCode('UNAUTHENTICATED', $this->get('/api/v1/auth/me'), 401);
    }
}
