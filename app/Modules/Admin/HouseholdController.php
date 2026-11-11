<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;

/**
 * Admin households screens (frozen contract §7):
 * list => households, buildings, household_types, status_filter, errors, old;
 * detail => household, members, buildings, household_types, errors, old.
 */
final class HouseholdController extends AdminBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $access = AdminAccess::make($context);
        $statusFilter = $access->statusFilter($request->queryString('status'));

        $data = $this->shell($context, 'Households', 'households') + [
            'households' => HouseholdAdminService::make($context)->list($statusFilter),
            'buildings' => $access->buildingsForSelect(),
            'household_types' => $access->householdTypes(),
            'status_filter' => $statusFilter,
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/households', $data);
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            HouseholdAdminService::make($context)->create($request->all());
            $this->flashSuccess('Household created.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/households');
    }

    public function show(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $access = AdminAccess::make($context);
        $result = HouseholdAdminService::make($context)->show((string) ($params['uuid'] ?? ''));

        $data = $this->shell($context, 'Household ' . $result['household']['unit_number'], 'households') + [
            'household' => $result['household'],
            'members' => $result['members'],
            'buildings' => $access->buildingsForSelect(),
            'household_types' => $access->householdTypes(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/household', $data);
    }

    public function update(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            HouseholdAdminService::make($context)->update($uuid, $request->all());
            $this->flashSuccess('Household updated.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/households/' . $uuid);
    }

    public function archive(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            HouseholdAdminService::make($context)->archive($uuid);
            $this->flashSuccess('Household archived.');
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/admin/households/' . $uuid);
    }
}
