<?php

declare(strict_types=1);

namespace App\Modules\Goods;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\View;
use App\Modules\Shared\BuildsShell;

/**
 * Resident goods in/out screen (owner request 2026-11-13).
 *
 * The tenant initiates a goods check-in or check-out request for their household
 * (description, quantity, carrier, vehicle plate, expected time, notes). The
 * Estate Admin approves/declines it, then the guard verifies it at the gate.
 */
final class GoodsController
{
    use BuildsShell;

    public function index(Request $request, array $params, Context $context): Response
    {
        $errors = Session::pullFlash('errors', []);
        $old = Session::pullFlash('old', []);

        $shell = $this->shell($context, 'Goods in and out', 'home');
        $shell['movements'] = GoodsMovementService::make($context)->residentList();
        $shell['directions'] = GoodsMovementService::DIRECTION_LABELS;
        $shell['errors'] = is_array($errors) ? $errors : [];
        $shell['old'] = is_array($old) ? $old : [];

        return View::render('app/goods', $shell, 'app');
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        try {
            GoodsMovementService::make($context)->create($request->all());
            Session::flash('success', 'Goods request sent to the Estate Admin for approval.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return Response::redirect('/app/goods');
    }
}
