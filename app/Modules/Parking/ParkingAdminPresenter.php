<?php

declare(strict_types=1);

namespace App\Modules\Parking;

use App\Core\Clock;

/**
 * Canonical parking-administration option lists, labels, tones and frozen view
 * shapes (Increment 8 §2-§4; T60 frozen keys for T61 views).
 */
final class ParkingAdminPresenter
{
    /** @var array<string, string> */
    private const ZONE_TYPE_LABELS = [
        'resident' => 'Resident',
        'visitor' => 'Visitor',
        'mixed' => 'Mixed',
        'service' => 'Service',
    ];

    /** @var array<string, string> */
    private const BAY_TYPE_LABELS = [
        'resident' => 'Resident',
        'visitor' => 'Visitor',
        'accessible' => 'Accessible',
        'service' => 'Service',
    ];

    /** @var array<string, string> */
    private const BAY_STATUS_LABELS = [
        'available' => 'Available',
        'reserved' => 'Reserved',
        'occupied' => 'Occupied',
        'maintenance' => 'Maintenance',
    ];

    /** @var array<string, string> */
    private const BAY_STATUS_TONES = [
        'available' => 'ok',
        'reserved' => 'warn',
        'occupied' => 'warn',
        'maintenance' => 'neutral',
    ];

    /** @var array<string, string> */
    private const ALLOCATION_STATUS_LABELS = [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'ended' => 'Ended',
    ];

    /** @var array<string, string> */
    private const ALLOCATION_STATUS_TONES = [
        'active' => 'ok',
        'suspended' => 'warn',
        'ended' => 'neutral',
    ];

    /** @var array<string, string> */
    private const VIOLATION_TYPE_LABELS = [
        'overstay' => 'Overstay',
        'unauthorised_bay' => 'Unauthorised bay',
        'unregistered_vehicle' => 'Unregistered vehicle',
        'other' => 'Other',
    ];

    /** @var array<string, string> */
    private const VIOLATION_STATUS_LABELS = [
        'open' => 'Open',
        'resolved' => 'Resolved',
        'dismissed' => 'Dismissed',
    ];

    /** @var array<string, string> */
    private const VIOLATION_STATUS_TONES = [
        'open' => 'warn',
        'resolved' => 'ok',
        'dismissed' => 'neutral',
    ];

    /** @var array<int, string> */
    public const ZONE_TYPES = ['resident', 'visitor', 'mixed', 'service'];

    /** @var array<int, string> */
    public const BAY_TYPES = ['resident', 'visitor', 'accessible', 'service'];

    /** @var array<int, string> */
    public const BAY_STATUSES = ['available', 'reserved', 'occupied', 'maintenance'];

    /** @var array<int, string> */
    public const ALLOCATION_STATUSES = ['active', 'suspended', 'ended'];

    /** @var array<int, string> */
    public const VIOLATION_TYPES = ['overstay', 'unauthorised_bay', 'unregistered_vehicle', 'other'];

    /** @var array<int, string> */
    public const VIOLATION_STATUSES = ['open', 'resolved', 'dismissed'];

    /* ------------------------------------------------------------------ */
    /* Options                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function zoneTypes(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::ZONE_TYPE_LABELS[$value],
        ], self::ZONE_TYPES);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function bayTypes(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::BAY_TYPE_LABELS[$value],
        ], self::BAY_TYPES);
    }

    /**
     * @return array<int, array{value: string, label: string, tone: string}>
     */
    public function bayStatuses(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::BAY_STATUS_LABELS[$value],
            'tone' => self::BAY_STATUS_TONES[$value],
        ], self::BAY_STATUSES);
    }

    /**
     * @return array<int, array{value: string, label: string, tone: string}>
     */
    public function allocationStatuses(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::ALLOCATION_STATUS_LABELS[$value],
            'tone' => self::ALLOCATION_STATUS_TONES[$value],
        ], self::ALLOCATION_STATUSES);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function violationTypes(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::VIOLATION_TYPE_LABELS[$value],
        ], self::VIOLATION_TYPES);
    }

    /**
     * @return array<int, array{value: string, label: string, tone: string}>
     */
    public function violationStatuses(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::VIOLATION_STATUS_LABELS[$value],
            'tone' => self::VIOLATION_STATUS_TONES[$value],
        ], self::VIOLATION_STATUSES);
    }

    /* ------------------------------------------------------------------ */
    /* Labels / tones                                                     */
    /* ------------------------------------------------------------------ */

    public static function zoneTypeLabel(string $type): string
    {
        return self::ZONE_TYPE_LABELS[$type] ?? ucfirst($type);
    }

    public static function bayTypeLabel(string $type): string
    {
        return self::BAY_TYPE_LABELS[$type] ?? ucfirst($type);
    }

    public static function bayStatusLabel(string $status): string
    {
        return self::BAY_STATUS_LABELS[$status] ?? ucfirst($status);
    }

    public static function bayStatusTone(string $status): string
    {
        return self::BAY_STATUS_TONES[$status] ?? 'neutral';
    }

    public static function allocationStatusLabel(string $status): string
    {
        return self::ALLOCATION_STATUS_LABELS[$status] ?? ucfirst($status);
    }

    public static function allocationStatusTone(string $status): string
    {
        return self::ALLOCATION_STATUS_TONES[$status] ?? 'neutral';
    }

    public static function violationTypeLabel(string $type): string
    {
        return self::VIOLATION_TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    public static function violationStatusLabel(string $status): string
    {
        return self::VIOLATION_STATUS_LABELS[$status] ?? ucfirst($status);
    }

    public static function violationStatusTone(string $status): string
    {
        return self::VIOLATION_STATUS_TONES[$status] ?? 'neutral';
    }

    /* ------------------------------------------------------------------ */
    /* Frozen shapes                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function zone(array $row): array
    {
        $type = (string) ($row['zone_type'] ?? 'visitor');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'zone_type' => $type,
            'zone_type_label' => self::zoneTypeLabel($type),
            'bay_count' => (int) ($row['bay_count'] ?? 0),
            'created_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function bay(array $row): array
    {
        $type = (string) ($row['bay_type'] ?? 'visitor');
        $status = (string) ($row['status'] ?? 'available');
        $allocated = self::householdLabel($row, 'allocated_');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'bay_code' => (string) ($row['bay_code'] ?? ''),
            'zone_uuid' => (string) ($row['zone_uuid'] ?? ''),
            'zone_name' => (string) ($row['zone_name'] ?? ''),
            'bay_type' => $type,
            'bay_type_label' => self::bayTypeLabel($type),
            'status' => $status,
            'status_label' => self::bayStatusLabel($status),
            'status_tone' => self::bayStatusTone($status),
            'allocated' => $allocated,
            'allocation_label' => $allocated,
            'created_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function allocation(array $row): array
    {
        $status = (string) ($row['status'] ?? 'active');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'bay_uuid' => (string) ($row['bay_uuid'] ?? ''),
            'bay_code' => (string) ($row['bay_code'] ?? ''),
            'household_uuid' => (string) ($row['household_uuid'] ?? ''),
            'household_label' => self::householdLabel($row),
            'vehicle_uuid' => $row['vehicle_uuid'] === null ? null : (string) $row['vehicle_uuid'],
            'vehicle_plate' => $row['plate_number'] === null ? null : (string) $row['plate_number'],
            'starts_at' => self::iso(isset($row['starts_at']) ? (string) $row['starts_at'] : null),
            'ends_at' => self::iso(isset($row['ends_at']) ? (string) $row['ends_at'] : null),
            'status' => $status,
            'status_label' => self::allocationStatusLabel($status),
            'status_tone' => self::allocationStatusTone($status),
            'created_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function violation(array $row): array
    {
        $type = (string) ($row['violation_type'] ?? 'other');
        $status = (string) ($row['status'] ?? 'open');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'type' => $type,
            'type_label' => self::violationTypeLabel($type),
            'status' => $status,
            'status_label' => self::violationStatusLabel($status),
            'status_tone' => self::violationStatusTone($status),
            'bay_uuid' => (string) ($row['bay_uuid'] ?? ''),
            'bay_code' => (string) ($row['bay_code'] ?? ''),
            'plate_number' => $row['plate_number'] === null ? null : (string) $row['plate_number'],
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'reported_by' => (string) ($row['reported_by_name'] ?? ''),
            'created_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
            'resolved_at' => self::iso(isset($row['resolved_at']) ? (string) $row['resolved_at'] : null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function householdLabel(array $row, string $prefix = ''): ?string
    {
        $unit = (string) ($row[$prefix . 'unit_number'] ?? '');

        if ($unit === '') {
            return null;
        }

        $building = (string) ($row[$prefix . 'building_name'] ?? '');

        return $building === '' ? $unit : $unit . ' · ' . $building;
    }

    public static function iso(?string $utc): ?string
    {
        return Clock::fromDb($utc)?->format('Y-m-d\TH:i:s.v\Z');
    }
}
