<?php

declare(strict_types=1);

namespace App\Modules\Residents;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\View;
use App\Modules\Estates\EstateRepository;
use App\Modules\Notices\NoticeService;
use App\Modules\Parking\ParkingService;
use App\Modules\Shared\BuildsShell;
use App\Modules\Vehicles\VehicleService;
use App\Modules\Visitors\AccessPresenter;
use App\Modules\Visitors\InvitationService;
use App\Modules\Visitors\UserHouseholdRepository;
use App\Modules\Visitors\WalkInService;

/**
 * Resident screens and JSON dashboard.
 *
 * Home/Bookings keep the layout-refresh behaviour; Visitors, Invite and Pass are
 * the real visitor-access module (contract §4, §5, §7.1). Authority always
 * comes from App\Core\Context, never from browser input.
 */
final class ResidentController
{
    use BuildsShell;

    /** @var array<int, string> */
    private const VISITORS_TABS = ['upcoming', 'history'];

    /** @var array<int, string> */
    private const BOOKINGS_TABS = ['facilities', 'bookings'];

    /* ------------------------------------------------------------------ */
    /* Home                                                               */
    /* ------------------------------------------------------------------ */

    public function webHome(Request $request, array $params, Context $context): Response
    {
        // Pure guards operate the gate PWA, not the resident shell (contract §5).
        if ($context->permission->can('visitor.checkin') && !$context->permission->can('estate.view')) {
            return Response::redirect('/gate');
        }

        $preview = $this->previewEnabled();

        // Increment 7: real published estate notices replace the demo announcement
        // cards. Real notices always win; demo cards are shown only when preview
        // content is enabled AND the estate has no published notices.
        $publishedNotices = [];

        try {
            $publishedNotices = NoticeService::make($context)->homeAnnouncements(3);
        } catch (\Throwable) {
            // Defensive: a deployment without the Inc-7 notices table must not break Home.
            $publishedNotices = [];
        }

        $shell = $this->shell($context, 'Home', 'home');
        $shell['dashboard'] = $this->dashboardPayload($context);
        $shell['preview'] = $preview;
        $shell['announcements'] = $publishedNotices !== []
            ? $publishedNotices
            : ($preview ? PreviewContent::announcements() : []);
        $shell['announcements_demo'] = $publishedNotices === [] && $preview;
        $shell['announcements_real'] = $publishedNotices !== [];
        $shell['next_pass'] = InvitationService::make($context)->nextPassForResident();

        return View::render('app/home', $shell, 'app');
    }

    /* ------------------------------------------------------------------ */
    /* Activity                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Own-household visitor timeline (gate events + invitation issue events).
     *
     * No dedicated permission node: the same `estate.view`-level resident access
     * as Home applies, and household scoping is enforced inside the service from
     * App\Core\Context only.
     */
    public function webActivity(Request $request, array $params, Context $context): Response
    {
        $shell = $this->shell($context, 'Activity', 'activity');
        $shell['activity'] = ResidentActivityService::make($context)->timeline();

        return View::render('app/activity', $shell, 'app');
    }

    /* ------------------------------------------------------------------ */
    /* My access pass (full page; owner request: no modal)                */
    /* ------------------------------------------------------------------ */

    /**
     * Full-page access pass (`GET /app/access`).
     *
     * The owner asked for the access QR to be a real page reached from the Home
     * Access tile and the centre QR button, not a pop-up dialog. It shows the
     * resident's next visitor pass (visitor, household, validity, status and the
     * server-rendered QR) with a link to the full pass screen.
     */
    public function webAccess(Request $request, array $params, Context $context): Response
    {
        $shell = $this->shell($context, 'My access pass', 'home');
        $shell['next_pass'] = InvitationService::make($context)->nextPassForResident();

        return View::render('app/access', $shell, 'app');
    }

    /* ------------------------------------------------------------------ */
    /* Visitors                                                           */
    /* ------------------------------------------------------------------ */

    public function webVisitors(Request $request, array $params, Context $context): Response
    {
        $tab = $this->resolveTab($request->queryString('tab'), self::VISITORS_TABS, 'upcoming');
        $invitations = InvitationService::make($context);
        $lists = $invitations->listForResident($tab);
        $pending = WalkInService::make($context)->pendingForResident();

        $shell = $this->shell($context, 'Visitors', 'home');
        $shell['visitors_tab'] = $tab;
        $shell['upcoming'] = $lists['upcoming'];
        $shell['history'] = $lists['history'];
        $shell['pending_walkins'] = $pending;

        return View::render('app/visitors', $shell, 'app');
    }

    public function webInvite(Request $request, array $params, Context $context): Response
    {
        $user = $context->auth->requireUser();
        $membership = $context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];
        $timezone = (string) ($membership['estate_timezone'] ?? 'Africa/Nairobi');

        $households = (new UserHouseholdRepository($context->db))->forUserInEstate((int) $user['id'], $estateId);
        $now = Clock::now();
        $defaultUntil = $now->modify('+' . $this->defaultValidityMinutes($context, $estateId) . ' minutes');

        $errors = Session::pullFlash('errors', []);
        $old = Session::pullFlash('old', []);

        $shell = $this->shell($context, 'Invite a visitor', 'home');
        $shell['households'] = array_map(
            static fn (array $household): array => [
                'uuid' => (string) $household['uuid'],
                'label' => (string) $household['label'],
                'unit_number' => (string) $household['unit_number'],
                'building_name' => $household['building_name'],
                'relationship' => $household['relationship'],
                'is_primary' => (bool) $household['is_primary'],
            ],
            $households
        );
        $shell['default_valid_from'] = AccessPresenter::localFormValue(Clock::toDb($now), $timezone);
        $shell['default_valid_until'] = AccessPresenter::localFormValue(Clock::toDb($defaultUntil), $timezone);
        $shell['max_visitor_count'] = max(1, (int) Config::get('visitors.invitation.visitor_count_max', 10));
        $shell['estate_timezone'] = $timezone;

        // Increment 3 (§4 frozen): optional household vehicle + visitor parking.
        $shell['vehicles'] = VehicleService::make($context)->vehiclesForInvite();
        $parking = ParkingService::make($context);

        try {
            $shell['parking_available_count'] = $parking->availableVisitorBayCount(
                AccessPresenter::iso(Clock::toDb($now)),
                AccessPresenter::iso(Clock::toDb($defaultUntil))
            );
        } catch (\Throwable) {
            $shell['parking_available_count'] = null;
        }

        $shell['errors'] = is_array($errors) ? $errors : [];
        $shell['old'] = is_array($old) ? $old : [];

        return View::render('app/invite', $shell, 'app');
    }

    public function webStoreInvitation(Request $request, array $params, Context $context): Response
    {
        try {
            $created = InvitationService::make($context)->create($request->all());
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());

            return Response::redirect('/app/visitors/invite');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());

            if ($exception->errorCode() === 'VALIDITY_INVALID') {
                Session::flash('errors', ['valid_until' => [$exception->publicMessage()]]);
            } elseif ($exception->errorCode() === 'NO_PARKING_AVAILABLE') {
                Session::flash('errors', ['parking_required' => [$exception->publicMessage()]]);
            } elseif ($exception->errorCode() === 'VEHICLE_NOT_FOUND') {
                Session::flash('errors', ['vehicle_id' => [$exception->publicMessage()]]);
            }

            Session::flash('old', $request->all());

            return Response::redirect('/app/visitors/invite');
        }

        if (!self::isProduction()) {
            Session::flash('dev_pin', (string) $created['_pin']);
        }

        Session::flash('success', 'Invitation created. Share the PIN or pass link with your visitor.');

        return Response::redirect('/app/passes/' . (string) $created['invitation_uuid']);
    }

    /* ------------------------------------------------------------------ */
    /* Pass                                                               */
    /* ------------------------------------------------------------------ */

    public function webPass(Request $request, array $params, Context $context): Response
    {
        $invitations = InvitationService::make($context);
        $row = $invitations->findForResident((string) ($params['uuid'] ?? ''));
        $pass = $invitations->passData($row);
        $errors = Session::pullFlash('errors', []);

        $shell = $this->shell($context, 'Visitor pass', 'home');
        $shell['pass'] = $pass;
        $shell['pin_display'] = Session::pullFlash('dev_pin');
        $shell['qr_url'] = $pass['qr_url'];
        $shell['pass_url'] = $pass['pass_url'];
        $shell['errors'] = is_array($errors) ? $errors : [];

        return View::render('app/pass', $shell, 'app');
    }

    public function webPassQr(Request $request, array $params, Context $context): Response
    {
        $png = InvitationService::make($context)->qrPng((string) ($params['uuid'] ?? ''));

        return new Response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
            'Content-Disposition' => 'inline; filename="visitor-pass-qr.png"',
        ]);
    }

    public function webRevoke(Request $request, array $params, Context $context): Response
    {
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            InvitationService::make($context)->revoke($uuid);
            Session::flash('success', 'Invitation revoked.');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
        }

        return Response::redirect('/app/passes/' . $uuid);
    }

    public function webExtend(Request $request, array $params, Context $context): Response
    {
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            $minutes = (int) $request->integer('minutes', 0);
            InvitationService::make($context)->extend($uuid, $minutes);
            Session::flash('success', 'Validity extended.');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
        }

        return Response::redirect('/app/passes/' . $uuid);
    }

    public function webRotatePin(Request $request, array $params, Context $context): Response
    {
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            $result = InvitationService::make($context)->rotatePin($uuid);

            if (!self::isProduction()) {
                Session::flash('dev_pin', (string) $result['pin']);
            }

            Session::flash('success', 'A new PIN has been issued.');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
        }

        return Response::redirect('/app/passes/' . $uuid);
    }

    /* ------------------------------------------------------------------ */
    /* Walk-in approvals                                                  */
    /* ------------------------------------------------------------------ */

    public function webApproveWalkIn(Request $request, array $params, Context $context): Response
    {
        try {
            $result = WalkInService::make($context)->approve((string) ($params['uuid'] ?? ''));
            $message = 'Walk-in visitor approved.';

            if (isset($result['pin']) && !self::isProduction()) {
                $message .= ' Visitor PIN: ' . (string) $result['pin'];
            }

            Session::flash('success', $message);
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
        }

        return Response::redirect('/app/visitors');
    }

    public function webRejectWalkIn(Request $request, array $params, Context $context): Response
    {
        try {
            WalkInService::make($context)->reject((string) ($params['uuid'] ?? ''));
            Session::flash('success', 'Walk-in visitor rejected.');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
        }

        return Response::redirect('/app/visitors');
    }

    /* ------------------------------------------------------------------ */
    /* Bookings → real visitor parking (Increment 3 §4 frozen)            */
    /* ------------------------------------------------------------------ */

    public function webBookings(Request $request, array $params, Context $context): Response
    {
        $membership = $context->tenant->requireEstate();
        $timezone = (string) ($membership['estate_timezone'] ?? 'Africa/Nairobi');
        $tab = $this->resolveTab($request->queryString('tab'), self::BOOKINGS_TABS, 'facilities');

        $parking = ParkingService::make($context);

        try {
            $availability = $parking->availability(
                $request->queryString('from'),
                $request->queryString('to'),
                'visitor'
            );
        } catch (HttpException) {
            $availability = $parking->availability(null, null, 'visitor');
        }

        $shell = $this->shell($context, 'Bookings', 'home');
        $shell['bookings_tab'] = $tab;
        $shell['visitor_bays'] = $availability['bays'];
        $shell['availability_from'] = $availability['from'];
        $shell['availability_to'] = $availability['to'];
        $shell['reservations'] = $parking->reservationsForBookings();

        return View::render('app/bookings', $shell, 'app');
    }

    /* ------------------------------------------------------------------ */
    /* JSON dashboard (unchanged contract §8.1)                           */
    /* ------------------------------------------------------------------ */

    public function apiDashboard(Request $request, array $params, Context $context): Response
    {
        return Response::success('Dashboard loaded.', $this->dashboardPayload($context));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function dashboardPayload(Context $context): array
    {
        $user = $context->auth->requireUser();
        $membership = $context->tenant->requireEstate();
        $activeEstateId = (int) $membership['estate_id'];

        $payload = DashboardService::make($context->db)->build($membership, (int) $user['id']);

        $payload['memberships'] = array_map(
            static fn (array $row): array => EstateRepository::membershipShape($row, $activeEstateId),
            $context->tenant->memberships()
        );

        return $payload;
    }

    private function defaultValidityMinutes(Context $context, int $estateId): int
    {
        $row = $context->db->selectOne(
            'SELECT setting_value FROM estate_settings
             WHERE estate_id = :estate_id AND setting_key = :setting_key LIMIT 1',
            ['estate_id' => $estateId, 'setting_key' => 'visitor_validity_minutes']
        );

        if ($row !== null && $row['setting_value'] !== null) {
            $decoded = json_decode((string) $row['setting_value'], true);

            if (is_numeric($decoded)) {
                return max(1, (int) $decoded);
            }
        }

        return max(1, (int) Config::get('visitors.invitation.default_validity_minutes', 240));
    }

    private function previewEnabled(): bool
    {
        return (bool) Config::get('ui.resident_pwa.preview_content', false);
    }

    /**
     * @param array<int, string> $allowed
     */
    private function resolveTab(?string $requested, array $allowed, string $default): string
    {
        if ($requested === null) {
            return $default;
        }

        return in_array($requested, $allowed, true) ? $requested : $default;
    }

    private static function isProduction(): bool
    {
        return (string) Config::get('app.env', 'production') === 'production';
    }
}
