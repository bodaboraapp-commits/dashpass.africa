<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Migration runner with version tracking.
 *
 * Migrations are plain SQL files applied in filename order. Each applied file is
 * recorded in `schema_migrations` with a checksum so edits to already-applied
 * migrations are detected instead of silently diverging between environments.
 */
final class Migrator
{
    private const TABLE = 'schema_migrations';

    public function __construct(
        private readonly Database $db,
        private readonly string $path
    ) {
    }

    /**
     * Create the tracking table if it does not exist. Idempotent.
     */
    public function ensureTable(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration VARCHAR(190) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME(3) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migration_name (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Files on disk that have not been applied yet.
     *
     * @return array<int, string>
     */
    public function pending(): array
    {
        $this->ensureTable();
        $applied = $this->appliedNames();
        $files = $this->files();

        return array_values(array_filter(
            $files,
            static fn (string $file): bool => !in_array(basename($file), $applied, true)
        ));
    }

    /**
     * Apply all pending migrations. Safe to run repeatedly.
     *
     * @return array<int, string> Applied migration names.
     */
    public function migrate(): array
    {
        $this->ensureTable();
        $this->verifyChecksums();

        $pending = $this->pending();

        if ($pending === []) {
            return [];
        }

        $batch = ((int) $this->db->scalar('SELECT COALESCE(MAX(batch), 0) FROM ' . self::TABLE)) + 1;
        $applied = [];

        foreach ($pending as $file) {
            $name = basename($file);
            $sql = (string) file_get_contents($file);

            if (trim($sql) === '') {
                continue;
            }

            Logger::info('Applying migration', ['migration' => $name]);

            foreach (self::splitStatements($sql) as $statement) {
                $this->db->execute($statement);
            }

            $this->db->insert(self::TABLE, [
                'migration' => $name,
                'batch' => $batch,
                'checksum' => hash('sha256', $sql),
                'applied_at' => Clock::nowDb(),
            ]);

            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function status(): array
    {
        $this->ensureTable();
        $applied = $this->db->select(
            'SELECT migration, batch, checksum, applied_at FROM ' . self::TABLE . ' ORDER BY id ASC'
        );
        $appliedByName = [];

        foreach ($applied as $row) {
            $appliedByName[(string) $row['migration']] = $row;
        }

        $status = [];

        foreach ($this->files() as $file) {
            $name = basename($file);
            $row = $appliedByName[$name] ?? null;

            $status[] = [
                'migration' => $name,
                'applied' => $row !== null,
                'batch' => $row['batch'] ?? null,
                'applied_at' => $row['applied_at'] ?? null,
            ];
        }

        return $status;
    }

    /**
     * Drop every table in the configured database and re-run migrations.
     *
     * Destructive: refused unless the application is in a local/testing
     * environment and the database name ends with `_test`.
     *
     * @return array<int, string>
     */
    public function fresh(): array
    {
        $environment = (string) Config::get('app.env');

        if (!in_array($environment, ['local', 'development', 'testing'], true)) {
            throw new \RuntimeException('Refusing to drop tables outside a local or testing environment.');
        }

        $database = $this->db->databaseName();

        if (!str_ends_with($database, '_test')) {
            throw new \RuntimeException(
                'Refusing to drop tables in database "' . $database . '". Test databases must end with `_test`.'
            );
        }

        $this->dropAllTables();

        return $this->migrate();
    }

    public function dropAllTables(): void
    {
        $database = $this->db->databaseName();

        $tables = $this->db->select(
            'SELECT table_name AS name
             FROM information_schema.tables
             WHERE table_schema = :database AND table_type = :type',
            ['database' => $database, 'type' => 'BASE TABLE']
        );

        if ($tables === []) {
            return;
        }

        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $name = (string) $table['name'];

                if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                    throw new \RuntimeException('Refusing to drop suspicious table name: ' . $name);
                }

                $this->db->execute('DROP TABLE IF EXISTS `' . $name . '`');
            }
        } finally {
            $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * @return array<int, string>
     */
    public function files(): array
    {
        $files = glob(rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . '*.sql');

        if ($files === false) {
            return [];
        }

        sort($files);

        return array_values($files);
    }

    /**
     * @return array<int, string>
     */
    private function appliedNames(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['migration'],
            $this->db->select('SELECT migration FROM ' . self::TABLE)
        );
    }

    /**
     * Detect a migration file that changed after it was applied.
     */
    private function verifyChecksums(): void
    {
        $applied = [];

        foreach ($this->db->select('SELECT migration, checksum FROM ' . self::TABLE) as $row) {
            $applied[(string) $row['migration']] = (string) $row['checksum'];
        }

        foreach ($this->files() as $file) {
            $name = basename($file);

            if (!isset($applied[$name])) {
                continue;
            }

            $checksum = hash('sha256', (string) file_get_contents($file));

            if (!hash_equals($applied[$name], $checksum)) {
                throw new \RuntimeException(
                    'Migration ' . $name . ' has changed after being applied. Create a new migration instead.'
                );
            }
        }
    }

    /**
     * Split a SQL file into statements.
     *
     * MtaaPass migrations deliberately avoid stored procedures, triggers and
     * delimiter changes, so a semicolon split outside quotes is sufficient and
     * keeps the runner dependency-free.
     *
     * @return array<int, string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inSingle = false;
        $inDouble = false;
        $inLineComment = false;
        $inBlockComment = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }

                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }

                continue;
            }

            if (!$inSingle && !$inDouble) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    $i++;

                    continue;
                }

                if ($char === '#') {
                    $inLineComment = true;

                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;

                    continue;
                }
            }

            if ($char === "'" && !$inDouble) {
                $escaped = $i > 0 && $sql[$i - 1] === '\\';

                if (!$escaped) {
                    $inSingle = !$inSingle;
                }
            } elseif ($char === '"' && !$inSingle) {
                $escaped = $i > 0 && $sql[$i - 1] === '\\';

                if (!$escaped) {
                    $inDouble = !$inDouble;
                }
            }

            if ($char === ';' && !$inSingle && !$inDouble) {
                $trimmed = trim($current);

                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }

                $current = '';

                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);

        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
