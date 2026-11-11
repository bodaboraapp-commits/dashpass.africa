<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;

/**
 * Billing persistence (Increment 10 §1/§3).
 *
 * Invoices/payments are never hard-deleted (statuses only). Confirmed payment
 * totals are computed with SQL sums; numbering uses a per-estate/month maximum
 * read inside the create transaction with a UNIQUE(estate_id, invoice_number)
 * backstop. All queries are platform-scope (super-admin/finance) or scoped by
 * estate for joins.
 */
final class BillingRepository
{
    private const INVOICE_SELECT = 'SELECT i.id, i.uuid, i.estate_id, i.account_id, i.subscription_id,
                i.invoice_number, i.status, i.period_start, i.period_end, i.amount_cents, i.currency,
                i.due_at, i.paid_at, i.notes, i.created_by_user_id, i.updated_by_user_id,
                i.created_at, i.updated_at,
                e.uuid AS estate_uuid, e.name AS estate_name,
                a.name AS account_name,
                s.uuid AS subscription_uuid,
                p.name AS plan_name,
                (SELECT COALESCE(SUM(pay.amount_cents), 0) FROM billing_payments pay
                 WHERE pay.invoice_id = i.id AND pay.status = \'confirmed\') AS confirmed_total_cents,
                (SELECT COALESCE(SUM(pay.amount_cents), 0) FROM billing_payments pay
                 WHERE pay.invoice_id = i.id AND pay.status = \'pending\') AS pending_total_cents
         FROM billing_invoices i
         INNER JOIN estates e ON e.id = i.estate_id
         INNER JOIN saas_accounts a ON a.id = i.account_id
         LEFT JOIN subscriptions s ON s.id = i.subscription_id AND s.estate_id = i.estate_id
         LEFT JOIN subscription_plans p ON p.id = s.plan_id';

    private const PAYMENT_SELECT = 'SELECT pay.id, pay.uuid, pay.estate_id, pay.invoice_id, pay.method, pay.reference,
                pay.amount_cents, pay.currency, pay.status, pay.paid_at, pay.recorded_by_user_id,
                pay.metadata, pay.created_at, pay.updated_at,
                i.uuid AS invoice_uuid, i.invoice_number, i.amount_cents AS invoice_amount_cents,
                e.uuid AS estate_uuid, e.name AS estate_name,
                u.full_name AS recorded_by_name
         FROM billing_payments pay
         INNER JOIN billing_invoices i ON i.id = pay.invoice_id AND i.estate_id = pay.estate_id
         INNER JOIN estates e ON e.id = pay.estate_id
         LEFT JOIN users u ON u.id = pay.recorded_by_user_id';

    public function __construct(private readonly Database $db)
    {
    }

    /* ------------------------------------------------------------------ */
    /* Invoices                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function invoices(?string $status, ?string $estateUuid, ?string $month): array
    {
        $filter = '';
        $params = [];

        if ($status !== null) {
            $filter .= ' AND i.status = :status';
            $params['status'] = $status;
        }

        if ($estateUuid !== null) {
            $filter .= ' AND e.uuid = :estate_uuid';
            $params['estate_uuid'] = $estateUuid;
        }

        if ($month !== null && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            $filter .= ' AND DATE_FORMAT(i.created_at, \'%Y-%m\') = :month';
            $params['month'] = $month;
        }

        return $this->db->select(
            self::INVOICE_SELECT . ' WHERE 1 = 1' . $filter
            . ' ORDER BY i.created_at DESC, i.id DESC',
            $params
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInvoice(string $uuid): ?array
    {
        return $this->db->selectOne(
            self::INVOICE_SELECT . ' WHERE i.uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function invoicesDueBefore(string $nowDb, ?int $estateId = null): array
    {
        $sql = "SELECT i.id, i.uuid, i.estate_id, i.invoice_number, i.amount_cents, i.currency
                FROM billing_invoices i
                WHERE i.status = 'open' AND i.due_at IS NOT NULL AND i.due_at < :now";
        $params = ['now' => $nowDb];

        if ($estateId !== null) {
            $sql .= ' AND i.estate_id = :estate_id';
            $params['estate_id'] = $estateId;
        }

        return $this->db->select($sql . ' ORDER BY i.due_at ASC, i.id ASC', $params);
    }

    /**
     * Latest invoice number for an estate + month prefix (inside the create txn).
     *
     * `FOR UPDATE` is deliberate: it turns this into a locking read so a
     * concurrent create in the same estate/month observes the latest committed
     * number (REPEATABLE READ snapshots would otherwise hide it) and serialises
     * sequence allocation behind UNIQUE(estate_id, invoice_number).
     *
     * @return array<string, mixed>|null
     */
    public function latestInvoiceNumber(int $estateId, string $prefix): ?array
    {
        return $this->db->selectOne(
            'SELECT invoice_number FROM billing_invoices
             WHERE estate_id = :estate_id AND invoice_number LIKE :prefix
             ORDER BY invoice_number DESC LIMIT 1 FOR UPDATE',
            ['estate_id' => $estateId, 'prefix' => $prefix . '%']
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertInvoice(array $fields): int
    {
        return $this->db->insert('billing_invoices', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateInvoice(int $id, array $fields): void
    {
        $this->db->update('billing_invoices', $fields, ['id' => $id]);
    }

    /* ------------------------------------------------------------------ */
    /* Payments                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paymentsForInvoice(int $invoiceId): array
    {
        return $this->db->select(
            self::PAYMENT_SELECT . ' WHERE pay.invoice_id = :invoice_id
             ORDER BY pay.created_at ASC, pay.id ASC',
            ['invoice_id' => $invoiceId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPayment(string $uuid): ?array
    {
        return $this->db->selectOne(
            self::PAYMENT_SELECT . ' WHERE pay.uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insertPayment(array $fields): int
    {
        return $this->db->insert('billing_payments', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updatePayment(int $id, array $fields): void
    {
        $this->db->update('billing_payments', $fields, ['id' => $id]);
    }

    public function confirmedTotal(int $invoiceId): int
    {
        return (int) ($this->db->scalar(
            "SELECT COALESCE(SUM(amount_cents), 0) FROM billing_payments
             WHERE invoice_id = :invoice_id AND status = 'confirmed'",
            ['invoice_id' => $invoiceId]
        ) ?? 0);
    }

    /* ------------------------------------------------------------------ */
    /* Options / dashboard                                                */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>> Estates selectable for invoicing (active/suspended).
     */
    public function estateOptions(): array
    {
        return $this->db->select(
            "SELECT e.uuid, e.name, e.status, a.name AS account_name
             FROM estates e
             INNER JOIN saas_accounts a ON a.id = e.account_id
             WHERE e.status IN ('active', 'suspended')
             ORDER BY e.name ASC"
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEstate(string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT e.id, e.uuid, e.name, e.status, e.account_id, e.timezone
             FROM estates e WHERE e.uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * @return array<int, array<string, mixed>> Subscriptions selectable for invoice prefill.
     */
    public function subscriptionOptions(): array
    {
        return $this->db->select(
            "SELECT s.uuid, s.estate_id, s.billing_cycle,
                    e.uuid AS estate_uuid, e.name AS estate_name,
                    p.code AS plan_code, p.name AS plan_name, p.monthly_price_cents, p.currency
             FROM subscriptions s
             INNER JOIN estates e ON e.id = s.estate_id
             INNER JOIN subscription_plans p ON p.id = s.plan_id
             ORDER BY e.name ASC, s.starts_at DESC"
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSubscription(string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT s.id, s.uuid, s.estate_id, s.billing_cycle, s.status,
                    p.name AS plan_name, p.code AS plan_code, p.monthly_price_cents, p.currency
             FROM subscriptions s
             INNER JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        $open = (int) ($this->db->scalar(
            "SELECT COALESCE(SUM(amount_cents), 0) FROM billing_invoices WHERE status = 'open'"
        ) ?? 0);
        $pastDue = (int) ($this->db->scalar(
            "SELECT COALESCE(SUM(amount_cents), 0) FROM billing_invoices WHERE status = 'past_due'"
        ) ?? 0);
        $paidThisMonth = (int) ($this->db->scalar(
            "SELECT COALESCE(SUM(amount_cents), 0) FROM billing_invoices
             WHERE status = 'paid' AND paid_at >= :month_start",
            ['month_start' => gmdate('Y-m-01 00:00:00')]
        ) ?? 0);

        return [
            'open_cents' => $open,
            'past_due_cents' => $pastDue,
            'paid_this_month_cents' => $paidThisMonth,
            'outstanding_cents' => (int) ($this->db->scalar(
                "SELECT COALESCE(SUM(amount_cents), 0) FROM billing_invoices WHERE status IN ('open', 'past_due')"
            ) ?? 0),
            'open_count' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM billing_invoices WHERE status = 'open'"
            ) ?? 0),
            'past_due_count' => (int) ($this->db->scalar(
                "SELECT COUNT(*) FROM billing_invoices WHERE status = 'past_due'"
            ) ?? 0),
        ];
    }

    /**
     * @return array<int, string> distinct invoice months for the filter (newest first).
     */
    public function invoiceMonths(): array
    {
        $rows = $this->db->select(
            "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') AS month
             FROM billing_invoices ORDER BY month DESC LIMIT 24"
        );

        return array_values(array_map(static fn (array $row): string => (string) $row['month'], $rows));
    }
}
