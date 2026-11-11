<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Uuid;
use Database\Seeds\DemoParkingSeeder;
use Database\Seeds\DemoNoticeSeeder;
use Database\Seeds\DemoStaffIncidentSeeder;
use Database\Seeds\DemoVisitorSeeder;
use Tests\TestDatabase;

/**
 * Demo seed data (contract §5): exact account/estate/role/permission inventory
 * and guaranteed idempotency on re-run.
 */
final class SeederTest extends IntegrationTestCase
{
    /**
     * phone => [full name, memberships as [estate slug, role name]...]
     *
     * @var array<string, array{0: string, 1: array<int, array{0: string, 1: string}>}>
     */
    private const DEMO_USERS = [
        '+254700000001' => ['Grace Wanjiku', [['greenview-residences', 'estate_admin'], ['riverside-court', 'estate_admin']]],
        '+254700000002' => ['Peter Ochieng', [['greenview-residences', 'estate_manager']]],
        '+254700000003' => ['Samuel Kariuki', [['greenview-residences', 'security_supervisor']]],
        '+254700000004' => ['John Mutiso', [['greenview-residences', 'security_guard']]],
        '+254700000005' => ['David Kiptoo', [['greenview-residences', 'security_guard']]],
        '+254700000006' => ['Faith Achieng', [['greenview-residences', 'security_guard']]],
        '+254700000007' => ['Brian Ochieng', [['greenview-residences', 'security_guard']]],
        '+254700000008' => ['Mary Atieno', [['greenview-residences', 'parking_manager']]],
        '+254700000009' => ['Lucy Wambui', [['greenview-residences', 'auditor']]],
        '+254700000010' => ['Jane Smith', [['greenview-residences', 'resident']]],
        '+254700000011' => ['Brian Otieno', [['greenview-residences', 'tenant']]],
        '+254700000012' => ['Lydia Njeri', [['greenview-residences', 'household_member']]],
        '+254700000013' => ['Amina Hassan', [['greenview-residences', 'resident']]],
        '+254700000014' => ['David Njoroge', [['riverside-court', 'estate_admin']]],
    ];

    /**
     * role => expected permission count from contract §5.
     *
     * @var array<string, int>
     */
    private const ROLE_PERMISSION_COUNTS = [
        'platform_super_admin' => 52,
        'platform_operations' => 5,
        'platform_support' => 2,
        'platform_finance' => 5,
        'estate_admin' => 33,
        'estate_manager' => 24,
        'security_supervisor' => 19,
        'security_guard' => 10,
        'parking_manager' => 11,
        'resident' => 17,
        'tenant' => 16,
        'household_member' => 11,
        'auditor' => 12,
    ];

    public function testDemoAccountAndPlans(): void
    {
        $account = $this->db->selectOne('SELECT name, status FROM saas_accounts LIMIT 1');
        $this->assertNotNull($account, 'The demo SaaS account must be seeded.');
        $this->assertSame('Greenview Holdings Ltd', (string) $account['name']);
        $this->assertSame('active', (string) $account['status']);

        $plans = [];
        foreach ($this->db->select('SELECT code, name, monthly_price_cents, household_limit, gate_device_limit, status FROM subscription_plans ORDER BY code ASC') as $row) {
            $plans[(string) $row['code']] = $row;
        }

        $this->assertSame(['growth', 'premium', 'starter'], array_keys($plans));

        $this->assertSame('Starter', (string) $plans['starter']['name']);
        $this->assertSame(250000, (int) $plans['starter']['monthly_price_cents']);
        $this->assertSame(50, (int) $plans['starter']['household_limit']);
        $this->assertSame(2, (int) $plans['starter']['gate_device_limit']);

        $this->assertSame('Growth', (string) $plans['growth']['name']);
        $this->assertSame(500000, (int) $plans['growth']['monthly_price_cents']);
        $this->assertSame(150, (int) $plans['growth']['household_limit']);
        $this->assertSame(5, (int) $plans['growth']['gate_device_limit']);

        $this->assertSame('Premium', (string) $plans['premium']['name']);
        $this->assertSame(900000, (int) $plans['premium']['monthly_price_cents']);
        $this->assertSame(300, (int) $plans['premium']['household_limit']);
    }

    public function testDemoEstates(): void
    {
        $greenview = $this->seededEstate('greenview-residences');
        $riverside = $this->seededEstate('riverside-court');

        $this->assertSame('Greenview Residences', (string) $greenview['name']);
        $this->assertSame('mixed', (string) $greenview['estate_type']);
        $this->assertSame('Africa/Nairobi', (string) $greenview['timezone']);
        $this->assertSame('active', (string) $greenview['status']);

        $this->assertSame('Riverside Court', (string) $riverside['name']);
        $this->assertSame('apartment', (string) $riverside['estate_type']);
        $this->assertSame('Africa/Nairobi', (string) $riverside['timezone']);

        $this->assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM estates'));
    }

    public function testSubscriptionsAreActiveMonthlyGrowth(): void
    {
        $rows = $this->db->select(
            'SELECT s.status, s.billing_cycle, p.code AS plan_code
             FROM subscriptions s
             INNER JOIN subscription_plans p ON p.id = s.plan_id
             INNER JOIN estates e ON e.id = s.estate_id'
        );

        $this->assertCount(2, $rows, 'Each demo estate must have one subscription.');

        foreach ($rows as $row) {
            $this->assertSame('active', (string) $row['status']);
            $this->assertSame('monthly', (string) $row['billing_cycle']);
            $this->assertSame('growth', (string) $row['plan_code']);
        }
    }

    public function testDemoUsersAndMemberships(): void
    {
        $this->assertSame(15, (int) $this->db->scalar('SELECT COUNT(*) FROM users'), 'Exactly 15 demo users must be seeded.');
        $this->assertSame(
            15,
            (int) $this->db->scalar("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'Every demo user must be active (contract §5).'
        );

        foreach (self::DEMO_USERS as $phone => [$fullName, $memberships]) {
            $user = $this->seededUser($phone);

            $this->assertSame($fullName, (string) $user['full_name'], 'Wrong full name for ' . $phone);
            $this->assertSame('active', (string) $user['status'], 'Demo user ' . $phone . ' must be active.');
            $this->assertTrue(Uuid::isValid((string) $user['uuid']), 'User uuid must be a UUID for ' . $phone);

            $rows = $this->db->select(
                'SELECT e.slug AS estate_slug, r.name AS role_name, em.status AS membership_status
                 FROM estate_memberships em
                 INNER JOIN estates e ON e.id = em.estate_id
                 INNER JOIN roles r ON r.id = em.role_id
                 WHERE em.user_id = :user_id
                 ORDER BY e.slug ASC',
                ['user_id' => (int) $user['id']]
            );

            $this->assertCount(count($memberships), $rows, 'Wrong membership count for ' . $phone);

            $actual = array_map(
                static fn (array $row): array => [(string) $row['estate_slug'], (string) $row['role_name']],
                $rows
            );
            $expected = $memberships;
            sort($actual);
            sort($expected);

            $this->assertSame($expected, $actual, 'Wrong estate/role memberships for ' . $phone);

            foreach ($rows as $row) {
                $this->assertSame('active', (string) $row['membership_status']);
            }
        }
    }

    public function testPlatformSuperAdminMembership(): void
    {
        $user = $this->seededUser('+254700000020');

        $this->assertSame('Daniel Mwangi', (string) $user['full_name']);

        $rows = $this->db->select(
            'SELECT r.name AS role_name, r.scope, pm.status
             FROM platform_memberships pm
             INNER JOIN roles r ON r.id = pm.role_id
             WHERE pm.user_id = :user_id',
            ['user_id' => (int) $user['id']]
        );

        $this->assertCount(1, $rows);
        $this->assertSame('platform_super_admin', (string) $rows[0]['role_name']);
        $this->assertSame('platform', (string) $rows[0]['scope']);
        $this->assertSame('active', (string) $rows[0]['status']);
    }

    public function testEstateStructureCounts(): void
    {
        $greenview = $this->seededEstate('greenview-residences');
        $riverside = $this->seededEstate('riverside-court');

        $this->assertSame(4, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM buildings WHERE estate_id = :id',
            ['id' => (int) $greenview['id']]
        ), 'Greenview must have 4 buildings: Block A, Block B, Willow Court, Acacia Street.');
        $this->assertSame(12, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM households WHERE estate_id = :id',
            ['id' => (int) $greenview['id']]
        ), 'Greenview must have 12 households.');
        $this->assertSame(1, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM buildings WHERE estate_id = :id',
            ['id' => (int) $riverside['id']]
        ), 'Riverside must have 1 building (Tower 1).');
        $this->assertSame(2, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM households WHERE estate_id = :id',
            ['id' => (int) $riverside['id']]
        ), 'Riverside must have 2 households.');
    }

    public function testGreenviewBuildingNames(): void
    {
        $greenview = $this->seededEstate('greenview-residences');
        $rows = $this->db->select(
            'SELECT name, building_type FROM buildings WHERE estate_id = :id ORDER BY name ASC',
            ['id' => (int) $greenview['id']]
        );

        $this->assertSame(
            [
                ['name' => 'Acacia Street', 'building_type' => 'street'],
                ['name' => 'Block A', 'building_type' => 'apartment_block'],
                ['name' => 'Block B', 'building_type' => 'apartment_block'],
                ['name' => 'Willow Court', 'building_type' => 'cluster'],
            ],
            $rows
        );
    }

    public function testGreenviewGates(): void
    {
        $greenview = $this->seededEstate('greenview-residences');
        $rows = $this->db->select(
            'SELECT name, location, status FROM gates WHERE estate_id = :id ORDER BY name ASC',
            ['id' => (int) $greenview['id']]
        );

        $this->assertCount(2, $rows);
        $this->assertSame('Main Gate', (string) $rows[0]['name']);
        $this->assertSame('Off Greenview Road', (string) $rows[0]['location']);
        $this->assertSame('Service Gate', (string) $rows[1]['name']);

        $riverside = $this->seededEstate('riverside-court');
        $this->assertSame('Riverside Gate', (string) $this->db->scalar(
            'SELECT name FROM gates WHERE estate_id = :id',
            ['id' => (int) $riverside['id']]
        ));

        $this->assertSame(3, (int) $this->db->scalar('SELECT COUNT(*) FROM gates'));
    }

    public function testGuardGateAssignments(): void
    {
        $expected = [
            '+254700000004' => 'Main Gate',
            '+254700000005' => 'Service Gate',
            '+254700000006' => 'Main Gate',
            '+254700000007' => 'Service Gate',
        ];

        foreach ($expected as $phone => $gateName) {
            $user = $this->seededUser($phone);

            $row = $this->db->selectOne(
                'SELECT g.name, gga.status
                 FROM guard_gate_assignments gga
                 INNER JOIN gates g ON g.id = gga.gate_id
                 WHERE gga.guard_user_id = :user_id',
                ['user_id' => (int) $user['id']]
            );

            $this->assertNotNull($row, 'Guard ' . $phone . ' must have a gate assignment.');
            $this->assertSame($gateName, (string) $row['name']);
            $this->assertSame('active', (string) $row['status']);
        }

        $this->assertSame(4, (int) $this->db->scalar('SELECT COUNT(*) FROM guard_gate_assignments'));
    }

    public function testEstateSettingsSeededAsJson(): void
    {
        $greenview = $this->seededEstate('greenview-residences');

        $rows = $this->db->select(
            'SELECT setting_key, setting_value FROM estate_settings WHERE estate_id = :id',
            ['id' => (int) $greenview['id']]
        );

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = json_decode((string) $row['setting_value'], true);
        }

        $this->assertSame(240, (int) ($settings['visitor_validity_minutes'] ?? -1));
        $this->assertSame(30, (int) ($settings['visitor_parking_grace_minutes'] ?? -1));
        $this->assertSame(300, (int) ($settings['walkin_approval_timeout_seconds'] ?? -1));
        $this->assertSame(5, (int) ($settings['max_visitors_per_invitation'] ?? -1));
    }

    public function testRoleInventoryAndPermissionCounts(): void
    {
        $this->assertSame(13, (int) $this->db->scalar('SELECT COUNT(*) FROM roles'), 'Exactly 13 roles must be seeded.');

        $rows = $this->db->select(
            'SELECT r.name, COUNT(rp.permission_id) AS permission_count
             FROM roles r
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             GROUP BY r.id, r.name'
        );

        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row['name']] = (int) $row['permission_count'];
        }

        ksort($actual);
        $expected = self::ROLE_PERMISSION_COUNTS;
        ksort($expected);

        $this->assertSame($expected, $actual, 'Role → permission mapping does not match contract §5.');
    }

    public function testPermissionCatalogue(): void
    {
        $this->assertSame(52, (int) $this->db->scalar('SELECT COUNT(*) FROM permissions'), 'Exactly 52 permissions must be seeded.');
        $this->assertSame(
            5,
            (int) $this->db->scalar("SELECT COUNT(*) FROM permissions WHERE scope = 'platform'"),
            'Exactly five platform-scope permissions.'
        );
        $this->assertSame(
            47,
            (int) $this->db->scalar("SELECT COUNT(*) FROM permissions WHERE scope = 'estate'"),
            'Exactly 47 estate-scope permissions.'
        );

        $slugs = array_map(
            static fn (array $row): string => (string) $row['slug'],
            $this->db->select("SELECT slug FROM permissions WHERE slug LIKE 'platform.%' ORDER BY slug ASC")
        );

        $this->assertSame(
            [
                'platform.audit.view',
                'platform.estate.manage',
                'platform.plan.manage',
                'platform.subscription.manage',
                'platform.support.manage',
            ],
            $slugs
        );

        foreach (['estate.view', 'audit.view', 'visitor.checkin', 'parking.session.checkin', 'billing.payment.manage', 'notice.view', 'notice.manage'] as $slug) {
            $this->assertSame(1, (int) $this->db->scalar(
                'SELECT COUNT(*) FROM permissions WHERE slug = :slug',
                ['slug' => $slug]
            ), 'Missing permission: ' . $slug);
        }
    }

    public function testVisitorDemoFixtures(): void
    {
        // visitor-access-module-contract §2 seed inventory.
        $this->assertSame(4, (int) $this->db->scalar('SELECT COUNT(*) FROM visitors'), 'Four demo visitors are seeded.');
        $this->assertSame(4, (int) $this->db->scalar('SELECT COUNT(*) FROM visitor_invitations'), 'Four demo invitations are seeded.');
        $this->assertSame(8, (int) $this->db->scalar('SELECT COUNT(*) FROM access_credentials'), 'PIN + QR credentials for each demo invitation.');
        $this->assertSame(5, (int) $this->db->scalar('SELECT COUNT(*) FROM visitor_access_events'), 'Five demo access events are seeded.');
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM walk_in_requests'), 'One pending demo walk-in is seeded.');

        $greenview = $this->seededEstate('greenview-residences');
        $jane = $this->seededUser('+254700000010');

        $upcoming = $this->db->selectOne(
            'SELECT vi.status, vi.valid_from, vi.valid_until, vi.host_user_id, vi.household_id, v.name, v.phone
             FROM visitor_invitations vi
             INNER JOIN visitors v ON v.id = vi.visitor_id
             WHERE vi.uuid = :uuid',
            ['uuid' => DemoVisitorSeeder::INVITATION_UPCOMING_UUID]
        );
        $this->assertNotNull($upcoming);
        $this->assertSame('approved', (string) $upcoming['status']);
        $this->assertSame('Demo Visitor Three', (string) $upcoming['name']);
        $this->assertSame('+254700090003', (string) $upcoming['phone']);
        $this->assertSame((int) $jane['id'], (int) $upcoming['host_user_id']);
        $this->assertSame((int) $greenview['id'], (int) $this->db->scalar(
            'SELECT estate_id FROM visitor_invitations WHERE uuid = :uuid',
            ['uuid' => DemoVisitorSeeder::INVITATION_UPCOMING_UUID]
        ));

        // The documented demo PIN is a REAL credential hash, not a stored secret.
        $pinCredential = $this->db->selectOne(
            'SELECT credential_hash, encrypted_secret, status, max_uses FROM access_credentials WHERE uuid = :uuid',
            ['uuid' => DemoVisitorSeeder::CREDENTIAL_UPCOMING_PIN_UUID]
        );
        $this->assertNotNull($pinCredential);
        $this->assertSame('active', (string) $pinCredential['status']);
        $this->assertNull($pinCredential['encrypted_secret'], 'PIN credentials are never recoverable.');
        $this->assertSame(
            Crypto::hmac('access-pin:' . DemoVisitorSeeder::CREDENTIAL_UPCOMING_PIN_UUID, DemoVisitorSeeder::DEMO_PIN),
            (string) $pinCredential['credential_hash']
        );

        // The QR token is encrypted (re-displayable) and never stored plaintext.
        $qrCredential = $this->db->selectOne(
            'SELECT encrypted_secret FROM access_credentials WHERE uuid = :uuid',
            ['uuid' => DemoVisitorSeeder::CREDENTIAL_UPCOMING_QR_UUID]
        );
        $this->assertNotNull($qrCredential);
        $token = Crypto::decrypt((string) $qrCredential['encrypted_secret'], 'access-qr:' . DemoVisitorSeeder::CREDENTIAL_UPCOMING_QR_UUID);
        $this->assertNotNull($token);
        $this->assertMatches('/^[0-9a-f]{64}$/', (string) $token);

        // Two completed history visits + one checked-in visit for the guard dashboard.
        $statuses = [];
        foreach ($this->db->select('SELECT uuid, status FROM visitor_invitations') as $row) {
            $statuses[(string) $row['uuid']] = (string) $row['status'];
        }
        $this->assertSame('checked_out', $statuses[DemoVisitorSeeder::INVITATION_HISTORY_ONE_UUID]);
        $this->assertSame('checked_out', $statuses[DemoVisitorSeeder::INVITATION_HISTORY_TWO_UUID]);
        $this->assertSame('checked_in', $statuses[DemoVisitorSeeder::INVITATION_INSIDE_UUID]);

        // The pending walk-in is at the Main Gate for Jane's household.
        $walkIn = $this->db->selectOne(
            'SELECT w.status, w.visitor_name, g.name AS gate_name, h.unit_number
             FROM walk_in_requests w
             INNER JOIN gates g ON g.id = w.gate_id
             INNER JOIN households h ON h.id = w.household_id
             WHERE w.uuid = :uuid',
            ['uuid' => DemoVisitorSeeder::WALKIN_PENDING_UUID]
        );
        $this->assertNotNull($walkIn);
        $this->assertSame('pending', (string) $walkIn['status']);
        $this->assertSame('Demo Walk-in Four', (string) $walkIn['visitor_name']);
        $this->assertSame('Main Gate', (string) $walkIn['gate_name']);
        $this->assertSame('A-001', (string) $walkIn['unit_number']);

        // Nothing in the demo seed exposes a plaintext PIN in the database.
        $hits = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE metadata LIKE :pin",
            ['pin' => '%' . DemoVisitorSeeder::DEMO_PIN . '%']
        );
        $this->assertSame(0, $hits);
    }

    public function testParkingDemoFixtures(): void
    {
        // vehicles-parking-contract §1: Greenview zones/bays/vehicles/allocations
        // plus one reserved visitor reservation and one completed history session.
        $greenview = $this->seededEstate('greenview-residences');
        $estateId = (int) $greenview['id'];

        $zones = [];
        foreach ($this->db->select('SELECT name, zone_type FROM parking_zones WHERE estate_id = :id', ['id' => $estateId]) as $row) {
            $zones[(string) $row['name']] = (string) $row['zone_type'];
        }
        $this->assertSame(['Resident Zone A' => 'resident', 'Visitor Zone V' => 'visitor'], $zones);

        $this->assertSame(8, (int) $this->db->scalar("SELECT COUNT(*) FROM parking_bays WHERE estate_id = :id AND bay_code LIKE 'R-%'", ['id' => $estateId]));
        $this->assertSame(6, (int) $this->db->scalar("SELECT COUNT(*) FROM parking_bays WHERE estate_id = :id AND bay_code LIKE 'V-%'", ['id' => $estateId]));

        $bays = [];
        foreach ($this->db->select('SELECT bay_code, bay_type, status FROM parking_bays WHERE estate_id = :id', ['id' => $estateId]) as $row) {
            $bays[(string) $row['bay_code']] = $row;
        }

        $this->assertSame('available', (string) $bays['V-01']['status'], 'Bay V-01 is physically available; availability excludes it via the reservation overlap.');
        $this->assertSame('accessible', (string) $bays['V-05']['bay_type']);
        $this->assertSame('maintenance', (string) $bays['V-06']['status'], 'V-06 must be under maintenance.');
        $this->assertSame('resident', (string) $bays['R-01']['bay_type']);

        $vehicles = [];
        foreach ($this->db->select(
            'SELECT v.plate_number, v.vehicle_type, v.colour, v.status, h.unit_number
             FROM vehicles v INNER JOIN households h ON h.id = v.household_id
             WHERE v.estate_id = :id',
            ['id' => $estateId]
        ) as $row) {
            $vehicles[(string) $row['plate_number']] = $row;
        }

        $this->assertSame(['KDA123A', 'KCA456B', 'KDD789C'], array_keys($vehicles));
        $this->assertSame('A-001', (string) $vehicles['KDA123A']['unit_number']);
        $this->assertSame('car', (string) $vehicles['KDA123A']['vehicle_type']);
        $this->assertSame('White', (string) $vehicles['KDA123A']['colour']);
        $this->assertSame('van', (string) $vehicles['KCA456B']['vehicle_type']);
        $this->assertSame('Grey', (string) $vehicles['KCA456B']['colour']);
        $this->assertSame('active', (string) $vehicles['KDD789C']['status']);

        $allocations = $this->db->select(
            'SELECT a.status, b.bay_code, h.unit_number, v.plate_number
             FROM parking_allocations a
             INNER JOIN parking_bays b ON b.id = a.bay_id
             INNER JOIN households h ON h.id = a.household_id
             LEFT JOIN vehicles v ON v.id = a.vehicle_id
             WHERE a.estate_id = :id ORDER BY b.bay_code ASC',
            ['id' => $estateId]
        );

        $this->assertCount(3, $allocations);
        $expectedAllocations = [
            ['R-01', 'A-001', 'KDA123A'],
            ['R-02', 'A-002', 'KCA456B'],
            ['R-03', 'B-001', 'KDD789C'],
        ];

        foreach ($expectedAllocations as $index => [$bayCode, $unit, $plate]) {
            $row = $allocations[$index];
            $this->assertSame($bayCode, (string) $row['bay_code']);
            $this->assertSame($unit, (string) $row['unit_number']);
            $this->assertSame($plate, (string) $row['plate_number']);
            $this->assertSame('active', (string) $row['status']);
        }

        // Reserved demo visitor reservation: V-01, upcoming invitation window.
        $reservation = $this->db->selectOne(
            'SELECT r.status, r.starts_at, r.ends_at, b.bay_code, i.uuid AS invitation_uuid, i.valid_from, i.valid_until
             FROM parking_reservations r
             INNER JOIN parking_bays b ON b.id = r.bay_id
             INNER JOIN visitor_invitations i ON i.id = r.invitation_id
             WHERE r.uuid = :uuid',
            ['uuid' => DemoParkingSeeder::RESERVATION_UPCOMING_UUID]
        );
        $this->assertNotNull($reservation);
        $this->assertSame('reserved', (string) $reservation['status']);
        $this->assertSame('V-01', (string) $reservation['bay_code']);
        $this->assertSame(DemoVisitorSeeder::INVITATION_UPCOMING_UUID, (string) $reservation['invitation_uuid']);
        $this->assertSame((string) $reservation['valid_from'], (string) $reservation['starts_at'], 'The reservation window must match the invitation window.');
        $this->assertSame((string) $reservation['valid_until'], (string) $reservation['ends_at']);

        // Completed history session on V-02 for a history invitation.
        $session = $this->db->selectOne(
            'SELECT s.status, s.checkin_at, s.checkout_at, b.bay_code, i.uuid AS invitation_uuid
             FROM parking_sessions s
             INNER JOIN parking_bays b ON b.id = s.bay_id
             LEFT JOIN visitor_invitations i ON i.id = s.invitation_id
             WHERE s.uuid = :uuid',
            ['uuid' => DemoParkingSeeder::SESSION_HISTORY_UUID]
        );
        $this->assertNotNull($session);
        $this->assertSame('completed', (string) $session['status']);
        $this->assertSame('V-02', (string) $session['bay_code']);
        $this->assertSame(DemoVisitorSeeder::INVITATION_HISTORY_ONE_UUID, (string) $session['invitation_uuid']);
        $this->assertNotEmpty($session['checkin_at']);
        $this->assertNotEmpty($session['checkout_at']);
        $this->assertTrue(strtotime((string) $session['checkout_at']) > strtotime((string) $session['checkin_at']));

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM parking_violations'));
    }

    public function testStaffAndIncidentDemoFixtures(): void
    {
        $greenview = $this->seededEstate('greenview-residences');
        $estateId = (int) $greenview['id'];

        $this->assertSame(3, (int) $this->db->scalar('SELECT COUNT(*) FROM household_staff WHERE estate_id = :id', ['id' => $estateId]));
        $this->assertSame(3, (int) $this->db->scalar('SELECT COUNT(*) FROM access_incidents WHERE estate_id = :id', ['id' => $estateId]));

        $staff = [];
        foreach ($this->db->select(
            'SELECT s.full_name, s.phone, s.staff_type, s.status, s.notes, h.unit_number
             FROM household_staff s INNER JOIN households h ON h.id = s.household_id
             WHERE s.estate_id = :id ORDER BY s.id ASC',
            ['id' => $estateId]
        ) as $row) {
            $staff[(string) $row['full_name']] = $row;
        }

        $this->assertSame(['Demo Housekeeper', 'Demo Gardener', 'Demo Driver'], array_keys($staff));
        $this->assertSame('A-001', (string) $staff['Demo Housekeeper']['unit_number']);
        $this->assertSame('domestic', (string) $staff['Demo Housekeeper']['staff_type']);
        $this->assertSame('+254700091001', (string) $staff['Demo Housekeeper']['phone']);
        $this->assertSame('A-002', (string) $staff['Demo Gardener']['unit_number']);
        $this->assertSame('gardener', (string) $staff['Demo Gardener']['staff_type']);
        $this->assertSame('B-001', (string) $staff['Demo Driver']['unit_number']);
        $this->assertSame('driver', (string) $staff['Demo Driver']['staff_type']);
        $this->assertSame('active', (string) $staff['Demo Driver']['status']);

        $incidents = [];
        foreach ($this->db->select(
            'SELECT i.incident_type, i.severity, i.status, i.summary, i.description, i.resolved_at, i.resolution_notes,
                    g.name AS gate_name, h.unit_number, u.phone AS reporter_phone, i.invitation_id
             FROM access_incidents i
             LEFT JOIN gates g ON g.id = i.gate_id
             LEFT JOIN households h ON h.id = i.household_id
             LEFT JOIN users u ON u.id = i.reported_by_user_id
             WHERE i.estate_id = :id ORDER BY i.id ASC',
            ['id' => $estateId]
        ) as $row) {
            $incidents[(string) $row['incident_type']] = $row;
        }

        $this->assertCount(3, $incidents);

        $suspicious = $incidents['suspicious_activity'];
        $this->assertSame('open', (string) $suspicious['status']);
        $this->assertSame('medium', (string) $suspicious['severity']);
        $this->assertSame('Main Gate', (string) $suspicious['gate_name']);
        $this->assertSame('+254700000004', (string) $suspicious['reporter_phone']);

        $unauthorised = $incidents['unauthorised_access'];
        $this->assertSame('investigating', (string) $unauthorised['status']);
        $this->assertSame('high', (string) $unauthorised['severity']);
        $this->assertNotNull($unauthorised['invitation_id'], 'The unauthorised-access demo incident is linked to a history visit.');
        $this->assertSame('A-001', (string) $unauthorised['unit_number']);

        $lostPass = $incidents['lost_pass'];
        $this->assertSame('resolved', (string) $lostPass['status']);
        $this->assertSame('low', (string) $lostPass['severity']);
        $this->assertNotEmpty($lostPass['resolved_at']);
        $this->assertNotEmpty($lostPass['resolution_notes']);
        $this->assertSame('A-001', (string) $lostPass['unit_number']);

        // No hard-delete residue: the fixture rows are all still present.
        $this->assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM household_staff WHERE status NOT IN ('active','inactive')"));
    }
    public function testEstateNoticeDemoFixtures(): void
    {
        // estate-notices-contract ?1: 4 Greenview demo notices, 0 Riverside.
        $greenview = $this->seededEstate('greenview-residences');
        $riverside = $this->seededEstate('riverside-court');
        $estateId = (int) $greenview['id'];

        $this->assertSame(4, (int) $this->db->scalar('SELECT COUNT(*) FROM estate_notices WHERE estate_id = :id', ['id' => $estateId]));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM estate_notices WHERE estate_id = :id', ['id' => (int) $riverside['id']]), 'Riverside has no demo notices (empty-state demo).');

        $notices = [];
        foreach ($this->db->select(
            'SELECT uuid, title, body, category, priority, status, audience, published_at, expires_at
             FROM estate_notices WHERE estate_id = :id ORDER BY id ASC',
            ['id' => $estateId]
        ) as $row) {
            $notices[(string) $row['uuid']] = $row;
        }

        $this->assertCount(4, $notices);

        $water = $notices[DemoNoticeSeeder::NOTICE_WATER_UUID];
        $this->assertSame('published', (string) $water['status']);
        $this->assertSame('general', (string) $water['category']);
        $this->assertSame('normal', (string) $water['priority']);
        $this->assertSame('all', (string) $water['audience']);
        $this->assertNotEmpty($water['published_at']);

        $barrier = $notices[DemoNoticeSeeder::NOTICE_BARRIER_UUID];
        $this->assertSame('published', (string) $barrier['status']);
        $this->assertSame('security', (string) $barrier['category']);
        $this->assertSame('important', (string) $barrier['priority']);

        $lift = $notices[DemoNoticeSeeder::NOTICE_LIFT_UUID];
        $this->assertSame('published', (string) $lift['status']);
        $this->assertSame('emergency', (string) $lift['category']);
        $this->assertSame('urgent', (string) $lift['priority']);
        $this->assertNotEmpty($lift['expires_at'], 'The emergency lift notice expires in 7 days.');
        $published = strtotime((string) $lift['published_at']);
        $expires = strtotime((string) $lift['expires_at']);
        $this->assertTrue($expires > $published, 'The demo emergency notice expiry must follow its publish time.');
        $this->assertTrue($expires - $published >= 6 * 86400, 'The demo notice expiry is roughly a week after publishing.');

        $cleanup = $notices[DemoNoticeSeeder::NOTICE_CLEANUP_UUID];
        $this->assertSame('draft', (string) $cleanup['status']);
        $this->assertNull($cleanup['published_at'], 'A draft notice has no publish time.');
        $this->assertNull($cleanup['expires_at']);
    }
    public function testSeedingIsIdempotent(): void
    {
        $tables = $this->baseTables();
        $before = $this->tableCounts($tables);

        TestDatabase::seed();

        $after = $this->tableCounts($tables);

        $this->assertSame($before, $after, 'Re-running the seeder must not add or remove any rows.');

        TestDatabase::seed();

        $this->assertSame($before, $this->tableCounts($tables), 'Re-seeding a third time must still be a no-op.');
    }

    /**
     * @return array<int, string>
     */
    private function baseTables(): array
    {
        $rows = $this->db->select(
            "SELECT table_name AS name FROM information_schema.tables
             WHERE table_schema = :database AND table_type = 'BASE TABLE' ORDER BY table_name ASC",
            ['database' => $this->db->databaseName()]
        );

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * @param array<int, string> $tables
     * @return array<string, int>
     */
    private function tableCounts(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            if (preg_match('/^[a-z0-9_]+$/', $table) !== 1) {
                continue;
            }

            $counts[$table] = (int) $this->db->scalar('SELECT COUNT(*) FROM `' . $table . '`');
        }

        return $counts;
    }
}
