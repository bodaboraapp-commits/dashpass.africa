<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

use RuntimeException;

/**
 * Raised when the WhatsApp Cloud API rejects a message or the credentials are
 * incomplete. The message never contains the access token or message body.
 */
final class WhatsAppSendException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $providerStatus = null
    ) {
        parent::__construct($message);
    }

    public function providerStatus(): ?int
    {
        return $this->providerStatus;
    }
}
