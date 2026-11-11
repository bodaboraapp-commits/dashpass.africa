<?php

declare(strict_types=1);

namespace App\Integrations\Mail;

use App\Core\Clock;
use App\Core\Config;
use RuntimeException;

/**
 * Development/test email adapter.
 *
 * Writes a redacted JSON line to `storage/logs/mail.log` instead of contacting a
 * real provider. Recipients are masked, only the subject is recorded (never the
 * message body, API key or any credential), which keeps alert emails (visitor
 * names, household labels) out of long-lived log files while still proving the
 * delivery path. Works with no API key configured.
 */
final class LogMailProvider implements MailProvider
{
    public function send(MailMessage $message): array
    {
        $messageId = 'log_' . bin2hex(random_bytes(8));

        $record = [
            'time' => Clock::nowDb(),
            'driver' => 'log',
            'message_id' => $messageId,
            'to' => $message->maskedRecipients(),
            'subject' => self::redactSubject($message->subject),
            'metadata_keys' => array_keys($message->metadata),
            'status' => 'sent',
        ];

        $path = $this->logPath();
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The mail log directory is not writable.');
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to write to the mail log adapter.');
        }

        return [
            'message_id' => $messageId,
            'provider' => 'log',
            'status' => 'sent',
        ];
    }

    public function name(): string
    {
        return 'log';
    }

    private function logPath(): string
    {
        return BASE_PATH . '/' . ltrim((string) Config::get('mail.log_path', 'storage/logs/mail.log'), '/');
    }

    /**
     * Remove credential-shaped digit runs and address-like tokens from the
     * subject before it is written to disk.
     */
    private static function redactSubject(string $subject): string
    {
        $subject = preg_replace('/(?<!\d)\d{4,8}(?!\d)/', '[redacted]', $subject) ?? $subject;
        $subject = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email-redacted]', $subject) ?? $subject;

        return $subject;
    }
}
