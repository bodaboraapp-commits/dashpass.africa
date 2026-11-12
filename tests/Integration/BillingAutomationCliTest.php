<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Request;
use App\Modules\Billing\Automation\BillingAutomationService;

/**
 * Inc-13 P1 — automated billing CLI/service (`scripts/billing-automate.php`).
 *
 * PARKED pre-write (qa-mail, build gate). The P1 slice has landed; fold into
 * tests/Integration/ and run `php tests/run.php --filter=BillingAutomationCli`
 * in a quiet window (single runner).
 *
 * Frozen contract: docs/automated-billing-contract.md §5 + §8 of the CLI:
 *   php scripts/billing-automate.php [--dry-run] [--date=YYYY-MM-DD (UTC)]
 *       [--scope=all|subscriptions|schedule|send|deliveries|reminders|utility]
 *       [--estate=<uuid>] [--json] [--limit=N] [--max-catchup=N] [--help]
 *   exits 0 ok/no-op/disabled/lock-held | 1 fatal/partial | 2 invalid args.
 * P1 scope: subscription invoice generation only (utility/send/reminders are
 * later slices; `--scope=utility` is a validation error until P3).
 */
final class BillingAutomationCliTest extends AdminModuleTestCase
{
    private const SCRIPT = '/scripts/billing-automate.php';

    /** @var array<string, mixed> */
    private array $originalAutomation = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalAutomation = (array) Config::get('billing.automation', []);
        $this->setFlag(false);
    }

    public function tearDown(): void
    {
        $this->setFlag(false);
        Config::set('billing.automation', $this->originalAutomation);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function setFlag(bool $enabled): void
    {
        putenv('AUTOMATED_BILLING_ENABLED=' . ($enabled ? 'true' : 'false'));
        $_ENV['AUTOMATED_BILLING_ENABLED'] = $enabled ? 'true' : 'false';
        $_SERVER['AUTOMATED_BILLING_ENABLED'] = $enabled ? 'true' : 'false';
        Config::set('billing.automation.enabled', $enabled);
    }

    /**
     * @param array<int, string> $arguments
     * @return array{code: int, output: string}
     */
    private function runCli(array $arguments = []): array
    {
        $script = BASE_PATH . self::SCRIPT;
        $this->assertTrue(is_file($script), 'scripts/billing-automate.php must exist.');

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $output = [];
        $code = 0;
        @exec($command . ' 2>&1', $output, $code);

        return ['code' => (int) $code, 'output' => implode("\n", $output)];
    }

    /**
     * In-process service context (no session needed: automation is system-scoped).
     */
    private function invocationContext(): Context
    {
        return new Context(
            new Request('GET', '/billing-automate', [], [], [], null, '127.0.0.1', 'MtaaPass-Test/1.0'),
            'qa-automation-' . bin2hex(random_bytes(4))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function greenview(): array
    {
        return $this->seededEstate(self::ESTATE_SLUG);
    }

    /**
     * @return array<string, mixed>
     */
    private function growthSubscription(): array
    {
        $estate = $this->greenview();
        $row = $this->db->selectOne(
            'SELECT * FROM subscriptions WHERE estate_id = :eid ORDER BY id DESC LIMIT 1',
            ['eid' => (int) $estate['id']]
        );

        if ($row === null) {
            $this->fail('The seeded Greenview subscription is required.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function insertSchedule(array $overrides = [], string $estateSlug = self::ESTATE_SLUG): array
    {
        $estate = $this->seededEstate($estateSlug);
        $subscription = $this->db->selectOne(
            'SELECT * FROM subscriptions WHERE estate_id = :eid ORDER BY id DESC LIMIT 1',
            ['eid' => (int) $estate['id']]
        );
        $now = Clock::nowDb();

        $row = array_merge([
            'uuid' => $this->newUuid(),
            'scope' => 'estate_subscription',
            'estate_id' => (int) $estate['id'],
            'subscription_id' => $subscription === null ? null : (int) $subscription['id'],
            'household_id' => null,
            'name' => 'Automation fixture ' . bin2hex(random_bytes(3)),
            'utility_type' => null,
            'flat_amount_cents' => null,
            'tariff_cents_per_unit' => null,
            'unit_label' => null,
            'currency' => 'KES',
            'period_unit' => 'month',
            'generate_lead_days' => 0,
            'due_days' => 14,
            'reminder_before_days' => 3,
            'reminder_on_due' => 1,
            'proration_policy' => 'none',
            'send_on_generate' => 0,
            'status' => 'active',
            'next_run_at' => Clock::toDb(Clock::now()->modify('-1 day')),
            'last_run_at' => null,
            'last_period_key' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        // The P1 seeder already creates the Greenview subscription schedule
        // (UNIQUE(subscription_id)); reuse/update it instead of inserting a second
        // row so tests control next_run_at deterministically.
        if ($row['subscription_id'] !== null) {
            $existing = $this->db->selectOne(
                'SELECT * FROM billing_schedules WHERE subscription_id = :sid LIMIT 1',
                ['sid' => (int) $row['subscription_id']]
            );

            if ($existing !== null) {
                $this->db->update('billing_schedules', [
                    'period_unit' => (string) $row['period_unit'],
                    'generate_lead_days' => (int) $row['generate_lead_days'],
                    'due_days' => (int) $row['due_days'],
                    'send_on_generate' => (int) $row['send_on_generate'],
                    'status' => 'active',
                    'next_run_at' => (string) $row['next_run_at'],
                    'last_run_at' => null,
                    'last_period_key' => null,
                    'updated_at' => $now,
                ], ['id' => (int) $existing['id']]);

                $refreshed = $this->db->selectOne('SELECT * FROM billing_schedules WHERE id = :id', ['id' => (int) $existing['id']]);
                $this->assertNotNull($refreshed);

                return $refreshed;
            }
        }

        $this->db->insert('billing_schedules', $row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function runService(array $options): array
    {
        Config::set('billing.automation.enabled', true);

        return BillingAutomationService::make($this->invocationContext())->run($options);
    }

    /**
     * @return array<string, int>
     */
    private function writeCounts(): array
    {
        return [
            'invoices' => (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices'),
            'schedules' => (int) $this->db->scalar('SELECT COUNT(*) FROM billing_schedules'),
            'charges' => (int) $this->db->scalar('SELECT COUNT(*) FROM utility_charges'),
            'audit' => (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'billing.%'"),
        ];
    }

    /**
     * @param array<string, mixed> $schedule
     * @return array<int, array<string, mixed>>
     */
    private function invoicesForSchedule(array $schedule): array
    {
        return $this->db->select(
            "SELECT * FROM billing_invoices
             WHERE generation_key LIKE :needle AND estate_id = :eid
             ORDER BY id ASC",
            ['needle' => 'sub:%', 'eid' => (int) $schedule['estate_id']]
        );
    }

    private function automationInvoiceCount(int $estateId, ?string $periodKey = null): int
    {
        $sql = "SELECT COUNT(*) FROM billing_invoices WHERE source = 'automation' AND estate_id = :eid";

        if ($periodKey !== null) {
            $sql .= ' AND period_key = :period';
        }

        $params = ['eid' => $estateId];

        if ($periodKey !== null) {
            $params['period'] = $periodKey;
        }

        return (int) $this->db->scalar($sql, $params);
    }

    /**
     * Parse the last JSON object printed by a CLI run (bootstrap warnings may be
     * printed to the same stream on this XAMPP build).
     *
     * @return array<string, mixed>
     */
    private function extractJson(string $output): array
    {
        $lines = array_reverse(array_filter(array_map('trim', explode("\n", $output))));

        foreach ($lines as $line) {
            if (!str_starts_with($line, '{')) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $this->fail('No JSON object found in CLI output: ' . $output);
    }

    /* ------------------------------------------------------------------ */
    /* CLI surface                                                        */
    /* ------------------------------------------------------------------ */

    public function testCliUsageAndInvalidArguments(): void
    {
        $help = $this->runCli(['--help']);
        $this->assertSame(0, $help['code']);
        $this->assertStringContainsString('--dry-run', $help['output']);
        $this->assertStringContainsString('--date=YYYY-MM-DD', $help['output']);
        $this->assertStringContainsString('--max-catchup', $help['output']);

        foreach ([
            ['--nonsense'],
            ['--date=not-a-date'],
            ['--scope=nope'],
            ['--estate=not-a-uuid'],
            ['--limit=0'],
            ['--max-catchup=99'],
        ] as $arguments) {
            $result = $this->runCli($arguments);
            $this->assertSame(2, $result['code'], 'Invalid argument must exit 2: ' . implode(' ', $arguments) . ' => ' . $result['output']);
        }

        // Utility generation landed with the P3 slice: a valid scope (disabled flag
        // or dry-run still exits 0).
        $utility = $this->runCli(['--scope=utility', '--dry-run', '--json']);
        $this->assertSame(0, $utility['code'], 'Utility scope is a valid scope now. Output: ' . $utility['output']);
    }

    public function testDisabledFlagIsASafeNoOp(): void
    {
        $schedule = $this->insertSchedule();
        $before = $this->writeCounts();
        $this->setFlag(false);

        $result = $this->runCli(['--json', '--date=2026-11-15']);
        $this->assertSame(0, $result['code'], 'Flag off must be a safe no-op. Output: ' . $result['output']);

        $payload = $this->extractJson($result['output']);
        $this->assertSame('disabled', (string) ($payload['status'] ?? ''));

        $this->assertSame($before, $this->writeCounts(), 'A disabled run must not write.');
        $this->assertSame(0, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM billing_invoices WHERE estate_id = :eid AND source = :source',
            ['eid' => (int) $schedule['estate_id'], 'source' => 'automation']
        ));
    }

    public function testDryRunWritesNothing(): void
    {
        $this->insertSchedule(['next_run_at' => Clock::toDb(new \DateTimeImmutable('2026-12-01 06:00:00', new \DateTimeZone('UTC')))]);
        $before = $this->writeCounts();

        $result = $this->runService([
            'dry_run' => true,
            'date' => new \DateTimeImmutable('2026-12-15', new \DateTimeZone('UTC')),
            'scope' => 'all',
            'estate_uuid' => (string) $this->greenview()['uuid'],
        ]);

        $this->assertTrue((bool) ($result['dry_run'] ?? false));
        $this->assertTrue((int) ($result['counts']['scanned'] ?? 0) >= 1, 'The dry run must scan the due schedule.');
        $this->assertSame($before, $this->writeCounts(), 'Dry run must not write invoices/schedules/audit.');
        $this->assertSame(0, $this->automationInvoiceCount((int) $this->greenview()['id']), 'Dry run must not persist invoices.');
    }

    /* ------------------------------------------------------------------ */
    /* Generation                                                         */
    /* ------------------------------------------------------------------ */

    public function testApplyGeneratesSubscriptionInvoiceAndAdvancesSchedule(): void
    {
        $schedule = $this->insertSchedule([
            'next_run_at' => Clock::toDb((new \DateTimeImmutable('2026-12-01 06:00:00', new \DateTimeZone('UTC')))),
        ]);
        $plan = $this->db->selectOne('SELECT * FROM subscription_plans WHERE id = :id', ['id' => (int) $this->growthSubscription()['plan_id']]);

        $result = $this->runService([
            'date' => new \DateTimeImmutable('2026-12-15', new \DateTimeZone('UTC')),
            'scope' => 'all',
            'estate_uuid' => (string) $this->greenview()['uuid'],
        ]);

        $this->assertSame('success', (string) ($result['status'] ?? ''), 'Apply run must succeed. ' . json_encode($result));
        $this->assertSame(1, (int) ($result['counts']['generated'] ?? 0));

        $invoices = $this->invoicesForSchedule($schedule);
        $this->assertCount(1, $invoices);
        $invoice = $invoices[0];
        $this->assertSame((int) $schedule['estate_id'], (int) $invoice['estate_id']);
        $this->assertSame('subscription', (string) $invoice['kind']);
        $this->assertSame('automation', (string) $invoice['source']);
        $this->assertSame('open', (string) $invoice['status']);
        $this->assertSame('202612', (string) $invoice['period_key']);
        $this->assertNull($invoice['created_by_user_id'], 'System-generated invoices have a NULL creator.');
        $this->assertMatches('/^INV-\d{6}-\d{4}$/', (string) $invoice['invoice_number']);
        $this->assertSame((int) $plan['monthly_price_cents'], (int) $invoice['amount_cents'], 'The invoice snapshots the current plan price.');

        $updated = $this->db->selectOne('SELECT * FROM billing_schedules WHERE id = :id', ['id' => (int) $schedule['id']]);
        $this->assertNotNull($updated);
        $this->assertSame('202612', (string) $updated['last_period_key']);
        $this->assertNotNull($updated['last_run_at']);
        $this->assertStringStartsWith('2027-01-01', (string) $updated['next_run_at'], 'The next run advances one period.');

        $this->assertTrue((int) $this->db->scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'billing.invoice_generated' AND metadata LIKE :needle",
            ['needle' => '%' . (string) $invoice['uuid'] . '%']
        ) >= 1, 'Generation must audit billing.invoice_generated with the invoice uuid.');
        $this->assertTrue((int) $this->db->scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'billing.automation_run'"
        ) >= 1, 'The run must write a billing.automation_run summary.');
    }

    public function testSecondApplyForTheSamePeriodIsIdempotent(): void
    {
        $schedule = $this->insertSchedule([
            'next_run_at' => Clock::toDb((new \DateTimeImmutable('2026-12-01 06:00:00', new \DateTimeZone('UTC')))),
        ]);
        $date = new \DateTimeImmutable('2026-12-15', new \DateTimeZone('UTC'));
        $options = [
            'date' => $date,
            'scope' => 'all',
            'estate_uuid' => (string) $this->greenview()['uuid'],
        ];

        $this->runService($options);
        $first = $this->writeCounts();
        $this->assertSame(1, $this->automationInvoiceCount((int) $schedule['estate_id']));

        $second = $this->runService($options);
        $this->assertSame(0, (int) ($second['counts']['generated'] ?? -1));
        $this->assertSame($first['invoices'], $this->writeCounts()['invoices'], 'Re-running the same period must not duplicate an invoice.');
        $this->assertSame(1, $this->automationInvoiceCount((int) $schedule['estate_id']));
    }

    public function testCatchUpIsBoundedByMaxCatchup(): void
    {
        $schedule = $this->insertSchedule([
            'next_run_at' => Clock::toDb((new \DateTimeImmutable('2026-12-01 06:00:00', new \DateTimeZone('UTC')))),
        ]);
        $date = new \DateTimeImmutable('2026-12-15', new \DateTimeZone('UTC'));
        $estateId = (int) $schedule['estate_id'];
        $estateUuid = (string) $this->greenview()['uuid'];

        $bounded = $this->runService(['date' => $date, 'scope' => 'all', 'max_catchup' => 1, 'estate_uuid' => $estateUuid]);
        $this->assertSame(1, (int) ($bounded['counts']['generated'] ?? 0), 'max_catchup=1 must generate exactly one period.');
        $this->assertSame(1, $this->automationInvoiceCount($estateId));

        $this->db->execute("DELETE FROM billing_invoices WHERE source = 'automation' AND estate_id = :eid", ['eid' => $estateId]);
        $this->db->execute(
            'UPDATE billing_schedules SET next_run_at = :next, last_run_at = NULL, last_period_key = NULL WHERE id = :id',
            ['next' => Clock::toDb((new \DateTimeImmutable('2026-12-01 06:00:00', new \DateTimeZone('UTC')))), 'id' => (int) $schedule['id']]
        );

        $bounded6 = $this->runService([
            'date' => new \DateTimeImmutable('2027-03-15', new \DateTimeZone('UTC')),
            'scope' => 'all',
            'max_catchup' => 6,
            'estate_uuid' => $estateUuid,
        ]);
        $generated = (int) ($bounded6['counts']['generated'] ?? 0);
        // Dec/Jan/Feb/Mar periods are all <= 2027-03-15 → bounded at 4 < 6.
        $this->assertSame(4, $generated, 'Catch-up must generate each missing period, bounded by the run date/max_catchup.');
        $this->assertSame(4, $this->automationInvoiceCount($estateId));
    }

    public function testEstateScopeRestrictsGeneration(): void
    {
        $greenview = $this->insertSchedule(['next_run_at' => Clock::toDb((new \DateTimeImmutable('2026-12-01 06:00:00', new \DateTimeZone('UTC'))))]);
        $riverside = $this->insertSchedule(['next_run_at' => Clock::toDb((new \DateTimeImmutable('2026-12-01 06:00:00', new \DateTimeZone('UTC'))))], 'riverside-court');

        $this->runService([
            'date' => new \DateTimeImmutable('2026-12-15', new \DateTimeZone('UTC')),
            'scope' => 'all',
            'estate_uuid' => (string) $this->greenview()['uuid'],
        ]);

        $this->assertSame(1, $this->automationInvoiceCount((int) $greenview['estate_id']));
        $this->assertSame(0, $this->automationInvoiceCount((int) $riverside['estate_id']), 'The estate filter must not generate for other estates.');
    }

    public function testLaterScopePlaceholdersAreSafeNoOps(): void
    {
        $this->insertSchedule();
        $before = $this->writeCounts();

        // Retry worker landed: send/deliveries return retry counts (no generation).
        foreach (['send', 'deliveries'] as $scope) {
            $result = $this->runService(['scope' => $scope, 'dry_run' => true, 'date' => new \DateTimeImmutable('2026-12-15', new \DateTimeZone('UTC'))]);
            $this->assertSame('success', (string) ($result['status'] ?? ''), $scope . ' retry scan must succeed.');
            $counts = (array) ($result['counts'] ?? []);
            foreach (['scanned', 'sent', 'retried', 'exhausted', 'skipped'] as $key) {
                $this->assertTrue(array_key_exists($key, $counts), $scope . ' counts must expose ' . $key . '.');
            }
            $this->assertTrue(!isset($counts['generated']) || (int) $counts['generated'] === 0, $scope . ' must not generate invoices.');
        }

        // Reminders landed with P2: a dry-run reports without writing.
        $reminders = $this->runService(['scope' => 'reminders', 'dry_run' => true, 'date' => new \DateTimeImmutable('2026-12-15', new \DateTimeZone('UTC'))]);
        $this->assertSame('success', (string) ($reminders['status'] ?? ''), 'Reminder dry-run must succeed.');

        $this->assertSame($before, $this->writeCounts());
    }

    public function testJsonCliOutputIsParseableAndPiiFree(): void
    {
        $this->insertSchedule(['next_run_at' => Clock::toDb((new \DateTimeImmutable('2026-12-01 06:00:00', new \DateTimeZone('UTC'))))]);
        $this->setFlag(true);

        $result = $this->runCli(['--json', '--dry-run', '--date=2026-11-15']);
        $this->assertSame(0, $result['code'], 'JSON dry-run must exit 0. Output: ' . $result['output']);

        $payload = $this->extractJson($result['output']);
        $encoded = (string) json_encode($payload);
        $this->assertStringNotContainsString('@', $encoded);
        $this->assertFalse((bool) preg_match('/\+254\d{6,9}/', $encoded), 'CLI JSON must not contain phone numbers.');
        $this->assertArrayHasKey('counts', $payload);
    }

    public function testAutomationIsCliOnly(): void
    {
        $this->ensureLoggedInAs('+254700000020');
        $response = $this->post('/platform/billing/automation', ['_token' => $this->csrfToken()], false);
        $this->assertTrue(
            in_array($response->status(), [403, 404, 405], true),
            'There must be no web trigger for billing generation (got HTTP ' . $response->status() . ').'
        );
    }
}
