<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Clock;
use App\Core\Session;
use App\Core\Uuid;

/**
 * Cross-tenant isolation (Blueprint §13, §60; contract §11): an estate admin of
 * Riverside Court must never see, enumerate or be forced into Greenview data.
 */
final class TenantIsolationTest extends SecurityTestCase
{
    private const DAVID = '+254700000014'; // estate_admin @ Riverside only
    private const GRACE = '+254700000001'; // estate_admin @ both estates
    private const DANIEL = '+254700000020'; // platform super admin, no estate

    public function testDavidSeesOnlyHisOwnMembership(): void
    {
        $this->loginAs(self::DAVID);

        $payload = $this->assertSuccess($this->get('/api/v1/auth/me'));
        $data = $payload['data'];

        $this->assertCount(1, $data['memberships'], 'David must only receive his Riverside membership.');
        $this->assertSame('riverside-court', (string) $data['memberships'][0]['estate_slug']);
        $this->assertSame('estate_admin', (string) $data['memberships'][0]['role_name']);
        $this->assertSame('riverside-court', (string) $data['active_estate']['estate_slug']);

        $estates = $this->assertSuccess($this->get('/api/v1/estates'));
        $this->assertCount(1, $estates['data']['memberships']);
    }

    public function testDavidCannotSwitchToGreenview(): void
    {
        $this->loginAs(self::DAVID);
        $greenview = $this->seededEstate('greenview-residences');

        $response = $this->post('/api/v1/estates/' . $greenview['uuid'] . '/switch', [], true);

        $this->assertErrorCode('ESTATE_NOT_FOUND', $response, 404);

        // The active estate is unchanged after the rejected switch.
        $me = $this->assertSuccess($this->get('/api/v1/auth/me'));
        $this->assertSame('riverside-court', (string) $me['data']['active_estate']['estate_slug']);
    }

    public function testEstateSummaryIsScopedToTheTenant(): void
    {
        $this->loginAs(self::DAVID);

        $payload = $this->assertSuccess($this->get('/api/v1/estate'));
        $estate = $payload['data'];

        $this->assertSame('riverside-court', (string) $estate['estate_slug']);
        $this->assertSame('Riverside Court', (string) $estate['estate_name']);
        $this->assertSame(1, (int) $estate['gate_count'], 'David must only see Riverside gates.');
        $this->assertSame(2, (int) $estate['household_count'], 'David must only see Riverside households.');
    }

    public function testTamperedSessionEstateIdFallsBackToOwnMembership(): void
    {
        $this->loginAs(self::DAVID);
        $greenview = $this->seededEstate('greenview-residences');

        // Simulate a tampered/forged session value: the tenant resolver must not
        // trust it and must fall back to a membership David actually owns.
        Session::put('estate_id', (int) $greenview['id']);

        $payload = $this->assertSuccess($this->get('/api/v1/estate'));
        $this->assertSame('riverside-court', (string) $payload['data']['estate_slug']);

        $me = $this->assertSuccess($this->get('/api/v1/auth/me'));
        $this->assertSame('riverside-court', (string) $me['data']['active_estate']['estate_slug']);
    }

    public function testAuditFeedNeverLeaksTheOtherEstate(): void
    {
        $this->loginAs(self::DAVID);

        $greenview = $this->seededEstate('greenview-residences');
        $riverside = $this->seededEstate('riverside-court');

        $greenviewAuditUuid = Uuid::v4();
        $this->db->insert('audit_logs', [
            'uuid' => $greenviewAuditUuid,
            'estate_id' => (int) $greenview['id'],
            'actor_id' => null,
            'action' => 'test.greenview_only',
            'entity_type' => 'estate',
            'entity_id' => (int) $greenview['id'],
            'metadata' => null,
            'correlation_id' => Uuid::v4(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'seed',
            'created_at' => Clock::nowDb(),
        ]);

        $payload = $this->assertSuccess($this->get('/api/v1/estate/audit-logs'));
        $logs = $payload['data']['logs'];

        $uuids = array_map(static fn (array $row): string => (string) $row['uuid'], $logs);

        $this->assertNotContains($greenviewAuditUuid, $uuids, 'Greenview audit rows must never appear in David\'s feed.');

        foreach ($logs as $log) {
            $ownerEstate = $this->db->scalar(
                'SELECT estate_id FROM audit_logs WHERE uuid = :uuid',
                ['uuid' => (string) $log['uuid']]
            );

            $this->assertSame((int) $riverside['id'], (int) $ownerEstate);
        }
    }

    public function testGraceCanSwitchBetweenBothOfHerEstatesAndDataFollows(): void
    {
        $this->loginAs(self::GRACE);

        $greenview = $this->seededEstate('greenview-residences');
        $riverside = $this->seededEstate('riverside-court');

        $this->assertSuccess($this->post('/api/v1/estates/' . $riverside['uuid'] . '/switch', [], true));
        $riversideSummary = $this->assertSuccess($this->get('/api/v1/estate'));
        $this->assertSame('riverside-court', (string) $riversideSummary['data']['estate_slug']);
        $this->assertSame(1, (int) $riversideSummary['data']['gate_count']);
        $this->assertSame(2, (int) $riversideSummary['data']['household_count']);

        $this->assertSuccess($this->post('/api/v1/estates/' . $greenview['uuid'] . '/switch', [], true));
        $greenviewSummary = $this->assertSuccess($this->get('/api/v1/estate'));
        $this->assertSame('greenview-residences', (string) $greenviewSummary['data']['estate_slug']);
        $this->assertSame(2, (int) $greenviewSummary['data']['gate_count']);
        $this->assertSame(12, (int) $greenviewSummary['data']['household_count']);
    }

    public function testPlatformAdminWithoutEstateMembershipGetsNoTenantData(): void
    {
        $this->loginAs(self::DANIEL);

        $me = $this->assertSuccess($this->get('/api/v1/auth/me'));
        $this->assertSame([], $me['data']['memberships'], 'A platform-only user has no estate memberships.');
        $this->assertNull($me['data']['active_estate']);

        $estates = $this->assertSuccess($this->get('/api/v1/estates'));
        $this->assertSame([], $estates['data']['memberships']);

        $this->assertErrorCode('ESTATE_CONTEXT_REQUIRED', $this->get('/api/v1/estate'), 403);
    }
}
