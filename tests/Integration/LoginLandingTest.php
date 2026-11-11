<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Platform\Uuid;
use App\Core\Session;
use App\Modules\Platform\PlatformAccess;
use App\Core\RateLimiter;

/**
 * T152/T153: post-login landing + no-estate view for platform-only operators.
 *
 * - a platform-only account (no estate membership) that signs in lands on
 *   `/platform` (both the server-OTP path and the Firebase token path), while a
 *   resident with an estate still lands on `/app`;
 * - an explicit safe intended URL wins (deep links), unsafe targets fall back;
 * - `/app/no-estate` renders the platform CTA for platform operators and the
 *   resident onboarding copy for estate-less non-platform users;
 * - no redirect loops; route count unchanged (165).
 */
final class LoginLandingTest extends VisitorModuleTestCase
{
    private const PLATFORM_ADMIN = '+254700000020';
    private const PROJECT = 'landing-test-project';
    private const KID = 'landing-test-kid';

    private string $privateKey = '';

    private string $cachePath = '';

    /** @var array<string, mixed> */
    private array $originalFirebase = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalFirebase = (array) Config::get('firebase', []);
        (new RateLimiter($this->db))->clearPrefix('firebase:');
        (new RateLimiter($this->db))->clearPrefix('otp:');
        (new RateLimiter($this->db))->clearPrefix('throttle:');
    }

    public function tearDown(): void
    {
        Config::set('firebase', $this->originalFirebase);
        (new RateLimiter($this->db))->clearPrefix('firebase:');

        if ($this->cachePath !== '') {
            @unlink($this->cachePath);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Server OTP path
    // ---------------------------------------------------------------------

    private function serverLogin(string $phone): \App\Core\Response
    {
        Config::set('firebase', array_merge($this->originalFirebase, ['enabled' => false, 'provider' => 'server']));
        $this->clearRateLimits();
        $this->resetSession();

        $request = $this->post('/api/v1/auth/request-otp', ['phone' => $phone], true);
        $this->assertStatus(200, $request);
        $code = $this->json($request)['data']['dev_otp'] ?? null;
        $this->assertTrue(is_string($code) && $code !== '', 'The dev OTP must be exposed for the landing test.');

        Session::put('login_phone', \App\Core\Phone::normalize($phone));

        return $this->post('/login/verify', ['code' => $code], true);
    }

    public function testPlatformOnlyOperatorLandsOnPlatformAfterServerOtpLogin(): void
    {
        $response = $this->serverLogin(self::PLATFORM_ADMIN);

        $this->assertStatus(302, $response);
        $this->assertSame('/platform', (string) $response->header('Location'), 'A platform-only operator must land on /platform.');

        $this->assertStatus(200, $this->get('/platform'), 'The platform dashboard must be reachable after landing.');
        $this->assertStatus(200, $this->get('/app/no-estate'), 'The no-estate page stays available as an explicit route.');
    }

    public function testPlatformOnlyOperatorDoesNotLandOnResidentNoEstate(): void
    {
        $response = $this->serverLogin(self::PLATFORM_ADMIN);

        $this->assertSame('/platform', (string) $response->header('Location'));
        $this->assertStringNotContainsString('/app/no-estate', (string) $response->header('Location'));
        $this->assertNotNull(PlatformAccess::userHasPlatformAccess($this->appContext()) ? true : null);
    }

    public function testResidentWithEstateStillLandsOnApp(): void
    {
        $response = $this->serverLogin(self::JANE);

        $this->assertStatus(302, $response);
        $this->assertSame('/app', (string) $response->header('Location'), 'Residents with an estate keep landing on /app.');
        $this->assertStatus(200, $this->get('/app'));
    }

    public function testIntendedDeepLinkIsHonouredForPlatformOperator(): void
    {
        Config::set('firebase', array_merge($this->originalFirebase, ['enabled' => false, 'provider' => 'server']));
        $this->clearRateLimits();
        $this->resetSession();

        $request = $this->post('/api/v1/auth/request-otp', ['phone' => self::PLATFORM_ADMIN], true);
        $code = $this->json($request)['data']['dev_otp'];
        Session::put('login_phone', self::PLATFORM_ADMIN);
        Session::put('intended_url', '/platform/estates');

        $response = $this->post('/login/verify', ['code' => $code], true);
        $this->assertStatus(302, $response);
        $this->assertSame('/platform/estates', (string) $response->header('Location'), 'A safe intended deep link is honoured.');
    }

    public function testUnsafeIntendedRedirectFallsBackSafely(): void
    {
        Config::set('firebase', array_merge($this->originalFirebase, ['enabled' => false, 'provider' => 'server']));
        $this->clearRateLimits();
        $this->resetSession();

        $request = $this->post('/api/v1/auth/request-otp', ['phone' => self::PLATFORM_ADMIN], true);
        $code = $this->json($request)['data']['dev_otp'];
        Session::put('login_phone', self::PLATFORM_ADMIN);
        Session::put('intended_url', 'https://evil.example/steal');

        $response = $this->post('/login/verify', ['code' => $code], true);
        $this->assertStatus(302, $response);
        $location = (string) $response->header('Location');
        $this->assertStringNotContainsString('evil.example', $location, 'External intended URLs must never be followed.');
        $this->assertStringStartsWith('/', $location);
    }

    // ---------------------------------------------------------------------
    // Firebase token path
    // ---------------------------------------------------------------------

    public function testPlatformOnlyOperatorLandsOnPlatformAfterFirebaseLogin(): void
    {
        $this->enableFirebaseForLanding();

        $response = $this->post('/login/verify', ['firebase_token' => $this->firebaseToken(self::PLATFORM_ADMIN)], true);

        $this->assertStatus(302, $response);
        $this->assertSame('/platform', (string) $response->header('Location'), 'Firebase sign-in for a platform-only operator must land on /platform.');
        $this->assertStatus(200, $this->get('/platform'));
    }

    public function testResidentWithEstateLandsOnAppAfterFirebaseLogin(): void
    {
        $this->enableFirebaseForLanding();

        $response = $this->post('/login/verify', ['firebase_token' => $this->firebaseToken(self::JANE)], true);

        $this->assertStatus(302, $response);
        $this->assertSame('/app', (string) $response->header('Location'));
    }

    // ---------------------------------------------------------------------
    // No-estate view
    // ---------------------------------------------------------------------

    public function testNoEstateViewShowsPlatformCtaForPlatformOperator(): void
    {
        $this->serverLogin(self::PLATFORM_ADMIN);

        $response = $this->get('/app/no-estate');
        $this->assertStatus(200, $response);
        $this->assertTextContains('platform', strtolower($this->pageText($response)), 'Platform operators get platform-appropriate copy.');
        $this->assertCount(1, $this->xpath('//a[@href="/platform"]', $this->dom($response)), 'The no-estate page must expose the platform CTA.');
        $this->assertTextNotContains('contact your estate administrator', $response, 'Platform operators must not get the resident onboarding wording.');
    }

    public function testNoEstateViewShowsResidentCopyForEstateLessResident(): void
    {
        $phone = '+254799900123';
        $this->db->execute(
            'INSERT INTO users (uuid, full_name, phone, email, status) VALUES (:uuid, :name, :phone, NULL, :status)',
            [
                'uuid' => Uuid::v4(),
                'name' => 'Estate-less Resident',
                'phone' => $phone,
                'status' => 'active',
            ]
        );

        $response = $this->serverLogin($phone);
        $this->assertStatus(302, $response);
        $this->assertSame('/app', (string) $response->header('Location'), 'Estate-less non-platform users keep the /app flow (then no-estate).');

        $noEstate = $this->get('/app/no-estate');
        $this->assertStatus(200, $noEstate);
        $this->assertCount(0, $this->xpath('//a[@href="/platform"]', $this->dom($noEstate)), 'Estate-less residents must not get the platform CTA.');
    }

    // ---------------------------------------------------------------------
    // No redirect loops / route count unchanged
    // ---------------------------------------------------------------------

    public function testPlatformLandingHasNoRedirectLoop(): void
    {
        $this->serverLogin(self::PLATFORM_ADMIN);

        $dashboard = $this->get('/platform');
        $this->assertStatus(200, $dashboard);

        $again = $this->get('/platform');
        $this->assertStatus(200, $again, 'Repeated platform dashboard requests must not redirect.');

        $noEstate = $this->get('/app/no-estate');
        $this->assertStatus(200, $noEstate);
    }

    public function testRouteCountIsStill165(): void
    {
        $router = $this->app->router();
        $property = new \ReflectionProperty($router, 'routes');

        $this->assertCount(165, (array) $property->getValue($router), 'T152/T153 must not add routes.');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function appContext(): \App\Core\Context
    {
        // The route-level context factory used by the kernel for the current session.
        return (new \App\Core\App())->makeContextForTest();
    }

    private function enableFirebaseForLanding(): void
    {
        $this->resetSession();

        [$privateKey, $certificatePem] = $this->generateKeyAndCertificate();
        $this->privateKey = $privateKey;

        $this->cachePath = BASE_PATH . '/storage/temporary/landing-certs-' . bin2hex(random_bytes(6)) . '.json';

        file_put_contents($this->cachePath, (string) json_encode([
            'expires_at' => time() + 3600,
            'certs' => [self::KID => $certificatePem],
        ], JSON_UNESCAPED_SLASHES));

        Config::set('firebase', array_merge($this->originalFirebase, [
            'enabled' => true,
            'provider' => 'firebase',
            'project_id' => self::PROJECT,
            'cache_path' => $this->cachePath,
            'cert_url' => 'https://certs.test.invalid/x509',
            'leeway_seconds' => 60,
            'rate_limits' => [
                'ip' => ['max' => 50, 'window' => 900],
                'phone' => ['max' => 50, 'window' => 900],
            ],
        ]));
    }

    /** @return array{0: string, 1: string} private key PEM, certificate PEM */
    private function generateKeyAndCertificate(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($resource, 'Could not generate an RSA key for the landing test.');

        $privatePem = '';
        openssl_pkey_export($resource, $privatePem);

        $csr = openssl_csr_new(['commonName' => 'firebase-landing-test'], $resource);
        $cert = openssl_csr_sign($csr, null, $resource, 1);
        $certPem = '';
        openssl_x509_export($cert, $certPem);

        return [$privatePem, $certPem];
    }

    private function firebaseToken(string $phone): string
    {
        $now = time();
        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT']));
        $claims = $this->base64Url((string) json_encode([
            'iss' => 'https://securetoken.google.com/' . self::PROJECT,
            'aud' => self::PROJECT,
            'sub' => 'landing-uid-' . substr(hash('sha256', $phone), 0, 8),
            'exp' => $now + 3600,
            'iat' => $now - 60,
            'auth_time' => $now - 60,
            'phone_number' => $phone,
        ], JSON_UNESCAPED_SLASHES));

        $signingInput = $header . '.' . $claims;
        $signature = '';
        openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . $this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
