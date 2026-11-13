<?php

declare(strict_types=1);

/**
 * Platform billing, invoice detail (billing-invoices contract §4).
 *
 * Invoice meta + balance, append-only payment ledger with record-payment form,
 * payment status actions (confirm/fail/refund) and invoice status actions
 * (draft/open/past_due/paid/void) with terminal-state handling. Amounts are
 * integer cents rendered as currency.
 *
 * Variables (T77 frozen keys, defensive): $invoice detail, $payments [],
 * $status_options [], $payment_method_options [], $errors [], $old [] +
 * platform shell data.
 */

require_once __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
// The detail record may arrive as $invoice / $invoice_record / $record, pick the
// first non-empty array before normalising into $invoice.
$invoiceData = [];
foreach (['invoice', 'invoice_record', 'record'] as $invoiceKey) {
    if (isset($$invoiceKey) && is_array($$invoiceKey) && $$invoiceKey !== []) {
        $invoiceData = $$invoiceKey;
        break;
    }
}
$invoice = $invoiceData;
$payments = isset($payments) && is_array($payments) ? $payments : [];
if ($payments === [] && isset($invoice['payments']) && is_array($invoice['payments'])) {
    // T77 invoiceDetail nests the ledger under invoice.payments.
    $payments = $invoice['payments'];
}
$statusOptions = isset($status_options) && is_array($status_options) ? $status_options : [];
$methodOptions = isset($payment_method_options) && is_array($payment_method_options) ? $payment_method_options : [];
$paymentStatusOptions = isset($payment_status_options) && is_array($payment_status_options) ? $payment_status_options : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
// T102: platform shell carries platform.* only, so billing capability is derived
// from platform roles; the route still enforces the exact billing.* permission.
$platformRoles = isset($platform_roles) && is_array($platform_roles) ? array_map('strval', $platform_roles) : [];
$canBillingRole = in_array('platform_super_admin', $platformRoles, true)
    || in_array('platform_finance', $platformRoles, true);
$canManage = in_array('billing.payment.manage', array_map('strval', $permissions), true) || $canBillingRole;

$uuid = platform_text($invoice, ['uuid']);
$number = platform_text($invoice, ['invoice_number', 'number'], 'Invoice');
$status = platform_text($invoice, ['status'], 'open');
$statusLabel = platform_text($invoice, ['status_label']) ?: platform_humanize($status);
$statusTone = platform_text($invoice, ['status_tone']);
$amountValue = $invoice['amount_cents'] ?? null;
$currency = platform_text($invoice, ['currency'], 'KES');
$amount = platform_money($amountValue, $currency);
$estateName = platform_text($invoice, ['estate_name', 'estate'], 'Estate');
$accountName = platform_text($invoice, ['account_name', 'account']);
$planName = platform_text($invoice, ['plan_name', 'subscription_plan_name', 'plan']);
$periodStart = platform_text($invoice, ['period_start']);
$periodEnd = platform_text($invoice, ['period_end']);
$dueAt = platform_text($invoice, ['due_at', 'due_date']);
$paidAt = platform_text($invoice, ['paid_at']);
$notes = platform_text($invoice, ['notes']);
$createdAt = platform_text($invoice, ['created_at']);
$isTerminal = $status === 'void';
$canRecordPayment = in_array($status, ['open', 'past_due'], true);

$statusMap = platform_option_map($statusOptions, [
    'draft' => 'Draft',
    'open' => 'Open',
    'past_due' => 'Past due',
    'paid' => 'Paid',
    'void' => 'Void',
]);
$methodMap = platform_option_map($methodOptions, [
    'mpesa' => 'M-Pesa',
    'bank' => 'Bank transfer',
    'card' => 'Card',
    'cash' => 'Cash',
    'other' => 'Other',
]);
// Frozen payment_status_options drive the record-payment status choices; a new
// payment may only be recorded as pending or confirmed.
$paymentStatusMap = platform_option_map($paymentStatusOptions, [
    'confirmed' => 'Confirmed',
    'pending' => 'Pending',
]);
$recordStatusMap = [];
foreach (['confirmed', 'pending'] as $recordStatus) {
    $recordStatusMap[$recordStatus] = $paymentStatusMap[$recordStatus] ?? platform_humanize($recordStatus);
}

$paymentRows = array_values(array_filter($payments, 'is_array'));
$confirmedCents = 0;
foreach ($paymentRows as $payment) {
    if (platform_text($payment, ['status']) === 'confirmed' && isset($payment['amount_cents']) && is_numeric($payment['amount_cents'])) {
        $confirmedCents += (int) $payment['amount_cents'];
    }
}
if ($confirmedCents === 0) {
    // T77 frozen invoice shape exposes confirmed_cents/confirmed_total_cents.
    foreach (['confirmed_total_cents', 'confirmed_cents'] as $confirmedKey) {
        if (isset($invoice[$confirmedKey]) && is_numeric($invoice[$confirmedKey])) {
            $confirmedCents = (int) $invoice[$confirmedKey];
            break;
        }
    }
}
$balanceCents = null;
if (isset($invoice['balance_cents']) && is_numeric($invoice['balance_cents'])) {
    $balanceCents = (int) $invoice['balance_cents'];
} elseif (is_numeric($amountValue)) {
    $balanceCents = max(0, (int) $amountValue - $confirmedCents);
}
$balance = $balanceCents !== null ? platform_money($balanceCents, $currency) : '';
$paidTotal = platform_money($confirmedCents, $currency);

$oldValue = static function (string $field, string $default = '') use ($old): string {
    $candidate = $old[$field] ?? $default;

    return is_scalar($candidate) ? trim((string) $candidate) : $default;
};
$paymentStatusClass = static function (string $paymentStatus): string {
    return match ($paymentStatus) {
        'confirmed' => 'status--active',
        'pending' => 'status--pending',
        'failed', 'refunded' => 'status--inactive',
        default => 'status--inactive',
    };
};
?>
<div class="platform-page__head">
  <div>
    <nav class="platform-breadcrumb" aria-label="Breadcrumb">
      <a href="/platform/billing">Billing</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page"><?= e($number) ?></span>
    </nav>
    <h1 class="platform-page__title"><?= e($number) ?></h1>
    <p class="platform-page__lede">
      <?= e($estateName) ?><?php if ($accountName !== ''): ?> · <?= e($accountName) ?><?php endif; ?>
      <?php if ($planName !== ''): ?> · <?= e($planName) ?><?php endif; ?>
    </p>
  </div>
  <div class="platform-page__actions">
    <span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
    <a class="btn btn--ghost btn--sm" href="/platform/billing">All invoices</a>
  </div>
</div>

<?= admin_summary($errors) ?>

<section class="platform-section" aria-labelledby="invoice-meta-title">
  <div class="platform-section__head">
    <h2 class="platform-section__title" id="invoice-meta-title">Invoice</h2>
  </div>

  <?php if ($uuid === '' && $number === 'Invoice'): ?>
    <div class="platform-empty">
      <span class="platform-empty__icon" aria-hidden="true"><?= admin_icon('wallet') ?></span>
      <p class="platform-empty__title">Invoice unavailable</p>
      <p class="platform-empty__text">This invoice could not be loaded. Return to billing and try again.</p>
      <a class="btn btn--outline" href="/platform/billing">Back to billing</a>
    </div>
  <?php else: ?>
    <div class="platform-card">
      <dl class="platform-detail-grid">
        <div class="platform-detail-grid__item">
          <dt>Amount</dt>
          <dd><?= e($amount !== '' ? $amount : '-') ?></dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Balance</dt>
          <dd>
            <span class="platform-balance platform-balance--lg <?= $status === 'past_due' ? 'platform-balance--due' : '' ?>">
              <?= e($balance !== '' ? $balance : '-') ?>
            </span>
          </dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Confirmed payments</dt>
          <dd><?= e($paidTotal) ?></dd>
        </div>
        <?php if (isset($invoice['pending_total_cents']) && is_numeric($invoice['pending_total_cents'])): ?>
          <div class="platform-detail-grid__item">
            <dt>Pending payments</dt>
            <dd><?= e(platform_money((int) $invoice['pending_total_cents'], $currency)) ?></dd>
          </div>
        <?php endif; ?>
        <div class="platform-detail-grid__item">
          <dt>Due</dt>
          <dd><?= e($dueAt !== '' ? admin_datetime($dueAt, $timezone, 'd M Y') : '-') ?></dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Period</dt>
          <dd>
            <?= e($periodStart !== '' ? admin_datetime($periodStart, $timezone, 'd M Y') : '-') ?>
            →
            <?= e($periodEnd !== '' ? admin_datetime($periodEnd, $timezone, 'd M Y') : '-') ?>
          </dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Paid</dt>
          <dd><?= e($paidAt !== '' ? admin_datetime($paidAt, $timezone, 'd M Y') : 'Not paid yet') ?></dd>
        </div>
        <div class="platform-detail-grid__item">
          <dt>Created</dt>
          <dd><?= e($createdAt !== '' ? admin_datetime($createdAt, $timezone) : '-') ?></dd>
        </div>
      </dl>

      <?php if ($notes !== ''): ?>
        <div class="platform-invoice__notes">
          <h3 class="platform-section__title">Notes</h3>
          <p class="platform-invoice__text"><?= nl2br(e($notes)) ?></p>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<section class="platform-section" aria-labelledby="invoice-payments-title">
  <div class="platform-section__head">
    <div>
      <h2 class="platform-section__title" id="invoice-payments-title">Payment ledger</h2>
      <p class="platform-section__hint">Append-only payments. Failed and refunded payments stay on the ledger for audit.</p>
    </div>
    <?php if ($paymentRows !== []): ?>
      <span class="chip"><?= e((string) count($paymentRows)) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($paymentRows !== []): ?>
    <div class="platform-table-wrap">
      <table class="platform-table">
        <thead>
          <tr>
            <th scope="col">Date</th>
            <th scope="col">Method</th>
            <th scope="col">Reference</th>
            <th scope="col">Amount</th>
            <th scope="col">Status</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($paymentRows as $payment): ?>
            <?php
            $paymentUuid = platform_text($payment, ['uuid']);
            $paymentStatus = platform_text($payment, ['status'], 'confirmed');
            $paymentStatusLabel = platform_text($payment, ['status_label']) ?: platform_humanize($paymentStatus);
            $paymentPaidAt = platform_text($payment, ['paid_at', 'created_at']);
            $method = platform_text($payment, ['method']);
            $methodLabel = platform_text($payment, ['method_label']) ?: ($methodMap[$method] ?? platform_humanize($method));
            $reference = platform_text($payment, ['reference', 'reference_code']);
            $paymentAmount = platform_money($payment['amount_cents'] ?? null, platform_text($payment, ['currency'], $currency));
            ?>
            <tr>
              <td data-label="Date"><?= e($paymentPaidAt !== '' ? admin_datetime($paymentPaidAt, $timezone) : '-') ?></td>
              <td data-label="Method"><?= e($methodLabel !== '' ? $methodLabel : '-') ?></td>
              <td data-label="Reference"><span class="mono"><?= e($reference !== '' ? $reference : '-') ?></span></td>
              <td data-label="Amount"><?= e($paymentAmount !== '' ? $paymentAmount : '-') ?></td>
              <td data-label="Status"><span class="status <?= e($paymentStatusClass($paymentStatus)) ?>"><?= e($paymentStatusLabel) ?></span></td>
              <td data-label="Actions">
                <?php if (!$canManage || $paymentUuid === ''): ?>
                  <span class="platform-field__hint">, </span>
                <?php elseif ($paymentStatus === 'pending'): ?>
                  <div class="platform-row-actions">
                    <form method="post" action="/platform/billing/payments/<?= e(rawurlencode($paymentUuid)) ?>/status" class="inline-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="status" value="confirmed">
                      <button type="submit" class="btn btn--primary btn--sm">Confirm</button>
                    </form>
                    <form method="post" action="/platform/billing/payments/<?= e(rawurlencode($paymentUuid)) ?>/status" class="inline-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="status" value="failed">
                      <button type="submit" class="btn btn--ghost btn--sm">Mark failed</button>
                    </form>
                  </div>
                <?php elseif ($paymentStatus === 'confirmed'): ?>
                  <form method="post" action="/platform/billing/payments/<?= e(rawurlencode($paymentUuid)) ?>/status" class="inline-form"
                        data-confirm="Record this payment as refunded? Refunding the covering payment re-opens the invoice.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="status" value="refunded">
                    <button type="submit" class="btn btn--ghost btn--sm">Refund</button>
                  </form>
                <?php else: ?>
                  <span class="platform-field__hint">Closed, terminal state</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="platform-empty">
      <span class="platform-empty__icon" aria-hidden="true"><?= admin_icon('wallet') ?></span>
      <p class="platform-empty__title">No payments recorded yet</p>
      <p class="platform-empty__text">Record a payment below to start the ledger for this invoice.</p>
    </div>
  <?php endif; ?>
</section>

<?php if ($canManage && $uuid !== ''): ?>
  <?php if ($isTerminal): ?>
    <section class="platform-section">
      <div class="alert alert--warning" role="status">
        <?= admin_icon('info') ?>
        <div class="alert__body">
          <p class="alert__title">Invoice is void</p>
          <p>Void invoices are terminal. Payments cannot be recorded and the status cannot change.</p>
        </div>
      </div>
    </section>
  <?php else: ?>
    <div class="platform-billing-actions">
      <?php if ($canRecordPayment): ?>
        <section class="platform-section platform-section--card" aria-labelledby="record-payment-title">
          <div class="platform-section__head">
            <div>
              <h2 class="platform-section__title" id="record-payment-title">Record a payment</h2>
              <p class="platform-section__hint">Manual or M-Pesa reference payments. Partial payments keep the invoice open.</p>
            </div>
          </div>

          <form method="post" action="/platform/billing/invoices/<?= e(rawurlencode($uuid)) ?>/payments" class="platform-form">
            <?= csrf_field() ?>
            <div class="platform-form__grid">
              <div class="platform-field<?= platform_field_error($errors, 'amount_cents') !== '' ? ' platform-field--invalid' : '' ?>">
                <label class="platform-field__label" for="payment_amount">Amount (cents)</label>
                <input class="input" id="payment_amount" name="amount_cents" type="number" min="1" required
                       value="<?= e($oldValue('amount_cents', $balanceCents !== null ? (string) $balanceCents : '')) ?>">
                <?php if (platform_field_error($errors, 'amount_cents') !== ''): ?>
                  <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'amount_cents')) ?></p>
                <?php endif; ?>
              </div>
              <div class="platform-field<?= platform_field_error($errors, 'method') !== '' ? ' platform-field--invalid' : '' ?>">
                <label class="platform-field__label" for="payment_method">Method</label>
                <select class="select" id="payment_method" name="method" required>
                  <?php foreach ($methodMap as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>" <?= $oldValue('method') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (platform_field_error($errors, 'method') !== ''): ?>
                  <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'method')) ?></p>
                <?php endif; ?>
              </div>
              <div class="platform-field<?= platform_field_error($errors, 'reference') !== '' ? ' platform-field--invalid' : '' ?>">
                <label class="platform-field__label" for="payment_reference">
                  Reference
                  <span class="muted text-xs">Optional</span>
                </label>
                <input class="input" id="payment_reference" name="reference" type="text" maxlength="80"
                       value="<?= e($oldValue('reference')) ?>" placeholder="e.g. M-Pesa code">
              </div>
              <div class="platform-field<?= platform_field_error($errors, 'paid_at') !== '' ? ' platform-field--invalid' : '' ?>">
                <label class="platform-field__label" for="payment_paid_at">Paid at</label>
                <input class="input" id="payment_paid_at" name="paid_at" type="datetime-local"
                       value="<?= e($oldValue('paid_at')) ?>">
                <p class="platform-field__hint">Defaults to now when left blank.</p>
                <?php if (platform_field_error($errors, 'paid_at') !== ''): ?>
                  <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'paid_at')) ?></p>
                <?php endif; ?>
              </div>
              <div class="platform-field<?= platform_field_error($errors, 'status') !== '' ? ' platform-field--invalid' : '' ?>">
                <label class="platform-field__label" for="payment_status">Payment status</label>
                <select class="select" id="payment_status" name="status">
                  <?php foreach ($recordStatusMap as $recordValue => $recordLabel): ?>
                    <option value="<?= e((string) $recordValue) ?>" <?= $oldValue('status', 'confirmed') === (string) $recordValue ? 'selected' : '' ?>><?= e($recordLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="platform-form__actions">
              <button type="submit" class="btn btn--primary"><?= admin_icon('plus', 'ico ico--sm') ?> Record payment</button>
            </div>
          </form>
        </section>
      <?php endif; ?>

      <section class="platform-section platform-section--card" aria-labelledby="invoice-status-title">
        <div class="platform-section__head">
          <div>
            <h2 class="platform-section__title" id="invoice-status-title">Invoice status</h2>
            <p class="platform-section__hint">
              Marking paid requires a confirmed payment covering the amount. Void is terminal; all changes are audited.
            </p>
          </div>
        </div>
        <form method="post" action="/platform/billing/invoices/<?= e(rawurlencode($uuid)) ?>/status" class="platform-form">
          <?= csrf_field() ?>
          <div class="platform-form__grid platform-form__grid--inline">
            <div class="platform-field<?= platform_field_error($errors, 'status') !== '' ? ' platform-field--invalid' : '' ?>">
              <label class="platform-field__label" for="invoice_status">New status</label>
              <select class="select" id="invoice_status" name="status" required>
                <?php $selectedStatus = $oldValue('status', $status); ?>
                <?php foreach ($statusMap as $value => $label): ?>
                  <option value="<?= e((string) $value) ?>" <?= $selectedStatus === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (platform_field_error($errors, 'status') !== ''): ?>
                <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'status')) ?></p>
              <?php endif; ?>
            </div>
            <div class="platform-form__actions">
              <button type="submit" class="btn btn--outline"><?= admin_icon('check', 'ico ico--sm') ?> Update status</button>
            </div>
          </div>
        </form>
      </section>
    </div>
  <?php endif; ?>
<?php endif; ?>
