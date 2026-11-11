<?php

declare(strict_types=1);

/**
 * Idempotent seed runner (CLI).
 *
 * Usage:
 *   php scripts/seed.php
 *
 * Seeds the permission catalogue, the role/permission mapping and the demo
 * Greenview Holdings environment. Safe to run repeatedly: rows are matched by
 * natural keys and no duplicates are created.
 */

use App\Core\Database;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require BASE_PATH . '/app/bootstrap.php';

/**
 * @param array<string, array<string, int>> $result
 */
function printSeedResult(array $result): void
{
    $totals = [];
    $changes = [];

    foreach ($result as $section => $counters) {
        foreach ($counters as $key => $value) {
            if (in_array($key, ['total', 'catalogue', 'roles_total', 'role_permissions_total'], true)) {
                $totals[$section][$key] = $value;

                continue;
            }

            if ($value > 0) {
                $changes[] = '  ' . str_pad($section, 12) . str_replace('_', ' ', $key) . ': ' . $value;
            }
        }
    }

    $catalogue = $result['permissions']['catalogue'] ?? 0;
    $permissionsTotal = $result['permissions']['total'] ?? 0;
    $rolesTotal = $result['roles']['roles_total'] ?? 0;
    $linksTotal = $result['roles']['role_permissions_total'] ?? 0;

    fwrite(STDOUT, 'Catalogue: ' . $catalogue . ' permissions, ' . $rolesTotal . ' roles, ' . $linksTotal . ' role-permission links' . PHP_EOL);

    if ($catalogue !== $permissionsTotal) {
        fwrite(STDOUT, 'Warning: database has ' . $permissionsTotal . ' permission rows for a catalogue of ' . $catalogue . '.' . PHP_EOL);
    }

    if ($changes === []) {
        fwrite(STDOUT, 'Seed: no changes (all seeded rows already present).' . PHP_EOL);

        return;
    }

    fwrite(STDOUT, 'Seed: applied changes' . PHP_EOL . implode(PHP_EOL, $changes) . PHP_EOL);
}

try {
    $db = Database::instance();

    fwrite(STDOUT, 'Seeding database "' . $db->databaseName() . '"...' . PHP_EOL);

    $seed = require BASE_PATH . '/database/seeds/run.php';
    $result = $seed();

    fwrite(STDOUT, PHP_EOL);
    printSeedResult($result);
    fwrite(STDOUT, PHP_EOL . 'Seed complete. Re-running this command is safe.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Seeding failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
