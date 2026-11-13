<?php

declare(strict_types=1);

/*
 * Transactional email configuration (Increment 5 §1.1, Increment 6 T42).
 *
 * `log` is the development/test adapter (writes a redacted line to
 * storage/logs/mail.log). Production uses `smtp` (Resend SMTP) or `resend`
 * (HTTP API) with credentials supplied via `.env` only, never commit real keys.
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
    // Public contact-form inbox (e.g. hello@dashpass.africa). Falls back to
    // `alert_to` and then `app.support_email` in the Help controller.
    'contact_to' => (string) env('MAIL_CONTACT_TO', ''),
    'resend' => [
        'key' => (string) env('RESEND_API_KEY', ''),
        'url' => (string) env('RESEND_API_URL', 'https://api.resend.com'),
    ],
    'smtp' => [
        'host' => (string) env('MAIL_SMTP_HOST', 'smtp.resend.com'),
        'port' => max(1, (int) env('MAIL_SMTP_PORT', 465)),
        'username' => (string) env('MAIL_SMTP_USERNAME', ''),
        'password' => (string) env('MAIL_SMTP_PASSWORD', ''),
        // ssl = implicit TLS (465), tls = STARTTLS (587), none = plain (test only).
        'encryption' => strtolower(trim((string) env('MAIL_SMTP_ENCRYPTION', 'ssl'))),
        'timeout' => max(1, (int) env('MAIL_TIMEOUT_SECONDS', 10)),
    ],
    'timeout' => max(1, (int) env('MAIL_TIMEOUT_SECONDS', 10)),
    'log_path' => 'storage/logs/mail.log',
];
