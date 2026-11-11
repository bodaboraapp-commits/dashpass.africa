<?php

declare(strict_types=1);

namespace App\Modules\Billing\Automation;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * UTC period/key arithmetic for billing automation (Inc-13).
 *
 * Period keys are frozen: YYYYMM (monthly), YYYYQn (quarterly), YYYY (annual),
 * YYYY-Www (ISO week), ONCE. P1 implements subscription monthly/annual; the
 * other units are used by later utility/one-shot slices. Period starts are
 * aligned to the calendar (monthly = 1st, annual = Jan 1) with month-end clamp
 * for the derived end date.
 */
final class AutomationPeriodCalculator
{
    public function period(string $unit, DateTimeImmutable $reference): array
    {
        $start = $reference->setTimezone(new \DateTimeZone('UTC'));

        return match ($unit) {
            'month' => $this->monthPeriod($start),
            'quarter' => $this->quarterPeriod($start),
            'year' => $this->yearPeriod($start),
            'week' => $this->weekPeriod($start),
            'once' => [
                'start' => $start->setTime(0, 0),
                'end' => $start->setTime(0, 0),
                'key' => 'ONCE',
            ],
            default => throw new InvalidArgumentException('Unsupported billing period unit: ' . $unit),
        };
    }

    /**
     * @return array{start:DateTimeImmutable,end:DateTimeImmutable,key:string}
     */
    private function monthPeriod(DateTimeImmutable $reference): array
    {
        $start = $reference->modify('first day of this month')->setTime(0, 0);
        $end = $reference->modify('last day of this month')->setTime(0, 0);

        return ['start' => $start, 'end' => $end, 'key' => $start->format('Ym')];
    }

    /**
     * @return array{start:DateTimeImmutable,end:DateTimeImmutable,key:string}
     */
    private function quarterPeriod(DateTimeImmutable $reference): array
    {
        $month = (int) $reference->format('n');
        $quarter = intdiv($month - 1, 3) + 1;
        $startMonth = (($quarter - 1) * 3) + 1;
        $start = $reference->setDate((int) $reference->format('Y'), $startMonth, 1)->setTime(0, 0);
        $end = $start->modify('+3 months')->modify('-1 day')->setTime(0, 0);

        return ['start' => $start, 'end' => $end, 'key' => $start->format('Y') . 'Q' . $quarter];
    }

    /**
     * @return array{start:DateTimeImmutable,end:DateTimeImmutable,key:string}
     */
    private function yearPeriod(DateTimeImmutable $reference): array
    {
        $start = $reference->setDate((int) $reference->format('Y'), 1, 1)->setTime(0, 0);
        $end = $start->setDate((int) $start->format('Y'), 12, 31)->setTime(0, 0);

        return ['start' => $start, 'end' => $end, 'key' => $start->format('Y')];
    }

    /**
     * @return array{start:DateTimeImmutable,end:DateTimeImmutable,key:string}
     */
    private function weekPeriod(DateTimeImmutable $reference): array
    {
        $start = $reference->modify('monday this week')->setTime(0, 0);
        $end = $start->modify('+6 days')->setTime(0, 0);

        return ['start' => $start, 'end' => $end, 'key' => $start->format('o-\WW')];
    }

    public function nextStart(DateTimeImmutable $periodStart, string $unit): DateTimeImmutable
    {
        $start = $periodStart->setTimezone(new \DateTimeZone('UTC'));

        return match ($unit) {
            'month' => $start->modify('first day of next month')->setTime(0, 0),
            'quarter' => $start->modify('+3 months')->setTime(0, 0),
            'year' => $start->setDate((int) $start->format('Y') + 1, 1, 1)->setTime(0, 0),
            'week' => $start->modify('+1 week')->setTime(0, 0),
            'once' => $start,
            default => throw new InvalidArgumentException('Unsupported billing period unit: ' . $unit),
        };
    }

    public function unitForCycle(string $billingCycle): string
    {
        return match ($billingCycle) {
            'annual' => 'year',
            'monthly' => 'month',
            default => throw new InvalidArgumentException('Unsupported billing cycle: ' . $billingCycle),
        };
    }
}
