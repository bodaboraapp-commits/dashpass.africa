<?php

declare(strict_types=1);

// Temporary T15 smoke teardown (deleted after use): drops the isolated smoke DB.

$database = 'mtaapass_smoke_test';

if (!str_ends_with($database, '_test')) {
    exit(1);
}

$pdo = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');

fwrite(STDOUT, 'smoke db dropped: ' . $database . PHP_EOL);
