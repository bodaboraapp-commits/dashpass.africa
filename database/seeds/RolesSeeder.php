<?php

declare(strict_types=1);

namespace Database\Seeds;

/**
 * Seeds roles and their exact role-to-permission mapping (contract §5).
 *
 * Roles are matched by the (scope, name) natural key. `platform_super_admin`
 * receives every permission in the catalogue; all other roles receive the
 * explicit list from the contract.
 */
final class RolesSeeder extends Seeder
{
    public const ALL_PERMISSIONS = '*';

    /**
     * Role name => definition. Scope defaults to `estate`.
     *
     * @var array<string, array{scope: string, description: string, permissions: array<int, string>|string}>
     */
    public const ROLES = [
        'platform_super_admin' => [
            'scope' => 'platform',
            'description' => 'Full platform administration across every estate.',
            'permissions' => self::ALL_PERMISSIONS,
        ],
        'platform_operations' => [
            'scope' => 'platform',
            'description' => 'Operate estate onboarding and subscriptions.',
            'permissions' => [
                'platform.estate.manage',
                'platform.subscription.manage',
                'platform.support.manage',
                'billing.subscription.view',
                'reports.access.view',
            ],
        ],
        'platform_support' => [
            'scope' => 'platform',
            'description' => 'Support tenants and review platform audit history.',
            'permissions' => [
                'platform.support.manage',
                'platform.audit.view',
            ],
        ],
        'platform_finance' => [
            'scope' => 'platform',
            'description' => 'Manage billing, invoices and payments.',
            'permissions' => [
                'billing.subscription.view',
                'billing.invoice.view',
                'billing.payment.manage',
                'billing.subscription.manage',
                'platform.subscription.manage',
            ],
        ],
        'estate_admin' => [
            'scope' => 'estate',
            'description' => 'Full administration of a single estate.',
            'permissions' => [
                'estate.view',
                'estate.settings.manage',
                'estate.user.manage',
                'household.view',
                'household.manage',
                'household.member.manage',
                'household.staff.manage',
                'visitor.invitation.view_all',
                'visitor.invitation.revoke',
                'visitor.walkin.approve',
                'visitor.walkin.reject',
                'visitor.history.view_all',
                'visitor.incident.create',
                'visitor.incident.manage',
                'gate.view',
                'gate.manage',
                'guard.manage',
                'vehicle.view_all',
                'vehicle.manage_all',
                'parking.view',
                'parking.bay.manage',
                'parking.allocation.manage',
                'parking.reservation.manage',
                'parking.violation.manage',
                'reports.access.view',
                'reports.parking.view',
                'reports.security.view',
                'audit.view',
                'billing.subscription.view',
            ],
        ],
        'estate_manager' => [
            'scope' => 'estate',
            'description' => 'Day-to-day estate operations management.',
            'permissions' => [
                'estate.view',
                'household.view',
                'household.manage',
                'household.member.manage',
                'household.staff.manage',
                'visitor.invitation.view_all',
                'visitor.walkin.approve',
                'visitor.walkin.reject',
                'visitor.history.view_all',
                'visitor.incident.create',
                'visitor.incident.manage',
                'gate.view',
                'guard.manage',
                'vehicle.view_all',
                'parking.view',
                'parking.allocation.manage',
                'parking.reservation.manage',
                'reports.access.view',
                'reports.parking.view',
                'reports.security.view',
                'audit.view',
                'billing.subscription.view',
            ],
        ],
        'security_supervisor' => [
            'scope' => 'estate',
            'description' => 'Supervise gate security operations.',
            'permissions' => [
                'estate.view',
                'gate.view',
                'gate.manage',
                'guard.manage',
                'visitor.invitation.view_all',
                'visitor.checkin',
                'visitor.checkout',
                'visitor.walkin.create',
                'visitor.walkin.approve',
                'visitor.manual_override',
                'visitor.history.view_all',
                'visitor.incident.create',
                'visitor.incident.manage',
                'vehicle.view_all',
                'parking.view',
                'parking.session.checkin',
                'parking.session.checkout',
                'reports.security.view',
            ],
        ],
        'security_guard' => [
            'scope' => 'estate',
            'description' => 'Operate gates and visitor check-in.',
            'permissions' => [
                'gate.view',
                'visitor.checkin',
                'visitor.checkout',
                'visitor.walkin.create',
                'visitor.incident.create',
                'vehicle.view_all',
                'parking.view',
                'parking.session.checkin',
                'parking.session.checkout',
            ],
        ],
        'parking_manager' => [
            'scope' => 'estate',
            'description' => 'Manage parking bays, allocations and violations.',
            'permissions' => [
                'estate.view',
                'vehicle.view_all',
                'parking.view',
                'parking.bay.manage',
                'parking.allocation.manage',
                'parking.reservation.manage',
                'parking.session.checkin',
                'parking.session.checkout',
                'parking.violation.manage',
                'reports.parking.view',
            ],
        ],
        'resident' => [
            'scope' => 'estate',
            'description' => 'Resident of an estate household.',
            'permissions' => [
                'estate.view',
                'household.view',
                'household.member.manage',
                'visitor.invitation.create',
                'visitor.invitation.view_own',
                'visitor.invitation.revoke',
                'visitor.invitation.extend',
                'visitor.history.view_own',
                'visitor.walkin.approve',
                'visitor.walkin.reject',
                'vehicle.view_own',
                'vehicle.manage_own',
                'parking.view',
                'parking.reservation.create',
            ],
        ],
        'tenant' => [
            'scope' => 'estate',
            'description' => 'Renting tenant of an estate household.',
            'permissions' => [
                'estate.view',
                'household.view',
                'visitor.invitation.create',
                'visitor.invitation.view_own',
                'visitor.invitation.revoke',
                'visitor.invitation.extend',
                'visitor.history.view_own',
                'visitor.walkin.approve',
                'visitor.walkin.reject',
                'vehicle.view_own',
                'vehicle.manage_own',
                'parking.view',
                'parking.reservation.create',
            ],
        ],
        'household_member' => [
            'scope' => 'estate',
            'description' => 'Additional member of a household.',
            'permissions' => [
                'estate.view',
                'household.view',
                'visitor.invitation.create',
                'visitor.invitation.view_own',
                'visitor.history.view_own',
                'visitor.walkin.approve',
                'visitor.walkin.reject',
                'vehicle.view_own',
            ],
        ],
        'auditor' => [
            'scope' => 'estate',
            'description' => 'Read-only audit and reporting access.',
            'permissions' => [
                'estate.view',
                'household.view',
                'gate.view',
                'visitor.invitation.view_all',
                'visitor.history.view_all',
                'vehicle.view_all',
                'parking.view',
                'reports.access.view',
                'reports.parking.view',
                'reports.security.view',
                'audit.view',
            ],
        ],
    ];

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $this->resetCounters(['roles_created', 'roles_updated', 'role_permissions_created', 'roles_total', 'role_permissions_total']);

        $permissionIds = $this->permissionIds();

        foreach (self::ROLES as $name => $definition) {
            $scope = $definition['scope'];
            $roleId = $this->findId('roles', ['scope' => $scope, 'name' => $name]);

            if ($roleId === null) {
                $roleId = $this->insert('roles', [
                    'scope' => $scope,
                    'name' => $name,
                    'description' => $definition['description'],
                ]);
                $this->count('roles_created');
            } else {
                $existing = $this->db->selectOne(
                    'SELECT description FROM roles WHERE id = :id LIMIT 1',
                    ['id' => $roleId]
                );

                if ((string) ($existing['description'] ?? '') !== $definition['description']) {
                    $this->update('roles', ['description' => $definition['description']], ['id' => $roleId]);
                    $this->count('roles_updated');
                }
            }

            $slugs = $definition['permissions'] === self::ALL_PERMISSIONS
                ? array_keys($permissionIds)
                : $definition['permissions'];

            foreach ($slugs as $slug) {
                if (!isset($permissionIds[$slug])) {
                    throw new RuntimeException(
                        'Role "' . $name . '" references unknown permission "' . $slug . '". Seed permissions first.'
                    );
                }

                $link = $this->db->selectOne(
                    'SELECT role_id FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id LIMIT 1',
                    ['role_id' => $roleId, 'permission_id' => $permissionIds[$slug]]
                );

                if ($link !== null) {
                    continue;
                }

                $this->db->insert('role_permissions', [
                    'role_id' => $roleId,
                    'permission_id' => $permissionIds[$slug],
                ]);
                $this->count('role_permissions_created');
            }
        }

        $this->counters['roles_total'] = (int) $this->db->scalar('SELECT COUNT(*) FROM roles');
        $this->counters['role_permissions_total'] = (int) $this->db->scalar('SELECT COUNT(*) FROM role_permissions');

        return $this->counters();
    }

    /**
     * @return array<string, int> slug => permission id
     */
    private function permissionIds(): array
    {
        $ids = [];

        foreach ($this->db->select('SELECT id, slug FROM permissions') as $row) {
            $ids[(string) $row['slug']] = (int) $row['id'];
        }

        return $ids;
    }
}
