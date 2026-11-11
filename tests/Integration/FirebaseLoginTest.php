<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\RateLimiter;
use App\Core\Session;

/**
 * Firebase Phone Auth login screen + POST /login/verify integration
 * (Increment 12, docs/firebase-sms-otp.md §1, §3-§7; updated for T131).
 *
 * The suite pins the legacy server-OTP provider in tests/bootstrap.php; each test
 * here enables the Firebase provider with deterministic public config (including
 * the updated appId/measurementId) via Config::set(). No real SDK/network/token
 * is used: the malformed/none-alg tokens are rejected by the verifier before any
 * certificate fetch.
 */
final class FirebaseLoginTest extends VisitorModuleTestCase
{
    private const APP_ID = '1:99186909972:web:ad3900fddee22fe6b91801';
    private const MEASUREMENT_ID = 'G-D9JVCQJG9V';

    /** @var array<string, mixed> */
    private array $originalFirebase = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalFirebase = (array) Config::get('firebase', []);
        (new RateLimiter($this->db))->clearPrefix('firebase:');
    }

    public function tearDown(): void
    {
        Config::set('firebase', $this->originalFirebase);
        (new RateLimiter($this->db))->clearPrefix('firebase:');

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function enableFirebase(array $overrides = []): void
    {
        $config = array_merge($this->originalFirebase, [
            'enabled' => true,
            'provider' => 'firebase',
            'project_id' => 'komiuta',
            'leeway_seconds' => 60,
            'rate_limits' => [
                'ip' => ['max' => 20, 'window' => 900],
                'phone' => ['max' => 10, 'window' => 900],
            ],
            'web' => [
                'apiKey' => 'test-public-api-key',
                'authDomain' => 'komiuta.firebaseapp.com',
                'projectId' => 'komiuta',
                'storageBucket' => 'komiuta.firebasestorage.app',
                'messagingSenderId' => '99186909972',
                'appId' => self::APP_ID,
                'measurementId' => self::MEASUREMENT_ID,
            ],
        ], $overrides);

        Config::set('firebase', $config);
    }

    private function disableFirebase(): void
    {
        Config::set('firebase', array_merge($this->originalFirebase, [
            'enabled' => false,
            'provider' => 'server',
        ]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postLogin(array $body, bool $withToken = true): \App\Core\Response
    {
        if ($withToken) {
            $body['_token'] = $this->csrfToken();
        }

        return $this->post('/login/verify', $body, false);
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * A structurally valid JWT with alg=none: the verifier rejects it before any
     * certificate fetch (no network involved).
     */
    private function noneAlgorithmToken(): string
    {
        $now = time();

        return $this->base64Url((string) json_encode(['alg' => 'none', 'kid' => 'kid-1', 'typ' => 'JWT']))
            . '.' . $this->base64Url((string) json_encode([
                'iss' => 'https://securetoken.google.com/komiuta',
                'aud' => 'komiuta',
                'sub' => 'uid-1',
                'exp' => $now + 3600,
                'iat' => $now - 10,
                'auth_time' => $now - 10,
                'phone_number' => '+254712345678',
            ]))
            . '.';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestFirebaseAudit(): ?array
    {
        $row = $this->db->selectOne(
            "SELECT action, metadata FROM audit_logs
             WHERE action IN ('auth.login_failed', 'auth.firebase_login')
             ORDER BY id DESC LIMIT 1"
        );

        if ($row === null) {
            return null;
        }

        return array_merge(
            ['action' => (string) $row['action']],
            (array) json_decode((string) $row['metadata'], true)
        );
    }

    private function firebaseFailureCount(string $reason): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM audit_logs
             WHERE action = 'auth.login_failed' AND metadata LIKE :needle",
            ['needle' => '%' . $reason . '%']
        );
    }

    /* ------------------------------------------------------------------ */
    /* Login page render (updated modular SDK 13.2.0 + new config)        */
    /* ------------------------------------------------------------------ */

    public function testLoginPageRendersModularSdk13Config(): void
    {
        $this->enableFirebase();

        $response = $this->get('/login');
        $this->assertStatus(200, $response);
        $body = $response->body();

        // Modular SDK 13.2.0: external module script only (no compat globals).
        $this->assertStringContainsString('type="module"', $body);
        $this->assertStringContainsString('/assets/js/firebase-auth.js', $body);
        $this->assertStringContainsString('data-sdk-version="13.2.0"', $body);
        $this->assertStringNotContainsString('firebase-app-compat', $body);
        $this->assertStringNotContainsString('firebase-auth-compat', $body);

        // Updated public config values are rendered into the data-* node.
        $this->assertStringContainsString('data-provider="firebase"', $body);
        $this->assertStringContainsString('data-app-id="' . self::APP_ID . '"', $body);
        $this->assertStringContainsString('data-measurement-id="' . self::MEASUREMENT_ID . '"', $body);
        $this->assertStringContainsString('data-project-id="komiuta"', $body);
        $this->assertStringContainsString('data-auth-domain="komiuta.firebaseapp.com"', $body);
        $this->assertStringContainsString('data-messaging-sender-id="99186909972"', $body);

        // The verified ID token rides the existing form (no new route/endpoint).
        $this->assertStringContainsString('name="firebase_token"', $body);
        $this->assertStringContainsString('data-firebase-recaptcha', $body);
        $this->assertStringContainsString('data-verify-action="/login/verify"', $body);

        // No analytics module and no inline script.
        $this->assertStringNotContainsString('firebase-analytics', $body);
        $this->assertStringNotContainsString('getAnalytics', $body);
        $this->assertCleanMarkup($response, 'Firebase login page');

        $scripts = $this->xpath('//script', $this->dom($response));
        $moduleScripts = 0;

        foreach ($scripts as $script) {
            if (!$script instanceof \DOMElement) {
                continue;
            }

            $this->assertNotSame('', trim($script->getAttribute('src')), 'No inline script is allowed on the Firebase login page.');

            if ($script->getAttribute('type') === 'module') {
                $moduleScripts++;
                $this->assertStringContainsString('/assets/js/firebase-auth.js', $script->getAttribute('src'));
            }
        }

        $this->assertSame(1, $moduleScripts, 'Exactly one module script (firebase-auth.js) is expected.');
    }

    public function testLoginPageFallsBackToLegacyServerOtpWhenFirebaseDisabled(): void
    {
        $this->disableFirebase();

        $response = $this->get('/login');
        $this->assertStatus(200, $response);
        $body = $response->body();

        $this->assertStringNotContainsString('data-firebase-auth', $body);
        $this->assertStringNotContainsString('/assets/js/firebase-auth.js', $body);
        $this->assertStringContainsString('data-login-form="phone"', $body);
    }

    public function testIncompleteFirebasePublicConfigFallsBackToLegacyUi(): void
    {
        $this->enableFirebase(['web' => [
            'apiKey' => 'test-public-api-key',
            'authDomain' => 'komiuta.firebaseapp.com',
            'projectId' => 'komiuta',
            'storageBucket' => '',
            'messagingSenderId' => '',
            'appId' => '', // missing appId -> UI must not load the SDK
            'measurementId' => '',
        ]]);

        $response = $this->get('/login');
        $this->assertStringNotContainsString('data-firebase-auth', $response->body());
        $this->assertStringNotContainsString('/assets/js/firebase-auth.js', $response->body());
    }

    /* ------------------------------------------------------------------ */
    /* POST /login/verify (Firebase provider)                             */
    /* ------------------------------------------------------------------ */

    public function testMalformedFirebaseTokenIsRejectedAndAuditedWithoutLeak(): void
    {
        $this->enableFirebase();
        $token = 'not-a-firebase-token';

        $response = $this->postLogin(['firebase_token' => $token]);

        $this->assertRedirect($response, '/login', 302);
        $audit = $this->latestFirebaseAudit();
        $this->assertNotNull($audit, 'A failed Firebase verify must be audited.');
        $this->assertSame('auth.login_failed', $audit['action']);
        $this->assertSame('firebase', $audit['provider'] ?? null);
        $this->assertSame('INVALID_FIREBASE_TOKEN', $audit['reason'] ?? null);
        $this->assertStringNotContainsString($token, (string) json_encode($audit));

        // No session was created.
        $this->assertRedirect($this->get('/app'), '/login', 302);
    }

    public function testNoneAlgorithmTokenIsRejectedAndAudited(): void
    {
        $this->enableFirebase();

        $response = $this->postLogin(['firebase_token' => $this->noneAlgorithmToken()]);

        $this->assertRedirect($response, '/login', 302);
        $this->assertSame(1, $this->firebaseFailureCount('INVALID_FIREBASE_TOKEN'));
        $this->assertRedirect($this->get('/app'), '/login', 302);
    }

    public function testEmptyFirebaseTokenIsRejectedWithoutAudit(): void
    {
        $this->enableFirebase();

        $response = $this->postLogin([]);
        $this->assertRedirect($response, '/login', 302);

        $this->assertSame(0, $this->firebaseFailureCount('INVALID_FIREBASE_TOKEN'), 'A missing token is a form error, not a verifier failure.');
        $this->assertNull($this->latestFirebaseAudit());
        $this->assertRedirect($this->get('/app'), '/login', 302);
    }

    public function testIpRateLimitAuditsRateLimitedAfterLimit(): void
    {
        $this->enableFirebase(['rate_limits' => [
            'ip' => ['max' => 1, 'window' => 900],
            'phone' => ['max' => 10, 'window' => 900],
        ]]);

        $first = $this->postLogin(['firebase_token' => 'invalid-one']);
        $this->assertRedirect($first, '/login', 302);
        $this->assertSame(1, $this->firebaseFailureCount('INVALID_FIREBASE_TOKEN'));

        $second = $this->postLogin(['firebase_token' => 'invalid-two']);
        $this->assertRedirect($second, '/login', 302);
        $this->assertSame(1, $this->firebaseFailureCount('RATE_LIMITED'), 'The second attempt must be rate-limited and audited.');
    }

    public function testFirebaseSuccessIsNotReachableWithoutARealToken(): void
    {
        // No verifier seam exists in AuthController (it constructs
        // FirebaseTokenVerifier::make() directly), so a successful Firebase login
        // can only be proven with a real signed token/certs (Firebase gate). This
        // test documents the current boundary: a forged/unsigned token never
        // creates a session or an auth.firebase_login audit row.
        $this->enableFirebase();

        $this->postLogin(['firebase_token' => $this->noneAlgorithmToken()]);

        $loginAudit = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.firebase_login'"
        );
        $this->assertSame(0, $loginAudit);
        $this->assertNull(Session::get('auth_user_id'));
    }

    public function testServerProviderRemainsTheTestBaseline(): void
    {
        $this->assertSame('server', (string) Config::get('firebase.provider', ''));
        $this->assertFalse((bool) Config::get('firebase.enabled', true));

        $response = $this->get('/login');
        $this->assertStringContainsString('data-login-form="phone"', $response->body());
        $this->assertStringNotContainsString('data-firebase-auth', $response->body());
    }
}
