<?php

declare(strict_types=1);

namespace App\Modules\Authentication;

use App\Core\Clock;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Phone;
use DateTimeImmutable;
use Throwable;

/**
 * Firebase ID-token verification (Increment 12).
 *
 * Verifies a Firebase Phone-Auth ID token **without a service account**: the
 * token's RS256 signature is checked against Google's public x509 certificate
 * endpoint (`securetoken@system.gserviceaccount.com`), cached under storage with
 * the endpoint's Cache-Control max-age. Claims enforced:
 *   - alg = RS256 + a known `kid`;
 *   - iss = https://securetoken.google.com/<projectId>;
 *   - aud = <projectId>;
 *   - non-empty `sub` (the Firebase uid);
 *   - exp / iat / auth_time sane relative to the injected clock (small leeway);
 *   - non-empty `phone_number`, normalized through Phone::normalize.
 *
 * Fail-closed: if certificates cannot be fetched/parsed the verifier throws 503
 * SERVICE_UNAVAILABLE. Invalid tokens throw 401 INVALID_FIREBASE_TOKEN; unusable
 * phone claims throw 422 FIREBASE_PHONE_INVALID. Tokens/phone numbers are never
 * logged or included in exception messages.
 */
final class FirebaseTokenVerifier
{
    private const CERT_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    /**
     * @param (callable(string): (?array{body: string, max_age?: int}))|null $certFetcher
     */
    public function __construct(
        private readonly string $projectId,
        private readonly string $certUrl,
        private readonly string $cachePath,
        private readonly int $leewaySeconds = 60,
        private readonly ?DateTimeImmutable $now = null,
        private readonly mixed $certFetcher = null
    ) {
    }

    /**
     * @param (callable(string): (?array{body: string, max_age?: int}))|null $certFetcher
     */
    public static function make(
        ?DateTimeImmutable $now = null,
        ?callable $certFetcher = null,
        ?string $cachePath = null,
        ?string $projectId = null
    ): self {
        $configuredProject = $projectId ?? (string) Config::get('firebase.project_id', '');
        $configuredProject = trim($configuredProject);

        if ($configuredProject === '') {
            throw self::unavailable();
        }

        return new self(
            $configuredProject,
            (string) Config::get('firebase.cert_url', self::CERT_URL),
            $cachePath ?? (string) Config::get('firebase.cache_path', dirname(__DIR__, 3) . '/storage/temporary/firebase-certs.json'),
            max(0, (int) Config::get('firebase.leeway_seconds', 60)),
            $now,
            $certFetcher
        );
    }

    /**
     * @return array{uid: string, phone: string, claims: array<string, mixed>}
     *
     * @throws HttpException 401 invalid token, 422 unusable phone, 503 certs unavailable
     */
    public function verify(string $idToken): array
    {
        $token = trim($idToken);

        if ($token === '' || substr_count($token, '.') !== 2) {
            throw self::invalidToken();
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = explode('.', $token);
        $header = $this->decodeJson($encodedHeader);
        $claims = $this->decodeJson($encodedClaims);
        $signature = $this->base64UrlDecode($encodedSignature);

        $algorithm = (string) ($header['alg'] ?? '');
        $keyId = (string) ($header['kid'] ?? '');

        if ($algorithm !== 'RS256' || $keyId === '') {
            throw self::invalidToken();
        }

        $certificates = $this->certificates();
        $publicKey = $certificates[$keyId] ?? null;

        if (!is_string($publicKey) || $publicKey === '') {
            throw self::invalidToken();
        }

        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedClaims,
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256
        );

        if ($verified !== 1) {
            throw self::invalidToken();
        }

        $now = $this->now ?? Clock::now();
        $nowTs = $now->getTimestamp();
        $leeway = $this->leewaySeconds;

        $issuer = 'https://securetoken.google.com/' . $this->projectId;

        if ((string) ($claims['iss'] ?? '') !== $issuer
            || (string) ($claims['aud'] ?? '') !== $this->projectId) {
            throw self::invalidToken();
        }

        $subject = $claims['sub'] ?? null;

        if (!is_string($subject) || trim($subject) === '' || mb_strlen(trim($subject)) > 128) {
            throw self::invalidToken();
        }

        $exp = $claims['exp'] ?? null;
        $iat = $claims['iat'] ?? null;
        $authTime = $claims['auth_time'] ?? null;

        if (!is_numeric($exp) || (int) $exp < $nowTs) {
            throw self::invalidToken();
        }

        if (!is_numeric($iat) || (int) $iat > $nowTs + $leeway) {
            throw self::invalidToken();
        }

        if (!is_numeric($authTime) || (int) $authTime > $nowTs + $leeway || (int) $authTime < 0) {
            throw self::invalidToken();
        }

        $phoneRaw = $claims['phone_number'] ?? null;

        if (!is_string($phoneRaw) || trim($phoneRaw) === '') {
            throw self::invalidPhone();
        }

        try {
            $phone = Phone::normalize($phoneRaw);
        } catch (Throwable) {
            throw self::invalidPhone();
        }

        return [
            'uid' => trim($subject),
            'phone' => $phone,
            'claims' => $claims,
        ];
    }

    /**
     * @return array<string, string> kid => PEM certificate
     */
    private function certificates(): array
    {
        $now = $this->now ?? Clock::now();
        $cached = $this->readCache($now->getTimestamp());

        if ($cached !== null) {
            return $cached;
        }

        $fetched = $this->fetchStage($this->certUrl);

        if ($fetched === null) {
            throw self::unavailable();
        }

        $body = $fetched['body'];
        $certs = json_decode($body, true);

        if (!is_array($certs) || $certs === []) {
            throw self::unavailable();
        }

        $normalized = [];

        foreach ($certs as $kid => $pem) {
            if (is_string($kid) && $kid !== '' && is_string($pem) && $pem !== '') {
                $normalized[$kid] = $pem;
            }
        }

        if ($normalized === []) {
            throw self::unavailable();
        }

        $ttl = (int) ($fetched['max_age'] ?? 0);

        if ($ttl <= 0) {
            $ttl = max(60, (int) Config::get('firebase.cache_ttl_seconds', 3600));
        }

        $this->writeCache($normalized, $now->getTimestamp() + $ttl);

        return $normalized;
    }

    /**
     * @return array<string, string>|null
     */
    private function readCache(int $nowTs): ?array
    {
        if ($this->cachePath === '' || !is_file($this->cachePath)) {
            return null;
        }

        $raw = @file_get_contents($this->cachePath);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['expires_at'], $decoded['certs'])
            || !is_numeric($decoded['expires_at']) || !is_array($decoded['certs'])) {
            return null;
        }

        if ((int) $decoded['expires_at'] <= $nowTs) {
            return null;
        }

        $certs = [];

        foreach ($decoded['certs'] as $kid => $pem) {
            if (is_string($kid) && $kid !== '' && is_string($pem) && $pem !== '') {
                $certs[$kid] = $pem;
            }
        }

        return $certs === [] ? null : $certs;
    }

    /**
     * @param array<string, string> $certs
     */
    private function writeCache(array $certs, int $expiresAt): void
    {
        if ($this->cachePath === '') {
            return;
        }

        $directory = dirname($this->cachePath);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode(['expires_at' => $expiresAt, 'certs' => $certs], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        $temporary = $this->cachePath . '.tmp-' . bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
            return;
        }

        if (!@rename($temporary, $this->cachePath)) {
            @unlink($temporary);
        }
    }

    /**
     * @return array{body: string, max_age?: int}|null
     */
    private function fetchStage(string $url): ?array
    {
        if (is_callable($this->certFetcher)) {
            try {
                $result = ($this->certFetcher)($url);
            } catch (Throwable) {
                return null;
            }

            if (!is_array($result) || !isset($result['body']) || !is_string($result['body'])) {
                return null;
            }

            $maxAge = isset($result['max_age']) && is_numeric($result['max_age'])
                ? (int) $result['max_age'] : 0;

            return ['body' => $result['body'], 'max_age' => $maxAge];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: MtaaPass-FirebaseVerifier/1.0\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if (!is_string($body) || trim($body) === '') {
            return null;
        }

        $maxAge = 0;
        $headers = $http_response_header ?? [];

        foreach ($headers as $header) {
            if (stripos($header, 'cache-control:') === 0
                && preg_match('/max-age=(\d+)/i', $header, $matches) === 1) {
                $maxAge = (int) $matches[1];
            }
        }

        return ['body' => $body, 'max_age' => $maxAge];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $segment): array
    {
        $json = $this->base64UrlDecode($segment);

        if ($json === '') {
            throw self::invalidToken();
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw self::invalidToken();
        }

        if (!is_array($decoded)) {
            throw self::invalidToken();
        }

        return $decoded;
    }

    private function base64UrlDecode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $remainder = strlen($normalized) % 4;

        if ($remainder > 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalized, true);

        if ($decoded === false) {
            throw self::invalidToken();
        }

        return $decoded;
    }

    private static function invalidToken(): HttpException
    {
        return new HttpException(
            401,
            'INVALID_FIREBASE_TOKEN',
            'We could not verify that sign-in. Please try again.'
        );
    }

    private static function invalidPhone(): HttpException
    {
        return new HttpException(
            422,
            'FIREBASE_PHONE_INVALID',
            'We could not verify that phone number. Please try again.'
        );
    }

    private static function unavailable(): HttpException
    {
        return new HttpException(
            503,
            'SERVICE_UNAVAILABLE',
            'Sign-in is temporarily unavailable. Please try again shortly.'
        );
    }
}
