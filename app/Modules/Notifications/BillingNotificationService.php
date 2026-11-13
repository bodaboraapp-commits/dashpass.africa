<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Uuid;
use App\Integrations\WhatsApp\WhatsAppCloudProvider;
use PDOException;
use Throwable;

/**
 * Inc-13 P2 billing/utility notification delivery: in-app always, email via
 * EmailAlertService when enabled, SMS/WhatsApp only when configured. WhatsApp is
 * delivered through the estate's own Meta WhatsApp Cloud API credentials when the
 * Estate Admin has configured them (owner request 2026-11-13); global env
 * credentials still enable it too. Per-channel failures are isolated and recorded
 * with attempts/backoff; dedupe prevents duplicate sends. No message bodies are
 * written to application logs.
 */
final class BillingNotificationService
{
    /** @var callable|null */
    private $emailSender;

    /** @var callable|null */
    private $whatsappTransport;

    public function __construct(
        private readonly Context $context,
        ?callable $emailSender = null,
        ?callable $whatsappTransport = null
    ) {
        $this->emailSender = $emailSender;
        $this->whatsappTransport = $whatsappTransport;
    }

    public static function make(Context $context, ?callable $emailSender = null, ?callable $whatsappTransport = null): self
    {
        return new self($context, $emailSender, $whatsappTransport);
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array{sent:int,skipped:int,failed:int,channels:array<string,string>}
     */
    public function deliverInvoiceEvent(array $invoice, string $event): array
    {
        $estateId = (int) ($invoice['estate_id'] ?? 0);
        $invoiceUuid = (string) ($invoice['uuid'] ?? '');
        $kind = (string) ($invoice['kind'] ?? 'subscription');
        $channels = ChannelSettingsService::make($this->context)->estateChannels($estateId);
        $recipients = $this->recipients($invoice);
        $subject = $event === 'reminder' ? 'Invoice reminder' : 'Invoice ' . $event;
        $body = 'Invoice ' . (string) ($invoice['invoice_number'] ?? '') . ' · '
            . strtoupper((string) ($invoice['currency'] ?? 'KES')) . ' '
            . number_format(((int) ($invoice['amount_cents'] ?? 0)) / 100, 2, '.', ',')
            . ' · due ' . (string) ($invoice['due_at'] ?? '-');
        $link = $kind === 'utility' ? '/app/bills' : '/platform/billing';
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'channels' => []];

        foreach ($recipients as $recipient) {
            $userId = (int) $recipient['user_id'];
            $dedupeBase = 'billing:' . $event . ':' . $invoiceUuid . ':' . $userId;

            // In-app is always attempted.
            try {
                $rowId = (new NotificationLogger($this->context->db))->logInApp(
                    $estateId,
                    'u:' . $userId,
                    'billing_' . $event,
                    mb_substr($subject, 0, 160),
                    mb_substr($body, 0, 500),
                    $link,
                    $dedupeBase . ':in_app'
                );
                $result[$rowId === null ? 'skipped' : 'sent']++;
                $result['channels']['in_app'] = $rowId === null ? 'deduped' : 'sent';
            } catch (Throwable) {
                $result['failed']++;
                $result['channels']['in_app'] = 'failed';
            }

            foreach (['email', 'sms', 'whatsapp'] as $channel) {
                if (!($channels[$channel] ?? false)) {
                    $this->recordChannel($invoice, $recipient, $channel, 'skipped', $dedupeBase . ':' . $channel, ChannelSettingsService::make($this->context)->skipReason($channel));

                    continue;
                }

                if ($channel === 'email') {
                    $outcome = $this->sendEmail($invoice, $recipient, $subject, $body, $dedupeBase . ':email', $estateId);
                } elseif ($channel === 'whatsapp') {
                    $outcome = $this->sendWhatsApp(
                        $invoice,
                        $recipient,
                        $body,
                        $dedupeBase . ':whatsapp',
                        $estateId
                    );
                } else {
                    $fake = $channel === 'sms'
                        ? getenv('SMS_FAKE_SEND') === '1'
                        : getenv('WHATSAPP_FAKE_SEND') === '1';

                    if ($fake) {
                        $this->recordChannel($invoice, $recipient, $channel, 'sent', $dedupeBase . ':' . $channel, null);
                        $outcome = 'sent';
                    } else {
                        $this->recordChannel($invoice, $recipient, $channel, 'skipped', $dedupeBase . ':' . $channel, $channel . '_adapter_unavailable');
                        $outcome = 'skipped';
                    }
                }

                $result[$outcome === 'sent' ? 'sent' : ($outcome === 'failed' ? 'failed' : 'skipped')]++;
                $result['channels'][$channel] = $outcome;
            }
        }

        try {
            if ($result['sent'] > 0 && $event === 'generated') {
                $this->context->db->update('billing_invoices', ['sent_at' => Clock::nowDb()], ['id' => (int) ($invoice['id'] ?? 0)]);
            }
        } catch (Throwable) {
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<int, array{user_id:int,email:?string,phone:?string}>
     */
    private function recipients(array $invoice): array
    {
        $estateId = (int) ($invoice['estate_id'] ?? 0);
        $rows = [];

        if ((string) ($invoice['kind'] ?? '') === 'utility' && (int) ($invoice['household_id'] ?? 0) > 0) {
            $rows = $this->context->db->select(
                "SELECT DISTINCT u.id AS user_id, u.email, u.phone
                 FROM household_members hm
                 INNER JOIN users u ON u.id = hm.user_id AND u.status = 'active'
                 WHERE hm.estate_id = :estate_id AND hm.household_id = :household_id AND hm.status = 'active'
                 ORDER BY hm.is_primary DESC, u.id LIMIT 3",
                ['estate_id' => $estateId, 'household_id' => (int) $invoice['household_id']]
            );
        } else {
            $rows = $this->context->db->select(
                "SELECT DISTINCT u.id AS user_id, u.email, u.phone
                 FROM estate_memberships em
                 INNER JOIN users u ON u.id = em.user_id AND u.status = 'active'
                 INNER JOIN roles r ON r.id = em.role_id
                 WHERE em.estate_id = :estate_id AND em.status = 'active'
                   AND r.name IN ('estate_admin','estate_manager')
                 ORDER BY u.id LIMIT 10",
                ['estate_id' => $estateId]
            );
        }

        return array_map(static fn (array $row): array => [
            'user_id' => (int) $row['user_id'],
            'email' => $row['email'] === null ? null : (string) $row['email'],
            'phone' => $row['phone'] === null ? null : (string) $row['phone'],
        ], $rows);
    }

    private function sendEmail(array $invoice, array $recipient, string $subject, string $body, string $dedupe, int $estateId): string
    {
        $email = (string) ($recipient['email'] ?? '');

        if ($email === '') {
            $this->recordChannel($invoice, $recipient, 'email', 'skipped', $dedupe, 'email_missing');

            return 'skipped';
        }

        $existing = $this->existing($dedupe);

        if ($existing !== null && $existing['status'] === 'sent') {
            return 'deduped';
        }

        try {
            if ($this->emailSender !== null) {
                ($this->emailSender)($email, $subject, $body);
            } else {
                EmailAlertService::make($this->context)->sendBillingAlert($estateId, [$email], $subject, $body);
            }

            $this->upsertChannel($dedupe, $invoice, $recipient, 'email', 'sent', null, 0, null);

            return 'sent';
        } catch (Throwable) {
            $attempts = (int) ($existing['attempts'] ?? 0) + 1;
            $status = $attempts >= 3 ? 'failed' : 'pending';
            $next = Clock::now()->modify('+' . min(30, $attempts * 5) . ' minutes');
            $this->upsertChannel($dedupe, $invoice, $recipient, 'email', $status, 'provider_error', $attempts, Clock::toDb($next));

            return $status === 'failed' ? 'failed' : 'pending';
        }
    }

    private function existing(string $dedupe): ?array
    {
        return $this->context->db->selectOne('SELECT * FROM notification_logs WHERE dedupe_key = :k LIMIT 1', ['k' => $dedupe]);
    }

    /**
     * Deliver a billing notification over WhatsApp.
     *
     * Uses the estate's own Meta WhatsApp Cloud API credentials when the Estate
     * Admin configured them (owner request 2026-11-13); otherwise keeps the legacy
     * fake/skip behaviour so environments without credentials keep working.
     */
    private function sendWhatsApp(array $invoice, array $recipient, string $body, string $dedupe, int $estateId): string
    {
        $settings = WhatsAppSettingsService::make($this->context);
        $credentials = $settings->credentials($estateId);

        if ($credentials === null || !$settings->isConfigured($estateId)) {
            if (getenv('WHATSAPP_FAKE_SEND') === '1') {
                $this->recordChannel($invoice, $recipient, 'whatsapp', 'sent', $dedupe, null);

                return 'sent';
            }

            $this->recordChannel($invoice, $recipient, 'whatsapp', 'skipped', $dedupe, 'whatsapp_adapter_unavailable');

            return 'skipped';
        }

        $phone = trim((string) ($recipient['phone'] ?? ''));

        if ($phone === '') {
            $this->recordChannel($invoice, $recipient, 'whatsapp', 'failed', $dedupe, 'whatsapp_phone_missing');

            return 'failed';
        }

        try {
            $provider = new WhatsAppCloudProvider($this->whatsappTransport);
            $provider->sendTemplate($credentials, $phone, [$body]);
            $this->recordChannel($invoice, $recipient, 'whatsapp', 'sent', $dedupe, null);

            return 'sent';
        } catch (\App\Integrations\WhatsApp\WhatsAppSendException $exception) {
            $this->recordChannel($invoice, $recipient, 'whatsapp', 'failed', $dedupe, $exception->getMessage());

            return 'failed';
        } catch (Throwable) {
            $this->recordChannel($invoice, $recipient, 'whatsapp', 'failed', $dedupe, 'whatsapp_send_failed');

            return 'failed';
        }
    }

    private function recordChannel(array $invoice, array $recipient, string $channel, string $status, string $dedupe, ?string $errorCode): void
    {
        try {
            $this->upsertChannel($dedupe, $invoice, $recipient, $channel, $status, $errorCode, 0, null);
        } catch (Throwable) {
        }
    }

    private function upsertChannel(
        string $dedupe,
        array $invoice,
        array $recipient,
        string $channel,
        string $status,
        ?string $errorCode,
        int $attempts,
        ?string $nextAttemptAt
    ): void {
        $existing = $this->existing($dedupe);
        $now = Clock::nowDb();
        // notification_logs.status is ENUM('queued','sent','failed','delivered');
        // "skipped" is not a column value, so a skipped channel is stored as
        // "failed" with the precise reason in error_code (same convention as
        // NotificationLogger for unknown statuses).
        $normalisedStatus = $status === 'pending'
            ? 'queued'
            : ($status === 'deduped' ? 'sent' : ($status === 'skipped' ? 'failed' : $status));

        if (!in_array($normalisedStatus, ['queued', 'sent', 'failed', 'delivered'], true)) {
            $normalisedStatus = 'failed';
        }

        $payload = [
            'status' => $normalisedStatus,
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAt,
            'error_code' => $errorCode === null ? null : mb_substr($errorCode, 0, 60),
            'sent_at' => $status === 'sent' ? $now : null,
        ];

        if ($existing !== null) {
            $this->context->db->update('notification_logs', $payload, ['id' => (int) $existing['id']]);

            return;
        }

        try {
            $this->context->db->insert('notification_logs', [
                'uuid' => Uuid::v4(),
                'estate_id' => (int) ($invoice['estate_id'] ?? 0),
                'channel' => $channel,
                'recipient' => $channel === 'email' ? mb_substr((string) ($recipient['email'] ?? ''), 0, 190) : mb_substr((string) ($recipient['phone'] ?? 'u:' . $recipient['user_id']), 0, 190),
                'template' => 'billing_notification',
                'provider' => $channel === 'email' ? 'mail' : $channel,
                'provider_message_id' => null,
                'status' => $payload['status'],
                'error' => null,
                'sent_at' => $payload['sent_at'],
                'created_at' => $now,
                'dedupe_key' => $dedupe,
                'attempts' => $attempts,
                'next_attempt_at' => $nextAttemptAt,
                'error_code' => $payload['error_code'],
                'title' => 'Billing notification',
                'body' => 'Billing notification',
                'link' => '/notifications',
                'read_at' => null,
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }
    }
}
