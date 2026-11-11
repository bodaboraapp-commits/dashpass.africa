<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Admin household members (estate-admin-management-contract §2 "Household
 * members", §5): add new/existing residents, duplicate protection, single
 * primary enforcement and soft removal.
 */
final class AdminMemberTest extends AdminModuleTestCase
{
    public function testAddNewResidentAsHouseholdMember(): void
    {
        $household = $this->seededHouseholdRow('A-001');
        $phone = '+2547000910' . random_int(10, 99);

        $response = $this->adminPost('/admin/households/' . $household['uuid'] . '/members', [
            'mode' => 'new',
            'full_name' => 'Member Test Person',
            'phone' => $phone,
            'relationship' => 'family_member',
            'is_primary' => '0',
        ]);
        $this->assertAdminRedirect($response, '/admin/households/');

        $user = $this->db->selectOne('SELECT id, full_name FROM users WHERE phone = :phone LIMIT 1', ['phone' => \App\Core\Phone::normalize($phone)]);
        $this->assertNotNull($user, 'Adding a new resident must create/link the user.');

        $member = $this->db->selectOne(
            'SELECT status, relationship, is_primary FROM household_members WHERE household_id = :hid AND user_id = :uid',
            ['hid' => (int) $household['id'], 'uid' => (int) $user['id']]
        );
        $this->assertNotNull($member, 'The household member row must exist.');
        $this->assertSame('active', (string) $member['status']);
        $this->assertSame('family_member', (string) $member['relationship']);
        $this->assertSame(0, (int) $member['is_primary']);

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'admin.member_added'");
        $this->assertTrue((int) $audit['c'] > 0, 'Adding a member must be audited.');
    }

    public function testAddExistingUserByPhone(): void
    {
        $household = $this->seededHouseholdRow('A-001');

        // Lydia (+254700000012) is an existing user/household member of A-002.
        $response = $this->adminPost('/admin/households/' . $household['uuid'] . '/members', [
            'mode' => 'existing',
            'phone' => '+254700000012',
            'relationship' => 'family_member',
            'is_primary' => '0',
        ]);
        $this->assertAdminRedirect($response, '/admin/households/');

        $user = $this->db->selectOne("SELECT id FROM users WHERE phone = '+254700000012' LIMIT 1");
        $this->assertNotNull($user);

        $member = $this->db->selectOne(
            'SELECT status FROM household_members WHERE household_id = :hid AND user_id = :uid',
            ['hid' => (int) $household['id'], 'uid' => (int) $user['id']]
        );
        $this->assertNotNull($member, 'Linking an existing user must add a household member row.');
        $this->assertSame('active', (string) $member['status']);
    }

    public function testDuplicateMemberIsRejected(): void
    {
        $household = $this->seededHouseholdRow('A-001');
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM household_members');

        // Jane is already an active member of A-001.
        $response = $this->adminPost('/admin/households/' . $household['uuid'] . '/members', [
            'mode' => 'existing',
            'phone' => '+254700000010',
            'relationship' => 'owner',
            'is_primary' => '0',
        ]);

        $this->assertAdminRejected($response, '/admin/households/' . $household['uuid'], ['MEMBER_EXISTS', 'already']);
        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM household_members'));
    }

    public function testOnlyOnePrimaryMemberPerHousehold(): void
    {
        $household = $this->seededHouseholdRow('A-001');
        $phone = '+2547000920' . random_int(10, 99);

        // New member flagged primary must demote the existing primary (Jane).
        $this->assertAdminRedirect($this->adminPost('/admin/households/' . $household['uuid'] . '/members', [
            'mode' => 'new',
            'full_name' => 'New Primary',
            'phone' => $phone,
            'relationship' => 'owner',
            'is_primary' => '1',
        ]), '/admin/households/');

        $primaries = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM household_members WHERE household_id = :id AND status = 'active' AND is_primary = 1",
            ['id' => (int) $household['id']]
        );
        $this->assertSame(1, $primaries, 'Exactly one active primary member must remain.');

        $newUser = $this->db->selectOne('SELECT id FROM users WHERE phone = :phone LIMIT 1', ['phone' => \App\Core\Phone::normalize($phone)]);
        $this->assertNotNull($newUser);
        $this->assertSame(1, (int) $this->db->scalar(
            'SELECT is_primary FROM household_members WHERE household_id = :hid AND user_id = :uid',
            ['hid' => (int) $household['id'], 'uid' => (int) $newUser['id']]
        ));
        $this->assertSame(0, (int) $this->db->scalar(
            "SELECT is_primary FROM household_members WHERE household_id = :hid AND relationship = 'owner' AND is_primary = 0 LIMIT 1",
            ['hid' => (int) $household['id']]
        ));
    }

    public function testRemovingTheLastPrimaryMemberIsRejected(): void
    {
        $household = $this->seededHouseholdRow('A-001');
        $jane = $this->db->selectOne("SELECT id FROM users WHERE phone = '+254700000010' LIMIT 1");
        $this->assertNotNull($jane);

        $member = $this->db->selectOne(
            "SELECT uuid FROM household_members WHERE household_id = :hid AND user_id = :uid AND is_primary = 1 AND status = 'active' LIMIT 1",
            ['hid' => (int) $household['id'], 'uid' => (int) $jane['id']]
        );
        $this->assertNotNull($member);

        $response = $this->adminPost('/admin/households/' . $household['uuid'] . '/members/' . $member['uuid'] . '/remove', []);
        $this->assertAdminRejected($response, '/admin/households/' . $household['uuid'], ['PRIMARY_MEMBER_REQUIRED', 'primary']);

        $this->assertSame('active', (string) $this->db->scalar(
            'SELECT status FROM household_members WHERE uuid = :uuid',
            ['uuid' => (string) $member['uuid']]
        ));
    }

    public function testRemoveNonPrimaryMemberIsSoft(): void
    {
        $household = $this->seededHouseholdRow('A-002');
        $lydia = $this->db->selectOne("SELECT id FROM users WHERE phone = '+254700000012' LIMIT 1");
        $this->assertNotNull($lydia);

        $member = $this->db->selectOne(
            "SELECT uuid FROM household_members WHERE household_id = :hid AND user_id = :uid LIMIT 1",
            ['hid' => (int) $household['id'], 'uid' => (int) $lydia['id']]
        );
        $this->assertNotNull($member);
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM household_members');

        $this->assertAdminRedirect($this->adminPost('/admin/households/' . $household['uuid'] . '/members/' . $member['uuid'] . '/remove', []), '/admin/households/');

        $this->assertSame('inactive', (string) $this->db->scalar(
            'SELECT status FROM household_members WHERE uuid = :uuid',
            ['uuid' => (string) $member['uuid']]
        ));
        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM household_members'), 'Removal must be soft (no hard delete).');

        $audit = $this->db->selectOne("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'admin.member_removed'");
        $this->assertTrue((int) $audit['c'] > 0, 'Member removal must be audited.');
    }

    public function testMemberRoutesAreEstateScoped(): void
    {
        $riverside = $this->seededEstate('riverside-court');
        $riversideHousehold = $this->db->selectOne(
            'SELECT uuid FROM households WHERE estate_id = :id LIMIT 1',
            ['id' => (int) $riverside['id']]
        );
        $this->assertNotNull($riversideHousehold);

        $this->ensureLoggedInAs(self::GRACE);
        $foreign = $this->post('/admin/households/' . $riversideHousehold['uuid'] . '/members', [
            'mode' => 'existing',
            'phone' => '+254700000012',
            'relationship' => 'family_member',
        ], true);

        $this->assertStatus(404, $foreign, 'Foreign-estate household members must 404.');
    }
}
