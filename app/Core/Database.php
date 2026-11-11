<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * PDO connection manager and thin query helpers.
 *
 * All SQL in the application uses prepared statements. Every tenant-scoped
 * read or write goes through a repository that filters on estate_id derived
 * from the authenticated context (Blueprint §13).
 */
final class Database
{
    private static ?self $instance = null;

    private ?PDO $pdo = null;

    /** @var array<string, mixed> */
    private array $config;

    private int $transactionDepth = 0;

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string, mixed>|null $config Override for the test bootstrap.
     */
    public static function instance(?array $config = null): self
    {
        if (self::$instance === null) {
            $resolved = $config ?? (array) Config::get('database.connections.' . Config::get('database.default'));
            self::$instance = new self($resolved);
        }

        return self::$instance;
    }

    public static function reset(?array $config = null): void
    {
        self::$instance = $config === null ? null : new self($config);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $driver = (string) ($this->config['driver'] ?? 'mysql');

        $dsn = match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                (string) ($this->config['host'] ?? '127.0.0.1'),
                (int) ($this->config['port'] ?? 3306),
                (string) ($this->config['database'] ?? 'mtaapass'),
                (string) ($this->config['charset'] ?? 'utf8mb4')
            ),
            default => throw new RuntimeException('Unsupported database driver: ' . $driver),
        };

        $this->pdo = new PDO(
            $dsn,
            (string) ($this->config['username'] ?? ''),
            (string) ($this->config['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
            ]
        );

        return $this->pdo;
    }

    public function databaseName(): string
    {
        return (string) ($this->config['database'] ?? '');
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'mysql');
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Execute a statement and return the affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Insert a row from an associative array of column => value.
     *
     * Column names are developer-supplied constants, never user input.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new RuntimeException('Cannot insert an empty row.');
        }

        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->run($sql, $this->bindable($data));

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Update rows from an associative array, returning the affected count.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            throw new RuntimeException('Cannot update with empty data.');
        }

        if ($where === []) {
            throw new RuntimeException('Refusing to update without a WHERE clause.');
        }

        $assignments = [];
        $params = [];

        foreach ($data as $column => $value) {
            $assignments[] = $column . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }

        $conditions = [];

        foreach ($where as $column => $value) {
            $conditions[] = $column . ' = :where_' . $column;
            $params['where_' . $column] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $assignments),
            implode(' AND ', $conditions)
        );

        return $this->run($sql, $this->bindable($params))->rowCount();
    }

    /**
     * Run a callback inside a transaction with automatic rollback on failure.
     *
     * Nested calls use savepoints so services can compose transactionally.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($this->transactionDepth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp_' . $this->transactionDepth);
        }

        $this->transactionDepth++;

        try {
            $result = $callback($this);
        } catch (Throwable $exception) {
            $this->transactionDepth--;

            if ($this->transactionDepth === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT sp_' . $this->transactionDepth);
            }

            throw $exception;
        }

        $this->transactionDepth--;

        if ($this->transactionDepth === 0 && $pdo->inTransaction()) {
            $pdo->commit();
        } elseif ($this->transactionDepth > 0) {
            $pdo->exec('RELEASE SAVEPOINT sp_' . $this->transactionDepth);
        }

        return $result;
    }

    public function inTransaction(): bool
    {
        return $this->pdo()->inTransaction();
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function run(string $sql, array $params = []): \PDOStatement
    {
        try {
            $statement = $this->pdo()->prepare($sql);
            $statement->execute($params);

            return $statement;
        } catch (PDOException $exception) {
            Logger::error('Database query failed', [
                'sql' => $this->redactSql($sql),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * PDO is happiest with positional/array parameters for `execute()`, but this
     * helper keeps associative inserts readable.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function bindable(array $data): array
    {
        $bindable = [];

        foreach ($data as $key => $value) {
            $bindable[':' . $key] = $value;
        }

        return $bindable;
    }

    private function redactSql(string $sql): string
    {
        // Only the statement shape is logged; bound values never appear here.
        return preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
    }
}
