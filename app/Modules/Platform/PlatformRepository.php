<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Database;

/**
 * Platform (super-admin) persistence (Increment 9 §2).
 *
 * Platform scope is global: estates/accounts/plans/subscriptions are not
 * estate-scoped, and platform audit rows carry `estate_id = NULL`. Nothing is
 * hard-deleted (estate/plan status only, subscription transitions only).
 */
final class PlatformRepository
{
    private const ESTATE_SELECT = 'SELECT e.id, e.uuid, e.name, e.slug, e.timezone, e.estate_type, e.status,
                e.account_id, e.created_at, e.updated_at,
                a.uuid AS account_uuid, a.name AS account_name, a.status AS account_status,
                (SELECT u.full_name FROM estate_memberships em
                 INNER JOIN roles r ON r.id = em.role_id
                 INNER JOIN users u ON u.id = em.user_id
                 WHERE em.estate_id = e.id AND em.status = \'active\' AND r.name = \'estate_admin\'
                 ORDER BY em.id ASC LIMIT 1) AS admin_name,
                (SELECT u.phone FROM estate_memberships em
                 INNER JOIN roles r ON r.id = em.role_id
                 INNER JOIN users u ON u.id = em.user_id
                 WHERE em.estate_id = e.id AND em.status = \'active\' AND r.name = \'estate_admin\'
                 ORDER BY em.id ASC LIMIT 1) AS admin_phone,
                (SELECT u.uuid FROM estate_memberships em
                 INNER JOIN roles r ON r.id = em.role_id
                 INNER JOIN users u ON u.id = em.user_id
                 WHERE em.estate_id = e.id AND em.status = \'active\' AND r.name = \'estate_admin\'
                 ORDER BY em.id ASC LIMIT 1) AS admin_uuid,
                (SELECT u.id FROM estate_memberships em
                 INNER JOIN roles r ON r.id = em.role_id
                 INNER JOIN users u ON u.id = em.user_id
                 WHERE em.estate_id = e.id AND em.status = \'active\' AND r.name = \'estate_admin\'
                 ORDER BY em.id ASC LIMIT 1) AS admin_user_id,
                (SELECT p.name FROM subscriptions s
                 INNER JOIN subscription_plans p ON p.id = s.plan_id
                 WHERE s.estate_id = e.id ORDER BY s.starts_at DESC, s.id DESC LIMIT 1) AS plan_name,
                (SELECT p.code FROM subscriptions s
                 INNER JOIN subscription_plans p ON p.id = s.plan_id
                 WHERE s.estate_id = e.id ORDER BY s.starts_at DESC, s.id DESC LIMIT 1) AS plan_code,
                (SELECT p.uuid FROM subscriptions s
                 INNER JOIN subscription_plans p ON p.id = s.plan_id
                 WHERE s.estate_id = e.id ORDER BY s.starts_at DESC, s.id DESC LIMIT 1) AS plan_uuid,
                (SELECT s.status FROM subscriptions s
                 WHERE s.estate_id = e.id ORDER BY s.starts_at DESC, s.id DESC LIMIT 1) AS subscription_status,
                (SELECT s.uuid FROM subscriptions s
                 WHERE s.estate_id = e.id ORDER BY s.starts_at DESC, s.id DESC LIMIT 1) AS subscription_uuid,
                (SELECT s.id FROM subscriptions s
                 WHERE s.estate_id = e.id ORDER BY s.starts_at DESC, s.id DESC LIMIT 1) AS subscription_id
         FROM estates e
         INNER JOIN saas_accounts a ON a.id = e.account_id';

    private const SUBSCRIPTION_SELECT = 'SELECT s.id, s.uuid, s.estate_id, s.plan_id, s.status, s.billing_cycle,
                s.starts_at, s.ends_at, s.trial_ends_at, s.created_at, s.updated_at,
                e.uuid AS estate_uuid, e.name AS estate_name,
                p.uuid AS plan_uuid, p.code AS plan_code, p.name AS plan_name,
                p.monthly_price_cents, p.currency
         FROM subscriptions s
         INNER JOIN estates e ON e.id = s.estate_id
         INNER JOIN subscription_plans p ON p.id = s.plan_id';

    private const PLAN_SELECT = 'SELECT p.id, p.uuid, p.code, p.name, p.monthly_price_cents, p.currency,
                p.household_limit, p.gate_device_limit, p.features, p.status, p.created_at,
                (SELECT COUNT(*) FROM subscriptions s
                 WHERE s.plan_id = p.id AND s.status NOT IN (\'cancelled\', \'expired\')) AS subscriber_count
         FROM subscription_plans p';

    public function __construct(private readonly Database $db)
    {
    }

    /* ------------------------------------------------------------------ */
    /* Estates / accounts                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function estates(?string $status = null): array
    {
        $filter = '';
        $params = [];

        if ($status !== null) {
            $filter = ' WHERE e.status = :status';
            $params['status'] = $status;
        }

        return $this->db->select(
            self::ESTATE_SELECT . $filter . ' ORDER BY e.created_at DESC, e.id DESC',
            $params
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEstate(string $uuid): ?array
    {
        return $this->db->selectOne(
            self::ESTATE_SELECT . ' WHERE e.uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function estateBySlug(string $slug): ?array
    {
        return $this->db->selectOne('SELECT id, uuid FROM estates WHERE slug = :slug LIMIT 1', ['slug' => $slug]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function account(string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT id, uuid, name, status FROM saas_accounts WHERE uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function accounts(): array
    {
        return $this->db->select(
            "SELECT id, uuid, name, status FROM saas_accounts WHERE status = 'active' ORDER BY name ASC"
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertAccount(array $fields): int
    {
        return $this->db->insert('saas_accounts', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertEstate(array $fields): int
    {
        return $this->db->insert('estates', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateEstate(int $id, array $fields): void
    {
        $this->db->update('estates', $fields, ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertEstateSetting(array $fields): int
    {
        return $this->db->insert('estate_settings', $fields);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByPhone(string $phone): ?array
    {
        return $this->db->selectOne(
            'SELECT id, uuid, full_name, phone, status FROM users WHERE phone = :phone LIMIT 1',
            ['phone' => $phone]
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertUser(array $fields): int
    {
        return $this->db->insert('users', $fields);
    }

    public function roleId(string $scope, string $name): ?int
    {
        $value = $this->db->scalar(
            'SELECT id FROM roles WHERE scope = :scope AND name = :name LIMIT 1',
            ['scope' => $scope, 'name' => $name]
        );

        return $value === null ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertEstateMembership(array $fields): int
    {
        return $this->db->insert('estate_memberships', $fields);
    }

    /* ------------------------------------------------------------------ */
    /* Plans                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function plans(?string $status = null): array
    {
        $filter = '';
        $params = [];

        if ($status !== null) {
            $filter = ' WHERE p.status = :status';
            $params['status'] = $status;
        }

        return $this->db->select(
            self::PLAN_SELECT . $filter . ' ORDER BY p.created_at DESC, p.id DESC',
            $params
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPlan(string $uuid): ?array
    {
        return $this->db->selectOne(
            self::PLAN_SELECT . ' WHERE p.uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPlanByCode(string $code): ?array
    {
        return $this->db->selectOne('SELECT id, uuid FROM subscription_plans WHERE code = :code LIMIT 1', ['code' => $code]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertPlan(array $fields): int
    {
        return $this->db->insert('subscription_plans', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updatePlan(int $id, array $fields): void
    {
        $this->db->update('subscription_plans', $fields, ['id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>> Active plans selectable for assignment.
     */
    public function activePlans(): array
    {
        return $this->db->select(
            self::PLAN_SELECT . " WHERE p.status = 'active' ORDER BY p.monthly_price_cents ASC, p.name ASC"
        );
    }

    /* ------------------------------------------------------------------ */
    /* Subscriptions                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function subscriptions(): array
    {
        return $this->db->select(
            self::SUBSCRIPTION_SELECT . ' ORDER BY s.starts_at DESC, s.id DESC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSubscription(string $uuid): ?array
    {
        return $this->db->selectOne(
            self::SUBSCRIPTION_SELECT . ' WHERE s.uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertSubscription(array $fields): int
    {
        return $this->db->insert('subscriptions', $fields);
    }

    /**
     * Update a non-terminal subscription atomically (terminal-state race guard).
     *
     * Unknown columns are ignored; returns the affected row count (0 when another
     * request already moved the subscription to cancelled/expired).
     *
     * @param array<string, mixed> $fields
     */
    public function updateSubscription(int $id, array $fields): int
    {
        $allowed = ['status', 'plan_id', 'ends_at', 'updated_at'];
        $assignments = [];
        $params = ['id' => $id];

        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }

            $assignments[] = $column . ' = :' . $column;
            $params[$column] = $value;
        }

        if ($assignments === []) {
            return 0;
        }

        return $this->db->execute(
            'UPDATE subscriptions SET ' . implode(', ', $assignments)
            . " WHERE id = :id AND status NOT IN ('cancelled', 'expired')",
            $params
        );
    }

    public function estateHasSubscription(int $estateId): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM subscriptions WHERE estate_id = :estate_id LIMIT 1',
            ['estate_id' => $estateId]
        ) !== null;
    }

    /* ------------------------------------------------------------------ */
    /* Dashboard / audit                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return [
            'accounts' => (int) ($this->db->scalar('SELECT COUNT(*) FROM saas_accounts') ?? 0),
            'estates_active' => (int) ($this->db->scalar("SELECT COUNT(*) FROM estates WHERE status = 'active'") ?? 0),
            'estates_suspended' => (int) ($this->db->scalar("SELECT COUNT(*) FROM estates WHERE status = 'suspended'") ?? 0),
            'estates_archived' => (int) ($this->db->scalar("SELECT COUNT(*) FROM estates WHERE status = 'archived'") ?? 0),
            'active_subscriptions' => (int) ($this->db->scalar("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'") ?? 0),
            'plans' => (int) ($this->db->scalar('SELECT COUNT(*) FROM subscription_plans') ?? 0),
        ];
    }

    /**
     * Recent dashboard audit feed: platform-scope actions plus billing lifecycle
     * events (both are platform-finance actions recorded with estate_id NULL; the
     * display estate is resolved from metadata.estate_uuid where available).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentPlatformAudit(int $limit = 10): array
    {
        return $this->db->select(
            "SELECT al.action, al.created_at,
                    COALESCE(e.name, me.name) AS estate_name
             FROM audit_logs al
             LEFT JOIN estates e ON e.id = al.estate_id
             LEFT JOIN estates me ON me.uuid = JSON_UNQUOTE(JSON_EXTRACT(al.metadata, '$.estate_uuid'))
             WHERE al.action LIKE 'platform.%' OR al.action LIKE 'billing.%'
             ORDER BY al.id DESC
             LIMIT " . max(1, $limit)
        );
    }
}
