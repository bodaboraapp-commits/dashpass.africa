<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Secure PHP session wrapper.
 *
 * Responsibilities:
 *  - harden cookie/session ini settings before starting the session;
 *  - enforce idle and absolute lifetimes;
 *  - regenerate the session identifier on privilege changes;
 *  - provide a test-mode array store so the HTTP pipeline can run in-process.
 */
final class Session
{
    private static bool $started = false;

    private static bool $testMode = false;

    public static function start(?Request $request = null): void
    {
        self::$testMode = defined('MTAAPASS_TEST_SESSION');

        if (self::$testMode) {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }

            self::$started = true;
            self::enforceLifetime();

            return;
        }

        if (self::$started) {
            self::enforceLifetime();

            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            self::enforceLifetime();

            return;
        }

        $config = (array) Config::get('security.session', []);

        $secure = self::resolveSecureCookie($config, $request);

        // Keep session files inside the project storage directory, outside the
        // public web root. This works on shared hosting where the system temp
        // directory is not writable and keeps session data off world-readable
        // paths.
        $savePath = self::ensureSavePath();
        ini_set('session.save_path', $savePath);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string) max(3600, (int) ($config['absolute_lifetime_minutes'] ?? 720) * 60));

        session_name((string) ($config['name'] ?? 'mtaapass_session'));

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => (string) ($config['same_site'] ?? 'Lax'),
        ]);

        session_start();
        self::$started = true;
        self::enforceLifetime();
    }

    public static function isStarted(): bool
    {
        return self::$started;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Retrieve a value once and remove it.
     */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);

        return $value;
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function pullFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);

        return $value;
    }

    /**
     * Regenerate the session identifier while preserving session data. Called
     * after login and privilege changes to prevent session fixation.
     */
    public static function regenerate(): void
    {
        if (self::$testMode) {
            self::put('_session_regenerated_at', time());

            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function id(): string
    {
        return self::$testMode ? 'test-session' : (string) session_id();
    }

    /**
     * Destroy session data and invalidate the session cookie.
     */
    public static function destroy(): void
    {
        $_SESSION = [];

        if (self::$testMode) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();

            if (!headers_sent()) {
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]);
            }

            session_destroy();
        }

        self::$started = false;
    }

    /**
     * Enforce idle and absolute session lifetimes.
     */
    private static function enforceLifetime(): void
    {
        $config = (array) Config::get('security.session', []);
        $idleSeconds = max(60, (int) ($config['idle_lifetime_minutes'] ?? 120) * 60);
        $absoluteSeconds = max($idleSeconds, (int) ($config['absolute_lifetime_minutes'] ?? 720) * 60);
        $now = time();

        if (!isset($_SESSION['_created_at'])) {
            $_SESSION['_created_at'] = $now;
            $_SESSION['_last_activity'] = $now;

            return;
        }

        $idleExpired = ($now - (int) $_SESSION['_last_activity']) > $idleSeconds;
        $absoluteExpired = ($now - (int) $_SESSION['_created_at']) > $absoluteSeconds;

        if ($idleExpired || $absoluteExpired) {
            self::destroy();
            $_SESSION = [
                '_created_at' => $now,
                '_last_activity' => $now,
            ];

            return;
        }

        $_SESSION['_last_activity'] = $now;
    }

    /**
     * Project-owned session directory (created on first use). Session files must
     * live outside the public web root and outside world-readable temp paths.
     */
    private static function ensureSavePath(): string
    {
        $path = BASE_PATH . '/storage/temporary/sessions';

        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            // Fall back to the configured path if the project directory is
            // read-only; session_start() will surface a clear error.
            return (string) ini_get('session.save_path');
        }

        // Restrict permissions on POSIX hosts; ignored on Windows.
        @chmod($path, 0775);

        return $path;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveSecureCookie(array $config, ?Request $request = null): bool
    {
        $setting = $config['secure_cookie'] ?? 'auto';

        if (is_bool($setting)) {
            return $setting;
        }

        if ((string) $setting !== 'auto') {
            return filter_var($setting, FILTER_VALIDATE_BOOL);
        }

        if ((string) Config::get('app.env') === 'production') {
            return true;
        }

        // TLS-terminating reverse proxies (nginx, Cloudflare, ngrok tunnels)
        // forward the original scheme. Marking the cookie Secure when the
        // browser's connection is HTTPS is correct; local HTTP development
        // without the header keeps working.
        if ($request !== null && self::isForwardedHttps($request)) {
            return true;
        }

        return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    }

    private static function isForwardedHttps(Request $request): bool
    {
        $proto = strtolower(trim((string) $request->header('X-Forwarded-Proto', '')));

        if ($proto !== '') {
            return str_starts_with($proto, 'https');
        }

        $forwardedSsl = strtolower(trim((string) $request->header('X-Forwarded-Ssl', '')));

        return $forwardedSsl === 'on';
    }
}
