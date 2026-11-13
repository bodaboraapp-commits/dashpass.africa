<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Automatic database migrations for web deployments (owner request 2026-11-13).
 *
 * Owner feedback: a cPanel Git deploy used to require a manual
 * `php scripts/migrate.php` step, and forgetting it produced 500s on new
 * screens. The front controller now applies pending migrations automatically on
 * the first web request after a deploy:
 *
 *  - only web SAPIs run it (CLI keeps explicit control: tests, cron scripts and
 *    `php scripts/migrate.php` are untouched);
 *  - `AUTO_MIGRATE=false` (app.auto_migrate) disables it for hosts that prefer
 *    the explicit command;
 *  - a MySQL advisory lock (GET_LOCK) serialises concurrent requests, so a burst
 *    of traffic cannot run the same migration twice;
 *  - a 60 second stamp file in storage/temporary keeps the per-request cost to
 *    one cheap check, instead of a schema query on every request;
 *  - any failure is logged as a warning and the request continues: the app keeps
 *    serving, and screens that need a missing table show their graceful
 *    "database update required" states instead of a 500.
 */
final class AutoMigrator
{
    private const LOCK_NAME = 'mtaapass_auto_migrate';

    private const CHECK_INTERVAL_SECONDS = 60;

    /** Run the automatic check for web requests (CLI manages migrations itself). */
    public static function runIfNeeded(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (!(bool) Config::get('app.auto_migrate', true)) {
            return;
        }

        $marker = self::markerPath();

        if ($marker !== null && is_file($marker) && (time() - (int) filemtime($marker)) < self::CHECK_INTERVAL_SECONDS) {
            return;
        }

        self::run();
    }

    /**
     * Apply every pending migration under an advisory lock.
     *
     * Public so the test suite can exercise it from the CLI test runner.
     * Never throws: failures are logged and the app keeps serving.
     */
    public static function run(): void
    {
        try {
            $db = Database::instance();
            $acquired = (int) ($db->scalar(
                "SELECT GET_LOCK('" . self::LOCK_NAME . "', 15)"
            ) ?? 0);

            if ($acquired !== 1) {
                // Another request is already applying migrations.
                return;
            }

            try {
                $migrator = new Migrator($db, BASE_PATH . '/database/migrations');

                if ($migrator->pending() !== []) {
                    $applied = $migrator->migrate();

                    if ($applied !== []) {
                        Logger::info('auto-migrate applied pending migrations', [
                            'migrations' => $applied,
                        ]);
                    }
                }

                self::touchMarker();
            } finally {
                try {
                    $db->scalar("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
                } catch (\Throwable) {
                    // Lock is released with the connection anyway.
                }
            }
        } catch (\Throwable $exception) {
            Logger::warning('auto-migrate could not run', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private static function markerPath(): ?string
    {
        try {
            $directory = BASE_PATH . '/storage/temporary';

            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                return null;
            }

            return $directory . '/auto-migrate.stamp';
        } catch (\Throwable) {
            return null;
        }
    }

    private static function touchMarker(): void
    {
        $path = self::markerPath();

        if ($path === null) {
            return;
        }

        try {
            @file_put_contents($path, (string) time());
        } catch (\Throwable) {
            // A read-only temporary directory only costs an extra pending check.
        }
    }
}
