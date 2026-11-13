<?php

declare(strict_types=1);

/**
 * Access PIN card for the household access pass pages.
 *
 * Same visual treatment as the visitor pass "Access PIN" block (icon title,
 * hint, large mono code, actions and offline note), adapted to the household
 * pass: the 4-digit code is derived from the signed household pass and is not
 * SMS-rotatable like a visitor invitation PIN, so it offers Copy PIN instead of
 * Rotate PIN.
 *
 * Variables: $passCode (string, 4 digits).
 */

$passCode = isset($passCode) && is_scalar($passCode) ? trim((string) $passCode) : '';

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<section class="pass-card access-pin-card" aria-labelledby="access-pin-title">
  <div class="pass-card__head">
    <h2 class="pass-card__title" id="access-pin-title"><?= $icon('key', 'ico ico--sm') ?> Access PIN</h2>
  </div>

  <p class="pass-card__hint">
    If the QR cannot be scanned, read this 4-digit code to the guard.
  </p>

  <p class="pass-pin" data-pass-code aria-label="Your 4-digit access PIN"><?= e($passCode) ?></p>

  <div class="access-pin-card__actions">
    <button type="button" class="btn btn--outline btn--sm" data-copy="<?= e($passCode) ?>">
      <?= $icon('copy', 'ico ico--sm') ?>
      Copy PIN
    </button>
  </div>

  <p class="pass-card__hint pass-card__offline-note">
    Offline: this PIN is not saved on this device. Open this page while online to see it.
  </p>
</section>
