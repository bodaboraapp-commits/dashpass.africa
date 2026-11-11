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
 * Platform estate management (Increment 9 §1-§2; frozen keys for T65):
 * estates list {estates[], filters, errors, old}; provisioning form
 * {accounts[], plans[], estate_types[], timezones[], errors, old}; estate detail
 * {estate, account, admin, subscription, status_options, errors, old}.
 */
final class PlatformEstateController extends PlatformBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'platform.estate.manage');
        $service = PlatformEstateService::make($context);
        $result = $service->list($request->queryString('status'));

        $data = $this->shell($context, 'Estates', 'estates') + $result + [
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('platform/estates', $data);
    }

    public function create(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'platform.estate.manage');
        $service = PlatformEstateService::make($context);

        $data = $this->shell($context, 'Provision an estate', 'estates') + $service->formOptions() + [
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('platform/estate-form', $data);
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'platform.estate.manage');

        try {
            $estate = PlatformEstateService::make($context)->provision($request->all());
            $this->flashSuccess('Estate provisioned.');

            return $this->backTo('/platform/estates/' . (string) ($estate['estate']['uuid'] ?? ''));
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/platform/estates/new');
    }

    public function show(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'platform.estate.manage');
        $detail = PlatformEstateService::make($context)->show((string) ($params['uuid'] ?? ''));

        $data = $this->shell($context, (string) ($detail['estate']['name'] ?? 'Estate'), 'estates') + $detail + [
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('platform/estate', $data);
    }

    public function updateStatus(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'platform.estate.manage');
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            PlatformEstateService::make($context)->updateStatus($uuid, (string) $request->input('status', ''));
            $this->flashSuccess('Estate status updated.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/platform/estates/' . $uuid);
    }
}
