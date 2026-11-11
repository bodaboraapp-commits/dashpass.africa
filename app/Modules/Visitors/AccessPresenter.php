<?php

declare(strict_types=1);

namespace App\Modules\Visitors;

use App\Core\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Single source of truth for visitor module payload shapes and labels.
 *
 * The canonical shapes are fixed by the visitor-access contract §6 and §7.1:
 * dates are ISO-8601 UTC strings and status tones are ok|warn|danger|neutral.
 */
final class AccessPresenter
{
    /** @var array<string, string> */
    private const LABELS = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'checked_in' => 'Inside',
        'checked_out' => 'Checked out',
        'revoked' => 'Revoked',
        'expired' => 'Expired',
        'denied' => 'Denied',
    ];

    /** @var array<string, string> */
    private const TONES = [
        'pending' => 'warn',
        'approved' => 'ok',
        'checked_in' => 'ok',
        'checked_out' => 'neutral',
        'revoked' => 'danger',
        'expired' => 'warn',
        'denied' => 'danger',
    ];

    public static function statusLabel(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function statusTone(string $status): string
    {
        return self::TONES[$status] ?? 'neutral';
    }

    /**
     * Convert a stored UTC DATETIME(3) value to an ISO-8601 UTC string.
     */
    public static function iso(?string $value): ?string
    {
        $moment = self::toUtc($value);

        return $moment?->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Human label for a household, e.g. "A-001 · Block A".
     *
     * @param array<string, mixed> $row
     */
    public static function householdLabel(array $row): string
    {
        $unit = trim((string) ($row['unit_number'] ?? ''));
        $building = trim((string) ($row['building_name'] ?? ''));

        if ($unit === '') {
            return $building;
        }

        return $building === '' ? $unit : $unit . ' · ' . $building;
    }

    /**
     * Canonical invitation Item (contract §6).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function item(array $row): array
    {
        $status = (string) ($row['status'] ?? 'pending');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'visitor_name' => (string) ($row['visitor_name'] ?? ''),
            'purpose' => isset($row['purpose']) && (string) $row['purpose'] !== '' ? (string) $row['purpose'] : null,
            'valid_from' => self::iso(isset($row['valid_from']) ? (string) $row['valid_from'] : null),
            'valid_until' => self::iso(isset($row['valid_until']) ? (string) $row['valid_until'] : null),
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'status_tone' => self::statusTone($status),
            'household_label' => self::householdLabel($row),
            'already_inside' => $status === 'checked_in',
        ];
    }

    /**
     * Canonical walk-in Item (contract §6).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function walkIn(array $row): array
    {
        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'visitor_name' => (string) ($row['visitor_name'] ?? ''),
            'purpose' => isset($row['purpose']) && (string) $row['purpose'] !== '' ? (string) $row['purpose'] : null,
            'requested_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
            'gate_name' => (string) ($row['gate_name'] ?? ''),
            'household_label' => self::householdLabel($row),
        ];
    }

    /**
     * Resident pass shape: Item + estate/host/visitor metadata (§7.1).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function pass(array $row): array
    {
        return self::item($row) + [
            'estate_name' => (string) ($row['estate_name'] ?? ''),
            'visitor_name' => (string) ($row['visitor_name'] ?? ''),
            'visitor_count' => (int) ($row['visitor_count'] ?? 1),
            'host_label' => (string) ($row['host_label'] ?? ''),
            'created_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
        ];
    }

    /**
     * A date in the estate's timezone, e.g. "25 May 2026".
     */
    public static function date(string $value, string $timezone): string
    {
        $moment = self::toUtc($value);

        if ($moment === null) {
            return '—';
        }

        try {
            return $moment->setTimezone(new DateTimeZone($timezone))->format('d M Y');
        } catch (Throwable) {
            return '—';
        }
    }

    /**
     * A time in the estate's timezone, e.g. "10:00".
     */
    public static function time(string $value, string $timezone): string
    {
        $moment = self::toUtc($value);

        if ($moment === null) {
            return '—';
        }

        try {
            return $moment->setTimezone(new DateTimeZone($timezone))->format('H:i');
        } catch (Throwable) {
            return '—';
        }
    }

    /**
     * Full validity window for SMS templates.
     *
     * @return array{date: string, start: string, end: string}
     */
    public static function window(string $validFrom, string $validUntil, string $timezone): array
    {
        return [
            'date' => self::date($validFrom, $timezone),
            'start' => self::time($validFrom, $timezone),
            'end' => self::time($validUntil, $timezone),
        ];
    }

    /**
     * Format a stored UTC value as local wall-clock time for a form field.
     */
    public static function localFormValue(?string $value, string $timezone): string
    {
        $moment = self::toUtc($value);

        if ($moment === null) {
            return '';
        }

        try {
            return $moment->setTimezone(new DateTimeZone($timezone))->format('Y-m-d\TH:i');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Effective public pass status: combines the invitation lifecycle with the
     * credential state and the wall clock so the guest page is honest.
     *
     * @param array<string, mixed> $row
     */
    public static function publicStatus(array $row): string
    {
        $invitationStatus = (string) ($row['status'] ?? 'approved');
        $credentialStatus = (string) ($row['credential_status'] ?? 'active');
        $validUntil = self::toUtc(isset($row['valid_until']) ? (string) $row['valid_until'] : null);
        $now = Clock::now();

        if ($invitationStatus === 'revoked' || $credentialStatus === 'revoked') {
            return 'revoked';
        }

        if (
            $credentialStatus === 'expired'
            || $invitationStatus === 'expired'
            || ($validUntil !== null && $validUntil <= $now)
        ) {
            return 'expired';
        }

        if ($invitationStatus === 'checked_out') {
            return 'checked_out';
        }

        if ($invitationStatus === 'checked_in') {
            return 'checked_in';
        }

        if ($invitationStatus === 'denied') {
            return 'denied';
        }

        return $invitationStatus === 'approved' ? 'approved' : 'pending';
    }

    private static function toUtc(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $fromDb = Clock::fromDb($value);

        if ($fromDb !== null) {
            return $fromDb;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(Clock::utc());
        } catch (Throwable) {
            return null;
        }
    }
}
