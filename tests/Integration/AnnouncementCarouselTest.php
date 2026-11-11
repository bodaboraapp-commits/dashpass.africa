<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\View;

/**
 * T173 announcements carousel — automated coverage (replaces the absent T173 E2E
 * harness): rendered markup (real + clone tracks, aria/tab-order, static 1–2 card
 * state), the JS auto-scroll behaviour source (right→left, pause, IO,
 * reduced-motion) and the no-page-overflow CSS contract.
 */
final class AnnouncementCarouselTest extends ResidentPwaTestCase
{
    /** @return array<string, mixed> */
    private function minimalHomeData(array $announcements): array
    {
        return [
            'title' => 'Home',
            'user' => ['full_name' => 'Jane Smith', 'phone' => '+254700000010', 'email' => null],
            'estate' => ['estate_name' => 'Greenview Residences', 'role_name' => 'resident'],
            'memberships' => [],
            'activeNav' => 'home',
            'csrfToken' => str_repeat('a', 64),
            'appVersion' => '0.1.0-foundation',
            'isDev' => true,
            'flashError' => null,
            'flashSuccess' => null,
            'permissions' => [],
            'dashboard' => [
                'estate' => ['estate_name' => 'Greenview Residences', 'role_name' => 'resident'],
                'households' => [],
                'gates' => [],
                'memberships' => [],
            ],
            'preview' => true,
            'announcements' => $announcements,
        ];
    }

    private function source(string $relative): string
    {
        $path = BASE_PATH . '/' . ltrim($relative, '/');
        $source = (string) @file_get_contents($path);
        $this->assertTrue($source !== '', $relative . ' must exist.');

        return $source;
    }

    // ---------------------------------------------------------------------
    // Rendered markup
    // ---------------------------------------------------------------------

    public function testThreeRealNoticesRenderTheSeamlessCarousel(): void
    {
        $this->loginResident();
        $response = $this->get('/app');
        $this->assertStatus(200, $response);
        $this->assertCleanMarkup($response, 'Home carousel');

        $dom = $this->dom($response);

        $carousel = $this->xpath('//*[@data-announce-carousel]', $dom);
        $this->assertCount(1, $carousel, 'Home must render exactly one announcements carousel.');

        $node = $carousel->item(0);
        $this->assertNotNull($node);
        $this->assertStringContainsString('announce-carousel--auto', $node->getAttribute('class'));
        $this->assertSame('3', $node->getAttribute('data-announce-count'));
        $this->assertSame('true', $node->getAttribute('data-announce-animate'));
        $this->assertSame('32', $node->getAttribute('data-announce-duration'));
        $this->assertSame('carousel', $node->getAttribute('aria-roledescription'));
        $this->assertSame('Announcements', $node->getAttribute('aria-label'));
        $this->assertSame('0', $node->getAttribute('tabindex'));

        $tracks = $this->xpath('//*[@data-announce-carousel]//div[contains(@class,"announce-track")]', $dom);
        $this->assertCount(2, $tracks, 'A >2-card carousel has the real track and a clone track.');

        $realCards = $this->xpath('//*[@data-announce-track][not(contains(@class,"announce-track--clone"))]/article[contains(@class,"announce-card--real")]', $dom);
        $this->assertCount(3, $realCards, 'The visible track shows the three real notices.');

        $clone = $this->xpath('//*[@data-announce-carousel]//div[contains(@class,"announce-track--clone")]', $dom);
        $this->assertCount(1, $clone);
        $cloneNode = $clone->item(0);
        $this->assertNotNull($cloneNode);
        $this->assertSame('true', strtolower($cloneNode->getAttribute('aria-hidden')), 'The clone track is aria-hidden.');
        $this->assertCount(3, $this->xpath('//*[@data-announce-clone]//article[contains(@class,"announce-card")]', $dom));

        // Real cards keep their detail links in the tab order; clone links do not.
        $realLinks = $this->xpath('//*[@data-announce-track][not(contains(@class,"announce-track--clone"))]//a[contains(@class,"announce-card__link")]', $dom);
        $this->assertCount(3, $realLinks);
        foreach ($realLinks as $link) {
            $this->assertSame('', $link->getAttribute('tabindex'), 'Real notice links stay focusable.');
            $this->assertMatches('#^/app/notices/[0-9a-f-]{36}$#', (string) $link->getAttribute('href'));
        }

        $cloneLinks = $this->xpath('//*[@data-announce-clone]//a[contains(@class,"announce-card__link")]', $dom);
        $this->assertCount(3, $cloneLinks);
        foreach ($cloneLinks as $link) {
            $this->assertSame('-1', $link->getAttribute('tabindex'), 'Clone links are removed from the tab order.');
        }

        foreach ($this->xpath('//*[@data-announce-clone]//article', $dom) as $cloneCard) {
            $this->assertSame('true', $cloneCard->getAttribute('data-announce-clone-card'));
        }

        // Pagination dots are gone.
        $this->assertCount(0, $this->xpath('//*[contains(@class,"announcement-dots")]', $dom));
    }

    public function testOneAndTwoCardsRenderAStaticScrollRow(): void
    {
        $two = View::capture('app/home', $this->minimalHomeData([
            ['title' => 'A', 'body' => 'Demo notice A'],
            ['title' => 'B', 'body' => 'Demo notice B'],
        ]));
        $this->assertStringContainsString('announce-carousel--static', $two);
        $this->assertStringContainsString('data-announce-animate="false"', $two);
        $this->assertStringContainsString('data-announce-count="2"', $two);
        $this->assertStringNotContainsString('announce-track--clone', $two);

        $one = View::capture('app/home', $this->minimalHomeData([
            ['title' => 'A', 'body' => 'Demo notice A'],
        ]));
        $this->assertStringContainsString('announce-carousel--static', $one);
        $this->assertStringContainsString('data-announce-count="1"', $one);
        $this->assertStringNotContainsString('announce-track--clone', $one);

        $none = View::capture('app/home', $this->minimalHomeData([]));
        $this->assertStringNotContainsString('announce-carousel', $none);
        $this->assertStringContainsString('No estate notices yet', $none);
    }

    public function testCarouselMarkupCarriesNoInlineHandlersOrStyles(): void
    {
        $this->loginResident();
        $body = $this->get('/app')->body();

        $this->assertStringNotContainsString('onclick=', $body);
        $this->assertStringNotContainsString('onscroll=', $body);
        $this->assertStringNotContainsString('onmouseover=', $body);
        $this->assertStringNotContainsString('style="', substr($body, (int) strpos($body, 'data-announce-carousel')));
    }

    // ---------------------------------------------------------------------
    // JS behaviour source
    // ---------------------------------------------------------------------

    public function testCarouselScriptImplementsRightToLeftAutoScrollAndPause(): void
    {
        $script = $this->source('public/assets/js/app.js');

        foreach ([
            'function initAnnouncements()',
            "[data-announce-carousel]",
            'data-announce-animate',
            'data-announce-duration',
            '[data-announce-track]',
            'requestAnimationFrame(step)',
            'root.scrollLeft +=',
            'halfWidth = function',
            'root.scrollWidth > root.clientWidth',
            'mouseenter',
            'mouseleave',
            'focusin',
            'focusout',
            'touchstart',
            'touchend',
            'pointerdown',
            'pointerup',
            "'ArrowRight'",
            "'ArrowLeft'",
            "'IntersectionObserver' in window",
            'observer.observe(root)',
        ] as $needle) {
            $this->assertStringContainsString($needle, $script, 'app.js carousel must implement: ' . $needle);
        }

        // Right→left: scrollLeft increases over time on the LTR page.
        $this->assertMatches('/root\.scrollLeft \+= \(width \/ duration\)/', $script);

        // Pause state machine: hover/focus/touch gate the animation frame.
        $this->assertStringContainsString('!hovering && !focusing && !touching && visible && playable()', $script);
    }

    public function testCarouselRespectsReducedMotion(): void
    {
        $script = $this->source('public/assets/js/app.js');
        $css = $this->source('public/assets/css/app.css');

        $this->assertStringContainsString("window.matchMedia('(prefers-reduced-motion: reduce)')", $script);
        $this->assertStringContainsString('if (!track || !animate || reduceMotion)', $script, 'Reduced-motion users skip the JS auto-scroll entirely.');
        $this->assertMatches('/@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*\.announce-carousel/s', $css);
    }

    // ---------------------------------------------------------------------
    // CSS: no page overflow
    // ---------------------------------------------------------------------

    public function testCarouselCssConfinesOverflowToTheRow(): void
    {
        $css = $this->source('public/assets/css/app.css');

        $this->assertMatches(
            '/\.announce-carousel\s*\{[^}]*overflow-x:\s*auto/s',
            $css,
            'The carousel itself must scroll horizontally.'
        );
        $this->assertMatches('/\.announce-carousel\s*\{[^}]*max-width:\s*100%/s', $css);
        $this->assertMatches('/\.announce-carousel\s*\{[^}]*overscroll-behavior-x:\s*contain/s', $css);
        $this->assertMatches('/\.announce-carousel\s*\{[^}]*scroll-snap-type:\s*x/s', $css);

        // Cards are non-shrinking fixed-ish width tracks.
        $this->assertMatches('/\.announce-carousel \.announce-card\s*\{[^}]*flex:\s*0 0 auto/s', $css);
        $this->assertMatches('/\.announce-carousel \.announce-card\s*\{[^}]*width:\s*clamp\(/s', $css);

        // Page-level overflow must not be hidden to mask a layout bug.
        $this->assertDoesNotMatch('/html\s*\{[^}]*overflow-x:\s*hidden/s', $css);
        $this->assertDoesNotMatch('/body\s*\{[^}]*overflow-x:\s*hidden/s', $css);
    }

    public function testViewNoLongerRendersPaginationDots(): void
    {
        $view = $this->source('app/Views/app/home.php');

        $this->assertStringNotContainsString('announcement-dots', $view, 'Pagination dots were replaced by the carousel.');
        $this->assertStringContainsString('data-announce-carousel', $view);
        $this->assertStringContainsString('data-announce-clone-card', $view);
        $this->assertStringContainsString('aria-hidden="true"', $view);
        $this->assertStringContainsString('tabindex="-1"', $view);
    }
}
