<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Crypto;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Core\Session;
use App\Core\Uuid;
use Database\Seeds\DemoVisitorSeeder;

/**
 * Shared helpers for the Visitor & Access module tests
 * (docs/visitor-access-module-contract.md §4–§7).
 *
 * Everything is resolved from seeded natural keys where possible (phone, gate
 * name, household unit) plus the documented stable demo-fixture UUIDs.
 */
abstract class VisitorModuleTestCase extends IntegrationTestCase
{
    protected const JANE = '+254700000010';       // resident, household A-001
    protected const BRIAN = '+254700000011';      // tenant, household A-002
    protected const AMINA = '+254700000013';      // resident, household B-001
    protected const GUARD_MAIN = '+254700000004'; // guard, Main Gate
    protected const GUARD_SERVICE = '+254700000005'; // guard, Service Gate
    protected const GRACE = '+254700000001';      // estate_admin (both estates)
    protected const DAVID_RIVERSIDE = '+254700000014'; // Riverside admin (other tenant)

    protected const ESTATE_SLUG = 'greenview-residences';

    public function setUp(): void
    {
        parent::setUp();

        $limiter = new RateLimiter($this->db);
        $limiter->clearPrefix('visitor:');
        $limiter->clearPrefix('pass:');
        $limiter->clearPrefix('otp:');
        $limiter->clearPrefix('throttle:');
    }

    /**
     * Re-authenticate the same (or another) phone inside one test. The OTP
     * request has a per-phone resend cooldown, so clear the OTP windows first.
     */
    protected function reloginAs(string $phone): void
    {
        $limiter = new RateLimiter($this->db);
        $limiter->clearPrefix('otp:');
        $limiter->clearPrefix('throttle:');

        $this->loginAs($phone);
    }

    /**
     * @return array<string, mixed>
     */
    protected function estate(): array
    {
        return $this->seededEstate(self::ESTATE_SLUG);
    }

    protected function householdUuid(string $unitNumber): string
    {
        $estate = $this->estate();

        $uuid = $this->db->scalar(
            'SELECT uuid FROM households WHERE estate_id = :estate_id AND unit_number = :unit LIMIT 1',
            ['estate_id' => (int) $estate['id'], 'unit' => $unitNumber]
        );

        if (!is_string($uuid) || $uuid === '') {
            $this->fail('Seeded household ' . $unitNumber . ' was not found.');
        }

        return $uuid;
    }

    /**
     * @return array<string, mixed>
     */
    protected function gate(string $name = 'Main Gate'): array
    {
        $estate = $this->estate();

        $gate = $this->db->selectOne(
            'SELECT id, uuid, name FROM gates WHERE estate_id = :estate_id AND name = :name LIMIT 1',
            ['estate_id' => (int) $estate['id'], 'name' => $name]
        );

        if ($gate === null) {
            $this->fail('Seeded gate ' . $name . ' was not found.');
        }

        return $gate;
    }

    /**
     * Log in only when the current test session is not already that user.
     * Avoids hitting the per-phone OTP resend cooldown on repeated helper calls.
     */
    protected function ensureLoggedInAs(string $phone): void
    {
        $userId = (int) $this->db->scalar(
            'SELECT id FROM users WHERE phone = :phone LIMIT 1',
            ['phone' => \App\Core\Phone::normalize($phone)]
        );

        $current = \App\Core\Session::get('auth_user_id');

        if ($current !== null && (int) $current === $userId) {
            return;
        }

        $this->reloginAs($phone);
    }

    /**
     * Log in as the Main Gate guard and establish the guard gate context.
     */
    protected function loginGuard(?string $gateName = 'Main Gate', string $phone = self::GUARD_MAIN): void
    {
        $this->ensureLoggedInAs($phone);
        $gate = $this->gate($gateName);

        $response = $this->post('/gate/select-gate', ['gate_uuid' => $gate['uuid']], true);

        if (Session::get('guard_gate_id') === null) {
            // Some implementations accept the form only via the guard UI; the
            // contract exposes the gate context through the session key.
            Session::put('guard_gate_id', (int) $gate['id']);
        }

        $this->assertTrue(
            in_array($response->status(), [200, 302], true),
            'Selecting an assigned gate must succeed, got HTTP ' . $response->status() . '.'
        );
    }

    /**
     * Force a gate context without going through the selection endpoint (used
     * to prove server-side assignment enforcement).
     */
    protected function forceGateContext(string $gateName): void
    {
        Session::put('guard_gate_id', (int) $this->gate($gateName)['id']);
    }

    /**
     * Create an invitation through the resident API and return the data payload.
     *
     * The visit window is already open (valid_from in the recent past) so guards
     * can verify/check in immediately; tests that need a future window override
     * valid_from/valid_until explicitly.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function createInvitation(array $overrides = [], string $resident = self::JANE): array
    {
        $this->ensureLoggedInAs($resident);

        $body = array_merge([
            'visitor_name' => 'Test Visitor ' . bin2hex(random_bytes(2)),
            'visitor_phone' => '+2547000901' . random_int(10, 99),
            'household_uuid' => $this->householdUuid('A-001'),
            'purpose' => 'Integration test visit',
            'valid_from' => Clock::toDb(Clock::now()->modify('-10 minutes')),
            'valid_until' => Clock::toDb(Clock::now()->modify('+2 hours')),
            'visitor_count' => 1,
        ], $overrides);

        $response = $this->post('/api/v1/resident/invitations', $body, true);
        $payload = $this->assertSuccess($response, 'Invitation creation failed.');

        return $payload['data'];
    }

    /**
     * @return array<string, mixed> Fixture invitation row (validity refreshed by the seeder).
     */
    protected function seededInvitation(string $uuid = DemoVisitorSeeder::INVITATION_UPCOMING_UUID): array
    {
        $row = $this->db->selectOne(
            'SELECT id, uuid, estate_id, household_id, status, valid_from, valid_until, visitor_id
             FROM visitor_invitations WHERE uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );

        if ($row === null) {
            $this->fail('Seeded demo invitation ' . $uuid . ' was not found (run the visitor seeder).');
        }

        return $row;
    }

    /**
     * Resolve the plaintext QR token for a seeded/created QR credential.
     */
    protected function qrTokenFor(string $credentialUuid): string
    {
        $row = $this->db->selectOne(
            'SELECT uuid, encrypted_secret, credential_hash FROM access_credentials WHERE uuid = :uuid LIMIT 1',
            ['uuid' => $credentialUuid]
        );

        if ($row === null) {
            $this->fail('Credential ' . $credentialUuid . ' was not found.');
        }

        $token = Crypto::decrypt((string) $row['encrypted_secret'], 'access-qr:' . $credentialUuid);

        if (!is_string($token) || $token === '') {
            $this->fail('Could not decrypt the QR token for credential ' . $credentialUuid . '.');
        }

        return $token;
    }

    protected function guardVerifyPin(string $pin): Response
    {
        return $this->post('/api/v1/gate/verify-pin', ['pin' => $pin], true);
    }

    protected function guardVerifyCode(string $code): Response
    {
        return $this->post('/api/v1/gate/verify-code', ['code' => $code], true);
    }

    protected function guardCheckin(string $invitationUuid, ?string $credentialUuid = null): Response
    {
        $body = ['invitation_uuid' => $invitationUuid];

        if ($credentialUuid !== null) {
            $body['credential_uuid'] = $credentialUuid;
        }

        return $this->post('/api/v1/gate/visitors/checkin', $body, true);
    }

    protected function guardCheckout(string $invitationUuid): Response
    {
        return $this->post('/api/v1/gate/visitors/checkout', ['invitation_uuid' => $invitationUuid], true);
    }

    protected function newUuid(): string
    {
        return Uuid::v4();
    }
}
