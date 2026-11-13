<?php

declare(strict_types=1);

/**
 * Resident goods in/out screen (owner request 2026-11-13).
 *
 * Owner request (2026-11-13): "My goods requests" is the first section on the
 * page (the request form follows below it).
 *
 * Variables: $movements (array from GoodsMovementService::residentList()),
 * $directions (direction => label), $errors, $old + shell data.
 */
?>
<div class="resident resident--pushed">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= \App\Core\View::capture('partials/icon', ['name' => 'chevron-left', 'class' => 'ico']) ?>
    </a>
    <h1 class="screen-bar__title">Goods in and out</h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <section class="resident-section" aria-labelledby="goods-history-title">
    <div class="resident-section__head">
      <h2 class="resident-section__title" id="goods-history-title">My goods requests</h2>
      <?php if ($movements !== []): ?><span class="resident-section__count"><?= e((string) count($movements)) ?></span><?php endif; ?>
    </div>

    <?php if ($movements === []): ?>
      <div class="card empty-state">
        <p class="empty-state__title">No goods requests yet</p>
        <p class="empty-state__text">Requests you send appear here with approval status and the gate reference code.</p>
      </div>
    <?php else: ?>
      <ul class="card-list" role="list">
        <?php foreach ($movements as $movement): ?>
          <?php
          $tone = match ((string) ($movement['status_tone'] ?? 'warn')) {
              'ok' => 'status--active',
              'danger' => 'status--danger',
              default => 'status--pending',
          };
          ?>
          <li class="card goods-card" data-goods-status="<?= e((string) $movement['status']) ?>">
            <div class="goods-card__head">
              <div>
                <p class="goods-card__direction"><?= e((string) $movement['direction_label']) ?></p>
                <p class="goods-card__description"><?= e((string) $movement['description']) ?></p>
              </div>
              <span class="status <?= e($tone) ?>"><?= e((string) $movement['status_label']) ?></span>
            </div>
            <dl class="goods-card__facts">
              <div><dt>Reference</dt><dd><code><?= e((string) $movement['reference_code']) ?></code></dd></div>
              <div><dt>Quantity</dt><dd><?= e((string) $movement['quantity']) ?></dd></div>
              <?php if (($movement['carrier_name'] ?? null) !== null): ?><div><dt>Carrier</dt><dd><?= e((string) $movement['carrier_name']) ?></dd></div><?php endif; ?>
              <?php if (($movement['vehicle_plate'] ?? null) !== null): ?><div><dt>Plate</dt><dd><?= e((string) $movement['vehicle_plate']) ?></dd></div><?php endif; ?>
              <?php if (($movement['expected_at'] ?? null) !== null): ?><div><dt>Expected</dt><dd><?= e((string) $movement['expected_at']) ?></dd></div><?php endif; ?>
              <?php if (($movement['decision_note'] ?? null) !== null): ?><div><dt>Admin note</dt><dd><?= e((string) $movement['decision_note']) ?></dd></div><?php endif; ?>
              <?php if (($movement['gate_name'] ?? null) !== null): ?><div><dt>Verified at</dt><dd><?= e((string) $movement['gate_name']) ?></dd></div><?php endif; ?>
            </dl>
            <?php if ((string) $movement['status'] === 'approved'): ?>
              <p class="goods-card__hint">Approved: show reference <strong><?= e((string) $movement['reference_code']) ?></strong> (or this pass) to the guard.</p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="resident-section" aria-labelledby="goods-request-title">
    <div class="resident-section__head">
      <h2 class="resident-section__title" id="goods-request-title">Request goods movement</h2>
    </div>
    <p class="page-lede">
      Tell the Estate Admin what is coming in or going out. Once approved, show the reference code
      to the guard at the gate. Goods out includes furniture, appliances and contractor equipment.
    </p>

    <?php if (isset($errors) && is_array($errors) && $errors !== []): ?>
      <div class="alert alert--error" role="alert">
        <div class="alert__body">
          <p class="alert__title">Goods request not sent</p>
          <?php foreach ($errors as $fieldErrors): ?>
            <?php foreach ((array) $fieldErrors as $message): ?><p><?= e((string) $message) ?></p><?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" action="/app/goods" class="card goods-form">
      <?= csrf_field() ?>
      <div class="field">
        <label class="field__label" for="goods_direction">Movement</label>
        <select class="input" id="goods_direction" name="direction" required>
          <?php foreach ($directions as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= ($old['direction'] ?? 'in') === $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label class="field__label" for="goods_description">What is moving</label>
        <input class="input" id="goods_description" name="description" type="text" maxlength="300" required
               value="<?= e((string) ($old['description'] ?? '')) ?>" placeholder="e.g. 3 boxes of groceries, washing machine">
      </div>

      <div class="field">
        <label class="field__label" for="goods_quantity">Quantity</label>
        <input class="input" id="goods_quantity" name="quantity" type="number" min="1" max="999" inputmode="numeric"
               value="<?= e((string) ($old['quantity'] ?? '1')) ?>">
      </div>

      <div class="field">
        <label class="field__label" for="goods_carrier">Carrier / company (optional)</label>
        <input class="input" id="goods_carrier" name="carrier_name" type="text" maxlength="120"
               value="<?= e((string) ($old['carrier_name'] ?? '')) ?>" placeholder="e.g. Jumia, G4S, family van">
      </div>

      <div class="field">
        <label class="field__label" for="goods_plate">Vehicle plate (optional)</label>
        <input class="input" id="goods_plate" name="vehicle_plate" type="text" maxlength="20"
               value="<?= e((string) ($old['vehicle_plate'] ?? '')) ?>" placeholder="e.g. KDA 123A">
      </div>

      <div class="field">
        <label class="field__label" for="goods_recipient">Recipient (optional)</label>
        <input class="input" id="goods_recipient" name="recipient_name" type="text" maxlength="180"
               value="<?= e((string) ($old['recipient_name'] ?? '')) ?>" placeholder="Who is handling it?">
      </div>

      <div class="field">
        <label class="field__label" for="goods_expected">Expected time (optional)</label>
        <input class="input" id="goods_expected" name="expected_at" type="datetime-local"
               value="<?= e((string) ($old['expected_at'] ?? '')) ?>">
        <p class="field__hint">Times are treated in the estate timezone.</p>
      </div>

      <div class="field">
        <label class="field__label" for="goods_notes">Notes (optional)</label>
        <textarea class="input" id="goods_notes" name="notes" rows="2" maxlength="500"><?= e((string) ($old['notes'] ?? '')) ?></textarea>
      </div>

      <button type="submit" class="btn btn--primary btn--block">Send goods request</button>
    </form>
  </section>
</div>
