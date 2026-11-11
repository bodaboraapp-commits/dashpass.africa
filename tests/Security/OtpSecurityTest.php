<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Clock;
use App\Core\Crypto;
use App\Core\RateLimiter;
use App\Integrations\Sms\MockSmsProvider;

/**
 * OTP hardening (Blueprint §41/§42, contract §7): keyed storage, single use,
 * expiry, attempt lockout and no credential plaintext in persistence or audit.
 */
final class OtpSecurityTest extends SecurityTestCase
{
    private const JANE = '+254700000010';

    /**
     * @return array{code: string, challenge: array<string, mixed>}
     */
    private function requestOtp(string $phone = self::JANE): array
    {
        $response = $this->post('/api/v1/auth/request-otp', ['phone' => $phone], true);
        $payload = $this->assertSuccess($response);

        $code = (string) ($payload['data']['dev_otp'] ?? '');
        $this->assertMatches('/^\d{6}$/', $code);

        $challenge = $this->db->selectOne(
            'SELECT id, uuid, phone, purpose, otp_hash, expires_at, attempts, max_attempts, consumed_at
             FROM otp_challenges WHERE phone = :phone AND purpose = :purpose
             ORDER BY id DESC LIMIT 1',
            ['phone' => $phone, 'purpose' => 'login']
        );

        $this->assertNotNull($challenge, 'Requesting an OTP must create an otp_challenges row.');

        return ['code' => $code, 'challenge' => $challenge];
    }

    private function clearCooldown(): void
    {
        (new RateLimiter($this->db))->clearPrefix('otp:request:cooldown:');
    }

    private function wrongCodeFor(string $code): string
    {
        return ($code[0] === '9' ? '0' : (string) (((int) $code[0]) + 1)) . substr($code, 1);
    }

    public function testOtpIsStoredAsKeyedHashOnly(): void
    {
        $otp = $this->requestOtp();
        $challenge = $otp['challenge'];

        $this->assertSame(64, strlen((string) $challenge['otp_hash']));
        $this->assertMatches('/^[0-9a-f]{64}$/', (string) $challenge['otp_hash']);
        $this->assertNotSame($otp['code'], (string) $challenge['otp_hash']);

        $expected = Crypto::hmac('otp:' . (string) $challenge['uuid'], $otp['code']);
        $this->assertSame($expected, (string) $challenge['otp_hash'], 'The stored hash must be HMAC(otp:{uuid}, code).');

        $serialized = json_encode($challenge);
        $this->assertFalse(str_contains((string) $serialized, $otp['code']), 'No column may contain the plaintext OTP.');
    }

    public function testOtpIsSingleUse(): void
    {
        $otp = $this->requestOtp();

        $this->assertSuccess($this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => $otp['code']], true));

        $consumedAt = $this->db->scalar(
            'SELECT consumed_at FROM otp_challenges WHERE id = :id',
            ['id' => (int) $otp['challenge']['id']]
        );
        $this->assertNotNull($consumedAt, 'A used challenge must be marked consumed.');

        $replay = $this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => $otp['code']], true);
        $this->assertErrorCode('INVALID_OTP', $replay, 422);
    }

    public function testExpiredOtpIsRejected(): void
    {
        $otp = $this->requestOtp();

        $this->db->execute(
            'UPDATE otp_challenges SET expires_at = :expires WHERE id = :id',
            ['expires' => Clock::toDb(Clock::now()->modify('-60 seconds')), 'id' => (int) $otp['challenge']['id']]
        );

        $response = $this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => $otp['code']], true);

        $this->assertErrorCode('INVALID_OTP', $response, 422);
    }

    public function testAttemptLockoutAfterMaxAttempts(): void
    {
        $otp = $this->requestOtp();
        $maxAttempts = (int) $otp['challenge']['max_attempts'];

        $this->assertSame(5, $maxAttempts, 'OTP challenges allow 5 attempts by default.');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->post(
                '/api/v1/auth/verify-otp',
                ['phone' => self::JANE, 'code' => $this->wrongCodeFor($otp['code'])],
                true
            );
            $this->assertErrorCode('INVALID_OTP', $response, 422);
        }

        $locked = $this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => $otp['code']], true);

        $this->assertErrorCode('OTP_ATTEMPTS_EXCEEDED', $locked, 429);
    }

    public function testNewOtpRequestConsumesThePreviousChallenge(): void
    {
        $first = $this->requestOtp();
        $this->clearCooldown();
        $second = $this->requestOtp();

        $firstConsumed = $this->db->scalar(
            'SELECT consumed_at FROM otp_challenges WHERE id = :id',
            ['id' => (int) $first['challenge']['id']]
        );
        $this->assertNotNull($firstConsumed, 'Requesting a new OTP must invalidate the previous unconsumed challenge.');

        $this->assertErrorCode(
            'INVALID_OTP',
            $this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => $first['code']], true),
            422
        );

        $this->assertSuccess(
            $this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => $second['code']], true)
        );
    }

    public function testPlaintextOtpIsNeverPersistedInAuditOrNotificationLogs(): void
    {
        $otp = $this->requestOtp();
        $this->assertSuccess($this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => $otp['code']], true));

        $tables = ['audit_logs', 'notification_logs', 'rate_limits', 'otp_challenges'];

        foreach ($tables as $table) {
            $rows = $this->db->select('SELECT * FROM `' . $table . '`');

            foreach ($rows as $row) {
                $this->assertFalse(
                    str_contains((string) json_encode($row), $otp['code']),
                    sprintf('The plaintext OTP must never be stored in %s.', $table)
                );
            }
        }
    }

    public function testSmsIsDispatchedThroughTheMockProviderAndLogged(): void
    {
        MockSmsProvider::reset();

        $otp = $this->requestOtp();

        $messages = MockSmsProvider::messages();
        $this->assertCount(1, $messages, 'Requesting an OTP must send exactly one SMS through the mock provider.');
        $this->assertSame(self::JANE, (string) ($messages[0]['phone'] ?? $messages[0]['to'] ?? ''));
        $this->assertContains($otp['code'], (string) ($messages[0]['message'] ?? $messages[0]['body'] ?? ''));

        $log = $this->db->selectOne(
            "SELECT channel, recipient, template, status FROM notification_logs WHERE template = 'otp' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($log, 'OTP SMS delivery must be recorded in notification_logs.');
        $this->assertSame('sms', (string) $log['channel']);
        $this->assertSame('otp', (string) $log['template']);
        $this->assertContains((string) $log['status'], ['sent', 'queued', 'delivered']);

        MockSmsProvider::reset();
    }
}
