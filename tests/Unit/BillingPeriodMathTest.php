<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Billing\Automation\AutomationPeriodCalculator;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Inc-13 P1 — UTC period arithmetic (`AutomationPeriodCalculator`).
 *
 * PARKED pre-write (qa-mail, build gate). The P1 slice has landed; fold into
 * tests/Unit/ and run `php tests/run.php --filter=BillingPeriod` (single runner).
 *
 * Frozen contract (docs/automated-billing-contract.md §3): period keys are UTC
 * (YYYYMM monthly, YYYYQn quarterly, YYYY annual, YYYY-Www ISO week, ONCE), month
 * start = 1st, month-end clamp/leap safe, next start advances exactly one period.
 */
final class BillingPeriodMathTest extends TestCase
{
    private function calculator(): AutomationPeriodCalculator
    {
        return new AutomationPeriodCalculator();
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public function testMonthlyPeriodKeyAndBounds(): void
    {
        $period = $this->calculator()->period('month', $this->utc('2026-11-15 12:34:56'));

        $this->assertSame('202611', $period['key']);
        $this->assertSame('2026-11-01 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 00:00:00', $period['end']->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $period['start']->getTimezone()->getName());
    }

    public function testMonthlyFebruaryIsLeapYearSafe(): void
    {
        $leap = $this->calculator()->period('month', $this->utc('2028-02-10'));
        $this->assertSame('202802', $leap['key']);
        $this->assertSame('2028-02-29 00:00:00', $leap['end']->format('Y-m-d H:i:s'));

        $nonLeap = $this->calculator()->period('month', $this->utc('2026-02-10'));
        $this->assertSame('202602', $nonLeap['key']);
        $this->assertSame('2026-02-28 00:00:00', $nonLeap['end']->format('Y-m-d H:i:s'));

        $thirty = $this->calculator()->period('month', $this->utc('2026-04-30'));
        $this->assertSame('202604', $thirty['key']);
        $this->assertSame('2026-04-30 00:00:00', $thirty['end']->format('Y-m-d H:i:s'));
    }

    public function testPeriodsAreComputedInUtcNotLocalTime(): void
    {
        // 2026-11-01 00:30 in EAT (+03) is still 2026-10-31 21:30 UTC → the UTC
        // period is October, per the frozen contract (§3: all dates UTC).
        $eat = new DateTimeImmutable('2026-11-01 00:30:00', new DateTimeZone('Africa/Nairobi'));
        $period = $this->calculator()->period('month', $eat);

        $this->assertSame('202610', $period['key']);
        $this->assertSame('2026-10-01 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $period['start']->getTimezone()->getName());
    }

    public function testQuarterlyPeriods(): void
    {
        foreach ([
            ['2026-01-15', '2026Q1', '2026-01-01', '2026-03-31'],
            ['2026-05-15', '2026Q2', '2026-04-01', '2026-06-30'],
            ['2026-09-30', '2026Q3', '2026-07-01', '2026-09-30'],
            ['2026-12-31', '2026Q4', '2026-10-01', '2026-12-31'],
        ] as [$reference, $key, $start, $end]) {
            $period = $this->calculator()->period('quarter', $this->utc($reference));

            $this->assertSame($key, $period['key']);
            $this->assertSame($start . ' 00:00:00', $period['start']->format('Y-m-d H:i:s'));
            $this->assertSame($end . ' 00:00:00', $period['end']->format('Y-m-d H:i:s'));
        }
    }

    public function testAnnualPeriods(): void
    {
        $period = $this->calculator()->period('year', $this->utc('2026-07-04'));

        $this->assertSame('2026', $period['key']);
        $this->assertSame('2026-01-01 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-31 00:00:00', $period['end']->format('Y-m-d H:i:s'));
    }

    public function testWeeklyPeriodsUseIsoWeekKeysAndMondayStarts(): void
    {
        $period = $this->calculator()->period('week', $this->utc('2026-11-11 12:00:00'));

        $this->assertMatches('/^\d{4}-W\d{2}$/', $period['key']);
        $this->assertSame('Monday', $period['start']->format('l'));
        $this->assertSame('2026-11-09 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-15 00:00:00', $period['end']->format('Y-m-d H:i:s'));
        $this->assertSame($period['start']->modify('+6 days')->format('Y-m-d'), $period['end']->format('Y-m-d'));

        // ISO-week year edge: Fri 2027-01-01 belongs to 2026-W53.
        $edge = $this->calculator()->period('week', $this->utc('2027-01-01'));
        $this->assertSame('2026-W53', $edge['key']);
        $this->assertSame('2026-12-28', $edge['start']->format('Y-m-d'));
    }

    public function testOncePeriod(): void
    {
        $period = $this->calculator()->period('once', $this->utc('2026-11-15 18:45:00'));

        $this->assertSame('ONCE', $period['key']);
        $this->assertSame('2026-11-15 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame($period['start']->format('Y-m-d H:i:s'), $period['end']->format('Y-m-d H:i:s'));
    }

    public function testNextStartAdvancesExactlyOnePeriod(): void
    {
        $calculator = $this->calculator();
        $november = $this->utc('2026-11-01 00:00:00');

        $this->assertSame('2026-12-01 00:00:00', $calculator->nextStart($november, 'month')->format('Y-m-d H:i:s'));
        $this->assertSame('2027-02-01 00:00:00', $calculator->nextStart($november, 'quarter')->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-01 00:00:00', $calculator->nextStart($november, 'year')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-08 00:00:00', $calculator->nextStart($november, 'week')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 00:00:00', $calculator->nextStart($november, 'once')->format('Y-m-d H:i:s'));

        // January rollover across a year boundary.
        $december = $this->utc('2026-12-01 00:00:00');
        $this->assertSame('2027-01-01 00:00:00', $calculator->nextStart($december, 'month')->format('Y-m-d H:i:s'));
    }

    public function testBillingCycleMapsToPeriodUnit(): void
    {
        $this->assertSame('month', $this->calculator()->unitForCycle('monthly'));
        $this->assertSame('year', $this->calculator()->unitForCycle('annual'));
    }

    public function testUnsupportedInputsAreRejected(): void
    {
        foreach (['day', 'fortnight', ''] as $unit) {
            try {
                $this->calculator()->period($unit, $this->utc('2026-11-15'));
                $this->fail('Unsupported period unit must be rejected: ' . $unit);
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        try {
            $this->calculator()->unitForCycle('weekly');
            $this->fail('Unsupported billing cycle must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }
}
