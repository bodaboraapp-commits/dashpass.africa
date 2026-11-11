<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Config;
use App\Core\Session;

/**
 * T138 (Increment 12 hardening): the Firebase provider must never allow the
 * legacy server-generated OTP path to be used as a bypass.
 *
 * When `firebase.provider=firebase`:
 *   - API `POST /api/v1/auth/request-otp` / `POST /api/v1/auth/verify-otp`
 *     return 403 AUTH_PROVIDER_MISMATCH before validation/rate-limit work,
 *     create no challenge and no session, and audit a generic PII-free
 *     `auth.login_failed` (provider=firebase, reason=PROVIDER_MISMATCH);
 *   - web `POST /login/otp` and a code-only `POST /login/verify` are refused
 *     with a 302 back to /login and no session.
 *
 * The legacy flow stays available under `provider=server` (the suite baseline),
 * and a non-production tooling fallback exists only behind the explicit
 * `FIREBASE_ALLOW_SERVER_OTP_FALLBACK=true` flag â€” production can never use it.
 */
final class AuthProviderEnforcementTest extends SecurityTestCase
{
    /** @var array<string, mixed> */
    private array $originalFirebase = [];

    private string|false $originalFallback = false;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalFirebase = (array) Config::get('firebase', []);
        $this->originalFallback = getenv('FIREBASE_ALLOW_SERVER_OTP_FALLBACK');
    }

    public function tearDown(): void
    {
        Config::set('firebase', $this->originalFirebase);

        if ($this->originalFallback === false) {
            putenv('FIREBASE_ALLOW_SERVER_OTP_FALLBACK');
            unset($_ENV['FIREBASE_ALLOW_SERVER_OTP_FALLBACK'], $_SERVER['FIREBASE_ALLOW_SERVER_OTP_FALLBACK']);
        } else {
            putenv('FIREBASE_ALLOW_SERVER_OTP_FALLBACK=' . $this->originalFallback);
        }

        parent::tearDown();
    }

    private function enableFirebaseProvider(): void
    {
        Config::set('firebase', array_merge($this->originalFirebase, [
            'enabled' => true,
            'provider' => 'firebase',
            'project_id' => 'komiuta',
        ]));
    }

    private function useServerProvider(): void
    {
        Config::set('firebase', array_merge($this->originalFirebase, [
            'enabled' => false,
            'provider' => 'server',
        ]));
    }

    private function enableFallbackFlag(): void
    {
        putenv('FIREBASE_ALLOW_SERVER_OTP_FALLBACK=true');
        $_ENV['FIREBASE_ALLOW_SERVER_OTP_FALLBACK'] = 'true';
        $_SERVER['FIREBASE_ALLOW_SERVER_OTP_FALLBACK'] = 'true';
    }

    /** @return array<string, mixed>|null */
    private function latestAuthFailure(): ?array
    {
        $row = $this->db->selectOne(
            "SELECT action, metadata FROM audit_logs
             WHERE action = 'auth.login_failed'
             ORDER BY id DESC LIMIT 1"
        );

        if ($row === null) {
            return null;
        }

        $metadata = json_decode((string) $row['metadata'], true);

        return array_merge(
            ['action' => (string) $row['action']],
            is_array($metadata) ? $metadata : []
        );
    }

    private function assertNoSession(): void
    {
        $me = $this->get('/api/v1/auth/me');
        $this->assertStatus(401, $me, 'A blocked provider-mismatch attempt must not establish a session.');
    }

    // ---------------------------------------------------------------------
    // API bypass (the original T138 finding)
    // ---------------------------------------------------------------------

    public function testApiVerifyOtpIsRejectedWithProviderMismatchAndNoSession(): void
    {
        $this->enableFirebaseProvider();
        $this->clearRateLimits();

        $response = $this->post('/api/v1/auth/verify-otp', [
            'phone' => '+254700000010',
            'code' => '123456',
        ], true);

        $this->assertErrorCode('AUTH_PROVIDER_MISMATCH', $response, 403);
        $this->assertNoSession();

        $failure = $this->latestAuthFailure();
        $this->assertNotNull($failure, 'A provider-mismatch attempt must be audited.');
        $this->assertSame('auth.login_failed', (string) $failure['action']);
        $this->assertSame('firebase', (string) ($failure['provider'] ?? ''));
        $this->assertSame('PROVIDER_MISMATCH', (string) ($failure['reason'] ?? ''));
        $this->assertTrue(isset($failure['endpoint']) && (string) $failure['endpoint'] !== '', 'The audit records which endpoint was attempted.');
        $this->assertStringNotContainsString('254700000010', (string) json_encode($failure), 'The failure audit must not contain the raw phone.');
        $this->assertStringNotContainsString('123456', (string) json_encode($failure), 'The failure audit must not contain the submitted code.');
    }

    public function testApiRequestOtpIsRejectedWithoutCreatingAChallenge(): void
    {
        $this->enableFirebaseProvider();
        $this->clearRateLimits();

        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM otp_challenges');

        $response = $this->post('/api/v1/auth/request-otp', ['phone' => '+254700000010'], true);

        $this->assertErrorCode('AUTH_PROVIDER_MISMATCH', $response, 403);
        $this->assertSame(
            $before,
            (int) $this->db->scalar('SELECT COUNT(*) FROM otp_challenges'),
            'A blocked provider-mismatch request must not create an OTP challenge row.'
        );

        $failure = $this->latestAuthFailure();
        $this->assertNotNull($failure);
        $this->assertSame('PROVIDER_MISMATCH', (string) ($failure['reason'] ?? ''));
        $this->assertStringNotContainsString('254700000010', (string) json_encode($failure));
    }

    public function testApiProviderMismatchNeedsNoChallengeOrRateLimitBudget(): void
    {
        $this->enableFirebaseProvider();
        $this->clearRateLimits();

        // Guards run before validation: repeated malformed bodies still get the
        // single, generic provider-mismatch answer (never a validation leak).
        for ($i = 0; $i < 3; $i++) {
            $response = $this->post('/api/v1/auth/verify-otp', ['phone' => 'nonsense', 'code' => 'x'], true);
            $this->assertErrorCode('AUTH_PROVIDER_MISMATCH', $response, 403);
        }

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM otp_challenges'));
        $this->assertNoSession();
    }

    // ---------------------------------------------------------------------
    // Web bypass
    // ---------------------------------------------------------------------

    public function testWebRequestOtpIsRejectedAndDoesNotStoreTheLoginPhone(): void
    {
        $this->enableFirebaseProvider();
        $this->resetSession();

        $response = $this->post('/login/otp', ['phone' => '+254700000010'], true);

        $this->assertStatus(302, $response);
        $this->assertSame('/login', (string) $response->header('Location'));
        $this->assertNull(Session::get('login_phone'), 'The blocked web OTP request must not store login_phone.');

        $failure = $this->latestAuthFailure();
        $this->assertNotNull($failure);
        $this->assertSame('web_request_otp', (string) ($failure['endpoint'] ?? ''));
        $this->assertSame('PROVIDER_MISMATCH', (string) ($failure['reason'] ?? ''));
    }

    public function testWebVerifyWithOnlyACodeIsRejectedAndEstablishesNoSession(): void
    {
        $this->enableFirebaseProvider();
        $this->resetSession();

        $response = $this->post('/login/verify', ['code' => '123456'], true);

        $this->assertStatus(302, $response);
        $this->assertSame('/login', (string) $response->header('Location'));
        $this->assertNull(Session::get('auth_user_id'), 'A code-only verify must not authenticate under the Firebase provider.');

        $app = $this->get('/app');
        $this->assertStatus(302, $app, 'The blocked verify must leave the visitor unauthenticated.');
        $this->assertStringContainsString('/login', (string) $app->header('Location'));
    }

    public function testWebProviderMismatchAuditContainsNoPii(): void
    {
        $this->enableFirebaseProvider();
        $this->resetSession();

        $this->post('/login/otp', ['phone' => '+254700000010'], true);
        $this->post('/login/verify', ['code' => '654321'], true);

        $rows = $this->db->select(
            "SELECT metadata FROM audit_logs WHERE action = 'auth.login_failed' ORDER BY id ASC"
        );

        $this->assertTrue(count($rows) >= 1, 'Blocked web attempts must be audited.');

        foreach ($rows as $row) {
            $metadata = (string) $row['metadata'];
            $this->assertStringContainsString('PROVIDER_MISMATCH', $metadata);
            $this->assertStringNotContainsString('254700000010', $metadata, 'No raw phone in the audit log.');
            $this->assertStringNotContainsString('654321', $metadata, 'No code in the audit log.');
        }
    }

    // ---------------------------------------------------------------------
    // Baseline + explicit non-production fallback
    // ---------------------------------------------------------------------

    public function testLegacyServerOtpFlowStaysGreenUnderTheServerProvider(): void
    {
        $this->useServerProvider();
        $this->clearRateLimits();
        $this->resetSession();

        $request = $this->post('/api/v1/auth/request-otp', ['phone' => '+254700000010'], true);
        $this->assertStatus(200, $request);

        $code = $this->json($request)['data']['dev_otp'] ?? null;
        $this->assertTrue(is_string($code) && $code !== '', 'The dev OTP must be exposed in tests.');

        $verify = $this->post('/api/v1/auth/verify-otp', ['phone' => '+254700000010', 'code' => $code], true);
        $this->assertStatus(200, $verify);

        $me = $this->get('/api/v1/auth/me');
        $this->assertStatus(200, $me, 'The server-provider legacy login must still establish a session.');
    }

    public function testNonProductionFallbackFlagReenablesTheServerOtpPath(): void
    {
        $this->enableFirebaseProvider();
        $this->enableFallbackFlag();
        $this->clearRateLimits();
        $this->resetSession();

        $request = $this->post('/api/v1/auth/request-otp', ['phone' => '+254700000010'], true);
        $this->assertStatus(200, $request, 'The explicit non-production tooling flag allows the server OTP path.');
        $this->assertNotEmpty($this->json($request)['data']['dev_otp'] ?? null);

        $code = $this->json($request)['data']['dev_otp'];
        $verify = $this->post('/api/v1/auth/verify-otp', ['phone' => '+254700000010', 'code' => $code], true);
        $this->assertStatus(200, $verify);

        $this->assertStatus(200, $this->get('/api/v1/auth/me'));
    }

    public function testFallbackFlagNeverWorksInProduction(): void
    {
        $this->enableFirebaseProvider();
        $this->enableFallbackFlag();
        $this->clearRateLimits();

        $originalEnv = (string) Config::get('app.env', 'production');
        Config::set('app.env', 'production');

        try {
            $response = $this->post('/api/v1/auth/request-otp', ['phone' => '+254700000010'], true);
            $this->assertErrorCode('AUTH_PROVIDER_MISMATCH', $response, 403);
        } finally {
            Config::set('app.env', $originalEnv);
        }
    }

    public function testFallbackFlagIsNotEnabledByDefault(): void
    {
        // The suite-wide baseline is the server provider; with Firebase enabled the
        // default has NO fallback (the flag is absent), even outside production.
        $this->enableFirebaseProvider();
        $this->clearRateLimits();

        putenv('FIREBASE_ALLOW_SERVER_OTP_FALLBACK');
        unset($_ENV['FIREBASE_ALLOW_SERVER_OTP_FALLBACK'], $_SERVER['FIREBASE_ALLOW_SERVER_OTP_FALLBACK']);

        $response = $this->post('/api/v1/auth/request-otp', ['phone' => '+254700000010'], true);
        $this->assertErrorCode('AUTH_PROVIDER_MISMATCH', $response, 403);
    }
}
