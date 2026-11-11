<?php
declare(strict_types=1);
$available = (bool) ($available ?? false);
$amount = isset($amount_cents) && is_numeric($amount_cents) ? (int) $amount_cents : null;
$currency = (string) ($currency ?? 'KES');
$methods = isset($methods) && is_array($methods) ? $methods : [];
?>
<section class="card state-card">
  <p class="state-card__eyebrow">Secure payment</p>
  <h1 class="state-card__title"><?= $available ? 'Pay this bill' : 'Payment link unavailable' ?></h1>
  <?php if ($amount !== null): ?><p class="state-card__amount"><?= e(number_format($amount / 100, 2) . ' ' . $currency) ?></p><?php endif; ?>
  <?php if ($available): ?>
    <p>Choose a payment method. You will be redirected to the provider's secure hosted checkout; card data is never collected in this app.</p>
    <div class="state-card__actions">
      <?php foreach ($methods as $method): ?>
        <a class="btn btn--primary" href="<?= e((string) ($method['url'] ?? '#')) ?>" rel="noopener"><?= e((string) ($method['label'] ?? 'Pay now')) ?></a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p>Payment links are not configured yet. Please use the pay instructions on your invoice or contact the estate office.</p>
    <p class="admin-field__hint">This page never asks for card details.</p>
  <?php endif; ?>
</section>
