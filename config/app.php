<?php

declare(strict_types=1);

/*
 * Application configuration.
 *
 * Values are read from the environment (`.env` in development) so that no
 * secret or environment-specific value is committed to source control.
 */

return [
    'name' => (string) env('APP_NAME', 'MtaaPass'),
    // Compact name for the PWA home-screen icon; defaults to the full name.
    'short_name' => (string) env('APP_SHORT_NAME', (string) env('APP_NAME', 'MtaaPass')),
    'env' => (string) env('APP_ENV', 'production'),
    'debug' => App\Core\Env::bool('APP_DEBUG', false),
    'url' => rtrim((string) env('APP_URL', 'http://localhost:8000'), '/'),
    'timezone' => (string) env('APP_TIMEZONE', 'Africa/Nairobi'),
    'version' => (string) env('APP_VERSION', '0.2.1-dashpass'),
    'key' => (string) env('APP_KEY', ''),
    'support_email' => (string) env('APP_SUPPORT_EMAIL', 'support@mtaapass.com'),
    'default_country_code' => '254',
];
