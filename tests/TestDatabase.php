<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Core\Migrator;
use Closure;
use RuntimeException;

/**
 * Shared database preparation for the test suite.
 *
 * `php tests/run.php` calls freshAndSeed() once at startup. Individual
 * integration/security tests call $this->freshDatabase() (which delegates here)
 * when they need a pristine seeded database.
 *
 * Migrator::fresh() itself refuses to run outside a local/testing environment
 * or against a database whose name does not end with `_test`, so even a direct
 * call cannot truncate the development database.
 */
final class TestDatabase
{
    private static ?Closure $seeder = null;

    public static function db(): Database
    {
        return Database::instance();
    }

    public static function migrationsPath(): string
    {
        return BASE_PATH . '/database/migrations';
    }

    public static function seedsPath(): string
    {
        return BASE_PATH . '/database/seeds/run.php';
    }

    /**
     * Drop every table in the test database and re-apply all migrations.
     */
    public static function fresh(): void
    {
        if (!is_dir(self::migrationsPath())) {
            throw new RuntimeException(
                'Missing database/migrations directory — the schema-seed deliverable is not installed yet.'
            );
        }

        (new Migrator(self::db(), self::migrationsPath()))->fresh();
    }

    /**
     * Run the idempotent demo seeder. The `run.php` file is required exactly
     * once and the returned callable is cached, so seeds can be re-run safely
     * for idempotency tests without redeclaring the seeder classes.
     */
    public static function seed(): void
    {
        if (self::$seeder === null) {
            if (!is_file(self::seedsPath())) {
                throw new RuntimeException(
                    'Missing database/seeds/run.php — the schema-seed deliverable is not installed yet.'
                );
            }

            $callable = require self::seedsPath();

            if (!is_callable($callable)) {
                throw new RuntimeException('database/seeds/run.php must return a callable.');
            }

            self::$seeder = $callable instanceof Closure ? $callable : Closure::fromCallable($callable);
        }

        (self::$seeder)();
    }

    public static function freshAndSeed(): void
    {
        self::fresh();
        self::seed();
    }
}
