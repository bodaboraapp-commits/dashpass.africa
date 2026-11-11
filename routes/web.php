<?php

declare(strict_types=1);

/*
 * Web (HTML / PWA) routes.
 *
 * Base route table: docs/foundation-contract.md §8.
 * Resident PWA layout: docs/resident-pwa-layout-addendum.md §3.
 * Visitor & access module: docs/visitor-access-module-contract.md §5.
 *
 * Handlers receive (Request $request, array $params, Context $context) and
 * return a Response. CSRF protection is applied globally to every state-changing
 * request; tabs and noscript forms are real server-rendered links/forms.
 */

use App\Core\Router;
use App\Modules\Accounts\AccountController;
use App\Modules\Admin\AdminController;
use App\Modules\Admin\BuildingController;
use App\Modules\Admin\GateAdminController;
use App\Modules\Admin\GuardAdminController;
use App\Modules\Admin\HouseholdController;
use App\Modules\Admin\HouseholdMemberController;
use App\Modules\Admin\ResidentAdminController;
use App\Modules\Admin\SettingsController;
use App\Modules\Authentication\AuthController;
use App\Modules\Estates\EstateController;
use App\Modules\Parking\ParkingController;
use App\Modules\Platform\HealthController;
use App\Modules\Platform\ManifestController;
use App\Modules\Residents\ResidentController;
use App\Modules\Vehicles\VehicleController;
use App\Modules\Visitors\GuardController;
use App\Modules\Visitors\VisitorPassController;

/** @var Router $router */

// Public
$router->get('/', [AuthController::class, 'webWelcome']);
$router->get('/health', [HealthController::class, 'show']);
// Dynamic PWA manifest so each deployment carries its own product name.
$router->get('/manifest.json', [ManifestController::class, 'show']);
$router->get('/v/{token}', [VisitorPassController::class, 'show']);

// Authentication
$router->get('/login', [AuthController::class, 'webLogin'], ['guest']);
$router->post('/login/otp', [AuthController::class, 'webRequestOtp'], ['guest', 'throttle:web-login,20,60']);
$router->post('/login/verify', [AuthController::class, 'webVerifyOtp'], ['guest', 'throttle:web-verify,30,60']);
$router->post('/logout', [AuthController::class, 'webLogout'], ['auth']);

// Authenticated application shell
$router->get('/app', [ResidentController::class, 'webHome'], ['auth', 'estate']);
$router->get('/app/account', [AccountController::class, 'webAccount'], ['auth']);
$router->get('/app/estates', [EstateController::class, 'webEstateSwitcher'], ['auth']);
$router->post('/app/estates/{uuid}/switch', [EstateController::class, 'webSwitch'], ['auth']);
$router->get('/app/no-estate', [AuthController::class, 'webNoEstate'], ['auth']);

// Resident visitor invitations + passes (contract §5)
$router->get('/app/visitors', [ResidentController::class, 'webVisitors'], ['auth', 'estate', 'permission:visitor.invitation.create']);
$router->get('/app/visitors/invite', [ResidentController::class, 'webInvite'], ['auth', 'estate', 'permission:visitor.invitation.create']);
$router->post('/app/visitors/invite', [ResidentController::class, 'webStoreInvitation'], ['auth', 'estate', 'permission:visitor.invitation.create', 'throttle:invite,20,600']);
$router->get('/app/passes/{uuid}', [ResidentController::class, 'webPass'], ['auth', 'estate', 'permission:visitor.invitation.view_own']);
$router->get('/app/passes/{uuid}/qr.png', [ResidentController::class, 'webPassQr'], ['auth', 'estate', 'permission:visitor.invitation.view_own']);
$router->post('/app/passes/{uuid}/revoke', [ResidentController::class, 'webRevoke'], ['auth', 'estate', 'permission:visitor.invitation.revoke']);
$router->post('/app/passes/{uuid}/extend', [ResidentController::class, 'webExtend'], ['auth', 'estate', 'permission:visitor.invitation.extend']);
$router->post('/app/passes/{uuid}/rotate-pin', [ResidentController::class, 'webRotatePin'], ['auth', 'estate', 'permission:visitor.invitation.create']);
$router->post('/app/walkins/{uuid}/approve', [ResidentController::class, 'webApproveWalkIn'], ['auth', 'estate', 'permission:visitor.walkin.approve']);
$router->post('/app/walkins/{uuid}/reject', [ResidentController::class, 'webRejectWalkIn'], ['auth', 'estate', 'permission:visitor.walkin.reject']);

// Resident bookings screen (Increment 3: real visitor parking data)
$router->get('/app/bookings', [ResidentController::class, 'webBookings'], ['auth', 'estate']);

// Resident vehicles + parking (Increment 3 §3)
$router->get('/app/vehicles', [VehicleController::class, 'webIndex'], ['auth', 'estate', 'permission:vehicle.view_own']);
$router->post('/app/vehicles', [VehicleController::class, 'webStore'], ['auth', 'estate', 'permission:vehicle.manage_own', 'throttle:vehicle,20,600']);
$router->post('/app/vehicles/{uuid}/deactivate', [VehicleController::class, 'webDeactivate'], ['auth', 'estate', 'permission:vehicle.manage_own']);
$router->get('/app/parking', [ParkingController::class, 'webIndex'], ['auth', 'estate', 'permission:parking.view']);

// Guard PWA (contract §5). The selected gate lives in the server-side session.
$router->get('/gate', [GuardController::class, 'webDashboard'], ['auth', 'estate', 'permission:gate.view']);
$router->post('/gate/select-gate', [GuardController::class, 'webSelectGate'], ['auth', 'estate', 'permission:gate.view']);
$router->get('/gate/verify', [GuardController::class, 'webVerify'], ['auth', 'estate', 'permission:visitor.checkin']);
$router->post('/gate/verify', [GuardController::class, 'webVerifySubmit'], ['auth', 'estate', 'permission:visitor.checkin']);
$router->post('/gate/checkin', [GuardController::class, 'webCheckin'], ['auth', 'estate', 'permission:visitor.checkin']);
$router->post('/gate/checkout', [GuardController::class, 'webCheckout'], ['auth', 'estate', 'permission:visitor.checkout']);
$router->get('/gate/active', [GuardController::class, 'webActive'], ['auth', 'estate', 'permission:visitor.checkin']);
$router->get('/gate/walkin', [GuardController::class, 'webWalkin'], ['auth', 'estate', 'permission:visitor.walkin.create']);
$router->post('/gate/walkin', [GuardController::class, 'webWalkinStore'], ['auth', 'estate', 'permission:visitor.walkin.create']);

// Estate admin console (Increment 4 contract §1; 26 web routes, web forms only).
$router->get('/admin', [AdminController::class, 'dashboard'], ['auth', 'estate', 'permission:estate.view']);

$router->get('/admin/buildings', [BuildingController::class, 'index'], ['auth', 'estate', 'permission:household.view']);
$router->post('/admin/buildings', [BuildingController::class, 'store'], ['auth', 'estate', 'permission:household.manage']);
$router->get('/admin/buildings/{uuid}', [BuildingController::class, 'show'], ['auth', 'estate', 'permission:household.view']);
$router->post('/admin/buildings/{uuid}', [BuildingController::class, 'update'], ['auth', 'estate', 'permission:household.manage']);

$router->get('/admin/households', [HouseholdController::class, 'index'], ['auth', 'estate', 'permission:household.view']);
$router->post('/admin/households', [HouseholdController::class, 'store'], ['auth', 'estate', 'permission:household.manage']);
$router->get('/admin/households/{uuid}', [HouseholdController::class, 'show'], ['auth', 'estate', 'permission:household.view']);
$router->post('/admin/households/{uuid}', [HouseholdController::class, 'update'], ['auth', 'estate', 'permission:household.manage']);
$router->post('/admin/households/{uuid}/archive', [HouseholdController::class, 'archive'], ['auth', 'estate', 'permission:household.manage']);
$router->post('/admin/households/{uuid}/members', [HouseholdMemberController::class, 'store'], ['auth', 'estate', 'permission:household.member.manage']);
$router->post('/admin/households/{uuid}/members/{memberUuid}/remove', [HouseholdMemberController::class, 'remove'], ['auth', 'estate', 'permission:household.member.manage']);

$router->get('/admin/residents', [ResidentAdminController::class, 'index'], ['auth', 'estate', 'permission:estate.user.manage']);
$router->post('/admin/residents', [ResidentAdminController::class, 'store'], ['auth', 'estate', 'permission:estate.user.manage']);
$router->post('/admin/residents/{uuid}/suspend', [ResidentAdminController::class, 'suspend'], ['auth', 'estate', 'permission:estate.user.manage']);
$router->post('/admin/residents/{uuid}/reactivate', [ResidentAdminController::class, 'reactivate'], ['auth', 'estate', 'permission:estate.user.manage']);

$router->get('/admin/guards', [GuardAdminController::class, 'index'], ['auth', 'estate', 'permission:guard.manage']);
$router->post('/admin/guards', [GuardAdminController::class, 'store'], ['auth', 'estate', 'permission:guard.manage']);
$router->post('/admin/guards/{uuid}/gates', [GuardAdminController::class, 'assignGates'], ['auth', 'estate', 'permission:guard.manage']);
$router->post('/admin/guards/{uuid}/archive', [GuardAdminController::class, 'archive'], ['auth', 'estate', 'permission:guard.manage']);

$router->get('/admin/gates', [GateAdminController::class, 'index'], ['auth', 'estate', 'permission:gate.view']);
$router->post('/admin/gates', [GateAdminController::class, 'store'], ['auth', 'estate', 'permission:gate.manage']);
$router->post('/admin/gates/{uuid}', [GateAdminController::class, 'update'], ['auth', 'estate', 'permission:gate.manage']);
$router->post('/admin/gates/{uuid}/archive', [GateAdminController::class, 'archive'], ['auth', 'estate', 'permission:gate.manage']);

$router->get('/admin/settings', [SettingsController::class, 'show'], ['auth', 'estate', 'permission:estate.settings.manage']);
$router->post('/admin/settings', [SettingsController::class, 'update'], ['auth', 'estate', 'permission:estate.settings.manage']);

// Household staff + incidents (Increment 6; routes land only after the Lead gate)
$router->get('/app/staff', [\App\Modules\Staff\StaffController::class, 'webIndex'], ['auth', 'estate', 'permission:household.staff.manage']);
$router->post('/app/staff', [\App\Modules\Staff\StaffController::class, 'webStore'], ['auth', 'estate', 'permission:household.staff.manage']);
$router->post('/app/staff/{uuid}/deactivate', [\App\Modules\Staff\StaffController::class, 'webDeactivate'], ['auth', 'estate', 'permission:household.staff.manage']);
$router->post('/app/staff/{uuid}/reactivate', [\App\Modules\Staff\StaffController::class, 'webReactivate'], ['auth', 'estate', 'permission:household.staff.manage']);

$router->get('/app/incidents', [\App\Modules\Incidents\IncidentController::class, 'webIndex'], ['auth', 'estate', 'permission:visitor.incident.create']);
$router->get('/app/incidents/new', [\App\Modules\Incidents\IncidentController::class, 'webCreate'], ['auth', 'estate', 'permission:visitor.incident.create']);
$router->post('/app/incidents', [\App\Modules\Incidents\IncidentController::class, 'webStore'], ['auth', 'estate', 'permission:visitor.incident.create']);

$router->get('/gate/incidents', [\App\Modules\Incidents\GuardIncidentController::class, 'index'], ['auth', 'estate', 'permission:visitor.incident.create']);
$router->get('/gate/incidents/new', [\App\Modules\Incidents\GuardIncidentController::class, 'create'], ['auth', 'estate', 'permission:visitor.incident.create']);
$router->post('/gate/incidents', [\App\Modules\Incidents\GuardIncidentController::class, 'store'], ['auth', 'estate', 'permission:visitor.incident.create']);

$router->get('/admin/staff', [\App\Modules\Staff\AdminStaffController::class, 'index'], ['auth', 'estate', 'permission:household.staff.manage']);
$router->get('/admin/incidents', [\App\Modules\Incidents\AdminIncidentController::class, 'index'], ['auth', 'estate', 'permission:visitor.incident.manage']);
$router->get('/admin/incidents/{uuid}', [\App\Modules\Incidents\AdminIncidentController::class, 'show'], ['auth', 'estate', 'permission:visitor.incident.manage']);
$router->post('/admin/incidents/{uuid}/status', [\App\Modules\Incidents\AdminIncidentController::class, 'updateStatus'], ['auth', 'estate', 'permission:visitor.incident.manage']);

// Estate notices (Increment 7; routes land only after the Inc-7 gate)
$router->get('/app/notices', [\App\Modules\Notices\NoticeController::class, 'webIndex'], ['auth', 'estate', 'permission:notice.view']);
$router->get('/app/notices/{uuid}', [\App\Modules\Notices\NoticeController::class, 'webShow'], ['auth', 'estate', 'permission:notice.view']);
$router->get('/gate/notices', [\App\Modules\Notices\GuardNoticeController::class, 'index'], ['auth', 'estate', 'permission:notice.view']);
$router->get('/admin/notices', [\App\Modules\Notices\AdminNoticeController::class, 'index'], ['auth', 'estate', 'permission:notice.manage']);
$router->get('/admin/notices/new', [\App\Modules\Notices\AdminNoticeController::class, 'create'], ['auth', 'estate', 'permission:notice.manage']);
$router->post('/admin/notices', [\App\Modules\Notices\AdminNoticeController::class, 'store'], ['auth', 'estate', 'permission:notice.manage']);
$router->get('/admin/notices/{uuid}', [\App\Modules\Notices\AdminNoticeController::class, 'show'], ['auth', 'estate', 'permission:notice.manage']);
$router->post('/admin/notices/{uuid}', [\App\Modules\Notices\AdminNoticeController::class, 'update'], ['auth', 'estate', 'permission:notice.manage']);
$router->post('/admin/notices/{uuid}/publish', [\App\Modules\Notices\AdminNoticeController::class, 'publish'], ['auth', 'estate', 'permission:notice.manage']);
$router->post('/admin/notices/{uuid}/archive', [\App\Modules\Notices\AdminNoticeController::class, 'archive'], ['auth', 'estate', 'permission:notice.manage']);

// Parking administration (Increment 8; routes land only after the Inc-8 gate)
$router->get('/admin/parking', [\App\Modules\Parking\AdminParkingController::class, 'overview'], ['auth', 'estate', 'permission:parking.view']);
$router->get('/admin/parking/zones', [\App\Modules\Parking\AdminParkingController::class, 'zones'], ['auth', 'estate', 'permission:parking.view']);
$router->post('/admin/parking/zones', [\App\Modules\Parking\AdminParkingController::class, 'zoneStore'], ['auth', 'estate', 'permission:parking.bay.manage']);
$router->post('/admin/parking/zones/{uuid}', [\App\Modules\Parking\AdminParkingController::class, 'zoneUpdate'], ['auth', 'estate', 'permission:parking.bay.manage']);
$router->get('/admin/parking/bays', [\App\Modules\Parking\AdminParkingController::class, 'bays'], ['auth', 'estate', 'permission:parking.view']);
$router->post('/admin/parking/bays', [\App\Modules\Parking\AdminParkingController::class, 'bayStore'], ['auth', 'estate', 'permission:parking.bay.manage']);
$router->post('/admin/parking/bays/{uuid}', [\App\Modules\Parking\AdminParkingController::class, 'bayUpdate'], ['auth', 'estate', 'permission:parking.bay.manage']);
$router->post('/admin/parking/bays/{uuid}/archive', [\App\Modules\Parking\AdminParkingController::class, 'bayArchive'], ['auth', 'estate', 'permission:parking.bay.manage']);
$router->get('/admin/parking/allocations', [\App\Modules\Parking\AdminParkingController::class, 'allocations'], ['auth', 'estate', 'permission:parking.view']);
$router->post('/admin/parking/allocations', [\App\Modules\Parking\AdminParkingController::class, 'allocationStore'], ['auth', 'estate', 'permission:parking.allocation.manage']);
$router->post('/admin/parking/allocations/{uuid}/end', [\App\Modules\Parking\AdminParkingController::class, 'allocationEnd'], ['auth', 'estate', 'permission:parking.allocation.manage']);
$router->get('/admin/parking/violations', [\App\Modules\Parking\AdminParkingController::class, 'violations'], ['auth', 'estate', 'permission:parking.violation.manage']);
$router->post('/admin/parking/violations', [\App\Modules\Parking\AdminParkingController::class, 'violationStore'], ['auth', 'estate', 'permission:parking.violation.manage']);
$router->post('/admin/parking/violations/{uuid}/resolve', [\App\Modules\Parking\AdminParkingController::class, 'violationResolve'], ['auth', 'estate', 'permission:parking.violation.manage']);

// Guard parking violations (Increment 8)
$router->get('/gate/parking/violations', [\App\Modules\Parking\GuardParkingViolationController::class, 'index'], ['auth', 'estate', 'permission:parking.session.checkin']);
$router->post('/gate/parking/violations', [\App\Modules\Parking\GuardParkingViolationController::class, 'store'], ['auth', 'estate', 'permission:parking.session.checkin']);

// Platform super-admin portal (Increment 9; routes land only after the Inc-9 gate)
$router->get('/platform', [\App\Modules\Platform\PlatformDashboardController::class, 'index'], ['auth']);
$router->get('/platform/estates', [\App\Modules\Platform\PlatformEstateController::class, 'index'], ['auth']);
$router->get('/platform/estates/new', [\App\Modules\Platform\PlatformEstateController::class, 'create'], ['auth']);
$router->post('/platform/estates', [\App\Modules\Platform\PlatformEstateController::class, 'store'], ['auth']);
$router->get('/platform/estates/{uuid}', [\App\Modules\Platform\PlatformEstateController::class, 'show'], ['auth']);
$router->post('/platform/estates/{uuid}/status', [\App\Modules\Platform\PlatformEstateController::class, 'updateStatus'], ['auth']);
$router->get('/platform/plans', [\App\Modules\Platform\PlatformPlanController::class, 'index'], ['auth']);
$router->post('/platform/plans', [\App\Modules\Platform\PlatformPlanController::class, 'store'], ['auth']);
$router->post('/platform/plans/{uuid}/retire', [\App\Modules\Platform\PlatformPlanController::class, 'retire'], ['auth']);
$router->get('/platform/subscriptions', [\App\Modules\Platform\PlatformSubscriptionController::class, 'index'], ['auth']);
$router->post('/platform/subscriptions/{uuid}', [\App\Modules\Platform\PlatformSubscriptionController::class, 'update'], ['auth']);

// Platform billing / invoices (Increment 10; routes land only after the Inc-10 gate)
$router->get('/platform/billing', [\App\Modules\Billing\PlatformBillingController::class, 'index'], ['auth']);
$router->get('/platform/billing/invoices/new', [\App\Modules\Billing\PlatformBillingController::class, 'createForm'], ['auth']);
$router->post('/platform/billing/invoices', [\App\Modules\Billing\PlatformBillingController::class, 'store'], ['auth']);
$router->get('/platform/billing/invoices/{uuid}', [\App\Modules\Billing\PlatformBillingController::class, 'show'], ['auth']);
$router->post('/platform/billing/invoices/{uuid}/status', [\App\Modules\Billing\PlatformBillingController::class, 'updateStatus'], ['auth']);
$router->post('/platform/billing/invoices/{uuid}/payments', [\App\Modules\Billing\PlatformBillingController::class, 'recordPayment'], ['auth']);
$router->post('/platform/billing/payments/{uuid}/status', [\App\Modules\Billing\PlatformBillingController::class, 'updatePaymentStatus'], ['auth']);
