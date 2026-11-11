<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Crypto;
use Database\Seeds\DemoVisitorSeeder;

/**
 * PIN/QR verification and the transactional check-in/check-out lifecycle
 * (visitor-access-module-contract §4.4–§4.5, §6, §9).
 */
final class VisitorAccessFlowTest extends VisitorModuleTestCase
{
    public function testSeededDemoPinVerifiesForTomorrowVisit(): void
    {
        $this->loginGuard('Main Gate');

        $payload = $this->assertSuccess($this->guardVerifyPin(DemoVisitorSeeder::DEMO_PIN));
        $data = $payload['data'];

        foreach (['credential_uuid', 'invitation_uuid', 'visitor_name', 'household_label', 'purpose', 'valid_from', 'valid_until', 'status', 'already_inside'] as $key) {
            $this->assertArrayHasKey($key, $data, 'Verify payload is missing ' . $key . '.');
        }

        $this->assertSame('Demo Visitor Three', (string) $data['visitor_name']);
        $this->assertSame(DemoVisitorSeeder::INVITATION_UPCOMING_UUID, (string) $data['invitation_uuid']);
        $this->assertSame('approved', (string) $data['status']);
        $this->assertFalse((bool) $data['already_inside']);

        // A successful verification is recorded.
        $attempt = $this->db->selectOne(
            'SELECT credential_type, success, gate_id FROM access_verification_attempts ORDER BY id DESC LIMIT 1'
        );
        $this->assertNotNull($attempt);
        $this->assertSame('pin', (string) $attempt['credential_type']);
        $this->assertSame(1, (int) $attempt['success']);
    }

    public function testInvalidPinIsGenericAndRecordedAsFailure(): void
    {
        $this->loginGuard('Main Gate');

        $response = $this->guardVerifyPin('000000');
        $this->assertErrorCode('INVALID_CREDENTIAL', $response, 422);

        $attempt = $this->db->selectOne(
            'SELECT credential_type, success FROM access_verification_attempts ORDER BY id DESC LIMIT 1'
        );
        $this->assertNotNull($attempt, 'Failed verifications must be recorded too.');
        $this->assertSame(0, (int) $attempt['success']);
    }

    public function testVerifiedCredentialAfterCheckinReportsAlreadyInside(): void
    {
        $data = $this->createInvitation();
        $pin = (string) $data['pin'];

        $this->loginGuard('Main Gate');
        $this->assertSuccess($this->guardCheckin((string) $data['invitation_uuid']));

        // Contract §4.4: a credential whose invitation is already inside verifies
        // for display with already_inside=true; a second check-in is a conflict.
        $payload = $this->assertSuccess($this->guardVerifyPin($pin));
        $this->assertTrue((bool) $payload['data']['already_inside'], 'The verification must report the visitor as already inside.');
        $this->assertSame('checked_in', (string) $payload['data']['status']);

        $this->assertErrorCode('ALREADY_INSIDE', $this->guardCheckin((string) $data['invitation_uuid']), 409);
    }

    public function testExpiredCredentialIsRejected(): void
    {
        $data = $this->createInvitation();
        $invitationUuid = (string) $data['invitation_uuid'];

        $this->db->execute(
            'UPDATE access_credentials ac INNER JOIN visitor_invitations vi ON vi.id = ac.invitation_id
             SET ac.expires_at = :past WHERE vi.uuid = :uuid',
            ['past' => Clock::toDb(Clock::now()->modify('-1 hour')), 'uuid' => $invitationUuid]
        );

        $this->loginGuard('Main Gate');
        $this->assertErrorCode('INVALID_CREDENTIAL', $this->guardVerifyPin((string) $data['pin']), 422);
    }

    public function testRevokedInvitationCredentialIsRejected(): void
    {
        $data = $this->createInvitation();
        $uuid = (string) $data['invitation_uuid'];

        $this->post('/api/v1/resident/invitations/' . $uuid . '/revoke', [], true);

        $this->loginGuard('Main Gate');
        $this->assertErrorCode('INVALID_CREDENTIAL', $this->guardVerifyPin((string) $data['pin']), 422);
    }

    public function testQrTokenVerifiesDirectlyAndAsFullPassUrl(): void
    {
        // Create a fresh invitation so both credential forms are untouched.
        $first = $this->createInvitation(['visitor_name' => 'QR One']);
        $qrToken = $this->lastQrToken();
        $this->assertMatches('/^[0-9a-f]{64}$/', $qrToken);

        $this->loginGuard('Main Gate');

        $direct = $this->assertSuccess($this->guardVerifyCode($qrToken));
        $this->assertSame('QR One', (string) $direct['data']['visitor_name']);
        $this->assertSame((string) $first['invitation_uuid'], (string) $direct['data']['invitation_uuid']);

        // Re-verify using the full pass URL form for a new invitation.
        $second = $this->createInvitation(['visitor_name' => 'QR Two']);
        $tokenTwo = $this->lastQrToken();
        $this->loginGuard('Main Gate');

        $viaUrl = $this->assertSuccess($this->guardVerifyCode('/v/' . $tokenTwo));
        $this->assertSame('QR Two', (string) $viaUrl['data']['visitor_name']);
        $this->assertSame((string) $second['invitation_uuid'], (string) $viaUrl['data']['invitation_uuid']);
    }

    public function testCheckinThenSecondCheckinConflict(): void
    {
        $data = $this->createInvitation();
        $uuid = (string) $data['invitation_uuid'];

        $this->loginGuard('Main Gate');

        $payload = $this->assertSuccess($this->guardCheckin($uuid));
        $this->assertSame('checked_in', (string) $payload['data']['status']);
        $this->assertArrayHasKey('occurred_at', $payload['data']);

        $status = (string) $this->db->scalar('SELECT status FROM visitor_invitations WHERE uuid = :uuid', ['uuid' => $uuid]);
        $this->assertSame('checked_in', $status);

        $event = $this->db->selectOne(
            "SELECT event_type, actor_id FROM visitor_access_events vae
             INNER JOIN visitor_invitations vi ON vi.id = vae.invitation_id
             WHERE vi.uuid = :uuid ORDER BY vae.id DESC LIMIT 1",
            ['uuid' => $uuid]
        );
        $this->assertNotNull($event);
        $this->assertSame('check_in', (string) $event['event_type']);

        $notification = $this->db->selectOne(
            "SELECT template, status FROM notification_logs WHERE template = 'visitor_checked_in' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($notification, 'Check-in must notify the host via the visitor_checked_in SMS template.');

        $audit = $this->db->selectOne(
            "SELECT action FROM audit_logs WHERE action = 'visitor.checked_in' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit);

        $this->assertErrorCode('ALREADY_INSIDE', $this->guardCheckin($uuid), 409);
    }

    public function testCheckoutThenSecondCheckoutConflict(): void
    {
        $data = $this->createInvitation();
        $uuid = (string) $data['invitation_uuid'];

        $this->loginGuard('Main Gate');
        $this->assertSuccess($this->guardCheckin($uuid));

        $payload = $this->assertSuccess($this->guardCheckout($uuid));
        $this->assertSame('checked_out', (string) $payload['data']['status']);

        $status = (string) $this->db->scalar('SELECT status FROM visitor_invitations WHERE uuid = :uuid', ['uuid' => $uuid]);
        $this->assertSame('checked_out', $status);

        $this->assertErrorCode('NOT_INSIDE', $this->guardCheckout($uuid), 409);
        $this->assertErrorCode('INVITATION_NOT_ACTIVE', $this->guardCheckin($uuid), 409);
    }

    public function testCheckinOutsideValidityWindowIsRejected(): void
    {
        // valid_from is in the future: the guard may verify, but not check in.
        $data = $this->createInvitation([
            'visitor_name' => 'Future Visitor',
            'valid_from' => Clock::toDb(Clock::now()->modify('+2 hours')),
            'valid_until' => Clock::toDb(Clock::now()->modify('+4 hours')),
        ]);
        $uuid = (string) $data['invitation_uuid'];

        $this->loginGuard('Main Gate');
        $this->assertSuccess($this->guardVerifyPin((string) $data['pin']));

        $this->assertErrorCode('OUTSIDE_VALIDITY', $this->guardCheckin($uuid), 422);
    }

    public function testCheckinConsumesCredentialAtomically(): void
    {
        $data = $this->createInvitation();
        $uuid = (string) $data['invitation_uuid'];

        $this->loginGuard('Main Gate');

        // The guard UI verifies the credential, then checks in with that credential.
        $verified = $this->assertSuccess($this->guardVerifyPin((string) $data['pin']));
        $credentialUuid = (string) $verified['data']['credential_uuid'];

        $this->assertSuccess($this->guardCheckin($uuid, $credentialUuid));

        $rows = $this->db->select(
            'SELECT ac.credential_type, ac.status AS status, ac.used_count, ac.max_uses FROM access_credentials ac
             INNER JOIN visitor_invitations vi ON vi.id = ac.invitation_id
             WHERE vi.uuid = :uuid ORDER BY credential_type ASC',
            ['uuid' => $uuid]
        );

        $this->assertCount(2, $rows);

        $byType = [];
        foreach ($rows as $row) {
            $byType[(string) $row['credential_type']] = $row;
        }

        $this->assertSame('used', (string) $byType['pin']['status'], 'The presented PIN must be consumed by the check-in.');
        $this->assertSame(1, (int) $byType['pin']['used_count']);
        $this->assertSame(1, (int) $byType['pin']['max_uses']);

        // The QR credential was not presented, so it remains single-use active
        // (a second visit would require a fresh invitation).
        $this->assertSame('active', (string) $byType['qr']['status']);
        $this->assertSame(0, (int) $byType['qr']['used_count']);
    }

    public function testPinAttemptRateLimitReturns429WithRetryAfter(): void
    {
        $this->loginGuard('Main Gate');

        // The per-credential window allows 5 attempts / 300s.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertErrorCode('INVALID_CREDENTIAL', $this->guardVerifyPin('000000'), 422);
        }

        $payload = $this->assertErrorCode('RATE_LIMITED', $this->guardVerifyPin('000000'), 429);
        $this->assertArrayHasKey('retry_after', $payload['error']);
        $this->assertTrue(is_numeric($payload['error']['retry_after']));
    }

    private function lastQrToken(): string
    {
        $credential = $this->db->selectOne(
            "SELECT uuid FROM access_credentials WHERE credential_type = 'qr' ORDER BY id DESC LIMIT 1"
        );

        if ($credential === null) {
            $this->fail('No QR credential found.');
        }

        return $this->qrTokenFor((string) $credential['uuid']);
    }
}
