<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Environment variable loader and accessor.
 *
 * Precedence: real process environment > values parsed from `.env` > default.
 * Secrets never need to live in source control, and test runners can override
 * configuration with putenv() before the application boots.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    private static bool $loaded = false;

    /**
     * Parse a `.env` file. Existing real environment variables are not
     * overwritten.
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '') {
                continue;
            }

            self::$values[$key] = self::parseValue($value);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $fromProcess = getenv($key);

        if ($fromProcess !== false) {
            return $fromProcess;
        }

        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        return $default;
    }

    /**
     * Force a value at runtime. Used by the test bootstrap; never used by
     * production request handling.
     */
    public static function set(string $key, ?string $value): void
    {
        if ($value === null) {
            unset(self::$values[$key]);
            putenv($key);

            return;
        }

        self::$values[$key] = $value;
        putenv($key . '=' . $value);
    }

    public static function has(string $key): bool
    {
        return getenv($key) !== false || array_key_exists($key, self::$values);
    }

    /**
     * Interpret a string as a boolean the way `.env` files conventionally do.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function parseValue(string $value): string
    {
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $inner = substr($value, 1, -1);

                return $first === '"'
                    ? str_replace(['\\n', '\\r', '\\t', '\\"'], ["\n", "\r", "\t", '"'], $inner)
                    : $inner;
            }
        }

        // Strip an unquoted trailing comment: `KEY=value # comment`.
        $comment = strpos($value, ' #');

        if ($comment !== false) {
            $value = substr($value, 0, $comment);
        }

        return trim($value);
    }
}
