<?php

declare(strict_types=1);

/**
 * One-command development install (CLI): migrate + seed.
 *
 * Usage:
 *   php scripts/install.php
 *
 * Repeatable: running it again applies no migrations and creates no duplicate
 * seed rows. It never drops or truncates an existing database. Use
 * `php scripts/migrate.php --fresh` only against a disposable *_test database.
 */

use App\Core\Database;
use App\Core\Migrator;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require BASE_PATH . '/app/bootstrap.php';

/**
 * @param array<string, array<string, int>> $result
 */
function printInstallSeedResult(array $result): void
{
    $changes = [];

    foreach ($result as $section => $counters) {
        foreach ($counters as $key => $value) {
            if ($value > 0 && !in_array($key, ['total', 'catalogue', 'roles_total', 'role_permissions_total'], true)) {
                $changes[] = '  ' . str_pad($section, 12) . str_replace('_', ' ', $key) . ': ' . $value;
            }
        }
    }

    $catalogue = $result['permissions']['catalogue'] ?? 0;
    $rolesTotal = $result['roles']['roles_total'] ?? 0;
    $linksTotal = $result['roles']['role_permissions_total'] ?? 0;

    fwrite(STDOUT, 'Seed data:  ' . $catalogue . ' permissions, ' . $rolesTotal . ' roles, ' . $linksTotal . ' role-permission links' . PHP_EOL);

    if ($changes === []) {
        fwrite(STDOUT, 'Seed data:  no changes (already installed).' . PHP_EOL);

        return;
    }

    fwrite(STDOUT, implode(PHP_EOL, $changes) . PHP_EOL);
}

try {
    $db = Database::instance();
    $database = $db->databaseName();
    $migrator = new Migrator($db, BASE_PATH . '/database/migrations');

    fwrite(STDOUT, 'MtaaPass install - database "' . $database . '"' . PHP_EOL . PHP_EOL);

    $appliedList = $migrator->migrate();
    $total = count($migrator->files());

    if ($appliedList === []) {
        fwrite(STDOUT, 'Migrations: nothing to apply (' . $total . ' already applied).' . PHP_EOL);
    } else {
        fwrite(STDOUT, 'Migrations: applied ' . count($appliedList) . ' of ' . $total . PHP_EOL);

        foreach ($appliedList as $migration) {
            fwrite(STDOUT, '  + ' . $migration . PHP_EOL);
        }
    }

    $seed = require BASE_PATH . '/database/seeds/run.php';
    $result = $seed();

    printInstallSeedResult($result);

    fwrite(STDOUT, PHP_EOL . 'Install complete. Start the app with:' . PHP_EOL);
    fwrite(STDOUT, '  php -S 127.0.0.1:8000 -t public public/index.php' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Install failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
