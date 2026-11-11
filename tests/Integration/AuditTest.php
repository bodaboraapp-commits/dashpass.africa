<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Audit trail (Blueprint §45, contract §11): login, logout and estate switch are
 * audited, rows carry request context, and estate-scoped reads never cross
 * tenants.
 */
final class AuditTest extends IntegrationTestCase
{
    private const JANE = '+254700000010';
    private const GRACE = '+254700000001';

    /**
     * @return array<string, mixed>|null
     */
    private function latestAuditFor(string $action, string $actorPhone): ?array
    {
        $user = $this->seededUser($actorPhone);

        return $this->db->selectOne(
            'SELECT id, uuid, estate_id, actor_id, action, entity_type, entity_id, metadata,
                    correlation_id, ip_address, user_agent, created_at
             FROM audit_logs
             WHERE action = :action AND actor_id = :actor_id
             ORDER BY id DESC LIMIT 1',
            ['action' => $action, 'actor_id' => (int) $user['id']]
        );
    }

    public function testLoginIsAudited(): void
    {
        $this->loginAs(self::JANE);

        $row = $this->latestAuditFor('auth.login', self::JANE);

        $this->assertNotNull($row, 'A successful login must write an auth.login audit row.');
        $this->assertSame('user', (string) $row['entity_type']);
        $this->assertSame((int) $this->seededUser(self::JANE)['id'], (int) $row['entity_id']);
        $this->assertMatches('/^[0-9a-f-]{36}$/', (string) $row['uuid']);
        $this->assertMatches('/^[0-9a-f-]{36}$/', (string) $row['correlation_id']);
        $this->assertSame('127.0.0.1', (string) $row['ip_address']);
        $this->assertTrue((string) $row['user_agent'] !== '');
        $this->assertMatches('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', (string) $row['created_at']);
    }

    public function testLogoutIsAudited(): void
    {
        $this->loginAs(self::JANE);
        $this->logout();

        $row = $this->latestAuditFor('auth.logout', self::JANE);

        $this->assertNotNull($row, 'Logout must write an auth.logout audit row.');
        $this->assertSame('user', (string) $row['entity_type']);
    }

    public function testEstateSwitchIsAuditedAndScopedToTheNewEstate(): void
    {
        $this->loginAs(self::GRACE);

        $riverside = $this->seededEstate('riverside-court');
        $this->assertSuccess($this->post('/api/v1/estates/' . $riverside['uuid'] . '/switch', [], true));

        $row = $this->latestAuditFor('estate.context_switched', self::GRACE);

        $this->assertNotNull($row, 'Switching estate must write an estate.context_switched audit row.');
        $this->assertSame((int) $riverside['id'], (int) $row['estate_id'], 'The switch audit row must be scoped to the new estate.');
    }

    public function testLoginAuditIsScopedToActiveEstate(): void
    {
        $this->loginAs(self::JANE);

        $greenview = $this->seededEstate('greenview-residences');
        $row = $this->latestAuditFor('auth.login', self::JANE);

        $this->assertNotNull($row);
        $this->assertSame((int) $greenview['id'], (int) $row['estate_id']);
    }

    public function testAuditLogEndpointOnlyReturnsTheActiveEstate(): void
    {
        $this->loginAs(self::GRACE);
        $this->switchTo(self::GRACE, 'riverside-court');

        $greenview = $this->seededEstate('greenview-residences');
        $riverside = $this->seededEstate('riverside-court');

        $payload = $this->assertSuccess($this->get('/api/v1/estate/audit-logs'));
        $logs = $payload['data']['logs'];

        $this->assertTrue(is_array($logs));

        $greenviewUuids = array_map(
            static fn (array $row): string => (string) $row['uuid'],
            $this->db->select(
                'SELECT uuid FROM audit_logs WHERE estate_id = :estate_id',
                ['estate_id' => (int) $greenview['id']]
            )
        );

        foreach ($logs as $log) {
            $this->assertNotContains(
                (string) $log['uuid'],
                $greenviewUuids,
                'The Riverside audit feed must never include Greenview rows.'
            );

            $ownerEstateId = $this->db->scalar(
                'SELECT estate_id FROM audit_logs WHERE uuid = :uuid',
                ['uuid' => (string) $log['uuid']]
            );
            $this->assertSame((int) $riverside['id'], (int) $ownerEstateId);
        }
    }

    public function testAuditMetadataRedactsCredentialShapedValues(): void
    {
        $jane = $this->seededUser(self::JANE);

        $context = new \App\Core\Context(
            \App\Core\Request::create('POST', '/api/v1/auth/verify-otp'),
            \App\Core\Uuid::v4()
        );

        $context->audit->record('test.credential', 'user', (int) $jane['id'], [
            'otp' => '123456',
            'pin' => '1234',
            'password' => 'secret',
            'token' => 'abcdef',
            'reason' => 'testing',
        ]);

        $row = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action = 'test.credential' ORDER BY id DESC LIMIT 1"
        );

        $this->assertNotNull($row);
        $metadata = json_decode((string) $row['metadata'], true);

        foreach (['otp', 'pin', 'password', 'token'] as $key) {
            $this->assertSame('[redacted]', $metadata[$key], 'Audit metadata must redact ' . $key . '.');
        }

        $this->assertSame('testing', $metadata['reason']);
    }
}
