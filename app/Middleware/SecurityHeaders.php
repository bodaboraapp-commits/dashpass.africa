<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Baseline browser security headers applied to every response.
 *
 * The Content-Security-Policy has no inline-script allowance and no eval
 * allowance: all JavaScript is served from /assets, which keeps injected markup
 * harmless.
 *
 * Increment 12, Firebase Phone Auth (default OTP path) + Google reCAPTCHA:
 *  - script-src https://www.gstatic.com: Firebase compat SDK
 *    (firebase-app-compat.js / firebase-auth-compat.js) + reCAPTCHA statics;
 *  - script-src https://www.google.com/recaptcha/: reCAPTCHA api.js loader,
 *    injected by Firebase Auth when it creates the RecaptchaVerifier;
 *  - script-src https://apis.google.com: Google API client used by Firebase Auth;
 *  - connect-src https://identitytoolkit.googleapis.com,
 *    https://securetoken.googleapis.com: Firebase Auth sign-in / ID-token calls;
 *  - connect-src https://www.googleapis.com: Google public certs + token metadata;
 *  - connect-src https://komiuta.firebaseapp.com: the project authDomain helper;
 *  - connect-src https://www.google.com/recaptcha/: reCAPTCHA challenge calls;
 *  - frame-src https://komiuta.firebaseapp.com: Firebase Auth iframe;
 *  - frame-src https://www.google.com, https://recaptcha.google.com:
 *    reCAPTCHA challenge frames;
 *  - img-src https: data: reCAPTCHA badge/assets; data: is already used by UI icons;
 *  - style-src https://www.gstatic.com / https://www.google.com/recaptcha/:
 *    reCAPTCHA stylesheets/assets; font-src https://www.gstatic.com for its fonts;
 *  - style-src 'unsafe-inline': REQUIRED and documented for the Google
 *    reCAPTCHA badge, which injects inline styles into the page. This weakens the
 *    style policy only; script policy remains strict (no inline/eval) and this
 *    application authors no inline styles. Remove it if the badge is replaced.
 */
final class SecurityHeaders
{
    /**
     * Public SEO / AI-crawler documents only: these GET responses may be cached
     * by browsers and proxies (their controllers set `public, max-age=3600`).
     */
    private const CACHEABLE_PUBLIC_PATHS = [
        '/sitemap.xml',
        '/robots.txt',
        '/llms.txt',
        '/llms-full.txt',
        '/feed.xml',
    ];

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
                . "img-src 'self' data: https:; "
                . "style-src 'self' 'unsafe-inline' https://www.gstatic.com https://www.google.com/recaptcha/; "
                . "font-src 'self' https://www.gstatic.com; "
                . "script-src 'self' https://www.gstatic.com https://www.google.com/recaptcha/ https://apis.google.com; "
                . "connect-src 'self' https://identitytoolkit.googleapis.com https://securetoken.googleapis.com "
                . "https://www.googleapis.com https://komiuta.firebaseapp.com https://www.google.com/recaptcha/; "
                . "frame-src https://komiuta.firebaseapp.com https://www.google.com https://recaptcha.google.com; "
                . "manifest-src 'self'; worker-src 'self'; object-src 'none'"
            );

        // SEO crawl documents are the only public responses allowed to be cached;
        // everything else (auth, PWA, passes/QR, forms) stays strictly no-store.
        $isCacheablePublicSeo = $request->method() === 'GET'
            && in_array($request->path(), self::CACHEABLE_PUBLIC_PATHS, true);

        if (!$isCacheablePublicSeo) {
            $response = $response
                ->withHeader('Cache-Control', 'no-store, private, max-age=0')
                ->withHeader('Pragma', 'no-cache');
        }

        if ($hsts && $isHttps) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
