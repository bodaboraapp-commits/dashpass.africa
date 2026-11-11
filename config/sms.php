<?php

declare(strict_types=1);

/*
 * SMS configuration.
 *
 * `log` and `mock` are development adapters. A production deployment must
 * configure a real provider adapter; the application refuses to run with a
 * development adapter when APP_ENV=production.
 */

return [
    'driver' => (string) env('SMS_DRIVER', 'log'),
    'from' => (string) env('SMS_SENDER_ID', 'MTAAPASS'),
    'expose_development_otp' => App\Core\Env::bool('SMS_DEV_EXPOSE_OTP', false),
    'log_path' => 'storage/logs/sms.log',
];
