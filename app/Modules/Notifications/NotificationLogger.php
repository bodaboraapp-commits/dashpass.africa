<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Uuid;
use Throwable;

/**
 * Persists outbound notification delivery attempts.
 *
 * The log is written after the business transaction commits and after the
 * provider call, and deliberately contains no message body — so verification
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
}
