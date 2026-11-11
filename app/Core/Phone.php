<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Phone number normalisation.
 *
 * MtaaPass stores and matches phone numbers in E.164 form (Blueprint §14).
 * Kenyan local formats are accepted from the UI and converted server-side.
 */
final class Phone
{
    /**
     * Normalise a user-supplied phone number to E.164.
     *
     * Accepts: +254712345678, 254712345678, 0712345678, 0712 345 678,
     * 07-12-345-678. Anything that cannot be normalised throws.
     */
    public static function normalize(string $input, string $countryCode = '254'): string
    {
        $countryCode = preg_replace('/\D+/', '', $countryCode) ?? '';
        $original = trim($input);

        if ($original === '') {
            throw new InvalidArgumentException('A phone number is required.');
        }

        // Keep an explicit international marker, discard common separators.
        $startsWithPlus = str_starts_with($original, '+');
        $digits = preg_replace('/[^0-9]/', '', $original) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('The phone number does not contain any digits.');
        }

        if ($startsWithPlus) {
            $candidate = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $candidate = substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && $countryCode !== '') {
            // Local national format: replace the trunk zero with the country code.
            $candidate = $countryCode . substr($digits, 1);
        } elseif ($countryCode !== '' && strlen($digits) <= 9 && !str_starts_with($digits, $countryCode)) {
            // Bare subscriber number such as 712345678.
            $candidate = $countryCode . $digits;
        } else {
            $candidate = $digits;
        }

        $candidate = ltrim($candidate, '0');

        if (!preg_match('/^[1-9][0-9]{7,14}$/', $candidate)) {
            throw new InvalidArgumentException('The phone number is not a valid international number.');
        }

        return '+' . $candidate;
    }

    public static function isValid(string $e164): bool
    {
        return preg_match('/^\+[1-9][0-9]{7,14}$/', $e164) === 1;
    }

    /**
     * Human-friendly display form, e.g. +254 712 345 678.
     */
    public static function display(string $e164): string
    {
        if (!self::isValid($e164)) {
            return $e164;
        }

        $digits = substr($e164, 1);

        if (str_starts_with($digits, '254') && strlen($digits) === 12) {
            return '+254 ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 3) . ' ' . substr($digits, 9);
        }

        return '+' . substr($digits, 0, 3) . ' ' . substr($digits, 3);
    }

    /**
     * Mask a phone number for display in audit/UI contexts.
     */
    public static function mask(string $e164): string
    {
        if (strlen($e164) < 6) {
            return '***';
        }

        return substr($e164, 0, 5) . str_repeat('*', max(0, strlen($e164) - 8)) . substr($e164, -3);
    }
}
