<?php

declare(strict_types=1);

/*
 * Visitor access configuration.
 *
 * Estate-specific values (default validity window, walk-in approval timeout)
 * live in `estate_settings`; these are the platform defaults and safety limits.
 */

return [
    'invitation' => [
        // Suggested default only when an estate has not configured one.
        'default_validity_minutes' => (int) env('VISITOR_DEFAULT_VALIDITY_MINUTES', 240),
        // Hard ceiling so a resident cannot create an effectively permanent pass.
        'max_validity_hours' => (int) env('VISITOR_MAX_VALIDITY_HOURS', 168),
        'default_pin_length' => 6,
        'default_max_uses' => 1,
        'visitor_count_max' => 10,
        'purpose_max_length' => 120,
    ],

    'verification' => [
        'pin_max_attempts' => (int) env('VISITOR_PIN_MAX_ATTEMPTS', 5),
        'pin_window_seconds' => (int) env('VISITOR_PIN_WINDOW_SECONDS', 300),
        'ip_max_attempts' => (int) env('VISITOR_PIN_IP_MAX_ATTEMPTS', 30),
        'ip_window_seconds' => (int) env('VISITOR_PIN_IP_WINDOW_SECONDS', 300),
    ],

    'pass' => [
        // Opaque token length in bytes (hex encoded in the pass URL).
        'token_bytes' => 32,
        // Public pass page: request budget before throttling.
        'page_rate_limit' => (int) env('VISITOR_PASS_PAGE_LIMIT', 60),
        'page_rate_window' => (int) env('VISITOR_PASS_PAGE_WINDOW', 300),
    ],

    'walk_in' => [
        // After this period a pending request is marked expired (never auto-admitted).
        'approval_timeout_seconds' => (int) env('WALKIN_APPROVAL_TIMEOUT_SECONDS', 300),
    ],
];
