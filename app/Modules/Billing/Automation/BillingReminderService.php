<?php

declare(strict_types=1);

namespace App\Modules\Billing\Automation;

use App\Core\Clock;
use App\Core\Context;
use App\Modules\Billing\BillingPastDueService;
use App\Modules\Notifications\BillingNotificationService;

/**
 * Inc-13 P2 reminders: 3 days before due, on due, and one past-due escalation;
 * idempotent via notification_logs dedupe and invoice reminder markers.
 */
final class BillingReminderService
{
    public function __construct(private readonly Context $context)
    {
    }

    public static function make(Context $context): self
    {
        return new self($context);
    }

    /**
     * @return array{dry_run:bool,status:string,counts:array<string,int>,skips:array<string,int>}
     */
    public function run(\DateTimeImmutable $asOf, ?string $estateUuid = null, bool $dryRun = false, bool $noSend = false): array
    {
        $counts = ['scanned' => 0, 'reminders' => 0, 'skipped' => 0, 'failed' => 0];
        $skips = [];
        $estateIds = $this->estateIds($estateUuid);

        if (!$dryRun && !$noSend) {
            foreach ($estateIds as $estateId) {
                try {
                    BillingPastDueService::make($asOf)->run(false, $estateId);
                } catch (\Throwable) {
                }
            }
        }

        $sql = "SELECT i.* FROM billing_invoices i
                WHERE i.status IN ('open','past_due') AND i.due_at IS NOT NULL";
        $params = [];

        if ($estateUuid !== null) {
            $sql .= ' AND i.estate_id = (SELECT id FROM estates WHERE uuid = :estate_uuid LIMIT 1)';
            $params['estate_uuid'] = $estateUuid;
        }

        $sql .= ' ORDER BY i.due_at ASC, i.id ASC';
        $invoices = $this->context->db->select($sql, $params);
        $today = $asOf->format('Y-m-d');
        $before = $asOf->modify('+3 days')->format('Y-m-d');

        foreach ($invoices as $invoice) {
            $counts['scanned']++;
            $dueDate = substr((string) $invoice['due_at'], 0, 10);

            if ($dueDate === $before) {
                $stage = 'before_due';
            } elseif ($dueDate === $today) {
                $stage = 'on_due';
            } elseif ((string) $invoice['status'] === 'past_due' && $invoice['reminder_sent_at'] === null) {
                $stage = 'past_due';
            } else {
                $counts['skipped']++;
                $skips['not_due'] = ($skips['not_due'] ?? 0) + 1;

                continue;
            }

            $existing = $this->context->db->selectOne(
                "SELECT id FROM notification_logs WHERE dedupe_key LIKE :p LIMIT 1",
                ['p' => 'billing:reminder_' . $stage . ':' . (string) $invoice['uuid'] . ':%']
            );

            if ($existing !== null || $invoice['last_reminder_stage'] === $stage) {
                $counts['skipped']++;
                $skips['deduped'] = ($skips['deduped'] ?? 0) + 1;

                continue;
            }

            if ($dryRun) {
                $counts['reminders']++;

                continue;
            }

            if ($noSend) {
                $counts['skipped']++;
                $skips['no_send'] = ($skips['no_send'] ?? 0) + 1;

                continue;
            }

            try {
                $result = BillingNotificationService::make($this->context)->deliverInvoiceEvent($invoice, 'reminder_' . $stage);
                $counts['reminders']++;
                $this->context->db->update('billing_invoices', [
                    'reminder_sent_at' => Clock::nowDb(),
                    'last_reminder_stage' => $stage,
                    'updated_at' => Clock::nowDb(),
                ], ['id' => (int) $invoice['id']]);
                $this->context->audit->record('billing.reminder_sent', 'billing_invoice', (int) $invoice['id'], [
                    'invoice_uuid' => (string) $invoice['uuid'],
                    'stage' => $stage,
                    'channels' => $result['channels'] ?? [],
                ], (int) $invoice['estate_id']);
            } catch (\Throwable $exception) {
                $counts['failed']++;
            }
        }

        return [
            'dry_run' => $dryRun,
            'status' => $counts['failed'] > 0 ? 'partial' : 'success',
            'counts' => $counts,
            'skips' => $skips,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function estateIds(?string $estateUuid): array
    {
        if ($estateUuid !== null) {
            $id = (int) ($this->context->db->scalar('SELECT id FROM estates WHERE uuid = :uuid', ['uuid' => $estateUuid]) ?? 0);

            return $id > 0 ? [$id] : [];
        }

        return array_map(static fn (array $row): int => (int) $row['id'], $this->context->db->select('SELECT id FROM estates'));
    }
}
