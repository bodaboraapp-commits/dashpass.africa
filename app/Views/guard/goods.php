<?php

declare(strict_types=1);

/**
 * Guard goods verification screen (owner request 2026-11-13).
 *
 * Variables: $movements (array from GoodsMovementService::guardList()),
 * $errors, $old + shell data/gate.
 */

$movements = isset($movements) && is_array($movements) ? $movements : [];
$gateName = isset($gate) && is_string($gate) ? trim($gate) : '';
$errors = isset($errors) && is_array($errors) ? $errors : [];

$pending = array_values(array_filter($movements, static fn (array $row): bool => (string) ($row['status'] ?? '') === 'approved'));
$completed = array_values(array_filter($movements, static fn (array $row): bool => (string) ($row['status'] ?? '') === 'completed'));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="g-screen">
  <header class="g-head">
    <a class="g-head__link" href="/gate" aria-label="Back to dashboard" title="Back to dashboard"><?= $icon('chevron-left', 'ico ico--sm') ?> Back</a>
    <div>
      <p class="g-head__eyebrow"><?= $gateName !== '' ? e($gateName) : 'No gate selected' ?></p>
      <h1 class="g-head__title">Goods in / out</h1>
    </div>
    <a class="g-head__link" href="/gate/active"><?= $icon('users', 'ico ico--sm') ?> Inside</a>
  </header>

  <?php if ($pending === [] && $completed === []): ?>
    <section class="g-panel">
      <div class="g-empty">
        <span class="g-empty__icon" aria-hidden="true"><?= $icon('list') ?></span>
        <p class="g-empty__title">No approved goods waiting</p>
        <p class="g-empty__text">Goods movements approved by the Estate Admin appear here for gate verification.</p>
        <p><a class="btn btn--primary btn--lg" href="/gate">Go to dashboard</a></p>
      </div>
    </section>
  <?php else: ?>
    <section class="g-panel" aria-labelledby="goods-pending-title">
      <div class="g-panel__head">
        <h2 class="g-panel__title" id="goods-pending-title">Approved, awaiting gate</h2>
        <span class="g-chip g-chip--warn"><?= e((string) count($pending)) ?></span>
      </div>

      <?php if ($pending === []): ?>
        <p class="g-empty__text">Nothing waiting. Check the verified list below.</p>
      <?php else: ?>
        <div class="g-list">
          <?php foreach ($pending as $movement): ?>
            <article class="g-card" data-goods-verify="<?= e((string) ($movement['uuid'] ?? '')) ?>">
              <div class="g-card__head">
                <div>
                  <p class="g-card__eyebrow"><?= e((string) ($movement['direction_label'] ?? '')) ?> · <code><?= e((string) ($movement['reference_code'] ?? '')) ?></code></p>
                  <h3 class="g-card__title"><?= e((string) ($movement['description'] ?? '')) ?></h3>
                </div>
                <span class="g-chip g-chip--ok">Approved</span>
              </div>
              <p class="g-card__meta">
                <?= e((string) ($movement['resident_name'] ?? '')) ?>
                · <?= e((string) ($movement['household_label'] ?? '')) ?>
                · x<?= e((string) ($movement['quantity'] ?? 1)) ?>
              </p>
              <?php if (($movement['carrier_name'] ?? null) !== null || ($movement['vehicle_plate'] ?? null) !== null): ?>
                <p class="g-card__meta">
                  <?= e(trim((string) ($movement['carrier_name'] ?? '')) ?: 'Carrier') ?>
                  <?php if (($movement['vehicle_plate'] ?? null) !== null): ?> · <?= e((string) $movement['vehicle_plate']) ?><?php endif; ?>
                </p>
              <?php endif; ?>
              <form method="post" action="/gate/goods/<?= e(rawurlencode((string) ($movement['uuid'] ?? ''))) ?>/verify" class="g-form">
                <?= csrf_field() ?>
                <label class="g-field">
                  <span class="g-field__label">Verification note (optional)</span>
                  <input class="input" name="note" type="text" maxlength="500" placeholder="e.g. 3 boxes checked, plate matches">
                </label>
                <button type="submit" class="btn btn--primary btn--lg btn--block"><?= $icon('check') ?> Verify at gate</button>
              </form>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($completed !== []): ?>
      <section class="g-panel" aria-labelledby="goods-completed-title">
        <div class="g-panel__head">
          <h2 class="g-panel__title" id="goods-completed-title">Recently verified</h2>
        </div>
        <div class="g-list">
          <?php foreach ($completed as $movement): ?>
            <article class="g-card">
              <div class="g-card__head">
                <div>
                  <p class="g-card__eyebrow"><?= e((string) ($movement['direction_label'] ?? '')) ?> · <code><?= e((string) ($movement['reference_code'] ?? '')) ?></code></p>
                  <h3 class="g-card__title"><?= e((string) ($movement['description'] ?? '')) ?></h3>
                </div>
                <span class="g-chip g-chip--ok">Verified</span>
              </div>
              <p class="g-card__meta">
                <?= e((string) ($movement['resident_name'] ?? '')) ?>
                · <?= e((string) ($movement['gate_name'] ?? '')) ?>
                <?php if (($movement['verified_at'] ?? null) !== null): ?> · <?= e((string) $movement['verified_at']) ?><?php endif; ?>
              </p>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</div>
