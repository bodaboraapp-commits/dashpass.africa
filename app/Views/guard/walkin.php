<?php

declare(strict_types=1);

/**
 * Guard walk-in screen (visitor-access-module-contract §7, §7.1).
 *
 * The guard records an unplanned visitor at the gate; the household must approve
 * before access is granted. The create form is a plain POST (works without
 * JavaScript); the pending list shows requests still waiting for a resident.
 *
 * Variables: shell + $households [{uuid,label,unit_number,building_name}],
 * $pending_walkins [WalkIn], $errors (map), $old (map).
 */

$households = isset($households) && is_array($households) ? $households : [];
$pendingWalkins = isset($pending_walkins) && is_array($pending_walkins) ? $pending_walkins : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$selectedGate = isset($selected_gate) && is_array($selected_gate) ? $selected_gate : null;
$gateName = trim((string) ($gate ?? ($selectedGate['name'] ?? '')));
$timezone = (string) config('app.timezone', 'Africa/Nairobi');

$oldValue = static function (string $field) use ($old): string {
    $value = $old[$field] ?? '';

    return is_scalar($value) ? trim((string) $value) : '';
};

$fieldErrors = static function (string $field) use ($errors): array {
    $messages = $errors[$field] ?? [];

    if (is_string($messages)) {
        $messages = [$messages];
    }

    return is_array($messages) ? array_values(array_filter($messages, 'is_string')) : [];
};

$oldHousehold = $oldValue('household_uuid');
$pendingItems = array_values(array_filter($pendingWalkins, 'is_array'));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="g-screen">
  <header class="g-head">
    <div>
      <p class="g-head__eyebrow"><?= $gateName !== '' ? e($gateName) : 'No gate selected' ?></p>
      <h1 class="g-head__title">Walk-in request</h1>
    </div>
    <a class="g-head__link" href="/gate/active"><?= $icon('users', 'ico ico--sm') ?> Inside</a>
  </header>

  <?php if ($gateName === ''): ?>
    <section class="g-panel">
      <div class="g-empty">
        <span class="g-empty__icon" aria-hidden="true"><?= $icon('gate') ?></span>
        <p class="g-empty__title">Choose a gate first</p>
        <p class="g-empty__text">Walk-in requests are recorded against the gate you are working at.</p>
        <p><a class="btn btn--primary btn--lg" href="/gate">Go to dashboard</a></p>
      </div>
    </section>
  <?php else: ?>
    <section class="g-panel" aria-labelledby="walkin-create-title">
      <div class="g-panel__head">
        <h2 class="g-panel__title" id="walkin-create-title">New walk-in</h2>
        <p class="g-panel__hint">
          The visitor's household receives an approval request. Never admit a walk-in before it is approved.
        </p>
      </div>

      <form method="post" action="/gate/walkin" class="g-form" data-guard-walkin>
        <?= csrf_field() ?>

        <div class="field<?= $fieldErrors('visitor_name') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="walkin-name">Visitor full name</label>
          <input class="input" id="walkin-name" name="visitor_name" type="text" required
                 maxlength="180" autocomplete="name" value="<?= e($oldValue('visitor_name')) ?>"
                 <?= $fieldErrors('visitor_name') !== [] ? 'aria-invalid="true"' : '' ?>>
          <?php foreach ($fieldErrors('visitor_name') as $message): ?>
            <p class="field__error" role="alert"><?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <div class="field<?= $fieldErrors('visitor_phone') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="walkin-phone">Visitor phone</label>
          <input class="input" id="walkin-phone" name="visitor_phone" type="tel" required
                 inputmode="tel" autocomplete="tel" placeholder="07xx xxx xxx"
                 value="<?= e($oldValue('visitor_phone')) ?>"
                 <?= $fieldErrors('visitor_phone') !== [] ? 'aria-invalid="true"' : '' ?>>
          <?php foreach ($fieldErrors('visitor_phone') as $message): ?>
            <p class="field__error" role="alert"><?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <div class="field<?= $fieldErrors('household_uuid') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="walkin-household">Visiting household</label>
          <select class="input" id="walkin-household" name="household_uuid" required
                  <?= $fieldErrors('household_uuid') !== [] ? 'aria-invalid="true"' : '' ?>>
            <option value="">Choose a unit…</option>
            <?php foreach ($households as $household): ?>
              <?php if (!is_array($household)) { continue; } ?>
              <?php
              $householdUuid = (string) ($household['uuid'] ?? '');
              $householdLabel = trim((string) ($household['label'] ?? ''));
              $unitNumber = trim((string) ($household['unit_number'] ?? ''));
              $buildingName = trim((string) ($household['building_name'] ?? ''));

              if ($householdLabel === '') {
                  $householdLabel = $unitNumber !== '' ? $unitNumber : 'Household';
              }
              ?>
              <option value="<?= e($householdUuid) ?>" <?= $oldHousehold === $householdUuid ? 'selected' : '' ?>>
                <?= e($householdLabel) ?><?= $buildingName !== '' ? ', ' . e($buildingName) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php foreach ($fieldErrors('household_uuid') as $message): ?>
            <p class="field__error" role="alert"><?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <div class="field<?= $fieldErrors('purpose') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="walkin-purpose">Purpose <span class="muted">(optional)</span></label>
          <input class="input" id="walkin-purpose" name="purpose" type="text" maxlength="120"
                 placeholder="Delivery, contractor, family visit…"
                 value="<?= e($oldValue('purpose')) ?>"
                 <?= $fieldErrors('purpose') !== [] ? 'aria-invalid="true"' : '' ?>>
          <?php foreach ($fieldErrors('purpose') as $message): ?>
            <p class="field__error" role="alert"><?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <button type="submit" class="btn btn--primary btn--lg btn--block g-submit">
          <?= $icon('ticket') ?>
          Send approval request
        </button>
      </form>
    </section>

    <section class="g-panel" aria-labelledby="walkin-pending-title">
      <div class="g-panel__head">
        <h2 class="g-panel__title" id="walkin-pending-title">Pending walk-ins</h2>
        <a class="g-panel__link" href="/gate/walkin">Refresh</a>
      </div>

      <?php if ($pendingItems !== []): ?>
        <ul class="g-list">
          <?php foreach ($pendingItems as $walkin): ?>
            <?= \App\Core\View::capture('guard/partials/walkin-card', [
                'walkin' => $walkin,
                'timezone' => $timezone,
            ]) ?>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="g-empty-line">No walk-ins are waiting for a resident reply.</p>
      <?php endif; ?>

      <p class="g-muted g-note">
        A resident must approve the request before the visitor can be verified and checked in.
      </p>
    </section>
  <?php endif; ?>
</div>
