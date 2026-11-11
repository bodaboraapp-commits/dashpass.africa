<?php

declare(strict_types=1);

namespace App\Modules\Parking;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Modules\Admin\AdminBaseController;

/**
 * Admin parking management (Increment 8 §1-§3; frozen keys for T61).
 *
 * 14 handler methods are ready for the Inc-8 admin routes (overview, zones x2,
 * bays x4, allocations x3, violations x3 + resolve); routes land only after the
 * Lead gate. Inherits the admin-area gate + admin shell.
 */
final class AdminParkingController extends AdminBaseController
{
    public function overview(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $service = ParkingAdminService::make($context);

        $data = $this->shell($context, 'Parking', 'parking')
            + $service->overview()
            + ['errors' => $this->pullErrors(), 'old' => $this->pullOld()];

        return $this->render('admin/parking', $data);
    }

    public function zones(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $service = ParkingAdminService::make($context);

        $data = $this->shell($context, 'Parking zones', 'parking')
            + $service->adminZones()
            + ['errors' => $this->pullErrors(), 'old' => $this->pullOld()];

        return $this->render('admin/parking-zones', $data);
    }

    public function zoneStore(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $this->mutate($context, static fn (): array => ParkingAdminService::make($context)->createZone($request->all()), '/admin/parking/zones');

        return $this->backTo('/admin/parking/zones');
    }

    public function zoneUpdate(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $this->mutate(
            $context,
            static fn (): array => ParkingAdminService::make($context)->updateZone((string) ($params['uuid'] ?? ''), $request->all()),
            '/admin/parking/zones'
        );

        return $this->backTo('/admin/parking/zones');
    }

    public function bays(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $service = ParkingAdminService::make($context);

        $data = $this->shell($context, 'Parking bays', 'parking')
            + $service->adminBays()
            + ['errors' => $this->pullErrors(), 'old' => $this->pullOld()];

        return $this->render('admin/parking-bays', $data);
    }

    public function bayStore(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $this->mutate($context, static fn (): array => ParkingAdminService::make($context)->createBay($request->all()), '/admin/parking/bays');

        return $this->backTo('/admin/parking/bays');
    }

    public function bayUpdate(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $this->mutate(
            $context,
            static fn (): array => ParkingAdminService::make($context)->updateBay((string) ($params['uuid'] ?? ''), $request->all()),
            '/admin/parking/bays'
        );

        return $this->backTo('/admin/parking/bays');
    }

    public function bayArchive(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $this->mutate(
            $context,
            static fn (): array => ParkingAdminService::make($context)->archiveBay((string) ($params['uuid'] ?? '')),
            '/admin/parking/bays'
        );

        return $this->backTo('/admin/parking/bays');
    }

    public function allocations(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $service = ParkingAdminService::make($context);

        $data = $this->shell($context, 'Parking allocations', 'parking')
            + $service->adminAllocations()
            + ['errors' => $this->pullErrors(), 'old' => $this->pullOld()];

        return $this->render('admin/parking-allocations', $data);
    }

    public function allocationStore(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $this->mutate(
            $context,
            static fn (): array => ParkingAdminService::make($context)->createAllocation($request->all()),
            '/admin/parking/allocations'
        );

        return $this->backTo('/admin/parking/allocations');
    }

    public function allocationEnd(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $this->mutate(
            $context,
            static fn (): array => ParkingAdminService::make($context)->endAllocation((string) ($params['uuid'] ?? '')),
            '/admin/parking/allocations'
        );

        return $this->backTo('/admin/parking/allocations');
    }

    public function violations(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $service = ParkingAdminService::make($context);

        $data = $this->shell($context, 'Parking violations', 'parking')
            + $service->adminViolations($request->queryString('status'), $request->queryString('type'))
            + ['errors' => $this->pullErrors(), 'old' => $this->pullOld()];

        return $this->render('admin/parking-violations', $data);
    }

    public function violationStore(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $this->mutate(
            $context,
            static fn (): array => ParkingAdminService::make($context)->createViolation($request->all()),
            '/admin/parking/violations'
        );

        return $this->backTo('/admin/parking/violations');
    }

    public function violationResolve(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $this->mutate(
            $context,
            static fn (): array => ParkingAdminService::make($context)->resolveViolation((string) ($params['uuid'] ?? ''), $request->all()),
            '/admin/parking/violations'
        );

        return $this->backTo('/admin/parking/violations');
    }

    /**
     * @param callable(): array<string, mixed> $operation
     */
    private function mutate(Context $context, callable $operation, string $fallback): void
    {
        try {
            $operation();
            Session::flash('success', 'Parking settings saved.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', []);
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
        }
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
