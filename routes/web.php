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
use App\Modules\Authentication\AuthController;
use App\Modules\Estates\EstateController;
use App\Modules\Platform\HealthController;
use App\Modules\Residents\ResidentController;
use App\Modules\Visitors\GuardController;
use App\Modules\Visitors\VisitorPassController;

/** @var Router $router */

// Public
$router->get('/', [AuthController::class, 'webWelcome']);
$router->get('/health', [HealthController::class, 'show']);
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

// Resident bookings screen (layout increment)
$router->get('/app/bookings', [ResidentController::class, 'webBookings'], ['auth', 'estate']);

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
