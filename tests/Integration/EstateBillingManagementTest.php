<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Response;
use App\Core\Uuid;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Estate-manager billing cockpit (owner-approved utilities workflow, 2026-11-13).
 *
 * Frozen behaviour (app/Modules/Billing/EstateUtilityBillingService +
 * EstateBillingManagementController):
 *  - estate_admin / estate_manager only (route permission + role gate);
 *  - readings → one bill per active household, idempotent per period;
 *  - a repeated run or a double submit never double-bills;
 *  - readings are validated before any bill is created (required, numeric,
 *    non-negative, no rollover below the last reading);
 *  - sending marks bills sent and logs in-app deliveries;
 *  - manual payments settle the bill; reminders are deduplicated;
 *  - everything is estate-scoped (another estate's bill answers 404).
 */
final class EstateBillingManagementTest extends AdminModuleTestCase
{
    private const ISSUE = '/admin/utility-bills/issue';
    private const SEND = '/admin/utility-bills/send';
    private const COLLECTIONS = '/admin/billing/collections';

    public function setUp(): void
    {
        parent::setUp();

        // Deterministic: only the schedules this test creates run.
        $this->db->execute(
            "UPDATE billing_schedules SET status = 'paused' WHERE estate_id = :estate_id AND scope = 'utility_bill'",
            ['estate_id' => $this->estateId()]
        );

        // Demo utility invoices are already delivered: keep send/collections assertions deterministic.
        $this->db->execute(
            "UPDATE billing_invoices SET sent_at = COALESCE(sent_at, NOW(3)) WHERE estate_id = :estate_id AND kind = 'utility'",
            ['estate_id' => $this->estateId()]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Access control                                                     */
    /* ------------------------------------------------------------------ */

    public function testOnlyEstateManagersCanOpenTheBillingCockpit(): void
    {
        $this->assertStatus(200, $this->adminGet('/admin/utility-bills'), 'Estate admin must open the utilities cockpit.');
        $this->assertStatus(200, $this->adminGet('/admin/utility-bills', self::MANAGER), 'Estate manager must open the utilities cockpit.');

        $this->assertStatus(403, $this->adminGet('/admin/utility-bills', self::SUPERVISOR), 'Security supervisor must not open billing.');
        $this->assertStatus(403, $this->adminGet(self::COLLECTIONS, self::SUPERVISOR), 'Security supervisor must not open collections.');
        $this->assertStatus(403, $this->adminGet('/admin/utility-bills', self::JANE), 'Residents must not open the billing cockpit.');
    }

    /* ------------------------------------------------------------------ */
    /* Readings cockpit                                                   */
    /* ------------------------------------------------------------------ */

    public function testCockpitRendersTheReadingsGridForAMeteredUtility(): void
    {
        $schedule = $this->makeMeteredSchedule('Water reading test');
        $households = $this->activeHouseholds();
        $first = $households[0];

        $response = $this->adminGet('/admin/utility-bills?period=' . $this->month());
        $this->assertStatus(200, $response);
        $this->assertBodyContains('Utilities', $response);
        $this->assertBodyContains('Water reading test', $response);
        $this->assertBodyContains('Metered', $response);
        $this->assertBodyContains('Last reading', $response);
        $this->assertBodyContains('readings[' . $schedule['uuid'] . '][' . $first['uuid'] . ']', $response);
    }

    /* ------------------------------------------------------------------ */
    /* Issue                                                              */
    /* ------------------------------------------------------------------ */

    public function testIssueCreatesOneBillPerHouseholdFromTheReadings(): void
    {
        $schedule = $this->makeMeteredSchedule('Water issue test');
        $households = $this->activeHouseholds();
        $readings = $this->readingsFor($schedule, $households, 150.0);

        $response = $this->adminPost(self::ISSUE, ['period' => $this->month(), 'readings' => $readings]);
        $this->assertAdminRedirect($response, '/admin/utility-bills');

        $invoices = $this->utilityInvoicesFor($schedule);
        $this->assertCount(count($households), $invoices, 'One bill per active household.');

        $charges = $this->chargesFor($schedule);
        $this->assertCount(count($households), $charges, 'One utility charge row per bill.');

        foreach ($invoices as $invoice) {
            $this->assertSame('utility', (string) $invoice['kind']);
            $this->assertSame('manual', (string) $invoice['source']);
            $this->assertSame('open', (string) $invoice['status']);
            $this->assertSame(150 * 4000, (int) $invoice['amount_cents'], 'Metered amount = units × tariff.');
            $this->assertTrue(str_starts_with((string) $invoice['generation_key'], 'util:'), 'Generation key must be the util: idempotency key.');
        }

        foreach ($charges as $charge) {
            $this->assertSame('metered', (string) $charge['charge_model']);
            $this->assertSame('manual', (string) $charge['reading_source']);
            $this->assertNull($charge['reading_start_units'], 'First bill has no previous reading.');
            $this->assertSame(150.0, (float) $charge['reading_end_units']);
            $this->assertSame(150.0, (float) $charge['units']);
        }

        $audit = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'billing.utility_run_issued' AND estate_id = :estate_id",
            ['estate_id' => $this->estateId()]
        );
        $this->assertSame(1, $audit, 'The run must be audited exactly once.');
    }

    public function testIssuingTwiceForTheSamePeriodNeverDoubleBills(): void
    {
        $schedule = $this->makeMeteredSchedule('Water idempotency test');
        $households = $this->activeHouseholds();
        $readings = $this->readingsFor($schedule, $households, 120.0);

        $this->assertAdminRedirect($this->adminPost(self::ISSUE, ['period' => $this->month(), 'readings' => $readings]));
        $before = count($this->utilityInvoicesFor($schedule));

        $second = $this->adminPost(self::ISSUE, ['period' => $this->month(), 'readings' => $readings]);
        $this->assertAdminRedirect($second, '/admin/utility-bills');

        $this->assertSame($before, count($this->utilityInvoicesFor($schedule)), 'A repeated run must not double-bill.');
        $this->assertBodyContains('No new bills were issued', $this->get((string) $second->header('Location')));
    }

    public function testIssueValidatesReadingsBeforeCreatingAnyBill(): void
    {
        $schedule = $this->makeMeteredSchedule('Water validation test');
        $households = $this->activeHouseholds();
        $readings = $this->readingsFor($schedule, $households, 100.0);
        unset($readings[$schedule['uuid']][$households[0]['uuid']]);

        $rejected = $this->adminPost(self::ISSUE, ['period' => $this->month(), 'readings' => $readings]);
        $this->assertAdminRedirect($rejected, '/admin/utility-bills');
        $this->assertBodyContains('meter reading is required', $this->get((string) $rejected->header('Location')));
        $this->assertSame([], $this->utilityInvoicesFor($schedule), 'A validation failure creates no bills.');

        // Issue the current month, then a lower reading next month is rejected as a rollover.
        $this->assertAdminRedirect($this->adminPost(self::ISSUE, ['period' => $this->month(), 'readings' => $this->readingsFor($schedule, $households, 100.0)]));
        $nextMonth = $this->month('+1 month');
        $rollover = $this->readingsFor($schedule, $households, 100.0);
        $rollover[$schedule['uuid']][$households[0]['uuid']] = '90';

        $response = $this->adminPost(self::ISSUE, ['period' => $nextMonth, 'readings' => $rollover]);
        $this->assertAdminRedirect($response, '/admin/utility-bills');
        $this->assertBodyContains('lower than the last reading', $this->get((string) $response->header('Location')));
        $this->assertCount(count($households), $this->utilityInvoicesFor($schedule), 'The rollover month issues nothing.');
    }

    /* ------------------------------------------------------------------ */
    /* Send                                                               */
    /* ------------------------------------------------------------------ */

    public function testSendMarksInvoicesSentAndLogsInAppDeliveries(): void
    {
        $schedule = $this->makeMeteredSchedule('Water send test');
        $households = $this->activeHouseholds();
        $this->assertAdminRedirect($this->adminPost(self::ISSUE, ['period' => $this->month(), 'readings' => $this->readingsFor($schedule, $households, 140.0)]));

        $response = $this->adminPost(self::SEND, ['period' => $this->month()]);
        $this->assertAdminRedirect($response, '/admin/utility-bills');
        $this->assertBodyContains('notification', $this->get((string) $response->header('Location')));

        $sent = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM billing_invoices WHERE estate_id = :estate_id AND kind = 'utility' AND sent_at IS NOT NULL",
            ['estate_id' => $this->estateId()]
        );
        $this->assertTrue($sent > 0, 'At least the household with members must be marked sent.');

        $logs = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM notification_logs WHERE estate_id = :estate_id AND channel = 'in_app' AND template = 'billing_generated'",
            ['estate_id' => $this->estateId()]
        );
        $this->assertTrue($logs > 0, 'In-app delivery rows must be written for residents.');

        $audit = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'billing.utility_bills_sent' AND estate_id = :estate_id",
            ['estate_id' => $this->estateId()]
        );
        $this->assertSame(1, $audit, 'Sending must be audited.');
    }

    public function testSendWithNothingIssuedIsSafe(): void
    {
        $this->makeMeteredSchedule('Water empty send test');

        $response = $this->adminPost(self::SEND, ['period' => $this->month()]);
        $this->assertAdminRedirect($response, '/admin/utility-bills');
        $this->assertBodyContains('Nothing to send', $this->get((string) $response->header('Location')));
    }

    /* ------------------------------------------------------------------ */
    /* Collections                                                        */
    /* ------------------------------------------------------------------ */

    public function testCollectionsShowsOpenBillsAndRecordsAManualPayment(): void
    {
        $schedule = $this->makeMeteredSchedule('Water collections test');
        $households = $this->activeHouseholds();
        $readings = $this->readingsFor($schedule, $households, 130.0);
        $this->assertAdminRedirect($this->adminPost(self::ISSUE, ['period' => $this->month(), 'readings' => $readings]));

        $invoice = $this->utilityInvoicesFor($schedule)[0];

        $page = $this->adminGet(self::COLLECTIONS);
        $this->assertStatus(200, $page);
        $this->assertBodyContains('Collections', $page);
        $this->assertBodyContains((string) $invoice['invoice_number'], $page);
        $this->assertBodyContains('Collection rate', $page);

        $payment = $this->adminPost('/admin/billing/bills/' . (string) $invoice['uuid'] . '/payment', [
            'amount' => '5200.00',
            'method' => 'mpesa',
            'reference' => 'TESTREF123',
        ]);
        $this->assertAdminRedirect($payment, self::COLLECTIONS);

        $paid = $this->db->selectOne('SELECT status FROM billing_invoices WHERE id = :id', ['id' => (int) $invoice['id']]);
        $this->assertSame('paid', (string) $paid['status'], 'A full payment settles the bill.');

        $recorded = $this->db->selectOne(
            "SELECT method, status, reference, amount_cents FROM billing_payments WHERE invoice_id = :invoice_id ORDER BY id DESC LIMIT 1",
            ['invoice_id' => (int) $invoice['id']]
        );
        $this->assertSame('mpesa', (string) $recorded['method']);
        $this->assertSame('confirmed', (string) $recorded['status']);
        $this->assertSame('TESTREF123', (string) $recorded['reference']);
        $this->assertSame(520000, (int) $recorded['amount_cents']);

        $after = $this->adminGet(self::COLLECTIONS);
        $this->assertStringNotContainsString(
            '<a class="admin-table__link" href="/admin/billing/invoices/' . (string) $invoice['uuid'] . '">',
            $after->body(),
            'A settled bill must drop out of the open collections list.'
        );
    }

    public function testNudgeSendsAReminderOnceAndIsDeduplicated(): void
    {
        $schedule = $this->makeMeteredSchedule('Water nudge test');
        $households = $this->activeHouseholds();
        $this->assertAdminRedirect($this->adminPost(self::ISSUE, ['period' => $this->month(), 'readings' => $this->readingsFor($schedule, $households, 160.0)]));

        $invoice = $this->utilityInvoicesFor($schedule)[0];
        $response = $this->adminPost('/admin/billing/bills/' . (string) $invoice['uuid'] . '/remind', []);
        $this->assertAdminRedirect($response, self::COLLECTIONS);

        $reminders = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM notification_logs WHERE estate_id = :estate_id AND channel = 'in_app' AND template = 'billing_reminder'",
            ['estate_id' => $this->estateId()]
        );
        $this->assertSame(1, $reminders, 'One reminder per bill/household.');

        $second = $this->adminPost('/admin/billing/bills/' . (string) $invoice['uuid'] . '/remind', []);
        $this->assertAdminRedirect($second, self::COLLECTIONS);
        $this->assertBodyContains('already sent', $this->get((string) $second->header('Location')));

        $this->assertSame(1, (int) $this->db->scalar(
            "SELECT COUNT(*) FROM notification_logs WHERE estate_id = :estate_id AND channel = 'in_app' AND template = 'billing_reminder'",
            ['estate_id' => $this->estateId()]
        ), 'A repeated nudge must not duplicate the reminder.');
    }

    public function testPaymentValidationAndEstateIsolation(): void
    {
        $schedule = $this->makeMeteredSchedule('Water isolation test');
        $households = $this->activeHouseholds();
        $this->assertAdminRedirect($this->adminPost(self::ISSUE, ['period' => $this->month(), 'readings' => $this->readingsFor($schedule, $households, 110.0)]));
        $invoice = $this->utilityInvoicesFor($schedule)[0];
        $path = '/admin/billing/bills/' . (string) $invoice['uuid'] . '/payment';

        $invalid = $this->adminPost($path, ['amount' => '0', 'method' => 'mpesa']);
        $this->assertAdminRedirect($invalid, self::COLLECTIONS);
        $this->assertBodyContains('Enter the amount received', $this->get((string) $invalid->header('Location')));
        $this->assertSame(0, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM billing_payments WHERE invoice_id = :invoice_id',
            ['invoice_id' => (int) $invoice['id']]
        ), 'Invalid amount records no payment.');

        // Riverside's admin can never act on a Greenview bill.
        $this->reloginAs(self::DAVID_RIVERSIDE);
        $foreign = $this->post($path, ['amount' => '100.00', 'method' => 'cash'], true);
        $this->assertStatus(404, $foreign, 'Another estate cannot record a payment on this bill.');
        $this->assertSame(0, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM billing_payments WHERE invoice_id = :invoice_id',
            ['invoice_id' => (int) $invoice['id']]
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                           */
    /* ------------------------------------------------------------------ */

    private function estateId(): int
    {
        return (int) $this->estate()['id'];
    }

    private function month(string $modify = ''): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return ($modify === '' ? $now : $now->modify($modify))->format('Y-m');
    }

    /** @return array<string, mixed> */
    private function makeMeteredSchedule(string $name): array
    {
        $uuid = Uuid::v4();
        $this->db->insert('billing_schedules', [
            'uuid' => $uuid,
            'scope' => 'utility_bill',
            'estate_id' => $this->estateId(),
            'household_id' => null,
            'name' => $name,
            'utility_type' => 'water',
            'flat_amount_cents' => null,
            'tariff_cents_per_unit' => 4000,
            'unit_label' => 'm³',
            'currency' => 'KES',
            'period_unit' => 'month',
            'generate_lead_days' => 0,
            'due_days' => 14,
            'reminder_before_days' => 3,
            'reminder_on_due' => 1,
            'proration_policy' => 'none',
            'send_on_generate' => 0,
            'status' => 'active',
            'next_run_at' => Clock::toDb(Clock::now()->modify('+1 month')),
            'created_at' => Clock::nowDb(),
            'updated_at' => Clock::nowDb(),
        ]);

        $row = $this->db->selectOne('SELECT * FROM billing_schedules WHERE uuid = :uuid', ['uuid' => $uuid]);
        $this->assertNotNull($row);

        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    private function activeHouseholds(): array
    {
        $rows = $this->db->select(
            "SELECT id, uuid, unit_number FROM households WHERE estate_id = :estate_id AND status = 'active' ORDER BY unit_number",
            ['estate_id' => $this->estateId()]
        );

        $this->assertNotSame([], $rows, 'Seeded households are required.');

        return $rows;
    }

    /**
     * @param array<string, mixed>            $schedule
     * @param array<int, array<string, mixed>> $households
     * @return array<string, array<string, string>>
     */
    private function readingsFor(array $schedule, array $households, float $reading): array
    {
        $readings = [];

        foreach ($households as $household) {
            $readings[(string) $schedule['uuid']][(string) $household['uuid']] = (string) $reading;
        }

        return $readings;
    }

    /** @return array<int, array<string, mixed>> */
    private function utilityInvoicesFor(array $schedule): array
    {
        return $this->db->select(
            "SELECT i.* FROM billing_invoices i
             INNER JOIN utility_charges c ON c.invoice_id = i.id
             WHERE i.estate_id = :estate_id AND c.billing_schedule_id = :schedule_id
             ORDER BY i.id",
            ['estate_id' => $this->estateId(), 'schedule_id' => (int) $schedule['id']]
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function chargesFor(array $schedule): array
    {
        return $this->db->select(
            'SELECT * FROM utility_charges WHERE estate_id = :estate_id AND billing_schedule_id = :schedule_id ORDER BY id',
            ['estate_id' => $this->estateId(), 'schedule_id' => (int) $schedule['id']]
        );
    }
}
