<?php

declare(strict_types=1);

/**
 * Guard parking violations (parking-administration contract §3, phase 1).
 *
 * Guards report a parking violation at the gate they are working (gate comes
 * from the session) and see only the reports they filed. Plain CSRF-protected
 * POST; errors/old preserved; explicit "choose a gate first" state.
 *
 * Variables (T60 frozen keys, defensive): $violations [], $violation_types [],
 * $bays [], $errors [], $old [], $gate/$selected_gate + guard shell data.
 */

$violations = isset($violations) && is_array($violations) ? $violations : [];
// T60 actual key is type_options; keep violation_types as a defensive alias.
$violationTypes = isset($violation_types) && is_array($violation_types) && $violation_types !== []
    ? $violation_types
    : (isset($type_options) && is_array($type_options) ? $type_options : []);
$bays = isset($bays) && is_array($bays) ? $bays : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$selectedGate = isset($selected_gate) && is_array($selected_gate) ? $selected_gate : null;
$gateName = trim((string) ($gate ?? ($selectedGate['name'] ?? '')));
$timezone = trim((string) ($estate['estate_timezone'] ?? $estate['timezone'] ?? (string) config('app.timezone', 'Africa/Nairobi')));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

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

$typeMap = [];
foreach ($violationTypes as $item) {
    if (is_string($item) && trim($item) !== '') {
        $typeMap[trim($item)] = ucwords(str_replace('_', ' ', trim($item)));
        continue;
    }
    if (!is_array($item)) {
        continue;
    }
    $value = trim((string) ($item['value'] ?? ''));
    if ($value === '') {
        continue;
    }
    $label = trim((string) ($item['label'] ?? ''));
    $typeMap[$value] = $label !== '' ? $label : ucwords(str_replace('_', ' ', $value));
}
if ($typeMap === []) {
    $typeMap = [
        'overstay' => 'Overstay',
        'unauthorised_bay' => 'Unauthorised bay',
        'unregistered_vehicle' => 'Unregistered vehicle',
        'other' => 'Other',
    ];
}
$bayOptions = [];
foreach ($bays as $bay) {
    if (!is_array($bay)) {
        continue;
    }
    $uuid = trim((string) ($bay['uuid'] ?? ''));
    if ($uuid === '') {
        continue;
    }
    $label = trim((string) ($bay['bay_code'] ?? ($bay['label'] ?? '')));
    $bayOptions[$uuid] = $label !== '' ? $label : ucfirst(str_replace('_', ' ', $uuid));
}

$chipClass = static function (string $tone, string $status = ''): string {
    $mapped = match ($tone) {
        'ok' => 'g-chip--ok',
        'warn' => 'g-chip--warn',
        'danger' => 'g-chip--danger',
        'neutral' => 'g-chip--neutral',
        default => '',
    };
    if ($mapped !== '') {
        return $mapped;
    }

    return match ($status) {
        'open' => 'g-chip--danger',
        'resolved' => 'g-chip--ok',
        'dismissed' => 'g-chip--neutral',
        default => 'g-chip--neutral',
    };
};
$humanise = static function (string $value): string {
    return $value === '' ? '' : ucwords(str_replace(['_', '-'], ' ', $value));
};
$rows = array_values(array_filter($violations, 'is_array'));
?>
<div class="g-screen">
  <header class="g-head">
    <div>
      <p class="g-head__eyebrow"><?= $gateName !== '' ? e($gateName) : 'No gate selected' ?></p>
      <h1 class="g-head__title">Parking violations</h1>
    </div>
    <a class="g-head__link" href="/gate"><?= $icon('home', 'ico ico--sm') ?> Dashboard</a>
  </header>

  <?php if ($gateName === ''): ?>
    <section class="g-panel">
      <div class="g-empty">
        <span class="g-empty__icon" aria-hidden="true"><?= $icon('gate') ?></span>
        <p class="g-empty__title">Choose a gate first</p>
        <p class="g-empty__text">Parking violations are recorded against the gate you are working at.</p>
        <p><a class="btn btn--primary btn--lg" href="/gate">Go to dashboard</a></p>
      </div>
    </section>
  <?php else: ?>
    <section class="g-panel" aria-labelledby="parking-report-title">
      <div class="g-panel__head">
        <h2 class="g-panel__title" id="parking-report-title">Report a violation</h2>
        <p class="g-panel__hint">The report is linked to <?= e($gateName) ?>. Never record PINs or passcodes.</p>
      </div>

      <form method="post" action="/gate/parking/violations" class="g-form">
        <?= csrf_field() ?>

        <?php if ($allErrors !== []): ?>
          <div class="alert alert--error" role="alert">
            <?= $icon('alert') ?>
            <div class="alert__body">
              <p class="alert__title">Please check the highlighted fields</p>
              <ul>
                <?php foreach (array_slice($allErrors, 0, 6) as $message): ?>
                  <li><?= e($message) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        <?php endif; ?>

        <div class="field<?= $fieldErrors('type') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="guard_violation_type">Violation type</label>
          <select class="input" id="guard_violation_type" name="type" required>
            <option value="">Choose a violation type</option>
            <?php foreach ($typeMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('type') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php foreach ($fieldErrors('type') as $message): ?>
            <p class="field__error" role="alert"><?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <?php if ($bayOptions !== []): ?>
          <div class="field<?= $fieldErrors('bay_uuid') !== [] ? ' field--invalid' : '' ?>">
            <label class="field__label" for="guard_violation_bay">Bay</label>
            <select class="input" id="guard_violation_bay" name="bay_uuid" required>
              <option value="">Choose a bay</option>
              <?php foreach ($bayOptions as $value => $label): ?>
                <option value="<?= e((string) $value) ?>" <?= $oldValue('bay_uuid') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php foreach ($fieldErrors('bay_uuid') as $message): ?>
              <p class="field__error" role="alert"><?= e($message) ?></p>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="field<?= $fieldErrors('plate_number') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="guard_violation_plate">
            Plate number
            <span class="muted text-xs">Optional</span>
          </label>
          <input class="input" id="guard_violation_plate" name="plate_number" type="text" maxlength="20"
                 value="<?= e($oldValue('plate_number', $oldValue('vehicle_plate'))) ?>" placeholder="e.g. KDA 123A">
          <?php foreach ($fieldErrors('plate_number') as $message): ?>
            <p class="field__error" role="alert"><?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <div class="field<?= $fieldErrors('notes') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="guard_violation_notes">What did you observe?</label>
          <textarea class="input" id="guard_violation_notes" name="notes" rows="4" maxlength="500" required
                    placeholder="Describe the violation, bay and any action you took."><?= e($oldValue('notes')) ?></textarea>
          <?php foreach ($fieldErrors('notes') as $message): ?>
            <p class="field__error" role="alert"><?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <div class="g-form__actions">
          <button type="submit" class="btn btn--primary btn--lg btn--block g-submit"><?= $icon('alert', 'ico ico--sm') ?> Submit report</button>
          <a class="btn btn--ghost btn--block" href="/gate">Cancel</a>
        </div>
      </form>
    </section>

    <section class="g-panel" aria-labelledby="parking-own-title">
      <div class="g-panel__head">
        <h2 class="g-panel__title" id="parking-own-title">My reports</h2>
        <span class="g-count"><strong><?= e((string) count($rows)) ?></strong> filed</span>
      </div>

      <?php if ($rows !== []): ?>
        <ul class="g-list">
          <?php foreach ($rows as $violation): ?>
            <?php
            $type = trim((string) ($violation['type'] ?? ($violation['violation_type'] ?? '')));
            $typeLabel = trim((string) ($violation['type_label'] ?? ($violation['violation_type_label'] ?? ''))) ?: ($typeMap[$type] ?? $humanise($type));
            $status = trim((string) ($violation['status'] ?? 'open'));
            $statusLabel = trim((string) ($violation['status_label'] ?? '')) ?: $humanise($status);
            $statusTone = trim((string) ($violation['status_tone'] ?? ''));
            $bayLabel = trim((string) ($violation['bay_code'] ?? ($violation['bay_label'] ?? '')));
            $plate = trim((string) ($violation['vehicle_plate'] ?? ($violation['plate_number'] ?? '')));
            $notes = trim((string) ($violation['notes'] ?? ($violation['description'] ?? '')));
            $createdAt = isset($violation['created_at']) ? (string) $violation['created_at'] : '';
            ?>
            <li class="g-violation">
              <div class="g-violation__head">
                <div class="g-violation__body">
                  <p class="g-violation__title"><?= e($typeLabel !== '' ? $typeLabel : 'Parking violation') ?></p>
                  <p class="g-violation__meta">
                    <span class="g-chip <?= e($chipClass($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
                    <?php if ($bayLabel !== ''): ?>
                      <span>Bay <?= e($bayLabel) ?></span>
                    <?php endif; ?>
                    <?php if ($plate !== ''): ?>
                      <span class="mono"><?= e($plate) ?></span>
                    <?php endif; ?>
                  </p>
                </div>
              </div>
              <?php if ($notes !== ''): ?>
                <p class="g-violation__notes"><?= e($notes) ?></p>
              <?php endif; ?>
              <?php if ($createdAt !== ''): ?>
                <p class="g-violation__meta g-violation__meta--date">
                  <?= $icon('clock', 'ico ico--xs') ?> <?= e(format_datetime($createdAt, $timezone, 'd M Y, H:i')) ?>
                </p>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="g-empty">
          <span class="g-empty__icon" aria-hidden="true"><?= $icon('parking') ?></span>
          <p class="g-empty__title">No violations reported yet</p>
          <p class="g-empty__text">Reports you file at the gate appear here with their review status.</p>
          <p><a class="btn btn--outline btn--lg" href="#guard_violation_type">Report a violation</a></p>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>
