<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use PDOException;

/**
 * Migrations: exact file inventory, fresh install, repeatability, checksum
 * tracking and multi-tenant referential integrity (contract §4, §12).
 */
final class MigrationTest extends IntegrationTestCase
{
    private const EXPECTED_FILES = [
        '20261111_000001_create_saas_and_estate_tables.sql',
        '20261111_000002_create_identity_and_rbac_tables.sql',
        '20261111_000003_create_estate_structure_tables.sql',
        '20261111_000004_create_security_and_audit_tables.sql',
        '20261111_000006_create_visitor_access_tables.sql',
    ];

    private const EXPECTED_TABLES = [
        'saas_accounts',
        'estates',
        'estate_settings',
        'subscription_plans',
        'subscriptions',
        'users',
        'roles',
        'permissions',
        'role_permissions',
        'estate_memberships',
        'platform_memberships',
        'buildings',
        'households',
        'household_members',
        'gates',
        'guard_gate_assignments',
        'audit_logs',
        'otp_challenges',
        'rate_limits',
        'notification_logs',
        'visitors',
        'visitor_invitations',
        'access_credentials',
        'visitor_access_events',
        'walk_in_requests',
        'access_verification_attempts',
        'schema_migrations',
    ];

    private function migrator(): Migrator
    {
        return new Migrator($this->db, BASE_PATH . '/database/migrations');
    }

    public function testMigrationFileInventory(): void
    {
        $files = array_map('basename', $this->migrator()->files());

        $this->assertSame(self::EXPECTED_FILES, $files, 'Migration filenames and order must match contract §4.');
    }

    public function testFreshInstallAppliesEveryMigration(): void
    {
        $applied = $this->migrator()->fresh();

        $this->assertCount(5, $applied, 'A fresh install must apply all five migrations.');
        $this->assertSame(self::EXPECTED_FILES, $applied);

        $status = $this->migrator()->status();
        $this->assertCount(5, $status);

        foreach ($status as $entry) {
            $this->assertTrue($entry['applied'], 'Migration ' . $entry['migration'] . ' must be recorded as applied.');
        }

        $this->assertSame(5, (int) $this->db->scalar('SELECT COUNT(*) FROM schema_migrations'));
    }

    public function testMigrateIsIdempotentOnSecondRun(): void
    {
        // setUp() already applied a fresh install.
        $this->assertSame(5, (int) $this->db->scalar('SELECT COUNT(*) FROM schema_migrations'));

        $applied = $this->migrator()->migrate();

        $this->assertSame([], $applied, 'A second migrate run must apply nothing.');
        $this->assertSame(5, (int) $this->db->scalar('SELECT COUNT(*) FROM schema_migrations'));
    }

    public function testFreshIsRepeatable(): void
    {
        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM schema_migrations');

        $first = $this->migrator()->fresh();
        $second = $this->migrator()->fresh();

        $this->assertCount(5, $first);
        $this->assertCount(5, $second);
        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM schema_migrations'));
    }

    public function testChecksumsAreRecordedPerFile(): void
    {
        $rows = $this->db->select('SELECT migration, checksum, batch FROM schema_migrations ORDER BY migration ASC');

        $this->assertCount(5, $rows);

        foreach ($rows as $row) {
            $this->assertMatches('/^[0-9a-f]{64}$/', (string) $row['checksum']);
            $file = BASE_PATH . '/database/migrations/' . $row['migration'];
            $this->assertTrue(is_file($file), 'Recorded migration file must exist: ' . $row['migration']);
            $this->assertSame(hash('sha256', (string) file_get_contents($file)), $row['checksum']);
            $this->assertSame(1, (int) $row['batch'], 'A fresh install runs as a single batch.');
        }
    }

    public function testAllContractTablesExist(): void
    {
        $rows = $this->db->select(
            'SELECT table_name AS name FROM information_schema.tables
             WHERE table_schema = :database AND table_type = :type',
            ['database' => $this->db->databaseName(), 'type' => 'BASE TABLE']
        );

        $tables = array_map(static fn (array $row): string => (string) $row['name'], $rows);

        foreach (self::EXPECTED_TABLES as $expected) {
            $this->assertContains($expected, $tables, 'Missing table: ' . $expected);
        }
    }

    public function testTablesUseInnodbAndUtf8mb4(): void
    {
        $rows = $this->db->select(
            'SELECT table_name AS name, engine, table_collation AS collation
             FROM information_schema.tables
             WHERE table_schema = :database AND table_type = :type',
            ['database' => $this->db->databaseName(), 'type' => 'BASE TABLE']
        );

        $this->assertTrue($rows !== [], 'No tables found in the test database.');

        foreach ($rows as $row) {
            $this->assertSame('InnoDB', (string) $row['engine'], 'Table ' . $row['name'] . ' must use InnoDB.');
            $this->assertContains('utf8mb4', (string) $row['collation'], 'Table ' . $row['name'] . ' must use utf8mb4.');
        }
    }

    public function testTenantCompositeForeignKeyRejectsCrossEstateChild(): void
    {
        $greenview = $this->seededEstate('greenview-residences');
        $riverside = $this->seededEstate('riverside-court');

        $riversideBuildingId = (int) $this->db->scalar(
            'SELECT id FROM buildings WHERE estate_id = :estate_id ORDER BY id ASC LIMIT 1',
            ['estate_id' => (int) $riverside['id']]
        );

        $this->assertTrue($riversideBuildingId > 0, 'Riverside Court must have at least one seeded building.');

        try {
            $this->db->insert('households', [
                'uuid' => \App\Core\Uuid::v4(),
                'estate_id' => (int) $greenview['id'],
                'building_id' => $riversideBuildingId,
                'unit_number' => 'X-TENANT-' . bin2hex(random_bytes(4)),
                'household_type' => 'apartment',
                'status' => 'active',
                'created_at' => \App\Core\Clock::nowDb(),
            ]);
            $this->fail('The (building_id, estate_id) composite foreign key must reject a cross-estate household.');
        } catch (PDOException) {
            $this->assertTrue(true, 'Composite tenant foreign key rejected the cross-estate row.');
        }
    }

    public function testSchemaMigrationsTrackingColumns(): void
    {
        $columns = $this->db->select(
            'SELECT column_name AS name FROM information_schema.columns
             WHERE table_schema = :database AND table_name = :table',
            ['database' => $this->db->databaseName(), 'table' => 'schema_migrations']
        );

        $names = array_map(static fn (array $row): string => (string) $row['name'], $columns);

        foreach (['id', 'migration', 'batch', 'checksum', 'applied_at'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function testMigrationFreshRefusesNonTestDatabase(): void
    {
        // The guard is environment-based and database-name-based; a normal
        // environment is not testing, so Migrator::fresh() must refuse.
        $previous = \App\Core\Config::get('app.env');
        \App\Core\Config::set('app.env', 'production');

        try {
            try {
                $this->migrator()->fresh();
                $this->fail('Migrator::fresh() must refuse to drop tables outside local/testing.');
            } catch (\RuntimeException $exception) {
                $this->assertContains('Refusing to drop tables', $exception->getMessage());
            }
        } finally {
            \App\Core\Config::set('app.env', $previous);
        }
    }
}
