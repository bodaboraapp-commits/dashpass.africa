<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Uuid;

/**
 * Authentication and authorization enforcement for every route class:
 * web redirects vs API 401, estate switching, role-based permission denials and
 * the no-estate fallback (contract §8, §8.1).
 */
final class AuthorizationTest extends IntegrationTestCase
{
    private const JANE = '+254700000010';       // resident @ Greenview
    private const GRACE = '+254700000001';      // estate_admin @ Greenview + Riverside
    private const GUARD = '+254700000004';      // security_guard @ Greenview
    private const AUDITOR = '+254700000009';    // auditor @ Greenview

    public function testUnauthenticatedApiRequestsReturn401(): void
    {
        $paths = [
            '/api/v1/auth/me',
            '/api/v1/estates',
            '/api/v1/estate',
            '/api/v1/estate/settings',
            '/api/v1/estate/audit-logs',
            '/api/v1/resident/dashboard',
        ];

        foreach ($paths as $path) {
            $response = $this->get($path);
            $this->assertErrorCode('UNAUTHENTICATED', $response, 401);
        }
    }

    public function testUnauthenticatedWebAppRedirectsToLogin(): void
    {
        $paths = [
            '/app',
            '/app/account',
            '/app/estates',
            '/app/visitors',
            '/app/bookings',
        ];

        foreach ($paths as $path) {
            $response = $this->get($path);

            $this->assertRedirect($response, '/login');
            $this->assertSame(302, $response->status());
        }
    }

    public function testUnauthenticatedSwitchReturns401(): void
    {
        $greenview = $this->seededEstate('greenview-residences');

        $response = $this->post(
            '/api/v1/estates/' . $greenview['uuid'] . '/switch',
            [],
            true
        );

        $this->assertErrorCode('UNAUTHENTICATED', $response, 401);
    }

    public function testGuestCannotSeeAppAndAuthUserCannotSeeLogin(): void
    {
        $welcome = $this->get('/');
        $this->assertStatus(200, $welcome, 'GET / must render the guest welcome page.');

        $login = $this->get('/login');
        $this->assertStatus(200, $login);

        $this->loginAs(self::JANE);

        $this->assertRedirect($this->get('/login'), '/app');
        $this->assertRedirect($this->get('/'), '/app');
    }

    public function testResidentIsForbiddenFromAuditLogs(): void
    {
        $this->loginAs(self::JANE);

        $response = $this->get('/api/v1/estate/audit-logs');

        $this->assertErrorCode('PERMISSION_DENIED', $response, 403);
    }

    public function testGuardIsForbiddenFromEstateSettings(): void
    {
        $this->loginAs(self::GUARD);

        $response = $this->get('/api/v1/estate/settings');

        $this->assertErrorCode('PERMISSION_DENIED', $response, 403);
    }

    public function testEstateAdminCanReadSettings(): void
    {
        $this->loginAs(self::GRACE);
        $this->switchTo(self::GRACE, 'greenview-residences');

        $payload = $this->assertSuccess($this->get('/api/v1/estate/settings'));
        $settings = $payload['data']['settings'] ?? null;

        $this->assertTrue(is_array($settings), 'data.settings must be an object.');
        $this->assertSame(240, (int) ($settings['visitor_validity_minutes'] ?? -1));
        $this->assertSame(30, (int) ($settings['visitor_parking_grace_minutes'] ?? -1));
        $this->assertSame(300, (int) ($settings['walkin_approval_timeout_seconds'] ?? -1));
        $this->assertSame(5, (int) ($settings['max_visitors_per_invitation'] ?? -1));
    }

    public function testEstateAdminCanReadAuditLogs(): void
    {
        $this->loginAs(self::GRACE);

        $payload = $this->assertSuccess($this->get('/api/v1/estate/audit-logs'));
        $data = $payload['data'];

        $this->assertArrayHasKey('logs', $data);
        $this->assertTrue(is_array($data['logs']));
        $this->assertSame(50, (int) ($data['limit'] ?? -1), 'The default audit limit is 50.');

        if ($data['logs'] !== []) {
            $allowedKeys = ['uuid', 'action', 'entity_type', 'entity_id', 'metadata', 'correlation_id', 'ip_address', 'created_at', 'actor_name'];
            foreach ($allowedKeys as $key) {
                $this->assertArrayHasKey($key, $data['logs'][0], 'Audit log rows are missing ' . $key);
            }
        }
    }

    public function testAuditLogLimitIsClamped(): void
    {
        $this->loginAs(self::GRACE);

        $large = $this->assertSuccess($this->get('/api/v1/estate/audit-logs?limit=1000'));
        $this->assertSame(200, (int) ($large['data']['limit'] ?? -1), 'limit must clamp to 200.');

        $zero = $this->assertSuccess($this->get('/api/v1/estate/audit-logs?limit=0'));
        $this->assertSame(1, (int) ($zero['data']['limit'] ?? -1), 'limit must clamp to 1.');

        $small = $this->assertSuccess($this->get('/api/v1/estate/audit-logs?limit=5'));
        $this->assertSame(5, (int) ($small['data']['limit'] ?? -1));
    }

    public function testEstateSwitching(): void
    {
        $this->loginAs(self::GRACE);

        $greenview = $this->seededEstate('greenview-residences');
        $riverside = $this->seededEstate('riverside-court');

        $estates = $this->assertSuccess($this->get('/api/v1/estates'));
        $this->assertCount(2, $estates['data']['memberships']);

        $switch = $this->post('/api/v1/estates/' . $riverside['uuid'] . '/switch', [], true);
        $payload = $this->assertSuccess($switch, 'Grace may switch to Riverside Court.');
        $data = $payload['data'];

        $this->assertSame('riverside-court', (string) ($data['estate']['estate_slug'] ?? ''));
        $this->assertSame('Riverside Court', (string) ($data['estate']['estate_name'] ?? ''));
        $this->assertSame('estate_admin', (string) ($data['estate']['role_name'] ?? ''));
        $this->assertTrue(is_array($data['permissions'] ?? null), 'Switch payload must include permissions.');
        $this->assertMatches('/^[0-9a-f]{64}$/', (string) ($data['csrf_token'] ?? ''));

        $me = $this->assertSuccess($this->get('/api/v1/auth/me'));
        $this->assertSame('riverside-court', (string) $me['data']['active_estate']['estate_slug']);

        $back = $this->assertSuccess($this->post('/api/v1/estates/' . $greenview['uuid'] . '/switch', [], true));
        $this->assertSame('greenview-residences', (string) $back['data']['estate']['estate_slug']);
    }

    public function testSwitchToUnknownEstateIs404(): void
    {
        $this->loginAs(self::JANE);

        $response = $this->post('/api/v1/estates/' . Uuid::v4() . '/switch', [], true);
        $this->assertErrorCode('ESTATE_NOT_FOUND', $response, 404);

        $invalid = $this->post('/api/v1/estates/not-a-uuid/switch', [], true);
        $this->assertErrorCode('ESTATE_NOT_FOUND', $invalid, 404);
    }

    public function testAuditorCanViewAuditLogsButNotManageSettings(): void
    {
        $this->loginAs(self::AUDITOR);

        $this->assertSuccess($this->get('/api/v1/estate/audit-logs'));
        $this->assertErrorCode('PERMISSION_DENIED', $this->get('/api/v1/estate/settings'), 403);
    }

    public function testResidentDashboardShape(): void
    {
        $this->loginAs(self::JANE);
        $this->switchTo(self::JANE, 'greenview-residences');

        $payload = $this->assertSuccess($this->get('/api/v1/resident/dashboard'));
        $data = $payload['data'];

        foreach (['estate', 'memberships', 'households', 'gates', 'counts', 'modules', 'announcements'] as $key) {
            $this->assertArrayHasKey($key, $data, 'Dashboard payload is missing ' . $key);
        }

        $estate = $data['estate'];
        foreach (['estate_uuid', 'estate_name', 'estate_slug', 'estate_type', 'estate_timezone', 'role_name', 'gate_count', 'household_count', 'member_count', 'plan_name', 'subscription_status'] as $key) {
            $this->assertArrayHasKey($key, $estate, 'Estate summary is missing ' . $key);
        }

        $this->assertSame('greenview-residences', (string) $estate['estate_slug']);
        $this->assertSame(2, (int) $estate['gate_count']);
        $this->assertSame(12, (int) $estate['household_count']);
        $this->assertSame('Growth', (string) $estate['plan_name']);
        $this->assertSame('active', (string) $estate['subscription_status']);

        $this->assertSame(['households', 'buildings', 'gates', 'members'], array_keys($data['counts']));
        $this->assertSame(12, (int) $data['counts']['households']);
        $this->assertSame(4, (int) $data['counts']['buildings']);
        $this->assertSame(2, (int) $data['counts']['gates']);

        $this->assertSame(
            ['visitors' => 'not_installed', 'parking' => 'not_installed', 'notifications' => 'not_installed'],
            $data['modules']
        );
        $this->assertSame([], $data['announcements']);

        $this->assertCount(2, $data['gates']);
        $this->assertSame('Main Gate', (string) $data['gates'][0]['name']);
        $this->assertSame('active', (string) $data['gates'][0]['status']);

        $this->assertTrue(is_array($data['households']));
        if ($data['households'] !== []) {
            foreach (['household_uuid', 'unit_number', 'household_type', 'building_name', 'relationship', 'is_primary'] as $key) {
                $this->assertArrayHasKey($key, $data['households'][0], 'Household payload is missing ' . $key);
            }
        }
    }

    public function testApiEstateSummaryShape(): void
    {
        $this->loginAs(self::GRACE);
        $this->switchTo(self::GRACE, 'greenview-residences');

        $payload = $this->assertSuccess($this->get('/api/v1/estate'));
        $estate = $payload['data'];

        foreach (['estate_uuid', 'estate_name', 'estate_slug', 'estate_type', 'estate_timezone', 'role_name', 'gate_count', 'household_count', 'member_count', 'plan_name', 'subscription_status'] as $key) {
            $this->assertArrayHasKey($key, $estate, 'Estate summary is missing ' . $key);
        }

        $this->assertSame('greenview-residences', (string) $estate['estate_slug']);
        $this->assertSame('mixed', (string) $estate['estate_type']);
        $this->assertSame('Africa/Nairobi', (string) $estate['estate_timezone']);
        $this->assertSame('estate_admin', (string) $estate['role_name']);
    }

    public function testNoEstateUserIsHandledGracefully(): void
    {
        // A valid, active account with no estate membership: created directly in
        // the test database so the suite does not depend on a seeded edge case.
        $this->db->insert('users', [
            'uuid' => Uuid::v4(),
            'full_name' => 'No Estate User',
            'phone' => '+254799000001',
            'email' => null,
            'password_hash' => null,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => \App\Core\Clock::nowDb(),
            'updated_at' => \App\Core\Clock::nowDb(),
        ]);

        $this->loginAs('+254799000001');

        $me = $this->assertSuccess($this->get('/api/v1/auth/me'));
        $this->assertSame([], $me['data']['memberships']);
        $this->assertNull($me['data']['active_estate']);
        $this->assertSame([], $me['data']['permissions']);

        $this->assertErrorCode('ESTATE_CONTEXT_REQUIRED', $this->get('/api/v1/estate'), 403);

        $this->assertRedirect($this->get('/app'), '/app/no-estate');
        $this->assertStatus(200, $this->get('/app/no-estate'));
    }
}
