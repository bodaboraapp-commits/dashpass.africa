<?php

declare(strict_types=1);

namespace App\Modules\Goods;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Modules\Admin\AdminBaseController;

/**
 * Estate Admin goods approvals (owner request 2026-11-13).
 *
 * The tenant initiates goods check-in/check-out; the Estate Admin approves or
 * declines here; the guard verifies the approved movement at the gate.
 */
final class GoodsAdminController extends AdminBaseController
{
    private const FILTERS = ['pending', 'approved', 'completed', 'declined', 'all'];

    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $status = (string) $request->queryString('status', 'pending');
        $status = in_array($status, self::FILTERS, true) ? $status : 'pending';

        $data = $this->shell($context, 'Goods in and out', 'goods') + [
            'movements' => GoodsMovementService::make($context)->adminList($status),
            'status_filter' => $status,
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/goods', $data);
    }

    public function approve(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            GoodsMovementService::make($context)->approve(
                (string) ($params['uuid'] ?? ''),
                (string) $request->string('decision_note')
            );
            $this->flashSuccess('Goods request approved. The tenant and guard can see it as approved.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/goods');
    }

    public function decline(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            GoodsMovementService::make($context)->decline(
                (string) ($params['uuid'] ?? ''),
                (string) $request->string('decision_note')
            );
            $this->flashSuccess('Goods request declined. The tenant has been notified.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/goods');
    }
}
