<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Config;

/**
 * Firebase Phone Auth security surface (Increment 12, docs/firebase-sms-otp.md
 * §5, §8; SDK migration T131): CSP origins for Firebase/reCAPTCHA, strict script
 * policy, no inline scripts/handlers, modular SDK pinned to 13.2.0 with no
 * analytics import, CDN allowlist, and no service-account/private-key material.
 */
final class FirebaseSecurityTest extends SecurityTestCase
{
    private const APP_ID = '1:99186909972:web:ad3900fddee22fe6b91801';
    private const MEASUREMENT_ID = 'G-D9JVCQJG9V';

    /** @var array<string, mixed> */
    private array $originalFirebase = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalFirebase = (array) Config::get('firebase', []);
    }

    public function tearDown(): void
    {
        Config::set('firebase', $this->originalFirebase);

        parent::tearDown();
    }

    private function enableFirebase(): void
    {
        Config::set('firebase', array_merge($this->originalFirebase, [
            'enabled' => true,
            'provider' => 'firebase',
            'project_id' => 'komiuta',
            'web' => [
                'apiKey' => 'test-public-api-key',
                'authDomain' => 'komiuta.firebaseapp.com',
                'projectId' => 'komiuta',
                'storageBucket' => 'komiuta.firebasestorage.app',
                'messagingSenderId' => '99186909972',
                'appId' => self::APP_ID,
                'measurementId' => self::MEASUREMENT_ID,
            ],
        ]));
    }

    /**
     * @return string
     */
    private function csp(\App\Core\Response $response): string
    {
        return (string) $response->header('Content-Security-Policy');
    }

    /* ------------------------------------------------------------------ */
    /* CSP                                                                */
    /* ------------------------------------------------------------------ */

    public function testCspAllowsFirebaseAndRecaptchaOriginsButKeepsScriptsStrict(): void
    {
        $this->enableFirebase();

        $csp = $this->csp($this->get('/login'));

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);

        // Strict script policy: Firebase/reCAPTCHA origins only, no inline/eval.
        $this->assertMatches('/script-src [^;]+/', $csp);
        preg_match('/script-src ([^;]+)/', $csp, $scriptDirective);
        $this->assertStringContainsString("'self'", $scriptDirective[1]);
        $this->assertStringContainsString('https://www.gstatic.com', $scriptDirective[1]);
        $this->assertStringContainsString('https://www.google.com/recaptcha/', $scriptDirective[1]);
        $this->assertStringContainsString('https://apis.google.com', $scriptDirective[1]);
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptDirective[1]);
        $this->assertStringNotContainsString("'unsafe-eval'", $scriptDirective[1]);

        // Documented style exception: reCAPTCHA badge inline styles only.
        preg_match('/style-src ([^;]+)/', $csp, $styleDirective);
        $this->assertStringContainsString("'unsafe-inline'", $styleDirective[1]);
        $this->assertStringContainsString('https://www.gstatic.com', $styleDirective[1]);
        $this->assertStringContainsString('https://www.google.com/recaptcha/', $styleDirective[1]);

        // Firebase Auth/runtime endpoints + cert host + authDomain helper.
        foreach (['connect-src', 'frame-src', 'img-src', 'font-src'] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }

        preg_match('/connect-src ([^;]+)/', $csp, $connectDirective);
        foreach ([
            'https://identitytoolkit.googleapis.com',
            'https://securetoken.googleapis.com',
            'https://www.googleapis.com',
            'https://komiuta.firebaseapp.com',
            'https://www.google.com/recaptcha/',
        ] as $origin) {
            $this->assertStringContainsString($origin, $connectDirective[1], 'CSP connect-src must allow ' . $origin);
        }

        preg_match('/frame-src ([^;]+)/', $csp, $frameDirective);
        foreach (['https://komiuta.firebaseapp.com', 'https://www.google.com', 'https://recaptcha.google.com'] as $origin) {
            $this->assertStringContainsString($origin, $frameDirective[1], 'CSP frame-src must allow ' . $origin);
        }

        preg_match('/img-src ([^;]+)/', $csp, $imgDirective);
        $this->assertStringContainsString("'self'", $imgDirective[1]);
        $this->assertStringContainsString('data:', $imgDirective[1]);
        $this->assertStringContainsString('https:', $imgDirective[1]);

        preg_match('/font-src ([^;]+)/', $csp, $fontDirective);
        $this->assertStringContainsString('https://www.gstatic.com', $fontDirective[1]);
    }

    /* ------------------------------------------------------------------ */
    /* Login markup                                                       */
    /* ------------------------------------------------------------------ */

    public function testLoginMarkupHasNoInlineScriptsOrHandlers(): void
    {
        $this->enableFirebase();

        $response = $this->get('/login');
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'Firebase login page');

        $dom = $this->dom($response);

        foreach ($this->xpath('//script', $dom) as $script) {
            $this->assertTrue($script instanceof \DOMElement);
            $this->assertNotSame('', trim($script->getAttribute('src')), 'Every script must be external (no inline script).');
        }

        foreach ($this->xpath('//@*', $dom) as $attribute) {
            $this->assertTrue($attribute instanceof \DOMAttr);
            $this->assertSame(
                0,
                preg_match('/^on[a-z]+$/i', $attribute->name),
                'Inline event handler attribute found: ' . $attribute->name
            );
        }

        $moduleScripts = $this->xpath('//script[@type="module"]', $dom);
        $this->assertSame(1, $moduleScripts->length, 'The Firebase SDK must load as exactly one external module script.');
    }

    /* ------------------------------------------------------------------ */
    /* Static SDK audit                                                   */
    /* ------------------------------------------------------------------ */

    public function testFirebaseAuthScriptIsModular13PinnedAndAnalyticsFree(): void
    {
        $path = BASE_PATH . '/public/assets/js/firebase-auth.js';
        $this->assertTrue(is_file($path), 'public/assets/js/firebase-auth.js must exist.');
        $source = (string) file_get_contents($path);

        // Modular 13.2.0 ESM imports from the official gstatic CDN.
        $this->assertStringContainsString(
            'https://www.gstatic.com/firebasejs/13.2.0/firebase-app.js',
            $source
        );
        $this->assertStringContainsString(
            'https://www.gstatic.com/firebasejs/13.2.0/firebase-auth.js',
            $source
        );

        // No compat globals, no analytics module, no legacy range.
        foreach ([
            'firebase-app-compat',
            'firebase-auth-compat',
            'firebase-analytics',
            'firebase-messaging',
            'getAnalytics',
            'firebase/9.',
            'firebase/10.',
            'firebase/11.',
            'firebase/12.',
            'firebase/14.',
            'eval(',
            'new Function(',
            'document.write',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, 'firebase-auth.js must not contain ' . $forbidden);
        }

        // Default SDK version fallback must match the pinned imports.
        $this->assertStringContainsString("data-sdk-version', '13.2.0'", $source);
        $this->assertStringContainsString("'use strict'", $source);
    }

    public function testFirebaseCdnAllowlistIsExact(): void
    {
        $source = (string) file_get_contents(BASE_PATH . '/public/assets/js/firebase-auth.js');

        preg_match_all('#https?://[^\s\'"<>]+#', $source, $matches);
        $urls = array_values(array_unique($matches[0]));

        $allowed = [
            'https://www.gstatic.com/firebasejs/13.2.0/firebase-app.js',
            'https://www.gstatic.com/firebasejs/13.2.0/firebase-auth.js',
        ];

        sort($allowed);
        sort($urls);

        $this->assertSame($allowed, $urls, 'firebase-auth.js may only reference the pinned Firebase 13.2.0 gstatic modules.');
    }

    public function testNoServiceAccountOrPrivateKeyMaterialOnFirebaseSurfaces(): void
    {
        $this->enableFirebase();

        $body = $this->get('/login')->body();

        foreach ([
            'BEGIN ' . 'PRIVATE KEY',
            'BEGIN RSA ' . 'PRIVATE KEY',
            'service' . '_account',
            'private_key',
            'client_email',
            'client' . '_secret',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, 'The login page must never render secret material.');
        }

        // The served config is only the public web identifiers.
        $this->assertStringContainsString(self::APP_ID, $body);
        $this->assertStringContainsString(self::MEASUREMENT_ID, $body);
        $this->assertStringNotContainsString('"private_key"', $body);

        $surfaces = [
            BASE_PATH . '/app/Modules/Authentication/FirebaseTokenVerifier.php',
            BASE_PATH . '/app/Modules/Authentication/AuthController.php',
            BASE_PATH . '/app/Views/auth/login.php',
            BASE_PATH . '/public/assets/js/firebase-auth.js',
            BASE_PATH . '/config/firebase.php',
        ];

        foreach ($surfaces as $surface) {
            $contents = (string) file_get_contents($surface);

            foreach (['BEGIN ' . 'PRIVATE KEY', 'BEGIN RSA ' . 'PRIVATE KEY', 'service' . '_account', 'client' . '_secret', 'BEGIN CERTIFICATE'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $contents,
                    basename($surface) . ' must not contain embedded key/certificate material.'
                );
            }
        }
    }

    public function testNoExternalAssetHostsOutsideTheFirebaseAllowlistInViewsAndAssets(): void
    {
        $files = array_merge(
            glob(BASE_PATH . '/app/Views/*.php') ?: [],
            glob(BASE_PATH . '/app/Views/**/*.php') ?: [],
            glob(BASE_PATH . '/public/assets/js/*.js') ?: [],
            glob(BASE_PATH . '/public/assets/css/*.css') ?: []
        );

        $allowed = [
            'https://www.gstatic.com/firebasejs/13.2.0/firebase-app.js',
            'https://www.gstatic.com/firebasejs/13.2.0/firebase-auth.js',
            // Documentation/attribution URL in the icon partial comment (not fetched).
            'https://fonts.google.com/icons',
            // Standard XML/SVG namespace constant used by the date-range picker
            // (a namespace identifier, never fetched as an asset).
            'http://www.w3.org/2000/svg',
        ];

        $violations = [];

        foreach (array_unique($files) as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('#https?://[^\s\'"<>]+#', $source, $matches);

            foreach (array_unique($matches[0]) as $url) {
                if (!in_array($url, $allowed, true)) {
                    $violations[] = str_replace(BASE_PATH . '/', '', $file) . ' -> ' . $url;
                }
            }
        }

        $this->assertSame([], $violations, 'Unexpected external asset/URL hosts: ' . implode('; ', $violations));
    }

    public function testLoginPageDoesNotExposeEnvironmentSecrets(): void
    {
        $this->enableFirebase();

        $body = $this->get('/login')->body();

        foreach ([
            'APP_KEY=',
            'DB_PASSWORD=',
            'RESEND_API_KEY=',
            'MAIL_SMTP_PASSWORD=',
            'DEPLOY_SYNC_TOKEN=',
        ] as $marker) {
            $this->assertStringNotContainsString($marker, $body);
        }
    }
}
