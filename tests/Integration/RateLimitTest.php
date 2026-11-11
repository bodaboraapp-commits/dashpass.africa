<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * OTP abuse controls (contract §7): resend cooldown, per-phone and per-IP fixed
 * windows, RATE_LIMITED envelope with a retry_after hint.
 */
final class RateLimitTest extends IntegrationTestCase
{
    private const JANE = '+254700000010';

    private function clearCooldown(): void
    {
        (new \App\Core\RateLimiter($this->db))->clearPrefix('otp:request:cooldown:');
    }

    public function testSecondImmediateOtpRequestIsRateLimited(): void
    {
        $this->assertSuccess($this->post('/api/v1/auth/request-otp', ['phone' => self::JANE], true));

        $response = $this->post('/api/v1/auth/request-otp', ['phone' => self::JANE], true);
        $payload = $this->assertErrorCode('RATE_LIMITED', $response, 429);

        $this->assertArrayHasKey('retry_after', $payload['error'], '429 responses carry a retry_after hint.');
        $this->assertTrue(is_numeric($payload['error']['retry_after']));
        $this->assertTrue((int) $payload['error']['retry_after'] > 0);
    }

    public function testSixthOtpRequestWithinWindowIsRejected(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->clearCooldown();
            $this->assertSuccess(
                $this->post('/api/v1/auth/request-otp', ['phone' => self::JANE], true),
                'OTP request ' . $attempt . ' of the first five must be allowed.'
            );
        }

        $this->clearCooldown();
        $response = $this->post('/api/v1/auth/request-otp', ['phone' => self::JANE], true);

        $this->assertErrorCode('RATE_LIMITED', $response, 429);
    }

    public function testLimitsAreKeyedByHashedPhoneNotPlaintext(): void
    {
        $this->assertSuccess($this->post('/api/v1/auth/request-otp', ['phone' => self::JANE], true));

        $keys = array_map(
            static fn (array $row): string => (string) $row['rl_key'],
            $this->db->select("SELECT rl_key FROM rate_limits WHERE rl_key LIKE 'otp:request:phone:%'")
        );

        $this->assertCount(1, $keys, 'One per-phone counter must be recorded.');

        $expectedHash = hash('sha256', self::JANE);
        $this->assertSame('otp:request:phone:' . $expectedHash, $keys[0]);
        $this->assertFalse(str_contains($keys[0], '+2547'), 'The plaintext phone number must never be a rate-limit key.');
    }

    public function testDifferentPhoneNumbersHaveIndependentLimits(): void
    {
        $this->assertSuccess($this->post('/api/v1/auth/request-otp', ['phone' => self::JANE], true));
        $this->assertSuccess($this->post('/api/v1/auth/request-otp', ['phone' => '+254700000002'], true));
    }

    public function testRateLimitRowsCarryExpiry(): void
    {
        $this->assertSuccess($this->post('/api/v1/auth/request-otp', ['phone' => self::JANE], true));

        $rows = $this->db->select('SELECT rl_key, window_started_at, hits, expires_at FROM rate_limits');

        $this->assertTrue($rows !== []);
        foreach ($rows as $row) {
            $this->assertTrue((int) $row['hits'] >= 1);
            $this->assertNotNull($row['window_started_at']);
            $this->assertNotNull($row['expires_at']);
        }
    }

    public function testClearingLimitsRestoresAccess(): void
    {
        $this->assertSuccess($this->post('/api/v1/auth/request-otp', ['phone' => self::JANE], true));
        $this->assertErrorCode('RATE_LIMITED', $this->post('/api/v1/auth/request-otp', ['phone' => self::JANE], true), 429);

        $this->clearRateLimits();
        $this->assertSuccess($this->post('/api/v1/auth/request-otp', ['phone' => self::JANE], true));
    }
}
