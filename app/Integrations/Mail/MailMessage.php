<?php

declare(strict_types=1);

namespace App\Integrations\Mail;

use InvalidArgumentException;

/**
 * Transport-independent transactional email value object.
 *
 * Validation happens at construction so a malformed message can never reach a
 * provider adapter. Recipients are deduplicated; `maskedRecipients()` exists for
 * audit/log output and never exposes a raw address.
 */
final class MailMessage
{
    /** @var array<int, string> */
    public readonly array $to;

    public readonly string $subject;

    public readonly string $text;

    public readonly ?string $html;

    public readonly ?string $replyTo;

    /** @var array<string, mixed> */
    public readonly array $metadata;

    /**
     * @param array<int, string> $to
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        array $to,
        string $subject,
        string $text,
        ?string $html = null,
        ?string $replyTo = null,
        array $metadata = []
    ) {
        $recipients = [];

        foreach ($to as $address) {
            if (!is_string($address) || !filter_var(trim($address), FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('A recipient email address is invalid.');
            }

            $recipients[] = trim($address);
        }

        if ($recipients === []) {
            throw new InvalidArgumentException('At least one recipient email address is required.');
        }

        if (trim($subject) === '') {
            throw new InvalidArgumentException('An email subject is required.');
        }

        if (trim($text) === '') {
            throw new InvalidArgumentException('An email text body is required.');
        }

        if ($replyTo !== null && !filter_var(trim($replyTo), FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('The reply-to email address is invalid.');
        }

        $unique = [];

        foreach ($recipients as $recipient) {
            $key = strtolower($recipient);

            if (!array_key_exists($key, $unique)) {
                $unique[$key] = $recipient;
            }
        }

        $this->to = array_values($unique);
        $this->subject = trim($subject);
        $this->text = $text;
        $this->html = $html;
        $this->replyTo = $replyTo === null ? null : trim($replyTo);
        $this->metadata = $metadata;
    }

    /**
     * Mask an address for logs/audit: `jane@example.com` -> `j***@example.com`.
     */
    public static function maskAddress(string $address): string
    {
        $parts = explode('@', trim($address), 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return '[masked]';
        }

        $local = function_exists('mb_substr') ? mb_substr($parts[0], 0, 1) : substr($parts[0], 0, 1);

        return $local . '***@' . $parts[1];
    }

    /**
     * @return array<int, string>
     */
    public function maskedRecipients(): array
    {
        return array_map([self::class, 'maskAddress'], $this->to);
    }
}
