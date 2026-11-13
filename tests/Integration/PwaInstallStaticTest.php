<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use Tests\TestCase;

/**
 * PWA install enforcement statics (email-pwa-greeting-contract §2, §4):
 * hardened manifest served by the dynamic route, install-banner hooks, install
 * state/platform/self-check JS, service worker v10 (fresh deploy assets), and the
 * SW serving headers.
 *
 * Browser install prompts and real devices cannot be automated in this sandbox —
 * these are the static installability criteria plus the app's own hooks.
 */
final class PwaInstallStaticTest extends TestCase
{
    private function source(string $relative): string
    {
        $path = BASE_PATH . '/' . $relative;
        $this->assertTrue(is_file($path), $relative . ' must exist.');
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        return $source;
    }

    public function testManifestHasTheHardenedFields(): void
    {
        $response = $this->get('/manifest.json');
        $this->assertStatus(200, $response);
        $this->assertContains('application/manifest+json', strtolower((string) $response->header('Content-Type')));

        $manifest = json_decode((string) $response->body(), true);
        $this->assertTrue(is_array($manifest), 'The manifest must be valid JSON.');

        $product = (string) Config::get('app.name', 'MtaaPass');
        $this->assertSame($product . ' Skip the Queue', (string) $manifest['name'], 'The manifest name must be product-derived.');
        $this->assertStringContainsString($product, (string) $manifest['name']);
        $this->assertNotEmpty((string) $manifest['short_name']);
        $this->assertSame('en', $manifest['lang']);
        $this->assertSame('ltr', $manifest['dir']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);

        $guestLayout = (string) file_get_contents(BASE_PATH . '/app/Views/layouts/guest.php');
        $this->assertStringContainsString('<small>Skip the Queue</small>', $guestLayout);
        $this->assertStringNotContainsString('<small>Estate access</small>', $guestLayout);
        $this->assertStringContainsString('<?= e($appName) ?> <?= e($appVersion) ?> · Secure, Fast &amp; Accountable.', $guestLayout);
        $this->assertStringNotContainsString('foundation', $guestLayout);
        $this->assertStringNotContainsString('Secure, accountable estate access.', $guestLayout);
        $this->assertStringContainsString('href="mailto:hello@dashpass.africa"', $guestLayout);
        $this->assertStringContainsString('>hello@dashpass.africa</a>', $guestLayout);
        $this->assertStringNotContainsString('hello@dashpass.com', $guestLayout);
        $this->assertStringContainsString('.guest-footer__contact a', (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css'));
        $this->assertMatchesRegularExpression('/\\.guest-footer__contact a\\s*\\{[^}]*min-height:\\s*44px/s', (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css'));
        $iconPartial = (string) file_get_contents(BASE_PATH . '/app/Views/partials/icon.php');
        $this->assertStringContainsString("in_array(\$name, ['chevron', 'chevron-left'], true)", $iconPartial);
        $this->assertStringContainsString("' ico--chevron'", $iconPartial);
        $cssIcons = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertMatchesRegularExpression('/\\.ico--chevron\\s*\\{[^}]*width:\\s*1\\.375rem[^}]*height:\\s*1\\.375rem[^}]*stroke-width:\\s*2\\.5/s', $cssIcons);
        $this->assertMatchesRegularExpression('/\\.ico--chevron\\.ico--sm\\s*\\{[^}]*width:\\s*1\\.25rem/s', $cssIcons);
        $this->assertMatchesRegularExpression('/\\.ico--chevron\\.ico--xs\\s*\\{[^}]*width:\\s*1rem/s', $cssIcons);
        $this->assertMatchesRegularExpression('/\\.hero-estate__details \\.ico--chevron\\s*\\{[^}]*width:\\s*1\\.25rem/s', $cssIcons);
        $this->assertMatchesRegularExpression('/\\.link-quiet:focus-visible\\s*\\{[^}]*outline:\\s*3px/s', $cssIcons);
        $welcome = (string) file_get_contents(BASE_PATH . '/app/Views/auth/welcome.php');
        $this->assertStringContainsString('Skip the Queue · <?= e($appVersion) ?>', $welcome);
        $this->assertStringContainsString('<?= e((string) config(\'app.name\', \'MtaaPass\')) ?> <?= e($appVersion) ?> · Secure, Fast &amp; Accountable.', $welcome);
        $this->assertStringContainsString('By continuing you agree to your estate\'s access rules and <?= e((string) config(\'app.name\', \'MtaaPass\')) ?> privacy notice.', $welcome);
        $this->assertStringContainsString('href="mailto:hello@dashpass.africa"', $welcome);
        $this->assertStringNotContainsString('support@mtaapass.com', $welcome);
        $this->assertStringNotContainsString('foundation', $welcome);
        $this->assertStringNotContainsString('support@mtaapass.com', $guestLayout);
        $noEstate = (string) file_get_contents(BASE_PATH . '/app/Views/app/no-estate.php');
        $this->assertStringContainsString('hello@dashpass.africa', $noEstate);
        $this->assertStringNotContainsString('support@mtaapass.com', $noEstate);
        $offlineHtml = (string) file_get_contents(BASE_PATH . '/public/offline.html');
        $this->assertStringContainsString('Offline access · Skip the Queue', $offlineHtml);
        $this->assertStringContainsString('/assets/js/offline-access.js', $offlineHtml, 'offline.html is the offline access card and must load the cache script.');
        $this->assertStringNotContainsString('You are offline · Estate Access', $offlineHtml);
        $this->assertSame(['standalone', 'minimal-ui'], $manifest['display_override']);
        $this->assertSame('portrait', $manifest['orientation']);
        $this->assertSame(['business', 'productivity', 'utilities'], $manifest['categories']);
        $this->assertFalse($manifest['prefer_related_applications']);
        $this->assertSame('navigate-existing', $manifest['launch_handler']['client_mode']);
        $this->assertSame('preferred', $manifest['handle_links']);
    }

    public function testManifestIncludesTwoShortcutsAndInstallableIcons(): void
    {
        $manifest = json_decode((string) $this->get('/manifest.json')->body(), true);
        $this->assertTrue(is_array($manifest));

        $this->assertCount(2, $manifest['shortcuts'], 'The manifest must declare the two app shortcuts.');
        $urls = array_map(static fn (array $shortcut): string => (string) ($shortcut['url'] ?? ''), $manifest['shortcuts']);
        $this->assertContains('/app/visitors/invite', $urls);
        $this->assertContains('/app/parking', $urls);

        $sizes = [];
        $maskable = false;
        foreach ($manifest['icons'] as $icon) {
            $sizes[] = (string) ($icon['sizes'] ?? '');
            $purpose = strtolower((string) ($icon['purpose'] ?? ''));
            if (str_contains($purpose, 'maskable')) {
                $maskable = true;
            }
            $this->assertStringStartsWith('/', (string) ($icon['src'] ?? ''), 'Icons must be root-relative.');
            $this->assertNotEmpty((string) ($icon['type'] ?? ''));
        }

        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertTrue($maskable, 'A maskable 512 icon must be declared.');
    }

    public function testManifestProductNameIsConfigurationDriven(): void
    {
        $original = (string) Config::get('app.name', 'MtaaPass');

        try {
            Config::set('app.name', 'DashPASS Static Test');
            $manifest = json_decode((string) $this->get('/manifest.json')->body(), true);
            $this->assertSame('DashPASS Static Test Skip the Queue', (string) $manifest['name']);
        } finally {
            Config::set('app.name', $original);
        }
    }

    public function testAppLayoutShipsTheInstallBannerHooks(): void
    {
        $layout = $this->source('app/Views/layouts/app.php');
        $welcome = $this->source('app/Views/auth/welcome.php');

        foreach (['data-install-bar', 'data-install-banner', 'data-install-platform', 'data-install-message', 'data-install-dismiss', 'data-install-steps'] as $hook) {
            $this->assertStringContainsString($hook, $layout, 'The app layout must expose the install hook ' . $hook . '.');
        }

        $this->assertStringContainsString('data-install-bar', $welcome, 'The welcome page must ship an install affordance.');
        $this->assertStringContainsString('Install ', $layout);
    }

    public function testAppScriptImplementsInstallStateBannerAndSelfCheck(): void
    {
        $script = $this->source('public/assets/js/app.js');

        foreach ([
            'beforeinstallprompt',
            'appinstalled',
            'mtaapass.install.dismissed',
            'display-mode: standalone',
            'navigator.standalone',
            'data-install',
            'data-install-dismiss',
            'In-app browser',
            'WhatsApp',
            'Instagram',
            'serviceWorker',
            'register(',
            'setInstallServiceWorkerState',
        ] as $needle) {
            $this->assertStringContainsString($needle, $script, 'app.js must implement install/PWA logic: ' . $needle);
        }

        $this->assertStringNotContainsString('onclick=', $script, 'No inline handlers may be introduced.');
        $this->assertStringNotContainsString('http://', $script, 'No absolute/insecure URLs may be introduced.');
    }

    public function testServiceWorkerIsVersionedWithSafeLifecycle(): void
    {
        $sw = $this->source('public/service-worker.js');

        // The owner bumps the cache version over time; assert the versioned
        // contract (vN) instead of pinning one release.
        $this->assertMatches("/var\\s+VERSION\\s*=\\s*'v\\d+'/", $sw, 'The service worker must expose a versioned VERSION constant.');
        $this->assertMatches('/mtaapass-shell-v\\d+/', $sw, 'The shell cache name must be versioned.');
        $this->assertMatches('/mtaapass-static-v\\d+/', $sw, 'The static cache name must be versioned.');

        foreach (['install', 'activate', 'fetch', 'SKIP_WAITING', 'LOGOUT', 'offline.html', 'offline-access.js', 'manifest.json', 'clients.claim', 'caches.delete'] as $needle) {
            $this->assertStringContainsString($needle, $sw, 'The service worker must keep: ' . $needle);
        }

        $this->assertStringContainsString('icons/icon-192.png', $sw);
        $this->assertStringContainsString('icons/icon-512.png', $sw);
        $this->assertStringNotContainsString('http://', $sw);
    }

    public function testServiceWorkerServingHeadersAreConfigured(): void
    {
        $htaccess = $this->source('public/.htaccess');

        $this->assertStringContainsString('<Files "service-worker.js">', $htaccess);
        $this->assertStringContainsString('Cache-Control "no-cache, max-age=0"', $htaccess);
        $this->assertStringContainsString('Service-Worker-Allowed "/"', $htaccess);
        $this->assertStringContainsString('<IfModule mod_headers.c>', $htaccess);
    }

    public function testAccountScreenReflectsInstalledState(): void
    {
        $account = $this->source('app/Views/app/account.php');

        $this->assertStringContainsString('data-install', $account, 'The account screen must expose the install card/self-check hooks.');
        $this->assertTrue(
            str_contains($account, 'Installed on this device') || str_contains($account, 'installed'),
            'The account install card must reflect the installed state.'
        );
    }
}
