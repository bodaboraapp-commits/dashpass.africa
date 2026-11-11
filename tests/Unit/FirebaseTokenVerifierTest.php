<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\HttpException;
use App\Modules\Authentication\FirebaseTokenVerifier;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

/**
 * Firebase ID-token verifier (Increment 12, docs/firebase-sms-otp.md §1, §6, §7, §8).
 *
 * The verifier is fully offline-testable: its certificate source, clock and cache
 * path are injected, so these tests generate their own RSA key + self-signed x509
 * certificate (no network, no Firebase project, no real token).
 *
 * Covers: RS256 + known-kid signature checks, iss/aud/sub/exp/iat/auth_time claim
 * rules, phone normalization/422, tamper/none-alg/unknown-kid rejection,
 * fail-closed 503 when certs are unavailable, cert caching + TTL, and the
 * no-leak property of every exception message.
 */
final class FirebaseTokenVerifierTest extends TestCase
{
    private const PROJECT = 'komiuta-test';
    private const KID = 'test-kid-1';

    private DateTimeImmutable $now;

    /** @var mixed */
    private $privateKey = null;

    private string $certPem = '';

    private string $cachePath = '';

    public function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-11-11T18:00:00+00:00', new DateTimeZone('UTC'));
        $this->cachePath = 'storage/temporary/firebase-cache-' . bin2hex(random_bytes(6)) . '.json';

        // The openssl extension reads OPENSSL_CONF at process start, so a
        // putenv() in bootstrap is too late for in-process key generation. Pass
        // the bundled XAMPP config explicitly (no-op where a platform default
        // exists).
        $opensslConf = null;

        foreach ([
            'C:/xampp/php/extras/openssl/openssl.cnf',
            'C:/xampp/php/extras/ssl/openssl.cnf',
        ] as $candidate) {
            if (is_file($candidate)) {
                $opensslConf = $candidate;

                break;
            }
        }

        $keyOptions = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        if ($opensslConf !== null) {
            $keyOptions['config'] = $opensslConf;
        }

        $key = openssl_pkey_new($keyOptions);

        if ($key === false) {
            $this->fail('Could not generate an RSA key for the Firebase verifier test.');
        }

        $csrOptions = ['digest_alg' => 'sha256'];

        if ($opensslConf !== null) {
            $csrOptions['config'] = $opensslConf;
        }

        $csr = openssl_csr_new(['commonName' => 'firebase-verifier-test'], $key, $csrOptions);

        if ($csr === false) {
            $this->fail('Could not create a CSR for the Firebase verifier test.');
        }

        $certificate = openssl_csr_sign($csr, null, $key, 1, $csrOptions);

        if ($certificate === false || openssl_x509_export($certificate, $pem) === false) {
            $this->fail('Could not self-sign the test certificate for the Firebase verifier test.');
        }

        $this->privateKey = $key;
        $this->certPem = (string) $pem;
    }

    public function tearDown(): void
    {
        $absolute = BASE_PATH . '/' . $this->cachePath;

        if (is_file($absolute)) {
            @unlink($absolute);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header
     */
    private function token(array $claims = [], array $header = [], bool $validSignature = true): string
    {
        $payload = array_merge([
            'iss' => 'https://securetoken.google.com/' . self::PROJECT,
            'aud' => self::PROJECT,
            'sub' => 'firebase-uid-123',
            'exp' => $this->now->getTimestamp() + 3600,
            'iat' => $this->now->getTimestamp() - 30,
            'auth_time' => $this->now->getTimestamp() - 120,
            'phone_number' => '+254712345678',
            'firebase' => ['sign_in_provider' => 'phone'],
        ], $claims);

        $head = array_merge(['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT'], $header);
        $encodedHeader = $this->base64Url(json_encode($head, JSON_UNESCAPED_SLASHES));
        $encodedClaims = $this->base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $signingInput = $encodedHeader . '.' . $encodedClaims;
        $signature = '';

        if ($validSignature && $this->privateKey !== null && $this->privateKey !== false) {
            openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        } else {
            $signature = str_repeat("\x01", 256);
        }

        return $signingInput . '.' . $this->base64Url($signature);
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param array<string, string> $certs
     * @return callable(string): array{body: string, max_age: int}
     */
    private function certFetcher(array $certs, int $maxAge = 3600): callable
    {
        return static fn (string $url): array => [
            'body' => (string) json_encode($certs, JSON_UNESCAPED_SLASHES),
            'max_age' => $maxAge,
        ];
    }

    /**
     * @return array{0: FirebaseTokenVerifier, 1: array<int, int>}
     */
    private function verifier(?callable $fetcher = null, ?DateTimeImmutable $now = null): array
    {
        $calls = [];

        $wrapped = function (string $url) use (&$calls, $fetcher): ?array {
            $calls[] = 1;

            if ($fetcher === null) {
                return ['body' => (string) json_encode([self::KID => $this->certPem]), 'max_age' => 3600];
            }

            return $fetcher($url);
        };

        $verifier = new FirebaseTokenVerifier(
            self::PROJECT,
            'https://certs.test/firebase.json',
            $this->cachePath,
            60,
            $now ?? $this->now,
            $wrapped
        );

        return [$verifier, &$calls];
    }

    /**
     * @return array<string, mixed>
     */
    private function expectHttpException(FirebaseTokenVerifier $verifier, string $token): array
    {
        try {
            $verifier->verify($token);
        } catch (HttpException $exception) {
            return [
                'status' => $exception->status(),
                'error' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ];
        }

        $this->fail('Expected the Firebase verifier to reject the token.');
    }

    /* ------------------------------------------------------------------ */
    /* Valid tokens                                                       */
    /* ------------------------------------------------------------------ */

    public function testValidTokenReturnsUidAndNormalizedPhone(): void
    {
        [$verifier] = $this->verifier();

        $verified = $verifier->verify($this->token());

        $this->assertSame('firebase-uid-123', $verified['uid']);
        $this->assertSame('+254712345678', $verified['phone']);
        $this->assertSame('phone', $verified['claims']['firebase']['sign_in_provider']);
    }

    public function testLocalPhoneFormatIsNormalized(): void
    {
        [$verifier] = $this->verifier();

        $verified = $verifier->verify($this->token(['phone_number' => '0712345678']));

        $this->assertSame('+254712345678', $verified['phone']);
    }

    public function testClockLeewayAcceptsSlightFutureClockSkew(): void
    {
        [$verifier] = $this->verifier();

        $verified = $verifier->verify($this->token([
            'iat' => $this->now->getTimestamp() + 30, // within the 60s leeway
            'auth_time' => $this->now->getTimestamp() + 30,
        ]));

        $this->assertSame('firebase-uid-123', $verified['uid']);
    }

    /* ------------------------------------------------------------------ */
    /* Claim failures -> 401 INVALID_FIREBASE_TOKEN                       */
    /* ------------------------------------------------------------------ */

    public function testExpiredTokenIsRejected(): void
    {
        [$verifier] = $this->verifier();

        $failure = $this->expectHttpException($verifier, $this->token([
            'exp' => $this->now->getTimestamp() - 10,
        ]));

        $this->assertSame(401, $failure['status']);
        $this->assertSame('INVALID_FIREBASE_TOKEN', $failure['error']);
    }

    public function testFutureIssuedAtBeyondLeewayIsRejected(): void
    {
        [$verifier] = $this->verifier();

        $failure = $this->expectHttpException($verifier, $this->token([
            'iat' => $this->now->getTimestamp() + 300,
        ]));

        $this->assertSame(401, $failure['status']);
    }

    public function testFutureAuthTimeIsRejected(): void
    {
        [$verifier] = $this->verifier();

        $failure = $this->expectHttpException($verifier, $this->token([
            'auth_time' => $this->now->getTimestamp() + 300,
        ]));

        $this->assertSame(401, $failure['status']);
    }

    public function testWrongIssuerOrAudienceIsRejected(): void
    {
        [$verifier] = $this->verifier();

        foreach ([
            ['iss' => 'https://securetoken.google.com/other-project'],
            ['aud' => 'other-project'],
        ] as $claims) {
            $failure = $this->expectHttpException($verifier, $this->token($claims));
            $this->assertSame(401, $failure['status']);
            $this->assertSame('INVALID_FIREBASE_TOKEN', $failure['error']);
        }
    }

    public function testEmptySubjectIsRejected(): void
    {
        [$verifier] = $this->verifier();

        foreach (['', '   '] as $sub) {
            $failure = $this->expectHttpException($verifier, $this->token(['sub' => $sub]));
            $this->assertSame(401, $failure['status']);
        }
    }

    public function testNoneAlgorithmIsRejectedBeforeAnyCertificateFetch(): void
    {
        $fetched = 0;
        $fetcher = static function (string $url) use (&$fetched): ?array {
            $fetched++;

            return null;
        };

        [$verifier] = $this->verifier($fetcher);
        $failure = $this->expectHttpException($verifier, $this->token(['alg' => 'none'], ['alg' => 'none']));

        $this->assertSame(401, $failure['status']);
        $this->assertSame(0, $fetched, 'A non-RS256 token must be rejected before fetching certificates.');
    }

    public function testUnknownKidIsRejected(): void
    {
        [$verifier] = $this->verifier();

        $failure = $this->expectHttpException($verifier, $this->token([], ['kid' => 'unknown-kid']));

        $this->assertSame(401, $failure['status']);
    }

    public function testTamperedSignatureOrPayloadIsRejected(): void
    {
        [$verifier] = $this->verifier();

        $token = $this->token();
        $parts = explode('.', $token);

        $tamperedPayload = $parts[0] . '.' . $this->base64Url((string) json_encode([
            'iss' => 'https://securetoken.google.com/' . self::PROJECT,
            'aud' => self::PROJECT,
            'sub' => 'attacker',
            'exp' => $this->now->getTimestamp() + 3600,
            'iat' => $this->now->getTimestamp() - 10,
            'auth_time' => $this->now->getTimestamp() - 10,
            'phone_number' => '+254712345678',
        ])) . '.' . $parts[2];

        $this->assertSame(401, $this->expectHttpException($verifier, $tamperedPayload)['status']);
        $this->assertSame(401, $this->expectHttpException($verifier, $this->token([], [], false))['status']);
    }

    public function testMalformedTokensAreRejectedWithoutWarnings(): void
    {
        [$verifier] = $this->verifier();

        foreach ([
            '',
            'not-a-token',
            'a.b',
            'a.b.c.d',
            '!!!.???.###',
            $this->base64Url('not json') . '.' . $this->base64Url('{}') . '.sig',
        ] as $token) {
            $failure = $this->expectHttpException($verifier, $token);
            $this->assertSame(401, $failure['status']);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Phone failures -> 422 FIREBASE_PHONE_INVALID                       */
    /* ------------------------------------------------------------------ */

    public function testMissingOrInvalidPhoneNumberIsRejected(): void
    {
        [$verifier] = $this->verifier();

        foreach (['', '   ', 'not-a-phone', '12'] as $phone) {
            $claims = $phone === '' ? ['phone_number' => ''] : ['phone_number' => $phone];
            $failure = $this->expectHttpException($verifier, $this->token($claims));

            $this->assertSame(422, $failure['status'], 'Phone "' . $phone . '" must yield 422.');
            $this->assertSame('FIREBASE_PHONE_INVALID', $failure['error']);
        }

        $withoutClaim = $this->token();
        $parts = explode('.', $withoutClaim);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        unset($claims['phone_number']);
        $encoded = $this->base64Url((string) json_encode($claims));
        $signature = '';
        openssl_sign($parts[0] . '.' . $encoded, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        $tokenWithoutPhone = $parts[0] . '.' . $encoded . '.' . $this->base64Url($signature);

        $failure = $this->expectHttpException($verifier, $tokenWithoutPhone);
        $this->assertSame(422, $failure['status']);
    }

    /* ------------------------------------------------------------------ */
    /* Fail-closed certificate handling                                   */
    /* ------------------------------------------------------------------ */

    public function testUnavailableCertificatesFailClosedWith503(): void
    {
        foreach ([
            static fn (string $url): ?array => null,
            static fn (string $url): ?array => ['body' => ''],
            static fn (string $url): ?array => ['body' => 'not-json'],
            static fn (string $url): ?array => ['body' => '{}'],
            static fn (string $url): ?array => ['body' => '{"kid":""}'],
            static function (string $url): ?array {
                throw new \RuntimeException('network down');
            },
        ] as $fetcher) {
            $this->tearDownCache();

            [$verifier] = $this->verifier($fetcher);
            $failure = $this->expectHttpException($verifier, $this->token());

            $this->assertSame(503, $failure['status']);
            $this->assertSame('SERVICE_UNAVAILABLE', $failure['error']);
        }
    }

    public function testCertificatesAreCachedAndRespectTheirTtl(): void
    {
        $fetched = 0;
        $fetcher = function (string $url) use (&$fetched): array {
            $fetched++;

            return ['body' => (string) json_encode([self::KID => $this->certPem]), 'max_age' => 120];
        };

        [$verifier] = $this->verifier($fetcher);
        $this->assertSame('firebase-uid-123', $verifier->verify($this->token())['uid']);
        $this->assertSame(1, $fetched);
        $this->assertTrue(is_file(BASE_PATH . '/' . $this->cachePath), 'The certificates must be cached.');

        // A second verifier with a failing fetcher still verifies from the cache.
        [$cachedVerifier] = $this->verifier(static fn (string $url): ?array => null);
        $this->assertSame('firebase-uid-123', $cachedVerifier->verify($this->token())['uid']);

        // Past the max-age TTL the cache is ignored and the failing fetch fails closed.
        $expiredNow = $this->now->modify('+121 seconds');
        [$expiredVerifier] = $this->verifier(static fn (string $url): ?array => null, $expiredNow);
        $failure = $this->expectHttpException($expiredVerifier, $this->token([
            'exp' => $expiredNow->getTimestamp() + 3600,
            'iat' => $expiredNow->getTimestamp() - 10,
            'auth_time' => $expiredNow->getTimestamp() - 10,
        ]));
        $this->assertSame(503, $failure['status']);
    }

    /* ------------------------------------------------------------------ */
    /* make() configuration + no-leak                                     */
    /* ------------------------------------------------------------------ */

    public function testMakeRequiresAConfiguredProjectId(): void
    {
        $original = Config::get('firebase');
        Config::set('firebase.project_id', '');

        try {
            $failure = null;

            try {
                FirebaseTokenVerifier::make();
            } catch (HttpException $exception) {
                $failure = $exception;
            }

            $this->assertNotNull($failure);
            $this->assertSame(503, $failure->status());
            $this->assertSame('SERVICE_UNAVAILABLE', $failure->errorCode());
        } finally {
            Config::set('firebase', $original);
        }
    }

    public function testMakeUsesConfiguredProjectAndInjectedFetcher(): void
    {
        $original = Config::get('firebase');

        Config::set('firebase', array_merge($original === null ? [] : (array) $original, [
            'project_id' => self::PROJECT,
            'cert_url' => 'https://certs.test/firebase.json',
            'cache_path' => BASE_PATH . '/' . $this->cachePath,
            'leeway_seconds' => 60,
        ]));

        try {
            $verifier = FirebaseTokenVerifier::make($this->now, $this->certFetcher([self::KID => $this->certPem]));
            $this->assertSame('firebase-uid-123', $verifier->verify($this->token())['uid']);
        } finally {
            Config::set('firebase', $original);
        }
    }

    public function testExceptionMessagesNeverContainTheTokenOrThePhone(): void
    {
        [$verifier] = $this->verifier();

        $token = $this->token(['exp' => $this->now->getTimestamp() - 10]);
        $failure = $this->expectHttpException($verifier, $token);

        $this->assertStringNotContainsString('+254712345678', $failure['message']);
        $this->assertStringNotContainsString(substr($token, 0, 20), $failure['message']);
        $this->assertStringNotContainsString('eyJ', $failure['message']);
    }

    private function tearDownCache(): void
    {
        $absolute = BASE_PATH . '/' . $this->cachePath;

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}
