<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Clock;

/**
 * Time-based greeting on the resident Home screen
 * (email-pwa-greeting-contract §3): estate-local hour boundaries
 * 04:59/05:00, 11:59/12:00, 16:59/17:00, 21:59/22:00, plus the safe
 * "Hello," fallback for an invalid timezone.
 *
 * The wall clock cannot be frozen, so the *estate timezone* is used as the
 * injectable seam: each case sets a fixed-offset timezone that makes the current
 * UTC hour equal the boundary hour being tested.
 */
final class GreetingTest extends VisitorModuleTestCase
{
    /**
     * @return array<int, array{0: int, 1: string}>
     */
    private function cases(): array
    {
        return [
            [4, 'Hello,'],
            [5, 'Good morning,'],
            [11, 'Good morning,'],
            [12, 'Good afternoon,'],
            [16, 'Good afternoon,'],
            [17, 'Good evening,'],
            [21, 'Good evening,'],
            [22, 'Hello,'],
        ];
    }

    /**
     * A fixed-offset timezone whose local hour is the requested hour.
     */
    private function timezoneForHour(int $localHour): string
    {
        $utcHour = (int) Clock::now()->setTimezone(Clock::utc())->format('G');
        $diff = (($localHour - $utcHour) % 24 + 24) % 24;

        if ($diff > 14) {
            $diff -= 24;
        }

        return sprintf('%+03d:00', $diff);
    }

    private function setEstateTimezone(string $timezone): void
    {
        $this->db->execute(
            'UPDATE estates SET timezone = :timezone WHERE id = :id',
            ['timezone' => $timezone, 'id' => (int) $this->estate()['id']]
        );
    }

    private function greeting(): string
    {
        $response = $this->get('/app');
        $this->assertStatus(200, $response);

        return $this->nodeText($this->dom($response), '//*[contains(@class,"home-head__hello")]');
    }

    public function testGreetingBoundariesFollowEstateLocalTime(): void
    {
        $this->ensureLoggedInAs(self::JANE);

        foreach ($this->cases() as [$hour, $expected]) {
            $this->setEstateTimezone($this->timezoneForHour($hour));

            $this->assertSame(
                $expected,
                $this->greeting(),
                'Greeting at local hour ' . $hour . ' must be "' . $expected . '".'
            );
        }
    }

    public function testGreetingIsServerRenderedAndEscaped(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $this->setEstateTimezone($this->timezoneForHour(12));

        $response = $this->get('/app');
        $this->assertStatus(200, $response);

        // Server-rendered (present in the HTML response before any JS runs).
        $this->assertBodyContains('Good afternoon,', $response);
        $this->assertBodyContains('home-head__hello', $response);

        // The greeting block holds only the fixed escaped greeting text.
        $node = $this->nodeText($this->dom($response), '//*[contains(@class,"home-head__hello")]');
        $this->assertSame('Good afternoon,', $node);
        $this->assertFalse(str_contains($node, '<'), 'The greeting must be escaped text.');
    }

    public function testInvalidTimezoneFallsBackToHello(): void
    {
        $this->ensureLoggedInAs(self::JANE);
        $this->setEstateTimezone('Not/AZone');

        $this->assertSame('Hello,', $this->greeting(), 'An invalid estate timezone must fall back to the neutral greeting.');
    }
}
