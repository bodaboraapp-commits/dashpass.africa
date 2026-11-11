<?php

declare(strict_types=1);

/*
 * Firebase Phone Auth configuration (Increment 12, owner decision).
 *
 * Firebase Phone Auth is the default OTP path. Only **public** Firebase web
 * client configuration is exposed here (safe to render into /login); no service
 * account, private key or server secret is required or stored. The server-side
 * ID-token check uses Google's public x509 certs (see FirebaseTokenVerifier).
 *
 * `provider` switches the POST /login/verify behaviour:
 *   - firebase: verify `firebase_token` (Firebase ID token) -> session login.
 *   - server:   legacy server-generated OTP flow (unchanged).
 */

use App\Core\Env;

$provider = strtolower(trim((string) env('AUTH_OTP_PROVIDER', 'server')));

if (!in_array($provider, ['firebase', 'server'], true)) {
    $provider = 'server';
}

$projectId = trim((string) env('FIREBASE_PROJECT_ID', ''));

return [
    // Public feature switch for the browser flow; server verification is bound
    // to `provider` so a misconfigured UI flag can never weaken token checks.
    'enabled' => Env::bool('FIREBASE_SMS_ENABLED', false),
    'provider' => $provider,
    'project_id' => $projectId,
    'cert_url' => (string) env(
        'FIREBASE_CERT_URL',
        'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com'
    ),
    'cache_path' => (string) env(
        'FIREBASE_CERT_CACHE_PATH',
        (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/storage/temporary/firebase-certs.json'
    ),
    'cache_ttl_seconds' => max(60, (int) env('FIREBASE_CERT_CACHE_TTL_SECONDS', 3600)),
    'leeway_seconds' => max(0, (int) env('FIREBASE_CLOCK_LEEWAY_SECONDS', 60)),
    'rate_limits' => [
        'ip' => [
            'max' => max(1, (int) env('AUTH_FIREBASE_IP_LIMIT', 20)),
            'window' => max(1, (int) env('AUTH_FIREBASE_IP_WINDOW_SECONDS', 900)),
        ],
        'phone' => [
            'max' => max(1, (int) env('AUTH_FIREBASE_PHONE_LIMIT', 10)),
            'window' => max(1, (int) env('AUTH_FIREBASE_PHONE_WINDOW_SECONDS', 900)),
        ],
    ],
    // Public web client config only — rendered/consumed by the login UI (T128).
    'web' => [
        'apiKey' => (string) env('FIREBASE_API_KEY', ''),
        'authDomain' => (string) env('FIREBASE_AUTH_DOMAIN', ''),
        'projectId' => $projectId,
        'storageBucket' => (string) env('FIREBASE_STORAGE_BUCKET', ''),
        'messagingSenderId' => (string) env('FIREBASE_MESSAGING_SENDER_ID', ''),
        'appId' => (string) env('FIREBASE_APP_ID', ''),
        'measurementId' => (string) env('FIREBASE_MEASUREMENT_ID', ''),
    ],
];
