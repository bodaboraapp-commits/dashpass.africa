<?php

declare(strict_types=1);

namespace Database\Seeds;

/**
 * Seeds the permission catalogue (contract §5 / Blueprint §10).
 *
 * Scope is derived from the slug prefix: `platform.*` permissions are platform
 * scope, everything else is estate scope. Rows are matched by the unique slug.
 */
final class PermissionsSeeder extends Seeder
{
    /**
     * Permission slug => human-readable name. This catalogue is exact and
     * order-stable so tests and role mappings can rely on it.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'estate.view' => 'View estate overview',
        'estate.settings.manage' => 'Manage estate settings',
        'estate.user.manage' => 'Manage estate users',
        'household.view' => 'View households',
        'household.manage' => 'Manage households',
        'household.member.manage' => 'Manage household members',
        'household.staff.manage' => 'Manage household staff',
        'visitor.invitation.create' => 'Create visitor invitations',
        'visitor.invitation.view_own' => 'View own visitor invitations',
        'visitor.invitation.view_all' => 'View all visitor invitations',
        'visitor.invitation.revoke' => 'Revoke visitor invitations',
        'visitor.invitation.extend' => 'Extend visitor invitations',
        'visitor.walkin.create' => 'Create walk-in visitor requests',
        'visitor.walkin.approve' => 'Approve walk-in visitors',
        'visitor.walkin.reject' => 'Reject walk-in visitors',
        'visitor.checkin' => 'Check visitors in',
        'visitor.checkout' => 'Check visitors out',
        'visitor.manual_override' => 'Override visitor access decisions',
        'visitor.history.view_own' => 'View own visitor history',
        'visitor.history.view_all' => 'View all visitor history',
        'visitor.incident.create' => 'Create visitor incidents',
        'visitor.incident.manage' => 'Manage visitor incidents',
        'gate.view' => 'View gates',
        'gate.manage' => 'Manage gates',
        'guard.manage' => 'Manage guards',
        'vehicle.view_own' => 'View own vehicles',
        'vehicle.manage_own' => 'Manage own vehicles',
        'vehicle.view_all' => 'View all vehicles',
        'vehicle.manage_all' => 'Manage all vehicles',
        'parking.view' => 'View parking',
        'parking.bay.manage' => 'Manage parking bays',
        'parking.allocation.manage' => 'Manage parking allocations',
        'parking.reservation.create' => 'Create parking reservations',
        'parking.reservation.manage' => 'Manage parking reservations',
        'parking.session.checkin' => 'Check vehicles into parking',
        'parking.session.checkout' => 'Check vehicles out of parking',
        'parking.violation.manage' => 'Manage parking violations',
        'reports.access.view' => 'View access reports',
        'reports.parking.view' => 'View parking reports',
        'reports.security.view' => 'View security reports',
        'audit.view' => 'View audit trail',
        'notice.view' => 'View estate notices',
        'notice.manage' => 'Manage estate notices',
        'billing.subscription.view' => 'View subscription billing',
        'billing.subscription.manage' => 'Manage subscription billing',
        'billing.invoice.view' => 'View invoices',
        'billing.payment.manage' => 'Manage payments',
        'platform.estate.manage' => 'Manage platform estates',
        'platform.plan.manage' => 'Manage subscription plans',
        'platform.subscription.manage' => 'Manage platform subscriptions',
        'platform.support.manage' => 'Manage platform support',
        'platform.audit.view' => 'View platform audit trail',
    ];

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $this->resetCounters(['created', 'updated', 'catalogue', 'total']);

        foreach (self::PERMISSIONS as $slug => $name) {
            $scope = $this->scopeFor($slug);
            $existing = $this->db->selectOne(
                'SELECT id, name, scope FROM permissions WHERE slug = :slug LIMIT 1',
                ['slug' => $slug]
            );

            if ($existing === null) {
                $this->insert('permissions', [
                    'slug' => $slug,
                    'name' => $name,
                    'scope' => $scope,
                ]);
                $this->count('created');

                continue;
            }

            if ((string) $existing['name'] !== $name || (string) $existing['scope'] !== $scope) {
                $this->update(
                    'permissions',
                    ['name' => $name, 'scope' => $scope],
                    ['id' => (int) $existing['id']]
                );
                $this->count('updated');
            }
        }

        $this->counters['catalogue'] = count(self::PERMISSIONS);
        $this->counters['total'] = (int) $this->db->scalar('SELECT COUNT(*) FROM permissions');

        return $this->counters();
    }

    public static function scopeFor(string $slug): string
    {
        return str_starts_with($slug, 'platform.') ? 'platform' : 'estate';
    }
}
