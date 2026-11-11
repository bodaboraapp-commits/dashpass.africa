<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\AdminBaseController;

/**
 * Admin estate-wide staff directory (Increment 6 frozen keys):
 * `/admin/staff` => staff, households, errors, old (rendered in the admin shell).
 *
 * Access requires the route permission `household.staff.manage` plus the admin-area
 * gate inherited from {@see AdminBaseController}. The JSON endpoint is implemented
 * for completeness but intentionally not routed in Increment 6 (web-only staff
 * admin; documented limitation).
 */
final class AdminStaffController extends AdminBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $service = StaffService::make($context);

        $data = $this->shell($context, 'Household staff', 'staff') + [
            'staff' => $service->listEstate(),
            'households' => $service->adminHouseholdsForSelect(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/staff', $data);
    }

    public function apiIndex(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        return Response::success('Estate staff directory loaded.', [
            'staff' => StaffService::make($context)->listEstate(),
        ]);
    }
}
