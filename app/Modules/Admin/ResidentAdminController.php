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
 * Admin residents screen (frozen contract §7): residents, roles, households,
 * errors, old.
 */
final class ResidentAdminController extends AdminBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $access = AdminAccess::make($context);
        $service = ResidentAdminService::make($context);

        $roles = array_values(array_filter(
            $access->residentRoles(),
            static fn (array $role): bool => (bool) ($role['available'] ?? true)
        ));

        $data = $this->shell($context, 'Residents', 'residents') + [
            'residents' => $service->list(),
            'roles' => array_map(
                static fn (array $role): array => ['value' => $role['value'], 'label' => $role['label']],
                $roles
            ),
            'households' => $access->householdsForSelect(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/residents', $data);
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            ResidentAdminService::make($context)->create($request->all());
            $this->flashSuccess('Resident added and invited by SMS.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/residents');
    }

    public function suspend(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            ResidentAdminService::make($context)->suspend((string) ($params['uuid'] ?? ''));
            $this->flashSuccess('Estate member suspended.');
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/admin/residents');
    }

    public function reactivate(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            ResidentAdminService::make($context)->reactivate((string) ($params['uuid'] ?? ''));
            $this->flashSuccess('Estate member reactivated.');
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/admin/residents');
    }
}
