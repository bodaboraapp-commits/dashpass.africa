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
 * Admin gates screen (frozen contract §7): gates, errors, old.
 */
final class GateAdminController extends AdminBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        $data = $this->shell($context, 'Gates', 'gates') + [
            'gates' => GateAdminService::make($context)->list(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/gates', $data);
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            GateAdminService::make($context)->create($request->all());
            $this->flashSuccess('Gate created.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/gates');
    }

    public function update(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            GateAdminService::make($context)->update((string) ($params['uuid'] ?? ''), $request->all());
            $this->flashSuccess('Gate updated.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/gates');
    }

    public function archive(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            GateAdminService::make($context)->archive((string) ($params['uuid'] ?? ''));
            $this->flashSuccess('Gate archived.');
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/admin/gates');
    }
}
