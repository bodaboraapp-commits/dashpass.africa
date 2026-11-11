<?php

declare(strict_types=1);

/*
 * In-app notification settings (Inc-13 P2a / notices -> bell).
 *
 * The feature flag is intentionally environment-overridable so tests and
 * pre-migration deployments can disable the bell/inbox safely. Column support
 * is additionally probed at runtime because P0 adds the notification columns
 * via a separate migration.
 */

return [
    'in_app_enabled' => App\Core\Env::bool('IN_APP_NOTIFICATIONS_ENABLED', true),
    'latest_limit' => 5,
    'inbox_limit' => 50,
    'excerpt_limit' => 140,
];
