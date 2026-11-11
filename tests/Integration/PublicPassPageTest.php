<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Crypto;
use Database\Seeds\DemoVisitorSeeder;

/**
 * Public visitor pass page `/v/{token}` (visitor-access-module-contract §4.7).
 */
final class PublicPassPageTest extends VisitorModuleTestCase
{
    private function seededUpcomingToken(): string
    {
        return $this->qrTokenFor(DemoVisitorSeeder::CREDENTIAL_UPCOMING_QR_UUID);
    }

    public function testValidTokenRendersPassPage(): void
    {
        $token = $this->seededUpcomingToken();

        $response = $this->get('/v/' . $token);

        $this->assertStatus(200, $response);
        $this->assertContains('text/html', (string) $response->header('Content-Type'));

        $body = $response->body();
        $this->assertContains('Greenview Residences', $body);
        $this->assertContains('Demo Visitor Three', $body);
        $this->assertContains('A-001', $body, 'The pass page must show the household label.');
        $this->assertFalse(str_contains($body, DemoVisitorSeeder::DEMO_PIN), 'The public pass page must never show the PIN.');
        $this->assertContains('no-store', strtolower((string) $response->header('Cache-Control')));
    }

    public function testUnknownTokenRendersNeutral404(): void
    {
        $response = $this->get('/v/' . bin2hex(random_bytes(32)));

        $this->assertStatus(404, $response);
        $this->assertBodyContains('MtaaPass', $response);
        $this->assertFalse(
            str_contains(strtolower($response->body()), 'sqlstate'),
            'The invalid pass page must not expose errors.'
        );
        $this->assertFalse(
            str_contains(strtolower($response->body()), 'trace'),
            'The invalid pass page must not expose stack traces.'
        );
    }

    public function testRevokedInvitationPassPageShowsRevokedStatus(): void
    {
        $data = $this->createInvitation(['visitor_name' => 'Revoked Pass Visitor']);
        $uuid = (string) $data['invitation_uuid'];
        $token = $this->qrTokenFromInvitation($uuid);

        $this->post('/api/v1/resident/invitations/' . $uuid . '/revoke', [], true);

        $response = $this->get('/v/' . $token);
        $this->assertStatus(200, $response);
        $this->assertBodyContains('Revoked', $response);
        $this->assertFalse(str_contains($response->body(), (string) $data['pin']));
    }

    public function testExpiredInvitationPassPageShowsExpiredStatus(): void
    {
        $data = $this->createInvitation(['visitor_name' => 'Expired Pass Visitor']);
        $uuid = (string) $data['invitation_uuid'];
        $token = $this->qrTokenFromInvitation($uuid);

        $this->db->execute(
            'UPDATE visitor_invitations SET status = :status, valid_until = :past WHERE uuid = :uuid',
            ['status' => 'expired', 'past' => Clock::toDb(Clock::now()->modify('-2 hours')), 'uuid' => $uuid]
        );

        $response = $this->get('/v/' . $token);
        $this->assertStatus(200, $response);
        $this->assertBodyContains('Expired', $response);
    }

    public function testCheckedOutPassPageShowsUsedStatus(): void
    {
        // Seeded history visit is checked_out with consumed credentials.
        $token = $this->qrTokenFor(DemoVisitorSeeder::CREDENTIAL_HISTORY_ONE_QR_UUID);

        $response = $this->get('/v/' . $token);

        $this->assertStatus(200, $response);
        $body = strtolower($response->body());
        $this->assertTrue(
            str_contains($body, 'checked out')
            || str_contains($body, 'used')
            || str_contains($body, 'no longer')
            || str_contains($body, 'expired'),
            'A checked-out pass must show a terminal status.'
        );
        $this->assertContains('Demo Visitor One', $response->body());
    }

    public function testPassPageNeverRevealsResidentPii(): void
    {
        $token = $this->seededUpcomingToken();
        $invitation = $this->seededInvitation();
        $host = $this->db->selectOne(
            'SELECT u.id, u.uuid, u.phone, u.email FROM users u WHERE u.id = :id',
            ['id' => (int) $this->db->scalar(
                'SELECT host_user_id FROM visitor_invitations WHERE id = :id',
                ['id' => (int) $invitation['id']]
            )]
        );
        $this->assertNotNull($host);

        $body = $this->get('/v/' . $token)->body();

        foreach ([
            '+254700000010',
            'jane',
            'Jane Smith',
            (string) $host['uuid'],
            'user_id',
            'host_user_id',
        ] as $forbidden) {
            $this->assertFalse(
                stripos($body, $forbidden) !== false,
                'The public pass page must not expose resident PII/ids ("' . $forbidden . '").'
            );
        }
    }

    public function testPassPageRateLimit(): void
    {
        $token = $this->seededUpcomingToken();

        for ($request = 1; $request <= 60; $request++) {
            $response = $this->get('/v/' . $token);
            $this->assertStatus(200, $response, 'Pass page request ' . $request . ' must be within the budget.');
        }

        $limited = $this->get('/v/' . $token);
        $this->assertStatus(429, $limited, 'The public pass page must be rate limited.');
    }

    private function qrTokenFromInvitation(string $invitationUuid): string
    {
        $credential = $this->db->selectOne(
            "SELECT ac.uuid FROM access_credentials ac
             INNER JOIN visitor_invitations vi ON vi.id = ac.invitation_id
             WHERE vi.uuid = :uuid AND ac.credential_type = 'qr' ORDER BY ac.id ASC LIMIT 1",
            ['uuid' => $invitationUuid]
        );

        if ($credential === null) {
            $this->fail('No QR credential for invitation ' . $invitationUuid);
        }

        return $this->qrTokenFor((string) $credential['uuid']);
    }
}
