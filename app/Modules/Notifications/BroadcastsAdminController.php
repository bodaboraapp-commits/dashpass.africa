<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Modules\Admin\AdminBaseController;
use App\Modules\Admin\AdminAccess;

/**
 * Estate Admin WhatsApp broadcast messaging (owner request 2026-11-15).
 *
 * Composer (title, audience, approved template, parameters, preview, schedule),
 * broadcast list, delivery log and "Send now" for scheduled broadcasts.
 */
final class BroadcastsAdminController extends AdminBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $service = WhatsAppBroadcastService::make($context);
        $settings = WhatsAppSettingsService::make($context);
        $estateId = AdminAccess::make($context)->estateId();

        // Scheduled broadcasts send when the Broadcasts screen is opened, so an
        // estate does not need a cron worker to honour the schedule.
        try {
            $service->dispatchDue();
        } catch (\Throwable) {
            // A due send failure is recorded on the broadcast; the page still loads.
        }

        $data = $this->shell($context, 'WhatsApp broadcasts', 'broadcasts') + [
            'broadcasts' => $service->listForEstate(),
            'audience_counts' => $service->audienceCounts($estateId),
            'audiences' => WhatsAppBroadcastService::AUDIENCE_LABELS,
            'whatsapp' => $settings->masked($estateId),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/broadcasts', $data);
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);

        try {
            $broadcast = WhatsAppBroadcastService::make($context)->create($request->all());

            if (($broadcast['status'] ?? '') === 'scheduled') {
                $this->flashSuccess('Broadcast scheduled for ' . (string) ($broadcast['scheduled_at'] ?? '') . '.');
            } elseif (($broadcast['status'] ?? '') === 'sent') {
                $this->flashSuccess('Broadcast sent to ' . (string) ($broadcast['sent_count'] ?? 0) . ' recipient(s).');
            } elseif (($broadcast['status'] ?? '') === 'partial') {
                $this->flashError('Broadcast partly sent: ' . (string) ($broadcast['sent_count'] ?? 0) . ' delivered, ' . (string) ($broadcast['failed_count'] ?? 0) . ' failed.');
            } else {
                $this->flashError('Broadcast failed: check the WhatsApp connection and delivery log.');
            }
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/broadcasts');
    }

    public function show(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $result = WhatsAppBroadcastService::make($context)->find((string) ($params['uuid'] ?? ''));

        if ($result === null) {
            throw HttpException::notFound('BROADCAST_NOT_FOUND', 'That broadcast is not available.');
        }

        $data = $this->shell($context, 'Broadcast', 'broadcasts') + $result;

        return $this->render('admin/broadcast', $data);
    }

    public function send(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            $broadcast = WhatsAppBroadcastService::make($context)->dispatchByUuid($uuid);
            $this->flashSuccess('Broadcast processed: ' . (string) ($broadcast['sent_count'] ?? 0) . ' sent, ' . (string) ($broadcast['failed_count'] ?? 0) . ' failed.');
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/admin/broadcasts/' . rawurlencode($uuid));
    }
}
