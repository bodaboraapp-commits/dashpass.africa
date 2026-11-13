<?php

declare(strict_types=1);

namespace App\Modules\Vehicles;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\View;
use App\Modules\Shared\BuildsShell;

/**
 * Resident Vehicles screen + JSON API (Increment 3 contract §2, §3).
 *
 * Frozen T21 view data: `vehicles` (canonical item shape), `errors`, `old`
 * (plus `vehicle_types` for the add form select).
 */
final class VehicleController
{
    use BuildsShell;

    public function webIndex(Request $request, array $params, Context $context): Response
    {
        $errors = Session::pullFlash('errors', []);
        $old = Session::pullFlash('old', []);

        $shell = $this->shell($context, 'Vehicles', 'home');
        $shell['vehicles'] = VehicleService::make($context)->listVisible();
        $shell['vehicle_types'] = VehicleStatus::types();
        $shell['errors'] = is_array($errors) ? $errors : [];
        $shell['old'] = is_array($old) ? $old : [];

        return View::render('app/vehicles', $shell, 'app');
    }

    public function webStore(Request $request, array $params, Context $context): Response
    {
        try {
            VehicleService::make($context)->create($request->all());
            Session::flash('success', 'Vehicle added.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
            Session::flash('old', $request->all());

            if ($exception->errorCode() === 'PLATE_ALREADY_REGISTERED') {
                Session::flash('errors', ['plate_number' => [$exception->publicMessage()]]);
            }
        }

        return Response::redirect('/app/vehicles');
    }

    public function webDeactivate(Request $request, array $params, Context $context): Response
    {
        try {
            VehicleService::make($context)->deactivate((string) ($params['uuid'] ?? ''));
            Session::flash('success', 'Vehicle deactivated.');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
        }

        return Response::redirect('/app/vehicles');
    }

    public function apiIndex(Request $request, array $params, Context $context): Response
    {
        return Response::success('Vehicles loaded.', [
            'vehicles' => VehicleService::make($context)->listVisible(),
        ]);
    }

    public function apiStore(Request $request, array $params, Context $context): Response
    {
        $result = VehicleService::make($context)->create($request->all());

        return Response::success('Vehicle added.', $result['vehicle'], 201);
    }

    public function apiDeactivate(Request $request, array $params, Context $context): Response
    {
        $result = VehicleService::make($context)->deactivate((string) ($params['uuid'] ?? ''));

        return Response::success('Vehicle deactivated.', $result);
    }
}
