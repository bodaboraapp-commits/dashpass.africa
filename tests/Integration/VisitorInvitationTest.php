<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Crypto;
use Database\Seeds\DemoVisitorSeeder;

/**
 * Resident invitation lifecycle (visitor-access-module-contract §4.1–§4.3, §6, §9).
 */
final class VisitorInvitationTest extends VisitorModuleTestCase
{
    public function testCreateInvitationReturnsPinPassAndValidityOnce(): void
    {
        $data = $this->createInvitation(['visitor_name' => 'Grace Njoroge', 'purpose' => 'Family lunch']);

        foreach (['invitation_uuid', 'pin', 'pass_url', 'qr_url', 'valid_from', 'valid_until', 'visitor'] as $key) {
            $this->assertArrayHasKey($key, $data, 'Create payload is missing ' . $key . '.');
        }

        $this->assertMatches('/^[0-9]{6}$/', (string) $data['pin'], 'The one-time PIN is six digits.');
        $this->assertSame('Grace Njoroge', (string) $data['visitor']['name']);
        $this->assertMatches('/^\+254[0-9]{9}$/', (string) $data['visitor']['phone']);
        $this->assertTrue(
            str_contains((string) $data['pass_url'], '/v/'),
            'Pass URL must contain the public /v/{token} path.'
        );

        $invitation = $this->db->selectOne(
            'SELECT id, estate_id, household_id, host_user_id, status, visitor_count
             FROM visitor_invitations WHERE uuid = :uuid',
            ['uuid' => (string) $data['invitation_uuid']]
        );
        $this->assertNotNull($invitation);
        $this->assertSame('approved', (string) $invitation['status']);
        $this->assertSame((int) $this->estate()['id'], (int) $invitation['estate_id']);

        $credentials = $this->db->select(
            'SELECT uuid, credential_type, credential_hash, encrypted_secret, status, max_uses, expires_at
             FROM access_credentials WHERE invitation_id = :id ORDER BY credential_type ASC',
            ['id' => (int) $invitation['id']]
        );
        $this->assertCount(2, $credentials, 'A PIN and a QR credential must be created.');

        $byType = [];
        foreach ($credentials as $row) {
            $byType[(string) $row['credential_type']] = $row;
        }

        $pinRow = $byType['pin'];
        $qrRow = $byType['qr'];

        $this->assertSame(Crypto::hmac('access-pin:' . (string) $pinRow['uuid'], (string) $data['pin']), (string) $pinRow['credential_hash']);
        $this->assertNull($pinRow['encrypted_secret'], 'PIN credentials must never be recoverable.');
        $this->assertNotNull($qrRow['encrypted_secret'], 'QR token must be encrypted for re-display.');
        $this->assertSame(1, (int) $pinRow['max_uses']);
        $this->assertSame(1, (int) $qrRow['max_uses']);
        $this->assertSame(
            strtotime((string) $data['valid_until']),
            strtotime((string) $pinRow['expires_at']),
            'Credential expiry must match the invitation valid_until window.'
        );
    }

    public function testInvitationIsAuditedAndSmsLoggedWithoutCredentialLeakage(): void
    {
        $data = $this->createInvitation(['visitor_name' => 'Audit Visitor']);
        $pin = (string) $data['pin'];
        $invitationUuid = (string) $data['invitation_uuid'];

        $audit = $this->db->selectOne(
            "SELECT id, action, estate_id, metadata FROM audit_logs
             WHERE action = 'visitor.invitation.created' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit, 'Creating an invitation must write the visitor.invitation.created audit row.');
        $this->assertSame((int) $this->estate()['id'], (int) $audit['estate_id']);
        $this->assertFalse(
            str_contains((string) ($audit['metadata'] ?? ''), $pin),
            'The audit metadata must never contain the plaintext PIN.'
        );

        $notification = $this->db->selectOne(
            "SELECT channel, template, status FROM notification_logs
             WHERE template = 'visitor_invitation' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($notification, 'The invitation SMS must be recorded in notification_logs.');
        $this->assertSame('sms', (string) $notification['channel']);
        $this->assertContains((string) $notification['status'], ['sent', 'queued', 'delivered']);

        $body = (string) json_encode($notification);
        $this->assertFalse(str_contains($body, $pin), 'notification_logs must not contain the plaintext PIN.');

        // The audit row is stamped with the invitation subject, not another tenant.
        $stored = $this->db->selectOne(
            'SELECT estate_id FROM visitor_invitations WHERE uuid = :uuid',
            ['uuid' => $invitationUuid]
        );
        $this->assertSame((int) $stored['estate_id'], (int) $audit['estate_id']);
    }

    public function testInvalidValidityWindowIsRejected(): void
    {
        $this->loginAs(self::JANE);

        $response = $this->post('/api/v1/resident/invitations', [
            'visitor_name' => 'Bad Window',
            'visitor_phone' => '+254700090199',
            'household_uuid' => $this->householdUuid('A-001'),
            'valid_from' => Clock::toDb(Clock::now()->modify('+3 hours')),
            'valid_until' => Clock::toDb(Clock::now()->modify('+2 hours')),
        ], true);

        $this->assertErrorCode('VALIDITY_INVALID', $response, 422);
    }

    public function testValidityInThePastIsRejected(): void
    {
        $this->loginAs(self::JANE);

        $response = $this->post('/api/v1/resident/invitations', [
            'visitor_name' => 'Past Visitor',
            'visitor_phone' => '+254700090198',
            'household_uuid' => $this->householdUuid('A-001'),
            'valid_from' => Clock::toDb(Clock::now()->modify('-3 hours')),
            'valid_until' => Clock::toDb(Clock::now()->modify('-2 hours')),
        ], true);

        $this->assertErrorCode('VALIDITY_INVALID', $response, 422);
    }

    public function testValidationErrorsForNameAndPhone(): void
    {
        $this->loginAs(self::JANE);

        $response = $this->post('/api/v1/resident/invitations', [
            'visitor_name' => 'x',
            'visitor_phone' => 'not-a-phone',
            'household_uuid' => $this->householdUuid('A-001'),
        ], true);

        $payload = $this->assertErrorCode('VALIDATION_FAILED', $response, 422);
        $this->assertArrayHasKey('visitor_name', $payload['error']['errors']);
        $this->assertArrayHasKey('visitor_phone', $payload['error']['errors']);
    }

    public function testForeignHouseholdReturns404WithoutDisclosure(): void
    {
        $this->loginAs(self::JANE);

        $response = $this->post('/api/v1/resident/invitations', [
            'visitor_name' => 'Foreign Household',
            'visitor_phone' => '+254700090197',
            'household_uuid' => $this->householdUuid('B-001'),
            'valid_from' => Clock::toDb(Clock::now()->modify('+5 minutes')),
            'valid_until' => Clock::toDb(Clock::now()->modify('+1 hour')),
        ], true);

        $this->assertErrorCode('HOUSEHOLD_NOT_FOUND', $response, 404);
    }

    public function testUnknownHouseholdUuidReturns404(): void
    {
        $this->loginAs(self::JANE);

        $response = $this->post('/api/v1/resident/invitations', [
            'visitor_name' => 'Unknown Household',
            'visitor_phone' => '+254700090196',
            'household_uuid' => $this->newUuid(),
        ], true);

        $this->assertErrorCode('HOUSEHOLD_NOT_FOUND', $response, 404);
    }

    public function testInviteCreationRateLimit(): void
    {
        $this->loginAs(self::JANE);
        $body = [
            'visitor_name' => 'Rate Visitor',
            'visitor_phone' => '+254700090195',
            'household_uuid' => $this->householdUuid('A-001'),
            'valid_from' => Clock::toDb(Clock::now()->modify('+5 minutes')),
            'valid_until' => Clock::toDb(Clock::now()->modify('+1 hour')),
        ];

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->assertSuccess(
                $this->post('/api/v1/resident/invitations', $body, true),
                'Invitation attempt ' . $attempt . ' should be within the creation budget.'
            );
        }

        $payload = $this->assertErrorCode(
            'RATE_LIMITED',
            $this->post('/api/v1/resident/invitations', $body, true),
            429
        );
        $this->assertArrayHasKey('retry_after', $payload['error']);
    }

    public function testUpcomingAndHistoryListsAreScopedToUserHouseholds(): void
    {
        $this->loginAs(self::JANE);

        $upcoming = $this->assertSuccess($this->get('/api/v1/resident/invitations?tab=upcoming'));
        $this->assertSame('upcoming', (string) $upcoming['data']['tab']);
        $this->assertArrayHasKey('upcoming', $upcoming['data']);
        $this->assertArrayHasKey('history', $upcoming['data']);
        $this->assertArrayHasKey('pending_walkins', $upcoming['data']);

        $upcomingNames = array_map(static fn (array $item): string => (string) $item['visitor_name'], $upcoming['data']['upcoming']);
        $this->assertContains('Demo Visitor Three', $upcomingNames, 'Jane must see her seeded upcoming invitation.');

        $history = $this->assertSuccess($this->get('/api/v1/resident/invitations?tab=history'));
        $historyNames = array_map(static fn (array $item): string => (string) $item['visitor_name'], $history['data']['history']);
        $this->assertContains('Demo Visitor One', $historyNames);
        $this->assertContains('Demo Visitor Two', $historyNames);

        // A resident of another household never sees Jane's invitations.
        $this->loginAs(self::AMINA);
        $otherUpcoming = $this->assertSuccess($this->get('/api/v1/resident/invitations?tab=upcoming'));
        $otherHistory = $this->assertSuccess($this->get('/api/v1/resident/invitations?tab=history'));
        $allNames = array_merge(
            array_map(static fn (array $item): string => (string) $item['visitor_name'], $otherUpcoming['data']['upcoming']),
            array_map(static fn (array $item): string => (string) $item['visitor_name'], $otherHistory['data']['history'])
        );

        $this->assertNotContains('Demo Visitor Three', $allNames);
        $this->assertNotContains('Demo Visitor One', $allNames);
    }

    public function testItemPayloadShape(): void
    {
        $this->loginAs(self::JANE);
        $payload = $this->assertSuccess($this->get('/api/v1/resident/invitations?tab=upcoming'));
        $this->assertNotEmpty($payload['data']['upcoming']);

        $item = $payload['data']['upcoming'][0];

        foreach (['uuid', 'visitor_name', 'purpose', 'valid_from', 'valid_until', 'status', 'status_label', 'status_tone', 'household_label', 'already_inside'] as $key) {
            $this->assertArrayHasKey($key, $item, 'Invitation item payload is missing ' . $key . '.');
        }

        $this->assertContains((string) $item['status_tone'], ['ok', 'warn', 'danger', 'neutral']);
        $this->assertTrue(is_bool($item['already_inside']));

        $pending = $payload['data']['pending_walkins'];
        $this->assertTrue(is_array($pending));

        if ($pending !== []) {
            foreach (['uuid', 'visitor_name', 'purpose', 'requested_at', 'gate_name', 'household_label'] as $key) {
                $this->assertArrayHasKey($key, $pending[0], 'WalkIn payload is missing ' . $key . '.');
            }
        }
    }

    public function testRevokeTransitionsAndRevokesCredentials(): void
    {
        $data = $this->createInvitation();
        $uuid = (string) $data['invitation_uuid'];

        $payload = $this->assertSuccess($this->post('/api/v1/resident/invitations/' . $uuid . '/revoke', [], true));
        $this->assertSame('revoked', (string) $payload['data']['status']);

        $status = (string) $this->db->scalar('SELECT status FROM visitor_invitations WHERE uuid = :uuid', ['uuid' => $uuid]);
        $this->assertSame('revoked', $status);

        $active = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM access_credentials ac INNER JOIN visitor_invitations vi ON vi.id = ac.invitation_id
             WHERE vi.uuid = :uuid AND ac.status = 'active'",
            ['uuid' => $uuid]
        );
        $this->assertSame(0, $active, 'Revoking an invitation must revoke its credentials.');

        $this->assertErrorCode(
            'INVITATION_NOT_ACTIVE',
            $this->post('/api/v1/resident/invitations/' . $uuid . '/revoke', [], true),
            409
        );
    }

    public function testRevokingACheckedInVisitKeepsItManageableButBlocksReentry(): void
    {
        $data = $this->createInvitation();
        $uuid = (string) $data['invitation_uuid'];

        $this->loginGuard('Main Gate');
        $this->assertSuccess($this->guardCheckin($uuid));

        $this->reloginAs(self::JANE);
        $this->assertSuccess($this->post('/api/v1/resident/invitations/' . $uuid . '/revoke', [], true));

        $status = (string) $this->db->scalar('SELECT status FROM visitor_invitations WHERE uuid = :uuid', ['uuid' => $uuid]);
        $this->assertSame('checked_in', $status, 'A checked-in visit stays checked_in after revocation.');

        $active = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM access_credentials ac INNER JOIN visitor_invitations vi ON vi.id = ac.invitation_id
             WHERE vi.uuid = :uuid AND ac.status = 'active'",
            ['uuid' => $uuid]
        );
        $this->assertSame(0, $active, 'Credentials must be revoked so re-entry is impossible.');
    }

    public function testExtendInvitationValidity(): void
    {
        $data = $this->createInvitation();
        $uuid = (string) $data['invitation_uuid'];
        $before = (string) $this->db->scalar('SELECT valid_until FROM visitor_invitations WHERE uuid = :uuid', ['uuid' => $uuid]);

        $payload = $this->assertSuccess($this->post('/api/v1/resident/invitations/' . $uuid . '/extend', ['minutes' => 60], true));
        $this->assertArrayHasKey('valid_until', $payload['data']);

        $after = (string) $this->db->scalar('SELECT valid_until FROM visitor_invitations WHERE uuid = :uuid', ['uuid' => $uuid]);
        $this->assertTrue(
            strtotime($after) > strtotime($before),
            'Extending must push valid_until later (' . $before . ' -> ' . $after . ').'
        );

        $mismatched = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM access_credentials ac INNER JOIN visitor_invitations vi ON vi.id = ac.invitation_id
             WHERE vi.uuid = :uuid AND ac.expires_at <> vi.valid_until",
            ['uuid' => $uuid]
        );
        $this->assertSame(0, $mismatched, 'Credential expiry must follow the extended invitation window.');

        $this->assertErrorCode(
            'VALIDATION_FAILED',
            $this->post('/api/v1/resident/invitations/' . $uuid . '/extend', ['minutes' => 5], true),
            422
        );
    }

    public function testRotatePinRevealsANewPinAndRevokesTheOldCredential(): void
    {
        $data = $this->createInvitation();
        $uuid = (string) $data['invitation_uuid'];
        $oldPin = (string) $data['pin'];

        $payload = $this->assertSuccess($this->post('/api/v1/resident/invitations/' . $uuid . '/rotate-pin', [], true));
        $newPin = (string) $payload['data']['pin'];

        $this->assertMatches('/^[0-9]{6}$/', $newPin);
        $this->assertNotSame($oldPin, $newPin);

        $pins = $this->db->select(
            "SELECT ac.uuid, ac.credential_hash, ac.status FROM access_credentials ac
             INNER JOIN visitor_invitations vi ON vi.id = ac.invitation_id
             WHERE vi.uuid = :uuid AND ac.credential_type = 'pin' ORDER BY ac.id ASC",
            ['uuid' => $uuid]
        );
        $this->assertCount(2, $pins, 'Rotation revokes the old PIN and issues a new one.');
        $this->assertSame('revoked', (string) $pins[0]['status']);
        $this->assertSame('active', (string) $pins[1]['status']);

        $newHash = Crypto::hmac('access-pin:' . (string) $pins[1]['uuid'], $newPin);
        $this->assertSame($newHash, (string) $pins[1]['credential_hash']);
    }

    public function testRotatePinOnlyAllowedWhileApproved(): void
    {
        $data = $this->createInvitation();
        $uuid = (string) $data['invitation_uuid'];

        $this->post('/api/v1/resident/invitations/' . $uuid . '/revoke', [], true);

        $this->assertErrorCode(
            'INVITATION_NOT_ACTIVE',
            $this->post('/api/v1/resident/invitations/' . $uuid . '/rotate-pin', [], true),
            409
        );
    }

    public function testDirectAccessToForeignInvitationReturns404(): void
    {
        $this->loginAs(self::AMINA);

        $seeded = DemoVisitorSeeder::INVITATION_UPCOMING_UUID;

        $responses = [
            $this->get('/api/v1/resident/invitations/' . $seeded),
            $this->post('/api/v1/resident/invitations/' . $seeded . '/revoke', [], true),
            $this->post('/api/v1/resident/invitations/' . $seeded . '/extend', ['minutes' => 30], true),
        ];

        foreach ($responses as $response) {
            $this->assertErrorCode('INVITATION_NOT_FOUND', $response, 404);
        }
    }
}
