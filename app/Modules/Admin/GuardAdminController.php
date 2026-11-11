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
 * Admin guards screen (frozen contract §7): guards, gates (active only), errors, old.
 */
final class GuardAdminController extends AdminBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $access = AdminAccess::make($context);
        $service = GuardAdminService::make($context);

        $data = $this->shell($context, 'Guards', 'guards') + [
            'guards' => $service->list(),
            'gates' => $access->gatesForSelect(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/guards', $data);
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            GuardAdminService::make($context)->create($request->all());
            $this->flashSuccess('Guard created with gate assignments.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/guards');
    }

    public function assignGates(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            GuardAdminService::make($context)->assignGates((string) ($params['uuid'] ?? ''), $request->all());
            $this->flashSuccess('Guard gate assignments updated.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/admin/guards');
    }

    public function archive(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            GuardAdminService::make($context)->archive((string) ($params['uuid'] ?? ''));
            $this->flashSuccess('Guard archived.');
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/admin/guards');
    }
}
