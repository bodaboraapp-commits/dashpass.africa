<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Uuid;
use PDOException;
use Throwable;

/**
 * Persists outbound notification delivery attempts.
 *
 * The log is written after the business transaction commits and after the
 * provider call, and deliberately contains no message body, so verification
 * codes never reach the database through this path (Blueprint §31, §45).
 */
final class NotificationLogger
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function make(?Database $db = null): self
    {
        return new self($db ?? Database::instance());
    }

    /**
     * Record one SMS delivery attempt.
     *
     * A logging failure must never break the surrounding business operation, so
     * errors are downgraded to application warnings.
     */
    public function logSms(
        ?int $estateId,
        string $recipient,
        ?string $template,
        ?string $provider,
        ?string $providerMessageId,
        string $status,
        ?string $error = null
    ): void {
        $status = in_array($status, ['queued', 'sent', 'failed', 'delivered'], true) ? $status : 'failed';

        try {
            $this->db->insert('notification_logs', [
                'uuid' => Uuid::v4(),
                'estate_id' => $estateId,
                'channel' => 'sms',
                'recipient' => mb_substr($recipient, 0, 190),
                'template' => $template === null ? null : mb_substr($template, 0, 80),
                'provider' => $provider === null ? null : mb_substr($provider, 0, 40),
                'provider_message_id' => $providerMessageId === null ? null : mb_substr($providerMessageId, 0, 120),
                'status' => $status,
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'sent_at' => in_array($status, ['sent', 'delivered'], true) ? Clock::nowDb() : null,
                'created_at' => Clock::nowDb(),
            ]);
        } catch (Throwable $exception) {
            Logger::warning('Notification log write failed', [
                'channel' => 'sms',
                'template' => $template,
                'status' => $status,
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ]);
        }
    }

    /**
     * Record one per-user in-app notification (Inc-13 P2a).
     *
     * Returns the row id, or null when the unique dedupe key already exists
     * (idempotent fanout/retry). Any other failure is re-thrown so a publishing
     * transaction can roll back rather than leave a half-created bell state.
     * The notification body is bounded by the caller and never written to app
     * logs; only non-content metadata is logged on failure.
     */
    public function logInApp(
        ?int $estateId,
        string $recipient,
        string $template,
        string $title,
        string $body,
        string $link,
        string $dedupeKey
    ): ?int {
        try {
            return $this->db->insert('notification_logs', [
                'uuid' => Uuid::v4(),
                'estate_id' => $estateId,
                'channel' => 'in_app',
                'recipient' => mb_substr($recipient, 0, 190),
                'template' => mb_substr($template, 0, 80),
                'provider' => null,
                'provider_message_id' => null,
                'status' => 'sent',
                'error' => null,
                'sent_at' => Clock::nowDb(),
                'created_at' => Clock::nowDb(),
                'dedupe_key' => mb_substr($dedupeKey, 0, 191),
                'attempts' => 0,
                'next_attempt_at' => null,
                'error_code' => null,
                'title' => mb_substr($title, 0, 160),
                'body' => mb_substr($body, 0, 500),
                'link' => mb_substr($link, 0, 255),
                'read_at' => null,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateKey($exception)) {
                return null;
            }

            Logger::warning('In-app notification write failed', [
                'template' => $template,
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ]);

            throw $exception;
        }
    }

    private function isDuplicateKey(PDOException $exception): bool
    {
        return (string) $exception->getCode() === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
