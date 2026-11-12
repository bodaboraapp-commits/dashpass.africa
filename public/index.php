<?php

declare(strict_types=1);

/*
 * MtaaPass front controller (contract §10).
 *
 * Apache/vhost document root must point at public/. For local development:
 *   php -S 127.0.0.1:8000 -t public public/index.php
 */

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';
header_remove('X-Powered-By');
$app->handle(App\Core\Request::fromGlobals())->send();
