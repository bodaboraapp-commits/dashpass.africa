<?php

declare(strict_types=1);

namespace App\Modules\Residents;

/**
 * Clearly-labelled preview content for resident screens whose data module is
 * not built yet (Visitors, Bookings), Task 001.1 addendum §4.
 *
 * This is presentation demo data only. Controllers must gate every read on
 * `config('ui.resident_pwa.preview_content')`; when that flag is false these
 * arrays are never passed to a view, so production shows honest empty states.
 * Rows are deliberately static and carry no real visitor, booking, payment or
 * parking information.
 */
final class PreviewContent
{
    /**
     * Upcoming visitor rows (3).
     *
     * @return array<int, array{name: string, purpose: string, when: string}>
     */
    public static function visitorsUpcoming(): array
    {
        return [
            ['name' => 'James Mwangi', 'purpose' => 'Family visit', 'when' => '25 May 2026, 10:00 AM'],
            ['name' => 'Robert Brown', 'purpose' => 'Service provider', 'when' => '25 May 2026, 2:30 PM'],
            ['name' => 'Amanda Lee', 'purpose' => 'Personal visit', 'when' => '26 May 2026, 11:00 AM'],
        ];
    }

    /**
     * Past visitor rows (2).
     *
     * @return array<int, array{name: string, purpose: string, when: string}>
     */
    public static function visitorsHistory(): array
    {
        return [
            ['name' => 'Grace Njeri', 'purpose' => 'Delivery', 'when' => '18 May 2026, 09:15 AM'],
            ['name' => 'Samuel Otieno', 'purpose' => 'Family visit', 'when' => '11 May 2026, 4:00 PM'],
        ];
    }

    /**
     * Bookable facilities (4). `tone` drives the status colour and `icon` picks
     * the local thumbnail/icon.
     *
     * @return array<int, array{name: string, status: string, tone: string, icon: string}>
     */
    public static function facilities(): array
    {
        return [
            ['name' => 'Function Room', 'status' => 'Available', 'tone' => 'ok', 'icon' => 'room'],
            ['name' => 'BBQ Area', 'status' => 'Available', 'tone' => 'ok', 'icon' => 'bbq'],
            ['name' => 'Gym Room', 'status' => 'Few Slots Left', 'tone' => 'warn', 'icon' => 'gym'],
            ['name' => 'Badminton Court', 'status' => 'Available', 'tone' => 'ok', 'icon' => 'court'],
        ];
    }

    /**
     * Announcing demo estate notices (2). Bodies explicitly say "Demo notice"
     * so preview content can never be mistaken for live operational data.
     *
     * @return array<int, array{title: string, body: string}>
     */
    public static function announcements(): array
    {
        return [
            [
                'title' => 'Water Supply Interruption',
                'body' => 'Demo notice: water supply will be interrupted on 25 May 2026 from 9:00 AM to 1:00 PM '
                    . 'for scheduled maintenance. Preview content, not a live estate notice.',
            ],
            [
                'title' => 'Estate Security Briefing',
                'body' => 'Demo notice: a routine security briefing for all residents is scheduled in the '
                    . 'Function Room. Preview content, not a live estate notice.',
            ],
        ];
    }
}
