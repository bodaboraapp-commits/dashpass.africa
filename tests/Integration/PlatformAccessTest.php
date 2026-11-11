<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Platform portal access gate (docs/platform-admin-contract.md §1, §4).
 *
 * PRE-WRITTEN by qa-mail (T66) for the Increment-9 gate: the `/platform*` routes,
 * platform shell/views and platform controllers do not exist yet, so this file is
 * parked in storage/temporary/pending-tests/ and moves to tests/Integration/ at
 * the gate. Assertions follow the binding contract only.
 *
 * Route target: 147 -> 158 (11 new platform web routes, no estate middleware).
 */
final class PlatformAccessTest extends AdminModuleTestCase
{
    /** Seeded platform super administrator (platform_memberships, no estate membership). */
    protected const PLATFORM_ADMIN = '+254700000020';

    /** @var array<int, string> */
    private const GET_ROUTES = [
        '/platform',
        '/platform/estates',
        '/platform/estates/new',
        '/platform/plans',
        '/platform/subscriptions',
    ];

    /** @var array<int, string> */
    private const ESTATE_ONLY_PHONES = [
        self::GRACE,
        self::JANE,
        self::MANAGER,
        self::DAVID_RIVERSIDE,
        self::GUARD_MAIN,
        self::AUDITOR,
        self::HOUSEHOLD_MEMBER,
    ];

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function platformGet(string $path, string $phone = self::PLATFORM_ADMIN): \App\Core\Response
    {
        $this->ensureLoggedInAs($phone);

        return $this->get($path);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function platformPost(
        string $path,
        array $body = [],
        string $phone = self::PLATFORM_ADMIN,
        bool $csrf = true
    ): \App\Core\Response {
        $this->ensureLoggedInAs($phone);

        return $this->post($path, $body, $csrf);
    }

    /**
     * Grant a seeded platform role to an existing seeded user (fixture only —
     * the real provisioning flow creates platform operators out of band).
     */
    private function grantPlatformRole(string $phone, string $roleName): void
    {
        $user = $this->seededUser($phone);
        $roleId = (int) $this->db->scalar(
            "SELECT id FROM roles WHERE scope = 'platform' AND name = :name LIMIT 1",
            ['name' => $roleName]
        );
        $this->assertTrue($roleId > 0, 'Seeded platform role ' . $roleName . ' must exist.');

        $now = \App\Core\Clock::nowDb();
        $this->db->insert('platform_memberships', [
            'uuid' => $this->newUuid(),
            'user_id' => (int) $user['id'],
            'role_id' => $roleId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function setPlatformMembershipStatus(string $phone, string $status): void
    {
        $user = $this->seededUser($phone);
        $this->db->execute(
            'UPDATE platform_memberships SET status = :status, updated_at = :now WHERE user_id = :uid',
            ['status' => $status, 'uid' => (int) $user['id'], 'now' => \App\Core\Clock::nowDb()]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Platform roles                                                     */
    /* ------------------------------------------------------------------ */

    public function testPlatformSuperAdminCanOpenEveryPlatformScreen(): void
    {
        foreach (self::GET_ROUTES as $path) {
            $response = $this->platformGet($path);
            $this->assertStatus(200, $response, 'platform_super_admin must open ' . $path . '.');
        }
    }

    public function testPlatformRolesAreLimitedToTheirPermissionedAreas(): void
    {
        // platform_operations: estates + subscriptions, but not plans, not audit.
        $this->grantPlatformRole(self::BRIAN, 'platform_operations');
        $this->assertStatus(200, $this->platformGet('/platform', self::BRIAN));
        $this->assertStatus(200, $this->platformGet('/platform/estates', self::BRIAN));
        $this->assertStatus(200, $this->platformGet('/platform/subscriptions', self::BRIAN));
        $this->assertStatus(403, $this->platformGet('/platform/plans', self::BRIAN));

        // platform_support: area gate via platform.support.manage + platform.audit.view,
        // but no estate/plan/subscription management.
        $this->grantPlatformRole(self::AMINA, 'platform_support');
        $this->assertStatus(200, $this->platformGet('/platform', self::AMINA));
        $this->assertStatus(403, $this->platformGet('/platform/estates', self::AMINA));
        $this->assertStatus(403, $this->platformGet('/platform/plans', self::AMINA));
        $this->assertStatus(403, $this->platformGet('/platform/subscriptions', self::AMINA));

        // platform_finance: subscriptions + dashboard only.
        $this->grantPlatformRole(self::AUDITOR, 'platform_finance');
        $this->assertStatus(200, $this->platformGet('/platform', self::AUDITOR));
        $this->assertStatus(200, $this->platformGet('/platform/subscriptions', self::AUDITOR));
        $this->assertStatus(403, $this->platformGet('/platform/estates', self::AUDITOR));
        $this->assertStatus(403, $this->platformGet('/platform/plans', self::AUDITOR));
    }

    /* ------------------------------------------------------------------ */
    /* Non-platform users                                                 */
    /* ------------------------------------------------------------------ */

    public function testEstateOnlyUsersAreForbiddenOnEveryPlatformRoute(): void
    {
        foreach (self::ESTATE_ONLY_PHONES as $phone) {
            foreach (self::GET_ROUTES as $path) {
                $response = $this->platformGet($path, $phone);
                $this->assertStatus(403, $response, 'Estate-only user ' . $phone . ' must get 403 on ' . $path . '.');
            }

            $this->assertStatus(403, $this->platformPost('/platform/plans', [
                'code' => 'HACK_PLAN',
                'name' => 'Hack plan',
                'monthly_price_cents' => 100,
                'currency' => 'KES',
            ], $phone));

            $this->assertStatus(403, $this->platformPost('/platform/estates/' . $this->newUuid() . '/status', [
                'status' => 'suspended',
            ], $phone));
        }
    }

    public function testUnauthenticatedPlatformRequestsRedirectToLogin(): void
    {
        foreach (self::GET_ROUTES as $path) {
            $this->resetSession();
            $response = $this->get($path);
            $this->assertRedirect($response, '/login', 302);
        }
    }

    public function testSuspendedPlatformMembershipLosesAccess(): void
    {
        $this->assertStatus(200, $this->platformGet('/platform'));

        $this->setPlatformMembershipStatus(self::PLATFORM_ADMIN, 'suspended');
        $this->ensureLoggedInAs(self::PLATFORM_ADMIN);
        $this->assertStatus(403, $this->get('/platform'), 'A suspended platform membership must lose portal access.');

        $this->setPlatformMembershipStatus(self::PLATFORM_ADMIN, 'active');
        $this->reloginAs(self::PLATFORM_ADMIN);
        $this->assertStatus(200, $this->get('/platform'));
    }

    /* ------------------------------------------------------------------ */
    /* Account link + context separation                                  */
    /* ------------------------------------------------------------------ */

    public function testAccountScreenShowsPlatformConsoleLinkOnlyForPlatformUsers(): void
    {
        $platform = $this->platformGet('/app/account');
        $this->assertStatus(200, $platform);
        $this->assertTextContains('Platform console', $platform);
        $this->assertStringContainsString('href="/platform"', $platform->body());

        $resident = $this->platformGet('/app/account', self::JANE);
        $this->assertStatus(200, $resident);
        $this->assertTextNotContains('Platform console', $resident);
        $this->assertStringNotContainsString('href="/platform"', $resident->body(), 'Estate users must not see the platform console link.');
    }

    public function testPlatformOperatorHasNoEstateAdminAccess(): void
    {
        $this->ensureLoggedInAs(self::PLATFORM_ADMIN);

        $response = $this->get('/admin');
        $this->assertTrue(
            in_array($response->status(), [302, 403], true),
            'A platform-only operator must not reach the estate admin portal (got HTTP ' . $response->status() . ').'
        );
    }

    public function testPlatformMutationsRequireCsrfToken(): void
    {
        $this->ensureLoggedInAs(self::PLATFORM_ADMIN);

        $response = $this->post('/platform/plans', [
            'code' => 'CSRF_PLAN',
            'name' => 'CSRF plan',
            'monthly_price_cents' => 100,
            'currency' => 'KES',
        ], false);

        $this->assertStatus(403, $response);
        $this->assertBodyContains('CSRF_TOKEN_INVALID', $response);
    }
}
