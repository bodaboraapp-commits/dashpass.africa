<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;

/**
 * Platform plans management (Increment 9 §1-§2; frozen keys):
 * `/platform/plans` => plans[] {uuid,code,name,monthly_price_cents,currency,
 * household_limit,gate_device_limit,status,status_label,status_tone,
 * subscriber_count}, errors, old.
 */
final class PlatformPlanController extends PlatformBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'platform.plan.manage');

        $data = $this->shell($context, 'Subscription plans', 'plans') + [
            'plans' => PlatformPlanService::make($context)->list(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('platform/plans', $data);
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'platform.plan.manage');

        try {
            PlatformPlanService::make($context)->create($request->all());
            $this->flashSuccess('Subscription plan created.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/platform/plans');
    }

    public function retire(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'platform.plan.manage');

        try {
            PlatformPlanService::make($context)->retire((string) ($params['uuid'] ?? ''));
            $this->flashSuccess('Subscription plan retired.');
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/platform/plans');
    }
}
