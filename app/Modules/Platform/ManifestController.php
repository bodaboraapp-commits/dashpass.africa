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
            'name' => $name . ' Estate Access',
            'short_name' => mb_substr($shortName, 0, 12),
            'description' => $name . ' — smart estate access, visitor passes and parking.',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#070B16',
            'theme_color' => '#070B16',
            'orientation' => 'portrait',
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
