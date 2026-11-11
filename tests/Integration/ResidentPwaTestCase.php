<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Response;
use DOMElement;

/**
 * Shared helpers for the resident PWA layout-refresh tests
 * (docs/resident-pwa-layout-addendum.md §7).
 *
 * Structural assertions parse the rendered HTML with DOMDocument/XPath, so they
 * check the real markup hooks the views ship (quick-tile grid, segmented tabs,
 * bottom tabbar, QR dialog) without depending on whitespace or attribute order.
 */
abstract class ResidentPwaTestCase extends IntegrationTestCase
{
    protected const JANE = '+254700000010';  // resident @ Greenview Residences
    protected const GRACE = '+254700000001'; // estate_admin, two estates
    protected const AMINA = '+254700000013'; // resident, household B-001 (no visitor invitations)

    /** @var array<int, string> Addendum §4 preview rows. */
    protected const UPCOMING_NAMES = ['James Mwangi', 'Robert Brown', 'Amanda Lee'];

    /** @var array<int, string> */
    protected const HISTORY_NAMES = ['Grace Njeri', 'Samuel Otieno'];

    /** @var array<int, string> Addendum §4 facility cards. */
    protected const FACILITIES = ['Function Room', 'BBQ Area', 'Gym Room', 'Badminton Court'];

    protected function loginResident(): void
    {
        $this->loginAs(self::JANE);
    }

    /**
     * Run a callback with the preview-content flag forced on or off, restoring
     * the original configuration value afterwards.
     */
    protected function withPreview(bool $enabled, callable $callback): mixed
    {
        $original = Config::get('ui.resident_pwa.preview_content', true);
        Config::set('ui.resident_pwa.preview_content', $enabled);

        try {
            return $callback();
        } finally {
            Config::set('ui.resident_pwa.preview_content', $original);
        }
    }

    /**
     * The six-tile quick-action grid (addendum §2A).
     */
    protected function quickActionGrid(Response $response): ?DOMElement
    {
        foreach ($this->xpath('//section[@aria-label="Quick actions"]', $this->dom($response)) as $section) {
            if ($section instanceof DOMElement) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Interactive tiles inside the quick-action grid, in document order.
     *
     * @return array<int, DOMElement>
     */
    protected function quickActionTiles(Response $response): array
    {
        $grid = $this->quickActionGrid($response);

        if ($grid === null) {
            return [];
        }

        $tiles = [];

        foreach ($this->xpath('.//a[contains(@class,"quick-tile")] | .//button[contains(@class,"quick-tile")]', $grid) as $tile) {
            if ($tile instanceof DOMElement) {
                $tiles[] = $tile;
            }
        }

        return $tiles;
    }

    /**
     * The primary (bottom) navigation bar (`nav.tabbar[aria-label="Primary"]`).
     */
    protected function bottomNav(Response $response): ?DOMElement
    {
        foreach ($this->xpath('//nav[contains(@class,"tabbar")]', $this->dom($response)) as $nav) {
            if ($nav instanceof DOMElement) {
                return $nav;
            }
        }

        return null;
    }

    /**
     * Direct interactive slots of the bottom navigation, in order.
     *
     * @return array<int, DOMElement>
     */
    protected function navSlots(Response $response): array
    {
        $nav = $this->bottomNav($response);

        if ($nav === null) {
            return [];
        }

        $slots = [];

        foreach ($this->xpath('./*[self::a or self::button]', $nav) as $slot) {
            if ($slot instanceof DOMElement) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * The segmented tab strip (`div.seg-tabs`) on the pushed list screens.
     */
    protected function segmentTabs(Response $response): ?DOMElement
    {
        foreach ($this->xpath('//div[contains(@class,"seg-tabs")]', $this->dom($response)) as $tabs) {
            if ($tabs instanceof DOMElement) {
                return $tabs;
            }
        }

        return null;
    }

    /**
     * The one segmented tab rendered as active.
     */
    protected function activeSegmentTab(Response $response): ?DOMElement
    {
        $tabs = $this->segmentTabs($response);

        if ($tabs === null) {
            return null;
        }

        foreach ($this->xpath('.//a[contains(@class,"seg-tab--active")]', $tabs) as $tab) {
            if ($tab instanceof DOMElement) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * The QR sheet dialog element, if present.
     */
    protected function qrDialog(Response $response): ?DOMElement
    {
        foreach ($this->xpath('//*[@role="dialog"]', $this->dom($response)) as $node) {
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Text of the first element matching an XPath relative to a document/root.
     */
    protected function nodeText(\DOMDocument|DOMElement $root, string $selector): string
    {
        foreach ($this->xpath($selector, $root) as $node) {
            if ($node instanceof DOMElement) {
                return $this->text($node);
            }
        }

        return '';
    }
}
