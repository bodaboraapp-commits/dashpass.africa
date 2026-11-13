<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Two-month date-range calendar contract.
 *
 * The range picker is progressive enhancement over native date / datetime-local
 * inputs: public/assets/js/date-range.js replaces the per-field browser pickers
 * with one two-month range calendar, while the native inputs stay in the form so
 * filters and forms keep working with JavaScript disabled. These assertions are
 * static (source-level) so they run fast and independent of the seeded database.
 */
final class DateRangePickerTest extends ResidentPwaTestCase
{
    /** Views that own a date / datetime range, with the expected group name. */
    private const RANGE_VIEWS = [
        'app/Views/admin/billing.php' => 'billing-filters',
        'app/Views/admin/billing-reports.php' => 'billing-report',
        'app/Views/platform/billing-invoice-form.php' => 'invoice-period',
        'app/Views/app/bookings.php' => 'availability-window',
        'app/Views/app/invite.php' => 'pass-validity',
    ];

    private function source(string $relative): string
    {
        $source = (string) @file_get_contents(BASE_PATH . '/' . ltrim($relative, '/'));
        $this->assertTrue($source !== '', $relative . ' must exist.');

        return $source;
    }

    public function testScriptImplementsTheTwoMonthRangeCalendar(): void
    {
        $script = $this->source('public/assets/js/date-range.js');

        foreach ([
            'date-range__popover',
            'date-range__months',
            'Two-month view',
            'input[data-date-range][data-date-range-part]',
            'data-date-range-part',
            'addMonths(this.view, 1)',
            'date-range__native',
            'date-range__trigger',
            'role',
            'aria-haspopup',
            'aria-expanded',
            'aria-modal',
            'aria-selected',
        ] as $needle) {
            $this->assertTrue(str_contains($script, $needle), 'date-range.js must implement "' . $needle . '".');
        }

        // Two months are rendered side by side, and month navigation moves both.
        $this->assertTrue(str_contains($script, "renderMonth(this.view)"), 'The picker must render the viewed month.');
        $this->assertTrue(str_contains($script, "renderMonth(addMonths(this.view, 1))"), 'The picker must render a second month.');

        // Keyboard support for a calendar grid.
        foreach (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', 'Escape'] as $key) {
            $this->assertTrue(str_contains($script, "'" . $key . "'"), 'The calendar must handle the ' . $key . ' key.');
        }
    }

    public function testScriptStaysCspSafeAndSelfHosted(): void
    {
        $script = $this->source('public/assets/js/date-range.js');

        $this->assertStringNotContainsString('eval(', $script);
        $this->assertStringNotContainsString('new Function(', $script);
        $this->assertStringNotContainsString('document.write', $script);
        $this->assertStringNotContainsString('innerHTML', $script);
        $this->assertStringNotContainsString('javascript:', $script);
        $this->assertStringNotContainsString('onclick=', $script);

        // Only allow the SVG namespace; no remote asset hosts.
        $this->assertSame(
            0,
            preg_match('/https?:\/\/(?!www\.w3\.org\/2000\/svg)/', $script),
            'date-range.js must not load remote resources.'
        );
    }

    public function testNativeInputsRemainTheNoJavaScriptFallback(): void
    {
        $script = $this->source('public/assets/js/date-range.js');

        // The enhancement writes back into the native inputs and fires the events
        // a server-rendered form / listener expects; it never removes them.
        $this->assertTrue(str_contains($script, 'this.fromInput.value'), 'The picker must write the start value into the native input.');
        $this->assertTrue(str_contains($script, 'this.toInput.value'), 'The picker must write the end value into the native input.');
        $this->assertTrue(str_contains($script, "new Event('input'"), 'Value changes must fire an input event.');
        $this->assertTrue(str_contains($script, "new Event('change'"), 'Value changes must fire a change event.');

        foreach (self::RANGE_VIEWS as $view => $group) {
            $markup = $this->source($view);
            $this->assertTrue(
                str_contains($markup, 'type="date"') || str_contains($markup, 'type="datetime-local"'),
                $view . ' must keep its native date input as the no-JS fallback.'
            );
            $this->assertTrue(str_contains($markup, 'data-date-range="' . $group . '"'), $view . ' must opt into the "' . $group . '" range picker.');
            $this->assertSame(1, substr_count($markup, 'data-date-range-part="from"'), $view . ' must mark exactly one range start.');
            $this->assertSame(1, substr_count($markup, 'data-date-range-part="to"'), $view . ' must mark exactly one range end.');
            $this->assertTrue(str_contains($markup, 'data-date-range-label='), $view . ' must provide the calendar dialog label.');
        }
    }

    public function testPickerScriptIsLoadedOnEverySurfaceThatUsesIt(): void
    {
        foreach (['app/Views/layouts/admin.php', 'app/Views/layouts/platform.php', 'app/Views/layouts/app.php'] as $layout) {
            $markup = $this->source($layout);
            $this->assertSame(
                1,
                substr_count($markup, "asset('js/date-range.js')"),
                $layout . ' must load the date-range picker exactly once.'
            );
            $this->assertTrue(
                (bool) preg_match('/<script src="<\?= e\(asset\(\'js\/date-range\.js\'\)\) \?>" defer><\/script>/', $markup),
                $layout . ' must load the picker as a deferred external script (no inline JS).'
            );
        }
    }

    public function testStylesProvideTheCalendarContract(): void
    {
        $css = $this->source('public/assets/css/app.css');

        foreach ([
            '.date-range__trigger',
            '.date-range__native',
            '.date-range__popover',
            '.date-range__months',
            '.date-range__month-title',
            '.date-range__day',
            '.date-range__day.is-today',
            '.date-range__day.is-in-range',
            '.date-range__day.is-start',
            '.date-range__day.is-end',
            '.date-range__foot',
            '.date-range__time',
        ] as $selector) {
            $this->assertTrue(str_contains($css, $selector), 'app.css must define ' . $selector . '.');
        }

        // Tokens, not hard-coded colours, so both themes stay consistent.
        $this->assertTrue(str_contains($css, 'background: var(--accent);') || str_contains($css, 'background: var(--accent-soft);'), 'Selected/range days must use accent tokens.');
        $this->assertTrue(str_contains($css, 'var(--sunshine)'), 'Today must be marked with the sunshine token.');

        // Responsive: the two months stack on phones.
        $this->assertTrue(
            (bool) preg_match('/@media \(max-width: 640px\)[\s\S]{0,400}\.date-range__months[\s\S]{0,120}grid-template-columns: minmax\(0, 1fr\)/', $css),
            'The two months must stack to a single column on narrow screens.'
        );
        $this->assertTrue(
            (bool) preg_match('/@media \(prefers-reduced-motion: reduce\)[\s\S]{0,300}\.date-range__trigger/', $css),
            'The picker must respect reduced motion.'
        );
    }
}
