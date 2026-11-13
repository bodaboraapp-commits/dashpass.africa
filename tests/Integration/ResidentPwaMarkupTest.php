<?php

declare(strict_types=1);

namespace Tests\Integration;

use DOMElement;

/**
 * Cross-cutting markup rules for the layout refresh (addendum §6, §7.6):
 * CSP-safe markup (no inline script/style/handlers, no external assets), exactly
 * one h1 per screen, labelled icon-only buttons, exactly one aria-current="page"
 * active item inside the bottom navigation, dark theme default, and root-relative
 * (never APP_URL-absolute) links.
 */
final class ResidentPwaMarkupTest extends ResidentPwaTestCase
{
    /**
     * @return array<string, string> path => human label
     */
    private function screens(): array
    {
        return [
            '/app' => 'Home',
            '/app/visitors' => 'Visitors (Upcoming)',
            '/app/visitors?tab=history' => 'Visitors (History)',
            '/app/bookings' => 'Bookings (Facilities)',
            '/app/bookings?tab=bookings' => 'Bookings (My Bookings)',
        ];
    }

    public function testEveryScreenIsCspSafeAndHasOneH1(): void
    {
        $this->loginResident();

        foreach ($this->screens() as $path => $label) {
            $response = $this->get($path);
            $this->assertStatus(200, $response, $label . ' must render for an authenticated resident.');

            $violations = $this->markupViolations($response);
            $this->assertCount(
                0,
                $violations,
                $label . ' has CSP-incompatible markup: ' . implode('; ', $violations)
            );

            $h1s = $this->xpath('//h1', $this->dom($response));
            $this->assertCount(1, $h1s, $label . ' must render exactly one h1.');
        }
    }

    public function testBottomNavHasOneAriaCurrentActiveItem(): void
    {
        $this->loginResident();

        foreach ($this->screens() as $path => $label) {
            $response = $this->get($path);
            $nav = $this->bottomNav($response);
            $this->assertNotNull($nav, $label . ' must render the bottom navigation.');
            $this->assertSame('Primary', $nav->getAttribute('aria-label'));

            $slots = $this->navSlots($response);
            $this->assertCount(5, $slots, $label . ' must keep the five-slot bottom navigation.');

            $active = $this->xpath('.//*[@aria-current]', $nav);
            $this->assertCount(1, $active, $label . ' must mark exactly one active bottom-nav slot.');

            $activeNode = $active->item(0);
            $this->assertNotNull($activeNode);
            $this->assertSame('page', strtolower($activeNode->getAttribute('aria-current')));
            $this->assertContains('Home', $this->text($activeNode));
        }
    }

    public function testIconOnlyButtonsAreLabelled(): void
    {
        $this->loginResident();

        foreach ($this->screens() as $path => $label) {
            $response = $this->get($path);
            $domain = $this->dom($response);
            $unlabelled = [];

            foreach ($this->xpath('//button', $domain) as $button) {
                if (!$button instanceof DOMElement) {
                    continue;
                }

                if ($this->text($button) !== '') {
                    continue;
                }

                $name = trim($button->getAttribute('aria-label') . ' ' . $button->getAttribute('title'));

                if ($name === '') {
                    $unlabelled[] = '<button class="' . $button->getAttribute('class') . '">';
                }
            }

            $this->assertCount(
                0,
                $unlabelled,
                $label . ' has icon-only buttons without an accessible name: ' . implode(', ', $unlabelled)
            );
        }
    }

    public function testResidentScreensUseAConfigurableThemeDefault(): void
    {
        // Owner UI change: the resident "Appearance" card/dark-default pin was removed;
        // screens now honour the system/estate theme default and expose the toggle.
        $this->loginResident();

        foreach (['/app', '/app/visitors', '/app/bookings'] as $path) {
            $response = $this->get($path);
            $this->assertStatus(200, $response, $path . ' must render for a resident.');
            $html = $this->xpath('//html', $this->dom($response));
            $this->assertCount(1, $html);
            $htmlNode = $html->item(0);
            $this->assertNotNull($htmlNode);

            $themeDefault = (string) $htmlNode->getAttribute('data-theme-default');
            $this->assertTrue(
                in_array($themeDefault, ['', 'default'], true),
                $path . ' must not force the removed dark default (got "' . $themeDefault . '").'
            );
        }

        // The theme toggle remains available for users to opt into dark.
        $account = $this->get('/app/account');
        $this->assertStatus(200, $account);
        $this->assertTrue($this->xpath('//*[@data-theme-toggle]', $this->dom($account))->length >= 1, 'The account screen keeps the theme toggle.');

        $css = (string) @file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertTrue($css !== '', 'public/assets/css/app.css must exist.');
        $lowerCss = strtolower($css);

        // The approved dark palette remains available for the toggle.
        foreach (['#070b16', '#0b1222', '#0e1526', '#141d33', '#1a2440', '#f4f6ff', '#98a4c2', '#2e4bff', '#35c77a', '#f4b942', '#ff6b6b'] as $token) {
            $this->assertContains($token, $lowerCss, 'The approved dark-theme token ' . $token . ' must be present in app.css.');
        }

        $this->assertContains('rgba(46, 75, 255, 0.16)', $lowerCss, 'The accent-soft token must match addendum ?5.');
        $this->assertContains('--radius-lg: 22px', $css, 'The addendum ?5 large radius token is 22px.');
        $this->assertContains('--radius-md: 16px', $css, 'The addendum ?5 medium radius token is 16px.');
    }
    public function testNoAbsoluteAppUrlLinks(): void
    {
        $this->loginResident();

        $absolute = [
            rtrim((string) config('app.url'), '/'),
            'http://localhost:8000',
            'http://127.0.0.1:8010',
        ];

        foreach (['/app', '/app/visitors', '/app/bookings'] as $path) {
            $response = $this->get($path);

            foreach ($absolute as $origin) {
                if ($origin === '') {
                    continue;
                }

                $this->assertBodyNotContains($origin, $response, $path . ' must use root-relative URLs, never the APP_URL origin.');
            }
        }
    }

    public function testScreensHaveVisibleFocusSupportAndTouchTargetTokens(): void
    {
        $css = (string) @file_get_contents(BASE_PATH . '/public/assets/css/app.css');

        $this->assertContains(':focus-visible', $css, 'Interactive elements must keep visible focus rings.');
        $this->assertContains('prefers-reduced-motion', $css, 'Reduced-motion support must be preserved.');
        $this->assertContains('forced-colors', $css, 'Forced-colors support must be preserved.');
        $this->assertContains('min-height: 44px', $css, 'Touch targets must stay at least 44px.');
    }

    public function testResidentThemeScopeKeepsTheStatusBarInAppColour(): void
    {
        $this->loginResident();
        $response = $this->get('/app');
        $html = $this->dom($response)->documentElement;
        $this->assertNotNull($html);
        $this->assertSame('resident', $html->getAttribute('data-theme-scope'), 'Resident screens declare the resident theme scope.');

        $themeJs = (string) file_get_contents(BASE_PATH . '/public/assets/js/theme.js');
        $this->assertStringContainsString('prefers-color-scheme', $themeJs, 'Theme resolution reads the OS preference.');
        $this->assertStringContainsString("data-theme-scope') === 'resident'", $themeJs, 'Resident scope is honoured by theme.js.');
        $osPos = strpos($themeJs, 'prefers-color-scheme');
        $estatePos = strpos($themeJs, 'estate default is only used');
        $this->assertTrue($osPos !== false && $estatePos !== false && $osPos < $estatePos, 'OS/device preference is resolved before the estate fallback.');

        // The installed-PWA status/notification bar must use the resident app
        // colour even when the phone is in light mode: the resident app paints
        // dark by default and every theme-color meta is kept in sync with it.
        $this->assertMatchesRegularExpression(
            "/if \\(residentScope\\(\\)\\) \\{\\s+return 'dark';/",
            $themeJs,
            'Resident screens default to dark regardless of the OS light mode.'
        );
        $this->assertStringContainsString("querySelectorAll('meta[name=\"theme-color\"]')", $themeJs);
        $this->assertStringContainsString("'#070B16'", $themeJs);
        $this->assertStringContainsString("'#F4F6FB'", $themeJs);

        $css = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertStringContainsString('.app-body::before', $css, 'The safe-area strip under a translucent PWA status bar must be painted.');
        $this->assertStringContainsString('env(safe-area-inset-top, 0px)', $css);
    }
}
