<?php

declare(strict_types=1);

/**
 * Resident incident report form (staff-incidents contract §4).
 *
 * Plain CSRF-protected POST form: incident type, severity, summary, description,
 * optional occurred-at time, gate, household and invitation. Inline errors and
 * old values are preserved on validation failure.
 *
 * Variables (T38 frozen keys): $incident_types, $severities, $gates,
 * $households, $invitations, $errors, $old + shell data.
 */

$incidentTypes = isset($incident_types) && is_array($incident_types) ? $incident_types : [];
$severities = isset($severities) && is_array($severities) ? $severities : [];
$gates = isset($gates) && is_array($gates) ? $gates : [];
$households = isset($households) && is_array($households) ? $households : [];
$invitations = isset($invitations) && is_array($invitations) ? $invitations : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];

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

/** Normalise a frozen list of {value,label} options (also accepts {uuid,name}). */
$options = static function (array $items, string $valueKey = 'value', string $labelKey = 'label'): array {
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $value = trim((string) ($item[$valueKey] ?? $item['uuid'] ?? ''));
        if ($value === '') {
            continue;
        }
        $label = trim((string) ($item[$labelKey] ?? $item['name'] ?? $item['label'] ?? $item['unit_number'] ?? $item['visitor_name'] ?? ''));
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
$gateOptions = $options($gates, 'uuid', 'name');
$householdOptions = $options($households, 'uuid', 'label');
$invitationOptions = $options($invitations, 'uuid', 'visitor_name');

$selectedSeverity = $oldValue('severity');
if ($selectedSeverity === '') {
    $selectedSeverity = array_key_exists('medium', $severityOptions) ? 'medium' : (string) array_key_first($severityOptions);
}
?>
<div class="resident">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app/incidents" aria-label="Back to my incidents" title="Back to my incidents">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">Report an incident</h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <p class="page-lede">
    Tell the security team what happened. Reports are shared with estate security, and you can see their status
    on your incidents list. This is not an emergency service, call the estate gate for immediate danger.
  </p>

  <form method="post" action="/app/incidents" class="card form-section incident-form">
    <?= csrf_field() ?>

    <?php if ($allErrors !== []): ?>
      <div class="form-error-summary" role="alert">
        <?= $icon('alert', 'ico ico--sm') ?>
        <div>
          <p class="alert__title">Please check the highlighted fields</p>
          <ul>
            <?php foreach (array_slice($allErrors, 0, 8) as $message): ?>
              <li><?= e($message) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <div class="field-grid">
      <div class="field<?= $fieldErrors('incident_type') !== [] ? ' field--invalid' : '' ?>">
        <label class="field__label" for="incident_type">What happened?</label>
        <select class="select" id="incident_type" name="incident_type" required>
          <option value="">Choose an incident type</option>
          <?php foreach ($typeOptions as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $oldValue('incident_type') === (string) $value ? 'selected' : '' ?>>
              <?= e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php foreach ($fieldErrors('incident_type') as $message): ?>
          <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
        <?php endforeach; ?>
      </div>

      <div class="field<?= $fieldErrors('severity') !== [] ? ' field--invalid' : '' ?>">
        <label class="field__label" for="incident_severity">Severity</label>
        <select class="select" id="incident_severity" name="severity" required>
          <?php foreach ($severityOptions as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $selectedSeverity === (string) $value ? 'selected' : '' ?>>
              <?= e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php foreach ($fieldErrors('severity') as $message): ?>
          <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
        <?php endforeach; ?>
      </div>

      <div class="field<?= $fieldErrors('summary') !== [] ? ' field--invalid' : '' ?>">
        <label class="field__label" for="incident_summary">
          Short summary
          <span class="muted text-xs">Required</span>
        </label>
        <input class="input<?= $fieldErrors('summary') !== [] ? ' input--invalid' : '' ?>"
               id="incident_summary" name="summary" type="text" maxlength="180" required
               value="<?= e($oldValue('summary')) ?>" placeholder="e.g. Unknown person tried the side gate">
        <?php foreach ($fieldErrors('summary') as $message): ?>
          <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
        <?php endforeach; ?>
      </div>

      <div class="field<?= $fieldErrors('occurred_at') !== [] ? ' field--invalid' : '' ?>">
        <label class="field__label" for="incident_occurred_at">
          When did it happen?
          <span class="muted text-xs">Optional</span>
        </label>
        <input class="input<?= $fieldErrors('occurred_at') !== [] ? ' input--invalid' : '' ?>"
               id="incident_occurred_at" name="occurred_at" type="datetime-local"
               value="<?= e($oldValue('occurred_at')) ?>">
        <p class="field__hint">Leave blank to use the current time.</p>
        <?php foreach ($fieldErrors('occurred_at') as $message): ?>
          <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="field<?= $fieldErrors('description') !== [] ? ' field--invalid' : '' ?>">
      <label class="field__label" for="incident_description">What did you see?</label>
      <textarea class="input<?= $fieldErrors('description') !== [] ? ' input--invalid' : '' ?>"
                id="incident_description" name="description" rows="5" maxlength="2000" required
                placeholder="Describe what happened, who was involved and anything that could help security. Do not include PINs or passwords."><?= e($oldValue('description')) ?></textarea>
      <?php foreach ($fieldErrors('description') as $message): ?>
        <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
      <?php endforeach; ?>
    </div>

    <div class="field-grid">
      <?php if ($gateOptions !== []): ?>
        <div class="field<?= $fieldErrors('gate_uuid') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="incident_gate">
            Gate
            <span class="muted text-xs">Optional</span>
          </label>
          <select class="select" id="incident_gate" name="gate_uuid">
            <option value="">Not sure / elsewhere</option>
            <?php foreach ($gateOptions as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('gate_uuid') === (string) $value ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php foreach ($fieldErrors('gate_uuid') as $message): ?>
            <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($householdOptions !== []): ?>
        <div class="field<?= $fieldErrors('household_uuid') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="incident_household">
            Household
            <span class="muted text-xs">Optional</span>
          </label>
          <select class="select" id="incident_household" name="household_uuid">
            <option value="">Not household-specific</option>
            <?php foreach ($householdOptions as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('household_uuid') === (string) $value ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php foreach ($fieldErrors('household_uuid') as $message): ?>
            <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($invitationOptions !== []): ?>
        <div class="field<?= $fieldErrors('invitation_uuid') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="incident_invitation">
            Related visitor
            <span class="muted text-xs">Optional</span>
          </label>
          <select class="select" id="incident_invitation" name="invitation_uuid">
            <option value="">Not visitor-specific</option>
            <?php foreach ($invitationOptions as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $oldValue('invitation_uuid') === (string) $value ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php foreach ($fieldErrors('invitation_uuid') as $message): ?>
            <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn--primary btn--lg btn--block"><?= $icon('alert', 'ico ico--sm') ?> Submit report</button>
      <a class="btn btn--ghost btn--block" href="/app/incidents">Cancel</a>
    </div>
  </form>
</div>
