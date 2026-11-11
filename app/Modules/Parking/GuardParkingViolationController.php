<?php

declare(strict_types=1);

namespace App\Modules\Parking;

use App\Core\Config;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\View;
use App\Modules\Visitors\AccessService;

/**
 * Guard parking-violation reporting (Increment 8 §1-§3; frozen keys for T61):
 * `/gate/parking/violations` => violations[] (own reports), bays[], type_options[],
 * gate (session), errors, old; rendered in the guard shell. No API routes.
 */
final class GuardParkingViolationController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $gate = $this->currentGate($context);

        if ($gate === null) {
            return $this->requireGate();
        }

        $service = ParkingAdminService::make($context);
        $data = $this->shell($context, 'Parking violations', 'parking-violations', $gate)
            + $service->guardList()
            + [
                'gate' => ['uuid' => (string) $gate['uuid'], 'name' => (string) $gate['name']],
                'errors' => $this->pullErrors(),
                'old' => $this->pullOld(),
            ];

        return View::render('guard/parking-violations', $data, 'guard');
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $gate = $this->currentGate($context);

        if ($gate === null) {
            return $this->requireGate();
        }

        try {
            ParkingAdminService::make($context)->reportViolation($gate, $request->all());
            Session::flash('success', 'Parking violation reported.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return Response::redirect('/gate/parking/violations');
    }

    /**
     * @return array{id: int, uuid: string, name: string}|null
     */
    private function currentGate(Context $context): ?array
    {
        return AccessService::make($context)->currentGate();
    }

    private function requireGate(): Response
    {
        Session::flash('error', 'Select your gate before reporting a parking violation.');

        return Response::redirect('/gate');
    }

    /**
     * @param array{id: int, uuid: string, name: string} $gate
     * @return array<string, mixed>
     */
    private function shell(Context $context, string $title, string $activeNav, array $gate): array
    {
        $access = AccessService::make($context);
        $user = $context->auth->user() ?? [];
        $flashError = Session::pullFlash('error');
        $flashSuccess = Session::pullFlash('success');

        return [
            'title' => $title,
            'user' => [
                'full_name' => (string) ($user['full_name'] ?? ''),
                'phone' => (string) ($user['phone'] ?? ''),
            ],
            'gate' => (string) $gate['name'],
            'selected_gate' => ['uuid' => (string) $gate['uuid'], 'name' => (string) $gate['name']],
            'gates' => $access->gateOptions(),
            'csrfToken' => Csrf::token(),
            'activeNav' => $activeNav,
            'flashError' => is_string($flashError) ? $flashError : null,
            'flashSuccess' => is_string($flashSuccess) ? $flashSuccess : null,
            'appVersion' => (string) Config::get('app.version', '0.1.0-foundation'),
            'isDev' => (string) Config::get('app.env', 'production') !== 'production',
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function pullErrors(): array
    {
        $errors = Session::pullFlash('errors', []);

        return is_array($errors) ? $errors : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function pullOld(): array
    {
        $old = Session::pullFlash('old', []);

        return is_array($old) ? $old : [];
    }
}
