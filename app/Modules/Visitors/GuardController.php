<?php

declare(strict_types=1);

namespace App\Modules\Visitors;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Modules\Residents\HouseholdAccessService;

/**
 * Guard PWA + JSON API (contract §5, §6, §7.1).
 *
 * The selected gate lives in the server-side session and is re-validated
 * against the guard's active assignments on every operation. Gate/gate
 * failures use the contract's exact codes: 409 GATE_CONTEXT_REQUIRED when no
 * gate is selected, 403 GATE_NOT_ASSIGNED when the gate is not theirs.
 */
final class GuardController
{
    /* ------------------------------------------------------------------ */
    /* Web (HTML, noscript-friendly)                                      */
    /* ------------------------------------------------------------------ */

    public function webDashboard(Request $request, array $params, Context $context): Response
    {
        $access = AccessService::make($context);
        $gate = $access->currentGate();
        $dashboard = $access->dashboard();

        $view = $this->shell($context, 'Gate dashboard', 'dashboard', $gate) + [
            'stats' => $dashboard['stats'],
            'active' => $dashboard['active'],
            'pending_walkins' => $dashboard['pending_walkins'],
        ];

        return View::render('guard/dashboard', $view, 'guard');
    }

    public function webSelectGate(Request $request, array $params, Context $context): Response
    {
        $validated = Validator::validate(
            $request->all(),
            ['gate_uuid' => 'required|uuid'],
            ['gate_uuid' => 'gate']
        );

        try {
            $gate = AccessService::make($context)->selectGate((string) $validated['gate_uuid']);
            Session::flash('success', 'Gate selected: ' . $gate['name'] . '.');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
        }

        return Response::redirect('/gate');
    }

    public function webVerify(Request $request, array $params, Context $context): Response
    {
        $gate = $this->requireGateForPage($context);

        if ($gate === null) {
            return Response::redirect('/gate');
        }

        $view = $this->shell($context, 'Verify visitor', 'verify', $gate) + [
            'result' => null,
            'error' => Session::pullFlash('error'),
            'prefill_code' => $request->queryString('code'),
        ];

        return View::render('guard/verify', $view, 'guard');
    }

    public function webVerifySubmit(Request $request, array $params, Context $context): Response
    {
        $gate = $this->requireGateForPage($context);

        if ($gate === null) {
            return Response::redirect('/gate');
        }

        $code = trim($request->string('code'));
        $access = AccessService::make($context);

        if ($code === '') {
            $view = $this->shell($context, 'Verify visitor', 'verify', $gate) + [
                'result' => null,
                'error' => 'Enter the visitor PIN or paste their pass code.',
                'prefill_code' => null,
            ];

            return View::render('guard/verify', $view, 'guard');
        }

        try {
            // Owner request (2026-11-13): household passes (Tenant / family member
            // / domestic worker) are gate-scannable too. They use the signed
            // household pass token and never trigger a visitor check-in.
            $householdAccess = HouseholdAccessService::make($context);

            if ($householdAccess->looksLikeToken($code)) {
                $membership = $context->tenant->requireEstate();
                $person = $householdAccess->verifyToken($code, (int) $membership['estate_id']);

                $view = $this->shell($context, 'Verify a pass', 'verify', $gate) + [
                    'result' => $person === null ? null : [
                        'status' => 'approved',
                        'already_inside' => false,
                        'invitation_uuid' => '',
                        'credential_uuid' => '',
                        'visitor_name' => $person['name'],
                        'household_label' => $person['household_label'],
                        'purpose' => $person['role_label'] . ' household pass',
                        'valid_from' => '',
                        'valid_until' => '',
                        'kind' => 'household',
                        'role_label' => $person['role_label'],
                    ],
                    'error' => $person === null ? 'That household pass is invalid or inactive.' : null,
                    'prefill_code' => $code,
                ];

                return View::render('guard/verify', $view, 'guard');
            }

            // Owner request (2026-11-13): the human household pass code is a
            // 4-digit number (visitor PINs are 6 digits, so the spaces do not
            // overlap). Verification scans the estate's active household people;
            // collisions are reported as ambiguous instead of admitting by guess.
            if (preg_match('/^\d{4}$/', $code) === 1) {
                $membership = $context->tenant->requireEstate();
                $check = $householdAccess->verifyShortCode($code, (int) $membership['estate_id']);
                $person = $check['status'] === 'ok' ? $check['person'] : null;
                $error = match ($check['status']) {
                    'ambiguous' => 'That 4-digit code matches more than one household pass. Ask them to scan the QR instead.',
                    'ok' => null,
                    default => 'That household pass code is invalid or inactive.',
                };

                $view = $this->shell($context, 'Verify a pass', 'verify', $gate) + [
                    'result' => $person === null ? null : [
                        'status' => 'approved',
                        'already_inside' => false,
                        'invitation_uuid' => '',
                        'credential_uuid' => '',
                        'visitor_name' => $person['name'],
                        'household_label' => $person['household_label'],
                        'purpose' => $person['role_label'] . ' household pass',
                        'valid_from' => '',
                        'valid_until' => '',
                        'kind' => 'household',
                        'role_label' => $person['role_label'],
                    ],
                    'error' => $error,
                    'prefill_code' => $code,
                ];

                return View::render('guard/verify', $view, 'guard');
            }

            $result = $this->looksLikePassCode($code)
                ? $access->verifyCode($code)
                : $access->verifyPin($code);

            $view = $this->shell($context, 'Verify visitor', 'verify', $gate) + [
                'result' => $result,
                'error' => null,
                'prefill_code' => $code,
            ];

            return View::render('guard/verify', $view, 'guard');
        } catch (HttpException $exception) {
            $view = $this->shell($context, 'Verify visitor', 'verify', $gate) + [
                'result' => null,
                'error' => $exception->publicMessage(),
                'prefill_code' => $code,
            ];

            return View::render('guard/verify', $view, 'guard');
        }
    }

    public function webCheckin(Request $request, array $params, Context $context): Response
    {
        if ($this->requireGateForPage($context) === null) {
            return Response::redirect('/gate');
        }

        $invitationUuid = $request->string('invitation_uuid');
        $credentialUuid = trim($request->string('credential_uuid'));

        try {
            AccessService::make($context)->checkin(
                $invitationUuid,
                $credentialUuid === '' ? null : $credentialUuid
            );
            Session::flash('success', 'Visitor checked in.');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
        }

        return Response::redirect('/gate/active');
    }

    public function webCheckout(Request $request, array $params, Context $context): Response
    {
        if ($this->requireGateForPage($context) === null) {
            return Response::redirect('/gate');
        }

        try {
            AccessService::make($context)->checkout($request->string('invitation_uuid'));
            Session::flash('success', 'Visitor checked out.');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
        }

        return Response::redirect('/gate/active');
    }

    public function webActive(Request $request, array $params, Context $context): Response
    {
        $gate = $this->requireGateForPage($context);

        if ($gate === null) {
            return Response::redirect('/gate');
        }

        $view = $this->shell($context, 'Active visitors', 'active', $gate) + [
            'active' => AccessService::make($context)->activeVisitors(),
        ];

        return View::render('guard/active', $view, 'guard');
    }

    public function webWalkin(Request $request, array $params, Context $context): Response
    {
        $gate = $this->requireGateForPage($context);

        if ($gate === null) {
            return Response::redirect('/gate');
        }

        $estateId = (int) $context->tenant->requireEstate()['estate_id'];
        $households = new UserHouseholdRepository($context->db);
        $errors = Session::pullFlash('errors', []);
        $old = Session::pullFlash('old', []);

        $view = $this->shell($context, 'Walk-in visitor', 'walkin', $gate) + [
            'households' => $households->allForEstate($estateId),
            'pending_walkins' => AccessService::make($context)->pendingWalkIns(),
            'errors' => is_array($errors) ? $errors : [],
            'old' => is_array($old) ? $old : [],
        ];

        return View::render('guard/walkin', $view, 'guard');
    }

    public function webWalkinStore(Request $request, array $params, Context $context): Response
    {
        try {
            $gate = AccessService::make($context)->resolveGate();
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());

            return Response::redirect('/gate');
        }

        try {
            WalkInService::make($context)->createByGuard($gate, $request->all());
            Session::flash('success', 'Walk-in request sent to the household for approval.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return Response::redirect('/gate/walkin');
    }

    /* ------------------------------------------------------------------ */
    /* JSON API                                                           */
    /* ------------------------------------------------------------------ */

    public function apiDashboard(Request $request, array $params, Context $context): Response
    {
        return Response::success('Gate dashboard loaded.', AccessService::make($context)->dashboard());
    }

    public function apiVerifyPin(Request $request, array $params, Context $context): Response
    {
        $validated = Validator::validate(
            $request->all(),
            ['pin' => 'required|string|max:20'],
            ['pin' => 'PIN']
        );

        $result = AccessService::make($context)->verifyPin((string) $validated['pin']);

        return Response::success('Access credential verified.', $result);
    }

    public function apiVerifyCode(Request $request, array $params, Context $context): Response
    {
        $validated = Validator::validate(
            $request->all(),
            ['code' => 'required|string|max:2048'],
            ['code' => 'pass code']
        );

        $result = AccessService::make($context)->verifyCode((string) $validated['code']);

        return Response::success('Access credential verified.', $result);
    }

    public function apiCheckin(Request $request, array $params, Context $context): Response
    {
        $validated = Validator::validate(
            $request->all(),
            ['invitation_uuid' => 'required|uuid', 'credential_uuid' => 'nullable|uuid'],
            ['invitation_uuid' => 'invitation', 'credential_uuid' => 'credential']
        );

        $result = AccessService::make($context)->checkin(
            (string) $validated['invitation_uuid'],
            isset($validated['credential_uuid']) && $validated['credential_uuid'] !== null
                ? (string) $validated['credential_uuid']
                : null
        );

        return Response::success('Visitor checked in.', $result);
    }

    public function apiCheckout(Request $request, array $params, Context $context): Response
    {
        $validated = Validator::validate(
            $request->all(),
            ['invitation_uuid' => 'required|uuid'],
            ['invitation_uuid' => 'invitation']
        );

        $result = AccessService::make($context)->checkout((string) $validated['invitation_uuid']);

        return Response::success('Visitor checked out.', $result);
    }

    public function apiActiveVisitors(Request $request, array $params, Context $context): Response
    {
        $active = AccessService::make($context)->activeVisitors();

        return Response::success('Active visitors loaded.', [
            'active' => $active,
            'count' => count($active),
        ]);
    }

    public function apiPendingApprovals(Request $request, array $params, Context $context): Response
    {
        return Response::success('Pending walk-in approvals loaded.', [
            'pending_walkins' => AccessService::make($context)->pendingWalkIns(),
        ]);
    }

    public function apiWalkinStore(Request $request, array $params, Context $context): Response
    {
        $gate = AccessService::make($context)->resolveGate();
        $walkIn = WalkInService::make($context)->createByGuard($gate, $request->all());

        return Response::success('Walk-in request created.', $walkIn, 201);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{id: int, uuid: string, name: string}|null
     */
    private function requireGateForPage(Context $context): ?array
    {
        $gate = AccessService::make($context)->currentGate();

        if ($gate === null) {
            Session::flash('error', 'Select your gate before continuing.');
        }

        return $gate;
    }

    private function looksLikePassCode(string $code): bool
    {
        return str_contains($code, '/v/') || preg_match('/^[0-9a-f]{64}$/', $code) === 1;
    }

    /**
     * @param array{id: int, uuid: string, name: string}|null $gate
     * @return array<string, mixed>
     */
    private function shell(Context $context, string $title, string $activeNav, ?array $gate): array
    {
        $access = AccessService::make($context);
        $gates = $access->gateOptions();
        $user = $context->auth->user() ?? [];
        $flashError = Session::pullFlash('error');
        $flashSuccess = Session::pullFlash('success');

        return [
            'title' => $title,
            'user' => [
                'full_name' => (string) ($user['full_name'] ?? ''),
                'phone' => (string) ($user['phone'] ?? ''),
            ],
            'gate' => $gate === null ? null : $gate['name'],
            'selected_gate' => $gate === null ? null : ['uuid' => $gate['uuid'], 'name' => $gate['name']],
            'gates' => $gates,
            'csrfToken' => Csrf::token(),
            'activeNav' => $activeNav,
            'flashError' => is_string($flashError) ? $flashError : null,
            'flashSuccess' => is_string($flashSuccess) ? $flashSuccess : null,
            'appVersion' => (string) Config::get('app.version', '0.1.0-foundation'),
            'isDev' => (string) Config::get('app.env', 'production') !== 'production',
        ];
    }
}
