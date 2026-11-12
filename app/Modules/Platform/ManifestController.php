<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * Builds the PWA web app manifest from configuration so each deployment can
 * carry its own product name (for example "DashPASS" in production and
 * "MtaaPass" in development) without editing static assets.
 */
final class ManifestController
{
    /**
     * @param array<string, string|null> $params
     */
    public function show(Request $request, array $params, Context $context): Response
    {
        $name = trim((string) config('app.name', 'MtaaPass')) ?: 'MtaaPass';
        $shortName = trim((string) config('app.short_name', $name)) ?: $name;

        $manifest = [
            'id' => '/',
            'name' => $name . ' Skip the Queue',
            'short_name' => mb_substr($shortName, 0, 12),
            'description' => $name . ' — smart estate access, visitor passes and parking.',
            'lang' => 'en',
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'display_override' => ['standalone', 'minimal-ui'],
            'orientation' => 'portrait',
            'background_color' => '#070B16',
            'theme_color' => '#070B16',
            'categories' => ['business', 'productivity', 'utilities'],
            'prefer_related_applications' => false,
            'launch_handler' => ['client_mode' => 'navigate-existing'],
            'handle_links' => 'preferred',
            'shortcuts' => [
                [
                    'name' => 'Invite visitor',
                    'short_name' => 'Invite',
                    'description' => 'Create a visitor pass for your household.',
                    'url' => '/app/visitors/invite',
                    'icons' => [
                        [
                            'src' => '/assets/icons/icon-192.png',
                            'sizes' => '192x192',
                            'type' => 'image/png',
                        ],
                    ],
                ],
                [
                    'name' => 'My parking',
                    'short_name' => 'Parking',
                    'description' => 'See visitor parking reservations and bays.',
                    'url' => '/app/parking',
                    'icons' => [
                        [
                            'src' => '/assets/icons/icon-192.png',
                            'sizes' => '192x192',
                            'type' => 'image/png',
                        ],
                    ],
                ],
            ],
            'icons' => [
                [
                    'src' => '/assets/icons/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/assets/icons/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/assets/icons/icon-maskable-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];

        return Response::json($manifest)
            ->withHeader('Content-Type', 'application/manifest+json; charset=utf-8');
    }
}
