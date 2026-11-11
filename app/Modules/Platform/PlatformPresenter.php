<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Clock;
use App\Core\Phone;

/**
 * Canonical platform-portal option lists, labels, tones and frozen view shapes
 * (Increment 9 §2-§3; T64 frozen keys for T65 views).
 */
final class PlatformPresenter
{
    /** @var array<string, string> */
    private const ESTATE_STATUS_LABELS = [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'archived' => 'Archived',
    ];

    /** @var array<string, string> */
    private const ESTATE_STATUS_TONES = [
        'active' => 'ok',
        'suspended' => 'warn',
        'archived' => 'neutral',
    ];

    /** @var array<string, string> */
    private const PLAN_STATUS_LABELS = [
        'active' => 'Active',
        'retired' => 'Retired',
    ];

    /** @var array<string, string> */
    private const PLAN_STATUS_TONES = [
        'active' => 'ok',
        'retired' => 'neutral',
    ];

    /** @var array<string, string> */
    private const SUBSCRIPTION_STATUS_LABELS = [
        'trialing' => 'Trialing',
        'active' => 'Active',
        'past_due' => 'Past due',
        'suspended' => 'Suspended',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    ];

    /** @var array<string, string> */
    private const SUBSCRIPTION_STATUS_TONES = [
        'trialing' => 'warn',
        'active' => 'ok',
        'past_due' => 'danger',
        'suspended' => 'danger',
        'cancelled' => 'neutral',
        'expired' => 'neutral',
    ];

    /** @var array<int, string> */
    public const ESTATE_STATUSES = ['active', 'suspended', 'archived'];

    /** @var array<int, string> */
    public const ESTATE_TYPES = ['apartment', 'maisonette', 'mixed'];

    /** @var array<int, string> */
    public const PLAN_STATUSES = ['active', 'retired'];

    /** @var array<int, string> */
    public const SUBSCRIPTION_STATUSES = ['trialing', 'active', 'past_due', 'suspended', 'cancelled', 'expired'];

    /** @var array<int, string> Terminal subscription statuses. */
    public const TERMINAL_SUBSCRIPTION_STATUSES = ['cancelled', 'expired'];

    /* ------------------------------------------------------------------ */
    /* Options                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array{value: string, label: string, tone: string}>
     */
    public function estateStatuses(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::ESTATE_STATUS_LABELS[$value],
            'tone' => self::ESTATE_STATUS_TONES[$value],
        ], self::ESTATE_STATUSES);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function estateTypes(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => ucfirst($value),
        ], self::ESTATE_TYPES);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function timezones(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => $value,
        ], ['Africa/Nairobi', 'Africa/Kampala', 'Africa/Dar_es_Salaam', 'Africa/Mogadishu', 'Africa/Kigali', 'UTC']);
    }

    /**
     * @return array<int, array{value: string, label: string, tone: string}>
     */
    public function subscriptionStatuses(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::SUBSCRIPTION_STATUS_LABELS[$value],
            'tone' => self::SUBSCRIPTION_STATUS_TONES[$value],
        ], self::SUBSCRIPTION_STATUSES);
    }

    /* ------------------------------------------------------------------ */
    /* Labels / tones                                                     */
    /* ------------------------------------------------------------------ */

    public static function estateStatusLabel(string $status): string
    {
        return self::ESTATE_STATUS_LABELS[$status] ?? ucfirst($status);
    }

    public static function estateStatusTone(string $status): string
    {
        return self::ESTATE_STATUS_TONES[$status] ?? 'neutral';
    }

    public static function planStatusLabel(string $status): string
    {
        return self::PLAN_STATUS_LABELS[$status] ?? ucfirst($status);
    }

    public static function planStatusTone(string $status): string
    {
        return self::PLAN_STATUS_TONES[$status] ?? 'neutral';
    }

    public static function subscriptionStatusLabel(string $status): string
    {
        return self::SUBSCRIPTION_STATUS_LABELS[$status] ?? ucfirst($status);
    }

    public static function subscriptionStatusTone(string $status): string
    {
        return self::SUBSCRIPTION_STATUS_TONES[$status] ?? 'neutral';
    }

    public static function isTerminalSubscription(string $status): bool
    {
        return in_array($status, self::TERMINAL_SUBSCRIPTION_STATUSES, true);
    }

    /* ------------------------------------------------------------------ */
    /* Frozen shapes                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Frozen estate list item for `/platform/estates` and dashboard links.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function estate(array $row): array
    {
        $status = (string) ($row['status'] ?? 'active');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'status' => $status,
            'status_label' => self::estateStatusLabel($status),
            'status_tone' => self::estateStatusTone($status),
            'admin_name' => (string) ($row['admin_name'] ?? ''),
            'admin_phone_masked' => Phone::mask((string) ($row['admin_phone'] ?? '')),
            'plan_name' => $row['plan_name'] === null ? null : (string) $row['plan_name'],
            'subscription_status' => $row['subscription_status'] === null ? null : (string) $row['subscription_status'],
            'subscription_status_label' => $row['subscription_status'] === null
                ? null
                : self::subscriptionStatusLabel((string) $row['subscription_status']),
            'created_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
        ];
    }

    /**
     * Frozen plan shape for `/platform/plans`.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function plan(array $row): array
    {
        $status = (string) ($row['status'] ?? 'active');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'monthly_price_cents' => (int) ($row['monthly_price_cents'] ?? 0),
            'currency' => (string) ($row['currency'] ?? 'KES'),
            'household_limit' => $row['household_limit'] === null ? null : (int) $row['household_limit'],
            'gate_device_limit' => $row['gate_device_limit'] === null ? null : (int) $row['gate_device_limit'],
            'features' => self::features((string) ($row['features'] ?? '')),
            'status' => $status,
            'status_label' => self::planStatusLabel($status),
            'status_tone' => self::planStatusTone($status),
            'subscriber_count' => (int) ($row['subscriber_count'] ?? 0),
            'created_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
        ];
    }

    /**
     * Frozen subscription shape for `/platform/subscriptions`.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function subscription(array $row): array
    {
        $status = (string) ($row['status'] ?? 'trialing');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'estate_uuid' => (string) ($row['estate_uuid'] ?? ''),
            'estate_name' => (string) ($row['estate_name'] ?? ''),
            'plan_name' => (string) ($row['plan_name'] ?? ''),
            'plan_code' => (string) ($row['plan_code'] ?? ''),
            'plan_uuid' => (string) ($row['plan_uuid'] ?? ''),
            'status' => $status,
            'status_label' => self::subscriptionStatusLabel($status),
            'status_tone' => self::subscriptionStatusTone($status),
            'billing_cycle' => (string) ($row['billing_cycle'] ?? 'monthly'),
            'starts_at' => self::iso(isset($row['starts_at']) ? (string) $row['starts_at'] : null),
            'ends_at' => self::iso(isset($row['ends_at']) ? (string) $row['ends_at'] : null),
            'price_cents' => (int) ($row['monthly_price_cents'] ?? 0),
            'currency' => (string) ($row['currency'] ?? 'KES'),
        ];
    }

    /**
     * Audit viewer entry (dashboard-only audit).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function auditEntry(array $row): array
    {
        $action = (string) ($row['action'] ?? '');

        return [
            'action' => $action,
            'summary' => self::auditSummary($action),
            'occurred_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
            'estate_name' => $row['estate_name'] === null ? null : (string) $row['estate_name'],
        ];
    }

    public static function auditSummary(string $action): string
    {
        $map = [
            'platform.estate_created' => 'Estate provisioned',
            'platform.estate_status_changed' => 'Estate status changed',
            'platform.plan_created' => 'Subscription plan created',
            'platform.plan_retired' => 'Subscription plan retired',
            'platform.subscription_created' => 'Subscription created',
            'platform.subscription_changed' => 'Subscription updated',
        ];

        return $map[$action] ?? ucfirst(str_replace(['platform.', '_'], ['', ' '], $action));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function accountOptions(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'uuid' => (string) $row['uuid'],
            'label' => (string) $row['name'],
        ], $rows);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function planOptions(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'uuid' => (string) $row['uuid'],
            'label' => (string) $row['name'] . ' (' . (string) $row['code'] . ')',
        ], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function features(string $json): ?array
    {
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function iso(?string $utc): ?string
    {
        return Clock::fromDb($utc)?->format('Y-m-d\TH:i:s.v\Z');
    }
}
