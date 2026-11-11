<?php

declare(strict_types=1);

/*
 * Security configuration: sessions, CSRF, OTP hardening, rate limits.
 */

return [
    'session' => [
        'name' => (string) env('SESSION_NAME', 'mtaapass_session'),
        'idle_lifetime_minutes' => (int) env('SESSION_LIFETIME', 120),
        'absolute_lifetime_minutes' => (int) env('SESSION_ABSOLUTE_LIFETIME', 720),
        // `auto` = secure cookie when the request is HTTPS (or APP_ENV=production).
        'secure_cookie' => env('SESSION_SECURE_COOKIE', 'auto'),
        'same_site' => (string) env('SESSION_SAME_SITE', 'Lax'),
    ],

    'csrf' => [
        // Header names accepted for AJAX/API state-changing requests.
        'header_names' => ['X-CSRF-Token', 'X-XSRF-Token'],
        'form_field' => '_token',
    ],

    'otp' => [
        'length' => (int) env('AUTH_OTP_LENGTH', 6),
        'ttl_seconds' => (int) env('AUTH_OTP_TTL_SECONDS', 300),
        'max_attempts' => (int) env('AUTH_OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('AUTH_OTP_RESEND_COOLDOWN_SECONDS', 60),
    ],

    'rate_limits' => [
        'enabled' => App\Core\Env::bool('RATE_LIMIT_ENABLED', true),
        'otp_request_phone' => [
            'max' => (int) env('AUTH_OTP_PHONE_LIMIT', 5),
            'window' => (int) env('AUTH_OTP_PHONE_WINDOW_SECONDS', 900),
        ],
        'otp_request_ip' => [
            'max' => (int) env('AUTH_OTP_IP_LIMIT', 20),
            'window' => (int) env('AUTH_OTP_IP_WINDOW_SECONDS', 900),
        ],
        'otp_verify_phone' => [
            'max' => (int) env('AUTH_VERIFY_PHONE_LIMIT', 10),
            'window' => (int) env('AUTH_VERIFY_PHONE_WINDOW_SECONDS', 900),
        ],
    ],

    'headers' => [
        'hsts' => App\Core\Env::bool('SECURITY_HSTS', false),
        'trust_correlation_header' => App\Core\Env::bool('SECURITY_TRUST_CORRELATION_HEADER', true),
    ],
];
