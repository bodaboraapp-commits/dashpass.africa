<?php

declare(strict_types=1);

namespace App\Modules\Parking;

use App\Core\Clock;
use App\Modules\Vehicles\VehicleStatus;
use App\Modules\Visitors\AccessPresenter;

/**
 * Canonical vehicles/parking payload shapes (Increment 3 contract §2–§4 and the
 * frozen T20↔T21 view-data contract). Dates are ISO-8601 UTC strings; statuses
 * always carry a paired text label and tone so views never rely on colour alone.
 */
final class ParkingPresenter
{
    /** @var array<string, string> */
    private const BAY_LABELS = [
        'available' => 'Available',
        'reserved' => 'Reserved',
        'occupied' => 'Occupied',
        'maintenance' => 'Maintenance',
    ];

    /** @var array<string, string> */
    private const BAY_TONES = [
        'available' => 'ok',
        'reserved' => 'warn',
        'occupied' => 'danger',
        'maintenance' => 'neutral',
    ];

    /** @var array<string, string> */
    private const RESERVATION_LABELS = [
        'reserved' => 'Reserved',
        'checked_in' => 'Checked in',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    ];

    /** @var array<string, string> */
    private const RESERVATION_TONES = [
        'reserved' => 'warn',
        'checked_in' => 'ok',
        'completed' => 'neutral',
        'cancelled' => 'danger',
        'expired' => 'warn',
    ];

    /** @var array<string, string> */
    private const ALLOCATION_LABELS = [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'ended' => 'Ended',
    ];

    /** @var array<string, string> */
    private const ALLOCATION_TONES = [
        'active' => 'ok',
        'suspended' => 'warn',
        'ended' => 'neutral',
    ];

    /** @var array<string, string> */
    private const SESSION_LABELS = [
        'active' => 'Active',
        'completed' => 'Completed',
        'overstay' => 'Overstay',
        'void' => 'Void',
    ];

    /** @var array<string, string> */
    private const SESSION_TONES = [
        'active' => 'ok',
        'completed' => 'neutral',
        'overstay' => 'danger',
        'void' => 'neutral',
    ];

    public static function bayLabel(string $status): string
    {
        return self::BAY_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function bayTone(string $status): string
    {
        return self::BAY_TONES[$status] ?? 'neutral';
    }

    public static function reservationLabel(string $status): string
    {
        return self::RESERVATION_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function reservationTone(string $status): string
    {
        return self::RESERVATION_TONES[$status] ?? 'neutral';
    }

    public static function allocationLabel(string $status): string
    {
        return self::ALLOCATION_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function allocationTone(string $status): string
    {
        return self::ALLOCATION_TONES[$status] ?? 'neutral';
    }

    public static function sessionLabel(string $status): string
    {
        return self::SESSION_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function sessionTone(string $status): string
    {
        return self::SESSION_TONES[$status] ?? 'neutral';
    }

    /**
     * Vehicle item shape (frozen contract #1).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function vehicle(array $row): array
    {
        $status = (string) ($row['status'] ?? 'active');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'plate_number' => (string) ($row['plate_number'] ?? ''),
            'vehicle_type' => (string) ($row['vehicle_type'] ?? 'car'),
            'colour' => isset($row['colour']) && (string) $row['colour'] !== '' ? (string) $row['colour'] : null,
            'status' => $status,
            'status_label' => VehicleStatus::label($status),
            'status_tone' => VehicleStatus::tone($status),
        ];
    }

    /**
     * Availability bay shape (frozen contract #3/#8).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function bay(array $row, bool $available): array
    {
        $status = (string) ($row['status'] ?? 'available');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'bay_code' => (string) ($row['bay_code'] ?? ''),
            'zone_name' => (string) ($row['zone_name'] ?? ''),
            'bay_type' => (string) ($row['bay_type'] ?? 'visitor'),
            'status' => $status,
            'available' => $available,
        ];
    }

    /**
     * Reservation shape (frozen contract #3).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function reservation(array $row): array
    {
        $status = (string) ($row['status'] ?? 'reserved');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'invitation_uuid' => (string) ($row['invitation_uuid'] ?? ''),
            'visitor_name' => (string) ($row['visitor_name'] ?? ''),
            'plate_number' => isset($row['plate_number']) && (string) $row['plate_number'] !== ''
                ? (string) $row['plate_number']
                : null,
            'bay_code' => (string) ($row['bay_code'] ?? ''),
            'zone_name' => (string) ($row['zone_name'] ?? ''),
            'starts_at' => AccessPresenter::iso(isset($row['starts_at']) ? (string) $row['starts_at'] : null),
            'ends_at' => AccessPresenter::iso(isset($row['ends_at']) ? (string) $row['ends_at'] : null),
            'status' => $status,
            'status_label' => self::reservationLabel($status),
            'status_tone' => self::reservationTone($status),
        ];
    }

    /**
     * Allocation shape (frozen contract #2).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function allocation(array $row): array
    {
        $status = (string) ($row['status'] ?? 'active');
        $vehicle = null;

        if (isset($row['vehicle_plate']) && (string) $row['vehicle_plate'] !== '') {
            $vehicle = [
                'plate_number' => (string) $row['vehicle_plate'],
                'vehicle_type' => (string) ($row['vehicle_type'] ?? 'car'),
                'colour' => isset($row['vehicle_colour']) && (string) $row['vehicle_colour'] !== ''
                    ? (string) $row['vehicle_colour']
                    : null,
            ];
        }

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'bay_code' => (string) ($row['bay_code'] ?? ''),
            'zone_name' => (string) ($row['zone_name'] ?? ''),
            'status' => $status,
            'status_label' => self::allocationLabel($status),
            'status_tone' => self::allocationTone($status),
            'vehicle' => $vehicle,
        ];
    }

    /**
     * Reservation-lite shape used on pass screens / create responses (#5/#7).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function passParking(array $row): array
    {
        $status = (string) ($row['status'] ?? 'reserved');

        return [
            'reservation_uuid' => (string) ($row['uuid'] ?? ''),
            'bay_code' => (string) ($row['bay_code'] ?? ''),
            'zone_name' => (string) ($row['zone_name'] ?? ''),
            'starts_at' => AccessPresenter::iso(isset($row['starts_at']) ? (string) $row['starts_at'] : null),
            'ends_at' => AccessPresenter::iso(isset($row['ends_at']) ? (string) $row['ends_at'] : null),
            'status' => $status,
            'status_label' => self::reservationLabel($status),
            'status_tone' => self::reservationTone($status),
        ];
    }

    /**
     * Public pass parking shape (#6): bay code + zone only, never the plate.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function publicParking(array $row): array
    {
        return [
            'bay_code' => (string) ($row['bay_code'] ?? ''),
            'zone_name' => (string) ($row['zone_name'] ?? ''),
            'starts_at' => AccessPresenter::iso(isset($row['starts_at']) ? (string) $row['starts_at'] : null),
            'ends_at' => AccessPresenter::iso(isset($row['ends_at']) ? (string) $row['ends_at'] : null),
        ];
    }

    /**
     * Parking session shape for guard APIs.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function session(array $row): array
    {
        $status = (string) ($row['status'] ?? 'active');

        return [
            'session_uuid' => (string) ($row['uuid'] ?? ''),
            'invitation_uuid' => (string) ($row['invitation_uuid'] ?? ''),
            'bay_code' => (string) ($row['bay_code'] ?? ''),
            'zone_name' => (string) ($row['zone_name'] ?? ''),
            'visitor_name' => (string) ($row['visitor_name'] ?? ''),
            'plate_number' => isset($row['plate_number']) && (string) $row['plate_number'] !== ''
                ? (string) $row['plate_number']
                : null,
            'checkin_at' => AccessPresenter::iso(isset($row['checkin_at']) ? (string) $row['checkin_at'] : null),
            'checkout_at' => AccessPresenter::iso(isset($row['checkout_at']) ? (string) $row['checkout_at'] : null),
            'status' => $status,
            'status_label' => self::sessionLabel($status),
            'status_tone' => self::sessionTone($status),
        ];
    }

    /**
     * ISO-8601 UTC now plus an offset, for default availability windows.
     */
    public static function isoNow(int $offsetSeconds = 0): string
    {
        return AccessPresenter::iso(Clock::toDb(Clock::now()->modify(($offsetSeconds >= 0 ? '+' : '') . $offsetSeconds . ' seconds'))) ?? '';
    }
}
