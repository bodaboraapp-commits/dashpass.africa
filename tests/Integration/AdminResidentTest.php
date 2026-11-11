<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Integrations\Sms\MockSmsProvider;

/**
 * Admin residents (estate-admin-management-contract §2 "Residents", §5):
 * create/link, duplicate protection, dev SMS invite, suspend/reactivate with
 * immediate access loss, and estate scoping.
 */
final class AdminResidentTest extends AdminModuleTestCase
{
    public function testResidentListShowsMaskedEstateMembers(): void
    {
        $response = $this->adminGet('/admin/residents');
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'Residents');

        $this->assertTextContains('Jane', $response);
        $this->assertTextContains('Brian', $response);

        // Phones are masked in the admin list (never full numbers).
        $this->assertBodyNotContains('+254700000010', $response, 'The admin list must mask resident phone numbers.');

        $forms = $this->xpath('//form[@action="/admin/residents"]', $this->dom($response));
        $this->assertCount(1, $forms, 'The list must offer an add-resident form.');
        $form = $forms->item(0);
        $this->assertNotNull($form);
        $this->assertCount(1, $this->xpath('.//input[@name="full_name"]', $form));
        $this->assertCount(1, $this->xpath('.//input[@name="phone"]', $form));
        $this->assertCount(1, $this->xpath('.//*[@name="role_slug"]', $form));

        // Only resident-ish roles are offered.
        $roleValues = [];
        foreach ($this->xpath('.//*[@name="role_slug"]//option', $form) as $option) {
            $roleValues[] = (string) $option->getAttribute('value');
        }
        $roleValues = array_values(array_filter($roleValues));

        foreach ($roleValues as $role) {
            $this->assertContains($role, ['resident', 'tenant', 'household_member']);
        }
    }

    public function testCreateNewResidentSendsDevSmsAndAudits(): void
    {
        MockSmsProvider::reset();
        $phone = '+2547000930' . random_int(10, 99);

        $response = $this->adminPost('/admin/residents', [
            'full_name' => 'Admin Created Resident',
            'phone' => $phone,
            'role_slug' => 'resident',
            'household_uuid' => (string) $this->seededHouseholdRow('A-001')['uuid'],
            'relationship' => 'owner',
        ]);
        $this->assertAdminRedirect($response, '/admin/residents');

        $user = $this->db->selectOne('SELECT id, full_name FROM users WHERE phone = :phone LIMIT 1', ['phone' => \App\Core\Phone::normalize($phone)]);
        $this->assertNotNull($user, 'The resident user must be created.');
        $this->assertSame('Admin Created Resident', (string) $user['full_name']);

        $membership = $this->db->selectOne(
            'SELECT status FROM estate_memberships WHERE user_id = :uid AND estate_id = :eid',
            ['uid' => (int) $user['id'], 'eid' => (int) $this->estate()['id']]
        );
        $this->assertNotNull($membership);
        $this->assertSame('active', (string) $membership['status']);

        // Dev SMS invite with the configured product name, no secrets.
        $messages = MockSmsProvider::messages();
        $this->assertNotEmpty($messages, 'Adding a resident must send a development SMS invite.');
        $product = (string) config('app.name', 'MtaaPass');
        $found = false;
        foreach ($messages as $message) {
            if (str_contains((string) $message['message'], $product)) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'The invite SMS must mention the configured product name.');
        $this->assertSame(1, (int) $this->db->scalar(
            "SELECT COUNT(*) FROM notification_logs WHERE template LIKE '%resident%' OR template LIKE '%invite%'"
        ));

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'admin.resident_created'");
        $this->assertTrue((int) $audit['c'] > 0, 'Resident creation must be audited.');
    }

    public function testLinkExistingGlobalUserByPhone(): void
    {
        // +254700000020 is a platform user with no estate membership.
        $phone = '+254700000020';

        $response = $this->adminPost('/admin/residents', [
            'full_name' => 'Linked Platform User',
            'phone' => $phone,
            'role_slug' => 'tenant',
            'household_uuid' => (string) $this->seededHouseholdRow('A-002')['uuid'],
            'relationship' => 'tenant',
        ]);
        $this->assertAdminRedirect($response, '/admin/residents');

        $user = $this->db->selectOne('SELECT id FROM users WHERE phone = :phone LIMIT 1', ['phone' => $phone]);
        $this->assertNotNull($user);

        $membership = $this->db->selectOne(
            'SELECT status FROM estate_memberships WHERE user_id = :uid AND estate_id = :eid',
            ['uid' => (int) $user['id'], 'eid' => (int) $this->estate()['id']]
        );
        $this->assertNotNull($membership, 'Linking an existing user must create the estate membership.');
        $this->assertSame('active', (string) $membership['status']);

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action IN ('admin.resident_linked','admin.resident_created')");
        $this->assertTrue((int) $audit['c'] > 0, 'Link/create must be audited.');
    }

    public function testDuplicateActiveMembershipIsRejected(): void
    {
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM estate_memberships');

        $response = $this->adminPost('/admin/residents', [
            'full_name' => 'Jane Duplicate',
            'phone' => '+254700000010',
            'role_slug' => 'resident',
        ]);

        $this->assertAdminRejected($response, '/admin/residents', ['RESIDENT_EXISTS', 'already']);
        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM estate_memberships'));
    }

    public function testInvalidPhoneAndRoleAreRejected(): void
    {
        foreach ([
            ['full_name' => 'Bad Phone', 'phone' => '12345', 'role_slug' => 'resident'],
            ['full_name' => 'Bad Role', 'phone' => '+2547000940' . random_int(10, 99), 'role_slug' => 'estate_admin'],
            ['full_name' => 'X', 'phone' => '+2547000941' . random_int(10, 99), 'role_slug' => 'resident'],
        ] as $body) {
            $this->assertAdminRejected($this->adminPost('/admin/residents', $body), '/admin/residents', ['phone', 'role', 'full_name', 'invalid', 'required', 'between']);
        }
    }

    public function testSuspendAndReactivateMembership(): void
    {
        $brian = $this->membershipRow(self::BRIAN);

        $this->assertAdminRedirect($this->adminPost('/admin/residents/' . $brian['uuid'] . '/suspend', []), '/admin/residents');

        $status = (string) $this->db->scalar('SELECT status FROM estate_memberships WHERE id = :id', ['id' => (int) $brian['id']]);
        $this->assertSame('suspended', $status, 'Suspend must set the membership status.');

        // A suspended resident loses access immediately.
        $this->ensureLoggedInAs(self::BRIAN);
        $access = $this->get('/app');
        $this->assertTrue(
            in_array($access->status(), [302, 403], true),
            'A suspended resident must lose access, got HTTP ' . $access->status() . '.'
        );

        // Reactivate restores it.
        $this->reloginAs(self::GRACE);
        $this->assertAdminRedirect($this->adminPost('/admin/residents/' . $brian['uuid'] . '/reactivate', []), '/admin/residents');
        $this->assertSame('active', (string) $this->db->scalar('SELECT status FROM estate_memberships WHERE id = :id', ['id' => (int) $brian['id']]));

        $this->reloginAs(self::BRIAN);
        $this->assertStatus(200, $this->get('/app'));

        $audits = $this->db->select("SELECT action FROM audit_logs WHERE action IN ('admin.resident_suspended','admin.resident_reactivated')");
        $this->assertCount(2, $audits);
    }

    public function testResidentsAreEstateScoped(): void
    {
        $riverside = $this->seededEstate('riverside-court');
        $greenviewMembership = $this->membershipRow(self::JANE);

        // Riverside admin cannot suspend a Greenview membership.
        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);
        $foreign = $this->post('/admin/residents/' . $greenviewMembership['uuid'] . '/suspend', [], true);
        $this->assertStatus(404, $foreign);
        $this->assertSame('active', (string) $this->db->scalar(
            'SELECT status FROM estate_memberships WHERE id = :id',
            ['id' => (int) $greenviewMembership['id']]
        ));

        // Greenview admin cannot suspend a Riverside membership.
        $riversideMember = $this->db->selectOne(
            'SELECT uuid FROM estate_memberships WHERE estate_id = :id LIMIT 1',
            ['id' => (int) $riverside['id']]
        );
        $this->assertNotNull($riversideMember);
        $this->ensureLoggedInAs(self::GRACE);
        $this->assertStatus(404, $this->post('/admin/residents/' . $riversideMember['uuid'] . '/suspend', [], true));
    }
}
