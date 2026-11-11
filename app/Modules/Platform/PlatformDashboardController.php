<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * Platform dashboard (Increment 9 §2): real stats + dashboard-only platform audit
 * + permission-filtered links (frozen keys: stats, recent_audit, quick_links).
 */
final class PlatformDashboardController extends PlatformBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $access = $this->access($context);
        $dashboard = PlatformDashboardService::make($context)->dashboard($access);

        $data = $this->shell($context, 'Platform overview', 'overview') + $dashboard + [
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('platform/dashboard', $data);
    }
}
