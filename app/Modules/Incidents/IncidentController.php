<?php

declare(strict_types=1);

namespace App\Modules\Incidents;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\View;
use App\Modules\Shared\BuildsShell;

/**
 * Resident incident screens + API (Increment 6, frozen keys):
 * `/app/incidents` => incidents, filters, errors, old;
 * `/app/incidents/new` => incident_types, severities, gates, households, invitations, errors, old;
 * resident API => `{incidents, filters}` / detail / created detail.
 */
final class IncidentController
{
    use BuildsShell;

    public function webIndex(Request $request, array $params, Context $context): Response
    {
        $service = IncidentService::make($context);
        $result = $service->listResident(
            $request->queryString('status'),
            $request->queryString('severity')
        );

        $data = $this->shell($context, 'Incidents', 'home') + [
            'incidents' => $result['incidents'],
            'filters' => $result['filters'],
            'incident_types' => $service->types(),
            'severities' => $service->severities(),
            'status_options' => $service->statusOptions(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return View::render('app/incidents', $data, 'app');
    }

    public function webCreate(Request $request, array $params, Context $context): Response
    {
        $service = IncidentService::make($context);

        $data = $this->shell($context, 'Report an incident', 'home') + $service->residentFormOptions() + [
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
            'estate_timezone' => (string) ($context->tenant->requireEstate()['estate_timezone'] ?? 'Africa/Nairobi'),
        ];

        return View::render('app/incident', $data, 'app');
    }

    public function webStore(Request $request, array $params, Context $context): Response
    {
        try {
            IncidentService::make($context)->createResident($request->all());
            Session::flash('success', 'Incident reported.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return Response::redirect('/app/incidents');
    }

    public function apiIndex(Request $request, array $params, Context $context): Response
    {
        $result = IncidentService::make($context)->listResident(
            $request->queryString('status'),
            $request->queryString('severity')
        );

        return Response::success('Incidents loaded.', $result);
    }

    public function apiStore(Request $request, array $params, Context $context): Response
    {
        $created = IncidentService::make($context)->createResident($request->all());

        return Response::success('Incident reported.', $created, 201);
    }

    public function apiShow(Request $request, array $params, Context $context): Response
    {
        $detail = IncidentService::make($context)->detailResident((string) ($params['uuid'] ?? ''));

        return Response::success('Incident loaded.', $detail);
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

