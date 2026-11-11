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
     * Build a versioned, root-relative asset URL so caches are busted when an
     * asset changes. The version combines app.version with the file mtime/size;
     * the value is stable while the file is unchanged and changes automatically
     * on deploy (no manual cache-bust strings).
     */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $version = (string) config('app.version', '0');
        $file = dirname(__DIR__) . '/public/assets/' . $path;

        if (is_file($file)) {
            $mtime = @filemtime($file);
            $size = @filesize($file);
            if ($mtime !== false && $size !== false) {
                $version .= '-' . dechex((int) $mtime) . '-' . dechex((int) $size);
            }
        }

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

if (!function_exists('notification_shell')) {
    /**
     * Bell/inbox shell data for the current request (Inc-13 P2a).
     *
     * Returns the frozen keys and never throws: absent migration columns, a
     * disabled feature flag or any query failure degrade to an empty bell.
     *
     * @return array{notifications_enabled: bool, notifications_unread_count: int, notifications_latest: array<int, array<string, mixed>>}
     */
    function notification_shell(): array
    {
        $context = App\Core\Context::current();

        if (!$context instanceof App\Core\Context) {
            return [
                'notifications_enabled' => false,
                'notifications_unread_count' => 0,
                'notifications_latest' => [],
            ];
        }

        try {
            $service = App\Modules\Notifications\NotificationService::make($context);

            return $service->shellData() + $service->incidentSirenData();
        } catch (Throwable) {
            return [
                'notifications_enabled' => false,
                'notifications_unread_count' => 0,
                'notifications_latest' => [],
                'incidents_unread_count' => 0,
                'incidents_latest_link' => null,
            ];
        }
    }
}
