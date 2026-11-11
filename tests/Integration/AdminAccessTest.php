<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Admin portal access matrix (estate-admin-management-contract §1, §5):
 * estate admins and permitted managers get in; everyone else is denied; the
 * portal is estate-scoped and a suspended admin loses access immediately.
 */
final class AdminAccessTest extends AdminModuleTestCase
{
    public function testEstateAdminReachesTheAdminOverview(): void
    {
        $response = $this->adminGet('/admin', self::GRACE);
        $this->assertStatus(200, $response);

        $this->assertCleanMarkup($response, 'Admin overview');

        $h1s = $this->xpath('//h1', $this->dom($response));
        $this->assertCount(1, $h1s, 'The admin overview must render exactly one h1.');
        $h1 = $h1s->item(0);
        $this->assertNotNull($h1);

        $this->assertTextContains('Greenview Residences', $response);
        $this->assertTextContains('Buildings', $response);
        $this->assertTextContains('Households', $response);
        $this->assertTextContains('Residents', $response);
        $this->assertTextContains('Guards', $response);
        $this->assertTextContains('Gates', $response);
        $this->assertTextContains('Settings', $response);

        // Real counts from the seeded estate, not fake numbers.
        foreach (['4', '12'] as $count) {
            $this->assertTextContains($count, $response);
        }
    }

    public function testRiversideAdminSeesOnlyTheirEstate(): void
    {
        $response = $this->adminGet('/admin', self::DAVID_RIVERSIDE);
        $this->assertStatus(200, $response);

        $this->assertTextContains('Riverside Court', $response);
        $this->assertTextNotContains('Greenview Residences', $response, 'A Riverside admin must never see Greenview data.');
    }

    public function testEstateManagerAccessFollowsItsPermissions(): void
    {
        // estate_manager passes the shared admin gate and is bounded by route perms.
        $this->assertStatus(200, $this->adminGet('/admin', self::MANAGER), 'estate_manager holds estate.view and may open the admin overview.');
        $this->assertStatus(200, $this->get('/admin/buildings'), 'estate_manager holds household.view → buildings list is readable.');
        $this->assertStatus(200, $this->get('/admin/households'), 'estate_manager holds household.view → households list is readable.');
        $this->assertStatus(200, $this->get('/admin/guards'), 'estate_manager holds guard.manage → guards are manageable.');

        // No estate.user.manage → resident admin is forbidden.
        $this->assertStatus(403, $this->get('/admin/residents'));

        // No estate.settings.manage → settings are forbidden.
        $this->assertStatus(403, $this->get('/admin/settings'));

        // No gate.manage → gate reads are allowed (gate.view) but writes are not.
        $this->assertStatus(200, $this->get('/admin/gates'));
        $this->assertStatus(403, $this->post('/admin/gates', ['name' => 'Manager Gate'], true));
        $this->assertStatus(403, $this->post('/admin/gates/' . $this->gate('Main Gate')['uuid'] . '/archive', [], true));
    }

    public function testSupervisorAccessFollowsItsPermissions(): void
    {
        // security_supervisor holds estate.view + guard.manage + gate.manage:
        // the admin gate passes, but route permissions bound the surface.
        $this->assertStatus(200, $this->adminGet('/admin', self::SUPERVISOR));
        $this->assertStatus(200, $this->get('/admin/guards'));
        $this->assertStatus(200, $this->get('/admin/gates'));

        // Gate management is within the supervisor's permissions.
        $this->assertStatus(302, $this->post('/admin/gates', ['name' => 'Supervisor Gate ' . bin2hex(random_bytes(3))], true));

        // No household / resident / settings management ? 403.
        $this->assertStatus(403, $this->get('/admin/buildings'));
        $this->assertStatus(403, $this->get('/admin/households'));
        $this->assertStatus(403, $this->get('/admin/residents'));
        $this->assertStatus(403, $this->get('/admin/settings'));
    }
    public function testNonAdminRolesAreForbiddenOnEveryAdminRoute(): void
    {
        $building = $this->seededBuilding('Block A');
        $household = $this->seededHouseholdRow('A-001');

        $paths = [
            '/admin',
            '/admin/buildings',
            '/admin/buildings/' . $building['uuid'],
            '/admin/households',
            '/admin/households/' . $household['uuid'],
            '/admin/residents',
            '/admin/guards',
            '/admin/gates',
            '/admin/settings',
        ];

        foreach ([self::JANE, self::BRIAN, self::AMINA, self::HOUSEHOLD_MEMBER, self::GUARD_MAIN, self::PARKING_MANAGER, self::AUDITOR] as $phone) {
            $this->ensureLoggedInAs($phone);

            foreach ($paths as $path) {
                $this->assertStatus(403, $this->get($path), $phone . ' must be forbidden from ' . $path . '.');
            }
        }
    }

    public function testResidentsCannotPostHouseholdMemberRoutes(): void
    {
        // Privilege-escalation guard: household member mutations need
        // household.manage|estate.user.manage at controller level too.
        $household = $this->seededHouseholdRow('A-001');
        $member = $this->db->selectOne(
            'SELECT uuid FROM household_members WHERE household_id = :id LIMIT 1',
            ['id' => (int) $household['id']]
        );
        $this->assertNotNull($member);

        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM household_members');

        $this->ensureLoggedInAs(self::JANE);
        $this->assertStatus(403, $this->post('/admin/households/' . $household['uuid'] . '/members', [
            'mode' => 'existing',
            'phone' => '+254700000013',
            'relationship' => 'family_member',
        ], true));
        $this->assertStatus(403, $this->post('/admin/households/' . $household['uuid'] . '/members/' . $member['uuid'] . '/remove', [], true));

        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM household_members'));
    }

    public function testUnauthenticatedAdminRequestRedirectsToLogin(): void
    {
        $this->resetSession();

        $response = $this->get('/admin');
        $this->assertStatus(302, $response);
        $this->assertRedirect($response, '/login');
    }

    public function testSuspendedAdminLosesAccessImmediately(): void
    {
        // David administers a single estate, so suspending that membership must
        // remove admin access entirely (Grace keeps a second active estate).
        $membership = $this->membershipRow(self::DAVID_RIVERSIDE, 'riverside-court');

        $this->db->execute(
            'UPDATE estate_memberships SET status = :status WHERE id = :id',
            ['status' => 'suspended', 'id' => (int) $membership['id']]
        );

        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);
        $response = $this->get('/admin');

        $this->assertTrue(
            in_array($response->status(), [302, 403], true),
            'A suspended admin must lose access immediately, got HTTP ' . $response->status() . '.'
        );
        $this->assertBodyNotContains('Riverside Court', $response);

        // Restore the membership: access returns.
        $this->db->execute(
            'UPDATE estate_memberships SET status = :status WHERE id = :id',
            ['status' => 'active', 'id' => (int) $membership['id']]
        );

        $this->reloginAs(self::DAVID_RIVERSIDE);
        $this->assertStatus(200, $this->get('/admin'));
    }

    public function testAccountScreenShowsAdminLinkOnlyForAdmins(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $adminAccount = $this->get('/app/account');
        $this->assertStatus(200, $adminAccount);
        $this->assertBodyContains('/admin', $adminAccount, 'An estate admin must get an Admin console link in the account screen.');
        $this->assertTextContains('Admin', $adminAccount);

        $this->ensureLoggedInAs(self::JANE);
        $residentAccount = $this->get('/app/account');
        $this->assertStatus(200, $residentAccount);
        $this->assertCount(
            0,
            $this->xpath('//a[@href="/admin"]', $this->dom($residentAccount)),
            'A resident must not be offered a real admin link.'
        );
        $this->assertTextNotContains('Open admin console', $residentAccount);
    }

    public function testAdminShellKeepsTheConfiguredProductName(): void
    {
        $response = $this->adminGet('/admin', self::GRACE);
        $product = (string) config('app.name', 'MtaaPass');

        $this->assertStatus(200, $response);
        $this->assertTextContains($product, $response, 'The admin shell must use the configured product name.');
    }
}
