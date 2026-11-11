<?php

declare(strict_types=1);

/**
 * Quick Invite screen — resident pre-registers a visitor (visitor-access
 * contract §7, §7.1).
 *
 * A plain server-rendered POST form: it works without JavaScript and shows
 * inline field errors. The visitor receives a PIN + public pass link via the
 * development SMS adapter; the resident is redirected to the pass screen where
 * the PIN is revealed once.
 *
 * Variables (§7.1): $households, $default_valid_from, $default_valid_until,
 * $max_visitor_count, $estate_timezone, $errors, $old, $isDev + shell data.
 */

$households = isset($households) && is_array($households) ? $households : [];
$defaultValidFrom = isset($default_valid_from) && is_string($default_valid_from) ? $default_valid_from : '';
$defaultValidUntil = isset($default_valid_until) && is_string($default_valid_until) ? $default_valid_until : '';
$maxVisitorCount = isset($max_visitor_count) ? (int) $max_visitor_count : 10;
$maxVisitorCount = max(1, min(10, $maxVisitorCount));
$estateTimezone = isset($estate_timezone) && is_string($estate_timezone) && $estate_timezone !== ''
    ? $estate_timezone
    : (string) config('app.timezone', 'Africa/Nairobi');
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$isDev = (bool) ($isDev ?? false);

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estateName = trim((string) ($estate['estate_name'] ?? ''));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$value = static function (string $key, string $default = '') use ($old): string {
    $candidate = $old[$key] ?? $default;

    return is_scalar($candidate) ? trim((string) $candidate) : $default;
};

$fieldError = static function (string $field) use ($errors): string {
    if (!array_key_exists($field, $errors)) {
        return '';
    }
    $messages = $errors[$field];

    if (is_array($messages)) {
        $first = reset($messages);

        return is_scalar($first) ? trim((string) $first) : '';
    }

    return is_scalar($messages) ? trim((string) $messages) : '';
};

$allErrors = [];
foreach ($errors as $messages) {
    if (is_array($messages)) {
        foreach ($messages as $message) {
            if (is_scalar($message) && trim((string) $message) !== '') {
                $allErrors[] = trim((string) $message);
            }
        }
    } elseif (is_scalar($messages) && trim((string) $messages) !== '') {
        $allErrors[] = trim((string) $messages);
    }
}
$allErrors = array_values(array_unique($allErrors));

// Default household selection: the caller's primary household, otherwise first.
$defaultHousehold = '';
foreach ($households as $household) {
    if (!is_array($household)) {
        continue;
    }
    $householdUuid = trim((string) ($household['uuid'] ?? ''));
    if ($householdUuid === '') {
        continue;
    }
    if ($defaultHousehold === '' || (bool) ($household['is_primary'] ?? false)) {
        $defaultHousehold = $householdUuid;
    }
    if ((bool) ($household['is_primary'] ?? false)) {
        break;
    }
}
$selectedHousehold = $value('household_uuid', $defaultHousehold);

// The +254 prefix is shown outside the input, so strip it from the value.
$phoneValue = $value('visitor_phone');
if (str_starts_with($phoneValue, '+254')) {
    $phoneValue = substr($phoneValue, 4);
} elseif (str_starts_with($phoneValue, '254') && strlen($phoneValue) === 12) {
    $phoneValue = substr($phoneValue, 3);
} elseif (str_starts_with($phoneValue, '0') && strlen($phoneValue) === 10) {
    // The static +254 prefix is rendered outside the input, so drop the trunk zero.
    $phoneValue = substr($phoneValue, 1);
}

$visitorCountValue = $value('visitor_count', '1');
?>
<div class="resident resident--pushed">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app/visitors" aria-label="Back to visitors" title="Back to visitors">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">Invite a visitor</h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <p class="page-lede">
    <?php if ($estateName !== ''): ?>
      Pre-register a visitor for <?= e($estateName) ?>. They receive a PIN and a pass link by SMS,
      valid for the window you choose.
    <?php else: ?>
      Pre-register a visitor. They receive a PIN and a pass link by SMS, valid for the window you choose.
    <?php endif; ?>
  </p>

  <?php if ($allErrors !== []): ?>
    <div class="form-error-summary" role="alert">
      <?= $icon('alert', 'ico ico--sm') ?>
      <div>
        <p class="alert__title">Please check the highlighted fields</p>
        <ul>
          <?php foreach (array_slice($allErrors, 0, 6) as $message): ?>
            <li><?= e($message) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($isDev): ?>
    <div class="alert alert--info" role="note">
      <?= $icon('info') ?>
      <div class="alert__body">
        <p class="alert__title">Development SMS adapter</p>
        <p>
          No real SMS is sent in local development. The message is written to
          <span class="mono">storage/logs/sms.log</span>, and the visitor PIN is shown to you once on the
          pass screen after you create the invitation.
        </p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($households === []): ?>
    <div class="resident-empty">
      <span class="resident-empty__icon" aria-hidden="true"><?= $icon('home') ?></span>
      <p class="resident-empty__title">No household linked</p>
      <p class="resident-empty__text">
        You need an active household membership before you can pre-register a visitor.
        Ask your estate administrator to link your unit.
      </p>
      <a class="btn btn--outline" href="/app/visitors">Back to visitors</a>
    </div>
  <?php else: ?>
    <form method="post" action="/app/visitors/invite" class="invite-form" data-invite-form
          data-default-validity="<?= e((string) max(15, (int) config('visitors.invitation.default_validity_minutes', 240))) ?>">
      <?= csrf_field() ?>

      <section class="card form-section" aria-labelledby="visitor-details-title">
        <div class="card__head">
          <div>
            <h2 class="card__title" id="visitor-details-title"><?= $icon('users', 'ico ico--sm') ?> Visitor details</h2>
            <p class="card__hint">The visitor receives their pass by SMS on this number.</p>
          </div>
        </div>

        <div class="field-grid">
          <div class="field<?= $fieldError('visitor_name') !== '' ? ' field--invalid' : '' ?>">
            <label class="field__label" for="visitor_name">
              Visitor name
              <span class="muted text-xs">Required</span>
            </label>
            <input class="input<?= $fieldError('visitor_name') !== '' ? ' input--invalid' : '' ?>"
                   id="visitor_name" name="visitor_name" type="text"
                   value="<?= e($value('visitor_name')) ?>"
                   maxlength="180" required autocomplete="off"
                   aria-describedby="<?= $fieldError('visitor_name') !== '' ? 'visitor_name-error' : '' ?>">
            <?php if ($fieldError('visitor_name') !== ''): ?>
              <p class="field__error" id="visitor_name-error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($fieldError('visitor_name')) ?></p>
            <?php endif; ?>
          </div>

          <div class="field<?= $fieldError('visitor_phone') !== '' ? ' field--invalid' : '' ?>">
            <label class="field__label" for="visitor_phone">
              Visitor phone
              <span class="muted text-xs">Required</span>
            </label>
            <div class="input-group">
              <span class="input-group__prefix" aria-hidden="true">+254</span>
              <input class="input<?= $fieldError('visitor_phone') !== '' ? ' input--invalid' : '' ?>"
                     id="visitor_phone" name="visitor_phone" type="tel" inputmode="tel"
                     value="<?= e($phoneValue) ?>" placeholder="712 345 678"
                     autocomplete="tel" required
                     aria-describedby="<?= $fieldError('visitor_phone') !== '' ? 'visitor_phone-error' : 'visitor_phone-hint' ?>">
            </div>
            <p class="field__hint" id="visitor_phone-hint">Local (0712 345 678) or international (+254 712 345 678) format.</p>
            <?php if ($fieldError('visitor_phone') !== ''): ?>
              <p class="field__error" id="visitor_phone-error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($fieldError('visitor_phone')) ?></p>
            <?php endif; ?>
          </div>

          <div class="field<?= $fieldError('household_uuid') !== '' ? ' field--invalid' : '' ?>">
            <label class="field__label" for="household_uuid">Your household</label>
            <select class="select<?= $fieldError('household_uuid') !== '' ? ' input--invalid' : '' ?>"
                    id="household_uuid" name="household_uuid" required>
              <?php foreach ($households as $household): ?>
                <?php
                if (!is_array($household)) {
                    continue;
                }
                $householdUuid = trim((string) ($household['uuid'] ?? ''));
                if ($householdUuid === '') {
                    continue;
                }
                $label = trim((string) ($household['label'] ?? ''));
                if ($label === '') {
                    $unit = trim((string) ($household['unit_number'] ?? ''));
                    $building = trim((string) ($household['building_name'] ?? ''));
                    $label = $unit !== '' ? $unit : 'Household';
                    if ($building !== '') {
                        $label .= ' · ' . $building;
                    }
                }
                ?>
                <option value="<?= e($householdUuid) ?>" <?= $householdUuid === $selectedHousehold ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="field__hint">The visitor will be expected at this household.</p>
            <?php if ($fieldError('household_uuid') !== ''): ?>
              <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($fieldError('household_uuid')) ?></p>
            <?php endif; ?>
          </div>

          <div class="field<?= $fieldError('purpose') !== '' ? ' field--invalid' : '' ?>">
            <label class="field__label" for="purpose">
              Purpose
              <span class="muted text-xs">Optional</span>
            </label>
            <input class="input<?= $fieldError('purpose') !== '' ? ' input--invalid' : '' ?>"
                   id="purpose" name="purpose" type="text"
                   value="<?= e($value('purpose')) ?>"
                   maxlength="120" placeholder="Family visit, delivery, service…">
            <?php if ($fieldError('purpose') !== ''): ?>
              <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($fieldError('purpose')) ?></p>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="card form-section" aria-labelledby="validity-title">
        <div class="card__head">
          <div>
            <h2 class="card__title" id="validity-title"><?= $icon('clock', 'ico ico--sm') ?> Validity window</h2>
            <p class="card__hint">Times are shown in <?= e($estateTimezone) ?>. The PIN expires when the window ends.</p>
          </div>
        </div>

        <div class="field-grid">
          <div class="field<?= $fieldError('valid_from') !== '' ? ' field--invalid' : '' ?>">
            <label class="field__label" for="valid_from">Valid from</label>
            <input class="input<?= $fieldError('valid_from') !== '' ? ' input--invalid' : '' ?>"
                   id="valid_from" name="valid_from" type="datetime-local"
                   value="<?= e($value('valid_from', $defaultValidFrom)) ?>"
                   data-valid-from required>
            <?php if ($fieldError('valid_from') !== ''): ?>
              <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($fieldError('valid_from')) ?></p>
            <?php endif; ?>
          </div>

          <div class="field<?= $fieldError('valid_until') !== '' ? ' field--invalid' : '' ?>">
            <label class="field__label" for="valid_until">Valid until</label>
            <input class="input<?= $fieldError('valid_until') !== '' ? ' input--invalid' : '' ?>"
                   id="valid_until" name="valid_until" type="datetime-local"
                   value="<?= e($value('valid_until', $defaultValidUntil)) ?>"
                   data-valid-until required>
            <?php if ($fieldError('valid_until') !== ''): ?>
              <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($fieldError('valid_until')) ?></p>
            <?php endif; ?>
          </div>

          <div class="field<?= $fieldError('visitor_count') !== '' ? ' field--invalid' : '' ?>">
            <label class="field__label" for="visitor_count">Number of visitors</label>
            <input class="input<?= $fieldError('visitor_count') !== '' ? ' input--invalid' : '' ?>"
                   id="visitor_count" name="visitor_count" type="number"
                   value="<?= e($visitorCountValue) ?>"
                   min="1" max="<?= e((string) $maxVisitorCount) ?>" step="1" inputmode="numeric">
            <p class="field__hint">Up to <?= e((string) $maxVisitorCount) ?> people on this pass.</p>
            <?php if ($fieldError('visitor_count') !== ''): ?>
              <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($fieldError('visitor_count')) ?></p>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <div class="form-actions">
        <button type="submit" class="btn btn--primary btn--lg btn--block">
          <?= $icon('ticket', 'ico ico--sm') ?>
          Create invite &amp; send PIN
        </button>
        <a class="btn btn--ghost btn--block" href="/app/visitors">Cancel</a>
      </div>
    </form>
  <?php endif; ?>
</div>
