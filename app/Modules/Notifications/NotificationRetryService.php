<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Clock;
use App\Core\Context;
use Throwable;

/**
 * Inc-13 P2 retry worker for queued notification rows.
 *
 * Scans only due rows with attempts < 3; success/failure updates attempts,
 * next_attempt_at and terminal status. Audits are metadata-only (no recipient).
 */
final class NotificationRetryService
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly Context $context)
    {
    }

    public static function make(Context $context): self
    {
        return new self($context);
    }

    /**
     * @return array{dry_run:bool,status:string,counts:array<string,int>}
     */
    public function run(bool $dryRun = false, int $limit = 100): array
    {
        $rows = $this->context->db->select(
            "SELECT * FROM notification_logs
             WHERE channel IN ('email','sms','whatsapp')
               AND status IN ('queued','pending')
               AND attempts < :max_attempts
               AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
             ORDER BY next_attempt_at ASC, id ASC LIMIT " . max(1, min(500, $limit)),
            ['max_attempts' => self::MAX_ATTEMPTS, 'now' => Clock::nowDb()]
        );
        $counts = ['scanned' => count($rows), 'sent' => 0, 'retried' => 0, 'exhausted' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            if ($dryRun) {
                $counts['retried']++;

                continue;
            }

            $attempts = (int) $row['attempts'] + 1;
            $channel = (string) $row['channel'];
            $ok = false;

            try {
                $ok = $this->send($row);
            } catch (Throwable) {
                $ok = false;
            }

            $now = Clock::nowDb();

            if ($ok) {
                $counts['sent']++;
                $this->update((int) $row['id'], [
                    'status' => 'sent',
                    'attempts' => $attempts,
                    'next_attempt_at' => null,
                    'sent_at' => $now,
                    'error_code' => null,
                ]);
                $this->audit('notification.retry_sent', $row, $attempts);
            } elseif ($attempts >= self::MAX_ATTEMPTS) {
                $counts['exhausted']++;
                $this->update((int) $row['id'], [
                    'status' => 'failed',
                    'attempts' => $attempts,
                    'next_attempt_at' => null,
                    'error_code' => 'retry_exhausted',
                ]);
                $this->audit('notification.retry_exhausted', $row, $attempts);
            } else {
                $counts['retried']++;
                $next = Clock::now()->modify('+' . ($attempts * 5) . ' minutes');
                $this->update((int) $row['id'], [
                    'status' => 'queued',
                    'attempts' => $attempts,
                    'next_attempt_at' => Clock::toDb($next),
                    'error_code' => 'provider_error',
                ]);
                $this->audit('notification.retry_failed', $row, $attempts);
            }
        }

        return ['dry_run' => $dryRun, 'status' => 'success', 'counts' => $counts];
    }

    private function send(array $row): bool
    {
        $channel = (string) $row['channel'];
        $recipient = (string) $row['recipient'];

        if ($channel === 'email') {
            EmailAlertService::make($this->context)->sendBillingAlert(
                (int) $row['estate_id'],
                [$recipient],
                (string) ($row['title'] ?? 'Billing notification'),
                (string) ($row['body'] ?? 'Billing notification')
            );

            return true;
        }

        if ($channel === 'sms' && getenv('SMS_FAKE_SEND') === '1') {
            return true;
        }

        if ($channel === 'whatsapp' && getenv('WHATSAPP_FAKE_SEND') === '1') {
            return true;
        }

        return false;
    }

    private function update(int $id, array $fields): void
    {
        $this->context->db->update('notification_logs', $fields, ['id' => $id]);
    }

    private function audit(string $action, array $row, int $attempts): void
    {
        try {
            $this->context->audit->record($action, 'notification', null, [
                'notification_uuid' => (string) $row['uuid'],
                'channel' => (string) $row['channel'],
                'template' => mb_substr((string) $row['template'], 0, 80),
                'attempts' => $attempts,
            ], (int) $row['estate_id']);
        } catch (Throwable) {
        }
    }
}
