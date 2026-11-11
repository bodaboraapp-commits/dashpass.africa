<?php

declare(strict_types=1);

/**
 * Shared helpers for the platform portal views (Increment 9).
 *
 * Kept separate so every platform screen normalises frozen option lists, money
 * and stat values the same way. All helpers are defensive: they accept absent or
 * malformed integration data and never throw.
 */

require_once __DIR__ . '/../admin/_helpers.php';

/** @return array<string, string> map of value => label from [{value,label}] or strings */
function platform_option_map(array $items, array $fallback = []): array
{
    $out = [];
    foreach ($items as $item) {
        if (is_string($item) && trim($item) !== '') {
            $out[trim($item)] = admin_humanize(trim($item));
            continue;
        }
        if (!is_array($item)) {
            continue;
        }
        $value = trim((string) ($item['value'] ?? $item['uuid'] ?? $item['code'] ?? ''));
        if ($value === '') {
            continue;
        }
        $label = trim((string) ($item['label'] ?? $item['name'] ?? ''));
        $out[$value] = $label !== '' ? $label : admin_humanize($value);
    }

    return $out !== [] ? $out : $fallback;
}

/** Humanise a slug/status/type value. */
function platform_humanize(string $value): string
{
    return $value === '' ? '' : admin_humanize($value);
}

/** Format a price in cents into a currency string; returns '' when unknown. */
function platform_money(mixed $cents, string $currency = 'KES'): string
{
    if (!is_numeric($cents)) {
        return '';
    }
    $amount = (int) $cents;
    $currency = strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'KES';
    $formatted = number_format($amount / 100, 2);

    return $currency . ' ' . $formatted;
}

/**
 * Read a numeric stat from a stats array, supporting several frozen aliases.
 * Arrays are counted so a list of estates can also answer "how many".
 *
 * @param array<int, string> $keys
 */
function platform_stat(array $stats, array $keys, ?int $default = null): ?int
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $stats)) {
            continue;
        }
        $value = $stats[$key];
        if (is_array($value)) {
            return count($value);
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
    }

    return $default;
}

/** Read a scalar string from a row (first alias that is scalar). */
function platform_text(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $value = $row[$key];
        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    return $default;
}

/** First message from an errors map entry. */
function platform_field_error(array $errors, string $field): string
{
    return admin_field_error($errors, $field);
}

/** Flatten an errors map into a short list of unique messages. */
function platform_error_list(array $errors): array
{
    $out = [];
    foreach ($errors as $messages) {
        if (is_string($messages)) {
            $messages = [$messages];
        }
        if (!is_array($messages)) {
            continue;
        }
        foreach ($messages as $message) {
            if (is_scalar($message) && trim((string) $message) !== '') {
                $out[] = trim((string) $message);
            }
        }
    }

    return array_values(array_unique($out));
}
