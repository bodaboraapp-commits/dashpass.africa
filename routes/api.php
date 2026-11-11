<?php

declare(strict_types=1);

/*
 * JSON API routes (prefix /api/v1).
 *
 * Base route table: docs/foundation-contract.md §8.
 * Visitor & access module: docs/visitor-access-module-contract.md §5.
 *
 * CSRF protection is global; API clients send the token in `X-CSRF-Token`
 * (or the `_token` field) on every non-GET request.
 */

use App\Core\Router;
use App\Modules\Authentication\AuthController;
use App\Modules\Estates\EstateController;
use App\Modules\Parking\ParkingController;
use App\Modules\Platform\HealthController;
use App\Modules\Residents\ResidentController;
use App\Modules\Vehicles\VehicleController;
use App\Modules\Visitors\GuardController;
use App\Modules\Visitors\VisitorApiController;

/** @var Router $router */

// Public
$router->get('/api/v1/health', [HealthController::class, 'show']);
$router->get('/api/v1/auth/csrf', [AuthController::class, 'apiCsrf']);
$router->post('/api/v1/auth/request-otp', [AuthController::class, 'apiRequestOtp'], ['throttle:otp-request,20,60']);
$router->post('/api/v1/auth/verify-otp', [AuthController::class, 'apiVerifyOtp'], ['throttle:otp-verify,30,60']);

// Authentication
$router->post('/api/v1/auth/logout', [AuthController::class, 'apiLogout'], ['auth']);
$router->get('/api/v1/auth/me', [AuthController::class, 'apiMe'], ['auth']);

// Estate context
$router->get('/api/v1/estates', [EstateController::class, 'apiIndex'], ['auth']);
$router->post('/api/v1/estates/{uuid}/switch', [EstateController::class, 'apiSwitch'], ['auth']);
$router->get('/api/v1/estate', [EstateController::class, 'apiShow'], ['auth', 'estate', 'permission:estate.view']);
$router->get('/api/v1/estate/settings', [EstateController::class, 'apiSettings'], ['auth', 'estate', 'permission:estate.settings.manage']);
$router->get('/api/v1/estate/audit-logs', [EstateController::class, 'apiAuditLogs'], ['auth', 'estate', 'permission:audit.view']);

// Resident dashboard
$router->get('/api/v1/resident/dashboard', [ResidentController::class, 'apiDashboard'], ['auth', 'estate', 'permission:estate.view']);

// Resident visitor invitations + walk-in approvals (contract §5)
$router->get('/api/v1/resident/invitations', [VisitorApiController::class, 'index'], ['auth', 'estate', 'permission:visitor.invitation.view_own']);
$router->post('/api/v1/resident/invitations', [VisitorApiController::class, 'store'], ['auth', 'estate', 'permission:visitor.invitation.create', 'throttle:invite,20,600']);
$router->get('/api/v1/resident/invitations/{uuid}', [VisitorApiController::class, 'show'], ['auth', 'estate', 'permission:visitor.invitation.view_own']);
$router->post('/api/v1/resident/invitations/{uuid}/revoke', [VisitorApiController::class, 'revoke'], ['auth', 'estate', 'permission:visitor.invitation.revoke']);
$router->post('/api/v1/resident/invitations/{uuid}/extend', [VisitorApiController::class, 'extend'], ['auth', 'estate', 'permission:visitor.invitation.extend']);
$router->post('/api/v1/resident/invitations/{uuid}/rotate-pin', [VisitorApiController::class, 'rotatePin'], ['auth', 'estate', 'permission:visitor.invitation.create']);
$router->post('/api/v1/resident/walkins/{uuid}/approve', [VisitorApiController::class, 'approveWalkIn'], ['auth', 'estate', 'permission:visitor.walkin.approve']);
$router->post('/api/v1/resident/walkins/{uuid}/reject', [VisitorApiController::class, 'rejectWalkIn'], ['auth', 'estate', 'permission:visitor.walkin.reject']);

// Resident vehicles + parking (Increment 3 §3)
$router->get('/api/v1/resident/vehicles', [VehicleController::class, 'apiIndex'], ['auth', 'estate', 'permission:vehicle.view_own']);
$router->post('/api/v1/resident/vehicles', [VehicleController::class, 'apiStore'], ['auth', 'estate', 'permission:vehicle.manage_own', 'throttle:vehicle,20,600']);
$router->post('/api/v1/resident/vehicles/{uuid}/deactivate', [VehicleController::class, 'apiDeactivate'], ['auth', 'estate', 'permission:vehicle.manage_own']);
$router->get('/api/v1/resident/parking', [ParkingController::class, 'apiResidentParking'], ['auth', 'estate', 'permission:parking.view']);
$router->get('/api/v1/parking/availability', [ParkingController::class, 'apiAvailability'], ['auth', 'estate', 'permission:parking.view']);

// Guard parking session API (Increment 3 §3)
$router->post('/api/v1/gate/parking/sessions/checkin', [ParkingController::class, 'apiGuardCheckin'], ['auth', 'estate', 'permission:visitor.checkin']);
$router->post('/api/v1/gate/parking/sessions/checkout', [ParkingController::class, 'apiGuardCheckout'], ['auth', 'estate', 'permission:visitor.checkout']);
$router->get('/api/v1/gate/parking/active', [ParkingController::class, 'apiGuardActive'], ['auth', 'estate', 'permission:visitor.checkin']);

// Guard API (contract §5)
$router->get('/api/v1/gate/dashboard', [GuardController::class, 'apiDashboard'], ['auth', 'estate', 'permission:gate.view']);
$router->post('/api/v1/gate/verify-pin', [GuardController::class, 'apiVerifyPin'], ['auth', 'estate', 'permission:visitor.checkin']);
$router->post('/api/v1/gate/verify-code', [GuardController::class, 'apiVerifyCode'], ['auth', 'estate', 'permission:visitor.checkin']);
$router->post('/api/v1/gate/visitors/checkin', [GuardController::class, 'apiCheckin'], ['auth', 'estate', 'permission:visitor.checkin']);
$router->post('/api/v1/gate/visitors/checkout', [GuardController::class, 'apiCheckout'], ['auth', 'estate', 'permission:visitor.checkout']);
$router->get('/api/v1/gate/active-visitors', [GuardController::class, 'apiActiveVisitors'], ['auth', 'estate', 'permission:visitor.checkin']);
$router->get('/api/v1/gate/pending-approvals', [GuardController::class, 'apiPendingApprovals'], ['auth', 'estate', 'permission:visitor.checkin']);
$router->post('/api/v1/gate/walkins', [GuardController::class, 'apiWalkinStore'], ['auth', 'estate', 'permission:visitor.walkin.create']);

// Resident staff + incident APIs (Increment 6)
$router->get('/api/v1/resident/staff', [\App\Modules\Staff\StaffController::class, 'apiIndex'], ['auth', 'estate', 'permission:household.staff.manage']);
$router->post('/api/v1/resident/staff', [\App\Modules\Staff\StaffController::class, 'apiStore'], ['auth', 'estate', 'permission:household.staff.manage']);
$router->get('/api/v1/resident/incidents', [\App\Modules\Incidents\IncidentController::class, 'apiIndex'], ['auth', 'estate', 'permission:visitor.incident.create']);
$router->post('/api/v1/resident/incidents', [\App\Modules\Incidents\IncidentController::class, 'apiStore'], ['auth', 'estate', 'permission:visitor.incident.create']);
$router->get('/api/v1/resident/incidents/{uuid}', [\App\Modules\Incidents\IncidentController::class, 'apiShow'], ['auth', 'estate', 'permission:visitor.incident.create']);

// Admin incident APIs (Increment 6)
$router->get('/api/v1/admin/incidents', [\App\Modules\Incidents\AdminIncidentController::class, 'apiIndex'], ['auth', 'estate', 'permission:visitor.incident.manage']);
$router->get('/api/v1/admin/incidents/{uuid}', [\App\Modules\Incidents\AdminIncidentController::class, 'apiShow'], ['auth', 'estate', 'permission:visitor.incident.manage']);
$router->patch('/api/v1/admin/incidents/{uuid}', [\App\Modules\Incidents\AdminIncidentController::class, 'apiUpdateStatus'], ['auth', 'estate', 'permission:visitor.incident.manage']);
