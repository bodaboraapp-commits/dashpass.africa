<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Seeds\DemoVisitorSeeder;

/**
 * Walk-in request flow (visitor-access-module-contract §4.6, §6, §9):
 * guard creates → resident approves/rejects, cross-household and repeated
 * decisions are denied, and approval creates a real invitation + credentials.
 */
final class WalkInFlowTest extends VisitorModuleTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function createWalkIn(string $householdUnit = 'A-001', array $overrides = []): array
    {
        $this->loginGuard('Main Gate');

        $body = array_merge([
            'visitor_name' => 'Walk In ' . bin2hex(random_bytes(2)),
            'visitor_phone' => '+2547000902' . random_int(10, 99),
            'household_uuid' => $this->householdUuid($householdUnit),
            'purpose' => 'Walk-in test',
        ], $overrides);

        $payload = $this->assertSuccess($this->post('/api/v1/gate/walkins', $body, true));
        $this->assertArrayHasKey('uuid', $payload['data']);

        return $payload['data'];
    }

    public function testGuardCreatesPendingWalkInAndHouseholdIsNotified(): void
    {
        $walkIn = $this->createWalkIn('A-001', ['visitor_name' => 'Walk-in Mwangi']);

        $row = $this->db->selectOne(
            'SELECT status, visitor_name, gate_id, household_id, invitation_id FROM walk_in_requests WHERE uuid = :uuid',
            ['uuid' => (string) $walkIn['uuid']]
        );
        $this->assertNotNull($row);
        $this->assertSame('pending', (string) $row['status']);
        $this->assertSame('Walk-in Mwangi', (string) $row['visitor_name']);
        $this->assertNull($row['invitation_id'], 'A walk-in must never be auto-admitted.');
        $this->assertSame((int) $this->gate('Main Gate')['id'], (int) $row['gate_id']);

        $notification = $this->db->selectOne(
            "SELECT template, recipient, status FROM notification_logs WHERE template = 'walkin_request' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($notification, 'The household must receive a walkin_request SMS.');
        $this->assertSame('+254700000010', (string) $notification['recipient']);

        $audit = $this->db->selectOne("SELECT action FROM audit_logs WHERE action = 'visitor.walkin.created' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit);
    }

    public function testResidentApproveCreatesInvitationAndCredentials(): void
    {
        $walkIn = $this->createWalkIn('A-001', ['visitor_name' => 'Approved Walk-in']);
        $walkInUuid = (string) $walkIn['uuid'];

        $this->loginAs(self::JANE);

        // The pending request appears in Jane's pending approvals.
        $list = $this->assertSuccess($this->get('/api/v1/resident/invitations?tab=upcoming'));
        $uuids = array_map(static fn (array $item): string => (string) $item['uuid'], $list['data']['pending_walkins']);
        $this->assertContains($walkInUuid, $uuids);

        $payload = $this->assertSuccess($this->post('/api/v1/resident/walkins/' . $walkInUuid . '/approve', [], true));
        $this->assertSame('approved', (string) $payload['data']['status']);
        $this->assertArrayHasKey('invitation_uuid', $payload['data']);

        $row = $this->db->selectOne(
            'SELECT status, invitation_id, responded_by_user_id, responded_at FROM walk_in_requests WHERE uuid = :uuid',
            ['uuid' => $walkInUuid]
        );
        $this->assertSame('approved', (string) $row['status']);
        $this->assertNotNull($row['invitation_id']);
        $this->assertNotNull($row['responded_at']);

        $invitation = $this->db->selectOne(
            'SELECT status FROM visitor_invitations WHERE id = :id',
            ['id' => (int) $row['invitation_id']]
        );
        $this->assertNotNull($invitation);
        $this->assertSame('approved', (string) $invitation['status']);

        $credentials = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM access_credentials WHERE invitation_id = :id',
            ['id' => (int) $row['invitation_id']]
        );
        $this->assertSame(2, $credentials, 'Approval must issue PIN + QR credentials.');

        $sms = $this->db->selectOne(
            "SELECT template FROM notification_logs WHERE template = 'walkin_approved' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($sms, 'Approving a walk-in must SMS the visitor the PIN + pass.');

        $audit = $this->db->selectOne("SELECT action FROM audit_logs WHERE action = 'visitor.walkin.approved' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit);

        // Approving twice is a conflict.
        $this->assertErrorCode(
            'WALKIN_NOT_PENDING',
            $this->post('/api/v1/resident/walkins/' . $walkInUuid . '/approve', [], true),
            409
        );
    }

    public function testResidentRejectCreatesNoCredentials(): void
    {
        $walkIn = $this->createWalkIn('A-001', ['visitor_name' => 'Rejected Walk-in']);
        $walkInUuid = (string) $walkIn['uuid'];

        $this->loginAs(self::JANE);
        $payload = $this->assertSuccess($this->post('/api/v1/resident/walkins/' . $walkInUuid . '/reject', [], true));
        $this->assertSame('rejected', (string) $payload['data']['status']);

        $row = $this->db->selectOne('SELECT status, invitation_id FROM walk_in_requests WHERE uuid = :uuid', ['uuid' => $walkInUuid]);
        $this->assertSame('rejected', (string) $row['status']);
        $this->assertNull($row['invitation_id']);

        $audit = $this->db->selectOne("SELECT action FROM audit_logs WHERE action = 'visitor.walkin.rejected' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit);

        $this->assertErrorCode(
            'WALKIN_NOT_PENDING',
            $this->post('/api/v1/resident/walkins/' . $walkInUuid . '/approve', [], true),
            409
        );
        $this->assertErrorCode(
            'WALKIN_NOT_PENDING',
            $this->post('/api/v1/resident/walkins/' . $walkInUuid . '/reject', [], true),
            409
        );
    }

    public function testForeignHouseholdCannotApproveWalkIn(): void
    {
        $walkIn = $this->createWalkIn('A-001', ['visitor_name' => 'Not Your Walk-in']);
        $walkInUuid = (string) $walkIn['uuid'];

        // Amina lives in B-001, not the requested household.
        $this->loginAs(self::AMINA);

        $this->assertErrorCode(
            'WALKIN_NOT_FOUND',
            $this->post('/api/v1/resident/walkins/' . $walkInUuid . '/approve', [], true),
            404
        );

        $status = (string) $this->db->scalar('SELECT status FROM walk_in_requests WHERE uuid = :uuid', ['uuid' => $walkInUuid]);
        $this->assertSame('pending', $status, 'A denied approval must not change the walk-in state.');
    }

    public function testWalkInForForeignHouseholdReturns404(): void
    {
        $this->loginGuard('Main Gate');

        // A household UUID from the other estate is never selectable.
        $riversideHousehold = $this->db->scalar(
            "SELECT h.uuid FROM households h INNER JOIN estates e ON e.id = h.estate_id
             WHERE e.slug = 'riverside-court' LIMIT 1"
        );

        $this->assertNotFalse($riversideHousehold);

        $response = $this->post('/api/v1/gate/walkins', [
            'visitor_name' => 'Cross Tenant Walk-in',
            'visitor_phone' => '+254700090299',
            'household_uuid' => (string) $riversideHousehold,
        ], true);

        $this->assertErrorCode('HOUSEHOLD_NOT_FOUND', $response, 404);
    }

    public function testPendingWalkinAppearsInGuardApprovalList(): void
    {
        $this->loginGuard('Main Gate');

        $payload = $this->assertSuccess($this->get('/api/v1/gate/pending-approvals'));
        $this->assertArrayHasKey('pending_walkins', $payload['data']);

        $names = array_map(static fn (array $item): string => (string) $item['visitor_name'], $payload['data']['pending_walkins']);
        $this->assertContains('Demo Walk-in Four', $names, 'The seeded pending walk-in must appear for the gate.');
    }

    public function testOldPendingWalkInDoesNotAutoApprove(): void
    {
        $walkIn = $this->createWalkIn('A-001', ['visitor_name' => 'Old Walk-in']);
        $walkInUuid = (string) $walkIn['uuid'];

        // Age it beyond the approval timeout window.
        $this->db->execute(
            'UPDATE walk_in_requests SET created_at = :old WHERE uuid = :uuid',
            ['old' => \App\Core\Clock::toDb(\App\Core\Clock::now()->modify('-2 days')), 'uuid' => $walkInUuid]
        );

        $this->loginAs(self::JANE);
        $this->get('/api/v1/resident/invitations?tab=upcoming');

        $row = $this->db->selectOne('SELECT status, invitation_id FROM walk_in_requests WHERE uuid = :uuid', ['uuid' => $walkInUuid]);
        $this->assertSame('pending', (string) $row['status'], 'A stale walk-in must never become approved without a resident decision.');
        $this->assertNull($row['invitation_id']);
    }
}
