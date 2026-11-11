<?php

declare(strict_types=1);

/**
 * Small global helper functions used by configuration files, views and
 * controllers. Keep this file dependency-free: it is loaded before the
 * autoloader so configuration files can call env().
 */

if (!function_exists('env')) {
    /**
     * Read an environment value. Real process environment wins over `.env`,
     * which lets test runners and CI override local configuration.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return App\Core\Env::get($key, $default);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return App\Core\Config::get($key, $default);
    }
}

if (!function_exists('e')) {
    /**
     * HTML-escape a value for output. `ENT_QUOTES | ENT_SUBSTITUTE` plus UTF-8
     * keeps both attribute and text contexts safe.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /**
     * Build a root-relative URL for a path inside the application.
     *
     * Root-relative URLs keep the app correct behind a reverse proxy, on any
     * local port and across HTTP/HTTPS without editing configuration. Use
     * absolute_url() when a fully qualified URL is required (SMS links, emails).
     */
    function url(string $path = ''): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}

if (!function_exists('absolute_url')) {
    /**
     * Build a fully qualified URL from the configured application origin.
     */
    function absolute_url(string $path = ''): string
    {
        return rtrim((string) config('app.url', ''), '/') . url($path);
    }
}

if (!function_exists('asset')) {
    /**
     * Build a versioned, root-relative asset URL so caches are busted when
     * assets change.
     */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $version = (string) config('app.version', '0');

        return url('/assets/' . $path) . '?v=' . rawurlencode($version);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Current session CSRF token (creates one on first use).
     */
    function csrf_token(): string
    {
        return App\Core\Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Hidden form field carrying the CSRF token.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('format_datetime')) {
    /**
     * Render a stored UTC timestamp in the given timezone.
     */
    function format_datetime(?string $utc, string $timezone = 'Africa/Nairobi', string $format = 'd M Y, H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }

        try {
            $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

            return $date->setTimezone(new DateTimeZone($timezone))->format($format);
        } catch (Throwable) {
            return '—';
        }
    }
}
