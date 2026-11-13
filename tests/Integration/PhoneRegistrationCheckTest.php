<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;

/**
 * Owner request (2026-11-13): enforce the platform registration check BEFORE the
 * app triggers a Firebase SMS OTP, so unregistered numbers never waste an OTP.
 *
 * POST /login/check-phone is the pre-flight endpoint used by the Firebase login
 * script: it only allows numbers that exist as an active user with at least one
 * active estate membership, is CSRF-protected and throttled, and never creates
 * an OTP challenge or sends an SMS itself.
 */
final class PhoneRegistrationCheckTest extends VisitorModuleTestCase
{
    /** @return array<string, mixed> */
    private function seededActivePhone(): array
    {
        $row = $this->db->selectOne(
            "SELECT u.id, u.phone
             FROM users u
             INNER JOIN estate_memberships em ON em.user_id = u.id AND em.status = 'active'
             WHERE u.status = 'active'
             ORDER BY u.id ASC
             LIMIT 1"
        );

        $this->assertNotNull($row, 'A seeded active user with an active membership is required.');

        return (array) $row;
    }

    private function otpChallengeCount(): int
    {
        return (int) (($this->db->selectOne('SELECT COUNT(*) AS c FROM otp_challenges') ?? [])['c'] ?? 0);
    }

    public function testRegisteredNumberIsAllowedAndCreatesNoChallenge(): void
    {
        $user = $this->seededActivePhone();
        $before = $this->otpChallengeCount();

        $response = $this->post('/login/check-phone', ['phone' => (string) $user['phone']]);

        $this->assertStatus(200, $response);
        $payload = $this->json($response);
        $this->assertSame(true, $payload['registered'] ?? null, 'A registered, active estate member must be allowed to request an OTP.');
        $this->assertSame(true, $payload['ok'] ?? null);
        $this->assertSame($before, $this->otpChallengeCount(), 'The pre-flight check itself must never create an OTP challenge.');
    }

    public function testLocalPhoneFormatIsAcceptedForARegisteredNumber(): void
    {
        $user = $this->seededActivePhone();
        $e164 = (string) $user['phone'];
        // Turn +254712345678 into the local 0712345678 form the login form accepts.
        $local = '0' . substr($e164, 4);

        $response = $this->post('/login/check-phone', ['phone' => $local]);

        $this->assertStatus(200, $response);
        $garbage = $this->json($response);
        $this->assertSame(true, $garbage['ok'] ?? null);
    }

    public function testUnregisteredNumberIsRejectedWithoutTriggeringOtp(): void
    {
        $before = $this->otpChallengeCount();

        $response = $this->post('/login/check-phone', ['phone' => '+254799999991']);

        $this->assertStatus(200, $response);
        $payload = $this->json($response);
        $this->assertSame(false, $payload['registered'] ?? null, 'Unregistered numbers must not be allowed to trigger an OTP.');
        $this->assertSame($before, $this->otpChallengeCount(), 'Rejected numbers must not create OTP challenges.');
    }

    public function testInactiveUserIsRejected(): void
    {
        $user = $this->seededActivePhone();
        $this->db->update('users', ['status' => 'suspended'], ['id' => (int) $user['id']]);

        $response = $this->post('/login/check-phone', ['phone' => (string) $user['phone']]);

        $this->assertStatus(200, $response);
        $payload = $this->json($response);
        $this->assertSame(false, $payload['registered'] ?? null, 'Suspended users must not be treated as registered.');
    }

    public function testInvalidNumberReturns422(): void
    {
        $response = $this->post('/login/check-phone', ['phone' => 'not-a-phone']);

        $this->assertStatus(422, $response);
        $payload = $this->json($response);
        $this->assertSame(false, $payload['registered'] ?? null);
        $this->assertSame('invalid_phone', $payload['error'] ?? null);
    }

    public function testCsrfTokenIsRequired(): void
    {
        $response = $this->post('/login/check-phone', ['phone' => '+254712345678'], false);

        $this->assertStatus(403, $response);
    }

    public function testLoginScriptChecksRegistrationBeforeCallingFirebase(): void
    {
        $script = (string) file_get_contents(BASE_PATH . '/public/assets/js/firebase-auth.js');

        $checkPos = strpos($script, '/login/check-phone');
        $firebasePos = strpos($script, 'signInWithPhoneNumber(getFirebaseAuth()');

        $this->assertNotFalse($checkPos, 'The login script must call the server registration pre-flight endpoint.');
        $this->assertNotFalse($firebasePos, 'The login script must still call Firebase signInWithPhoneNumber.');
        $this->assertLessThan($firebasePos, $checkPos, 'The registration check must be wired before the Firebase OTP call.');
        $this->assertStringContainsString('phone-not-registered', $script, 'Unregistered numbers need a clear message.');
        $this->assertStringContainsString('X-CSRF-Token', $script, 'The pre-flight check must send the CSRF token.');
    }
}
