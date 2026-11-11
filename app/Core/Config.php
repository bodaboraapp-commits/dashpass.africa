<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dot-notation access to the configuration arrays in `config/`.
 */
final class Config
{
    /** @var array<string, array<string, mixed>> */
    private static array $items = [];

    private static bool $loaded = false;

    public static function load(string $directory): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        $files = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php');

        if ($files === false) {
            return;
        }

        sort($files);

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $values = require $file;

            if (is_array($values)) {
                self::$items[$name] = $values;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Runtime override, used by the test bootstrap only.
     */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor = &self::$items;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;

                return;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::$items;
    }

    public static function reset(): void
    {
        self::$items = [];
        self::$loaded = false;
    }
}
