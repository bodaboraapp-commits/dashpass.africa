<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Uuid;

/**
 * Base class for idempotent seeders.
 *
 * Every seeder is safe to run multiple times: rows are looked up by natural
 * keys (phone, slug, unit number, role/permission name, ...) and only missing
 * rows are inserted. Counters are returned so the CLI can report exactly what
 * changed. Table and column identifiers are developer-defined constants, never
 * request input, and are validated before being interpolated.
 */
abstract class Seeder
{
    protected Database $db;

    /** @var array<string, int> */
    protected array $counters = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Run the seeder and return per-entity change counters.
     *
     * @return array<string, int>
     */
    abstract public function run(): array;

    /**
     * Reset the change counters. Called at the start of each run.
     *
     * @param array<int, string> $keys
     */
    protected function resetCounters(array $keys): void
    {
        $this->counters = array_fill_keys($keys, 0);
    }

    protected function count(string $key): void
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    /**
     * @return array<string, int>
     */
    protected function counters(): array
    {
        return $this->counters;
    }

    /**
     * Find a row id by natural keys.
     *
     * @param array<string, mixed> $where
     */
    protected function findId(string $table, array $where): ?int
    {
        $this->assertIdentifier($table);
        $conditions = [];
        $params = [];

        foreach ($where as $column => $value) {
            $this->assertIdentifier($column);
            $conditions[] = '`' . $column . '` = :' . $column;
            $params[$column] = $value;
        }

        $row = $this->db->selectOne(
            'SELECT `id` FROM `' . $table . '` WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1',
            $params
        );

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Insert a row from column => value pairs.
     *
     * @param array<string, mixed> $data
     */
    protected function insert(string $table, array $data): int
    {
        $this->assertIdentifier($table);

        foreach (array_keys($data) as $column) {
            $this->assertIdentifier($column);
        }

        return $this->db->insert($table, $data);
    }

    /**
     * Update a single row addressed by natural keys.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    protected function update(string $table, array $data, array $where): int
    {
        $this->assertIdentifier($table);

        foreach (array_keys($data) as $column) {
            $this->assertIdentifier($column);
        }

        foreach (array_keys($where) as $column) {
            $this->assertIdentifier($column);
        }

        return $this->db->update($table, $data, $where);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function sameValue(mixed $existing, mixed $expected): bool
    {
        return $existing === $expected;
    }

    protected function now(): string
    {
        return Clock::nowDb();
    }

    protected function uuid(): string
    {
        return Uuid::v4();
    }

    /**
     * JSON encode a value for a JSON column. JSON scalars (numbers, booleans)
     * are valid JSON documents, so settings can be stored as native types.
     */
    protected function json(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode seed value as JSON.');
        }

        return $encoded;
    }

    /**
     * @param mixed $value
     */
    protected function decodeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return json_decode((string) $value, true);
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \RuntimeException('Refusing unsafe SQL identifier: ' . $identifier);
        }
    }
}
