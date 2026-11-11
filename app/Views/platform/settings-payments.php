<?php
declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';
$payments = isset($payments) && is_array($payments) ? $payments : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$mpesa = is_array($payments['mpesa'] ?? null) ? $payments['mpesa'] : [];
$card = is_array($payments['card'] ?? null) ? $payments['card'] : [];
$status = isset($payments['status']) && is_array($payments['status']) ? $payments['status'] : [];
$provider = (string) ($old['provider'] ?? 'mpesa');
$provider = in_array($provider, ['mpesa', 'card'], true) ? $provider : 'mpesa';
$mode = (string) ($old['mode'] ?? $mpesa['mode'] ?? $card['mode'] ?? 'test');
$mode = in_array($mode, ['test', 'live'], true) ? $mode : 'test';
$expiryDays = max(1, min(7, (int) ($old['link_expiry_days'] ?? $payments['link_expiry_days'] ?? 7)));
$oldMethods = isset($old['methods']) && is_array($old['methods']) ? array_map('strval', $old['methods']) : [];
$methods = $oldMethods !== [] ? $oldMethods : array_values(array_filter(['mpesa', 'card'], static fn (string $m): bool => (bool) (($m === 'mpesa' ? $mpesa : $card)['enabled'] ?? false)));
$errorFor = static function (string $key) use ($errors): string {
    $messages = $errors[$key] ?? [];
    return is_array($messages) && $messages !== [] ? (string) reset($messages) : '';
};
$firstError = '';
foreach ($errors as $messages) {
    if (is_array($messages) && $messages !== []) {
        $firstError = (string) reset($messages);
        break;
    }
}
$secretState = static function (string $key) use ($status): array {
    if (!array_key_exists($key, $status)) {
        return ['Status not reported', 'status--inactive'];
    }
    return $status[$key] ? ['Configured', 'status--active'] : ['Not set', 'status--inactive'];
};
[$mpesaSecret, $mpesaTone] = $secretState('mpesa_configured');
[$cardSecret, $cardTone] = $secretState('card_configured');
?>
<div class="platform-page">
  <header class="platform-page__head">
    <div><p class="platform-page__eyebrow">Platform settings</p><h1 class="platform-page__title">Payment providers</h1><p class="platform-page__lede">Choose provider and mode, enable available methods, set pay-link expiry and check masked credential status. Secret values are never rendered.</p></div>
    <a class="btn btn--ghost btn--sm" href="/platform">Back to platform</a>
  </header>

  <?php if ($errors !== []): ?>
    <div class="alert alert--danger" role="alert"><div class="alert__body"><p class="alert__title">Check the payment settings</p><p><?= e($firstError !== '' ? $firstError : 'Some values could not be saved.') ?></p></div></div>
  <?php endif; ?>

  <form method="post" action="/platform/settings/payments" class="card admin-form">
    <?= csrf_field() ?>
    <div class="admin-form__grid">
      <div class="admin-field<?= $errorFor('provider') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="payments_provider">Primary provider</label>
        <select class="select" id="payments_provider" name="provider">
          <?php foreach (['mpesa' => 'M-Pesa', 'card' => 'Card'] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $provider === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($errorFor('provider') !== ''): ?><p class="admin-field__error"><?= e($errorFor('provider')) ?></p><?php endif; ?>
      </div>
      <div class="admin-field<?= $errorFor('mode') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="payments_mode">Mode</label>
        <select class="select" id="payments_mode" name="mode">
          <option value="test" <?= $mode === 'test' ? 'selected' : '' ?>>Test mode — no real money</option>
          <option value="live" <?= $mode === 'live' ? 'selected' : '' ?>>Live</option>
        </select>
        <p><span class="status <?= $mode === 'live' ? 'status--active' : 'status--pending' ?>"><?= $mode === 'live' ? 'Live' : 'Test mode' ?></span><?php if (!empty($status['test_mode'])): ?> <span class="status status--pending">Test</span><?php endif; ?></p>
        <?php if ($errorFor('mode') !== ''): ?><p class="admin-field__error"><?= e($errorFor('mode')) ?></p><?php endif; ?>
      </div>
      <div class="admin-field<?= $errorFor('link_expiry_days') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="payments_expiry_days">Payment-link expiry (days)</label>
        <input class="input" id="payments_expiry_days" name="link_expiry_days" type="number" min="1" max="7" value="<?= e((string) $expiryDays) ?>">
        <p class="admin-field__hint">1–7 days. The same expiry applies to both methods.</p>
        <?php if ($errorFor('link_expiry_days') !== ''): ?><p class="admin-field__error"><?= e($errorFor('link_expiry_days')) ?></p><?php endif; ?>
      </div>
    </div>

    <div class="admin-form__grid">
      <fieldset class="admin-field<?= $errorFor('methods') !== '' ? ' admin-field--invalid' : '' ?>">
        <legend class="admin-field__label">Enabled payment methods</legend>
        <?php foreach (['mpesa' => ['M-Pesa', $mpesaSecret, $mpesaTone], 'card' => ['Card', $cardSecret, $cardTone]] as $key => [$label, $secretLabel, $secretTone]): ?>
          <label class="admin-check" for="payment_method_<?= e($key) ?>">
            <input type="checkbox" id="payment_method_<?= e($key) ?>" name="methods[]" value="<?= e($key) ?>" <?= in_array($key, $methods, true) ? 'checked' : '' ?>>
            <?= e($label) ?>
          </label>
          <p><span class="status <?= e($secretTone) ?>"><?= e($secretLabel) ?></span> <span class="muted text-xs">Credential status; blank input keeps it unchanged.</span></p>
        <?php endforeach; ?>
        <?php if ($errorFor('methods') !== ''): ?><p class="admin-field__error"><?= e($errorFor('methods')) ?></p><?php endif; ?>
      </fieldset>
      <div class="admin-field">
        <h3 class="admin-field__label">Security</h3>
        <p class="admin-field__hint">Secret values stay in environment configuration and are never collected or shown here. Card payments use hosted checkout only.</p>
      </div>
    </div>

    <div class="admin-form__actions"><button type="submit" class="btn btn--primary">Save payment settings</button></div>
  </form>
</div>
