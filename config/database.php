<?php

declare(strict_types=1);

/*
 * Database configuration.
 *
 * MtaaPass uses a single MySQL 8 (MariaDB 10.4+ compatible) database with
 * estate-scoped rows. See Blueprint §13, shared database multi-tenancy.
 */

return [
    'default' => (string) env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => (string) env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => (string) env('DB_DATABASE', 'mtaapass'),
            'username' => (string) env('DB_USERNAME', 'root'),
            'password' => (string) env('DB_PASSWORD', ''),
            'charset' => (string) env('DB_CHARSET', 'utf8mb4'),
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],

    'test_database' => (string) env('DB_TEST_DATABASE', 'mtaapass_test'),
];
