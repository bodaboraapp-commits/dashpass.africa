<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

/**
 * In-memory SMS adapter for the automated test suite.
 *
 * No message ever leaves the process; tests can inspect what "would" have been
 * sent and reset the buffer between cases.
 */
final class MockSmsProvider implements SmsProvider
{
    /** @var array<int, array<string, mixed>> */
    private static array $messages = [];

    public function send(string $phone, string $message): array
    {
        $messageId = 'mock_' . substr(
            hash('sha256', $phone . '|' . $message . '|' . count(self::$messages) . '|' . microtime(true)),
            0,
            24
        );

        self::$messages[] = [
            'message_id' => $messageId,
            'phone' => $phone,
            'message' => $message,
            'status' => 'sent',
            'driver' => 'mock',
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        return [
            'message_id' => $messageId,
            'status' => 'sent',
            'driver' => 'mock',
        ];
    }

    public function getDeliveryStatus(string $messageId): array
    {
        foreach (self::$messages as $message) {
            if ((string) $message['message_id'] === $messageId) {
                return [
                    'message_id' => $messageId,
                    'status' => (string) $message['status'],
                    'driver' => 'mock',
                ];
            }
        }

        return [
            'message_id' => $messageId,
            'status' => 'unknown',
            'driver' => 'mock',
        ];
    }

    /**
     * Clear the in-memory buffer (test isolation).
     */
    public static function reset(): void
    {
        self::$messages = [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function messages(): array
    {
        return self::$messages;
    }
}
