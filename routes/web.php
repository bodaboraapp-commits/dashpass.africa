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
use App\Modules\Marketing\AboutController;
use App\Modules\Marketing\BlogController;
use App\Modules\Marketing\FeedController;
use App\Modules\Marketing\HelpController;
use App\Modules\Marketing\LlmsController;
use App\Modules\Marketing\RobotsController;
use App\Modules\Marketing\SitemapController;
use App\Modules\Billing\AdminUtilityChargeController;
use App\Modules\Billing\PlatformBillingAutomationController;
use App\Modules\Billing\PlatformBillingSettingsController;
use App\Modules\Billing\ResidentBillingController;
use App\Modules\Estates\EstateController;
use App\Modules\Navigation\NavigationController;
use App\Modules\Notices\NoticeImageController;
use App\Modules\Notifications\NotificationController;
use App\Modules\Parking\ParkingController;
use App\Modules\Payments\PaymentWebhookController;
use App\Modules\Payments\PublicPaymentController;
use App\Modules\Platform\HealthController;
use App\Modules\Platform\ManifestController;
use App\Modules\Residents\ResidentController;
use App\Modules\Vehicles\VehicleController;
use App\Modules\Visitors\GuardController;
use App\Modules\Visitors\VisitorPassController;

/** @var Router $router */

// Public
$router->get('/', [AuthController::class, 'webWelcome']);
// Public marketing pages (guest layout; no auth).
$router->get('/about', [AboutController::class, 'show']);
$router->get('/blog', [BlogController::class, 'index']);
$router->get('/blog/{slug}', [BlogController::class, 'show']);
$router->get('/help', [HelpController::class, 'index']);
$router->post('/help/contact', [HelpController::class, 'contact'], ['throttle:contact,5,600']);
// Public legal + privacy centre (guest layout; no auth). The Privacy, Terms and
// Data Policy pages are real, indexable pages linked from the landing footer;
// the choices pages hold the opt in/opt out and delete-my-data request forms.
$router->get('/privacy', [\App\Modules\Privacy\LegalController::class, 'privacy']);
$router->get('/terms', [\App\Modules\Privacy\LegalController::class, 'terms']);
$router->get('/data-policy', [\App\Modules\Privacy\LegalController::class, 'dataPolicy']);
$router->get('/privacy/choices', [\App\Modules\Privacy\LegalController::class, 'choices']);
$router->get('/data-deletion', [\App\Modules\Privacy\LegalController::class, 'dataDeletion']);
$router->post('/privacy/choices', [\App\Modules\Privacy\LegalController::class, 'submitRequest'], ['throttle:privacy,5,600']);
// Public SEO / AI-crawler documents (intentionally cacheable; see SecurityHeaders).
$router->get('/sitemap.xml', [SitemapController::class, 'show']);
$router->get('/robots.txt', [RobotsController::class, 'show']);
$router->get('/llms.txt', [LlmsController::class, 'index']);
$router->get('/llms-full.txt', [LlmsController::class, 'full']);
$router->get('/feed.xml', [FeedController::class, 'show']);
$router->get('/health', [HealthController::class, 'show']);
// Dynamic PWA manifest so each deployment carries its own product name.
$router->get('/manifest.json', [ManifestController::class, 'show']);
$router->get('/v/{token}', [VisitorPassController::class, 'show']);

// Authentication
$router->get('/login', [AuthController::class, 'webLogin'], ['guest']);
// Pre-flight registration check: the Firebase login UI verifies the number is
// registered on the platform BEFORE triggering the SMS OTP (no wasted OTPs).
$router->post('/login/check-phone', [AuthController::class, 'webCheckPhone'], ['guest', 'throttle:phone-check,30,60']);
$router->post('/login/otp', [AuthController::class, 'webRequestOtp'], ['guest', 'throttle:web-login,20,60']);
$router->post('/login/verify', [AuthController::class, 'webVerifyOtp'], ['guest', 'throttle:web-verify,30,60']);
$router->post('/logout', [AuthController::class, 'webLogout'], ['auth']);

// Authenticated application shell
$router->get('/app', [ResidentController::class, 'webHome'], ['auth', 'estate']);
$router->get('/app/account', [AccountController::class, 'webAccount'], ['auth']);
$router->get('/app/estates', [EstateController::class, 'webEstateSwitcher'], ['auth']);
$router->post('/app/estates/{uuid}/switch', [EstateController::class, 'webSwitch'], ['auth']);
$router->get('/app/no-estate', [AuthController::class, 'webNoEstate'], ['auth']);
$router->get('/app/navigation', [NavigationController::class, 'index'], ['auth', 'estate']);

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

// Resident activity timeline (own-household visitor activity; no dedicated permission node)
$router->get('/app/activity', [ResidentController::class, 'webActivity'], ['auth', 'estate']);

// Owner request (2026-11-13): the "My access pass" QR is a full page, not a modal.
// The page lists household passes (Tenant / family members / domestic workers).
$router->get('/app/access', [ResidentController::class, 'webAccess'], ['auth', 'estate']);
$router->get('/app/access/qr.png', [ResidentController::class, 'webAccessQr'], ['auth', 'estate']);

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
$router->get('/app/incidents/{uuid}', [\App\Modules\Incidents\IncidentController::class, 'webShow'], ['auth', 'estate', 'permission:visitor.incident.create']);

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
$router->get('/notices/{uuid}/image', [NoticeImageController::class, 'show'], ['auth', 'estate', 'permission:notice.view']);

// Inc-13 P2a: in-app notification inbox + mark-read (owner priority; auth-only ownership)
$router->get('/notifications', [NotificationController::class, 'webIndex'], ['auth']);
$router->post('/notifications/{uuid}/read', [NotificationController::class, 'webRead'], ['auth']);

// Inc-13 P4: settings, automation summary, signed pay page, provider webhooks,
// resident bills and estate utility overview. Webhooks are CSRF-exempt via the
// reviewed security.csrf.exempt_paths allowlist (provider signature is auth).
$router->get('/platform/settings/notifications', [PlatformBillingSettingsController::class, 'notifications'], ['auth']);
$router->post('/platform/settings/notifications', [PlatformBillingSettingsController::class, 'saveNotifications'], ['auth']);
$router->get('/platform/settings/payments', [PlatformBillingSettingsController::class, 'payments'], ['auth']);
$router->post('/platform/settings/payments', [PlatformBillingSettingsController::class, 'savePayments'], ['auth']);
$router->get('/platform/billing/automation', [PlatformBillingAutomationController::class, 'index'], ['auth']);
$router->post('/platform/billing/pay-links', [PlatformBillingAutomationController::class, 'createPayLink'], ['auth']);
$router->get('/pay/{token}', [PublicPaymentController::class, 'show']);
$router->post('/webhooks/payments/mpesa', [PaymentWebhookController::class, 'mpesa']);
$router->post('/webhooks/payments/card', [PaymentWebhookController::class, 'card']);
$router->get('/app/bills', [ResidentBillingController::class, 'index'], ['auth']);
$router->get('/app/bills/{uuid}', [ResidentBillingController::class, 'show'], ['auth']);
$router->post('/app/bills/{uuid}/pay-link', [ResidentBillingController::class, 'payLink'], ['auth']);
$router->get('/admin/utility-bills', [AdminUtilityChargeController::class, 'index'], ['auth', 'estate', 'permission:billing.subscription.view']);

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

// Inc-13 P2/P3 platform billing schedules (owner frontend backend)
$router->get('/platform/billing/schedules', [\App\Modules\Billing\PlatformBillingScheduleController::class, 'index'], ['auth']);
$router->get('/platform/billing/schedules/new', [\App\Modules\Billing\PlatformBillingScheduleController::class, 'createForm'], ['auth']);
$router->post('/platform/billing/schedules', [\App\Modules\Billing\PlatformBillingScheduleController::class, 'store'], ['auth']);
$router->get('/platform/billing/schedules/{uuid}', [\App\Modules\Billing\PlatformBillingScheduleController::class, 'show'], ['auth']);
$router->post('/platform/billing/schedules/{uuid}/edit', [\App\Modules\Billing\PlatformBillingScheduleController::class, 'update'], ['auth']);
$router->post('/platform/billing/schedules/{uuid}/toggle', [\App\Modules\Billing\PlatformBillingScheduleController::class, 'toggle'], ['auth']);
$router->post('/platform/billing/schedules/{uuid}/run-now', [\App\Modules\Billing\PlatformBillingScheduleController::class, 'runNow'], ['auth']);

// Inc-11 estate billing read-only overview/reports/export
$router->get('/admin/billing', [\App\Modules\Billing\EstateBillingController::class, 'overview'], ['auth', 'estate']);
$router->get('/admin/billing/invoices/{uuid}', [\App\Modules\Billing\EstateBillingController::class, 'invoice'], ['auth', 'estate']);
$router->get('/admin/billing/reports', [\App\Modules\Billing\EstateBillingController::class, 'reports'], ['auth', 'estate']);
$router->get('/admin/billing/export.csv', [\App\Modules\Billing\EstateBillingController::class, 'export'], ['auth', 'estate']);
