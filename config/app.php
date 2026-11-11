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
    'env' => (string) env('APP_ENV', 'production'),
    'debug' => App\Core\Env::bool('APP_DEBUG', false),
    'url' => rtrim((string) env('APP_URL', 'http://localhost:8000'), '/'),
    'timezone' => (string) env('APP_TIMEZONE', 'Africa/Nairobi'),
    'version' => (string) env('APP_VERSION', '0.1.0-foundation'),
    'key' => (string) env('APP_KEY', ''),
    'support_email' => 'support@mtaapass.com',
    'default_country_code' => '254',
];
