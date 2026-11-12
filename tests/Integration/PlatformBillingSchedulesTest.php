<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use Database\Seeds\DemoBillingScheduleSeeder;

/**
 * Inc-13 P2/P3 platform billing schedules backend (routes landed 2026-11-12):
 * index/filter, create form + validation, show/update, toggle, run-now dry-run
 * preview with audit, and platform-area permission gates.
 */
final class PlatformBillingSchedulesTest extends AdminModuleTestCase
{
    private const PLATFORM_SUPER = '+254700000020';

    /** @return array<string, mixed> */
    private function seededSchedule(string $uuid): array
    {
        $row = $this->db->selectOne('SELECT * FROM billing_schedules WHERE uuid = :u', ['u' => $uuid]);
        $this->assertNotNull($row, 'The seeded billing schedule fixture is required.');

        return $row;
    }

    private function scheduleCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM billing_schedules');
    }

    public function testIndexListsAndFiltersSeededSchedules(): void
    {
        $this->ensureLoggedInAs(self::PLATFORM_SUPER);

        $page = $this->get('/platform/billing/schedules');
        $this->assertStatus(200, $page);
        $this->assertTextContains('Greenview', $page);
        $this->assertTextContains('Water bill - A-001', $page);
        $this->assertTextContains('Growth', $page);

        $utility = $this->get('/platform/billing/schedules?scope=utility_bill');
        $this->assertStatus(200, $utility);
        $this->assertTextContains('Water bill - A-001', $utility);
        $this->assertTextNotContains('Growth', $utility);

        $this->assertSame(200, $this->get('/platform/billing/schedules?status=paused')->status());
        $this->assertSame(200, $this->get('/platform/billing/schedules?status=active')->status());
    }

    public function testCreateFormAndValidation(): void
    {
        $this->ensureLoggedInAs(self::PLATFORM_SUPER);
        $greenview = $this->seededEstate(self::ESTATE_SLUG);

        $form = $this->get('/platform/billing/schedules/new');
        $this->assertStatus(200, $form);
        $this->assertTextContains('schedule', $form);

        $before = $this->scheduleCount();
        $this->assertAdminRedirect($this->post('/platform/billing/schedules', [
            'scope' => 'not_a_scope',
            'estate_uuid' => (string) $greenview['uuid'],
            'period_unit' => 'month',
            'status' => 'active',
            'name' => 'QA invalid schedule',
        ], true), '/platform/billing/schedules');
        $this->assertSame($before, $this->scheduleCount(), 'Validation errors must not insert a schedule.');

        $name = 'QA billing schedule ' . bin2hex(random_bytes(3));
        $this->assertAdminRedirect($this->post('/platform/billing/schedules', [
            'scope' => 'utility_bill',
            'estate_uuid' => (string) $greenview['uuid'],
            'household_id' => (int) $this->seededHousehold('A-001')['id'],
            'period_unit' => 'month',
            'status' => 'active',
            'name' => $name,
            'generate_lead_days' => 0,
            'due_days' => 14,
            'reminder_before_days' => 3,
            'next_run_at' => Clock::toDb(Clock::now()->modify('+1 day')),
        ], true));
        $this->assertSame($before + 1, $this->scheduleCount(), 'A valid schedule is persisted.');

        $created = $this->db->selectOne('SELECT * FROM billing_schedules WHERE name = :n', ['n' => $name]);
        $this->assertNotNull($created);
        $this->assertSame('utility_bill', (string) $created['scope']);
        $this->assertSame('month', (string) $created['period_unit']);
        $this->assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'billing.schedule_created'"));
    }

    public function testShowUpdateToggleAndAudit(): void
    {
        $this->ensureLoggedInAs(self::PLATFORM_SUPER);
        $uuid = DemoBillingScheduleSeeder::SUBSCRIPTION_SCHEDULE_UUID;

        $show = $this->get('/platform/billing/schedules/' . $uuid);
        $this->assertStatus(200, $show);
        $this->assertTextContains($uuid, $show);

        $this->assertAdminRedirect($this->post('/platform/billing/schedules/' . $uuid . '/edit', [
            'name' => 'QA renamed growth schedule',
            'due_days' => 20,
        ], true), '/platform/billing/schedules/' . $uuid);
        $row = $this->seededSchedule($uuid);
        $this->assertSame('QA renamed growth schedule', (string) $row['name']);
        $this->assertSame(20, (int) $row['due_days']);

        $this->assertAdminRedirect($this->post('/platform/billing/schedules/' . $uuid . '/toggle', [], true), '/platform/billing/schedules');
        $this->assertSame('paused', (string) $this->seededSchedule($uuid)['status']);
        $this->assertAdminRedirect($this->post('/platform/billing/schedules/' . $uuid . '/toggle', [], true), '/platform/billing/schedules');
        $this->assertSame('active', (string) $this->seededSchedule($uuid)['status']);

        $actions = array_column($this->db->select(
            "SELECT action FROM audit_logs WHERE action LIKE 'billing.schedule_%' ORDER BY id ASC"
        ), 'action');
        $this->assertContains('billing.schedule_updated', $actions);
        $this->assertContains('billing.schedule_toggled', $actions);
    }

    public function testRunNowIsADryRunPreviewAndAudits(): void
    {
        $this->ensureLoggedInAs(self::PLATFORM_SUPER);
        $uuid = DemoBillingScheduleSeeder::UTILITY_SCHEDULE_UUID;
        $invoicesBefore = (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices');
        $rowBefore = $this->seededSchedule($uuid);

        $this->assertAdminRedirect($this->post('/platform/billing/schedules/' . $uuid . '/run-now', [], true), '/platform/billing/schedules/' . $uuid);

        $this->assertSame($invoicesBefore, (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices'), 'Run-now is a dry-run: no invoices are generated.');
        $rowAfter = $this->seededSchedule($uuid);
        $this->assertSame((string) $rowBefore['next_run_at'], (string) $rowAfter['next_run_at'], 'Dry-run must not move the schedule.');

        $audit = $this->db->selectOne("SELECT metadata FROM audit_logs WHERE action = 'billing.schedule_run_preview' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit);
        $this->assertStringContainsString((string) $uuid, (string) $audit['metadata']);
    }

    public function testScheduleScreensArePlatformAreaOnly(): void
    {
        // Estate admin, guard and resident have no platform-area access.
        foreach ([self::GRACE, '+254700000004', '+254700000010'] as $phone) {
            $this->ensureLoggedInAs($phone);
            $this->assertStatus(403, $this->get('/platform/billing/schedules'), $phone . ' must be rejected from the platform schedules screen.');
            $this->assertStatus(403, $this->get('/platform/billing/schedules/new'), $phone . ' must be rejected from schedule creation.');
        }

        $this->resetSession();
        $unauth = $this->get('/platform/billing/schedules');
        $this->assertStatus(302, $unauth);
        $this->assertStringContainsString('/login', (string) $unauth->header('Location'));
    }

    public function testRunNowJsonReturnsTheStructuredDryRunPayload(): void
    {
        $this->ensureLoggedInAs(self::PLATFORM_SUPER);
        $uuid = DemoBillingScheduleSeeder::SUBSCRIPTION_SCHEDULE_UUID;
        $before = [
            'invoices' => (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices'),
            'charges' => (int) $this->db->scalar('SELECT COUNT(*) FROM utility_charges'),
        ];
        $scheduleBefore = $this->seededSchedule($uuid);

        $response = $this->post('/platform/billing/schedules/' . $uuid . '/run-now', [], true, ['Accept' => 'application/json']);
        $this->assertStatus(200, $response);
        $payload = $this->json($response);
        $data = $payload['data'] ?? $payload;
        $this->assertTrue(is_array($data));
        $this->assertSame(true, $data['dry_run'] ?? null);
        foreach (['scanned', 'due', 'generated', 'skipped'] as $key) {
            $this->assertTrue(array_key_exists($key, $data), 'Run-now payload must expose ' . $key . '.');
            $this->assertTrue(is_int($data[$key]), $key . ' must be an integer.');
        }
        $this->assertTrue(isset($data['invoices']) && is_array($data['invoices']), 'Run-now payload lists the would-be invoices.');
        $this->assertSame(0, (int) $data['generated'], 'Dry-run generates nothing.');
        $this->assertSame((string) $scheduleBefore['scope'], (string) ($data['scope'] ?? ''));

        $this->assertSame($before['invoices'], (int) $this->db->scalar('SELECT COUNT(*) FROM billing_invoices'), 'Run-now writes no invoices.');
        $this->assertSame($before['charges'], (int) $this->db->scalar('SELECT COUNT(*) FROM utility_charges'), 'Run-now writes no utility charges.');
        $this->assertSame((string) $scheduleBefore['next_run_at'], (string) $this->seededSchedule($uuid)['next_run_at'], 'Run-now does not advance the schedule.');
    }
}
