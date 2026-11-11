<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Context;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Uuid;
use App\Core\View;
use App\Modules\Estates\EstateRepository;
use App\Modules\Residents\DashboardService;

/**
 * XSS escaping of the layout-refresh views (addendum §7.6).
 *
 * Preview rows and announcement strings are rendered through the views with
 * attacker-controlled content; every dynamic value must still go through e().
 */
final class ResidentPwaXssTest extends SecurityTestCase
{
    private const JANE = '+254700000010';

    /**
     * Build the shell data the layout-refresh controller passes to the views,
     * from a real authenticated resident context.
     *
     * @return array{shell: array<string, mixed>, dashboard: array<string, mixed>}
     */
    private function residentViewData(string $activeNav = 'home'): array
    {
        $request = Request::create('GET', '/app');
        $context = new Context($request, Uuid::v4());

        $user = $context->auth->requireUser();
        $membership = $context->tenant->requireEstate();
        $activeEstateId = (int) $membership['estate_id'];

        $dashboard = DashboardService::make($context->db)->build($membership, (int) $user['id']);
        $dashboard['memberships'] = array_map(
            static fn (array $row): array => EstateRepository::membershipShape($row, $activeEstateId),
            $context->tenant->memberships()
        );

        $shell = [
            'title' => 'Home',
            'user' => [
                'uuid' => (string) $user['uuid'],
                'full_name' => (string) $user['full_name'],
                'phone' => (string) $user['phone'],
                'email' => $user['email'],
            ],
            'estate' => $membership,
            'memberships' => $context->tenant->memberships(),
            'activeNav' => $activeNav,
            'csrfToken' => Csrf::token(),
            'appVersion' => (string) config('app.version', '0.1.0-foundation'),
            'isDev' => true,
            'flashError' => null,
            'flashSuccess' => null,
            'permissions' => $context->permission->slugs(),
        ];

        return ['shell' => $shell, 'dashboard' => $dashboard];
    }

    private function assertEscaped(string $html, string $raw, string $context): void
    {
        $this->assertFalse(
            str_contains($html, $raw),
            $context . ': raw payload must never reach the rendered HTML: ' . $raw
        );

        $escaped = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->assertContains(
            $escaped,
            $html,
            $context . ': expected the escaped payload to be rendered: ' . $raw
        );
    }

    public function testHomeAnnouncementAndEstateStringsAreEscaped(): void
    {
        $this->loginAs(self::JANE);
        $data = $this->residentViewData('home');

        $payload = '<script>alert("announcement")</script>';
        $estatePayload = '<script>alert("estate")</script>';
        $data['shell']['announcements'] = [
            ['title' => $payload, 'body' => '<img src=x onerror="alert(\'body\')">'],
        ];
        $data['shell']['preview'] = true;
        $data['shell']['dashboard'] = $data['dashboard'];
        $data['shell']['dashboard']['estate']['estate_name'] = $estatePayload;
        $data['shell']['estate']['estate_name'] = $estatePayload;

        $html = View::capture('app/home', $data['shell']);

        $this->assertEscaped($html, $payload, 'Home announcement title');
        $this->assertEscaped($html, $estatePayload, 'Home hero estate name');
        $this->assertFalse(
            str_contains($html, 'onerror="alert('),
            'Home must escape attribute-breaking announcement/estate payloads.'
        );
    }

    public function testVisitorsInvitationStringsAreEscaped(): void
    {
        $this->loginAs(self::JANE);
        $data = $this->residentViewData('home');

        $name = '<script>alert("visitor-name")</script>';
        $purpose = '<img src=x onerror="alert(\'visitor-purpose\')">';

        $html = View::capture('app/visitors', $data['shell'] + [
            'visitors_tab' => 'upcoming',
            'upcoming' => [
                [
                    'uuid' => '11111111-1111-4111-8111-111111111111',
                    'visitor_name' => $name,
                    'purpose' => $purpose,
                    'household_label' => '<script>alert("household")</script>',
                    'valid_from' => '2026-11-11T08:00:00.000Z',
                    'valid_until' => '2026-11-11T12:00:00.000Z',
                    'status' => 'approved',
                    'status_label' => 'Approved',
                    'status_tone' => 'ok',
                    'already_inside' => false,
                ],
            ],
            'history' => [],
            'pending_walkins' => [],
        ]);

        $this->assertEscaped($html, $name, 'Visitors invitation name');
        $this->assertEscaped($html, $purpose, 'Visitors invitation purpose');
        $this->assertEscaped($html, '<script>alert("household")</script>', 'Visitors household label');
        $this->assertFalse(str_contains($html, 'onerror="alert('), 'Visitors rows must be attribute-safe.');
    }

    public function testBookingsParkingStringsAreEscaped(): void
    {
        $this->loginAs(self::JANE);
        $data = $this->residentViewData('home');

        $bayPayload = '<script>alert("bay-code")</script>';
        $zonePayload = '<img src=x onerror="alert(\'zone-name\')">';
        $visitorPayload = '<script>alert("reservation-visitor")</script>';

        $facilities = View::capture('app/bookings', $data['shell'] + [
            'bookings_tab' => 'facilities',
            'visitor_bays' => [
                [
                    'uuid' => '88888888-8888-4888-8888-888888888888',
                    'bay_code' => $bayPayload,
                    'zone_name' => $zonePayload,
                    'bay_type' => 'visitor',
                    'status' => 'available',
                    'available' => true,
                ],
            ],
            'availability_from' => '2026-11-12T07:00:00.000Z',
            'availability_to' => '2026-11-12T11:00:00.000Z',
            'reservations' => [],
        ]);

        $this->assertEscaped($facilities, $bayPayload, 'Bookings bay code');
        $this->assertEscaped($facilities, $zonePayload, 'Bookings zone name');
        $this->assertFalse(str_contains($facilities, 'Layout preview'), 'The Bookings screen must not render preview content any more.');

        $myBookings = View::capture('app/bookings', $data['shell'] + [
            'bookings_tab' => 'bookings',
            'visitor_bays' => [],
            'availability_from' => '2026-11-12T07:00:00.000Z',
            'availability_to' => '2026-11-12T11:00:00.000Z',
            'reservations' => [
                [
                    'uuid' => '99999999-9999-4999-8999-999999999999',
                    'invitation_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    'visitor_name' => $visitorPayload,
                    'plate_number' => null,
                    'bay_code' => $bayPayload,
                    'zone_name' => $zonePayload,
                    'starts_at' => '2026-11-12T07:00:00.000Z',
                    'ends_at' => '2026-11-12T11:00:00.000Z',
                    'status' => 'reserved',
                    'status_label' => 'Reserved',
                    'status_tone' => 'warn',
                ],
            ],
        ]);

        $this->assertEscaped($myBookings, $visitorPayload, 'Bookings reservation visitor');
        $this->assertEscaped($myBookings, $bayPayload, 'Bookings reservation bay code');
        $this->assertFalse(str_contains($myBookings, 'onerror="alert('), 'Bookings parking values must be attribute-safe.');
    }
}
