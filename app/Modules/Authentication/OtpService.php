<?php

declare(strict_types=1);

namespace App\Modules\Authentication;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Phone;
use App\Core\RateLimiter;
use App\Core\Uuid;
use App\Integrations\Sms\SmsManager;
use App\Integrations\Sms\SmsProvider;
use App\Modules\Notifications\NotificationLogger;
use InvalidArgumentException;
use Throwable;

/**
 * SMS one-time-password lifecycle (Blueprint §41, foundation contract §7).
 *
 * Security properties:
 *  - codes are generated with random_int() and stored only as a keyed HMAC
 *    scoped to the challenge uuid;
 *  - prior unconsumed challenges are invalidated when a new one is issued;
 *  - verification is transactional with SELECT ... FOR UPDATE, single use,
 *    expiry-checked and attempt-locked;
 *  - rate limits are applied before any challenge work;
 *  - unknown phone numbers get the same public response as known ones.
 */
final class OtpService
{
    private const PURPOSE_LOGIN = 'login';

    /** @var array<int, string> */
    private const PURPOSES = ['login', 'staff_login'];

    private readonly RateLimiter $rateLimiter;

    private readonly string $providerName;

    public function __construct(
        private readonly Database $db,
        private readonly SmsProvider $sms,
        private readonly NotificationLogger $notifications,
        ?RateLimiter $rateLimiter = null,
        ?string $providerName = null
    ) {
        $this->rateLimiter = $rateLimiter ?? new RateLimiter($db);
        $this->providerName = $providerName ?? (new SmsManager())->driver();
    }

    public static function make(): self
    {
        $db = Database::instance();
        $manager = new SmsManager();

        return new self($db, $manager->make(), new NotificationLogger($db), null, $manager->driver());
    }

    /**
     * Issue a login OTP challenge and deliver it by SMS.
     *
     * @return array{requested: true, expires_in: int, dev_otp?: string, dev_note?: string}
     *
     * @throws HttpException 429 when a rate limit is exceeded.
     */
    public function request(string $e164Phone, string $purpose, string $ip, ?string $userAgent = null): array
    {
        $phone = self::normalizePhone($e164Phone);
        self::assertPurpose($purpose);

        $ttl = max(60, (int) Config::get('security.otp.ttl_seconds', 300));
        $length = max(4, (int) Config::get('security.otp.length', 6));
        $maxAttempts = max(1, (int) Config::get('security.otp.max_attempts', 5));
        $cooldown = max(1, (int) Config::get('security.otp.resend_cooldown_seconds', 60));
        $phoneLimit = (array) Config::get('security.rate_limits.otp_request_phone', ['max' => 5, 'window' => 900]);
        $ipLimit = (array) Config::get('security.rate_limits.otp_request_ip', ['max' => 20, 'window' => 900]);

        $phoneHash = hash('sha256', $phone);

        // Order matters: the cheap resend cooldown is checked first, then the
        // per-phone and finally the per-IP windows.
        foreach ([
            ['otp:request:cooldown:' . $phoneHash, 1, $cooldown],
            ['otp:request:phone:' . $phoneHash, (int) ($phoneLimit['max'] ?? 5), (int) ($phoneLimit['window'] ?? 900)],
            ['otp:request:ip:' . $ip, (int) ($ipLimit['max'] ?? 20), (int) ($ipLimit['window'] ?? 900)],
        ] as [$key, $max, $window]) {
            $result = $this->rateLimiter->attempt($key, $max, $window);

            if (!$result['allowed']) {
                throw HttpException::tooManyRequests(
                    'Please wait before requesting another code.',
                    $result['retry_after']
                );
            }
        }

        $code = Crypto::numericCode($length);
        $expiresAt = Clock::now()->modify('+' . $ttl . ' seconds');

        $user = (new UserRepository($this->db))->findActiveByPhone($phone);

        if ($user === null) {
            // Do not create a challenge and do not reveal that the number is
            // unknown. Development mode may explain the situation.
            $payload = ['requested' => true, 'expires_in' => $ttl];

            if (!self::isProduction()) {
                $payload['dev_note'] = 'No active MtaaPass account is registered for this number (development mode).';
            }

            return $payload;
        }

        $userId = (int) $user['id'];
        $challengeUuid = Uuid::v4();
        $now = Clock::nowDb();

        $this->db->transaction(function (Database $db) use (
            $phone,
            $purpose,
            $challengeUuid,
            $code,
            $userId,
            $expiresAt,
            $maxAttempts,
            $ip,
            $userAgent,
            $now
        ): void {
            // Invalidate every prior unconsumed challenge for this phone/purpose.
            $db->execute(
                'UPDATE otp_challenges
                  SET consumed_at = :consumed_at
                  WHERE phone = :phone
                    AND purpose = :purpose
                    AND consumed_at IS NULL',
                ['consumed_at' => $now, 'phone' => $phone, 'purpose' => $purpose]
            );

            $db->insert('otp_challenges', [
                'uuid' => $challengeUuid,
                'phone' => $phone,
                'purpose' => $purpose,
                'user_id' => $userId,
                'otp_hash' => Crypto::hmac('otp:' . $challengeUuid, $code),
                'expires_at' => Clock::toDb($expiresAt),
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'created_at' => $now,
            ]);
        });

        // Deliver only after the challenge is committed; a provider failure must
        // not roll the challenge back (the user can simply try again).
        $minutes = max(1, (int) ceil($ttl / 60));
        $message = sprintf(
            'MtaaPass verification code: %s. Expires in %d minutes. Never share this code.',
            $code,
            $minutes
        );

        $status = 'sent';
        $providerMessageId = null;
        $error = null;

        try {
            $delivery = $this->sms->send($phone, $message);
            $providerMessageId = isset($delivery['message_id']) ? (string) $delivery['message_id'] : null;
            $reported = (string) ($delivery['status'] ?? 'sent');
            $status = in_array($reported, ['queued', 'sent', 'delivered'], true) ? $reported : 'sent';
        } catch (Throwable $exception) {
            $status = 'failed';
            $error = $exception->getMessage();
            // The message body (which contains the code) is never passed here.
            Logger::warning('OTP SMS delivery failed', [
                'provider' => $this->providerName,
                'error' => mb_substr($error, 0, 300),
            ]);
        }

        $this->notifications->logSms(null, $phone, 'otp', $this->providerName, $providerMessageId, $status, $error);

        $payload = ['requested' => true, 'expires_in' => $ttl];

        if (!self::isProduction() && (bool) Config::get('sms.expose_development_otp', false)) {
            $payload['dev_otp'] = $code;
            $payload['dev_note'] = 'Development OTP adapter — never enabled in production.';
        }

        return $payload;
    }

    /**
     * Verify a code and return the authenticated user id.
     *
     * @throws HttpException 422 INVALID_OTP / 429 OTP_ATTEMPTS_EXCEEDED / 429 RATE_LIMITED.
     */
    public function verify(string $e164Phone, string $code, string $purpose, string $ip): int
    {
        $phone = self::normalizePhone($e164Phone);
        $code = trim($code);
        self::assertPurpose($purpose);

        $phoneHash = hash('sha256', $phone);
        $phoneLimit = (array) Config::get('security.rate_limits.otp_verify_phone', ['max' => 10, 'window' => 900]);

        foreach ([
            ['otp:verify:phone:' . $phoneHash, (int) ($phoneLimit['max'] ?? 10), (int) ($phoneLimit['window'] ?? 900)],
            ['otp:verify:ip:' . $ip, 30, 900],
        ] as [$key, $max, $window]) {
            $result = $this->rateLimiter->attempt($key, $max, $window);

            if (!$result['allowed']) {
                throw HttpException::tooManyRequests(
                    'Too many attempts. Please wait and try again.',
                    $result['retry_after']
                );
            }
        }

        // The closure returns a success payload, or null for a mismatched code
        // (which is committed so the attempt counter is not lost).
        $outcome = $this->db->transaction(function (Database $db) use ($phone, $purpose, $code): ?array {
            $challenge = $db->selectOne(
                'SELECT id, uuid, user_id, otp_hash, expires_at, attempts, max_attempts, consumed_at
                 FROM otp_challenges
                 WHERE phone = :phone
                   AND purpose = :purpose
                   AND consumed_at IS NULL
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1
                 FOR UPDATE',
                ['phone' => $phone, 'purpose' => $purpose]
            );

            if ($challenge === null) {
                throw self::invalidOtp();
            }

            $expiresAt = Clock::fromDb((string) $challenge['expires_at']);

            if ($challenge['consumed_at'] !== null || $expiresAt === null || $expiresAt <= Clock::now()) {
                throw self::invalidOtp();
            }

            $attempts = (int) $challenge['attempts'];
            $maxAttempts = (int) $challenge['max_attempts'];

            if ($attempts >= $maxAttempts) {
                throw new HttpException(
                    429,
                    'OTP_ATTEMPTS_EXCEEDED',
                    'Too many incorrect attempts. Request a new code.'
                );
            }

            $expected = Crypto::hmac('otp:' . (string) $challenge['uuid'], $code);
            $matches = Crypto::equals((string) $challenge['otp_hash'], $expected);
            $nextAttempts = $attempts + 1;

            if (!$matches) {
                $db->execute(
                    'UPDATE otp_challenges SET attempts = :attempts WHERE id = :id',
                    ['attempts' => $nextAttempts, 'id' => (int) $challenge['id']]
                );

                return null;
            }

            $userId = (int) ($challenge['user_id'] ?? 0);
            $user = $userId > 0
                ? $db->selectOne('SELECT id, status FROM users WHERE id = :id LIMIT 1', ['id' => $userId])
                : null;

            if ($user === null || (string) $user['status'] !== 'active') {
                throw self::invalidOtp();
            }

            $db->execute(
                'UPDATE otp_challenges
                  SET consumed_at = :consumed_at, attempts = :attempts
                  WHERE id = :id',
                ['consumed_at' => Clock::nowDb(), 'attempts' => $nextAttempts, 'id' => (int) $challenge['id']]
            );

            return ['user_id' => $userId];
        });

        if ($outcome === null) {
            throw self::invalidOtp();
        }

        return (int) $outcome['user_id'];
    }

    private static function invalidOtp(): HttpException
    {
        return new HttpException(422, 'INVALID_OTP', 'The verification code is invalid or has expired.');
    }

    private static function normalizePhone(string $phone): string
    {
        if (Phone::isValid($phone)) {
            return $phone;
        }

        try {
            return Phone::normalize($phone, (string) Config::get('app.default_country_code', '254'));
        } catch (InvalidArgumentException) {
            throw HttpException::badRequest(
                'INVALID_PHONE',
                'Enter a valid phone number, for example 0712 345 678.'
            );
        }
    }

    private static function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw HttpException::badRequest('INVALID_OTP_PURPOSE', 'The verification request is not valid.');
        }
    }

    private static function isProduction(): bool
    {
        return (string) Config::get('app.env', 'production') === 'production';
    }
}
