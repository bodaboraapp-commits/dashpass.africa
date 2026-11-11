<?php
declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';
$payments = isset($payments) && is_array($payments) ? $payments : [];
$mpesa = is_array($payments['mpesa'] ?? null) ? $payments['mpesa'] : [];
$card = is_array($payments['card'] ?? null) ? $payments['card'] : [];
$mode = (string) ($mpesa['mode'] ?? $card['mode'] ?? 'test');
$expiry = max(1, min(168, (int) ($payments['link_expiry_hours'] ?? 24)));
?>
<div class="platform-page">
  <header class="platform-page__head">
    <div><p class="platform-page__eyebrow">Platform settings</p><h1 class="platform-page__title">Payment providers</h1><p class="platform-page__lede">Configure M-Pesa/card availability and payment-link expiry. Credentials stay in environment configuration and are never rendered.</p></div>
    <a class="btn btn--ghost btn--sm" href="/platform">Back to platform</a>
  </header>
  <form method="post" action="/platform/settings/payments" class="card admin-form">
    <?= csrf_field() ?>
    <div class="admin-form__grid">
      <div class="admin-field">
        <label class="admin-field__label" for="payments_mode">Provider mode</label>
        <select class="select" id="payments_mode" name="mode">
          <?php foreach (['test' => 'Test mode', 'live' => 'Live', 'disabled' => 'Disabled'] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $mode === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="admin-field__hint">Test mode is safe; no real money moves.</p>
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="payments_expiry">Payment link expiry (hours)</label>
        <input class="input" id="payments_expiry" name="link_expiry_hours" type="number" min="1" max="168" value="<?= e((string) $expiry) ?>">
      </div>
    </div>
    <div class="admin-form__grid">
      <div class="admin-field">
        <label class="admin-field__label" for="mpesa_enabled"><input type="checkbox" id="mpesa_enabled" name="mpesa_enabled" value="1" <?= !empty($mpesa['enabled']) ? 'checked' : '' ?>> M-Pesa enabled</label>
        <p><span class="status <?= !empty($mpesa['enabled']) ? 'status--active' : 'status--inactive' ?>"><?= !empty($mpesa['enabled']) ? 'Configured' : 'Not configured' ?></span></p>
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="card_enabled"><input type="checkbox" id="card_enabled" name="card_enabled" value="1" <?= !empty($card['enabled']) ? 'checked' : '' ?>> Card enabled</label>
        <p><span class="status <?= !empty($card['enabled']) ? 'status--active' : 'status--inactive' ?>"><?= !empty($card['enabled']) ? 'Configured' : 'Not configured' ?></span></p>
      </div>
    </div>
    <p class="admin-field__hint">Card data is never collected in this app; payments use a hosted checkout redirect only.</p>
    <div class="admin-form__actions"><button type="submit" class="btn btn--primary">Save payment settings</button></div>
  </form>
</div>
