<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;
use Database\Seeds\DemoBillingScheduleSeeder;
use Database\Seeds\DemoNoticeSeeder;
use Database\Seeds\DemoStaffIncidentSeeder;
use Database\Seeds\DemoVisitorSeeder;
use DOMElement;

/**
 * Resident back-navigation chevrons (owner request: "ensure the chevrons are
 * available and functional" in the resident app navigation).
 *
 * The resident PWA uses one convention for back affordances: a plain anchor in
 * the screen-bar header with `screen-bar__action`, an accessible name starting
 * with "Back", and the `chevron-left` icon partial (which appends the
 * `ico--chevron` marker and a left-pointing path). No `history.back()`, no
 * `javascript:` href, no text-only "Back" link.
 *
 * Matrix coverage: every pushed resident screen renders exactly one back
 * control, pointing at its logical parent, and that parent itself resolves for
 * the signed-in resident (so the chevron is functional, not decorative).
 *
 * Root screens (Home `/app`, Account `/app/account`) render no back control.
 * WayFinder `/app/navigation` is a pushed screen and carries the same header
 * chevron back to `/app` (owner task-18 navigation polish); it also keeps its
 * explicit "Back to Home" button in the help card.
 *
 * The `.screen-bar__action` CSS target (44x44 with a focus-visible ring) is
 * asserted statically so a future CSS change cannot silently shrink the target;
 * this test file is verification only and is not a product fix.
 */
final class ResidentNavigationChevronTest extends ResidentPwaTestCase
{
    /**
     * Every pushed resident screen: URL => logical parent URL.
     *
     * @return array<int, array{path: string, parent: string, label: string}>
     */
    private function pushedScreens(): array
    {
        return [
            ['path' => '/app/activity', 'parent' => '/app', 'label' => 'Activity'],
            ['path' => '/app/access', 'parent' => '/app', 'label' => 'My access pass'],
            ['path' => '/app/visitors', 'parent' => '/app', 'label' => 'Visitors'],
            ['path' => '/app/visitors/invite', 'parent' => '/app/visitors', 'label' => 'Invite a visitor'],
            [
                'path' => '/app/passes/' . DemoVisitorSeeder::INVITATION_UPCOMING_UUID,
                'parent' => '/app/visitors',
                'label' => 'Visitor pass',
            ],
            ['path' => '/app/notices', 'parent' => '/app', 'label' => 'Notices'],
            [
                'path' => '/app/notices/' . DemoNoticeSeeder::NOTICE_WATER_UUID,
                'parent' => '/app/notices',
                'label' => 'Estate notice detail',
            ],
            ['path' => '/app/estates', 'parent' => '/app', 'label' => 'Your estates'],
            ['path' => '/app/vehicles', 'parent' => '/app', 'label' => 'My vehicles'],
            ['path' => '/app/parking', 'parent' => '/app', 'label' => 'My parking'],
            ['path' => '/app/bookings', 'parent' => '/app', 'label' => 'Bookings'],
            ['path' => '/app/staff', 'parent' => '/app/account', 'label' => 'Household staff'],
            ['path' => '/app/incidents', 'parent' => '/app/account', 'label' => 'Estate incidents'],
            ['path' => '/app/incidents/new', 'parent' => '/app/incidents', 'label' => 'Report an incident'],
            [
                'path' => '/app/incidents/' . DemoStaffIncidentSeeder::INCIDENT_LOST_PASS_UUID,
                'parent' => '/app/incidents',
                'label' => 'Incident detail',
            ],
            ['path' => '/app/bills', 'parent' => '/app', 'label' => 'My bills'],
            [
                'path' => '/app/bills/' . DemoBillingScheduleSeeder::UTILITY_INVOICE_UUID,
                'parent' => '/app/bills',
                'label' => 'Bill detail',
            ],
            // Owner request (task-18): WayFinder is a pushed screen and gets the
            // same header back chevron as every other resident app page.
            ['path' => '/app/navigation', 'parent' => '/app', 'label' => 'WayFinder'],
        ];
    }

    /**
     * Anchors in a screen-bar header whose accessible name marks them as a back
     * control. Used both to count back controls and to validate their markup.
     *
     * @return array<int, DOMElement>
     */
    private function headerBackControls(Response $response): array
    {
        $selector = '//header[contains(concat(" ", normalize-space(@class), " "), " screen-bar ")]'
            . '//a[contains(concat(" ", normalize-space(@class), " "), " screen-bar__action ")]';

        $controls = [];

        foreach ($this->xpath($selector, $this->dom($response)) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $label = trim($node->getAttribute('aria-label'));

            if ($label === '' || stripos($label, 'back') !== 0) {
                continue;
            }

            $controls[] = $node;
        }

        return $controls;
    }

    /**
     * The icon partial appends `ico--chevron` to `chevron-left`; the left
     * direction is carried by the Material-style path `m14.4 6-6 6 6 6`.
     */
    private function isChevronLeftIcon(DOMElement $svg): bool
    {
        $classes = preg_split('/\s+/', trim($svg->getAttribute('class'))) ?: [];

        if (
            !in_array('ico--chevron', $classes, true)
            && !in_array('ico--chevron-left', $classes, true)
        ) {
            return false;
        }

        foreach ($this->xpath('.//path', $svg) as $path) {
            if (!$path instanceof DOMElement) {
                continue;
            }

            $data = strtolower(trim($path->getAttribute('d')));

            if (str_starts_with($data, 'm14.4') || str_starts_with($data, 'm 14.4')) {
                return true;
            }
        }

        return false;
    }

    public function testEveryPushedResidentScreenHasOneFunctionalBackChevron(): void
    {
        $this->loginResident();

        $resolvedParents = [];

        foreach ($this->pushedScreens() as $screen) {
            $path = $screen['path'];
            $label = $screen['label'];

            $response = $this->get($path);
            $this->assertStatus(200, $response, $label . ' (' . $path . ') must render for an active resident.');

            $backControls = $this->headerBackControls($response);
            $this->assertCount(
                1,
                $backControls,
                $label . ' (' . $path . ') must render exactly one screen-bar back control.'
            );

            $anchor = $backControls[0];

            $this->assertSame('a', strtolower($anchor->tagName), $label . ' back control must be a plain anchor.');

            $href = trim($anchor->getAttribute('href'));
            $this->assertSame($screen['parent'], $href, $label . ' back control must point at its logical parent.');
            $this->assertStringStartsWith('/', $href, $label . ' back control must use a root-relative href.');
            $this->assertFalse(
                str_starts_with(strtolower($href), 'javascript:'),
                $label . ' back control must never use a javascript: href.'
            );
            $this->assertFalse(
                str_contains(strtolower($href), 'history.back'),
                $label . ' back control must never use history.back().'
            );

            $accessibleName = trim($anchor->getAttribute('aria-label'));
            $this->assertTrue(
                $accessibleName !== '',
                $label . ' back control must have a non-empty aria-label.'
            );
            $this->assertSame(
                $accessibleName,
                trim($anchor->getAttribute('title')),
                $label . ' back control should keep title in sync with its aria-label.'
            );

            foreach ($anchor->attributes as $attribute) {
                $this->assertFalse(
                    str_starts_with(strtolower($attribute->name), 'on'),
                    $label . ' back control must not carry inline event handlers.'
                );
            }

            $icons = $this->xpath('.//svg', $anchor);
            $this->assertCount(1, $icons, $label . ' back control must contain exactly one icon.');

            $icon = $icons->item(0);
            $this->assertInstanceOf(DOMElement::class, $icon);
            $this->assertTrue(
                $this->isChevronLeftIcon($icon),
                $label . ' back control must render the left-pointing chevron-left icon.'
            );
            $this->assertSame(
                'true',
                strtolower(trim($icon->getAttribute('aria-hidden'))),
                $label . ' back chevron must stay decorative (aria-hidden), labelled by the anchor.'
            );
            $this->assertSame(
                'false',
                strtolower(trim($icon->getAttribute('focusable'))),
                $label . ' back chevron must not be keyboard-focusable.'
            );

            // The bar keeps its pushed 3-slot layout (back / centred title /
            // trailing action or spacer) so the title does not shift between
            // screens.
            $header = $anchor->parentNode;
            $this->assertInstanceOf(DOMElement::class, $header);
            $slots = $this->xpath('./*', $header);
            $this->assertCount(
                3,
                $slots,
                $label . ' pushed screen-bar must keep the 3-slot layout (back chevron / title / trailing slot).'
            );

            $firstSlot = $slots->item(0);
            $this->assertTrue(
                $firstSlot instanceof DOMElement && $firstSlot->isSameNode($anchor),
                $label . ' back chevron must be the leading slot of the screen-bar.'
            );

            $titleSlot = $slots->item(1);
            $this->assertInstanceOf(DOMElement::class, $titleSlot);
            $this->assertSame('h1', strtolower($titleSlot->tagName), $label . ' screen-bar must keep the page h1 in its centre slot.');
            $this->assertContains(
                'screen-bar__title',
                preg_split('/\s+/', trim($titleSlot->getAttribute('class'))) ?: [],
                $label . ' screen-bar h1 must keep the screen-bar__title class.'
            );

            // Functional check: the target of the chevron itself renders for
            // the same resident (200 — a redirect would mean the chevron is a
            // dead end because this is a plain anchor, not JS history).
            if (!isset($resolvedParents[$screen['parent']])) {
                $parentResponse = $this->get($screen['parent']);
                $this->assertStatus(
                    200,
                    $parentResponse,
                    'The back target ' . $screen['parent'] . ' for ' . $label . ' must resolve for the resident.'
                );
                $resolvedParents[$screen['parent']] = true;
            }
        }

        $this->assertCount(
            6,
            $resolvedParents,
            'The matrix must resolve every distinct back target '
                . '(/app, /app/account, /app/visitors, /app/notices, /app/incidents, /app/bills).'
        );
    }

    public function testRootScreensHaveNoBackChevron(): void
    {
        $this->loginResident();

        $rootScreens = [
            '/app' => 'Home',
            '/app/account' => 'Account',
        ];

        foreach ($rootScreens as $path => $label) {
            $response = $this->get($path);
            $this->assertStatus(200, $response, $label . ' (' . $path . ') must render for an active resident.');
            $this->assertCount(
                0,
                $this->headerBackControls($response),
                $label . ' (' . $path . ') is a root screen and must not render a back chevron.'
            );
        }

        // WayFinder additionally keeps its long-standing explicit "Back to Home"
        // button in the help card (now alongside the header chevron).
        $wayfinder = $this->get('/app/navigation');
        $explicitBack = $this->xpath(
            '//a[@href="/app"][contains(normalize-space(.), "Back to Home")]',
            $this->dom($wayfinder)
        );
        $this->assertTrue(
            $explicitBack->length >= 1,
            'WayFinder must keep its explicit "Back to Home" link too.'
        );
    }

    public function testEveryPushedResidentViewShipsChevronMarkupNotTextOrScriptedBackLinks(): void
    {
        $files = glob(BASE_PATH . '/app/Views/app/*.php') ?: [];
        $this->assertNotEmpty($files, 'Resident views must exist under app/Views/app.');

        // Root screens intentionally have no back control; every other resident
        // view that renders a screen-bar header must use the chevron-left icon.
        $rootScreens = ['home.php', 'account.php', 'no-estate.php'];

        $textOnlyBackLinks = [];
        $scriptedBackLinks = [];
        $deadBackLinks = [];
        $missingChevron = [];

        foreach ($files as $file) {
            $name = basename($file);
            $contents = (string) file_get_contents($file);

            if (preg_match(
                '/<a\b[^>]*\bscreen-bar__action\b[^>]*>\s*(?:Back|&larr;|←)\s*<\/a>/i',
                $contents
            ) === 1) {
                $textOnlyBackLinks[] = $name;
            }

            if (preg_match('/<a\b[^>]*\bscreen-bar__action\b[^>]*href="#"/i', $contents) === 1) {
                $deadBackLinks[] = $name;
            }

            if (stripos($contents, 'history.back') !== false || stripos($contents, 'javascript:') !== false) {
                $scriptedBackLinks[] = $name;
            }

            if (
                !in_array($name, $rootScreens, true)
                && str_contains($contents, 'class="screen-bar')
                && !str_contains($contents, 'chevron-left')
            ) {
                $missingChevron[] = $name;
            }
        }

        $this->assertCount(
            0,
            $textOnlyBackLinks,
            'Resident screen-bar back links must use the chevron icon, not text-only "Back": '
                . implode(', ', $textOnlyBackLinks)
        );
        $this->assertCount(
            0,
            $scriptedBackLinks,
            'Resident views must not use javascript: or history.back() for back navigation: '
                . implode(', ', $scriptedBackLinks)
        );
        $this->assertCount(
            0,
            $deadBackLinks,
            'Resident screen-bar actions must not ship href="#": ' . implode(', ', $deadBackLinks)
        );
        $this->assertCount(
            0,
            $missingChevron,
            'Every pushed resident screen-bar view must render the chevron-left icon: '
                . implode(', ', $missingChevron)
        );

        // The shared `.screen-bar__action` target keeps the 44px minimum and a
        // visible focus ring (CSS is owned by another task; asserted read-only).
        $css = (string) @file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $this->assertNotEmpty($css, 'public/assets/css/app.css must exist.');
        $this->assertMatchesRegularExpression(
            '/\.screen-bar__action\s*\{[^}]*\bwidth:\s*44px/s',
            $css,
            'Back-chevron/action targets must stay at least 44px wide.'
        );
        $this->assertMatchesRegularExpression(
            '/\.screen-bar__action\s*\{[^}]*\bheight:\s*44px/s',
            $css,
            'Back-chevron/action targets must stay at least 44px tall.'
        );
        $this->assertMatchesRegularExpression(
            '/\.screen-bar__action:focus-visible\s*\{[^}]*box-shadow/s',
            $css,
            'Back-chevron/action targets must keep a visible focus-visible ring.'
        );
    }
}
