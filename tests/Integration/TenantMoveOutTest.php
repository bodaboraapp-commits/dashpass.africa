<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Tenant move-out module (owner request 2026-11-13).
 *
 * The Estate Admin moves a tenant out from the Residents > Move-outs tab:
 * household members are deactivated, resident estate access is suspended, staff,
 * visitor passes and vehicles for the house are closed, the house stays active
 * but vacant, a move-out record is kept and the next tenant can be added.
 */
final class TenantMoveOutTest extends IntegrationTestCase
{
    private const ESTATE_ADMIN = '+254700000001'; // Grace, estate_admin @ Greenview Residences

    private const RESIDENT = '+254700000010'; // Jane, tenant @ A-001

    public function testMoveOutTabListsTenantsAndHistory(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $response = $this->get('/admin/residents?tab=move-outs');

        $this->assertStatus(200, $response);
        $this->assertTextContains('Tenant move-outs', $response);
        $this->assertTextContains('Move out a tenant', $response);
        $this->assertTextContains('Vacant houses', $response);
        $this->assertTextContains('Move-out history', $response);
        $this->assertTextContains('A-001', $response);
        $this->assertTextContains('Jane Smith', $response);
        $this->assertTextContains('Move out tenant', $response);
    }

    public function testMoveOutRemovesTenantAndLeavesHouseVacant(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $household = $this->household('A-001');
        $jane = $this->user(self::RESIDENT);

        $response = $this->post('/admin/residents', [
            'action' => 'move_out',
            'household_uuid' => (string) $household['uuid'],
            'moved_out_at' => date('Y-m-d'),
            'reason' => 'Lease ended',
            'notes' => 'Keys returned at handover.',
            'confirm' => '1',
        ]);
        $this->assertStatus(302, $response);
        $this->assertStringContainsString('/admin/residents?tab=move-outs', (string) $response->header('Location'));

        // Household members are soft-deactivated; the household stays active (vacant).
        $activeMembers = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM household_members WHERE household_id = :household_id AND status = 'active'",
            ['household_id' => (int) $household['id']]
        );
        $this->assertSame(0, $activeMembers, 'Every active household member must be moved out.');

        $householdRow = $this->db->selectOne('SELECT status FROM households WHERE id = :id', ['id' => (int) $household['id']]);
        $this->assertNotNull($householdRow);
        $this->assertSame('active', (string) $householdRow['status'], 'A vacant house stays active so it can be re-occupied.');

        // Resident estate access is suspended.
        $membership = $this->db->selectOne(
            'SELECT status FROM estate_memberships WHERE estate_id = :estate_id AND user_id = :user_id LIMIT 1',
            ['estate_id' => (int) $household['estate_id'], 'user_id' => (int) $jane['id']]
        );
        $this->assertNotNull($membership);
        $this->assertSame('suspended', (string) $membership['status']);

        // Household staff, open visitor passes and active vehicles are closed.
        $activeStaff = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM household_staff WHERE household_id = :household_id AND status = 'active'",
            ['household_id' => (int) $household['id']]
        );
        $this->assertSame(0, $activeStaff, 'Household staff must be deactivated on move-out.');

        $openPasses = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM visitor_invitations
             WHERE household_id = :household_id AND status IN ('pending', 'approved')",
            ['household_id' => (int) $household['id']]
        );
        $this->assertSame(0, $openPasses, 'Open visitor passes must be revoked on move-out.');

        // A move-out snapshot record is stored.
        $record = $this->db->selectOne(
            'SELECT * FROM household_move_outs WHERE household_id = :household_id LIMIT 1',
            ['household_id' => (int) $household['id']]
        );
        $this->assertNotNull($record, 'A move-out history record is required.');
        $this->assertSame('Jane Smith', (string) $record['tenant_name_snapshot']);
        $this->assertSame('A-001', (string) $record['unit_number_snapshot']);
        $this->assertSame('Lease ended', (string) $record['reason']);
        $this->assertSame(1, (int) $record['members_removed']);

        // The move-out is audited.
        $audit = $this->db->selectOne(
            "SELECT id FROM audit_logs WHERE action = 'admin.tenant_moved_out' AND entity_type = 'household' AND entity_id = :id LIMIT 1",
            ['id' => (int) $household['id']]
        );
        $this->assertNotNull($audit, 'The move-out must be audited.');

        // History + vacant list render after the move-out.
        $page = $this->get('/admin/residents?tab=move-outs');
        $this->assertStatus(200, $page);
        $this->assertTextContains('Jane Smith', $page);
        $this->assertTextContains('Lease ended', $page);
        $this->assertTextContains('Vacant houses', $page);

        $households = $this->get('/admin/households');
        $this->assertStatus(200, $households);
        $this->assertTextContains('Vacant', $households);
    }

    public function testVacantHouseAcceptsTheNextTenant(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $household = $this->household('A-001');

        $this->post('/admin/residents', [
            'action' => 'move_out',
            'household_uuid' => (string) $household['uuid'],
            'moved_out_at' => date('Y-m-d'),
            'confirm' => '1',
        ]);

        $response = $this->post('/admin/households/' . rawurlencode((string) $household['uuid']) . '/members', [
            'mode' => 'new',
            'phone' => '+254700099999',
            'full_name' => 'New Tenant',
            'relationship' => 'tenant',
            'is_primary' => '1',
        ]);
        $this->assertStatus(302, $response);

        $newMember = $this->db->selectOne(
            "SELECT hm.status, hm.is_primary, u.full_name
             FROM household_members hm
             INNER JOIN users u ON u.id = hm.user_id
             WHERE hm.household_id = :household_id AND u.phone = :phone LIMIT 1",
            ['household_id' => (int) $household['id'], 'phone' => '+254700099999']
        );
        $this->assertNotNull($newMember, 'The next tenant must be attachable to the vacant house.');
        $this->assertSame('active', (string) $newMember['status']);
        $this->assertSame(1, (int) $newMember['is_primary']);
        $this->assertSame('New Tenant', (string) $newMember['full_name']);
    }

    public function testMovingOutAnAlreadyVacantHouseIsRejected(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $household = $this->household('A-001');

        $payload = [
            'action' => 'move_out',
            'household_uuid' => (string) $household['uuid'],
            'moved_out_at' => date('Y-m-d'),
            'confirm' => '1',
        ];
        $this->assertStatus(302, $this->post('/admin/residents', $payload));
        $this->assertStatus(302, $this->post('/admin/residents', $payload));

        $page = $this->get('/admin/residents?tab=move-outs');
        $this->assertStatus(200, $page);
        $this->assertTextContains('already vacant', $page);
    }

    public function testMoveOutRequiresConfirmation(): void
    {
        $this->loginAs(self::ESTATE_ADMIN);
        $household = $this->household('A-001');

        $response = $this->post('/admin/residents', [
            'action' => 'move_out',
            'household_uuid' => (string) $household['uuid'],
            'moved_out_at' => date('Y-m-d'),
        ]);
        $this->assertStatus(302, $response);

        $page = $this->get('/admin/residents?tab=move-outs');
        $this->assertStatus(200, $page);
        $this->assertTextContains('confirmation', $page);

        $activeMembers = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM household_members WHERE household_id = :household_id AND status = 'active'",
            ['household_id' => (int) $household['id']]
        );
        $this->assertSame(1, $activeMembers, 'A move-out without confirmation must not change anything.');
    }

    public function testResidentCannotOpenTheMoveOutModule(): void
    {
        $this->loginAs(self::RESIDENT);
        $this->assertStatus(403, $this->get('/admin/residents?tab=move-outs'));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function household(string $unit): array
    {
        $row = $this->db->selectOne(
            "SELECT h.* FROM households h
             INNER JOIN estates e ON e.id = h.estate_id
             WHERE e.slug = 'greenview-residences' AND h.unit_number = :unit LIMIT 1",
            ['unit' => $unit]
        );
        $this->assertNotNull($row, 'The seeded household ' . $unit . ' is required.');

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $phone): array
    {
        $row = $this->db->selectOne('SELECT * FROM users WHERE phone = :phone LIMIT 1', ['phone' => $phone]);
        $this->assertNotNull($row, 'The seeded user ' . $phone . ' is required.');

        return $row;
    }
}
