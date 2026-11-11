<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

/**
 * Admin buildings screens (list/add/edit/detail). Frozen keys per contract §7:
 * buildings list => `buildings`, `building_types`, `errors`, `old`;
 * building detail => `building`, `households`, `building_types`, `errors`, `old`.
 */
final class BuildingController extends AdminBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $access = AdminAccess::make($context);

        $data = $this->shell($context, 'Buildings', 'buildings') + [
            'buildings' => BuildingAdminService::make($context)->list(),
            'building_types' => $access->buildingTypes(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/buildings', $data);
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            BuildingAdminService::make($context)->create($request->all());
            $this->flashSuccess('Building created.');
        } catch (ValidationException $exception) {
            \App\Core\Session::flash('errors', $exception->errors());
            \App\Core\Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            \App\Core\Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/buildings');
    }

    public function show(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $access = AdminAccess::make($context);
        $result = BuildingAdminService::make($context)->show((string) ($params['uuid'] ?? ''));

        $data = $this->shell($context, $result['building']['name'], 'buildings') + [
            'building' => $result['building'],
            'households' => $result['households'],
            'building_types' => $access->buildingTypes(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/building', $data);
    }

    public function update(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            BuildingAdminService::make($context)->update($uuid, $request->all());
            $this->flashSuccess('Building updated.');
        } catch (ValidationException $exception) {
            \App\Core\Session::flash('errors', $exception->errors());
            \App\Core\Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            \App\Core\Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/buildings/' . $uuid);
    }
}
