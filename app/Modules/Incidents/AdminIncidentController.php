<?php

declare(strict_types=1);

namespace App\Modules\Incidents;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Modules\Admin\AdminBaseController;

/**
 * Admin incident queue/detail/triage (Increment 6, frozen keys):
 * `/admin/incidents` => incidents, filters {status,severity}, status_options,
 * severity_options, errors, old;
 * `/admin/incidents/{uuid}` => incident detail, status_options, errors;
 * admin API => `{incidents, filters}` / detail / updated detail.
 */
final class AdminIncidentController extends AdminBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $service = IncidentService::make($context);
        $result = $service->listAdmin(
            $request->queryString('status'),
            $request->queryString('severity')
        );

        $data = $this->shell($context, 'Incidents', 'incidents') + [
            'incidents' => $result['incidents'],
            'filters' => $result['filters'],
            'status_options' => $service->statusOptions(),
            'severity_options' => $service->severities(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/incidents', $data);
    }

    public function show(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $service = IncidentService::make($context);
        $incident = $service->detailAdmin((string) ($params['uuid'] ?? ''));

        $data = $this->shell($context, 'Incident ' . $incident['summary'], 'incidents') + [
            'incident' => $incident,
            'status_options' => $service->statusOptions(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/incident', $data);
    }

    public function updateStatus(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            IncidentService::make($context)->transition($uuid, $request->all());
            $this->flashSuccess('Incident status updated.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/incidents/' . $uuid);
    }

    public function apiIndex(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $result = IncidentService::make($context)->listAdmin(
            $request->queryString('status'),
            $request->queryString('severity')
        );

        return Response::success('Incident queue loaded.', $result);
    }

    public function apiShow(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $detail = IncidentService::make($context)->detailAdmin((string) ($params['uuid'] ?? ''));

        return Response::success('Incident loaded.', $detail);
    }

    public function apiUpdateStatus(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $updated = IncidentService::make($context)->transition(
            (string) ($params['uuid'] ?? ''),
            $request->all()
        );

        return Response::success('Incident status updated.', $updated);
    }
}
