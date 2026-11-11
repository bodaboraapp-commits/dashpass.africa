<?php

declare(strict_types=1);

namespace App\Modules\Notices;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Modules\Admin\AdminBaseController;

/**
 * Admin notices composer/queue/detail (Increment 7 frozen keys):
 * `/admin/notices` => notices, filters {status,category,priority},
 *   status_options, category_options, priority_options, counts, errors, old;
 * `/admin/notices/new` => notice null, category_options, priority_options,
 *   audience_options, errors, old;
 * `/admin/notices/{uuid}` => notice detail, category_options, priority_options,
 *   audience_options, status_options, can_edit/can_publish/can_archive, errors, old.
 *
 * Seven handler methods are ready for the Increment-7 admin routes (index, new,
 * store, show, update, publish, archive); routes land only after the Lead gate.
 */
final class AdminNoticeController extends AdminBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $service = NoticeService::make($context);
        $result = $service->adminList(
            $request->queryString('status'),
            $request->queryString('category'),
            $request->queryString('priority')
        );

        $data = $this->shell($context, 'Estate notices', 'notices') + [
            'notices' => $result['notices'],
            'filters' => $result['filters'],
            'counts' => $result['counts'],
            'status_options' => $service->statusOptions(),
            'category_options' => $service->categories(),
            'priority_options' => $service->priorities(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/notices', $data);
    }

    public function create(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $service = NoticeService::make($context);

        $data = $this->shell($context, 'New notice', 'notices') + [
            'notice' => null,
            'category_options' => $service->categories(),
            'priority_options' => $service->priorities(),
            'audience_options' => $service->audiences(),
            'status_options' => $service->statusOptions(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
            'estate_timezone' => (string) ($context->tenant->requireEstate()['estate_timezone'] ?? 'Africa/Nairobi'),
        ];

        return $this->render('admin/notice-form', $data);
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            $created = NoticeService::make($context)->create($request->all());
            $this->flashSuccess('Notice draft saved.');

            return $this->backTo('/admin/notices/' . (string) ($created['uuid'] ?? ''));
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/notices/new');
    }

    public function show(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $service = NoticeService::make($context);
        $uuid = (string) ($params['uuid'] ?? '');
        $notice = $service->adminView($uuid);

        $data = $this->shell($context, 'Notice: ' . (string) ($notice['title'] ?? ''), 'notices') + [
            'notice' => $notice,
            'category_options' => $service->categories(),
            'priority_options' => $service->priorities(),
            'audience_options' => $service->audiences(),
            'status_options' => $service->statusOptions(),
            'can_edit' => (string) $notice['status'] === 'draft',
            'can_publish' => (string) $notice['status'] === 'draft',
            'can_archive' => (string) $notice['status'] !== 'archived',
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
            'estate_timezone' => (string) ($context->tenant->requireEstate()['estate_timezone'] ?? 'Africa/Nairobi'),
        ];

        return $this->render('admin/notice', $data);
    }

    public function update(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            NoticeService::make($context)->update($uuid, $request->all());
            $this->flashSuccess('Notice draft updated.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/notices/' . $uuid);
    }

    public function publish(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            NoticeService::make($context)->publish($uuid);
            $this->flashSuccess('Notice published.');
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/admin/notices/' . $uuid);
    }

    public function archive(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            NoticeService::make($context)->archive($uuid);
            $this->flashSuccess('Notice archived.');
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/admin/notices/' . $uuid);
    }
}
