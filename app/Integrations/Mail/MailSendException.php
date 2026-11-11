<?php

declare(strict_types=1);

namespace App\Integrations\Mail;

use RuntimeException;

/**
 * A provider rejected or could not deliver an email.
 *
 * The message is deliberately generic and safe: it never contains the API key,
 * request body, recipients or provider response body. Callers (EmailAlertService)
 * catch it and audit `email.alert_failed` without failing the business flow.
 */
final class MailSendException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly string $provider = 'unknown'
    ) {
        parent::__construct($message);
    }

    public static function rejected(string $provider, int $httpStatus): self
    {
        return new self(
            sprintf('MAIL_SEND_FAILED: the email provider rejected the message (HTTP %d).', $httpStatus),
            $httpStatus,
            $provider
        );
    }

    public static function unreachable(string $provider): self
    {
        return new self('MAIL_SEND_FAILED: the email provider could not be reached.', null, $provider);
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function provider(): string
    {
        return $this->provider;
    }
}
