<?php

declare(strict_types=1);

// Temporary T15 smoke setup (deleted after use): creates an isolated
// `mtaapass_smoke_test` database, migrates + seeds it, and clears limits.

putenv('APP_ENV=testing');
putenv('APP_DEBUG=true');
putenv('DB_DATABASE=mtaapass_smoke_test');
putenv('SMS_DRIVER=mock');
putenv('SMS_DEV_EXPOSE_OTP=true');
putenv('RATE_LIMIT_ENABLED=true');
putenv('LOG_LEVEL=error');

$root = dirname(__DIR__);
$app = require $root . '/app/bootstrap.php';

$db = App\Core\Database::instance();
$database = $db->databaseName();

if ($database !== 'mtaapass_smoke_test' || !str_ends_with($database, '_test')) {
    fwrite(STDERR, 'Refusing to touch database ' . $database . PHP_EOL);
    exit(1);
}

$server = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$server->exec('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$migrator = new App\Core\Migrator($db, $root . '/database/migrations');
$applied = $migrator->fresh();
$seed = require $root . '/database/seeds/run.php';
$seed();

$limiter = new App\Core\RateLimiter($db);
$limiter->clearPrefix('otp:');
$limiter->clearPrefix('throttle:');
$limiter->clearPrefix('visitor:');
$limiter->clearPrefix('pass:');

fwrite(STDOUT, 'smoke db ready: ' . $database . ' (' . count($applied) . ' migrations)' . PHP_EOL);
