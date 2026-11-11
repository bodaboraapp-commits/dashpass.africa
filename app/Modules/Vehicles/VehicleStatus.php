<?php

declare(strict_types=1);

namespace App\Modules\Vehicles;

/**
 * Vehicle status presentation labels/tones and the allowed vehicle types.
 *
 * Status text always accompanies any colour so UI states are accessible
 * (Increment 3 contract §2, frozen view-data contract #1).
 */
final class VehicleStatus
{
    /** @var array<string, string> */
    private const LABELS = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'blacklisted' => 'Blacklisted',
    ];

    /** @var array<string, string> */
    private const TONES = [
        'active' => 'ok',
        'inactive' => 'neutral',
        'blacklisted' => 'danger',
    ];

    /** @var array<string, string> */
    private const TYPES = [
        'car' => 'Car',
        'motorcycle' => 'Motorcycle',
        'van' => 'Van',
        'truck' => 'Truck',
        'other' => 'Other',
    ];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function tone(string $status): string
    {
        return self::TONES[$status] ?? 'neutral';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function types(): array
    {
        $types = [];

        foreach (self::TYPES as $value => $label) {
            $types[] = ['value' => $value, 'label' => $label];
        }

        return $types;
    }

    /**
     * @return array<int, string>
     */
    public static function typeValues(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * Normalise a plate for storage/matching: uppercase, letters+digits only.
     */
    public static function normalizePlate(string $input): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
    }
}
