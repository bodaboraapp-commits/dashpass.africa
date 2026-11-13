<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Goods in/out register (owner request 2026-11-13).
 *
 * Flow: tenant initiates goods check-in/check-out -> Estate Admin approves or
 * declines -> guard verifies the approved movement at the gate. Every step is
 * scoped to the estate/household and the resident is notified in-app.
 */
final class GoodsMovementTest extends IntegrationTestCase
{
    private const RESIDENT = '+254700000010'; // Jane, A-001 resident

    private const OTHER_RESIDENT = '+254700000011'; // Brian, A-002

    private const ESTATE_ADMIN = '+254700000001'; // Grace

    private const GUARD_MAIN = '+254700000004';

    public function testResidentCreatesAGoodsRequestForTheirHousehold(): void
    {
        $this->loginAs(self::RESIDENT);
        $page = $this->get('/app/goods');
        $this->assertStatus(200, $page);
        $this->assertTextContains('Goods in and out', $page);
        $this->assertTextContains('Request goods movement', $page);

        $response = $this->post('/app/goods', [
            'direction' => 'out',
            'description' => 'Washing machine',
            'quantity' => '1',
            'carrier_name' => 'MoveCo',
            'vehicle_plate' => 'kda 123a',
            'recipient_name' => 'Jane Smith',
            'notes' => 'Moving to the new house.',
        ]);
        $this->assertStatus(302, $response);
        $this->assertStringContainsString('/app/goods', (string) $response->header('Location'));

        $row = $this->db->selectOne(
            "SELECT gm.*, h.unit_number, e.slug AS estate_slug
             FROM goods_movements gm
             INNER JOIN households h ON h.id = gm.household_id
             INNER JOIN estates e ON e.id = gm.estate_id
             WHERE gm.direction = 'out' AND gm.description = 'Washing machine' LIMIT 1"
        );
        $this->assertNotNull($row, 'The goods request must be stored.');
        $this->assertSame('pending', (string) $row['status']);
        $this->assertSame('A-001', (string) $row['unit_number']);
        $this->assertSame('greenview-residences', (string) $row['estate_slug']);
        $this->assertSame('KDA 123A', (string) $row['vehicle_plate']);
        $this->assertMatchesRegularExpression('/^GDS-[0-9A-F]{6}$/', (string) $row['reference_code']);

        $page = $this->get('/app/goods');
        $this->assertTextContains('Washing machine', $page);
        $this->assertTextContains((string) $row['reference_code'], $page);
        $this->assertTextContains('Pending approval', $page);
    }

    public function testGoodsRequestValidation(): void
    {
        $this->loginAs(self::RESIDENT);
        $response = $this->post('/app/goods', ['direction' => 'out', 'description' => '']);
        $this->assertStatus(302, $response);

        $page = $this->get('/app/goods');
        $this->assertStatus(200, $page);
        $this->assertTextContains('Goods request not sent', $page);
        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM goods_movements')
        );
    }

    public function testRequestsAreScopedToTheResidentsHousehold(): void
    {
        $this->loginAs(self::RESIDENT);
        $this->post('/app/goods', ['direction' => 'in', 'description' => 'Groceries delivery']);

        $this->loginAs(self::OTHER_RESIDENT);
        $page = $this->get('/app/goods');
        $this->assertStatus(200, $page);
        $this->assertStringNotContainsString('Groceries delivery', $page->body(), 'Another household must not see this request.');
    }

    public function testEstateAdminApprovesAndTenantIsNotified(): void
    {
        $request = $this->createRequest('in', 'New fridge');

        $this->loginAs(self::ESTATE_ADMIN);
        $page = $this->get('/admin/goods?status=pending');
        $this->assertStatus(200, $page);
        $this->assertTextContains((string) $request['reference_code'], $page);
        $this->assertTextContains('New fridge', $page);

        $response = $this->post('/admin/goods/' . rawurlencode((string) $request['uuid']) . '/approve', [
            'decision_note' => 'Approved for delivery',
        ]);
        $this->assertStatus(302, $response);

        $row = $this->db->selectOne('SELECT * FROM goods_movements WHERE uuid = :uuid', ['uuid' => (string) $request['uuid']]);
        $this->assertSame('approved', (string) $row['status']);
        $this->assertNotNull($row['decided_by']);
        $this->assertNotNull($row['decided_at']);

        $notification = $this->db->selectOne(
            "SELECT * FROM notification_logs WHERE channel = 'in_app' AND template = 'goods_approved' AND recipient = :recipient LIMIT 1",
            ['recipient' => 'u:' . (int) $request['resident_user_id']]
        );
        $this->assertNotNull($notification, 'The tenant must be notified in-app when goods are approved.');
        $this->assertStringContainsString('/app/goods', (string) $notification['link']);

        $audit = $this->db->selectOne("SELECT id FROM audit_logs WHERE action = 'goods.approved' LIMIT 1");
        $this->assertNotNull($audit);
    }

    public function testEstateAdminDeclinesWithARequiredReason(): void
    {
        $request = $this->createRequest('out', 'Borrowed sofa');
        $this->loginAs(self::ESTATE_ADMIN);

        // Reason required.
        $this->post('/admin/goods/' . rawurlencode((string) $request['uuid']) . '/decline', ['decision_note' => '']);
        $row = $this->db->selectOne('SELECT status FROM goods_movements WHERE uuid = :uuid', ['uuid' => (string) $request['uuid']]);
        $this->assertSame('pending', (string) $row['status'], 'Declining without a reason must not change the request.');

        $this->post('/admin/goods/' . rawurlencode((string) $request['uuid']) . '/decline', ['decision_note' => 'Not on the inventory list']);
        $row = $this->db->selectOne('SELECT * FROM goods_movements WHERE uuid = :uuid', ['uuid' => (string) $request['uuid']]);
        $this->assertSame('declined', (string) $row['status']);
        $this->assertSame('Not on the inventory list', (string) $row['decision_note']);
    }

    public function testResidentCannotApproveGoods(): void
    {
        $request = $this->createRequest('out', 'Television');

        // createRequest leaves the resident signed in; the admin route must still 403.
        $this->assertStatus(403, $this->post('/admin/goods/' . rawurlencode((string) $request['uuid']) . '/approve', []));
        $row = $this->db->selectOne('SELECT status FROM goods_movements WHERE uuid = :uuid', ['uuid' => (string) $request['uuid']]);
        $this->assertSame('pending', (string) $row['status']);
    }

    public function testGuardVerifiesApprovedGoodsAtTheGate(): void
    {
        $request = $this->createRequest('out', 'Dining table');
        $this->loginAs(self::ESTATE_ADMIN);
        $this->post('/admin/goods/' . rawurlencode((string) $request['uuid']) . '/approve', []);

        $this->loginAs(self::GUARD_MAIN);
        $this->assertStatus(302, $this->post('/gate/select-gate', ['gate_uuid' => $this->mainGateUuid()]));

        $page = $this->get('/gate/goods');
        $this->assertStatus(200, $page);
        $this->assertTextContains((string) $request['reference_code'], $page);
        $this->assertTextContains('Dining table', $page);

        $response = $this->post('/gate/goods/' . rawurlencode((string) $request['uuid']) . '/verify', [
            'note' => 'Plate matches, 1 table',
        ]);
        $this->assertStatus(302, $response);

        $row = $this->db->selectOne('SELECT * FROM goods_movements WHERE uuid = :uuid', ['uuid' => (string) $request['uuid']]);
        $this->assertSame('completed', (string) $row['status']);
        $this->assertNotNull($row['verified_by']);
        $this->assertNotNull($row['verified_at']);
        $this->assertNotNull($row['gate_id']);

        // Verifying again is rejected.
        $this->post('/gate/goods/' . rawurlencode((string) $request['uuid']) . '/verify', []);
        $row = $this->db->selectOne('SELECT status FROM goods_movements WHERE uuid = :uuid', ['uuid' => (string) $request['uuid']]);
        $this->assertSame('completed', (string) $row['status']);
    }

    public function testGuardCannotVerifyUnapprovedGoods(): void
    {
        $request = $this->createRequest('in', 'Pending delivery');

        $this->loginAs(self::GUARD_MAIN);
        $this->assertStatus(302, $this->post('/gate/select-gate', ['gate_uuid' => $this->mainGateUuid()]));

        $this->post('/gate/goods/' . rawurlencode((string) $request['uuid']) . '/verify', []);
        $row = $this->db->selectOne('SELECT status FROM goods_movements WHERE uuid = :uuid', ['uuid' => (string) $request['uuid']]);
        $this->assertSame('pending', (string) $row['status'], 'Only approved goods may be verified at the gate.');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function createRequest(string $direction, string $description): array
    {
        $this->loginAs(self::RESIDENT);
        $this->post('/app/goods', [
            'direction' => $direction,
            'description' => $description,
            'quantity' => '1',
        ]);

        $row = $this->db->selectOne(
            'SELECT * FROM goods_movements WHERE description = :description ORDER BY id DESC LIMIT 1',
            ['description' => $description]
        );
        $this->assertNotNull($row, 'The seeded goods request must exist.');

        return $row;
    }

    private function mainGateUuid(): string
    {
        $gate = $this->db->selectOne(
            "SELECT g.uuid FROM gates g
             INNER JOIN guard_gate_assignments a ON a.gate_id = g.id AND a.guard_user_id = (SELECT id FROM users WHERE phone = :phone)
             WHERE g.name = 'Main Gate' AND g.status = 'active' LIMIT 1",
            ['phone' => self::GUARD_MAIN]
        );
        $this->assertNotNull($gate, 'The seeded Main Gate assignment is required.');

        return (string) $gate['uuid'];
    }
}
