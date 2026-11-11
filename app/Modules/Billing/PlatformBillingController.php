<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Modules\Platform\PlatformBaseController;

/**
 * Platform billing screens (Increment 10 §2-§4; frozen keys for T78).
 *
 * 7 handlers ready for `/platform/billing*` routes: dashboard/list, new-invoice
 * form, invoice create, invoice detail, invoice status, record payment, payment
 * status. Platform area gate + exact `billing.*` permission checks (approved
 * middleware decision: `['auth']` routes + controller gates).
 */
final class PlatformBillingController extends PlatformBaseController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.invoice.view');
        $service = BillingService::make($context);
        $result = $service->overview(
            $request->queryString('status'),
            $request->queryString('estate'),
            $request->queryString('month')
        );

        $data = $this->shell($context, 'Billing', 'billing') + $result + [
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('platform/billing', $data);
    }

    public function createForm(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.payment.manage');
        $service = BillingService::make($context);

        $data = $this->shell($context, 'Create an invoice', 'billing')
            + $service->formOptions($request->queryString('subscription'), $request->queryString('estate'))
            + ['errors' => $this->pullErrors(), 'old' => $this->pullOld()];

        return $this->render('platform/billing-invoice-form', $data);
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.payment.manage');

        try {
            $created = BillingService::make($context)->createInvoice($request->all());
            $this->flashSuccess('Invoice created.');

            return $this->backTo('/platform/billing/invoices/' . (string) ($created['invoice']['uuid'] ?? ''));
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/platform/billing/invoices/new');
    }

    public function show(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.invoice.view');
        $result = BillingService::make($context)->showInvoice((string) ($params['uuid'] ?? ''));

        $data = $this->shell($context, 'Invoice ' . (string) ($result['invoice']['invoice_number'] ?? ''), 'billing')
            + $result
            + ['errors' => $this->pullErrors(), 'old' => $this->pullOld()];

        return $this->render('platform/billing-invoice', $data);
    }

    public function updateStatus(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.payment.manage');
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            BillingService::make($context)->updateInvoiceStatus($uuid, $request->all());
            $this->flashSuccess('Invoice status updated.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/platform/billing/invoices/' . $uuid);
    }

    public function recordPayment(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.payment.manage');
        $uuid = (string) ($params['uuid'] ?? '');

        try {
            BillingService::make($context)->recordPayment($uuid, $request->all());
            $this->flashSuccess('Payment recorded.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/platform/billing/invoices/' . $uuid);
    }

    public function updatePaymentStatus(Request $request, array $params, Context $context): Response
    {
        $this->requirePlatformArea($context);
        $this->requirePlatformPermission($context, 'billing.payment.manage');

        try {
            $result = BillingService::make($context)->updatePaymentStatus((string) ($params['uuid'] ?? ''), $request->all());
            $this->flashSuccess('Payment status updated.');

            return $this->backTo('/platform/billing/invoices/' . (string) ($result['invoice']['uuid'] ?? ''));
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/platform/billing');
    }
}
