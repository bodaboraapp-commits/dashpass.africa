<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use App\Core\Uuid;

/**
 * Authentication/tenant gating for the two new PWA screens (addendum §3, §7.1):
 * unauthenticated visitors are redirected, residents with an estate get 200,
 * users without an estate get the no-estate screen, and suspended or invalid
 * sessions are treated as unauthenticated.
 */
final class ResidentPwaAccessTest extends ResidentPwaTestCase
{
    /** @var array<int, string> */
    private const SCREENS = ['/app/visitors', '/app/bookings'];

    public function testAuthenticatedResidentCanOpenBothScreens(): void
    {
        $this->loginResident();

        foreach (self::SCREENS as $path) {
            $response = $this->get($path);
            $this->assertStatus(200, $response, $path . ' must render for an authenticated resident with an estate.');

            $expected = $path === '/app/visitors' ? 'Visitors' : 'Bookings';
            $this->assertTextContains($expected, $response);
        }
    }

    public function testUnauthenticatedRequestsRedirectToLogin(): void
    {
        $this->resetSession();

        foreach (self::SCREENS as $path) {
            $response = $this->get($path);
            $this->assertRedirect($response, '/login', 302);
        }
    }

    public function testUserWithoutAnEstateGetsTheNoEstateScreen(): void
    {
        $this->db->insert('users', [
            'uuid' => Uuid::v4(),
            'full_name' => 'No Estate Resident',
            'phone' => '+254799000002',
            'email' => null,
            'password_hash' => null,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => \App\Core\Clock::nowDb(),
            'updated_at' => \App\Core\Clock::nowDb(),
        ]);

        $this->loginAs('+254799000002');

        foreach (self::SCREENS as $path) {
            $response = $this->get($path);
            $this->assertRedirect($response, '/app/no-estate', 302);
        }
    }

    public function testSuspendedSessionIsTreatedAsUnauthenticated(): void
    {
        $this->loginResident();
        $this->assertStatus(200, $this->get('/app/visitors'));

        $this->db->execute(
            "UPDATE users SET status = 'suspended' WHERE phone = :phone",
            ['phone' => self::JANE]
        );

        foreach (self::SCREENS as $path) {
            $this->assertRedirect($this->get($path), '/login', 302);
        }
    }

    public function testInvalidSessionUserIsTreatedAsUnauthenticated(): void
    {
        $this->resetSession();
        Session::put('auth_user_id', 999999);

        foreach (self::SCREENS as $path) {
            $this->assertRedirect($this->get($path), '/login', 302);
        }
    }
}
