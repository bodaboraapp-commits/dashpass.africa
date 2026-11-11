<?php

declare(strict_types=1);

namespace Database\Seeds;

/**
 * Seeds the demo estate environment exactly as specified in contract §5.
 *
 * The fictional Nairobi development is deliberately reproducible and clearly
 * demo-labelled: one SaaS account (Greenview Holdings Ltd), two estates
 * (Greenview Residences and Riverside Court), the full role cast, households,
 * gates and guard assignments. Every row is matched by a natural key so the
 * seeder can run on every install/test boot without duplicating data.
 *
 * David Njoroge (+254700000014) is the cross-tenant test account: he is an
 * estate administrator at Riverside Court only. Daniel Mwangi (+254700000020)
 * is the platform super administrator.
 */
final class DemoSeeder extends Seeder
{
    private const SAAS_ACCOUNT = 'Greenview Holdings Ltd';

    private const TIMEZONE = 'Africa/Nairobi';

    /** @var array<int, string> */
    public const STAT_KEYS = [
        'accounts_created',
        'plans_created',
        'estates_created',
        'settings_created',
        'settings_updated',
        'buildings_created',
        'households_created',
        'users_created',
        'users_updated',
        'estate_memberships_created',
        'estate_memberships_updated',
        'platform_memberships_created',
        'household_members_created',
        'household_members_updated',
        'gates_created',
        'guard_assignments_created',
        'subscriptions_created',
    ];

    /**
     * Subscription plans. Code => definition.
     *
     * @var array<string, array{name: string, price: int, households: int, devices: int}>
     */
    private const PLANS = [
        'starter' => ['name' => 'Starter', 'price' => 250000, 'households' => 50, 'devices' => 2],
        'growth' => ['name' => 'Growth', 'price' => 500000, 'households' => 150, 'devices' => 5],
        'premium' => ['name' => 'Premium', 'price' => 900000, 'households' => 300, 'devices' => 10],
    ];

    /**
     * Estates. Slug => definition.
     *
     * @var array<string, array{name: string, type: string}>
     */
    private const ESTATES = [
        'greenview-residences' => ['name' => 'Greenview Residences', 'type' => 'mixed'],
        'riverside-court' => ['name' => 'Riverside Court', 'type' => 'apartment'],
    ];

    /**
     * Greenview estate settings (decoded values are exposed by the API).
     *
     * @var array<string, int>
     */
    private const GREENVIEW_SETTINGS = [
        'visitor_validity_minutes' => 240,
        'visitor_parking_grace_minutes' => 30,
        'parking_overstay_grace_minutes' => 30,
        'walkin_approval_timeout_seconds' => 300,
        'max_visitors_per_invitation' => 5,
    ];

    /**
     * @var array<int, array{estate: string, name: string, type: string}>
     */
    private const BUILDINGS = [
        ['estate' => 'greenview-residences', 'name' => 'Block A', 'type' => 'apartment_block'],
        ['estate' => 'greenview-residences', 'name' => 'Block B', 'type' => 'apartment_block'],
        ['estate' => 'greenview-residences', 'name' => 'Willow Court', 'type' => 'cluster'],
        ['estate' => 'greenview-residences', 'name' => 'Acacia Street', 'type' => 'street'],
        ['estate' => 'riverside-court', 'name' => 'Tower 1', 'type' => 'apartment_block'],
    ];

    /**
     * @var array<int, array{estate: string, unit: string, building: string, type: string}>
     */
    private const HOUSEHOLDS = [
        ['estate' => 'greenview-residences', 'unit' => 'A-001', 'building' => 'Block A', 'type' => 'apartment'],
        ['estate' => 'greenview-residences', 'unit' => 'A-002', 'building' => 'Block A', 'type' => 'apartment'],
        ['estate' => 'greenview-residences', 'unit' => 'A-003', 'building' => 'Block A', 'type' => 'apartment'],
        ['estate' => 'greenview-residences', 'unit' => 'A-004', 'building' => 'Block A', 'type' => 'apartment'],
        ['estate' => 'greenview-residences', 'unit' => 'A-005', 'building' => 'Block A', 'type' => 'apartment'],
        ['estate' => 'greenview-residences', 'unit' => 'A-006', 'building' => 'Block A', 'type' => 'apartment'],
        ['estate' => 'greenview-residences', 'unit' => 'B-001', 'building' => 'Block B', 'type' => 'apartment'],
        ['estate' => 'greenview-residences', 'unit' => 'B-002', 'building' => 'Block B', 'type' => 'apartment'],
        ['estate' => 'greenview-residences', 'unit' => 'B-003', 'building' => 'Block B', 'type' => 'apartment'],
        ['estate' => 'greenview-residences', 'unit' => 'W-01', 'building' => 'Willow Court', 'type' => 'maisonette'],
        ['estate' => 'greenview-residences', 'unit' => 'W-02', 'building' => 'Willow Court', 'type' => 'maisonette'],
        ['estate' => 'greenview-residences', 'unit' => 'S-01', 'building' => 'Acacia Street', 'type' => 'maisonette'],
        ['estate' => 'riverside-court', 'unit' => 'R-101', 'building' => 'Tower 1', 'type' => 'apartment'],
        ['estate' => 'riverside-court', 'unit' => 'R-102', 'building' => 'Tower 1', 'type' => 'apartment'],
    ];

    /**
     * Demo users: phone (E.164) => full name. All are active.
     *
     * @var array<string, string>
     */
    private const USERS = [
        '+254700000001' => 'Grace Wanjiku',
        '+254700000002' => 'Peter Ochieng',
        '+254700000003' => 'Samuel Kariuki',
        '+254700000004' => 'John Mutiso',
        '+254700000005' => 'David Kiptoo',
        '+254700000006' => 'Faith Achieng',
        '+254700000007' => 'Brian Ochieng',
        '+254700000008' => 'Mary Atieno',
        '+254700000009' => 'Lucy Wambui',
        '+254700000010' => 'Jane Smith',
        '+254700000011' => 'Brian Otieno',
        '+254700000012' => 'Lydia Njeri',
        '+254700000013' => 'Amina Hassan',
        '+254700000014' => 'David Njoroge',
        '+254700000020' => 'Daniel Mwangi',
    ];

    /**
     * Estate-scoped memberships. Grace Wanjiku is the estate-switcher demo
     * account: estate_admin at both Greenview and Riverside.
     *
     * @var array<int, array{phone: string, estate: string, role: string}>
     */
    private const ESTATE_MEMBERSHIPS = [
        ['phone' => '+254700000001', 'estate' => 'greenview-residences', 'role' => 'estate_admin'],
        ['phone' => '+254700000001', 'estate' => 'riverside-court', 'role' => 'estate_admin'],
        ['phone' => '+254700000002', 'estate' => 'greenview-residences', 'role' => 'estate_manager'],
        ['phone' => '+254700000003', 'estate' => 'greenview-residences', 'role' => 'security_supervisor'],
        ['phone' => '+254700000004', 'estate' => 'greenview-residences', 'role' => 'security_guard'],
        ['phone' => '+254700000005', 'estate' => 'greenview-residences', 'role' => 'security_guard'],
        ['phone' => '+254700000006', 'estate' => 'greenview-residences', 'role' => 'security_guard'],
        ['phone' => '+254700000007', 'estate' => 'greenview-residences', 'role' => 'security_guard'],
        ['phone' => '+254700000008', 'estate' => 'greenview-residences', 'role' => 'parking_manager'],
        ['phone' => '+254700000009', 'estate' => 'greenview-residences', 'role' => 'auditor'],
        ['phone' => '+254700000010', 'estate' => 'greenview-residences', 'role' => 'resident'],
        ['phone' => '+254700000011', 'estate' => 'greenview-residences', 'role' => 'tenant'],
        ['phone' => '+254700000012', 'estate' => 'greenview-residences', 'role' => 'household_member'],
        ['phone' => '+254700000013', 'estate' => 'greenview-residences', 'role' => 'resident'],
        ['phone' => '+254700000014', 'estate' => 'riverside-court', 'role' => 'estate_admin'],
    ];

    /**
     * Platform-scope memberships.
     *
     * @var array<int, array{phone: string, role: string}>
     */
    private const PLATFORM_MEMBERSHIPS = [
        ['phone' => '+254700000020', 'role' => 'platform_super_admin'],
    ];

    /**
     * @var array<int, array{phone: string, estate: string, unit: string, relationship: string, primary: int}>
     */
    private const HOUSEHOLD_MEMBERS = [
        ['phone' => '+254700000010', 'estate' => 'greenview-residences', 'unit' => 'A-001', 'relationship' => 'owner', 'primary' => 1],
        ['phone' => '+254700000011', 'estate' => 'greenview-residences', 'unit' => 'A-002', 'relationship' => 'tenant', 'primary' => 1],
        ['phone' => '+254700000012', 'estate' => 'greenview-residences', 'unit' => 'A-002', 'relationship' => 'family_member', 'primary' => 0],
        ['phone' => '+254700000013', 'estate' => 'greenview-residences', 'unit' => 'B-001', 'relationship' => 'owner', 'primary' => 1],
    ];

    /**
     * @var array<int, array{estate: string, name: string, location: ?string}>
     */
    private const GATES = [
        ['estate' => 'greenview-residences', 'name' => 'Main Gate', 'location' => 'Off Greenview Road'],
        ['estate' => 'greenview-residences', 'name' => 'Service Gate', 'location' => null],
        ['estate' => 'riverside-court', 'name' => 'Riverside Gate', 'location' => null],
    ];

    /**
     * @var array<int, array{estate: string, gate: string, phone: string, shift: string}>
     */
    private const GUARD_ASSIGNMENTS = [
        ['estate' => 'greenview-residences', 'gate' => 'Main Gate', 'phone' => '+254700000004', 'shift' => 'day'],
        ['estate' => 'greenview-residences', 'gate' => 'Main Gate', 'phone' => '+254700000006', 'shift' => 'day'],
        ['estate' => 'greenview-residences', 'gate' => 'Service Gate', 'phone' => '+254700000005', 'shift' => 'day'],
        ['estate' => 'greenview-residences', 'gate' => 'Service Gate', 'phone' => '+254700000007', 'shift' => 'day'],
    ];

    /**
     * Every estate gets the active monthly Growth subscription.
     */
    private const SUBSCRIPTION_PLAN_CODE = 'growth';

    /** @var array<string, int> */
    private array $roleIdCache = [];

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $this->resetCounters(self::STAT_KEYS);
        $this->roleIdCache = [];

        $accountId = $this->ensureAccount();
        $planIds = $this->ensurePlans();
        $estateIds = $this->ensureEstates($accountId);
        $this->ensureEstateSettings($estateIds);
        $buildingIds = $this->ensureBuildings($estateIds);
        $householdIds = $this->ensureHouseholds($estateIds, $buildingIds);
        $userIds = $this->ensureUsers();
        $this->ensureEstateMemberships($estateIds, $userIds);
        $this->ensurePlatformMemberships($userIds);
        $this->ensureHouseholdMembers($householdIds, $userIds);
        $gateIds = $this->ensureGates($estateIds);
        $this->ensureGuardAssignments($gateIds, $userIds);
        $this->ensureSubscriptions($estateIds, $planIds);

        return $this->counters();
    }

    private function ensureAccount(): int
    {
        $accountId = $this->findId('saas_accounts', ['name' => self::SAAS_ACCOUNT]);

        if ($accountId !== null) {
            return $accountId;
        }

        $accountId = $this->insert('saas_accounts', [
            'uuid' => $this->uuid(),
            'name' => self::SAAS_ACCOUNT,
            'status' => 'active',
        ]);
        $this->count('accounts_created');

        return $accountId;
    }

    /**
     * @return array<string, int>
     */
    private function ensurePlans(): array
    {
        $planIds = [];

        foreach (self::PLANS as $code => $plan) {
            $planId = $this->findId('subscription_plans', ['code' => $code]);

            if ($planId === null) {
                $planId = $this->insert('subscription_plans', [
                    'uuid' => $this->uuid(),
                    'code' => $code,
                    'name' => $plan['name'],
                    'monthly_price_cents' => $plan['price'],
                    'currency' => 'KES',
                    'household_limit' => $plan['households'],
                    'gate_device_limit' => $plan['devices'],
                    'status' => 'active',
                ]);
                $this->count('plans_created');
            }

            $planIds[$code] = $planId;
        }

        return $planIds;
    }

    /**
     * @return array<string, int>
     */
    private function ensureEstates(int $accountId): array
    {
        $estateIds = [];

        foreach (self::ESTATES as $slug => $estate) {
            $estateId = $this->findId('estates', ['slug' => $slug]);

            if ($estateId === null) {
                $estateId = $this->insert('estates', [
                    'uuid' => $this->uuid(),
                    'account_id' => $accountId,
                    'name' => $estate['name'],
                    'slug' => $slug,
                    'timezone' => self::TIMEZONE,
                    'estate_type' => $estate['type'],
                    'status' => 'active',
                ]);
                $this->count('estates_created');
            }

            $estateIds[$slug] = $estateId;
        }

        return $estateIds;
    }

    /**
     * @param array<string, int> $estateIds
     */
    private function ensureEstateSettings(array $estateIds): void
    {
        $estateId = $this->estateId($estateIds, 'greenview-residences');

        foreach (self::GREENVIEW_SETTINGS as $key => $value) {
            $settingId = $this->findId('estate_settings', [
                'estate_id' => $estateId,
                'setting_key' => $key,
            ]);

            if ($settingId === null) {
                $this->insert('estate_settings', [
                    'estate_id' => $estateId,
                    'setting_key' => $key,
                    'setting_value' => $this->json($value),
                ]);
                $this->count('settings_created');

                continue;
            }

            $current = $this->db->selectOne(
                'SELECT setting_value FROM estate_settings WHERE id = :id LIMIT 1',
                ['id' => $settingId]
            );

            if ($this->decodeJson($current['setting_value'] ?? null) !== $value) {
                $this->update(
                    'estate_settings',
                    ['setting_value' => $this->json($value)],
                    ['id' => $settingId]
                );
                $this->count('settings_updated');
            }
        }
    }

    /**
     * @param array<string, int> $estateIds
     * @return array<string, array<string, int>> estate slug => building name => id
     */
    private function ensureBuildings(array $estateIds): array
    {
        $ids = [];

        foreach (self::BUILDINGS as $building) {
            $estateId = $this->estateId($estateIds, $building['estate']);
            $buildingId = $this->findId('buildings', [
                'estate_id' => $estateId,
                'name' => $building['name'],
            ]);

            if ($buildingId === null) {
                $buildingId = $this->insert('buildings', [
                    'uuid' => $this->uuid(),
                    'estate_id' => $estateId,
                    'name' => $building['name'],
                    'building_type' => $building['type'],
                ]);
                $this->count('buildings_created');
            }

            $ids[$building['estate']][$building['name']] = $buildingId;
        }

        return $ids;
    }

    /**
     * @param array<string, int> $estateIds
     * @param array<string, array<string, int>> $buildingIds
     * @return array<string, array<string, int>> estate slug => unit number => id
     */
    private function ensureHouseholds(array $estateIds, array $buildingIds): array
    {
        $ids = [];

        foreach (self::HOUSEHOLDS as $household) {
            $estateId = $this->estateId($estateIds, $household['estate']);
            $buildingId = $buildingIds[$household['estate']][$household['building']] ?? null;

            if ($buildingId === null) {
                throw new \RuntimeException('Demo household references missing building: ' . $household['building']);
            }

            $householdId = $this->findId('households', [
                'estate_id' => $estateId,
                'unit_number' => $household['unit'],
            ]);

            if ($householdId === null) {
                $householdId = $this->insert('households', [
                    'uuid' => $this->uuid(),
                    'estate_id' => $estateId,
                    'building_id' => $buildingId,
                    'unit_number' => $household['unit'],
                    'household_type' => $household['type'],
                    'status' => 'active',
                ]);
                $this->count('households_created');
            }

            $ids[$household['estate']][$household['unit']] = $householdId;
        }

        return $ids;
    }

    /**
     * @return array<string, int> phone => user id
     */
    private function ensureUsers(): array
    {
        $ids = [];

        foreach (self::USERS as $phone => $fullName) {
            $user = $this->db->selectOne(
                'SELECT id, full_name, status FROM users WHERE phone = :phone LIMIT 1',
                ['phone' => $phone]
            );

            if ($user === null) {
                $ids[$phone] = $this->insert('users', [
                    'uuid' => $this->uuid(),
                    'full_name' => $fullName,
                    'phone' => $phone,
                    'status' => 'active',
                ]);
                $this->count('users_created');

                continue;
            }

            $userId = (int) $user['id'];
            $ids[$phone] = $userId;

            if ((string) $user['full_name'] !== $fullName || (string) $user['status'] !== 'active') {
                $this->update(
                    'users',
                    ['full_name' => $fullName, 'status' => 'active'],
                    ['id' => $userId]
                );
                $this->count('users_updated');
            }
        }

        return $ids;
    }

    /**
     * @param array<string, int> $estateIds
     * @param array<string, int> $userIds
     */
    private function ensureEstateMemberships(array $estateIds, array $userIds): void
    {
        foreach (self::ESTATE_MEMBERSHIPS as $membership) {
            $estateId = $this->estateId($estateIds, $membership['estate']);
            $userId = $this->userId($userIds, $membership['phone']);
            $roleId = $this->roleId($membership['role'], 'estate');

            $existing = $this->db->selectOne(
                'SELECT id, role_id, status FROM estate_memberships
                 WHERE estate_id = :estate_id AND user_id = :user_id LIMIT 1',
                ['estate_id' => $estateId, 'user_id' => $userId]
            );

            if ($existing === null) {
                $this->insert('estate_memberships', [
                    'uuid' => $this->uuid(),
                    'estate_id' => $estateId,
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'status' => 'active',
                ]);
                $this->count('estate_memberships_created');

                continue;
            }

            if ((int) $existing['role_id'] !== $roleId || (string) $existing['status'] !== 'active') {
                $this->update(
                    'estate_memberships',
                    ['role_id' => $roleId, 'status' => 'active'],
                    ['id' => (int) $existing['id']]
                );
                $this->count('estate_memberships_updated');
            }
        }
    }

    /**
     * @param array<string, int> $userIds
     */
    private function ensurePlatformMemberships(array $userIds): void
    {
        foreach (self::PLATFORM_MEMBERSHIPS as $membership) {
            $userId = $this->userId($userIds, $membership['phone']);
            $roleId = $this->roleId($membership['role'], 'platform');

            $existing = $this->findId('platform_memberships', [
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);

            if ($existing !== null) {
                continue;
            }

            $this->insert('platform_memberships', [
                'uuid' => $this->uuid(),
                'user_id' => $userId,
                'role_id' => $roleId,
                'status' => 'active',
            ]);
            $this->count('platform_memberships_created');
        }
    }

    /**
     * @param array<string, array<string, int>> $householdIds
     * @param array<string, int> $userIds
     */
    private function ensureHouseholdMembers(array $householdIds, array $userIds): void
    {
        foreach (self::HOUSEHOLD_MEMBERS as $member) {
            $estateId = $this->findEstateIdBySlug($member['estate']);
            $householdId = $householdIds[$member['estate']][$member['unit']] ?? null;
            $userId = $this->userId($userIds, $member['phone']);

            if ($householdId === null) {
                throw new \RuntimeException('Demo household member references missing unit: ' . $member['unit']);
            }

            $existing = $this->db->selectOne(
                'SELECT id, relationship, is_primary, status FROM household_members
                 WHERE household_id = :household_id AND user_id = :user_id LIMIT 1',
                ['household_id' => $householdId, 'user_id' => $userId]
            );

            if ($existing === null) {
                $this->insert('household_members', [
                    'uuid' => $this->uuid(),
                    'estate_id' => $estateId,
                    'household_id' => $householdId,
                    'user_id' => $userId,
                    'relationship' => $member['relationship'],
                    'is_primary' => $member['primary'],
                    'status' => 'active',
                ]);
                $this->count('household_members_created');

                continue;
            }

            if (
                (string) ($existing['relationship'] ?? '') !== $member['relationship']
                || (int) $existing['is_primary'] !== $member['primary']
                || (string) $existing['status'] !== 'active'
            ) {
                $this->update(
                    'household_members',
                    [
                        'relationship' => $member['relationship'],
                        'is_primary' => $member['primary'],
                        'status' => 'active',
                    ],
                    ['id' => (int) $existing['id']]
                );
                $this->count('household_members_updated');
            }
        }
    }

    /**
     * @param array<string, int> $estateIds
     * @return array<string, array<string, int>> estate slug => gate name => id
     */
    private function ensureGates(array $estateIds): array
    {
        $ids = [];

        foreach (self::GATES as $gate) {
            $estateId = $this->estateId($estateIds, $gate['estate']);
            $gateId = $this->findId('gates', [
                'estate_id' => $estateId,
                'name' => $gate['name'],
            ]);

            if ($gateId === null) {
                $gateId = $this->insert('gates', [
                    'uuid' => $this->uuid(),
                    'estate_id' => $estateId,
                    'name' => $gate['name'],
                    'location' => $gate['location'],
                    'status' => 'active',
                ]);
                $this->count('gates_created');
            }

            $ids[$gate['estate']][$gate['name']] = $gateId;
        }

        return $ids;
    }

    /**
     * @param array<string, array<string, int>> $gateIds
     * @param array<string, int> $userIds
     */
    private function ensureGuardAssignments(array $gateIds, array $userIds): void
    {
        foreach (self::GUARD_ASSIGNMENTS as $assignment) {
            $estateId = $this->findEstateIdBySlug($assignment['estate']);
            $gateId = $gateIds[$assignment['estate']][$assignment['gate']] ?? null;
            $guardUserId = $this->userId($userIds, $assignment['phone']);

            if ($gateId === null) {
                throw new \RuntimeException('Demo guard assignment references missing gate: ' . $assignment['gate']);
            }

            $existing = $this->findId('guard_gate_assignments', [
                'gate_id' => $gateId,
                'guard_user_id' => $guardUserId,
            ]);

            if ($existing !== null) {
                continue;
            }

            $this->insert('guard_gate_assignments', [
                'uuid' => $this->uuid(),
                'estate_id' => $estateId,
                'gate_id' => $gateId,
                'guard_user_id' => $guardUserId,
                'shift' => $assignment['shift'],
                'status' => 'active',
            ]);
            $this->count('guard_assignments_created');
        }
    }

    /**
     * @param array<string, int> $estateIds
     * @param array<string, int> $planIds
     */
    private function ensureSubscriptions(array $estateIds, array $planIds): void
    {
        $planId = $planIds[self::SUBSCRIPTION_PLAN_CODE] ?? null;

        if ($planId === null) {
            throw new \RuntimeException('Demo subscriptions require the "' . self::SUBSCRIPTION_PLAN_CODE . '" plan.');
        }

        foreach ($estateIds as $estateId) {
            $existing = $this->db->selectOne(
                'SELECT id, status, billing_cycle FROM subscriptions
                 WHERE estate_id = :estate_id AND plan_id = :plan_id LIMIT 1',
                ['estate_id' => $estateId, 'plan_id' => $planId]
            );

            if ($existing === null) {
                $this->insert('subscriptions', [
                    'uuid' => $this->uuid(),
                    'estate_id' => $estateId,
                    'plan_id' => $planId,
                    'status' => 'active',
                    'billing_cycle' => 'monthly',
                    'starts_at' => $this->now(),
                ]);
                $this->count('subscriptions_created');

                continue;
            }

            if ((string) $existing['status'] !== 'active' || (string) $existing['billing_cycle'] !== 'monthly') {
                $this->update(
                    'subscriptions',
                    ['status' => 'active', 'billing_cycle' => 'monthly'],
                    ['id' => (int) $existing['id']]
                );
            }
        }
    }

    /**
     * @param array<string, int> $estateIds
     */
    private function estateId(array $estateIds, string $slug): int
    {
        if (!isset($estateIds[$slug])) {
            throw new \RuntimeException('Demo data references unknown estate slug: ' . $slug);
        }

        return $estateIds[$slug];
    }

    /**
     * @param array<string, int> $userIds
     */
    private function userId(array $userIds, string $phone): int
    {
        if (!isset($userIds[$phone])) {
            throw new \RuntimeException('Demo data references unknown user phone: ' . $phone);
        }

        return $userIds[$phone];
    }

    private function roleId(string $name, string $scope): int
    {
        $cacheKey = $scope . ':' . $name;

        if (isset($this->roleIdCache[$cacheKey])) {
            return $this->roleIdCache[$cacheKey];
        }

        $row = $this->db->selectOne(
            'SELECT id FROM roles WHERE scope = :scope AND name = :name LIMIT 1',
            ['scope' => $scope, 'name' => $name]
        );

        if ($row === null) {
            throw new \RuntimeException(
                'Demo data references missing role "' . $name . '" (' . $scope . ' scope). Seed roles first.'
            );
        }

        return $this->roleIdCache[$cacheKey] = (int) $row['id'];
    }

    private function findEstateIdBySlug(string $slug): int
    {
        $estateId = $this->findId('estates', ['slug' => $slug]);

        if ($estateId === null) {
            throw new \RuntimeException('Demo data references missing estate slug: ' . $slug);
        }

        return $estateId;
    }
}
