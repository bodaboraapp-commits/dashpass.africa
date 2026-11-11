<?php

declare(strict_types=1);

namespace App\Modules\Incidents;

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
 * Guard incident reporting screens (Increment 6, frozen keys):
 * `/gate/incidents` + `/gate/incidents/new`: incidents, filters, incident_types,
 * severities, gate (from the session gate), errors, old.
 */
final class GuardIncidentController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $gate = $this->currentGate($context);

        if ($gate === null) {
            return $this->requireGate();
        }

        $service = IncidentService::make($context);
        $result = $service->listGuard(
            $request->queryString('status'),
            $request->queryString('severity')
        );

        $data = $this->shell($context, 'Incidents', 'incidents', $gate) + [
            'incidents' => $result['incidents'],
            'filters' => $result['filters'],
            'incident_types' => $service->types(),
            'severities' => $service->severities(),
            'status_options' => $service->statusOptions(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return View::render('guard/incidents', $data, 'guard');
    }

    public function create(Request $request, array $params, Context $context): Response
    {
        $gate = $this->currentGate($context);

        if ($gate === null) {
            return $this->requireGate();
        }

        $data = $this->shell($context, 'Report an incident', 'incidents', $gate)
            + IncidentService::make($context)->guardFormOptions($gate)
            + ['errors' => $this->pullErrors(), 'old' => $this->pullOld()];

        return View::render('guard/incident', $data, 'guard');
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $gate = $this->currentGate($context);

        if ($gate === null) {
            return $this->requireGate();
        }

        try {
            IncidentService::make($context)->createGuard($gate, $request->all());
            Session::flash('success', 'Incident reported.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return Response::redirect('/gate/incidents');
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
        Session::flash('error', 'Select your gate before reporting an incident.');

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
            'gate' => $gate['name'],
            'selected_gate' => ['uuid' => $gate['uuid'], 'name' => $gate['name']],
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

