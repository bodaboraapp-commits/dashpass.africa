<?php

declare(strict_types=1);

/**
 * Seed entry point.
 *
 * Returns a callable that seeds permissions, roles and the demo estate data in
 * dependency order. Both `scripts/seed.php` and `tests/bootstrap.php` use:
 *
 *     $seed = require BASE_PATH . '/database/seeds/run.php';
 *     $seed(); // idempotent
 */

use Database\Seeds\DemoParkingSeeder;
use Database\Seeds\DemoSeeder;
use Database\Seeds\DemoVisitorSeeder;
use Database\Seeds\PermissionsSeeder;
use Database\Seeds\RolesSeeder;

require_once __DIR__ . '/Seeder.php';
require_once __DIR__ . '/PermissionsSeeder.php';
require_once __DIR__ . '/RolesSeeder.php';
require_once __DIR__ . '/DemoSeeder.php';
require_once __DIR__ . '/DemoVisitorSeeder.php';
require_once __DIR__ . '/DemoParkingSeeder.php';

/**
 * @return callable(): array<string, array<string, int>>
 */
return static function (): array {
    return [
        'permissions' => (new PermissionsSeeder())->run(),
        'roles' => (new RolesSeeder())->run(),
        'demo' => (new DemoSeeder())->run(),
        'visitors' => (new DemoVisitorSeeder())->run(),
        'parking' => (new DemoParkingSeeder())->run(),
    ];
};
