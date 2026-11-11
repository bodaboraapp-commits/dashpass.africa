<?php

declare(strict_types=1);

namespace App\Modules\Incidents;

use App\Core\Clock;

/**
 * Canonical incident option lists, labels, tones and frozen view shapes
 * (Increment 6 §3; task-38 frozen keys for T39 views).
 */
final class IncidentPresenter
{
    /** @var array<int, string> */
    public const TYPES = [
        'unauthorised_access',
        'tailgating',
        'lost_pass',
        'suspicious_activity',
        'property_damage',
        'medical',
        'other',
    ];

    /** @var array<int, string> */
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    /** @var array<int, string> */
    public const STATUSES = ['open', 'investigating', 'resolved', 'dismissed'];

    /** @var array<string, string> */
    private const TYPE_LABELS = [
        'unauthorised_access' => 'Unauthorised access',
        'tailgating' => 'Tailgating',
        'lost_pass' => 'Lost pass',
        'suspicious_activity' => 'Suspicious activity',
        'property_damage' => 'Property damage',
        'medical' => 'Medical',
        'other' => 'Other',
    ];

    /** @var array<string, string> */
    private const SEVERITY_TONES = [
        'low' => 'neutral',
        'medium' => 'warn',
        'high' => 'danger',
        'critical' => 'danger',
    ];

    /** @var array<string, string> */
    private const STATUS_TONES = [
        'open' => 'warn',
        'investigating' => 'neutral',
        'resolved' => 'ok',
        'dismissed' => 'neutral',
    ];

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function types(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::typeLabel($value),
        ], self::TYPES);
    }

    /**
     * @return array<int, array{value: string, label: string, tone: string}>
     */
    public function severities(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::severityLabel($value),
            'tone' => self::severityTone($value),
        ], self::SEVERITIES);
    }

    /**
     * @return array<int, array{value: string, label: string, tone: string}>
     */
    public function statuses(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::statusLabel($value),
            'tone' => self::statusTone($value),
        ], self::STATUSES);
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    public static function severityLabel(string $severity): string
    {
        return ucfirst($severity);
    }

    public static function severityTone(string $severity): string
    {
        return self::SEVERITY_TONES[$severity] ?? 'neutral';
    }

    public static function statusLabel(string $status): string
    {
        return ucfirst($status);
    }

    public static function statusTone(string $status): string
    {
        return self::STATUS_TONES[$status] ?? 'neutral';
    }

    public static function isTerminal(string $status): bool
    {
        return $status === 'resolved' || $status === 'dismissed';
    }

    /**
     * Frozen list item shape used by resident/guard/admin lists and APIs.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function item(array $row): array
    {
        $severity = (string) ($row['severity'] ?? 'medium');
        $status = (string) ($row['status'] ?? 'open');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'incident_type' => (string) ($row['incident_type'] ?? 'other'),
            'incident_type_label' => self::typeLabel((string) ($row['incident_type'] ?? 'other')),
            'severity' => $severity,
            'severity_label' => self::severityLabel($severity),
            'tone' => self::severityTone($severity),
            'summary' => (string) ($row['summary'] ?? ''),
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'status_tone' => self::statusTone($status),
            'occurred_at' => self::iso(isset($row['occurred_at']) ? (string) $row['occurred_at'] : null),
            'gate_name' => $row['gate_name'] === null ? null : (string) $row['gate_name'],
            'household_label' => self::householdLabel($row),
            'reported_by' => (string) ($row['reported_by_name'] ?? ''),
        ];
    }

    /**
     * Frozen admin detail shape (list item + description/resolution/timestamps).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function detail(array $row): array
    {
        $item = self::item($row);

        return $item + [
            'description' => (string) ($row['description'] ?? ''),
            'gate_uuid' => $row['gate_uuid'] === null ? null : (string) $row['gate_uuid'],
            'household_uuid' => $row['household_uuid'] === null ? null : (string) $row['household_uuid'],
            'invitation_uuid' => $row['invitation_uuid'] === null ? null : (string) $row['invitation_uuid'],
            'invitation_visitor_name' => $row['invitation_visitor_name'] === null ? null : (string) $row['invitation_visitor_name'],
            'reported_by_uuid' => $row['reported_by_uuid'] === null ? null : (string) $row['reported_by_uuid'],
            'resolved_at' => self::iso(isset($row['resolved_at']) ? (string) $row['resolved_at'] : null),
            'resolved_by' => $row['resolved_by_name'] === null ? null : (string) $row['resolved_by_name'],
            'resolution_notes' => $row['resolution_notes'] === null ? null : (string) $row['resolution_notes'],
            'created_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
            'updated_at' => self::iso(isset($row['updated_at']) ? (string) $row['updated_at'] : null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function householdLabel(array $row): ?string
    {
        $unit = (string) ($row['unit_number'] ?? '');

        if ($unit === '') {
            return null;
        }

        $building = (string) ($row['building_name'] ?? '');

        return $building === '' ? $unit : $unit . ' · ' . $building;
    }

    public static function iso(?string $utc): ?string
    {
        return Clock::fromDb($utc)?->format('Y-m-d\TH:i:s.v\Z');
    }
}
