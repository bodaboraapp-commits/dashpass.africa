<?php

declare(strict_types=1);

/**
 * MtaaPass test bootstrap.
 *
 * Every environment override is applied with putenv() *before* app/bootstrap.php
 * is required, because configuration is read from the environment at boot time
 * and a real process environment value always wins over `.env`.
 *
 * Returns ['app' => App\Core\App] exactly as required by
 * docs/foundation-contract.md §11.
 */

use App\Core\Database;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// The in-process test session store: Session uses $_SESSION as an array instead
// of a real PHP session cookie, so the whole HTTP pipeline can be exercised
// without a web server.
if (!defined('MTAAPASS_TEST_SESSION')) {
    define('MTAAPASS_TEST_SESSION', true);
}

/** @var array<string, string> $overrides */
$overrides = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'true',
    'DB_DATABASE' => 'mtaapass_test',
    'SMS_DRIVER' => 'mock',
    'SMS_DEV_EXPOSE_OTP' => 'true',
    'RATE_LIMIT_ENABLED' => 'true',
    'LOG_LEVEL' => 'error',
    'APP_URL' => 'http://localhost:8000',
];

foreach ($overrides as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

// Boots the real application (Env, Config, autoloader, routes, middleware).
$app = require BASE_PATH . '/app/bootstrap.php';

// -----------------------------------------------------------------------------
// Test database guard: only a database whose name ends in `_test` may ever be
// created or dropped by this suite. This is the single safety net that protects
// the development database from an accidental destructive run.
// -----------------------------------------------------------------------------
/** @var array<string, mixed> $connection */
$connection = (array) config('database.connections.mysql');
$database = (string) ($connection['database'] ?? '');

if ($database === '' || !str_ends_with($database, '_test')) {
    throw new RuntimeException(sprintf(
        'Refusing to run tests against database "%s": the test database name must end with `_test`.',
        $database
    ));
}

$host = (string) ($connection['host'] ?? '127.0.0.1');
$port = (int) ($connection['port'] ?? 3306);
$username = (string) ($connection['username'] ?? 'root');
$password = (string) ($connection['password'] ?? '');

try {
    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Create the test schema with the same charset/collation every migration
    // declares. Existing data is untouched — Migrator::fresh() owns dropping.
    $server->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        $database
    ));
} catch (PDOException $exception) {
    fwrite(STDERR, sprintf(
        "Cannot prepare the test database %s on %s:%d: %s\n",
        $database,
        $host,
        $port,
        $exception->getMessage()
    ));

    throw $exception;
}

unset($server, $connection, $password);

// Force a fresh connection configuration now that the schema exists.
Database::reset();

return ['app' => $app];
