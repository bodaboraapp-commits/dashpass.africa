<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Time handling. Everything is stored in UTC; estate-local conversion happens
 * only for display and business-day reporting (Blueprint §14).
 */
final class Clock
{
    public const DB_FORMAT = 'Y-m-d H:i:s.v';

    private static ?DateTimeZone $utc = null;

    public static function utc(): DateTimeZone
    {
        return self::$utc ??= new DateTimeZone('UTC');
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::utc());
    }

    /**
     * Current time as a storage-safe string for DATETIME(3) columns.
     */
    public static function nowDb(): string
    {
        return self::toDb(self::now());
    }

    public static function toDb(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(self::utc())
            ->format(self::DB_FORMAT);
    }

    public static function fromDb(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, self::utc());
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Parse a client-supplied ISO-8601 timestamp into UTC. Throws on malformed
     * input so the validator can report a clean 422.
     */
    public static function parse(string $value): DateTimeImmutable
    {
        try {
            $parsed = new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new InvalidArgumentException('The supplied date/time is not valid ISO-8601.');
        }

        return $parsed->setTimezone(self::utc());
    }

    public static function secondsUntil(?DateTimeInterface $moment): int
    {
        if ($moment === null) {
            return 0;
        }

        return max(0, $moment->getTimestamp() - self::now()->getTimestamp());
    }

    public static function display(?DateTimeInterface $moment, string $timezone): string
    {
        if ($moment === null) {
            return '-';
        }

        try {
            return $moment->setTimezone(new DateTimeZone($timezone))->format('d M Y, H:i');
        } catch (\Exception) {
            return '-';
        }
    }
}
