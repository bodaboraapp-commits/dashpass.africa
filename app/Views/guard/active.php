<?php

declare(strict_types=1);

/**
 * Guard active visitors screen (visitor-access-module-contract §7, §7.1).
 *
 * Shows every visit currently inside the estate with a one-tap checkout.
 * Checkout is a plain POST (works without JavaScript) that guard.js enhances
 * into an API call with an inline confirmation toast.
 *
 * Variables: shell + $active [Item].
 */

$active = isset($active) && is_array($active) ? $active : [];
$selectedGate = isset($selected_gate) && is_array($selected_gate) ? $selected_gate : null;
$gateName = trim((string) ($gate ?? ($selectedGate['name'] ?? '')));
$timezone = (string) config('app.timezone', 'Africa/Nairobi');

$activeItems = array_values(array_filter($active, 'is_array'));
$insideCount = count($activeItems);

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="g-screen">
  <header class="g-head">
    <a class="g-head__link" href="<?= e('/gate') ?>" aria-label="Back to dashboard" title="Back to dashboard"><?= $icon('chevron-left', 'ico ico--sm') ?> Back</a>
    <div>
      <p class="g-head__eyebrow"><?= $gateName !== '' ? e($gateName) : 'No gate selected' ?></p>
      <h1 class="g-head__title">Visitors inside</h1>
    </div>
    <a class="g-head__link" href="/gate/verify"><?= $icon('key', 'ico ico--sm') ?> Verify</a>
  </header>

  <div class="g-toolbar">
    <span class="g-count"><strong><?= e((string) $insideCount) ?></strong> <?= $insideCount === 1 ? 'visitor' : 'visitors' ?> on site</span>
    <a class="btn btn--ghost btn--sm" href="/gate/active"><?= $icon('refresh', 'ico ico--sm') ?> Refresh</a>
  </div>

  <?php if ($activeItems !== []): ?>
    <ul class="g-list g-list--spaced">
      <?php foreach ($activeItems as $item): ?>
        <?= \App\Core\View::capture('guard/partials/visitor-card', [
            'item' => $item,
            'showCheckout' => true,
            'timezone' => $timezone,
        ]) ?>
      <?php endforeach; ?>
    </ul>
    <p class="g-muted g-note">
      Check a visitor out when they leave the estate so their pass cannot be reused.
    </p>
  <?php else: ?>
    <div class="g-empty">
      <span class="g-empty__icon" aria-hidden="true"><?= $icon('users') ?></span>
      <p class="g-empty__title">Nobody is inside right now</p>
      <p class="g-empty__text">Visitors you check in at the gate appear here until they are checked out.</p>
      <p><a class="btn btn--primary btn--lg" href="/gate/verify">Verify a visitor</a></p>
    </div>
  <?php endif; ?>
</div>
