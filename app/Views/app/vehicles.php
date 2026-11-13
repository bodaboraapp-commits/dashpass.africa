<?php

declare(strict_types=1);

/**
 * Household vehicles screen (vehicles-parking contract §4, §2).
 *
 * Lists the household's registered vehicles with plate/type/colour/status,
 * offers an inline add form (plain POST so it works without JavaScript) and a
 * deactivate action for active vehicles. No fabricated rows: when the household
 * has no vehicles the view renders an honest empty state.
 *
 * Variables (T21 view-data contract): $vehicles (list), $errors, $old,
 * optional $vehicle_types + shell data.
 */

$vehicles = isset($vehicles) && is_array($vehicles) ? $vehicles : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];

// The list shows active household vehicles only; deactivated/blacklisted rows
// stay in the database (and can be re-activated by management) but are hidden
// here so a stale plate is never presented as usable. A summary line keeps the
// resident aware of them without rendering the plate.
$activeVehicles = [];
$hiddenVehicleCount = 0;
foreach ($vehicles as $vehicleRow) {
    if (!is_array($vehicleRow)) {
        continue;
    }
    if (trim((string) ($vehicleRow['status'] ?? 'active')) === 'active') {
        $activeVehicles[] = $vehicleRow;
    } else {
        $hiddenVehicleCount++;
    }
}

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estateName = trim((string) ($estate['estate_name'] ?? ''));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$typeLabels = [
    'car' => 'Car',
    'motorcycle' => 'Motorcycle',
    'van' => 'Van',
    'truck' => 'Truck',
    'other' => 'Other',
];
if (isset($vehicle_types) && is_array($vehicle_types)) {
    // Accept either [{value,label}] (T20 canonical extra) or [value => label].
    foreach ($vehicle_types as $key => $label) {
        if (is_array($label)) {
            $typeKey = trim((string) ($label['value'] ?? $label['key'] ?? ''));
            $typeLabel = trim((string) ($label['label'] ?? ''));
            if ($typeKey !== '' && $typeLabel !== '' && array_key_exists($typeKey, $typeLabels)) {
                $typeLabels[$typeKey] = $typeLabel;
            }
            continue;
        }
        if (is_string($key) && is_string($label) && array_key_exists($key, $typeLabels)) {
            $typeLabels[$key] = $label;
        }
    }
}

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

$statusClass = static function (string $status, string $tone = ''): string {
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

    return match ($status) {
        'active' => 'status--active',
        'blacklisted' => 'status--danger',
        default => 'status--inactive',
    };
};

$statusLabel = static function (string $status): string {
    return match ($status) {
        'active' => 'Active',
        'blacklisted' => 'Blacklisted',
        default => 'Inactive',
    };
};
?>
<div class="resident resident--pushed">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title vehicle-title">
      <span class="vehicle-title__icon" aria-hidden="true"><?= $icon('vehicle', 'ico ico--sm') ?></span>
      Vehicles
    </h1>
    <a class="screen-bar__action" href="/app/parking" aria-label="My parking" title="My parking">
      <?= $icon('parking', 'ico') ?>
    </a>
  </header>

  <section class="resident-section" aria-labelledby="vehicles-title">
    <div class="resident-section__head">
      <div>
        <h2 class="resident-section__title" id="vehicles-title">Household vehicles</h2>
        <p class="resident-section__hint">
          <?php if ($estateName !== ''): ?>
            Registered vehicles for <?= e($estateName) ?>. Plates are used for parking and gate matching.
          <?php else: ?>
            Registered vehicles for this household. Plates are used for parking and gate matching.
          <?php endif; ?>
        </p>
      </div>
      <?php if ($activeVehicles !== []): ?>
        <span class="chip"><?= e((string) count($activeVehicles)) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($activeVehicles !== []): ?>
      <ul class="vehicle-list">
        <?php foreach ($activeVehicles as $vehicle): ?>
          <?php
          if (!is_array($vehicle)) {
              continue;
          }
          $vehicleUuid = trim((string) ($vehicle['uuid'] ?? ''));
          $plate = trim((string) ($vehicle['plate_number'] ?? ''));
          $type = trim((string) ($vehicle['vehicle_type'] ?? 'car'));
          $typeLabel = $typeLabels[$type] ?? 'Vehicle';
          $colour = trim((string) ($vehicle['colour'] ?? ''));
          $status = trim((string) ($vehicle['status'] ?? 'active'));
          $statusTone = trim((string) ($vehicle['status_tone'] ?? ''));
          $label = trim((string) ($vehicle['status_label'] ?? '')) ?: $statusLabel($status);
          $canDeactivate = $status === 'active' && $vehicleUuid !== '';
          ?>
          <li class="vehicle-card">
            <span class="vehicle-card__icon" aria-hidden="true"><?= $icon('vehicle') ?></span>
            <div class="vehicle-card__body">
              <div class="vehicle-card__heading">
                <p class="vehicle-card__plate"><?= e($plate !== '' ? $plate : 'Unregistered plate') ?></p>
                <span class="status <?= e($statusClass($status, $statusTone)) ?>"><?= e($label) ?></span>
              </div>
              <p class="vehicle-card__meta">
                <?= e($typeLabel) ?>
                <?php if ($colour !== ''): ?>
                  <span class="vehicle-card__dot" aria-hidden="true"></span> <?= e($colour) ?>
                <?php endif; ?>
              </p>
            </div>
            <?php if ($canDeactivate): ?>
              <div class="vehicle-card__side">
                <form method="post" action="/app/vehicles/<?= e(rawurlencode($vehicleUuid)) ?>/deactivate"
                      data-confirm="Deactivate this vehicle? It will no longer be available for visitor parking.">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn--danger-ghost btn--sm">Deactivate</button>
                </form>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="resident-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('vehicle') ?></span>
        <p class="resident-empty__title">No vehicles yet</p>
        <p class="resident-empty__text">Register a household vehicle below so it can be used for parking and gate matching.</p>
      </div>
    <?php endif; ?>

    <?php if ($hiddenVehicleCount > 0): ?>
      <p class="vehicle-hidden-note">
        <?= $icon('info', 'ico ico--sm') ?>
        <?= e((string) $hiddenVehicleCount) ?> deactivated or blocked <?= $hiddenVehicleCount === 1 ? 'vehicle is' : 'vehicles are' ?>
        hidden from this list. Ask estate management to re-activate one if needed.
      </p>
    <?php endif; ?>
  </section>

  <section class="resident-section" aria-labelledby="add-vehicle-title">
    <?php /* Owner request (2026-11-13): the add-vehicle form is hidden until the
       resident taps "+ Add vehicle". <details> gives the collapse/expand toggle
       without JavaScript, so the no-JS plain-POST form still works. Server-side
       validation errors auto-open it so the resident sees what to fix. */ ?>
    <details class="add-vehicle"<?= $errors !== [] ? ' open' : '' ?>>
      <summary class="add-vehicle__summary btn btn--primary btn--lg btn--block">
        <?= $icon('plus', 'ico ico--sm') ?>
        <span class="add-vehicle__label" id="add-vehicle-title">Add a vehicle</span>
      </summary>
      <div class="add-vehicle__body">
        <p class="resident-section__hint">The plate is stored uppercase without spaces or hyphens.</p>

        <form method="post" action="/app/vehicles" class="card form-section vehicle-form">
          <?= csrf_field() ?>

          <div class="field-grid">
        <div class="field<?= $fieldError('plate_number') !== '' ? ' field--invalid' : '' ?>">
          <label class="field__label" for="plate_number">
            Plate number
            <span class="muted text-xs">Required</span>
          </label>
          <input class="input<?= $fieldError('plate_number') !== '' ? ' input--invalid' : '' ?>"
                 id="plate_number" name="plate_number" type="text"
                 value="<?= e($value('plate_number')) ?>"
                 placeholder="KXX 123A" maxlength="20" required autocomplete="off"
                 autocapitalize="characters" spellcheck="false"
                 aria-describedby="<?= $fieldError('plate_number') !== '' ? 'plate_number-error' : 'plate_number-hint' ?>">
          <p class="field__hint" id="plate_number-hint">4 to 12 letters and numbers. Spaces and hyphens are removed automatically.</p>
          <?php if ($fieldError('plate_number') !== ''): ?>
            <p class="field__error" id="plate_number-error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($fieldError('plate_number')) ?></p>
          <?php endif; ?>
        </div>

        <div class="field<?= $fieldError('vehicle_type') !== '' ? ' field--invalid' : '' ?>">
          <label class="field__label" for="vehicle_type">Vehicle type</label>
          <select class="select<?= $fieldError('vehicle_type') !== '' ? ' input--invalid' : '' ?>"
                  id="vehicle_type" name="vehicle_type" required>
            <?php foreach ($typeLabels as $typeKey => $typeLabel): ?>
              <option value="<?= e($typeKey) ?>" <?= $value('vehicle_type', 'car') === $typeKey ? 'selected' : '' ?>>
                <?= e($typeLabel) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($fieldError('vehicle_type') !== ''): ?>
            <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($fieldError('vehicle_type')) ?></p>
          <?php endif; ?>
        </div>

        <div class="field<?= $fieldError('colour') !== '' ? ' field--invalid' : '' ?>">
          <label class="field__label" for="colour">
            Colour
            <span class="muted text-xs">Optional</span>
          </label>
          <input class="input<?= $fieldError('colour') !== '' ? ' input--invalid' : '' ?>"
                 id="colour" name="colour" type="text"
                 value="<?= e($value('colour')) ?>" maxlength="40" placeholder="White">
          <?php if ($fieldError('colour') !== ''): ?>
            <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($fieldError('colour')) ?></p>
          <?php endif; ?>
        </div>
      </div>

          <div class="form-actions">
            <button type="submit" class="btn btn--primary btn--lg btn--block">
              <?= $icon('plus', 'ico ico--sm') ?>
              Add vehicle
            </button>
          </div>
        </form>
      </div>
    </details>
  </section>
</div>
