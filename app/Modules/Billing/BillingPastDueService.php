<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\AuditLogger;
use App\Core\Clock;
use App\Core\Database;
use DateTimeImmutable;

/**
 * Past-due invoice sweep (Increment 10 §3; testable in-process, no HTTP).
 *
 * Sets `open` invoices with `due_at < now` to `past_due`, audits
 * `billing.invoice_status_changed` per invoice, and writes one aggregate
 * `billing.past_due_swept` audit when rows changed. `$dryRun` never writes.
 * Idempotent: past_due rows are not scanned again. The CLI
 * (`scripts/billing-past-due.php`) is a thin wrapper over run().
 */
final class BillingPastDueService
{
    public function __construct(
        private readonly Database $db,
        private readonly ?DateTimeImmutable $now = null
    ) {
    }

    public static function make(?DateTimeImmutable $now = null): self
    {
        return new self(Database::instance(), $now);
    }

    /**
     * @return array{dry_run: bool, scanned: int, eligible: int, flagged: int, estates: int, processed_at: string}
     */
    public function run(bool $dryRun = false, ?int $estateId = null): array
    {
        $now = $this->now ?? Clock::now();
        $nowDb = Clock::toDb($now);
        $invoices = (new BillingRepository($this->db))->invoicesDueBefore($nowDb, $estateId);
        $result = [
            'dry_run' => $dryRun,
            'scanned' => count($invoices),
            'eligible' => count($invoices),
            'flagged' => 0,
            'estates' => 0,
            'processed_at' => $nowDb,
        ];

        if ($dryRun || $invoices === []) {
            $estates = [];

            foreach ($invoices as $invoice) {
                $estates[(int) $invoice['estate_id']] = true;
            }

            $result['estates'] = count($estates);

            return $result;
        }

        $audit = new AuditLogger($this->db);
        $estates = [];

        foreach ($invoices as $invoice) {
            $estateId = (int) $invoice['estate_id'];
            $estates[$estateId] = true;
            $updated = $this->db->execute(
                "UPDATE billing_invoices SET status = 'past_due', updated_at = :updated
                 WHERE id = :id AND status = 'open'",
                ['updated' => $nowDb, 'id' => (int) $invoice['id']]
            );

            if ($updated < 1) {
                continue;
            }

            $result['flagged']++;
            $audit->record(
                'billing.invoice_status_changed',
                'billing_invoice',
                (int) $invoice['id'],
                [
                    'invoice_uuid' => (string) $invoice['uuid'],
                    'invoice_number' => (string) $invoice['invoice_number'],
                    'estate_uuid' => (string) ($invoice['estate_uuid'] ?? ''),
                    'old_status' => 'open',
                    'new_status' => 'past_due',
                    'amount_cents' => (int) $invoice['amount_cents'],
                    'currency' => (string) $invoice['currency'],
                ],
                null
            );
        }

        $result['estates'] = count($estates);

        if ($result['flagged'] > 0) {
            $audit->record(
                'billing.past_due_swept',
                'billing_invoice',
                null,
                ['flagged' => $result['flagged'], 'estates' => $result['estates']],
                null
            );
        }

        return $result;
    }
}
