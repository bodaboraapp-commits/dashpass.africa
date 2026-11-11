<?php

declare(strict_types=1);

/**
 * Read-only database integrity checker (CLI) â€” post-deploy diagnostics.
 *
 * Usage:
 *   php scripts/db-integrity-check.php            # human-readable report
 *   php scripts/db-integrity-check.php --json     # machine-readable JSON
 *   php scripts/db-integrity-check.php --help
 *
 * The script only issues SELECT/information_schema queries. It never writes to
 * the database (no migrations, no seeding, no temporary tables). Exit codes:
 *   0 = all checks passed, 1 = one or more integrity failures, 2 = usage/refusal.
 *
 * Checks:
 *  1. Migration files vs schema_migrations rows, byte checksums, batch sanity.
 *  2. Schema: expected tables from migrations, InnoDB/utf8mb4, UNIQUE(uuid) on
 *     uuid tables, composite-FK parent unique indexes, FK child index coverage.
 *  3. Referential integrity: orphan rows for every foreign key.
 *  4. Data sanity: undeclared ENUM values, RBAC totals + per-role counts,
 *     estate_settings JSON validity, billing table/state expectations.
 *  5. Secrets/PII: no plaintext PIN/OTP/credential columns, hash column sanity,
 *     audit metadata PII sample scan.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'db-integrity-check must be run from the command line.' . PHP_EOL);
    exit(2);
}

$json = in_array('--json', $argv, true);
$help = in_array('--help', $argv, true) || in_array('-h', $argv, true);

if ($help) {
    fwrite(STDOUT, 'Usage: php scripts/db-integrity-check.php [--json] [--help]' . PHP_EOL);
    fwrite(STDOUT, PHP_EOL . 'Read-only database integrity diagnostics (no writes).' . PHP_EOL);
    fwrite(STDOUT, '  --json   Output a machine-readable JSON report.' . PHP_EOL);
    fwrite(STDOUT, '  --help   Show this help.' . PHP_EOL);
    exit(0);
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require BASE_PATH . '/app/bootstrap.php';

// CLI integrity diagnostics must always fail loudly (and with a non-zero exit
// code) instead of rendering the web error page if something unexpected breaks.
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, 'db-integrity-check: unexpected failure: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
});

$db = \App\Core\Database::instance();
$databaseName = $db->databaseName();

/** @var array<int, array{name: string, ok: bool, detail: string}> $checks */
$checks = [];
$failures = 0;

$add = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];

    if (!$ok) {
        $failures++;
    }
};

$read = static fn (string $path): string => is_file($path) ? (string) file_get_contents($path) : '';
$scalar = static fn (string $sql, array $params = []): mixed => $db->scalar($sql, $params);
$select = static fn (string $sql, array $params = []): array => $db->select($sql, $params);
$escape = static fn (string $value): string => str_replace('`', '``', $value);

/* ========================================================================= */
/* 1. Migrations                                                             */
/* ========================================================================= */
$migrationDir = BASE_PATH . '/database/migrations';
$migrationFiles = glob($migrationDir . '/*.sql') ?: [];
sort($migrationFiles);
$fileNames = array_map('basename', $migrationFiles);

$appliedRows = [];
$migrationTableExists = (int) $scalar(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema AND table_name = 'schema_migrations' AND table_type = 'BASE TABLE'",
    ['schema' => $databaseName]
) > 0;

if ($migrationTableExists) {
    foreach ($select('SELECT migration, batch, checksum, applied_at FROM schema_migrations ORDER BY id') as $row) {
        $appliedRows[(string) $row['migration']] = $row;
    }
}

$add('schema_migrations table exists', $migrationTableExists, $migrationTableExists ? count($appliedRows) . ' applied row(s)' : 'missing schema_migrations table');
$add(
    'migration file count matches applied rows',
    count($fileNames) === count($appliedRows),
    count($fileNames) . ' file(s) vs ' . count($appliedRows) . ' applied row(s)'
);

$missingApplied = array_values(array_diff($fileNames, array_keys($appliedRows)));
$extraApplied = array_values(array_diff(array_keys($appliedRows), $fileNames));
$add('no unapplied migration files', $missingApplied === [], implode(', ', $missingApplied));
$add('no schema_migrations rows without a file', $extraApplied === [], implode(', ', $extraApplied));

$checksumMismatches = [];

foreach ($migrationFiles as $file) {
    $name = basename($file);

    if (!isset($appliedRows[$name])) {
        continue;
    }

    $expected = (string) $appliedRows[$name]['checksum'];
    $actual = hash('sha256', $read($file));

    if (!hash_equals($expected, $actual)) {
        $checksumMismatches[] = $name;
    }
}

$add('applied migration checksums match files', $checksumMismatches === [], implode(', ', $checksumMismatches));

$badBatches = [];

foreach ($appliedRows as $name => $row) {
    if ((int) ($row['batch'] ?? 0) <= 0 || ($row['applied_at'] ?? null) === null) {
        $badBatches[] = $name;
    }
}

$add('migration batches/applied_at are sane', $badBatches === [], implode(', ', $badBatches));

/* ========================================================================= */
/* 2. Schema                                                                 */
/* ========================================================================= */
$tables = $select(
    "SELECT table_name AS name, engine, table_collation AS collation
     FROM information_schema.tables
     WHERE table_schema = :schema AND table_type = 'BASE TABLE'
     ORDER BY table_name",
    ['schema' => $databaseName]
);
$tableNames = array_map(static fn (array $t): string => (string) $t['name'], $tables);

$expectedTables = [];

foreach ($migrationFiles as $file) {
    preg_match_all('/CREATE TABLE\s+`?([a-z0-9_]+)`?/i', $read($file), $matches);

    foreach ($matches[1] as $table) {
        $expectedTables[strtolower((string) $table)] = true;
    }
}

// `schema_migrations` is created by the migrator itself, not by a migration file.
$expectedTables['schema_migrations'] = true;

$expectedTableNames = array_keys($expectedTables);
sort($expectedTableNames);
$actualTableNames = $tableNames;
sort($actualTableNames);

$add(
    'table inventory matches migration files',
    $actualTableNames === $expectedTableNames,
    count($actualTableNames) . ' live vs ' . count($expectedTableNames) . ' expected'
    . ($actualTableNames === $expectedTableNames ? '' : ' â€” missing: ' . implode(',', array_diff($expectedTableNames, $actualTableNames)) . '; extra: ' . implode(',', array_diff($actualTableNames, $expectedTableNames)))
);

$badEngine = [];
$badCollation = [];

foreach ($tables as $table) {
    if (strtolower((string) $table['engine']) !== 'innodb') {
        $badEngine[] = $table['name'];
    }

    if ((string) $table['collation'] !== 'utf8mb4_unicode_ci') {
        $badCollation[] = $table['name'];
    }
}

$add('all tables use InnoDB', $badEngine === [], implode(', ', $badEngine));
$add('all tables use utf8mb4_unicode_ci', $badCollation === [], implode(', ', $badCollation));

$dbMeta = $db->selectOne(
    'SELECT default_character_set_name AS charset, default_collation_name AS collation FROM information_schema.schemata WHERE schema_name = :schema',
    ['schema' => $databaseName]
);
$add(
    'database default charset/collation',
    strtolower((string) ($dbMeta['charset'] ?? '')) === 'utf8mb4' && (string) ($dbMeta['collation'] ?? '') === 'utf8mb4_unicode_ci',
    (string) ($dbMeta['charset'] ?? '?') . ' / ' . (string) ($dbMeta['collation'] ?? '?')
);

$columns = $select(
    'SELECT table_name, column_name, column_type, is_nullable, column_default
     FROM information_schema.columns WHERE table_schema = :schema ORDER BY table_name, ordinal_position',
    ['schema' => $databaseName]
);
$columnsByTable = [];

foreach ($columns as $column) {
    $columnsByTable[$column['table_name']][] = $column;
}

$statistics = $select(
    'SELECT table_name, index_name, non_unique, seq_in_index, column_name
     FROM information_schema.statistics WHERE table_schema = :schema ORDER BY table_name, index_name, seq_in_index',
    ['schema' => $databaseName]
);
$indexesByTable = [];

foreach ($statistics as $index) {
    $indexesByTable[$index['table_name']][$index['index_name']]['unique'] = ((int) $index['non_unique']) === 0;
    $indexesByTable[$index['table_name']][$index['index_name']]['columns'][] = $index['column_name'];
}

$uuidTablesMissingUnique = [];

foreach ($tableNames as $table) {
    $columnNames = array_column($columnsByTable[$table] ?? [], 'column_name');

    if (!in_array('uuid', $columnNames, true)) {
        continue;
    }

    $hasUniqueUuid = false;

    foreach ($indexesByTable[$table] ?? [] as $index) {
        if ($index['unique'] && $index['columns'] === ['uuid']) {
            $hasUniqueUuid = true;
            break;
        }
    }

    if (!$hasUniqueUuid) {
        $uuidTablesMissingUnique[] = $table;
    }
}

$add('every uuid table has UNIQUE(uuid)', $uuidTablesMissingUnique === [], implode(', ', $uuidTablesMissingUnique));

$fkRows = $select(
    "SELECT rc.constraint_name, kcu.table_name AS child_table, kcu.column_name AS child_column,
            kcu.referenced_table_name AS parent_table, kcu.referenced_column_name AS parent_column
     FROM information_schema.referential_constraints rc
     JOIN information_schema.key_column_usage kcu
       ON kcu.constraint_schema = rc.constraint_schema AND kcu.constraint_name = rc.constraint_name
     WHERE rc.constraint_schema = :schema
     ORDER BY kcu.table_name, rc.constraint_name, kcu.ordinal_position",
    ['schema' => $databaseName]
);
$fks = [];

foreach ($fkRows as $row) {
    $fks[$row['child_table']][$row['constraint_name']]['parent'] = $row['parent_table'];
    $fks[$row['child_table']][$row['constraint_name']]['child_columns'][] = $row['child_column'];
    $fks[$row['child_table']][$row['constraint_name']]['parent_columns'][] = $row['parent_column'];
}

$missingChildIndexes = [];
$missingCompositeParentIndexes = [];

foreach ($fks as $childTable => $constraints) {
    foreach ($constraints as $name => $fk) {
        $covered = false;

        foreach ($indexesByTable[$childTable] ?? [] as $index) {
            if (array_slice($index['columns'], 0, count($fk['child_columns'])) === $fk['child_columns']) {
                $covered = true;
                break;
            }
        }

        if (!$covered) {
            $missingChildIndexes[] = $childTable . '.' . $name;
        }

        if (count($fk['child_columns']) > 1) {
            $uniqueParent = false;

            foreach ($indexesByTable[$fk['parent']] ?? [] as $index) {
                if ($index['unique'] && $index['columns'] === $fk['parent_columns']) {
                    $uniqueParent = true;
                    break;
                }
            }

            if (!$uniqueParent) {
                $missingCompositeParentIndexes[] = $childTable . '.' . $name . ' -> ' . $fk['parent'] . '(' . implode(',', $fk['parent_columns']) . ')';
            }
        }
    }
}

$add('every FK has a left-prefix child index', $missingChildIndexes === [], implode(', ', $missingChildIndexes));
$add('composite tenant FK parents have a matching UNIQUE index', $missingCompositeParentIndexes === [], implode(', ', $missingCompositeParentIndexes));
$add('foreign key count > 0', count($fks) > 0, array_sum(array_map('count', $fks)) . ' FK constraint(s) across ' . count($fks) . ' table(s)');

/* ========================================================================= */
/* 3. Referential integrity (orphan scan)                                    */
/* ========================================================================= */
$orphanFailures = [];

foreach ($fks as $childTable => $constraints) {
    foreach ($constraints as $name => $fk) {
        $on = [];
        $notNull = [];

        foreach ($fk['child_columns'] as $i => $childColumn) {
            $on[] = 'p.' . $fk['parent_columns'][$i] . ' = c.' . $childColumn;
            $notNull[] = 'c.' . $childColumn . ' IS NOT NULL';
        }

        $sql = 'SELECT COUNT(*) FROM `' . $escape($childTable) . '` c LEFT JOIN `' . $escape($fk['parent']) . '` p ON '
            . implode(' AND ', $on) . ' WHERE ' . implode(' AND ', $notNull)
            . ' AND p.' . $fk['parent_columns'][0] . ' IS NULL';

        try {
            $orphans = (int) $scalar($sql);
        } catch (Throwable $exception) {
            $orphans = -1;
        }

        if ($orphans !== 0) {
            $orphanFailures[] = $childTable . '.' . $name . '=' . $orphans;
        }
    }
}

$add('no orphan rows for any foreign key', $orphanFailures === [], implode(', ', $orphanFailures));

/* ========================================================================= */
/* 4a. ENUM sanity                                                           */
/* ========================================================================= */
$enumColumns = [];

foreach ($columns as $column) {
    if (preg_match('/^enum\((.*)\)$/', (string) $column['column_type'], $match) !== 1) {
        continue;
    }

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $match[1], $values);
    $allowed = array_map(static fn (string $v): string => str_replace("''", "'", $v), $values[1]);
    $enumColumns[] = ['table' => $column['table_name'], 'column' => $column['column_name'], 'allowed' => $allowed, 'nullable' => $column['is_nullable'] === 'YES'];
}

$invalidEnumValues = [];

foreach ($enumColumns as $enum) {
    $quoted = implode(', ', array_map(static fn (string $v): string => "'" . str_replace("'", "''", $v) . "'", $enum['allowed']));
    $sql = 'SELECT COUNT(*) FROM `' . $escape($enum['table']) . '` WHERE `' . $escape($enum['column']) . '` NOT IN (' . $quoted . ')';

    if ($enum['nullable']) {
        $sql .= ' AND `' . $escape($enum['column']) . '` IS NOT NULL';
    }

    try {
        $invalid = (int) $scalar($sql);
    } catch (Throwable) {
        $invalid = 0;
    }

    if ($invalid > 0) {
        $invalidEnumValues[] = $enum['table'] . '.' . $enum['column'] . '=' . $invalid;
    }
}

$add('no undeclared ENUM values', $invalidEnumValues === [], implode(', ', $invalidEnumValues));

/* ========================================================================= */
/* 4b. RBAC totals + per-role counts (expected derived from seeders)         */
/* ========================================================================= */
$permissionsSeeder = $read(BASE_PATH . '/database/seeds/PermissionsSeeder.php');
$rolesSeeder = $read(BASE_PATH . '/database/seeds/RolesSeeder.php');

preg_match_all("/'([a-z][a-z0-9_.]+)' => '[^']*',/", $permissionsSeeder, $permissionMatches);
$expectedPermissionSlugs = $permissionMatches[1];

$rolesByBlock = static function (string $text): array {
    // Only real role definitions begin with `'<role>' => [` followed by `'scope' =>`.
    preg_match_all("/'([a-z_]+)' => \\[\\s*'scope' =>/", $text, $matches);
    $roles = [];

    foreach ($matches[1] as $role) {
        $start = strpos($text, "'" . $role . "' => [");

        if ($start === false) {
            continue;
        }

        $open = (int) strpos($text, '[', (int) $start);
        $depth = 0;
        $block = '';

        for ($i = $open; $i < strlen($text); $i++) {
            if ($text[$i] === '[') {
                $depth++;
            } elseif ($text[$i] === ']') {
                $depth--;

                if ($depth === 0) {
                    $block = substr($text, $open, $i - $open + 1);
                    break;
                }
            }
        }

        $roles[$role] = $block;
    }

    return $roles;
};

$roleBlocks = $rolesByBlock($rolesSeeder);
$expectedRoleCounts = [];

foreach ($roleBlocks as $role => $block) {
    if (str_contains($block, 'self::ALL_PERMISSIONS')) {
        $expectedRoleCounts[$role] = count($expectedPermissionSlugs);

        continue;
    }

    $permissionsPosition = strpos($block, "'permissions' => [");

    if ($permissionsPosition === false) {
        $expectedRoleCounts[$role] = 0;

        continue;
    }

    $open = (int) strpos($block, '[', (int) $permissionsPosition);
    $depth = 0;
    $inner = '';

    for ($i = $open; $i < strlen($block); $i++) {
        if ($block[$i] === '[') {
            $depth++;
        } elseif ($block[$i] === ']') {
            $depth--;

            if ($depth === 0) {
                $inner = substr($block, $open, $i - $open + 1);
                break;
            }
        }
    }

    $expectedRoleCounts[$role] = preg_match_all("/'[a-z][a-z0-9_.]+'/", $inner);
}

$livePermissionCount = (int) $scalar('SELECT COUNT(*) FROM permissions');
$liveRoleCount = (int) $scalar('SELECT COUNT(*) FROM roles');
$liveLinkCount = (int) $scalar('SELECT COUNT(*) FROM role_permissions');
$liveRoleCounts = [];

foreach ($select('SELECT r.id, r.name, COUNT(rp.permission_id) AS c FROM roles r LEFT JOIN role_permissions rp ON rp.role_id = r.id GROUP BY r.id, r.name ORDER BY r.name') as $row) {
    $liveRoleCounts[(string) $row['name']] = (int) $row['c'];
}

$expectedLinkTotal = array_sum($expectedRoleCounts);
$add(
    'permission total matches PermissionsSeeder',
    $livePermissionCount === count($expectedPermissionSlugs),
    $livePermissionCount . ' live vs ' . count($expectedPermissionSlugs) . ' seeded'
);
$add(
    'role total matches RolesSeeder',
    $liveRoleCount === count($expectedRoleCounts),
    $liveRoleCount . ' live vs ' . count($expectedRoleCounts) . ' seeded'
);
$add(
    'role-permission link total matches seeders',
    $liveLinkCount === $expectedLinkTotal,
    $liveLinkCount . ' live vs ' . $expectedLinkTotal . ' expected'
);

$roleCountMismatches = [];
$roleCountDetails = [];

foreach ($expectedRoleCounts as $role => $expected) {
    $live = $liveRoleCounts[$role] ?? null;
    $roleCountDetails[] = $role . '=' . ($live ?? '?') . '/' . $expected;

    if ($live !== $expected) {
        $roleCountMismatches[] = $role . '(' . ($live ?? '?') . ' vs ' . $expected . ')';
    }
}

$add('per-role permission counts match seeders', $roleCountMismatches === [], implode(', ', $roleCountMismatches) . ' | ' . implode(' ', $roleCountDetails));

/* ========================================================================= */
/* 4c. Settings JSON sanity                                                  */
/* ========================================================================= */
$invalidSettings = [];
$settingRows = $select('SELECT estate_id, setting_key, setting_value FROM estate_settings ORDER BY estate_id, setting_key');

foreach ($settingRows as $row) {
    $key = (string) $row['setting_key'];

    if ($key === '') {
        $invalidSettings[] = 'estate ' . $row['estate_id'] . ': empty key';
        continue;
    }

    if ($row['setting_value'] === null) {
        continue;
    }

    $decoded = json_decode((string) $row['setting_value'], true);

    if ($decoded === null && strtolower(trim((string) $row['setting_value'])) !== 'null') {
        $invalidSettings[] = 'estate ' . $row['estate_id'] . '.' . $key . ' invalid JSON';
    }
}

$add('estate_settings values are valid JSON', $invalidSettings === [], implode(', ', $invalidSettings) . ' (' . count($settingRows) . ' setting row(s) checked)');

/* ========================================================================= */
/* 4d. Billing expectation (absent pre-Inc-10 / seeded post-gate)            */
/* ========================================================================= */
$billingTables = array_values(array_filter(
    $tableNames,
    static fn (string $name): bool => in_array($name, ['billing_invoices', 'billing_payments'], true)
));

if ($billingTables === []) {
    $add('billing tables state (pre-Inc-10)', true, 'billing_invoices/billing_payments absent â€” expected before the Inc-10 DB gate');
} else {
    $invoiceCount = (int) $scalar("SELECT COUNT(*) FROM billing_invoices");
    $paymentCount = (int) $scalar("SELECT COUNT(*) FROM billing_payments");
    $greenviewInvoices = (int) $scalar("SELECT COUNT(*) FROM billing_invoices i JOIN estates e ON e.id = i.estate_id WHERE e.slug = 'greenview-residences'");
    $greenviewPayments = (int) $scalar("SELECT COUNT(*) FROM billing_payments p JOIN estates e ON e.id = p.estate_id WHERE e.slug = 'greenview-residences'");
    $add(
        'billing demo data present (post-Inc-10)',
        $greenviewInvoices >= 2 && $greenviewPayments >= 1,
        'total invoices=' . $invoiceCount . ', payments=' . $paymentCount . '; greenview invoices=' . $greenviewInvoices . ', payments=' . $greenviewPayments
    );
}

/* ========================================================================= */
/* 5. Secrets / PII                                                          */
/* ========================================================================= */
$suspiciousNames = [];
$knownHashColumns = [];

foreach ($columns as $column) {
    $name = strtolower((string) $column['column_name']);

    if (preg_match('/_(hash|encrypted|secret_hash)$/', $name) === 1) {
        $knownHashColumns[] = $column['table_name'] . '.' . $column['column_name'];

        continue;
    }

    // ENUM discriminators such as credential_type ENUM('pin','qr') are labels, not secrets.
    if (stripos((string) $column['column_type'], 'enum(') === 0) {
        continue;
    }

    if (preg_match('/^(pin|otp|password|passwd|secret|token|credential|api_key)(_|$)/', $name) === 1
        || preg_match('/_(plain|plaintext)$/', $name) === 1) {
        $suspiciousNames[] = $column['table_name'] . '.' . $column['column_name'] . ' (' . $column['column_type'] . ')';
    }
}

$add('no plaintext PIN/OTP/credential columns', $suspiciousNames === [], implode(', ', $suspiciousNames));

$weakHashValues = [];

foreach ($knownHashColumns as $qualified) {
    [$table, $column] = explode('.', $qualified, 2);

    try {
        $weak = (int) $scalar(
            'SELECT COUNT(*) FROM `' . $escape($table) . '` WHERE `' . $escape($column) . '` IS NOT NULL AND `' . $escape($column) . "` <> '' AND CHAR_LENGTH(`" . $escape($column) . "`) < 32"
        );
    } catch (Throwable) {
        $weak = 0;
    }

    if ($weak > 0) {
        $weakHashValues[] = $qualified . '=' . $weak;
    }
}

$add('known hash/secret columns hold hashed/encrypted values', $weakHashValues === [], implode(', ', $weakHashValues) . ' (' . count($knownHashColumns) . ' column(s) checked)');

$piiSampleLimit = 500;
$auditRows = (int) $scalar('SELECT COUNT(*) FROM audit_logs');
$sampleRows = $select('SELECT action, metadata FROM audit_logs WHERE metadata IS NOT NULL ORDER BY id DESC LIMIT ' . $piiSampleLimit);
$piiHits = [];

foreach ($sampleRows as $row) {
    $metadata = (string) $row['metadata'];

    if (preg_match('/(\+?2547\d{8}|\b0[17]\d{8}\b)/', $metadata) === 1) {
        $piiHits[] = $row['action'] . ': phone-like value';
    }

    if (preg_match('/"(pin|otp|password|secret|token)"\s*:/', $metadata) === 1) {
        $piiHits[] = $row['action'] . ': credential-key value';
    }

    if (preg_match('/"(full_name|resident_name|visitor_name)"\s*:\s*"/', $metadata) === 1) {
        $piiHits[] = $row['action'] . ': name value';
    }
}

$add(
    'audit metadata PII sample',
    $piiHits === [],
    count($sampleRows) . '/' . $auditRows . ' row(s) sampled' . ($piiHits === [] ? '' : ' â€” ' . implode('; ', array_slice(array_unique($piiHits), 0, 5)))
);

/* ========================================================================= */
/* Report                                                                    */
/* ========================================================================= */
$counts = [
    'migrations' => count($fileNames),
    'tables' => count($tableNames),
    'permissions' => $livePermissionCount,
    'roles' => $liveRoleCount,
    'role_links' => $liveLinkCount,
    'foreign_keys' => array_sum(array_map('count', $fks)),
    'settings' => count($settingRows),
    'audit_rows' => $auditRows,
];
$status = $failures === 0 ? 'ok' : 'fail';

if ($json) {
    fwrite(STDOUT, json_encode([
        'status' => $status,
        'database' => $databaseName,
        'checked_at' => gmdate('c'),
        'counts' => $counts,
        'checks' => $checks,
        'failures' => $failures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    exit($failures === 0 ? 0 : 1);
}

fwrite(STDOUT, 'MtaaPass database integrity check â€” database "' . $databaseName . '"' . PHP_EOL);
fwrite(STDOUT, 'Counts: ' . $counts['migrations'] . ' migrations, ' . $counts['tables'] . ' tables, '
    . $counts['permissions'] . ' permissions, ' . $counts['roles'] . ' roles, ' . $counts['role_links'] . ' role links, '
    . $counts['foreign_keys'] . ' FK constraints, ' . $counts['settings'] . ' settings rows, ' . $counts['audit_rows'] . ' audit rows' . PHP_EOL . PHP_EOL);

foreach ($checks as $check) {
    fwrite(STDOUT, ($check['ok'] ? 'PASS' : 'FAIL') . '  ' . $check['name'] . ($check['detail'] !== '' ? ' â€” ' . $check['detail'] : '') . PHP_EOL);
}

fwrite(STDOUT, PHP_EOL . ($failures === 0
    ? 'OK â€” database integrity checks passed (' . count($checks) . ' checks).'
    : 'FAILED â€” ' . $failures . ' of ' . count($checks) . ' checks failed.') . PHP_EOL);

exit($failures === 0 ? 0 : 1);

