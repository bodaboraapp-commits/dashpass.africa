<?php

declare(strict_types=1);

/*
 * Logging configuration.
 */

return [
    'level' => (string) env('LOG_LEVEL', 'debug'),
    'path' => 'storage/logs',
    'max_days' => (int) env('LOG_MAX_DAYS', 14),
    'levels' => [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
    ],
];
