<?php

declare(strict_types=1);

/**
 * Developer-only database reset (CLI).
 *
 * Rebuilds a disposable DEVELOPMENT database from scratch: ensures the database
 * charset, drops every table, re-runs the real migrations and the real seeders.
 * Intended for acceptance/live-verification gates on local/dev databases only —
 * it is never safe against staging or production data.
 *
 * Usage:
 *   php scripts/dev-reset.php --dry-run     # show current state + reset plan (no changes)
 *   php scripts/dev-reset.php --yes         # perform the reset
 *   php scripts/dev-reset.php --yes --force # override the name guard (also needs ALLOW_DEV_RESET=1)
 *
 * Refusals (exit code 1):
 *   - APP_ENV=production|prod|live (always refused, even with --force)
 *   - APP_ENV=staging unless --force and ALLOW_DEV_RESET=1
 *   - database name outside mtaapass / mtaapass_<tag> unless --force and ALLOW_DEV_RESET=1
 *   - database name containing prod/production/live/dashpass unless --force and ALLOW_DEV_RESET=1
 *   - DB name ending _test unless --force and ALLOW_DEV_RESET=1 (the tests suite owns it)
 *   - the tests harness lock (storage/temporary/mtaapass-test.lock) is currently held
 *
 * Exit codes: 0 ok (dry-run included), 1 refusal/failure, 2 usage.
 */

use App\Core\Database;
use App\Core\Migrator;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'dev-reset.php must be run from the command line.' . PHP_EOL);
    exit(2);
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require BASE_PATH . '/app/bootstrap.php';

$options = [
    'dry-run' => in_array('--dry-run', $argv, true),
    'yes' => in_array('--yes', $argv, true),
    'force' => in_array('--force', $argv, true),
    'keep-database' => in_array('--keep-database', $argv, true),
    'help' => in_array('--help', $argv, true) || in_array('-h', $argv, true),
];

$write = static function (string $line = ''): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

$fail = static function (string $message): never {
    fwrite(STDERR, 'dev-reset: ' . $message . PHP_EOL);
    exit(1);
};

if ($options['help']) {
    $write('Usage: php scripts/dev-reset.php --dry-run | --yes [--force] [--keep-database]');
    $write('');
    $write('  --dry-run        Print current state and the reset plan; changes nothing.');
    $write('  --yes            Actually rebuild the configured dev database (drop + recreate),');
    $write('                   then migrate and seed it.');
    $write('  --keep-database  Legacy path: drop every table instead of dropping/recreating the');
    $write('                   database. Can hit stale InnoDB FK dictionary errors after bulk');
    $write('                   drops (errno 121); prefer the default recreate path.');
    $write('  --force          Override safety guards (requires ALLOW_DEV_RESET=1): allows a');
    $write('                   non-standard dev DB name, a *_test target, APP_ENV=staging, and');
    $write('                   bypasses the tests-harness lock refusal. Production/live and');
    $write('                   production-like database names are ALWAYS refused. The dev DB');
    $write('                   is separate from mtaapass_test, so resetting it while a suite');
    $write('                   runs does not touch the suite database.');
    $write('');
    $write('Safety: only mtaapass / mtaapass_<tag> databases are accepted by default;');
    $write('production, prod-like names and APP_ENV=production are always refused.');
    exit(0);
}

/* ------------------------------------------------------------------------- */
/* Target + environment guards                                               */
/* ------------------------------------------------------------------------- */
$appEnv = strtolower((string) config('app.env', 'local'));
$db = Database::instance();
$databaseConfig = (array) $db->config();
$host = (string) ($databaseConfig['host'] ?? '127.0.0.1');
$port = (string) ($databaseConfig['port'] ?? '3306');
$dbName = $db->databaseName();

$forceAllowed = $options['force'] && getenv('ALLOW_DEV_RESET') === '1';

$isProductionEnv = in_array($appEnv, ['production', 'prod', 'live'], true);
$isStagingEnv = $appEnv === 'staging';
$looksProductionName = preg_match('/(prod|production|live|dashpass)/i', $dbName) === 1;
$nameAllowed = preg_match('/^mtaapass(:?_[a-z0-9]+)?$/i', $dbName) === 1;
$isTestDatabase = str_ends_with(strtolower($dbName), '_test');

$write('MtaaPass dev-reset — ' . ($options['dry-run'] ? 'DRY RUN (no changes)' : 'REAL RESET'));
$write('  app env : ' . $appEnv);
$write('  database: ' . $dbName . ' @ ' . $host . ':' . $port);
$write('');

if ($isProductionEnv) {
    $fail('refusing to run: APP_ENV=' . $appEnv . '. This tool only resets disposable development databases.');
}

if ($isStagingEnv && !$forceAllowed) {
    $fail('refusing to run against APP_ENV=staging (use --force with ALLOW_DEV_RESET=1 only on a disposable staging copy).');
}

if ($looksProductionName && !$forceAllowed) {
    $fail('refusing to run: database name "' . $dbName . '" looks production-like (contains prod/production/live/dashpass).');
}

if (!$nameAllowed && !$forceAllowed) {
    $fail('refusing to run: database "' . $dbName . '" is not an allowed dev database (expected mtaapass or mtaapass_<tag>).');
}

if ($isTestDatabase && !$forceAllowed) {
    $fail('refusing to run: "' . $dbName . '" ends with _test and is owned by the tests harness (use --force + ALLOW_DEV_RESET=1 only when no suite is running).');
}

if ($options['force']) {
    $write('  --force  : enabled (ALLOW_DEV_RESET=' . (getenv('ALLOW_DEV_RESET') === '1' ? '1' : '0') . ')');
    $write('');
}

/* ------------------------------------------------------------------------- */
/* Tests-harness lock guard (authoritative flock, not file presence)         */
/* ------------------------------------------------------------------------- */
$lockHeld = static function (): bool {
    $lockPath = BASE_PATH . '/storage/temporary/mtaapass-test.lock';

    if (!is_file($lockPath)) {
        return false;
    }

    $handle = fopen($lockPath, 'c');

    if ($handle === false) {
        return true; // cannot prove it is free -> treat as held
    }

    $free = flock($handle, LOCK_EX | LOCK_NB);

    if ($free) {
        flock($handle, LOCK_UN);
    }

    fclose($handle);

    return !$free;
};

$suiteRunning = $lockHeld();
$write('  test suite lock: ' . ($suiteRunning ? 'HELD (a tests/run.php process is running)' : 'free'));

if ($suiteRunning && !$options['dry-run'] && !$forceAllowed) {
    $fail('refusing to reset while the tests harness lock is held. Wait for the full suite to finish, then re-run (or use --force with ALLOW_DEV_RESET=1 to reset only the configured dev DB while tests use the separate *_test DB).');
}

if ($suiteRunning && !$options['dry-run'] && $forceAllowed) {
    $write('  WARNING: resetting the dev DB while the tests harness lock is held (--force + ALLOW_DEV_RESET=1).');
    $write('           Tests use the separate test database; this affects only the configured dev database.');
}

$write('');

/* ------------------------------------------------------------------------- */
/* State inspection helpers                                                  */
/* ------------------------------------------------------------------------- */
$tableExists = static function (string $table) use ($db): bool {
    return (int) $db->scalar(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table AND table_type = :type',
        ['schema' => $db->databaseName(), 'table' => $table, 'type' => 'BASE TABLE']
    ) > 0;
};

$tableCount = static function () use ($db): int {
    return (int) $db->scalar(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema AND table_type = :type',
        ['schema' => $db->databaseName(), 'type' => 'BASE TABLE']
    );
};

$rowCount = static function (string $table) use ($db, $tableExists): ?int {
    if (!$tableExists($table)) {
        return null;
    }

    try {
        return (int) $db->scalar('SELECT COUNT(*) FROM `' . $table . '`');
    } catch (Throwable) {
        return null;
    }
};

$charset = static function () use ($db): array {
    $row = $db->selectOne(
        'SELECT default_character_set_name AS charset, default_collation_name AS collation FROM information_schema.schemata WHERE schema_name = :schema',
        ['schema' => $db->databaseName()]
    );

    return [
        'charset' => (string) ($row['charset'] ?? ''),
        'collation' => (string) ($row['collation'] ?? ''),
    ];
};

$seedSections = static function (): array {
    $source = (string) file_get_contents(BASE_PATH . '/database/seeds/run.php');
    preg_match_all("/'([a-z0-9_]+)' => \\(new ([A-Za-z0-9_]+)\\(\\)\\)->run\\(\\)/", $source, $matches, PREG_SET_ORDER);
    $sections = [];

    foreach ($matches as $match) {
        $sections[] = $match[1];
    }

    return $sections;
};

$countedTables = ['users', 'households', 'buildings', 'gates', 'guard_gate_assignments', 'vehicles', 'estate_memberships', 'roles', 'permissions', 'role_permissions', 'estate_settings', 'household_staff', 'access_incidents', 'estate_notices', 'parking_zones', 'parking_bays', 'audit_logs'];

$printCounts = static function (string $heading) use ($write, $rowCount, $countedTables): void {
    $write($heading);

    foreach ($countedTables as $table) {
        $count = $rowCount($table);

        if ($count === null) {
            continue;
        }

        $write('    ' . str_pad($table . ':', 22) . $count);
    }
};

/* ------------------------------------------------------------------------- */
/* Current state + plan                                                      */
/* ------------------------------------------------------------------------- */
$migrator = new Migrator($db, BASE_PATH . '/database/migrations');
$migrationFiles = $migrator->files();
$appliedMigrations = static function () use ($db, $tableExists): int {
    return $tableExists('schema_migrations') ? (int) $db->scalar('SELECT COUNT(*) FROM schema_migrations') : 0;
};
$applied = $appliedMigrations();
$charsetState = $charset();
$currentTables = $tableCount();

$write('Current state');
$write('    migrations applied: ' . $applied . '/' . count($migrationFiles));
$write('    tables:             ' . $currentTables);
$write('    database charset:   ' . $charsetState['charset'] . ' / ' . $charsetState['collation']);
$printCounts('    rows:');

$write('');
$write('Reset plan (would)');
$write('    1. ensure database charset utf8mb4 / utf8mb4_unicode_ci'
    . (($charsetState['charset'] === 'utf8mb4' && $charsetState['collation'] === 'utf8mb4_unicode_ci')
        ? ' (already correct, no ALTER)'
        : ' (ALTER DATABASE required)'));
$write('    2. ' . ($options['keep-database']
    ? 'drop ' . $currentTables . ' table(s) (legacy --keep-database path; FOREIGN_KEY_CHECKS disabled and restored)'
    : 'drop and recreate database `' . $dbName . '` (charset utf8mb4 / utf8mb4_unicode_ci; clears stale InnoDB FK dictionary entries)'));
$write('    3. apply all migrations: ' . count($migrationFiles) . ' file(s)');
$write('    4. run seeders: ' . implode(', ', $seedSections()));
$write('    5. print final counts (no app/route/test changes)');

if ($options['dry-run']) {
    $write('');
    $write('Dry run complete — nothing was changed. Re-run with --yes to perform the reset.');

    if ($suiteRunning) {
        $write('NOTE: the tests suite lock is currently held; --yes would be refused until it finishes.');
    }

    exit(0);
}

if (!$options['yes']) {
    fwrite(STDERR, 'dev-reset: refusing to modify the database without --yes (use --dry-run to preview).' . PHP_EOL);
    exit(2);
}

/* ------------------------------------------------------------------------- */
/* Perform the reset                                                         */
/* ------------------------------------------------------------------------- */
try {
    $write('');
    $write('Resetting...');

    if ($options['keep-database']) {
        // Legacy path: drop every table. Can leave stale InnoDB FK dictionary
        // entries after bulk drops (errno 121 on the next CREATE TABLE ... FK).
        if ($charsetState['charset'] !== 'utf8mb4' || $charsetState['collation'] !== 'utf8mb4_unicode_ci') {
            $db->execute('ALTER DATABASE `' . str_replace('`', '', $dbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $write('  + database charset set to utf8mb4 / utf8mb4_unicode_ci');
        } else {
            $write('  = database charset already utf8mb4 / utf8mb4_unicode_ci');
        }

        $tablesBefore = $tableCount();
        $migrator->dropAllTables();
        $write('  + dropped ' . $tablesBefore . ' table(s) (legacy path)');
    } else {
        // Default robust path: DROP DATABASE + CREATE DATABASE. This clears all
        // tables, constraints and stale InnoDB FK dictionary entries in one go.
        $safeName = str_replace('`', '', $dbName);
        $write('  + dropping and recreating database `' . $safeName . '`');

        try {
            $db->execute('DROP DATABASE IF EXISTS `' . $safeName . '`');
            $db->execute('CREATE DATABASE `' . $safeName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $db->execute('USE `' . $safeName . '`');
        } catch (Throwable $recreateError) {
            $fail('could not drop/recreate database `' . $safeName . '` (' . $recreateError->getMessage() . '). Retry with --keep-database only if DROP/CREATE is not permitted.');
        }

        $write('  + database recreated (utf8mb4 / utf8mb4_unicode_ci)');
    }

    $appliedList = $migrator->migrate();
    $write('  + applied ' . count($appliedList) . ' migration(s)');

    $seed = require BASE_PATH . '/database/seeds/run.php';
    $result = $seed();
    $write('  + seeded: ' . implode(', ', $seedSections()));

    foreach ($result as $section => $counters) {
        $changes = [];

        foreach ($counters as $key => $value) {
            if ($value > 0 && !in_array($key, ['total', 'catalogue', 'roles_total', 'role_permissions_total'], true)) {
                $changes[] = $key . '=' . $value;
            }
        }

        $write('      ' . str_pad($section . ':', 18) . implode(' ', $changes));
    }
} catch (Throwable $exception) {
    $fail('reset failed: ' . $exception->getMessage());
}

/* ------------------------------------------------------------------------- */
/* Final state                                                               */
/* ------------------------------------------------------------------------- */
$write('');
$write('Final state');
$write('    migrations applied: ' . $appliedMigrations() . '/' . count($migrationFiles));
$write('    tables:             ' . $tableCount());
$write('    database charset:   ' . $charset()['charset'] . ' / ' . $charset()['collation']);
$printCounts('    rows:');

$write('');
$write('Dev reset complete. Start the app with:');
$write('  php -S 127.0.0.1:8000 -t public public/index.php');
exit(0);
