<?php

declare(strict_types=1);

/**
 * MtaaPass application bootstrap.
 *
 * Loads helpers, registers the PSR-4 style autoloader, configuration and the
 * application kernel. Both `public/index.php` and CLI scripts require this file.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require BASE_PATH . '/app/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

App\Core\Env::load(BASE_PATH . '/.env');
App\Core\Config::load(BASE_PATH . '/config');

// The application stores and reasons in UTC; estate-local time is a display
// concern handled with the estate timezone.
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

return App\Core\App::boot();
