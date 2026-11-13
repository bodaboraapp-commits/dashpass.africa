<?php

declare(strict_types=1);

/**
 * Access PIN card for the household access pass pages.
 *
 * Same visual treatment as the visitor pass "Access PIN" block (icon title,
 * hint, large mono code, Rotate PIN action). Rotating writes a new per-person
 * salt: the 4-digit code and the QR token both change, the QR image URL is
 * versioned, and the previous PIN/QR stop verifying at the gate.
 *
 * Variables: $passCode (string, 4 digits), $passType (member|staff),
 * $passId (int), $returnTo ('/app/access' or '/app/access/member').
 */

$passCode = isset($passCode) && is_scalar($passCode) ? trim((string) $passCode) : '';
$passType = isset($passType) && in_array($passType, ['member', 'staff'], true) ? (string) $passType : 'member';
$passId = isset($passId) ? max(0, (int) $passId) : 0;
$returnTo = isset($returnTo) && in_array($returnTo, ['/app/access', '/app/access/member'], true)
    ? (string) $returnTo
    : '/app/access';

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
    <?php if ($passId > 0): ?>
      <form method="post" action="/app/access/rotate" class="access-pin-card__rotate"
            data-confirm="Rotate the PIN? The current PIN and QR stop working immediately.">
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="<?= e($passType) ?>">
        <input type="hidden" name="id" value="<?= e((string) $passId) ?>">
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <button type="submit" class="btn btn--outline btn--sm">
          <?= $icon('refresh', 'ico ico--sm') ?>
          Rotate PIN
        </button>
      </form>
    <?php endif; ?>
  </div>

  <p class="pass-card__hint pass-card__offline-note">
    Rotating creates a new PIN and QR. The old ones stop working.
  </p>
</section>
