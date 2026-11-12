<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\View;
use App\Modules\Platform\PlatformBaseController;

/**
 * Platform billing schedules screen (Inc-13 P2/P3 backend).
 */
final class PlatformBillingScheduleController extends PlatformBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.subscription.view');
        $service = PlatformBillingScheduleService::make($context);
        $data = $this->shell($context, 'Billing schedules', 'billing') + [
            'schedules' => $service->all($request->queryString('status'), $request->queryString('scope')),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->renderOrFallback('platform/billing-schedules', $data, 'Billing schedules', '/platform/billing/schedules/new');
    }

    public function createForm(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.subscription.manage');
        $data = $this->shell($context, 'New billing schedule', 'billing')
            + PlatformBillingScheduleService::make($context)->formOptions()
            + ['errors' => $this->pullErrors(), 'old' => $this->pullOld()];

        return $this->renderOrFallback('platform/billing-schedule-form', $data, 'New billing schedule', '/platform/billing/schedules');
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.subscription.manage');

        try {
            $created = PlatformBillingScheduleService::make($context)->create($request->all());
            $this->flashSuccess('Billing schedule created.');

            return $this->backTo('/platform/billing/schedules/' . (string) $created['uuid']);
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/platform/billing/schedules/new');
    }

    public function show(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.subscription.view');
        $service = PlatformBillingScheduleService::make($context);
        $data = $this->shell($context, 'Billing schedule', 'billing')
            + ['schedule' => $service->find((string) ($params['uuid'] ?? ''))]
            + ['errors' => $this->pullErrors(), 'old' => $this->pullOld()];

        return $this->renderOrFallback('platform/billing-schedule', $data, 'Billing schedule', null);
    }

    public function update(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.subscription.manage');

        try {
            PlatformBillingScheduleService::make($context)->update((string) ($params['uuid'] ?? ''), $request->all());
            $this->flashSuccess('Billing schedule updated.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/platform/billing/schedules/' . (string) ($params['uuid'] ?? ''));
    }

    public function toggle(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.subscription.manage');

        try {
            PlatformBillingScheduleService::make($context)->toggle((string) ($params['uuid'] ?? ''));
            $this->flashSuccess('Schedule status updated.');
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/platform/billing/schedules/' . (string) ($params['uuid'] ?? ''));
    }

    public function runNow(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.subscription.manage');

        try {
            $result = PlatformBillingScheduleService::make($context)->runNowPreview((string) ($params['uuid'] ?? ''));

            if ($request->expectsJson()) {
                return Response::json(['success' => true, 'data' => $result]);
            }

            Session::flash('success', (string) $result['message']);
            Session::flash('run_preview', $result);
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/platform/billing/schedules/' . (string) ($params['uuid'] ?? ''));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderOrFallback(string $view, array $data, string $title, ?string $action): Response
    {
        if (View::exists($view)) {
            return View::render($view, $data, 'platform');
        }

        $body = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . e($title) . '</title></head><body><main><h1>' . e($title) . '</h1>';

        if (isset($data['schedules']) && is_array($data['schedules'])) {
            $body .= '<ul>';

            foreach ($data['schedules'] as $schedule) {
                $body .= '<li><a href="/platform/billing/schedules/' . e((string) $schedule['uuid']) . '">'
                    . e((string) ($schedule['name'] ?? $schedule['scope'])) . '</a> · '
                    . e((string) $schedule['status']) . ' · next ' . e((string) $schedule['next_run_at']) . '</li>';
            }

            $body .= '</ul>';
        }

        if (isset($data['schedule']) && is_array($data['schedule'])) {
            $body .= '<pre>' . e((string) json_encode($data['schedule'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
            $body .= '<form method="post" action="/platform/billing/schedules/' . e((string) $data['schedule']['uuid']) . '/run-now">'
                . csrf_field() . '<button type="submit">Dry-run now</button></form>';
        }

        if ($action !== null) {
            $body .= '<p><a href="' . e($action) . '">New schedule</a></p>';
        }

        return Response::html($body . '<p><a href="/platform/billing">Back to billing</a></p></main></body></html>');
    }
}
