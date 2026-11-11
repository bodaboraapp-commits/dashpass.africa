<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;

/**
 * Inc-13 utility billing slice (`scripts/billing-automate.php --scope=utility`).
 *
 * Frozen behaviour (app/Modules/Billing/Automation/UtilityBillingService):
 *  - dry-run performs zero DB writes;
 *  - apply generates utility invoices + one utility_charges row per schedule /
 *    household / UTC period (flat basis from the seeded A-001 water schedule);
 *  - re-running the same date is idempotent (duplicate skip, 0 new rows);
 *  - `--estate=<uuid>` scopes candidates to that estate;
 *  - `AUTOMATED_BILLING_ENABLED=false` is a safe no-op (exit 0, disabled).
 */
final class BillingUtilityTest extends AdminModuleTestCase
{
    private const SCRIPT = '/scripts/billing-automate.php';

    private string|false $originalFlag = false;

    /** @var array<string, mixed> */
    private array $originalAutomation = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalFlag = getenv('AUTOMATED_BILLING_ENABLED');
        putenv('AUTOMATED_BILLING_ENABLED=true');

        $this->originalAutomation = (array) Config::get('billing.automation', []);
        Config::set('billing.automation', array_merge($this->originalAutomation, ['enabled' => true]));

        // Make the seeded A-001 water schedule due for the next period so the CLI has
        // a deterministic candidate without touching earlier demo fixtures.
        $schedule = $this->db->selectOne(
            "SELECT * FROM billing_schedules WHERE scope = 'utility_bill' ORDER BY id ASC LIMIT 1"
        );
        $this->assertNotNull($schedule, 'The seeded utility schedule is required.');

        $this->db->execute(
            'UPDATE billing_schedules SET next_run_at = :next, last_period_key = NULL, status = :status WHERE id = :id',
            [
                'next' => (new \DateTimeImmutable('first day of next month 00:00:00', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
                'status' => 'active',
                'id' => (int) $schedule['id'],
            ]
        );
    }

    public function tearDown(): void
    {
        Config::set('billing.automation', $this->originalAutomation);

        if ($this->originalFlag === false) {
            putenv('AUTOMATED_BILLING_ENABLED');
        } else {
            putenv('AUTOMATED_BILLING_ENABLED=' . $this->originalFlag);
        }

        parent::tearDown();
    }

    /** @return array{code:int, output:string, json:array<string,mixed>|null} */
    private function runCli(array $arguments): array
    {
        $script = BASE_PATH . self::SCRIPT;
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $output = [];
        $code = 0;
        @exec($command . ' 2>&1', $output, $code);
        $text = implode("\n", $output);
        $json = null;

        // The exec() capture merges stderr (PHP startup warnings) into the output;
        // decode the last JSON-object line emitted by the CLI.
        foreach (array_reverse($output) as $line) {
            $line = trim((string) $line);
            if ($line === '' || !str_starts_with($line, '{')) {
                continue;
            }
            $candidate = json_decode($line, true);
            if (is_array($candidate)) {
                $json = $candidate;
                break;
            }
        }

        return ['code' => (int) $code, 'output' => $text, 'json' => $json];
    }

    private function runDate(): string
    {
        return (new \DateTimeImmutable('first day of next month 06:00:00', new \DateTimeZone('UTC')))->format('Y-m-d');
    }

    private function utilityCounts(): array
    {
        return [
            'invoices' => (int) $this->db->scalar("SELECT COUNT(*) FROM billing_invoices WHERE kind = 'utility'"),
            'charges' => (int) $this->db->scalar('SELECT COUNT(*) FROM utility_charges'),
        ];
    }

    public function testDryRunPerformsZeroWrites(): void
    {
        $before = $this->utilityCounts();

        $result = $this->runCli(['--scope=utility', '--dry-run', '--date=' . $this->runDate(), '--json']);

        $this->assertSame(0, $result['code'], 'A utility dry-run exits 0. Output: ' . $result['output']);
        $this->assertNotNull($result['json'], 'The JSON CLI output must be parseable.');
        $this->assertSame(true, $result['json']['dry_run'] ?? null);
        $this->assertSame('utility', $result['json']['scope'] ?? null);
        $this->assertTrue((int) ($result['json']['counts']['scanned'] ?? 0) >= 1, 'The seeded utility schedule is a candidate.');
        $this->assertTrue((int) ($result['json']['counts']['generated'] ?? 0) >= 1, 'A dry-run reports the would-be generation.');

        $this->assertSame($before, $this->utilityCounts(), 'A dry-run must not write invoices or charges.');
    }

    public function testApplyGeneratesUtilityInvoiceAndChargeThenIsIdempotent(): void
    {
        $before = $this->utilityCounts();

        $result = $this->runCli(['--scope=utility', '--date=' . $this->runDate(), '--json']);
        $this->assertSame(0, $result['code'], 'A utility apply exits 0. Output: ' . $result['output']);
        $this->assertSame('utility', $result['json']['scope'] ?? null);
        $generated = (int) ($result['json']['counts']['generated'] ?? 0);
        $this->assertSame(1, $generated, 'Exactly one A-001 utility charge is generated for the next period.');

        $after = $this->utilityCounts();
        $this->assertSame($before['invoices'] + 1, $after['invoices'], 'One utility invoice is generated.');
        $this->assertSame($before['charges'] + 1, $after['charges'], 'One utility charge row is generated.');

        $charge = $this->db->selectOne(
            "SELECT c.amount_cents, c.currency, c.charge_model, c.period_key, c.invoice_id, h.unit_number
             FROM utility_charges c INNER JOIN households h ON h.id = c.household_id
             ORDER BY c.id DESC LIMIT 1"
        );
        $this->assertNotNull($charge);
        $this->assertSame(120000, (int) $charge['amount_cents'], 'The seeded flat water tariff (KES 1,200) is charged.');
        $this->assertSame('KES', (string) $charge['currency']);
        $this->assertSame('flat', (string) $charge['charge_model']);
        $this->assertSame('A-001', (string) $charge['unit_number']);
        $this->assertMatches('/^\d{6}$/', (string) $charge['period_key'], 'Charges carry a UTC YYYYMM period key.');

        // The generated invoice is an open automation invoice linked to the charge.
        $invoice = $this->db->selectOne(
            "SELECT status, amount_cents, source FROM billing_invoices WHERE kind = 'utility' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($invoice);
        $this->assertSame(120000, (int) $invoice['amount_cents']);
        $this->assertSame('automation', (string) $invoice['source'], 'Automation-generated invoices are marked as such.');

        // Idempotent re-run: duplicate skipped, no new rows.
        $second = $this->runCli(['--scope=utility', '--date=' . $this->runDate(), '--json']);
        $this->assertSame(0, $second['code']);
        $this->assertSame(0, (int) ($second['json']['counts']['generated'] ?? 0), 'A re-run generates nothing.');
        $this->assertSame(1, (int) ($second['json']['skips']['duplicate'] ?? 0), 'The existing period is reported as a duplicate.');
        $this->assertSame($after, $this->utilityCounts(), 'A re-run must not duplicate invoices/charges.');
    }

    public function testEstateScopeRestrictsGeneration(): void
    {
        $riverside = $this->seededEstate('riverside-court');
        $before = $this->utilityCounts();

        $result = $this->runCli(['--scope=utility', '--date=' . $this->runDate(), '--estate=' . $riverside['uuid'], '--json']);
        $this->assertSame(0, $result['code']);
        $this->assertSame(0, (int) ($result['json']['counts']['scanned'] ?? 0), 'Riverside has no utility schedules.');
        $this->assertSame(0, (int) ($result['json']['counts']['generated'] ?? 0));
        $this->assertSame($before, $this->utilityCounts(), 'A scoped run for Riverside writes nothing.');
    }

    public function testDisabledFlagIsASafeNoOp(): void
    {
        putenv('AUTOMATED_BILLING_ENABLED=false');
        Config::set('billing.automation', array_merge($this->originalAutomation, ['enabled' => false]));

        $before = $this->utilityCounts();
        $result = $this->runCli(['--scope=utility', '--date=' . $this->runDate(), '--json']);

        $this->assertSame(0, $result['code'], 'Disabled automation exits 0.');
        $this->assertSame('disabled', $result['json']['status'] ?? null);
        $this->assertSame($before, $this->utilityCounts(), 'Disabled automation writes nothing.');
    }

    public function testInvalidScopeAndDateAreRejectedWithExit2(): void
    {
        $bad = $this->runCli(['--scope=not-a-scope', '--json']);
        $this->assertSame(2, $bad['code']);
        $this->assertSame('invalid', $bad['json']['status'] ?? null);

        $badDate = $this->runCli(['--scope=utility', '--date=not-a-date', '--json']);
        $this->assertSame(2, $badDate['code']);
        $this->assertSame('invalid', $badDate['json']['status'] ?? null);
    }
}
