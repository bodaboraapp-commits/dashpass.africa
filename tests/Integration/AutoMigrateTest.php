<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AutoMigrator;

/**
 * Automatic migrations on deploy (owner request 2026-11-13).
 *
 * A cPanel Git pull no longer needs a manual `php scripts/migrate.php`: the
 * first web request applies pending migrations under an advisory lock. CLI
 * (tests, cron, explicit migrate command) is deliberately untouched.
 */
final class AutoMigrateTest extends IntegrationTestCase
{
    private const MIGRATION = '20261111_000016_create_household_move_outs.sql';

    public function testCliRequestsDoNotRunAutomaticMigrations(): void
    {
        // Remove the latest migration's table + ledger row, then prove the CLI
        // guard in runIfNeeded() leaves it pending (tests/Cron own migrations).
        $this->forgetLatestMigration();

        AutoMigrator::runIfNeeded();

        $this->assertNull(
            $this->db->selectOne("SHOW TABLES LIKE 'household_move_outs'"),
            'The CLI SAPI must not auto-migrate; explicit migrate commands remain in control.'
        );
    }

    public function testRunAppliesPendingMigrationsAndRecordsThem(): void
    {
        $this->forgetLatestMigration();
        $this->assertNull($this->db->selectOne("SHOW TABLES LIKE 'household_move_outs'"));

        AutoMigrator::run();

        $this->assertNotNull(
            $this->db->selectOne("SHOW TABLES LIKE 'household_move_outs'"),
            'Auto-migrate must create the pending ledger table.'
        );

        $row = $this->db->selectOne(
            'SELECT migration FROM schema_migrations WHERE migration = :name LIMIT 1',
            ['name' => self::MIGRATION]
        );
        $this->assertNotNull($row, 'Auto-migrate must record the applied migration.');
    }

    public function testRunIsIdempotentWhenNothingIsPending(): void
    {
        AutoMigrator::run();
        AutoMigrator::run();

        $this->assertNotNull($this->db->selectOne("SHOW TABLES LIKE 'household_move_outs'"));
    }

    /**
     * Drop the newest migration's table and ledger row so it is pending again.
     * The per-test fresh database makes this safe.
     */
    private function forgetLatestMigration(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS household_move_outs');
        $this->db->execute(
            'DELETE FROM schema_migrations WHERE migration = :name',
            ['name' => self::MIGRATION]
        );
    }
}
