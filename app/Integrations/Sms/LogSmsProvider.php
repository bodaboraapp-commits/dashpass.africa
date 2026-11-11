<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Phone;
use RuntimeException;

/**
 * Development SMS adapter.
 *
 * Writes JSON lines to `storage/logs/sms.log` instead of contacting a real
 * gateway. The message body is redacted before it hits disk so a development
 * OTP is never persisted in plaintext (the development OTP is delivered to the
 * UI through the API only when `SMS_DEV_EXPOSE_OTP` is enabled).
 *
 * This adapter is refused in production by {@see SmsManager}.
 */
final class LogSmsProvider implements SmsProvider
{
    public function send(string $phone, string $message): array
    {
        $messageId = 'log_' . bin2hex(random_bytes(8));

        $record = [
            'time' => Clock::nowDb(),
            'driver' => 'log',
            'message_id' => $messageId,
            'to' => Phone::mask($phone),
            'message' => self::redact($message),
            'status' => 'sent',
        ];

        $path = $this->logPath();
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The SMS log directory is not writable.');
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to write to the SMS log adapter.');
        }

        return [
            'message_id' => $messageId,
            'status' => 'sent',
            'driver' => 'log',
        ];
    }

    public function getDeliveryStatus(string $messageId): array
    {
        return [
            'message_id' => $messageId,
            'status' => 'sent',
            'driver' => 'log',
        ];
    }

    private function logPath(): string
    {
        return BASE_PATH . '/' . ltrim((string) Config::get('sms.log_path', 'storage/logs/sms.log'), '/');
    }

    /**
     * Remove code-shaped digit runs from the adapter output.
     */
    private static function redact(string $message): string
    {
        return preg_replace('/(?<!\d)\d{4,8}(?!\d)/', '[otp-redacted]', $message) ?? $message;
    }
}
