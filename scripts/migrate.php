<?php

declare(strict_types=1);

/**
 * Migration runner (CLI).
 *
 * Usage:
 *   php scripts/migrate.php              Apply all pending migrations.
 *   php scripts/migrate.php --status     Show applied/pending migration files.
 *   php scripts/migrate.php --fresh      Drop every table in a *_test database and re-migrate.
 *
 * `--fresh` is intentionally refused for the development database by the
 * Migrator guard (only databases whose name ends with `_test` may be dropped).
 * Use `php scripts/install.php` for the development database instead.
 */

use App\Core\Database;
use App\Core\Migrator;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require BASE_PATH . '/app/bootstrap.php';

$arguments = $_SERVER['argv'] ?? [];
$options = array_slice(is_array($arguments) ? $arguments : [], 1);
$mode = 'migrate';

foreach ($options as $option) {
    switch ($option) {
        case '--status':
            $mode = 'status';
            break;
        case '--fresh':
            $mode = 'fresh';
            break;
        case '--help':
        case '-h':
            $mode = 'help';
            break;
        default:
            fwrite(STDERR, 'Unknown option: ' . $option . PHP_EOL);
            fwrite(STDERR, 'Run "php scripts/migrate.php --help" for usage.' . PHP_EOL);
            exit(1);
    }
}

try {
    $db = Database::instance();
    $database = $db->databaseName();
    $migrator = new Migrator($db, BASE_PATH . '/database/migrations');

    if ($mode === 'help') {
        fwrite(STDOUT, 'MtaaPass migration runner' . PHP_EOL . PHP_EOL);
        fwrite(STDOUT, '  php scripts/migrate.php            Apply pending migrations' . PHP_EOL);
        fwrite(STDOUT, '  php scripts/migrate.php --status   Show migration status' . PHP_EOL);
        fwrite(STDOUT, '  php scripts/migrate.php --fresh    Rebuild a disposable *_test database' . PHP_EOL);
        exit(0);
    }

    if ($mode === 'status') {
        $rows = $migrator->status();
        $applied = 0;
        $pending = 0;

        fwrite(STDOUT, 'Migration status for database "' . $database . '":' . PHP_EOL . PHP_EOL);

        if ($rows === []) {
            fwrite(STDOUT, '  No migration files found in database/migrations.' . PHP_EOL);
        }

        foreach ($rows as $row) {
            if ((bool) $row['applied']) {
                $applied++;
                $state = 'applied';
                $detail = ' (batch ' . (int) $row['batch'] . ', ' . (string) $row['applied_at'] . ')';
            } else {
                $pending++;
                $state = 'pending';
                $detail = '';
            }

            fwrite(STDOUT, sprintf('  [%-7s] %s%s', $state, (string) $row['migration'], $detail) . PHP_EOL);
        }

        fwrite(STDOUT, PHP_EOL . $applied . ' of ' . count($rows) . ' migration(s) applied');
        fwrite(STDOUT, $pending > 0 ? ', ' . $pending . ' pending.' . PHP_EOL : '.' . PHP_EOL);
        exit(0);
    }

    if ($mode === 'fresh') {
        $appliedList = $migrator->fresh();

        fwrite(STDOUT, 'Rebuilt database "' . $database . '" with ' . count($appliedList) . ' migration(s):' . PHP_EOL);

        foreach ($appliedList as $migration) {
            fwrite(STDOUT, '  + ' . $migration . PHP_EOL);
        }

        exit(0);
    }

    $appliedList = $migrator->migrate();
    $total = count($migrator->files());

    if ($appliedList === []) {
        fwrite(STDOUT, 'Nothing to migrate: ' . $total . ' migration(s) already applied to "' . $database . '".' . PHP_EOL);
        exit(0);
    }

    fwrite(STDOUT, 'Applied ' . count($appliedList) . ' migration(s) to "' . $database . '":' . PHP_EOL);

    foreach ($appliedList as $migration) {
        fwrite(STDOUT, '  + ' . $migration . PHP_EOL);
    }

    fwrite(STDOUT, $total . ' migration(s) now applied.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);

    if ($mode === 'fresh') {
        fwrite(STDERR, 'The --fresh option only drops databases whose name ends with "_test".' . PHP_EOL);
        fwrite(STDERR, 'Use "php scripts/install.php" to install into the development database.' . PHP_EOL);
    }

    exit(1);
}
