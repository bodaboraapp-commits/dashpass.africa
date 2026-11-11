<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Clock;

/**
 * Canonical billing labels, tones, options and frozen view shapes
 * (Increment 10 §3-§4; T77 frozen keys for T78 views).
 */
final class BillingPresenter
{
    /** @var array<string, string> */
    private const INVOICE_STATUS_LABELS = [
        'draft' => 'Draft',
        'open' => 'Open',
        'past_due' => 'Past due',
        'paid' => 'Paid',
        'void' => 'Void',
    ];

    /** @var array<string, string> */
    private const INVOICE_STATUS_TONES = [
        'draft' => 'neutral',
        'open' => 'warn',
        'past_due' => 'danger',
        'paid' => 'ok',
        'void' => 'neutral',
    ];

    /** @var array<string, string> */
    private const PAYMENT_STATUS_LABELS = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'failed' => 'Failed',
        'refunded' => 'Refunded',
    ];

    /** @var array<string, string> */
    private const PAYMENT_STATUS_TONES = [
        'pending' => 'warn',
        'confirmed' => 'ok',
        'failed' => 'danger',
        'refunded' => 'neutral',
    ];

    /** @var array<string, string> */
    private const METHOD_LABELS = [
        'mpesa' => 'M-Pesa',
        'bank' => 'Bank transfer',
        'card' => 'Card',
        'cash' => 'Cash',
        'other' => 'Other',
    ];

    /** @var array<int, string> */
    public const INVOICE_STATUSES = ['draft', 'open', 'past_due', 'paid', 'void'];

    /** @var array<int, string> */
    public const PAYMENT_STATUSES = ['pending', 'confirmed', 'failed', 'refunded'];

    /** @var array<int, string> */
    public const METHODS = ['mpesa', 'bank', 'card', 'cash', 'other'];

    /** @var array<int, string> */
    public const TERMINAL_PAYMENT_STATUSES = ['failed', 'refunded'];

    /* ------------------------------------------------------------------ */
    /* Options                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array{value: string, label: string, tone: string}>
     */
    public function invoiceStatuses(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::invoiceStatusLabel($value),
            'tone' => self::invoiceStatusTone($value),
        ], self::INVOICE_STATUSES);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function paymentMethods(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::METHOD_LABELS[$value],
        ], self::METHODS);
    }

    /**
     * @return array<int, array{value: string, label: string, tone: string}>
     */
    public function paymentStatuses(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::paymentStatusLabel($value),
            'tone' => self::paymentStatusTone($value),
        ], self::PAYMENT_STATUSES);
    }

    /* ------------------------------------------------------------------ */
    /* Labels / tones                                                     */
    /* ------------------------------------------------------------------ */

    public static function invoiceStatusLabel(string $status): string
    {
        return self::INVOICE_STATUS_LABELS[$status] ?? ucfirst($status);
    }

    public static function invoiceStatusTone(string $status): string
    {
        return self::INVOICE_STATUS_TONES[$status] ?? 'neutral';
    }

    public static function paymentStatusLabel(string $status): string
    {
        return self::PAYMENT_STATUS_LABELS[$status] ?? ucfirst($status);
    }

    public static function paymentStatusTone(string $status): string
    {
        return self::PAYMENT_STATUS_TONES[$status] ?? 'neutral';
    }

    public static function methodLabel(string $method): string
    {
        return self::METHOD_LABELS[$method] ?? ucfirst($method);
    }

    public static function isTerminalPayment(string $status): bool
    {
        return in_array($status, self::TERMINAL_PAYMENT_STATUSES, true);
    }

    public static function money(int $cents, string $currency): string
    {
        return number_format($cents / 100, 2) . ' ' . $currency;
    }

    /* ------------------------------------------------------------------ */
    /* Frozen shapes                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Frozen invoice list item (`/platform/billing` invoices[]).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function invoice(array $row): array
    {
        $status = (string) ($row['status'] ?? 'open');
        $currency = (string) ($row['currency'] ?? 'KES');
        $amount = (int) ($row['amount_cents'] ?? 0);
        $confirmed = (int) ($row['confirmed_total_cents'] ?? 0);

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
            'estate_uuid' => (string) ($row['estate_uuid'] ?? ''),
            'estate_name' => (string) ($row['estate_name'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'subscription_uuid' => $row['subscription_uuid'] === null ? null : (string) $row['subscription_uuid'],
            'plan_name' => $row['plan_name'] === null ? null : (string) $row['plan_name'],
            'amount_cents' => $amount,
            'currency' => $currency,
            'amount_display' => self::money($amount, $currency),
            'confirmed_cents' => $confirmed,
            'balance_cents' => max(0, $amount - $confirmed),
            'status' => $status,
            'status_label' => self::invoiceStatusLabel($status),
            'status_tone' => self::invoiceStatusTone($status),
            'period_start' => self::dateOnly(isset($row['period_start']) ? (string) $row['period_start'] : null),
            'period_end' => self::dateOnly(isset($row['period_end']) ? (string) $row['period_end'] : null),
            'due_at' => self::iso(isset($row['due_at']) ? (string) $row['due_at'] : null),
            'paid_at' => self::iso(isset($row['paid_at']) ? (string) $row['paid_at'] : null),
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'created_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
        ];
    }

    /**
     * Frozen invoice detail (`/platform/billing/invoices/{uuid}`): invoice + ledger.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $payments
     * @return array<string, mixed>
     */
    public static function invoiceDetail(array $row, array $payments): array
    {
        return self::invoice($row) + [
            'payments' => array_map([self::class, 'payment'], $payments),
            'confirmed_total_cents' => (int) ($row['confirmed_total_cents'] ?? 0),
            'pending_total_cents' => (int) ($row['pending_total_cents'] ?? 0),
        ];
    }

    /**
     * Frozen payment ledger row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function payment(array $row): array
    {
        $status = (string) ($row['status'] ?? 'pending');
        $method = (string) ($row['method'] ?? 'other');
        $amount = (int) ($row['amount_cents'] ?? 0);
        $currency = (string) ($row['currency'] ?? 'KES');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'invoice_uuid' => (string) ($row['invoice_uuid'] ?? ''),
            'method' => $method,
            'method_label' => self::methodLabel($method),
            'reference' => $row['reference'] === null ? null : (string) $row['reference'],
            'amount_cents' => $amount,
            'currency' => $currency,
            'amount_display' => self::money($amount, $currency),
            'status' => $status,
            'status_label' => self::paymentStatusLabel($status),
            'status_tone' => self::paymentStatusTone($status),
            'paid_at' => self::iso(isset($row['paid_at']) ? (string) $row['paid_at'] : null),
            'recorded_by' => (string) ($row['recorded_by_name'] ?? ''),
            'created_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{uuid: string, label: string, status: string}>
     */
    public static function estateOptions(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'uuid' => (string) $row['uuid'],
            'label' => (string) $row['name'] . ' · ' . (string) ($row['account_name'] ?? ''),
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
        ], $rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function subscriptionOptions(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'uuid' => (string) $row['uuid'],
            'label' => (string) $row['estate_name'] . ' · ' . (string) $row['plan_name']
                . ' (' . (string) $row['billing_cycle'] . ')',
            'estate_uuid' => (string) $row['estate_uuid'],
            'plan_name' => (string) $row['plan_name'],
            'plan_code' => (string) $row['plan_code'],
            'monthly_price_cents' => (int) $row['monthly_price_cents'],
            'currency' => (string) $row['currency'],
            'billing_cycle' => (string) $row['billing_cycle'],
        ], $rows);
    }

    public static function iso(?string $utc): ?string
    {
        return Clock::fromDb($utc)?->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function dateOnly(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        return substr(trim($date), 0, 10);
    }
}
