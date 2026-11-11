<?php

declare(strict_types=1);

/**
 * Guard incident report form (staff-incidents contract §4).
 *
 * Guard records an incident at the gate they are working (gate comes from the
 * server-side session; there is no gate selector). Plain POST form with CSRF,
 * inline errors and old-value preservation.
 *
 * Variables (T38 frozen keys): $incident_types, $severities, $errors, $old,
 * $gate / $selected_gate + guard shell data.
 */

$incidentTypes = isset($incident_types) && is_array($incident_types) ? $incident_types : [];
$severities = isset($severities) && is_array($severities) ? $severities : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$selectedGate = isset($selected_gate) && is_array($selected_gate) ? $selected_gate : null;
$gateName = trim((string) ($gate ?? ($selectedGate['name'] ?? '')));

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

$options = static function (array $items): array {
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $value = trim((string) ($item['value'] ?? $item['uuid'] ?? ''));
        if ($value === '') {
            continue;
        }
        $label = trim((string) ($item['label'] ?? $item['name'] ?? ''));
        $out[$value] = $label !== '' ? $label : ucfirst(str_replace('_', ' ', $value));
    }

    return $out;
};

$typeOptions = $options($incidentTypes);
if ($typeOptions === []) {
    $typeOptions = [
        'unauthorised_access' => 'Unauthorised access',
        'tailgating' => 'Tailgating',
        'lost_pass' => 'Lost pass',
        'suspicious_activity' => 'Suspicious activity',
        'property_damage' => 'Property damage',
        'medical' => 'Medical',
        'other' => 'Other',
    ];
}
$severityOptions = $options($severities);
if ($severityOptions === []) {
    $severityOptions = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
}
$selectedSeverity = $oldValue('severity');
if ($selectedSeverity === '') {
    $selectedSeverity = array_key_exists('medium', $severityOptions) ? 'medium' : (string) array_key_first($severityOptions);
}

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
?>
<div class="g-screen">
  <header class="g-head">
    <div>
      <p class="g-head__eyebrow"><?= $gateName !== '' ? e($gateName) : 'No gate selected' ?></p>
      <h1 class="g-head__title">Report an incident</h1>
    </div>
    <a class="g-head__link" href="/gate/incidents"><?= $icon('alert', 'ico ico--sm') ?> Reports</a>
  </header>

  <?php if ($gateName === ''): ?>
    <section class="g-panel">
      <div class="g-empty">
        <span class="g-empty__icon" aria-hidden="true"><?= $icon('gate') ?></span>
        <p class="g-empty__title">Choose a gate first</p>
        <p class="g-empty__text">Incident reports are recorded against the gate you are working at.</p>
        <p><a class="btn btn--primary btn--lg" href="/gate">Go to dashboard</a></p>
      </div>
    </section>
  <?php else: ?>
    <section class="g-panel" aria-labelledby="guard-incident-form-title">
      <div class="g-panel__head">
        <h2 class="g-panel__title" id="guard-incident-form-title">Incident details</h2>
        <p class="g-panel__hint">The report is linked to <?= e($gateName) ?>. Describe only what you saw — never record PINs or passcodes.</p>
      </div>

      <form method="post" action="/gate/incidents" class="g-form">
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

        <div class="field<?= $fieldErrors('incident_type') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="guard_incident_type">What happened?</label>
          <select class="input" id="guard_incident_type" name="incident_type" required>
            <option value="">Choose an incident type</option>
            <?php foreach ($typeOptions as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('incident_type') === (string) $value ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php foreach ($fieldErrors('incident_type') as $message): ?>
            <p class="field__error" role="alert"><?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <div class="field<?= $fieldErrors('severity') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="guard_incident_severity">Severity</label>
          <select class="input" id="guard_incident_severity" name="severity" required>
            <?php foreach ($severityOptions as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $selectedSeverity === (string) $value ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php foreach ($fieldErrors('severity') as $message): ?>
            <p class="field__error" role="alert"><?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <div class="field<?= $fieldErrors('summary') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="guard_incident_summary">Short summary</label>
          <input class="input" id="guard_incident_summary" name="summary" type="text" maxlength="180" required
                 value="<?= e($oldValue('summary')) ?>" placeholder="e.g. Two people followed a resident through the gate">
          <?php foreach ($fieldErrors('summary') as $message): ?>
            <p class="field__error" role="alert"><?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <div class="field<?= $fieldErrors('occurred_at') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="guard_incident_occurred_at">When did it happen?</label>
          <input class="input" id="guard_incident_occurred_at" name="occurred_at" type="datetime-local"
                 value="<?= e($oldValue('occurred_at')) ?>">
          <p class="field__hint">Leave blank to record the current time.</p>
          <?php foreach ($fieldErrors('occurred_at') as $message): ?>
            <p class="field__error" role="alert"><?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <div class="field<?= $fieldErrors('description') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="guard_incident_description">What did you see?</label>
          <textarea class="input" id="guard_incident_description" name="description" rows="5" maxlength="2000" required
                    placeholder="Describe the incident, people involved and any action you took. Never include PINs or passcodes."><?= e($oldValue('description')) ?></textarea>
          <?php foreach ($fieldErrors('description') as $message): ?>
            <p class="field__error" role="alert"><?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <div class="g-form__actions">
          <button type="submit" class="btn btn--primary btn--lg btn--block g-submit"><?= $icon('alert', 'ico ico--sm') ?> Submit report</button>
          <a class="btn btn--ghost btn--block" href="/gate/incidents">Cancel</a>
        </div>
      </form>
    </section>
  <?php endif; ?>
</div>
