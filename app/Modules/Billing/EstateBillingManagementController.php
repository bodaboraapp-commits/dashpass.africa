<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Modules\Admin\AdminBaseController;
use App\Modules\Notifications\BillingNotificationService;
use Throwable;

/**
 * Estate-manager billing cockpit (owner-approved utilities workflow, 2026-11-13).
 *
 * Estate-scoped web forms only (no new permission nodes): the route already
 * requires `billing.subscription.view`, and every action additionally requires
 * an estate_admin / estate_manager role and the caller's own estate id.
 *
 * Surfaces:
 *  - `/admin/utility-bills` readings grid → issue → send;
 *  - `/admin/billing/collections` ageing, manual payment capture, one-tap nudge.
 */
final class EstateBillingManagementController extends AdminBaseController
{
    private const ROLES = ['estate_admin', 'estate_manager'];

    /* ------------------------------------------------------------------ */
    /* Utilities run cockpit                                              */
    /* ------------------------------------------------------------------ */

    public function index(Request $request, array $params, Context $context): Response
    {
        $this->requireBillingManager($context);
        $service = EstateUtilityBillingService::make($context);
        $estateId = $this->estateId($context);
        $month = $service->normaliseMonth($request->queryString('period') ?? '');

        $data = $this->shell($context, 'Utilities', 'overview');
        $data += [
            'month' => $month,
            'run' => $service->run($estateId, $month),
            'collections' => $service->collections($estateId),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/utility-bills', $data);
    }

    public function issue(Request $request, array $params, Context $context): Response
    {
        $this->requireBillingManager($context);
        $service = EstateUtilityBillingService::make($context);
        $estateId = $this->estateId($context);
        $month = $service->normaliseMonth($request->string('period', ''));
        $readings = $request->input('readings', []);
        $readings = is_array($readings) ? $readings : [];

        $result = $service->issue($estateId, $month, $readings, $this->userId($context));

        if (!$result['ok']) {
            $this->flashError('Some readings need attention before bills can be issued.');
            Session::flash('errors', $result['errors']);
            Session::flash('old', $readings);
        } elseif ($result['issued'] === 0) {
            $this->flashError('No new bills were issued for this period — they may already exist.');
        } else {
            $this->flashSuccess(
                'Issued ' . $result['issued'] . ' bill' . ($result['issued'] === 1 ? '' : 's')
                . ' · KES ' . number_format($result['amount_cents'] / 100, 2) . ' for ' . $month . '.'
            );
        }

        return Response::redirect('/admin/utility-bills?period=' . urlencode($month));
    }

    public function send(Request $request, array $params, Context $context): Response
    {
        $this->requireBillingManager($context);
        $service = EstateUtilityBillingService::make($context);
        $estateId = $this->estateId($context);
        $month = $service->normaliseMonth($request->string('period', ''));

        $result = $service->send($estateId, $month, $this->userId($context));

        if ($result['invoices'] === 0) {
            $this->flashError('Nothing to send for ' . $month . ' — issue the bills first.');
        } elseif ($result['sent'] === 0) {
            $this->flashError(
                'No messages were delivered for ' . $result['invoices'] . ' bill(s) — residents may already have been notified, '
                . 'or the estate has no delivery channels configured.'
            );
        } else {
            $this->flashSuccess(
                'Sent ' . $result['sent'] . ' notification' . ($result['sent'] === 1 ? '' : 's')
                . ' for ' . $result['invoices'] . ' bill' . ($result['invoices'] === 1 ? '' : 's')
                . ($result['skipped'] > 0 ? ' · ' . $result['skipped'] . ' skipped' : '')
                . ($result['failed'] > 0 ? ' · ' . $result['failed'] . ' failed' : '') . '.'
            );
        }

        return Response::redirect('/admin/utility-bills?period=' . urlencode($month));
    }

    /* ------------------------------------------------------------------ */
    /* Collections                                                        */
    /* ------------------------------------------------------------------ */

    public function collections(Request $request, array $params, Context $context): Response
    {
        $this->requireBillingManager($context);
        $service = EstateUtilityBillingService::make($context);

        $data = $this->shell($context, 'Collections', 'overview');
        $data += [
            'collections' => $service->collections($this->estateId($context)),
            'month' => $service->currentMonth(),
            'errors' => $this->pullErrors(),
        ];

        return $this->render('admin/billing-collections', $data);
    }

    public function recordPayment(Request $request, array $params, Context $context): Response
    {
        $this->requireBillingManager($context);
        $service = EstateUtilityBillingService::make($context);
        $estateId = $this->estateId($context);
        $uuid = (string) ($params['uuid'] ?? '');
        $invoice = $service->findInvoice($estateId, $uuid);

        if ($invoice === null) {
            throw HttpException::notFound('INVOICE_NOT_FOUND', 'That bill was not found for your estate.');
        }

        if (!in_array((string) $invoice['status'], ['open', 'past_due'], true)) {
            $this->flashError('This bill is already settled or closed.');

            return Response::redirect('/admin/billing/collections');
        }

        $amount = trim($request->string('amount', ''));
        $method = $request->string('method', 'cash');
        $reference = trim($request->string('reference', ''));

        if (!is_numeric($amount) || (float) $amount <= 0) {
            $this->flashError('Enter the amount received (greater than zero).');

            return Response::redirect('/admin/billing/collections');
        }

        if (!in_array($method, BillingPresenter::METHODS, true)) {
            $this->flashError('Choose a valid payment method.');

            return Response::redirect('/admin/billing/collections');
        }

        try {
            BillingService::make($context)->recordPayment($uuid, [
                'method' => $method,
                'amount_cents' => (int) round((float) $amount * 100),
                'reference' => $reference === '' ? null : $reference,
                'status' => 'confirmed',
            ]);

            try {
                BillingNotificationService::make($context)->deliverInvoiceEvent($invoice, 'paid');
            } catch (Throwable) {
                // Receipt delivery is best effort; the payment itself is recorded.
            }

            $this->flashSuccess(
                'Payment recorded for ' . (string) $invoice['invoice_number']
                . ' · KES ' . number_format((float) $amount, 2) . '. Receipt sent to the household.'
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $first = $errors === [] ? null : reset($errors);
            $this->flashError(is_string($first) ? $first : 'The payment details are not valid.');
        } catch (HttpException $exception) {
            $this->flashError($exception->getMessage());
        } catch (Throwable) {
            $this->flashError('The payment could not be recorded. Try again.');
        }

        return Response::redirect('/admin/billing/collections');
    }

    public function remind(Request $request, array $params, Context $context): Response
    {
        $this->requireBillingManager($context);
        $service = EstateUtilityBillingService::make($context);
        $result = $service->remind($this->estateId($context), (string) ($params['uuid'] ?? ''), $this->userId($context));

        if ($result['sent'] > 0) {
            $this->flashSuccess('Reminder sent to the household.');
        } elseif ($result['skipped'] > 0) {
            $this->flashError('A reminder was already sent for this bill — it will not be duplicated.');
        } else {
            $this->flashError('The reminder could not be delivered; check the estate notification channels.');
        }

        return Response::redirect('/admin/billing/collections');
    }

    /* ------------------------------------------------------------------ */
    /* Guards                                                             */
    /* ------------------------------------------------------------------ */

    private function requireBillingManager(Context $context): void
    {
        $membership = $context->tenant->active();

        if ($membership === null) {
            throw HttpException::forbidden('BILLING_MANAGER_ONLY', 'Billing is available to estate managers only.');
        }

        $role = $context->db->selectOne('SELECT name FROM roles WHERE id = :id LIMIT 1', [
            'id' => (int) $membership['role_id'],
        ]);
        $name = (string) ($role['name'] ?? '');

        if (!in_array($name, self::ROLES, true)) {
            throw HttpException::forbidden('BILLING_MANAGER_ONLY', 'Billing is available to estate managers only.');
        }
    }

    private function estateId(Context $context): int
    {
        return (int) ($context->tenant->requireEstate()['estate_id']);
    }

    private function userId(Context $context): int
    {
        $user = $context->auth->requireUser();

        return (int) $user['id'];
    }
}
