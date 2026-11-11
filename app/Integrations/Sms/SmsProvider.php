<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

/**
 * Provider-independent SMS transport (Blueprint §28).
 *
 * Application code depends only on this interface so that a real Kenyan
 * aggregator adapter (Africa's Talking or similar) can be added later without
 * touching OTP or notification logic.
 */
interface SmsProvider
{
    /**
     * Send one SMS message.
     *
     * @return array{message_id: string, status: string, driver: string}
     */
    public function send(string $phone, string $message): array;

    /**
     * Look up the delivery state of a previously sent message.
     *
     * @return array{message_id: string, status: string, driver?: string}
     */
    public function getDeliveryStatus(string $messageId): array;
}
