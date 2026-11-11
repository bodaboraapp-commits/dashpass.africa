<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Clock;
use App\Core\Uuid;

/**
 * Insecure direct object references (Blueprint §60): a user must not reach
 * another estate by changing a URL segment or request parameter.
 */
final class IdorTest extends SecurityTestCase
{
    private const DAVID = '+254700000014'; // Riverside only
    private const JANE = '+254700000010';  // Greenview only

    public function testRandomEstateUuidCannotBeSwitchedTo(): void
    {
        $this->loginAs(self::DAVID);

        $response = $this->post('/api/v1/estates/' . Uuid::v4() . '/switch', [], true);

        $this->assertErrorCode('ESTATE_NOT_FOUND', $response, 404);
    }

    public function testNonUuidEstateIdentifierIsRejected(): void
    {
        $this->loginAs(self::DAVID);

        $response = $this->post('/api/v1/estates/1/switch', [], true);

        $this->assertErrorCode('ESTATE_NOT_FOUND', $response, 404);
    }

    public function testNumericEstateIdCannotBeUsedAsUuid(): void
    {
        $this->loginAs(self::JANE);
        $greenview = $this->seededEstate('greenview-residences');

        $response = $this->post('/api/v1/estates/' . $greenview['id'] . '/switch', [], true);

        $this->assertErrorCode('ESTATE_NOT_FOUND', $response, 404);
    }

    public function testQueryParametersCannotOverrideTheActiveEstate(): void
    {
        $this->loginAs(self::DAVID);
        $greenview = $this->seededEstate('greenview-residences');

        $query = '?estate_id=' . (int) $greenview['id']
            . '&estate_uuid=' . $greenview['uuid']
            . '&estate=' . $greenview['uuid'];

        $payload = $this->assertSuccess($this->get('/api/v1/estate' . $query));
        $this->assertSame('riverside-court', (string) $payload['data']['estate_slug']);

        $me = $this->assertSuccess($this->get('/api/v1/auth/me' . $query));
        $this->assertSame('riverside-court', (string) $me['data']['active_estate']['estate_slug']);

        $dashboard = $this->assertSuccess($this->get('/api/v1/resident/dashboard' . $query));
        $this->assertSame('riverside-court', (string) $dashboard['data']['estate']['estate_slug']);
    }

    public function testAuditLogEstateQueryParameterIsIgnored(): void
    {
        $this->loginAs(self::DAVID);

        $greenview = $this->seededEstate('greenview-residences');
        $riverside = $this->seededEstate('riverside-court');

        $foreignAuditUuid = Uuid::v4();
        $this->db->insert('audit_logs', [
            'uuid' => $foreignAuditUuid,
            'estate_id' => (int) $greenview['id'],
            'actor_id' => null,
            'action' => 'test.foreign_idor',
            'entity_type' => 'estate',
            'entity_id' => (int) $greenview['id'],
            'metadata' => null,
            'correlation_id' => Uuid::v4(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'seed',
            'created_at' => Clock::nowDb(),
        ]);

        $payload = $this->assertSuccess($this->get(
            '/api/v1/estate/audit-logs?estate_id=' . (int) $greenview['id'] . '&limit=200'
        ));

        $uuids = array_map(static fn (array $row): string => (string) $row['uuid'], $payload['data']['logs']);
        $this->assertNotContains($foreignAuditUuid, $uuids);

        foreach ($payload['data']['logs'] as $log) {
            $owner = $this->db->scalar(
                'SELECT estate_id FROM audit_logs WHERE uuid = :uuid',
                ['uuid' => (string) $log['uuid']]
            );
            $this->assertSame((int) $riverside['id'], (int) $owner);
        }
    }

    public function testResidentCannotReadAnotherEstatesSettings(): void
    {
        // David is Riverside estate_admin; the Greenview settings rows must not
        // leak into his response (settings are tenant-scoped).
        $this->loginAs(self::DAVID);

        $response = $this->get('/api/v1/estate/settings');
        $this->assertStatus(200, $response);

        $body = $response->body();
        $this->assertFalse(str_contains($body, 'visitor_validity_minutes'), 'Greenview settings must not leak to David.');
        $this->assertFalse(str_contains($body, 'walkin_approval_timeout_seconds'));
    }

    public function testUnauthenticatedDirectObjectAccessIsRejected(): void
    {
        $greenview = $this->seededEstate('greenview-residences');

        $this->assertErrorCode(
            'UNAUTHENTICATED',
            $this->get('/api/v1/estate?estate_id=' . (int) $greenview['id']),
            401
        );

        $this->assertErrorCode(
            'UNAUTHENTICATED',
            $this->post('/api/v1/estates/' . $greenview['uuid'] . '/switch', [], true),
            401
        );
    }
}
