<?php

declare(strict_types=1);

/*
 * Transactional email configuration (Increment 5 §1.1).
 *
 * `log` is the development/test adapter (writes a redacted line to
 * storage/logs/mail.log). Production pilots use `resend` with a verified sender
 * domain and `RESEND_API_KEY` supplied via `.env` only — never commit a real key.
 */

$alertTo = array_values(array_filter(
    array_map(
        static fn (string $address): string => trim($address),
        explode(',', (string) env('MAIL_ALERT_TO', ''))
    ),
    static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false
));

return [
    'driver' => (string) env('MAIL_DRIVER', 'log'),
    'from' => [
        'address' => (string) env('MAIL_FROM_ADDRESS', 'onboarding@resend.dev'),
        'name' => (string) env('MAIL_FROM_NAME', (string) env('APP_NAME', 'MtaaPass')),
    ],
    'reply_to' => (string) env('MAIL_REPLY_TO', ''),
    'alert_to' => $alertTo,
    'resend' => [
        'key' => (string) env('RESEND_API_KEY', ''),
        'url' => (string) env('RESEND_API_URL', 'https://api.resend.com'),
    ],
    'timeout' => max(1, (int) env('MAIL_TIMEOUT_SECONDS', 10)),
    'log_path' => 'storage/logs/mail.log',
];
