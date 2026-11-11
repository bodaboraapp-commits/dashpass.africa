<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Crypto;
use Database\Seeds\DemoVisitorSeeder;
use Tests\Integration\VisitorModuleTestCase;

/**
 * Security invariants for the Visitor & Access module
 * (visitor-access-module-contract §9): no credential plaintext anywhere, no
 * token in audit/notification logs, tenant isolation, XSS, CSRF and QR endpoint.
 */
final class VisitorSecurityTest extends VisitorModuleTestCase
{
    public function testPlaintextPinIsNeverStoredInAnyDatabaseColumn(): void
    {
        $data = $this->createInvitation(['visitor_name' => 'Plaintext Scan Visitor']);
        $pin = (string) $data['pin'];

        $hits = $this->scanDatabaseFor($pin);

        $this->assertCount(0, $hits, 'The plaintext PIN must never be stored in any column: ' . implode(', ', $hits));
    }

    public function testQrTokenAndPassSecretNeverReachAuditOrNotificationLogs(): void
    {
        $data = $this->createInvitation(['visitor_name' => 'Token Log Visitor']);
        $uuid = (string) $data['invitation_uuid'];

        $credential = $this->db->selectOne(
            "SELECT ac.uuid, ac.encrypted_secret FROM access_credentials ac
             INNER JOIN visitor_invitations vi ON vi.id = ac.invitation_id
             WHERE vi.uuid = :uuid AND ac.credential_type = 'qr' ORDER BY ac.id ASC LIMIT 1",
            ['uuid' => $uuid]
        );
        $this->assertNotNull($credential);

        $token = Crypto::decrypt((string) $credential['encrypted_secret'], 'access-qr:' . (string) $credential['uuid']);
        $this->assertNotNull($token);

        $audit = $this->db->select('SELECT action, metadata FROM audit_logs');
        $notifications = $this->db->select('SELECT template, recipient, error FROM notification_logs');

        foreach ($audit as $row) {
            $this->assertFalse(
                str_contains((string) json_encode($row), (string) $token),
                'The QR/pass token must never appear in audit_logs.'
            );
            $this->assertFalse(
                str_contains((string) json_encode($row), (string) $data['pin']),
                'The PIN must never appear in audit_logs.'
            );
        }

        foreach ($notifications as $row) {
            $this->assertFalse(
                str_contains((string) json_encode($row), (string) $token),
                'The QR/pass token must never appear in notification_logs.'
            );
        }
    }

    public function testCrossTenantInvitationAccessIsDenied(): void
    {
        // Riverside-only admin must never reach a Greenview invitation resource.
        $this->loginAs(self::DAVID_RIVERSIDE);

        $response = $this->get('/api/v1/resident/invitations/' . DemoVisitorSeeder::INVITATION_UPCOMING_UUID);

        $this->assertContains($response->status(), [403, 404], 'A foreign-tenant admin must not read the invitation.');
        $this->assertFalse(
            str_contains($response->body(), 'Demo Visitor Three'),
            'A denied cross-tenant read must not leak the visitor name.'
        );
    }

    public function testPublicPassPageRevealsNoResidentPii(): void
    {
        $token = $this->qrTokenFor(DemoVisitorSeeder::CREDENTIAL_UPCOMING_QR_UUID);
        $body = $this->get('/v/' . $token)->body();

        foreach (['+254700000010', 'Jane Smith', 'host_user_id', 'user_id', 'resident'] as $forbidden) {
            $this->assertFalse(
                stripos($body, $forbidden) !== false,
                'The public pass page must not expose "' . $forbidden . '".'
            );
        }
    }

    public function testVisitorStringsAreEscapedOnResidentAndPublicPages(): void
    {
        $payload = '<script>alert("xss")</script>';
        $data = $this->createInvitation(['visitor_name' => $payload, 'purpose' => '<img src=x onerror="alert(1)">']);
        $uuid = (string) $data['invitation_uuid'];

        // Resident list page (real data since Increment 2).
        $this->reloginAs(self::JANE);
        $residentPage = $this->get('/app/visitors');
        $this->assertStatus(200, $residentPage);
        $this->assertFalse(str_contains($residentPage->body(), $payload), 'Resident Visitors must escape the visitor name.');
        $this->assertFalse(str_contains($residentPage->body(), 'onerror="alert(1)"'));

        // Public pass page.
        $token = $this->getQrTokenForInvitation($uuid);
        $public = $this->get('/v/' . $token);
        $this->assertStatus(200, $public);
        $this->assertFalse(str_contains($public->body(), $payload), 'The public pass page must escape the visitor name.');
        $this->assertFalse(str_contains($public->body(), 'onerror="alert(1)"'));

        // Guard active list after check-in.
        $this->loginGuard('Main Gate');
        $this->assertSuccess($this->guardCheckin($uuid));
        $active = $this->get('/gate/active');
        $this->assertStatus(200, $active);
        $this->assertFalse(str_contains($active->body(), $payload), 'The guard active list must escape the visitor name.');
    }

    public function testCsrfIsEnforcedOnEveryNewPost(): void
    {
        $this->loginAs(self::JANE);
        $invitation = $this->seededInvitation();
        $household = $this->householdUuid('A-001');

        // Browser (HTML) posts answer with the rendered 403 error page…
        $webPosts = [
            ['/app/visitors/invite', ['visitor_name' => 'No CSRF', 'visitor_phone' => '+254700090501', 'household_uuid' => $household]],
        ];

        foreach ($webPosts as [$path, $body]) {
            $response = $this->post($path, $body, false);
            $this->assertStatus(403, $response);
            $this->assertBodyContains('CSRF_TOKEN_INVALID', $response);
        }

        // …while API posts answer with the JSON error envelope.
        $apiPosts = [
            ['/api/v1/resident/invitations', ['visitor_name' => 'No CSRF', 'visitor_phone' => '+254700090502', 'household_uuid' => $household]],
            ['/api/v1/resident/invitations/' . $invitation['uuid'] . '/revoke', []],
        ];

        foreach ($apiPosts as [$path, $body]) {
            $this->assertErrorCode('CSRF_TOKEN_INVALID', $this->post($path, $body, false), 403);
        }

        $this->loginGuard('Main Gate');

        $this->assertStatus(403, $this->post('/gate/checkin', ['invitation_uuid' => (string) $invitation['uuid']], false));

        $guardApiPosts = [
            ['/api/v1/gate/visitors/checkin', ['invitation_uuid' => (string) $invitation['uuid']]],
            ['/api/v1/gate/visitors/checkout', ['invitation_uuid' => (string) $invitation['uuid']]],
            ['/api/v1/gate/verify-pin', ['pin' => '000000']],
            ['/api/v1/gate/walkins', ['visitor_name' => 'No CSRF', 'visitor_phone' => '+254700090503', 'household_uuid' => $household]],
        ];

        foreach ($guardApiPosts as [$path, $body]) {
            $this->assertErrorCode('CSRF_TOKEN_INVALID', $this->post($path, $body, false), 403);
        }
    }

    public function testQrPngRequiresAuthenticationAndIsNotCacheable(): void
    {
        $invitationUuid = DemoVisitorSeeder::INVITATION_UPCOMING_UUID;

        // Unauthenticated: browser route redirects to login.
        $unauthenticated = $this->get('/app/passes/' . $invitationUuid . '/qr.png');
        $this->assertTrue(
            in_array($unauthenticated->status(), [302, 401, 403], true),
            'The QR PNG endpoint must require authentication.'
        );

        // The owning resident receives a PNG with no-store caching.
        $this->loginAs(self::JANE);
        $response = $this->get('/app/passes/' . $invitationUuid . '/qr.png');
        $this->assertStatus(200, $response);
        $this->assertContains('image/png', strtolower((string) $response->header('Content-Type')));
        $this->assertContains('no-store', strtolower((string) $response->header('Cache-Control')));
        $this->assertTrue(
            str_starts_with($response->body(), "\x89PNG\r\n\x1a\n"),
            'The QR endpoint must return a real PNG file.'
        );

        // A foreign household resident gets 404 (no enumeration).
        $this->loginAs(self::AMINA);
        $this->assertStatus(404, $this->get('/app/passes/' . $invitationUuid . '/qr.png'));
    }

    public function testQrPngIsDeniedForRiversideTenant(): void
    {
        $this->loginAs(self::DAVID_RIVERSIDE);

        $response = $this->get('/app/passes/' . DemoVisitorSeeder::INVITATION_UPCOMING_UUID . '/qr.png');

        $this->assertTrue(
            in_array($response->status(), [403, 404], true),
            'A foreign tenant must not fetch the Greenview QR PNG.'
        );
    }

    /**
     * Scan every character/text column in the test database for a plaintext.
     *
     * @return array<int, string> Human-readable hits (table.column).
     */
    private function scanDatabaseFor(string $needle): array
    {
        $database = $this->db->databaseName();

        $columns = $this->db->select(
            'SELECT table_name AS t, column_name AS c, data_type AS d
             FROM information_schema.columns
             WHERE table_schema = :database AND data_type IN (:c1, :c2, :c3, :c4, :c5, :c6, :c7)',
            [
                'database' => $database,
                'c1' => 'char',
                'c2' => 'varchar',
                'c3' => 'text',
                'c4' => 'mediumtext',
                'c5' => 'longtext',
                'c6' => 'json',
                'c7' => 'tinytext',
            ]
        );

        $hits = [];

        foreach ($columns as $column) {
            $table = (string) $column['t'];
            $name = (string) $column['c'];

            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                continue;
            }

            $count = (int) $this->db->scalar(
                'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $name . '` LIKE :needle',
                ['needle' => '%' . $needle . '%']
            );

            if ($count > 0) {
                $hits[] = $table . '.' . $name;
            }
        }

        return $hits;
    }

    private function getQrTokenForInvitation(string $invitationUuid): string
    {
        $credential = $this->db->selectOne(
            "SELECT ac.uuid FROM access_credentials ac
             INNER JOIN visitor_invitations vi ON vi.id = ac.invitation_id
             WHERE vi.uuid = :uuid AND ac.credential_type = 'qr' ORDER BY ac.id ASC LIMIT 1",
            ['uuid' => $invitationUuid]
        );

        if ($credential === null) {
            $this->fail('No QR credential found for invitation ' . $invitationUuid);
        }

        return $this->qrTokenFor((string) $credential['uuid']);
    }
}
