<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Uuid;
use Database\Seeds\DemoBillingScheduleSeeder;

/**
 * Inc-11 estate billing read-only surface (routes landed 2026-11-12):
 * overview, invoice detail, reports (filters from/to/status/kind) and CSV export
 * with formula-injection safety, private cache headers, tenant scoping and the
 * PII-free `report.exported` audit.
 */
final class EstateBillingReadTest extends AdminModuleTestCase
{
    /** @return array<string, mixed> */
    private function createInvoice(int $estateId, int $householdId, string $number, string $kind = 'utility'): array
    {
        $uuid = Uuid::v4();
        $now = Clock::nowDb();
        $this->db->insert('billing_invoices', [
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'household_id' => $householdId,
            'invoice_number' => $number,
            'kind' => $kind,
            'status' => 'open',
            'amount_cents' => 123400,
            'currency' => 'KES',
            'issued_at' => $now,
            'due_at' => Clock::toDb(Clock::now()->modify('+14 days')),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = $this->db->selectOne('SELECT * FROM billing_invoices WHERE uuid = :u', ['u' => $uuid]);
        $this->assertNotNull($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function seededInvoice(string $number): array
    {
        $row = $this->db->selectOne('SELECT * FROM billing_invoices WHERE invoice_number = :n', ['n' => $number]);
        $this->assertNotNull($row, 'The seeded invoice ' . $number . ' is required.');

        return $row;
    }

    private function riversideEstateId(): int
    {
        $row = $this->db->selectOne("SELECT id FROM estates WHERE slug = 'riverside-court' LIMIT 1");
        $this->assertNotNull($row);

        return (int) $row['id'];
    }

    private function riversideHouseholdId(): int
    {
        $row = $this->db->selectOne("SELECT id FROM households WHERE estate_id = :e LIMIT 1", ['e' => $this->riversideEstateId()]);
        $this->assertNotNull($row);

        return (int) $row['id'];
    }

    public function testOverviewIsReadOnlyAndRoleGated(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $page = $this->get('/admin/billing');
        $this->assertStatus(200, $page);
        $this->assertTextContains('Greenview', $page);
        $this->assertTextContains('INV-', $page);
        $this->assertCleanMarkup($page, 'Estate billing overview');

        // Read-only: no mutation forms/buttons on the overview.
        $this->assertCount(0, $this->xpath('//form[contains(@action,"/admin/billing")]', $this->dom($page)));

        foreach (['+254700000010' => 'resident', '+254700000004' => 'guard'] as $phone => $label) {
            $this->ensureLoggedInAs($phone);
            $this->assertStatus(403, $this->get('/admin/billing'), $label . ' must not reach estate billing.');
        }

        $this->resetSession();
        $unauth = $this->get('/admin/billing');
        $this->assertStatus(302, $unauth);
        $this->assertStringContainsString('/login', (string) $unauth->header('Location'));
    }

    public function testInvoiceDetailIsEstateScoped(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $invoice = $this->seededInvoice($this->currentInvoiceNumber());

        $detail = $this->get('/admin/billing/invoices/' . (string) $invoice['uuid']);
        $this->assertStatus(200, $detail);
        $this->assertTextContains((string) $invoice['invoice_number'], $detail);

        // Cross-tenant invoice uuid → 404, never cross-estate data.
        $foreign = $this->createInvoice($this->riversideEstateId(), $this->riversideHouseholdId(), 'INV-QA-RIVERSIDE-0001');
        $this->assertStatus(404, $this->get('/admin/billing/invoices/' . (string) $foreign['uuid']));
        $this->assertStatus(404, $this->get('/admin/billing/invoices/' . Uuid::v4()));
    }

    private function currentInvoiceNumber(): string
    {
        $number = (string) $this->db->scalar(
            "SELECT invoice_number FROM billing_invoices WHERE kind = 'subscription' AND status = 'open' ORDER BY id ASC LIMIT 1"
        );
        if ($number === '') {
            $number = (string) $this->db->scalar('SELECT invoice_number FROM billing_invoices ORDER BY id ASC LIMIT 1');
        }

        return $number;
    }

    public function testReportsFiltersAndFromToValidation(): void
    {
        $this->ensureLoggedInAs(self::GRACE);

        foreach (['summary', 'outstanding', 'payments', 'utility', 'unknown-report'] as $report) {
            $response = $this->get('/admin/billing/reports?report=' . $report);
            $this->assertStatus(200, $response, 'Report ' . $report . ' must render.');
            $this->assertCleanMarkup($response, 'Billing report ' . $report);
        }

        $filtered = $this->get('/admin/billing/reports?report=payments&status=confirmed&kind=subscription&from=2026-01-01&to=2030-01-01');
        $this->assertStatus(200, $filtered);

        $invalid = $this->get('/admin/billing/reports?report=payments&from=not-a-date');
        $this->assertTrue(
            in_array($invalid->status(), [302, 422], true),
            'An invalid from date must be rejected (422/PRG), got ' . $invalid->status() . '.'
        );

        $reversed = $this->get('/admin/billing/reports?report=payments&from=2030-01-01&to=2026-01-01');
        $this->assertTrue(
            in_array($reversed->status(), [200, 302, 422], true),
            'A reversed date range must be handled without a server error, got ' . $reversed->status() . '.'
        );
    }

    public function testCsvExportHeadersAuditAndTenantScoping(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $response = $this->get('/admin/billing/export.csv?report=summary');

        $this->assertStatus(200, $response);
        $this->assertStringContainsString('text/csv', (string) $response->header('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->header('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        $this->assertSame('nosniff', (string) $response->header('X-Content-Type-Options'));
        $this->assertStringContainsString('INV-', $response->body());

        $audit = $this->db->selectOne("SELECT metadata, estate_id FROM audit_logs WHERE action = 'report.exported' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($audit, 'Every CSV export is audited.');
        $meta = (string) $audit['metadata'];
        $this->assertStringContainsString('summary', $meta);
        $this->assertStringContainsString('row_count', $meta);
        $this->assertStringNotContainsString('INV-', $meta, 'Export audit metadata must be PII-free.');

        // Cross-tenant scoping: Riverside's export never contains Greenview invoices.
        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);
        $foreign = $this->get('/admin/billing/export.csv?report=summary');
        $this->assertStatus(200, $foreign);
        $this->assertStringNotContainsString('INV-202610-0001', $foreign->body());
    }

    public function testCsvNeutralisesFormulaInjection(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $payment = $this->db->selectOne('SELECT * FROM billing_payments ORDER BY id ASC LIMIT 1');
        $this->assertNotNull($payment, 'The seeded payment fixture is required.');
        $this->db->execute("UPDATE billing_payments SET reference = :r WHERE id = :id", ['r' => '=2+2', 'id' => (int) $payment['id']]);

        $response = $this->get('/admin/billing/export.csv?report=payments');
        $this->assertStatus(200, $response);
        $body = $response->body();

        $this->assertStringContainsString("'=2+2", $body, 'Formula-leading fields are neutralised with a leading apostrophe.');
        $this->assertStringNotContainsString(',"=2+2"', $body, 'The raw formula must never start a CSV field.');

        // Restore the fixture reference.
        $this->db->execute("UPDATE billing_payments SET reference = :r WHERE id = :id", ['r' => 'MPESA-QA', 'id' => (int) $payment['id']]);
    }
}
