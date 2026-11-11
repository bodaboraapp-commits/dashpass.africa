<?php

declare(strict_types=1);

namespace App\Integrations\Mail;

/**
 * Provider-independent transactional email transport.
 *
 * Application code depends only on this interface so the development log adapter
 * can be swapped for a real provider (Resend today, another later) without
 * touching alert or visitor-flow logic.
 */
interface MailProvider
{
    /**
     * Send one email message.
     *
     * @return array{message_id: string, provider: string, status: string}
     */
    public function send(MailMessage $message): array;

    /**
     * The provider adapter name (`log`, `resend`).
     */
    public function name(): string;
}
