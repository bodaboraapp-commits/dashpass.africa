<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

/**
 * Offline access pass statics (docs/offline-access-contract.md §2, §3, §5, §6).
 *
 * The client-side cache cannot be browser-tested in this sandbox, so these tests
 * pin the security-critical contract in the shipped sources: service worker v8
 * never intercepts credential responses, offline.html is the offline access card
 * and stays CSP-clean, offline-access.js is encrypted-at-rest IndexedDB with a
 * non-extractable AES-GCM key and no plaintext credential storage, the views
 * carry the cache hooks, and the resident shell wires refresh/fallback/purge.
 */
final class OfflineAccessStaticTest extends TestCase
{
    private function source(string $relative): string
    {
        $path = BASE_PATH . '/' . $relative;
        $this->assertTrue(is_file($path), $relative . ' must exist.');
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        return $source;
    }

    /**
     * Parse an HTML string; named parsedHtml() so it never collides with the
     * inherited TestCase::dom(Response) helper.
     */
    private function parsedHtml(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }

    /**
     * @return array<int, string>
     */
    private function cspViolations(string $html): array
    {
        $dom = $this->parsedHtml($html);
        $xpath = new \DOMXPath($dom);
        $violations = [];

        foreach ($xpath->query('//script') ?: [] as $script) {
            if (!$script instanceof \DOMElement) {
                continue;
            }

            $src = trim($script->getAttribute('src'));

            if ($src === '') {
                $violations[] = 'inline <script> without src';
            } elseif (preg_match('#^https?://#i', $src) === 1) {
                $violations[] = 'external script src: ' . $src;
            }
        }

        foreach ($xpath->query('//@*') ?: [] as $attribute) {
            if (!$attribute instanceof \DOMAttr) {
                continue;
            }

            $name = strtolower($attribute->name);

            if ($name === 'style') {
                $violations[] = 'inline style attribute on <' . ($attribute->ownerElement?->tagName ?? '?') . '>';
            } elseif (str_starts_with($name, 'on')) {
                $violations[] = 'inline event handler ' . $name;
            }
        }

        foreach ($xpath->query('//*[@src or @href]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            foreach (['src', 'href'] as $attribute) {
                $value = trim($node->getAttribute($attribute));

                if ($value !== '' && preg_match('#^https?://#i', $value) === 1) {
                    $violations[] = 'external ' . $attribute . ': ' . $value;
                }
            }
        }

        return array_values(array_unique($violations));
    }

    public function testServiceWorkerIsV8AndNeverInterceptsCredentialResponses(): void
    {
        $sw = $this->source('public/service-worker.js');

        // Version bump + precache of the new offline-access script.
        $this->assertStringContainsString("var VERSION = 'v8'", $sw);
        $this->assertStringContainsString("var SHELL_CACHE = 'mtaapass-shell-v8'", $sw);
        $this->assertStringContainsString("var STATIC_CACHE = 'mtaapass-static-v8'", $sw);
        $this->assertStringContainsString("'/assets/js/offline-access.js'", $sw);
        $this->assertStringNotContainsString('mtaapass-shell-v7', $sw, 'The v7 shell cache name must be gone.');
        $this->assertStringNotContainsString('mtaapass-static-v7', $sw, 'The v7 static cache name must be gone.');

        // Never-intercept list: API, health and authenticated QR images.
        $this->assertStringContainsString('function isNeverCached(pathname)', $sw);
        $this->assertStringContainsString("pathname.indexOf('/api/') === 0", $sw);
        $this->assertStringContainsString("pathname === '/health'", $sw);
        $this->assertStringContainsString('api/v1/health', $sw);
        $this->assertStringContainsString('QR_IMAGE_PATTERN', $sw);
        $this->assertStringContainsString('passes\\/[^/]+\\/qr\\.png', $sw, 'The qr.png credential URL must be in the never-intercept regex.');
        $this->assertStringContainsString('QR_IMAGE_PATTERN.test(pathname)', $sw);

        // Navigation behaviour is unchanged: network-first with the offline card.
        $this->assertStringContainsString("var OFFLINE_URL = '/offline.html'", $sw);
        $this->assertStringContainsString("request.mode === 'navigate'", $sw);
        $this->assertStringContainsString('networkFirst(request)', $sw);

        // No authenticated page/API response is ever put into a cache: only the
        // stale-while-revalidate static-asset helper writes to a cache.
        $networkFirst = preg_match('/function networkFirst\(request\)[\s\S]*?\n}/', $sw, $match) === 1 ? $match[0] : '';
        $this->assertNotEmpty($networkFirst);
        $this->assertStringNotContainsString('cache.put', $networkFirst, 'Network-first navigations must never cache the response.');
        $this->assertSame(1, substr_count($sw, 'cache.put('), 'Only the static-asset helper may write to a cache.');

        // Sign-out still clears every cache.
        $this->assertStringContainsString("data.type === 'LOGOUT'", $sw);
        $this->assertStringContainsString('caches.delete(key)', $sw);
        $this->assertStringNotContainsString('http://', $sw);
    }

    public function testOfflineScriptIsEncryptedAtIndexedDbWithNonExtractableKey(): void
    {
        $js = $this->source('public/assets/js/offline-access.js');

        // IndexedDB database + stores from the contract §4.
        $this->assertStringContainsString("var DB_NAME = 'mtaapass-access'", $js);
        $this->assertStringContainsString("var KEY_STORE = 'keys'", $js);
        $this->assertStringContainsString("var PASS_STORE = 'passes'", $js);
        $this->assertStringContainsString('window.indexedDB.open(DB_NAME, DB_VERSION)', $js);
        $this->assertStringContainsString("createObjectStore(KEY_STORE, { keyPath: 'id' })", $js);
        $this->assertStringContainsString("createObjectStore(PASS_STORE, { keyPath: 'uuid' })", $js);

        // WebCrypto AES-256-GCM with a non-extractable device key.
        $this->assertStringContainsString("name: 'AES-GCM', length: 256", $js);
        $this->assertStringContainsString('extractable: false', $js, 'The documentation comment must pin the non-extractable key.');
        $this->assertMatchesRegularExpression(
            '/generateKey\(KEY_ALGORITHM,\s*false,\s*\[\'encrypt\',\s*\'decrypt\']\)/',
            $js,
            'The AES-GCM key must be generated with extractable=false.'
        );
        $this->assertStringContainsString('crypto.subtle.encrypt', $js);
        $this->assertStringContainsString('crypto.subtle.decrypt', $js);
        $this->assertStringContainsString('crypto.getRandomValues(new Uint8Array(IV_BYTES))', $js, 'A fresh random IV per encryption is required.');

        // Only ciphertext envelopes are persisted (no plaintext PIN/QR keys).
        $this->assertStringContainsString('pinEnc', $js);
        $this->assertStringContainsString('qrEnc', $js);
        $this->assertStringContainsString('encodeText(pinValue)', $js);

        // Object URLs are revoked after use.
        $this->assertStringContainsString('URL.createObjectURL', $js);
        $this->assertStringContainsString('URL.revokeObjectURL', $js);

        // Minimum necessary data + explicit save PIN / TTL / purge / wipe API.
        foreach (['savePass', 'getPass', 'loadForOffline', 'purgeExpired', 'clearAll', 'renderOfflineCard', 'attachToQrSheet', 'attachToPassPage'] as $api) {
            $this->assertStringContainsString($api, $js, 'OfflineAccess must expose ' . $api . '.');
        }
        $this->assertStringContainsString('TERMINAL_STATUSES', $js);
        $this->assertStringContainsString('data-offline-save-pin', $js);
        $this->assertStringContainsString('data-pass-pin', $js);
        $this->assertStringContainsString('isExpired', $js);
        $this->assertStringContainsString('Saved on this device', $js);

        // No credential material in browser key-value storage or cookies.
        foreach (['localStorage', 'sessionStorage', 'document.cookie'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, 'Credentials must never touch ' . $forbidden . '.');
        }

        // CSP/JS hygiene.
        $this->assertStringNotContainsString('innerHTML', $js, 'Render dynamic values with textContent, never innerHTML.');
        $this->assertStringNotContainsString('onclick=', $js);
        $this->assertStringNotContainsString('http://', $js);
        $this->assertStringNotContainsString('eval(', $js);
    }

    public function testOfflinePageIsTheAccessCardAndCspClean(): void
    {
        $html = $this->source('public/offline.html');

        $this->assertStringContainsString('Offline access · Skip the Queue', $html);
        $this->assertStringContainsString('/assets/js/offline-access.js', $html);
        $this->assertStringContainsString('defer', $html);
        $this->assertStringContainsString('data-offline-access', $html, 'The JS needs a container to render the saved pass card into.');
        $this->assertStringContainsString('data-offline-fallback', $html, 'The honest offline message must stay as the no-JS fallback.');
        $this->assertStringContainsString('Retry', $html);
        $this->assertStringNotContainsString('You are offline · Estate Access', $html);
        $this->assertCount(1, (new \DOMXPath($this->parsedHtml($html)))->query('//h1') ?: [], 'The offline page keeps a single h1.');

        $violations = $this->cspViolations($html);
        $this->assertCount(0, $violations, 'offline.html must stay CSP-clean: ' . implode('; ', $violations));
    }

    public function testViewsShipTheCacheHooksAndExplicitSavePinControl(): void
    {
        $qrSheet = $this->source('app/Views/partials/qr-sheet.php');

        foreach (['data-pass-uuid', 'data-household', 'data-status=', 'data-status-label', 'data-valid-until-iso', 'data-qr-url'] as $hook) {
            $this->assertStringContainsString($hook, $qrSheet, 'The QR sheet must expose the offline cache hook ' . $hook . '.');
        }
        $this->assertStringContainsString('$validUntilIso', $qrSheet, 'The QR sheet must pass the machine-readable validity for TTL.');

        $pass = $this->source('app/Views/app/pass.php');

        foreach ([
            'data-offline-pass',
            'data-offline-uuid',
            'data-offline-visitor',
            'data-offline-household',
            'data-offline-valid-until',
            'data-offline-status=',
            'data-offline-qr-url',
            'data-pass-pin',
            'data-offline-save-pin',
            'data-offline-save-note',
        ] as $hook) {
            $this->assertStringContainsString($hook, $pass, 'The pass screen must expose the offline hook ' . $hook . '.');
        }

        $this->assertStringContainsString('Save PIN on this device for offline access', $pass);
        $this->assertStringContainsString('this PIN is not saved on this device', $pass, 'A pass without a displayed PIN needs an honest offline hint.');

        // The offline script is part of the resident shell only, before app.js.
        $layout = $this->source('app/Views/layouts/app.php');
        $this->assertStringContainsString('js/offline-access.js', $layout);
        $this->assertStringContainsString('js/app.js', $layout);
        $this->assertTrue(
            strpos($layout, 'js/offline-access.js') < strpos($layout, 'js/app.js'),
            'offline-access.js must load before app.js so the hooks exist at boot.'
        );

        foreach (['guest.php', 'guard.php', 'admin.php', 'platform.php'] as $otherLayout) {
            $source = $this->source('app/Views/layouts/' . $otherLayout);
            $this->assertStringNotContainsString('offline-access.js', $source, 'Only the resident shell may load the offline access script.');
        }
    }

    public function testResidentAppScriptWiresRefreshFallbackAndLogoutPurge(): void
    {
        $script = $this->source('public/assets/js/app.js');

        $this->assertStringContainsString('OfflineAccess.attachToQrSheet', $script, 'The QR sheet must refresh/fall back to the cached pass.');
        $this->assertStringContainsString('OfflineAccess.attachToPassPage', $script, 'The pass screen must cache/purge its pass.');
        $this->assertStringContainsString('OfflineAccess.clearAll', $script, 'Sign-out must wipe the encrypted credential cache.');
        $this->assertStringContainsString('OfflineAccess.purgeExpired', $script, 'The app must purge expired cached passes on start.');

        $clearPos = strpos($script, 'OfflineAccess.clearAll');
        $logoutPos = strpos($script, "api('/api/v1/auth/logout'");
        $this->assertTrue($clearPos !== false && $logoutPos !== false && $clearPos < $logoutPos, 'The local credential cache must be cleared before the network logout call.');

        // QR bytes are fetched from the authenticated endpoint, never embedded.
        $offline = $this->source('public/assets/js/offline-access.js');
        $this->assertStringContainsString("credentials: 'same-origin'", $offline);
        $this->assertStringContainsString("cache: 'no-store'", $offline);
    }

    public function testOfflineAccessStylesAreShipped(): void
    {
        $css = $this->source('public/assets/css/app.css');

        foreach (['.offline-access', '.offline-access__qr', '.offline-access__pin', '.offline-access__saved', '.qr-sheet__offline-note', '.qr-sheet__offline-pin'] as $selector) {
            $this->assertStringContainsString($selector, $css, 'Missing offline access style ' . $selector . '.');
        }
        $this->assertStringContainsString('min-height: 44px', $css, 'Touch-target tokens must stay intact.');
    }
}
