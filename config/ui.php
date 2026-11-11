<?php

declare(strict_types=1);

/*
 * Presentation-layer configuration.
 *
 * `preview_content` shows clearly labelled demo rows on screens whose data
 * module is not built yet (Visitors, Bookings). It must be disabled for
 * production pilots so operators never see demo data as if it were real.
 */

return [
    'resident_pwa' => [
        'preview_content' => App\Core\Env::bool('UI_PREVIEW_CONTENT', true),
    ],
];
