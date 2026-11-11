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
 * Platform subscriptions management (Increment 9 §1-§2; frozen keys):
 * `/platform/subscriptions` => subscriptions[] {uuid,estate_name,plan_name,
 * plan_code,status,status_label,status_tone,billing_cycle,starts_at,ends_at,
 * price_cents,currency}, plans[], status_options[], errors, old.
 */
final class PlatformSubscriptionController extends PlatformBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'platform.subscription.manage');
        $service = PlatformSubscriptionService::make($context);

        $data = $this->shell($context, 'Subscriptions', 'subscriptions') + $service->list() + [
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('platform/subscriptions', $data);
    }

    public function update(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'platform.subscription.manage');

        try {
            PlatformSubscriptionService::make($context)->update(
                (string) ($params['uuid'] ?? ''),
                $request->all()
            );
            $this->flashSuccess('Subscription updated.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/platform/subscriptions');
    }
}
