<?php

declare(strict_types=1);

/**
 * Resident household staff directory (staff-incidents contract §4).
 *
 * Lists staff registered for the caller's households with masked phone numbers,
 * status chips and deactivate/reactivate actions, plus an add form. All forms
 * are plain CSRF-protected POSTs so the screen works without JavaScript.
 *
 * Variables (T38 frozen keys): $staff [{uuid,full_name,phone_masked,staff_type,
 * status,status_label,status_tone,household_label,created_at}], $staff_types
 * [{value,label}], $households [{uuid,label,unit_number,building_name}],
 * $errors, $old + shell data.
 */

$staff = isset($staff) && is_array($staff) ? $staff : [];
$staffTypes = isset($staff_types) && is_array($staff_types) ? $staff_types : [];
$households = isset($households) && is_array($households) ? $households : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];

$timezone = trim((string) ($estate['estate_timezone'] ?? $estate['timezone'] ?? (string) config('app.timezone', 'Africa/Nairobi')));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$typeLabels = [];
foreach ($staffTypes as $type) {
    if (is_array($type) && isset($type['value'])) {
        $typeLabels[(string) $type['value']] = (string) ($type['label'] ?? ucfirst(str_replace('_', ' ', (string) $type['value'])));
    }
}
if ($typeLabels === []) {
    $typeLabels = [
        'domestic' => 'Domestic',
        'driver' => 'Driver',
        'gardener' => 'Gardener',
        'nanny' => 'Nanny',
        'cook' => 'Cook',
        'security' => 'Security',
        'other' => 'Other',
    ];
}

$humanise = static function (string $value): string {
    return $value === '' ? '' : ucwords(str_replace(['_', '-'], ' ', $value));
};

$oldValue = static function (string $field) use ($old): string {
    $candidate = $old[$field] ?? '';

    return is_scalar($candidate) ? trim((string) $candidate) : '';
};

$fieldErrors = static function (string $field) use ($errors): array {
    $messages = $errors[$field] ?? [];
    if (is_string($messages)) {
        $messages = [$messages];
    }

    return is_array($messages) ? array_values(array_filter($messages, static fn (mixed $m): bool => is_scalar($m))) : [];
};

$allErrors = [];
foreach ($errors as $messages) {
    if (is_string($messages)) {
        $allErrors[] = $messages;
    } elseif (is_array($messages)) {
        foreach ($messages as $message) {
            if (is_scalar($message) && trim((string) $message) !== '') {
                $allErrors[] = trim((string) $message);
            }
        }
    }
}
$allErrors = array_values(array_unique($allErrors));

$toneClass = static function (string $tone, string $status): string {
    $mapped = match ($tone) {
        'ok' => 'status--active',
        'warn' => 'status--pending',
        'danger' => 'status--danger',
        'neutral' => 'status--inactive',
        default => '',
    };
    if ($mapped !== '') {
        return $mapped;
    }

    return $status === 'active' ? 'status--active' : 'status--inactive';
};

$statusLabel = static function (string $status): string {
    return $status === 'active' ? 'Active' : 'Inactive';
};

$staffRows = array_values(array_filter($staff, 'is_array'));
$defaultHousehold = '';
foreach ($households as $household) {
    if (!is_array($household)) {
        continue;
    }
    $candidate = trim((string) ($household['uuid'] ?? ''));
    if ($candidate !== '') {
        $defaultHousehold = $candidate;
        break;
    }
}
$selectedHousehold = $oldValue('household_uuid') !== '' ? $oldValue('household_uuid') : $defaultHousehold;
?>
<div class="resident">
  <header class="screen-bar screen-bar--root">
    <h1 class="screen-bar__title">Household staff</h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= $icon('home', 'ico') ?>
    </a>
  </header>

  <p class="page-lede">
    Register the people who work in your household — housekeepers, drivers, gardeners, nannies and others.
    Their phone numbers are stored but only shown masked after saving.
  </p>

  <section class="resident-section" aria-labelledby="staff-list-title">
    <div class="resident-section__head">
      <div>
        <h2 class="resident-section__title" id="staff-list-title">Registered staff</h2>
        <p class="resident-section__hint">Deactivate someone when they stop working for you; their record is kept.</p>
      </div>
      <?php if ($staffRows !== []): ?>
        <span class="chip"><?= e((string) count($staffRows)) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($staffRows !== []): ?>
      <ul class="staff-list">
        <?php foreach ($staffRows as $member): ?>
          <?php
          $staffUuid = trim((string) ($member['uuid'] ?? ''));
          $fullName = trim((string) ($member['full_name'] ?? 'Staff member'));
          $phoneMasked = trim((string) ($member['phone_masked'] ?? ''));
          $staffType = trim((string) ($member['staff_type'] ?? ''));
          $status = trim((string) ($member['status'] ?? 'active'));
          $label = trim((string) ($member['status_label'] ?? '')) ?: $statusLabel($status);
          $tone = trim((string) ($member['status_tone'] ?? ''));
          $householdLabel = trim((string) ($member['household_label'] ?? ''));
          $createdAt = isset($member['created_at']) ? (string) $member['created_at'] : '';
          $isActive = $status === 'active';
          $initials = '';
          foreach (array_slice(preg_split('/\s+/', $fullName) ?: [], 0, 2) as $part) {
              if ($part !== '') {
                  $initials .= mb_strtoupper(mb_substr($part, 0, 1));
              }
          }
          ?>
          <li class="staff-card">
            <span class="staff-card__avatar" aria-hidden="true"><?= e($initials !== '' ? $initials : 'S') ?></span>
            <div class="staff-card__body">
              <p class="staff-card__name"><?= e($fullName) ?></p>
              <p class="staff-card__meta">
                <?= e($typeLabels[$staffType] ?? $humanise($staffType)) ?>
                <?php if ($phoneMasked !== ''): ?>
                  · <span class="mono"><?= e($phoneMasked) ?></span>
                <?php endif; ?>
              </p>
              <?php if ($householdLabel !== ''): ?>
                <p class="staff-card__meta">
                  <?= $icon('home', 'ico ico--xs') ?> <?= e($householdLabel) ?>
                  <?php if ($createdAt !== ''): ?>
                    · added <?= e(format_datetime($createdAt, $timezone, 'd M Y')) ?>
                  <?php endif; ?>
                </p>
              <?php endif; ?>
            </div>
            <div class="staff-card__side">
              <span class="status <?= e($toneClass($tone, $status)) ?>"><?= e($label) ?></span>
              <?php if ($staffUuid !== ''): ?>
                <?php if ($isActive): ?>
                  <form method="post" action="/app/staff/<?= e(rawurlencode($staffUuid)) ?>/deactivate"
                        data-confirm="Deactivate this staff member? Their record stays, but they will be marked inactive.">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--danger-ghost btn--sm">Deactivate</button>
                  </form>
                <?php else: ?>
                  <form method="post" action="/app/staff/<?= e(rawurlencode($staffUuid)) ?>/reactivate">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--outline btn--sm">Reactivate</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="resident-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('users') ?></span>
        <p class="resident-empty__title">No household staff yet</p>
        <p class="resident-empty__text">Register a housekeeper, driver, gardener or other household worker below.</p>
      </div>
    <?php endif; ?>
  </section>

  <section class="resident-section" aria-labelledby="add-staff-title">
    <div class="resident-section__head">
      <div>
        <h2 class="resident-section__title" id="add-staff-title">Add a staff member</h2>
        <p class="resident-section__hint">You can add staff for households you belong to in this estate.</p>
      </div>
    </div>

    <?php if ($households === []): ?>
      <div class="resident-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('home') ?></span>
        <p class="resident-empty__title">No household available</p>
        <p class="resident-empty__text">You need an active household membership before you can register staff.</p>
      </div>
    <?php else: ?>
      <form method="post" action="/app/staff" class="card form-section staff-form">
        <?= csrf_field() ?>

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

        <div class="field-grid">
          <div class="field<?= $fieldErrors('full_name') !== [] ? ' field--invalid' : '' ?>">
            <label class="field__label" for="staff_full_name">Full name</label>
            <input class="input<?= $fieldErrors('full_name') !== [] ? ' input--invalid' : '' ?>"
                   id="staff_full_name" name="full_name" type="text" maxlength="180" required
                   value="<?= e($oldValue('full_name')) ?>" placeholder="Staff member's full name">
            <?php foreach ($fieldErrors('full_name') as $message): ?>
              <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
            <?php endforeach; ?>
          </div>

          <div class="field<?= $fieldErrors('phone') !== [] ? ' field--invalid' : '' ?>">
            <label class="field__label" for="staff_phone">Phone number</label>
            <div class="input-group">
              <span class="input-group__prefix" aria-hidden="true">+254</span>
              <input class="input<?= $fieldErrors('phone') !== [] ? ' input--invalid' : '' ?>"
                     id="staff_phone" name="phone" type="tel" inputmode="tel"
                     value="<?= e($oldValue('phone')) ?>" placeholder="712 345 678" autocomplete="tel">
            </div>
            <p class="field__hint">Shown masked after saving.</p>
            <?php foreach ($fieldErrors('phone') as $message): ?>
              <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
            <?php endforeach; ?>
          </div>

          <div class="field<?= $fieldErrors('staff_type') !== [] ? ' field--invalid' : '' ?>">
            <label class="field__label" for="staff_type">Staff type</label>
            <select class="select" id="staff_type" name="staff_type" required>
              <?php foreach ($typeLabels as $typeValue => $typeText): ?>
                <option value="<?= e((string) $typeValue) ?>" <?= $oldValue('staff_type') === (string) $typeValue ? 'selected' : '' ?>>
                  <?= e($typeText) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php foreach ($fieldErrors('staff_type') as $message): ?>
              <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
            <?php endforeach; ?>
          </div>

          <div class="field<?= $fieldErrors('household_uuid') !== [] ? ' field--invalid' : '' ?>">
            <label class="field__label" for="staff_household">Household</label>
            <select class="select" id="staff_household" name="household_uuid" required>
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
                <option value="<?= e($householdUuid) ?>" <?= $selectedHousehold === $householdUuid ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php foreach ($fieldErrors('household_uuid') as $message): ?>
              <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="field<?= $fieldErrors('notes') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="staff_notes">
            Notes
            <span class="muted text-xs">Optional</span>
          </label>
          <textarea class="input" id="staff_notes" name="notes" rows="3" maxlength="500"
                    placeholder="Anything the household should remember (days, duties, access notes)."><?= e($oldValue('notes')) ?></textarea>
          <?php foreach ($fieldErrors('notes') as $message): ?>
            <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn--primary btn--lg btn--block"><?= $icon('plus', 'ico ico--sm') ?> Add staff member</button>
          <a class="btn btn--ghost btn--block" href="/app">Cancel</a>
        </div>
      </form>
    <?php endif; ?>
  </section>
</div>
