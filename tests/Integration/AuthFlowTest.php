<?php

declare(strict_types=1);

namespace Tests\Integration;


/**
 * Full development OTP authentication flow (contract §7, §8.1): request code,
 * verify it, read the identity payload, log out and confirm the session is dead.
 */
final class AuthFlowTest extends IntegrationTestCase
{
    private const JANE = '+254700000010';
    private const GRACE = '+254700000001';

    /**
     * Request a development OTP and return the code plus the raw data payload.
     *
     * @return array{code: string, data: array<string, mixed>}
     */
    private function requestDevOtp(string $phone): array
    {
        $response = $this->post('/api/v1/auth/request-otp', ['phone' => $phone], true);
        $payload = $this->assertSuccess($response, 'OTP request failed for ' . $phone);

        $data = $payload['data'];
        $this->assertTrue(($data['requested'] ?? null) === true, 'data.requested must be true.');
        $this->assertSame(300, (int) ($data['expires_in'] ?? -1), 'data.expires_in must be the OTP TTL (300s).');
        $this->assertTrue(isset($data['dev_otp']) && is_string($data['dev_otp']), 'The dev OTP adapter must expose dev_otp in tests.');
        $this->assertMatches('/^\d{6}$/', $data['dev_otp'], 'The development OTP is a 6 digit code.');
        $this->assertTrue(isset($data['dev_note']) && is_string($data['dev_note']));
        $this->assertContains('Development', (string) $data['dev_note']);

        return ['code' => (string) $data['dev_otp'], 'data' => $data];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertIdentityPayload(array $data): void
    {
        $this->assertArrayHasKey('user', $data);
        $this->assertArrayHasKey('memberships', $data);
        $this->assertArrayHasKey('active_estate', $data);
        $this->assertArrayHasKey('permissions', $data);
        $this->assertArrayHasKey('csrf_token', $data);

        $user = $data['user'];
        foreach (['uuid', 'full_name', 'phone', 'email'] as $key) {
            $this->assertArrayHasKey($key, $user, 'User payload is missing ' . $key);
        }
        $this->assertMatches('/^[0-9a-f-]{36}$/', (string) $user['uuid']);
        $this->assertMatches('/^\+2547\d{8}$/', (string) $user['phone']);

        $this->assertTrue(is_array($data['memberships']));
        $this->assertTrue(is_array($data['permissions']));
        $this->assertMatches('/^[0-9a-f]{64}$/', (string) $data['csrf_token']);

        foreach ($data['memberships'] as $membership) {
            foreach (['estate_uuid', 'estate_name', 'estate_slug', 'estate_type', 'estate_timezone', 'role_name', 'is_active'] as $key) {
                $this->assertArrayHasKey($key, $membership, 'Membership payload is missing ' . $key);
            }

            $this->assertTrue(is_bool($membership['is_active']), 'membership.is_active must be a boolean.');
        }
    }

    public function testRequestOtpReturnsDevelopmentCode(): void
    {
        $otp = $this->requestDevOtp(self::JANE);
        $this->assertMatches('/^\d{6}$/', $otp['code']);

        // Exactly one unconsumed challenge exists for the phone.
        $count = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM otp_challenges WHERE phone = :phone AND purpose = :purpose AND consumed_at IS NULL',
            ['phone' => self::JANE, 'purpose' => 'login']
        );
        $this->assertSame(1, $count);
    }

    public function testRequestOtpForUnknownNumberIsGenericAndRevealsNothing(): void
    {
        $response = $this->post('/api/v1/auth/request-otp', ['phone' => '+254799999999'], true);
        $payload = $this->assertSuccess($response);
        $data = $payload['data'];

        $this->assertTrue(($data['requested'] ?? null) === true);
        $this->assertSame(300, (int) ($data['expires_in'] ?? -1));
        $this->assertFalse(array_key_exists('dev_otp', $data), 'No OTP may be generated for an unknown number.');
        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM otp_challenges WHERE phone = :phone', ['phone' => '+254799999999'])
        );
    }

    public function testFullOtpLoginLogoutFlow(): void
    {
        $otp = $this->requestDevOtp(self::JANE);

        $verify = $this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => $otp['code']], true);
        $payload = $this->assertSuccess($verify, 'Valid OTP verification must succeed.');
        $data = $payload['data'];

        $this->assertIdentityPayload($data);
        $this->assertSame('Jane Smith', (string) $data['user']['full_name']);
        $this->assertSame(self::JANE, (string) $data['user']['phone']);
        $this->assertSame('/app', (string) ($data['redirect'] ?? ''), 'verify-otp must tell the PWA to redirect to /app.');

        $this->assertCount(1, $data['memberships']);
        $this->assertSame('greenview-residences', (string) $data['memberships'][0]['estate_slug']);
        $this->assertSame('resident', (string) $data['memberships'][0]['role_name']);
        $this->assertSame('Greenview Residences', (string) $data['memberships'][0]['estate_name']);

        $this->assertNotNull($data['active_estate'], 'A user with one membership must have an active estate.');
        $this->assertSame('greenview-residences', (string) $data['active_estate']['estate_slug']);
        $this->assertSame('resident', (string) $data['active_estate']['role_name']);
        $this->assertContains('estate.view', $data['permissions']);
        $this->assertContains('visitor.invitation.create', $data['permissions']);
        $this->assertNotContains('audit.view', $data['permissions']);

        // /auth/me must reflect the authenticated identity and current estate.
        $me = $this->get('/api/v1/auth/me');
        $mePayload = $this->assertSuccess($me);
        $this->assertIdentityPayload($mePayload['data']);
        $this->assertSame(self::JANE, (string) $mePayload['data']['user']['phone']);
        $this->assertSame('greenview-residences', (string) $mePayload['data']['active_estate']['estate_slug']);

        // Logout is 204 with no body, and the session is destroyed.
        $logout = $this->post('/api/v1/auth/logout', [], true);
        $this->assertStatus(204, $logout);
        $this->assertSame('', $logout->body());

        $unauthorized = $this->get('/api/v1/auth/me');
        $this->assertErrorCode('UNAUTHENTICATED', $unauthorized, 401);
    }

    public function testInvalidCodeReturns422(): void
    {
        $otp = $this->requestDevOtp(self::JANE);
        $wrong = $this->wrongCodeFor($otp['code']);

        $response = $this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => $wrong], true);

        $this->assertErrorCode('INVALID_OTP', $response, 422);
    }

    public function testCorrectCodeStillWorksAfterOneWrongAttempt(): void
    {
        $otp = $this->requestDevOtp(self::JANE);

        $this->assertErrorCode(
            'INVALID_OTP',
            $this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => $this->wrongCodeFor($otp['code'])], true),
            422
        );

        $response = $this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => $otp['code']], true);
        $payload = $this->assertSuccess($response);
        $this->assertSame(self::JANE, (string) $payload['data']['user']['phone']);
    }

    public function testVerifyWithoutAChallengeReturns422(): void
    {
        $response = $this->post('/api/v1/auth/verify-otp', ['phone' => self::JANE, 'code' => '123456'], true);

        $this->assertErrorCode('INVALID_OTP', $response, 422);
    }

    public function testRequestOtpValidatesPhone(): void
    {
        $response = $this->post('/api/v1/auth/request-otp', [], true);

        $payload = $this->assertErrorCode('VALIDATION_FAILED', $response, 422);
        $this->assertArrayHasKey('phone', $payload['error']['errors']);
    }

    public function testRequestOtpRejectsMalformedPhone(): void
    {
        $response = $this->post('/api/v1/auth/request-otp', ['phone' => 'not-a-phone'], true);

        $payload = $this->assertErrorCode('VALIDATION_FAILED', $response, 422);
        $this->assertArrayHasKey('phone', $payload['error']['errors']);
    }

    public function testMultiEstateUserPayloadContainsEveryMembership(): void
    {
        $this->loginAs(self::GRACE);

        $me = $this->assertSuccess($this->get('/api/v1/auth/me'));
        $data = $me['data'];

        $this->assertIdentityPayload($data);
        $this->assertCount(2, $data['memberships'], 'Grace must belong to both demo estates.');

        $slugs = array_map(
            static fn (array $membership): string => (string) $membership['estate_slug'],
            $data['memberships']
        );
        sort($slugs);
        $this->assertSame(['greenview-residences', 'riverside-court'], $slugs);

        foreach ($data['memberships'] as $membership) {
            $this->assertSame('estate_admin', (string) $membership['role_name']);
        }

        // Exactly one membership is the active one.
        $activeFlags = array_filter(
            array_map(static fn (array $membership): bool => (bool) $membership['is_active'], $data['memberships'])
        );
        $this->assertCount(1, $activeFlags, 'Exactly one membership must be marked active.');

        $activeSlug = (string) $data['active_estate']['estate_slug'];
        $this->assertContains($activeSlug, ['greenview-residences', 'riverside-court']);
    }

    public function testOtpTokenIsNotThePlaintextCode(): void
    {
        $otp = $this->requestDevOtp(self::JANE);

        $hash = (string) $this->db->scalar('SELECT otp_hash FROM otp_challenges ORDER BY id DESC LIMIT 1');

        $this->assertMatches('/^[0-9a-f]{64}$/', $hash);
        $this->assertNotSame($otp['code'], $hash);
        $this->assertFalse(str_contains($hash, $otp['code']));
    }

    private function wrongCodeFor(string $code): string
    {
        $first = $code[0];
        $replacement = $first === '9' ? '0' : (string) (((int) $first) + 1);

        return $replacement . substr($code, 1);
    }
}
