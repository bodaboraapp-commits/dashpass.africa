<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Baseline browser security headers applied to every response.
 *
 * The Content-Security-Policy has no `unsafe-inline` for scripts: all
 * JavaScript is served from /assets, which keeps injected markup harmless.
 */
final class SecurityHeaders
{
    public static function apply(Response $response, Request $request): Response
    {
        $hsts = (bool) config('security.headers.hsts', false);
        $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; "
                . "img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; "
                . "manifest-src 'self'; worker-src 'self'; object-src 'none'"
            )
            ->withHeader('Cache-Control', 'no-store, private, max-age=0')
            ->withHeader('Pragma', 'no-cache');

        if ($hsts && $isHttps) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
