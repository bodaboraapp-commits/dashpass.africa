<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;

/**
 * Admin estate settings screen (frozen contract §7): settings, errors.
 */
final class SettingsController extends AdminBaseController
{
    public function show(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        $data = $this->shell($context, 'Estate settings', 'settings') + [
            'settings' => SettingsAdminService::make($context)->all(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/settings', $data);
    }

    public function update(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            SettingsAdminService::make($context)->update($request->all());
            $this->flashSuccess('Estate settings updated.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/settings');
    }
}
