<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Uuid;
use App\Core\Validator;
use App\Core\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Throwable;

/**
 * Platform billing service (Increment 10 §3).
 *
 * Invoices: transactional per-estate/month numbering `INV-{YYYYMM}-{0001}`, status
 * workflow (draft → open → paid|void|past_due; paid → void), `paid` requires
 * confirmed payments covering the amount. Payments: append-only ledger with
 * pending → confirmed|failed and confirmed → refunded; refunds of a covering payment
 * reopen the invoice (past_due when overdue, else open) and clear paid_at. All
 * mutations audit uuid/amount/currency/status metadata only (no PII).
 */
final class BillingService
{
    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly BillingRepository $repo,
        private readonly BillingPresenter $presenter
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db, new BillingRepository($context->db), new BillingPresenter());
    }

    /* ------------------------------------------------------------------ */
    /* Read surfaces                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Billing overview. The `month` filter is the invoice **creation month in UTC**
     * (YYYY-MM), matching the INV-{YYYYMM}-nnnn numbering series; it is not the
     * billing period or due month.
     *
     * @return array<string, mixed>
     */
    public function overview(?string $status = null, ?string $estateUuid = null, ?string $month = null): array
    {
        $status = $status !== null && in_array($status, BillingPresenter::INVOICE_STATUSES, true) ? $status : null;
        $estateUuid = $estateUuid !== null && $estateUuid !== '' ? $estateUuid : null;
        $month = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : null;

        return [
            'stats' => $this->repo->stats(),
            'invoices' => array_map(
                static fn (array $row): array => BillingPresenter::invoice($row),
                $this->repo->invoices($status, $estateUuid, $month)
            ),
            'filters' => [
                'status' => $status ?? 'all',
                'estate' => $estateUuid ?? 'all',
                'month' => $month ?? 'all',
            ],
            'status_options' => $this->presenter->invoiceStatuses(),
            'estate_options' => BillingPresenter::estateOptions($this->repo->estateOptions()),
            'month_options' => $this->repo->invoiceMonths(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formOptions(?string $subscriptionUuid = null, ?string $estateUuid = null): array
    {
        $defaultAmount = null;
        $defaultCurrency = 'KES';
        $defaultSubUuid = null;
        $defaultEstateUuid = null;

        if ($subscriptionUuid !== null && $subscriptionUuid !== '') {
            $subscription = $this->repo->findSubscription($subscriptionUuid);

            if ($subscription !== null) {
                $months = (string) $subscription['billing_cycle'] === 'annual' ? 12 : 1;
                $defaultAmount = (int) $subscription['monthly_price_cents'] * $months;
                $defaultCurrency = (string) $subscription['currency'];
                $defaultSubUuid = (string) $subscription['uuid'];
                $defaultEstateUuid = $this->estateUuidById((int) $subscription['estate_id']);
            }
        }

        if ($defaultEstateUuid === null && $estateUuid !== null && $estateUuid !== '') {
            $estate = $this->repo->findEstate($estateUuid);
            $defaultEstateUuid = $estate === null ? null : (string) $estate['uuid'];
        }

        return [
            'estates' => BillingPresenter::estateOptions($this->repo->estateOptions()),
            'subscriptions' => BillingPresenter::subscriptionOptions($this->repo->subscriptionOptions()),
            'default_amount_cents' => $defaultAmount,
            'default_currency' => $defaultCurrency,
            'default_estate_uuid' => $defaultEstateUuid,
            'default_subscription_uuid' => $defaultSubUuid,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function showInvoice(string $uuid): array
    {
        $invoice = $this->findInvoice($uuid);
        $payments = $this->repo->paymentsForInvoice((int) $invoice['id']);

        return [
            'invoice' => BillingPresenter::invoiceDetail($invoice, $payments),
            'status_options' => $this->presenter->invoiceStatuses(),
            'payment_method_options' => $this->presenter->paymentMethods(),
            'payment_status_options' => $this->presenter->paymentStatuses(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Invoices                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createInvoice(array $input): array
    {
        $clean = Validator::validate($input, [
            'estate_uuid' => 'required|uuid',
            'subscription_uuid' => 'nullable|uuid',
            'amount_cents' => 'required|integer|between:1,4294967295',
            'currency' => 'nullable|string|max:3',
            'due_at' => 'nullable|string|max:40',
            'period_start' => 'nullable|string|max:20',
            'period_end' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:500',
            'save_draft' => 'nullable|boolean',
        ], [
            'estate_uuid' => 'estate',
            'subscription_uuid' => 'subscription',
            'amount_cents' => 'amount',
            'currency' => 'currency',
            'due_at' => 'due date',
            'period_start' => 'period start',
            'period_end' => 'period end',
            'notes' => 'notes',
        ]);

        $estate = $this->repo->findEstate((string) $clean['estate_uuid']);

        if ($estate === null) {
            throw HttpException::notFound('ESTATE_NOT_FOUND', 'That estate is not available.');
        }

        if ((string) $estate['status'] === 'archived') {
            throw new HttpException(422, 'ESTATE_ARCHIVED', 'Archived estates cannot be invoiced.');
        }

        $subscription = null;
        if (isset($clean['subscription_uuid']) && is_string($clean['subscription_uuid']) && $clean['subscription_uuid'] !== '') {
            $subscription = $this->repo->findSubscription((string) $clean['subscription_uuid']);

            if ($subscription === null || (int) $subscription['estate_id'] !== (int) $estate['id']) {
                throw new ValidationException([
                    'subscription_uuid' => ['Select a subscription that belongs to the chosen estate.'],
                ]);
            }
        }

        $currency = strtoupper(trim((string) ($clean['currency'] ?? '')));

        if ($currency === '') {
            $currency = $subscription !== null ? (string) $subscription['currency'] : 'KES';
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ValidationException(['currency' => ['Use a 3-letter currency code.']]);
        }

        $periodStart = $this->parseDate(isset($clean['period_start']) ? (string) $clean['period_start'] : null);
        $periodEnd = $this->parseDate(isset($clean['period_end']) ? (string) $clean['period_end'] : null);

        if ($periodStart !== null && $periodEnd !== null && $periodStart > $periodEnd) {
            throw new ValidationException(['period_end' => ['The period end must be on or after the period start.']]);
        }

        $dueAt = $this->parseMoment(isset($clean['due_at']) ? (string) $clean['due_at'] : null);
        $status = (bool) ($clean['save_draft'] ?? false) ? 'draft' : 'open';
        $user = $this->context->auth->requireUser();
        $now = Clock::nowDb();
        $uuid = Uuid::v4();
        $invoiceId = 0;
        $invoiceNumber = '';

        $this->db->transaction(function () use (
            &$invoiceId,
            &$invoiceNumber,
            $estate,
            $subscription,
            $clean,
            $currency,
            $periodStart,
            $periodEnd,
            $dueAt,
            $status,
            $user,
            $now,
            $uuid
        ): void {
            // Invoice series: INV-{YYYYMM}-{0001}, sequence per estate+month. The
            // month prefix is UTC (gmdate) and created_at is stored UTC, so the
            // numbering window and the list/stats month filters agree.
            $prefix = 'INV-' . gmdate('Ym') . '-';
            $attempts = 0;

            do {
                $attempts++;
                $latest = $this->repo->latestInvoiceNumber((int) $estate['id'], $prefix);
                $next = 1;

                if ($latest !== null) {
                    $parts = explode('-', (string) $latest['invoice_number']);
                    $next = max(1, (int) end($parts) + 1);
                }

                $invoiceNumber = $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);

                try {
                    $invoiceId = $this->repo->insertInvoice([
                        'uuid' => $uuid,
                        'estate_id' => (int) $estate['id'],
                        'account_id' => (int) $estate['account_id'],
                        'subscription_id' => $subscription === null ? null : (int) $subscription['id'],
                        'invoice_number' => $invoiceNumber,
                        'status' => $status,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'amount_cents' => (int) $clean['amount_cents'],
                        'currency' => $currency,
                        'due_at' => $dueAt,
                        'paid_at' => null,
                        'notes' => isset($clean['notes']) && $clean['notes'] !== null ? trim((string) $clean['notes']) : null,
                        'created_by_user_id' => (int) $user['id'],
                        'updated_by_user_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    break;
                } catch (PDOException $exception) {
                    // Only a duplicate-key error (MySQL 1062) on
                    // UNIQUE(estate_id, invoice_number) is retryable; every other
                    // constraint/DB failure must surface immediately.
                    $isDuplicateKey = (string) $exception->getCode() === '23000'
                        && (int) ($exception->errorInfo[1] ?? 0) === 1062;

                    if (!$isDuplicateKey || $attempts >= 5) {
                        throw $exception;
                    }
                }
            } while (true);

            $this->audit('billing.invoice_created', 'billing_invoice', $invoiceId, [
                'invoice_uuid' => $uuid,
                'invoice_number' => $invoiceNumber,
                'estate_uuid' => (string) $estate['uuid'],
                'amount_cents' => (int) $clean['amount_cents'],
                'currency' => $currency,
                'status' => $status,
            ]);
        });

        return $this->showInvoice($uuid);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateInvoiceStatus(string $uuid, array $input): array
    {
        $invoice = $this->findInvoice($uuid);
        $clean = Validator::validate(
            $input,
            ['status' => 'required|in:' . implode(',', BillingPresenter::INVOICE_STATUSES)],
            ['status' => 'status']
        );
        $current = (string) $invoice['status'];
        $target = (string) $clean['status'];

        if ($current === 'void') {
            throw new HttpException(409, 'INVOICE_CLOSED', 'This invoice is void and cannot be changed.', [
                'errors' => ['status' => ['This invoice is void and cannot be changed.']],
            ]);
        }

        if ($target === $current) {
            return $this->showInvoice((string) $invoice['uuid']);
        }

        $allowed = [
            'draft' => ['open'],
            'open' => ['paid', 'void', 'past_due'],
            'past_due' => ['paid', 'void'],
            'paid' => ['void'],
        ];

        if (!in_array($target, $allowed[$current] ?? [], true)) {
            throw new HttpException(
                422,
                'INVALID_STATUS_TRANSITION',
                'That status change is not allowed for this invoice.',
                ['errors' => ['status' => ['That status change is not allowed for this invoice.']]]
            );
        }

        if ($target === 'paid') {
            $confirmed = $this->repo->confirmedTotal((int) $invoice['id']);

            if ($confirmed <= 0) {
                throw new HttpException(422, 'PAYMENT_REQUIRED', 'Record a confirmed payment before marking this invoice paid.');
            }

            if ($confirmed < (int) $invoice['amount_cents']) {
                throw new HttpException(
                    422,
                    'PAYMENT_AMOUNT_MISMATCH',
                    'Confirmed payments do not cover the invoice amount yet.'
                );
            }
        }

        $user = $this->context->auth->requireUser();
        $now = Clock::nowDb();
        $fields = [
            'status' => $target,
            'updated_by_user_id' => (int) $user['id'],
            'updated_at' => $now,
        ];

        if ($target === 'paid') {
            $fields['paid_at'] = $now;
        }

        $this->repo->updateInvoice((int) $invoice['id'], $fields);
        $this->audit('billing.invoice_status_changed', 'billing_invoice', (int) $invoice['id'], [
            'invoice_uuid' => (string) $invoice['uuid'],
            'estate_uuid' => (string) $invoice['estate_uuid'],
            'old_status' => $current,
            'new_status' => $target,
            'amount_cents' => (int) $invoice['amount_cents'],
            'currency' => (string) $invoice['currency'],
        ]);

        return $this->showInvoice((string) $invoice['uuid']);
    }

    /* ------------------------------------------------------------------ */
    /* Payments                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function recordPayment(string $invoiceUuid, array $input): array
    {
        $invoice = $this->findInvoice($invoiceUuid);

        if (!in_array((string) $invoice['status'], ['open', 'past_due'], true)) {
            throw new HttpException(422, 'INVOICE_CLOSED', 'Payments can only be recorded on open or past-due invoices.');
        }

        $clean = Validator::validate($input, [
            'method' => 'required|in:' . implode(',', BillingPresenter::METHODS),
            'amount_cents' => 'required|integer|between:1,4294967295',
            'reference' => 'nullable|string|max:80',
            'currency' => 'nullable|string|max:3',
            'status' => 'nullable|in:pending,confirmed',
            'paid_at' => 'nullable|string|max:40',
        ], [
            'method' => 'payment method',
            'amount_cents' => 'amount',
            'reference' => 'reference',
            'currency' => 'currency',
            'status' => 'payment status',
            'paid_at' => 'paid date',
        ]);

        $currency = strtoupper(trim((string) ($clean['currency'] ?? '')));

        if ($currency === '') {
            $currency = (string) $invoice['currency'];
        }

        if ($currency !== (string) $invoice['currency']) {
            throw new ValidationException(['currency' => ['The payment currency must match the invoice currency.']]);
        }

        $status = isset($clean['status']) && is_string($clean['status']) ? (string) $clean['status'] : 'confirmed';
        $user = $this->context->auth->requireUser();
        $now = Clock::nowDb();
        $paidAt = null;

        if ($status === 'confirmed') {
            $paidAt = $this->parseMoment(isset($clean['paid_at']) ? (string) $clean['paid_at'] : null) ?? $now;
        }

        $uuid = Uuid::v4();
        $paymentId = $this->repo->insertPayment([
            'uuid' => $uuid,
            'estate_id' => (int) $invoice['estate_id'],
            'invoice_id' => (int) $invoice['id'],
            'method' => (string) $clean['method'],
            'reference' => isset($clean['reference']) && $clean['reference'] !== null
                ? trim((string) $clean['reference']) : null,
            'amount_cents' => (int) $clean['amount_cents'],
            'currency' => $currency,
            'status' => $status,
            'paid_at' => $paidAt,
            'recorded_by_user_id' => (int) $user['id'],
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit('billing.payment_recorded', 'billing_payment', $paymentId, [
            'payment_uuid' => $uuid,
            'invoice_uuid' => (string) $invoice['uuid'],
            'estate_uuid' => (string) $invoice['estate_uuid'],
            'amount_cents' => (int) $clean['amount_cents'],
            'currency' => $currency,
            'method' => (string) $clean['method'],
            'status' => $status,
        ]);

        if ($status === 'confirmed' && (string) $invoice['status'] !== 'paid') {
            // Partial payments keep the invoice open/past_due (balance visible);
            // overpayment is recorded as a payment and auto-marks the invoice paid
            // once the confirmed total covers the amount.
            $confirmed = $this->repo->confirmedTotal((int) $invoice['id']);

            if ($confirmed >= (int) $invoice['amount_cents']) {
                $this->markInvoicePaid($invoice, $now, (int) $user['id']);
            }
        }

        return $this->showInvoice((string) $invoice['uuid']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updatePaymentStatus(string $paymentUuid, array $input): array
    {
        $payment = $this->repo->findPayment($paymentUuid);

        if ($payment === null) {
            throw HttpException::notFound('PAYMENT_NOT_FOUND', 'That payment is not available.');
        }

        $current = (string) $payment['status'];

        if (BillingPresenter::isTerminalPayment($current)) {
            throw new HttpException(409, 'PAYMENT_CLOSED', 'This payment is closed and cannot be changed.', [
                'errors' => ['status' => ['This payment is closed and cannot be changed.']],
            ]);
        }

        $clean = Validator::validate(
            $input,
            ['status' => 'required|in:' . implode(',', BillingPresenter::PAYMENT_STATUSES)],
            ['status' => 'status']
        );
        $target = (string) $clean['status'];

        if ($target === $current) {
            return $this->showInvoice((string) $payment['invoice_uuid']);
        }

        $allowed = [
            'pending' => ['confirmed', 'failed'],
            'confirmed' => ['refunded'],
        ];

        if (!in_array($target, $allowed[$current] ?? [], true)) {
            throw new HttpException(
                422,
                'INVALID_STATUS_TRANSITION',
                'That payment status change is not allowed.',
                ['errors' => ['status' => ['That payment status change is not allowed.']]]
            );
        }

        $now = Clock::nowDb();
        $fields = ['status' => $target, 'updated_at' => $now];

        if ($target === 'confirmed' && $payment['paid_at'] === null) {
            $fields['paid_at'] = $now;
        }

        $this->repo->updatePayment((int) $payment['id'], $fields);
        $this->audit('billing.payment_status_changed', 'billing_payment', (int) $payment['id'], [
            'payment_uuid' => (string) $payment['uuid'],
            'invoice_uuid' => (string) $payment['invoice_uuid'],
            'estate_uuid' => (string) $payment['estate_uuid'],
            'old_status' => $current,
            'new_status' => $target,
            'amount_cents' => (int) $payment['amount_cents'],
            'currency' => (string) $payment['currency'],
        ]);

        if ($target === 'confirmed') {
            // Confirming a previously pending payment can complete the invoice total.
            $invoice = $this->repo->findInvoice((string) $payment['invoice_uuid']);

            if ($invoice !== null
                && in_array((string) $invoice['status'], ['open', 'past_due'], true)
                && $this->repo->confirmedTotal((int) $invoice['id']) >= (int) $invoice['amount_cents']) {
                $user = $this->context->auth->requireUser();
                $this->markInvoicePaid($invoice, $now, (int) $user['id']);
            }
        }

        if ($target === 'refunded') {
            $this->reopenInvoiceIfUncovered((string) $payment['invoice_uuid']);
        }

        return $this->showInvoice((string) $payment['invoice_uuid']);
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $invoice
     */
    private function markInvoicePaid(array $invoice, string $now, int $userId): void
    {
        $this->repo->updateInvoice((int) $invoice['id'], [
            'status' => 'paid',
            'paid_at' => $now,
            'updated_by_user_id' => $userId,
            'updated_at' => $now,
        ]);
        $this->audit('billing.invoice_status_changed', 'billing_invoice', (int) $invoice['id'], [
            'invoice_uuid' => (string) $invoice['uuid'],
            'estate_uuid' => (string) $invoice['estate_uuid'],
            'old_status' => (string) $invoice['status'],
            'new_status' => 'paid',
            'amount_cents' => (int) $invoice['amount_cents'],
            'currency' => (string) $invoice['currency'],
        ]);
    }

    private function reopenInvoiceIfUncovered(string $invoiceUuid): void
    {
        $invoice = $this->repo->findInvoice($invoiceUuid);

        if ($invoice === null || (string) $invoice['status'] !== 'paid') {
            return;
        }

        $confirmed = $this->repo->confirmedTotal((int) $invoice['id']);

        // Refund of one of several confirmed payments: if the remaining confirmed
        // total still covers the invoice (including overpayments) the invoice stays
        // paid. Only an uncovered paid invoice re-opens to past_due/open.
        if ($confirmed >= (int) $invoice['amount_cents']) {
            return;
        }

        $dueAt = Clock::fromDb(isset($invoice['due_at']) ? (string) $invoice['due_at'] : null);
        $newStatus = $dueAt !== null && $dueAt < Clock::now() ? 'past_due' : 'open';

        $this->repo->updateInvoice((int) $invoice['id'], [
            'status' => $newStatus,
            'paid_at' => null,
            'updated_at' => Clock::nowDb(),
        ]);
        $this->audit('billing.invoice_status_changed', 'billing_invoice', (int) $invoice['id'], [
            'invoice_uuid' => (string) $invoice['uuid'],
            'estate_uuid' => (string) $invoice['estate_uuid'],
            'old_status' => 'paid',
            'new_status' => $newStatus,
            'amount_cents' => (int) $invoice['amount_cents'],
            'currency' => (string) $invoice['currency'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function findInvoice(string $uuid): array
    {
        $invoice = $this->repo->findInvoice($uuid);

        if ($invoice === null) {
            throw HttpException::notFound('INVOICE_NOT_FOUND', 'That invoice is not available.');
        }

        return $invoice;
    }

    private function parseMoment(?string $raw): ?string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $value) === 1) {
                $moment = new DateTimeImmutable($value, new DateTimeZone($this->timezone()));
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $moment = new DateTimeImmutable($value . ' 00:00:00', new DateTimeZone($this->timezone()));
            } else {
                $moment = Clock::parse($value);
            }
        } catch (Throwable) {
            throw new ValidationException(['due_at' => ['Enter a valid date/time.']]);
        }

        return Clock::toDb($moment->setTimezone(Clock::utc()));
    }

    private function parseDate(?string $raw): ?string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        try {
            $moment = new DateTimeImmutable($value, new DateTimeZone($this->timezone()));
        } catch (Throwable) {
            throw new ValidationException(['period_start' => ['Enter a valid date.']]);
        }

        return $moment->format('Y-m-d');
    }

    private function estateUuidById(int $estateId): ?string
    {
        $value = $this->db->scalar('SELECT uuid FROM estates WHERE id = :id', ['id' => $estateId]);

        return $value === null ? null : (string) $value;
    }

    private function timezone(): string
    {
        $membership = $this->context->tenant->active();
        $timezone = is_array($membership) ? (string) ($membership['estate_timezone'] ?? '') : '';

        return $timezone !== '' ? $timezone : (string) Config::get('app.timezone', 'Africa/Nairobi');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, string $entityType, ?int $entityId, array $metadata): void
    {
        $this->context->audit->record($action, $entityType, $entityId, $metadata, null);
    }
}
