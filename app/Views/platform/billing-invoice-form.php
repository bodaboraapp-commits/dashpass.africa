<?php

declare(strict_types=1);

/**
 * Platform billing, create invoice (billing-invoices contract §4).
 *
 * Estate + optional subscription prefill (amount defaults from the plan), with
 * manual amount/currency/period/due/notes overrides and a save-as-draft option.
 * Works without JavaScript; errors/old values are preserved.
 *
 * Variables (T77 frozen keys, defensive): $estates [], $subscriptions [],
 * $default_amount_cents, $errors [], $old [] + platform shell data.
 */

require_once __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estates = isset($estates) && is_array($estates) ? $estates : [];
$subscriptions = isset($subscriptions) && is_array($subscriptions) ? $subscriptions : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$defaultAmount = isset($default_amount_cents) && is_numeric($default_amount_cents) ? (int) $default_amount_cents : null;
$defaultCurrency = isset($default_currency) && is_scalar($default_currency) && trim((string) $default_currency) !== ''
    ? strtoupper(trim((string) $default_currency))
    : 'KES';
$defaultEstateUuid = isset($default_estate_uuid) && is_scalar($default_estate_uuid) ? trim((string) $default_estate_uuid) : '';
$defaultSubscriptionUuid = isset($default_subscription_uuid) && is_scalar($default_subscription_uuid) ? trim((string) $default_subscription_uuid) : '';

$estateMap = [];
foreach ($estates as $item) {
    if (is_string($item) && trim($item) !== '') {
        $estateMap[trim($item)] = admin_humanize(trim($item));
        continue;
    }
    if (!is_array($item)) {
        continue;
    }
    $uuid = platform_text($item, ['uuid']);
    if ($uuid !== '') {
        $estateMap[$uuid] = platform_text($item, ['label', 'name', 'estate_name'], 'Estate');
    }
}
$subscriptionMap = [];
foreach ($subscriptions as $item) {
    if (!is_array($item)) {
        continue;
    }
    $uuid = platform_text($item, ['uuid']);
    if ($uuid === '') {
        continue;
    }
    $planName = platform_text($item, ['plan_name', 'plan', 'name']);
    $estateName = platform_text($item, ['estate_name', 'estate']);
    $status = platform_text($item, ['status']);
    // T77 subscriptionOptions carry a ready-made `label` (estate · plan (cycle)).
    $label = platform_text($item, ['label']);
    if ($label === '') {
        if ($planName !== '' && $estateName !== '') {
            $label = $planName . ' · ' . $estateName;
        } else {
            $label = $planName !== '' ? $planName : 'Subscription';
        }
    }
    if ($status !== '') {
        $label .= ' (' . platform_humanize($status) . ')';
    }
    $subscriptionMap[$uuid] = $label;
}
$currencyDefault = $defaultCurrency;
$oldValue = static function (string $field, string $default = '') use ($old): string {
    $candidate = $old[$field] ?? $default;

    return is_scalar($candidate) ? trim((string) $candidate) : $default;
};
$oldChecked = static function (string $field) use ($old): bool {
    $candidate = $old[$field] ?? null;

    return is_scalar($candidate) && in_array((string) $candidate, ['1', 'true', 'on', 'yes'], true);
};
$amountValue = $oldValue('amount_cents');
if ($amountValue === '' && $defaultAmount !== null) {
    $amountValue = (string) $defaultAmount;
}
?>
<div class="platform-page__head">
  <div>
    <nav class="platform-breadcrumb" aria-label="Breadcrumb">
      <a href="/platform/billing">Billing</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page">New invoice</span>
    </nav>
    <h1 class="platform-page__title">New invoice</h1>
    <p class="platform-page__lede">
      Create an estate invoice. The amount is prefilled from the selected plan's monthly price; override it for
      annual billing or custom amounts.
    </p>
  </div>
</div>

<?= admin_summary($errors) ?>

<form method="post" action="/platform/billing/invoices" class="platform-form card">
  <?= csrf_field() ?>

  <section class="platform-section" aria-labelledby="invoice-estate-title">
    <div class="platform-section__head">
      <h2 class="platform-section__title" id="invoice-estate-title">Billing target</h2>
    </div>

    <div class="platform-form__grid">
      <div class="platform-field<?= platform_field_error($errors, 'estate_uuid') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="invoice_estate">Estate</label>
        <select class="select" id="invoice_estate" name="estate_uuid" required>
          <option value="">Choose an estate</option>
          <?php foreach ($estateMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $oldValue('estate_uuid', $defaultEstateUuid) === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (platform_field_error($errors, 'estate_uuid') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'estate_uuid')) ?></p>
        <?php endif; ?>
      </div>

      <?php if ($subscriptionMap !== []): ?>
        <div class="platform-field<?= platform_field_error($errors, 'subscription_uuid') !== '' ? ' platform-field--invalid' : '' ?>">
          <label class="platform-field__label" for="invoice_subscription">
            Subscription
            <span class="muted text-xs">Optional, prefill source</span>
          </label>
          <select class="select" id="invoice_subscription" name="subscription_uuid">
            <option value="">No subscription</option>
            <?php foreach ($subscriptionMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('subscription_uuid', $defaultSubscriptionUuid) === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="platform-field__hint">The amount and currency default from the subscription's plan.</p>
          <?php if (platform_field_error($errors, 'subscription_uuid') !== ''): ?>
            <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'subscription_uuid')) ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="platform-section" aria-labelledby="invoice-amount-title">
    <div class="platform-section__head">
      <h2 class="platform-section__title" id="invoice-amount-title">Amount and period</h2>
    </div>

    <div class="platform-form__grid">
      <div class="platform-field<?= platform_field_error($errors, 'amount_cents') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="invoice_amount">Amount (cents)</label>
        <input class="input<?= platform_field_error($errors, 'amount_cents') !== '' ? ' input--invalid' : '' ?>"
               id="invoice_amount" name="amount_cents" type="number" min="1" required
               value="<?= e($amountValue) ?>">
        <p class="platform-field__hint">
          Integer cents, e.g. 500000 = KES 5,000.00.
          <?php if ($defaultAmount !== null && $defaultAmount > 0): ?>
            Plan default: <?= e(platform_money($defaultAmount, $currencyDefault)) ?>.
          <?php endif; ?>
        </p>
        <?php if (platform_field_error($errors, 'amount_cents') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'amount_cents')) ?></p>
        <?php endif; ?>
      </div>

      <div class="platform-field<?= platform_field_error($errors, 'currency') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="invoice_currency">Currency</label>
        <input class="input<?= platform_field_error($errors, 'currency') !== '' ? ' input--invalid' : '' ?>"
               id="invoice_currency" name="currency" type="text" maxlength="3"
               value="<?= e($oldValue('currency', $currencyDefault)) ?>" placeholder="KES">
        <?php if (platform_field_error($errors, 'currency') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'currency')) ?></p>
        <?php endif; ?>
      </div>

      <div class="platform-field<?= platform_field_error($errors, 'period_start') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="invoice_period_start">
          Period start
          <span class="muted text-xs">Optional</span>
        </label>
        <input class="input" id="invoice_period_start" name="period_start" type="date"
               data-date-range="invoice-period" data-date-range-part="from" data-date-range-label="Invoice period"
               value="<?= e($oldValue('period_start')) ?>">
        <?php if (platform_field_error($errors, 'period_start') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'period_start')) ?></p>
        <?php endif; ?>
      </div>

      <div class="platform-field<?= platform_field_error($errors, 'period_end') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="invoice_period_end">
          Period end
          <span class="muted text-xs">Optional</span>
        </label>
        <input class="input" id="invoice_period_end" name="period_end" type="date"
               data-date-range="invoice-period" data-date-range-part="to"
               value="<?= e($oldValue('period_end')) ?>">
        <?php if (platform_field_error($errors, 'period_end') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'period_end')) ?></p>
        <?php endif; ?>
      </div>

      <div class="platform-field<?= platform_field_error($errors, 'due_at') !== '' ? ' platform-field--invalid' : '' ?>">
        <label class="platform-field__label" for="invoice_due_at">
          Due
          <span class="muted text-xs">Optional</span>
        </label>
        <input class="input" id="invoice_due_at" name="due_at" type="datetime-local"
               value="<?= e($oldValue('due_at')) ?>">
        <p class="platform-field__hint">Invoices past due are flagged by the past-due sweep.</p>
        <?php if (platform_field_error($errors, 'due_at') !== ''): ?>
          <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'due_at')) ?></p>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="platform-section" aria-labelledby="invoice-notes-title">
    <div class="platform-section__head">
      <h2 class="platform-section__title" id="invoice-notes-title">Notes</h2>
    </div>
    <div class="platform-field<?= platform_field_error($errors, 'notes') !== '' ? ' platform-field--invalid' : '' ?>">
      <label class="platform-field__label" for="invoice_notes">
        Notes
        <span class="muted text-xs">Optional, up to 500 characters</span>
      </label>
      <textarea class="input<?= platform_field_error($errors, 'notes') !== '' ? ' input--invalid' : '' ?>"
                id="invoice_notes" name="notes" rows="3" maxlength="500"
                placeholder="Anything the estate finance contact should know about this invoice."><?= e($oldValue('notes')) ?></textarea>
      <?php if (platform_field_error($errors, 'notes') !== ''): ?>
        <p class="platform-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(platform_field_error($errors, 'notes')) ?></p>
      <?php endif; ?>
    </div>
  </section>

  <label class="platform-checkbox">
    <input type="checkbox" name="save_draft" value="1" <?= $oldChecked('save_draft') ? 'checked' : '' ?>>
    <span>Save as draft (do not issue to the estate yet)</span>
  </label>

  <div class="platform-form__actions">
    <button type="submit" class="btn btn--primary"><?= admin_icon('check', 'ico ico--sm') ?> Create invoice</button>
    <a class="btn btn--ghost" href="/platform/billing">Cancel</a>
  </div>
</form>
