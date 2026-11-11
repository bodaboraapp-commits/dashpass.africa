<?php

declare(strict_types=1);

/**
 * Admin incident detail + status workflow (staff-incidents contract §4).
 *
 * Shows the full report (summary, description, when/where/who) and a CSRF
 * status form with resolution notes. Terminal states (resolved/dismissed) are
 * closed: the form is replaced with a clear note and the resolution details.
 *
 * Variables (T38 frozen keys): $incident detail, $status_options [], $errors [],
 * $old [] + admin shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$incident = isset($incident) && is_array($incident) ? $incident : [];
$statusOptions = isset($status_options) && is_array($status_options) ? $status_options : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];

$optionMap = static function (array $items, array $fallback): array {
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $value = trim((string) ($item['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $label = trim((string) ($item['label'] ?? ''));
        $out[$value] = $label !== '' ? $label : admin_humanize($value);
    }

    return $out !== [] ? $out : $fallback;
};
$statusMap = $optionMap($statusOptions, [
    'open' => 'Open',
    'investigating' => 'Investigating',
    'resolved' => 'Resolved',
    'dismissed' => 'Dismissed',
]);

$incidentUuid = trim((string) ($incident['uuid'] ?? ''));
$incidentType = trim((string) ($incident['incident_type'] ?? ''));
$summary = trim((string) ($incident['summary'] ?? ''));
$description = trim((string) ($incident['description'] ?? ''));
$severity = trim((string) ($incident['severity'] ?? ''));
$severityLabel = trim((string) ($incident['severity_label'] ?? '')) ?: admin_humanize($severity);
$severityTone = trim((string) ($incident['tone'] ?? ''));
$status = trim((string) ($incident['status'] ?? 'open'));
$statusLabel = trim((string) ($incident['status_label'] ?? '')) ?: admin_humanize($status);
$statusTone = trim((string) ($incident['status_tone'] ?? ''));
$occurredAt = isset($incident['occurred_at']) ? (string) $incident['occurred_at'] : '';
$gateName = trim((string) ($incident['gate_name'] ?? ''));
$householdLabel = trim((string) ($incident['household_label'] ?? ''));
$reportedBy = trim((string) ($incident['reported_by'] ?? ($incident['reporter_name'] ?? '')));
$resolvedAt = isset($incident['resolved_at']) ? (string) $incident['resolved_at'] : '';
$resolutionNotes = trim((string) ($incident['resolution_notes'] ?? ''));
$isClosed = in_array($status, ['resolved', 'dismissed'], true);

$oldValue = static function (string $field, string $default = '') use ($old): string {
    $candidate = $old[$field] ?? $default;

    return is_scalar($candidate) ? trim((string) $candidate) : $default;
};
$fieldError = static function (string $field) use ($errors): string {
    return admin_field_error($errors, $field);
};
?>
<div class="admin-page__head">
  <div>
    <nav class="admin-breadcrumb" aria-label="Breadcrumb">
      <a href="/admin/incidents">Incidents</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page"><?= e($summary !== '' ? $summary : 'Incident report') ?></span>
    </nav>
    <h1 class="admin-page__title">Incident report</h1>
    <p class="admin-page__lede">
      <?php if ($summary !== ''): ?>
        <?= e($summary) ?>
      <?php else: ?>
        Review the report and update its status.
      <?php endif; ?>
    </p>
  </div>
</div>

<?= admin_summary($errors) ?>

<section class="admin-section" aria-labelledby="incident-overview-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="incident-overview-title">Overview</h2>
    <span class="admin-chip-row">
      <span class="status <?= e(admin_tone_class($severityTone)) ?>"><?= e($severityLabel) ?> severity</span>
      <span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
    </span>
  </div>

  <?php if ($summary === '' && $description === '' && $incidentType === ''): ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('shield') ?></span>
      <p class="admin-empty__title">Incident details unavailable</p>
      <p class="admin-empty__text">This incident could not be loaded. Return to the incident queue and try again.</p>
      <a class="btn btn--outline" href="/admin/incidents">Back to incidents</a>
    </div>
  <?php else: ?>
    <div class="admin-card admin-incident">
      <dl class="admin-detail-grid">
        <div class="admin-detail-grid__item">
          <dt>Type</dt>
          <dd><?= e($incidentType !== '' ? admin_humanize($incidentType) : '—') ?></dd>
        </div>
        <div class="admin-detail-grid__item">
          <dt>Severity</dt>
          <dd><?= e($severityLabel !== '' ? $severityLabel : '—') ?></dd>
        </div>
        <div class="admin-detail-grid__item">
          <dt>Occurred</dt>
          <dd><?= e(admin_datetime($occurredAt, $timezone)) ?></dd>
        </div>
        <div class="admin-detail-grid__item">
          <dt>Gate</dt>
          <dd><?= e($gateName !== '' ? $gateName : '—') ?></dd>
        </div>
        <div class="admin-detail-grid__item">
          <dt>Household</dt>
          <dd><?= e($householdLabel !== '' ? $householdLabel : '—') ?></dd>
        </div>
        <div class="admin-detail-grid__item">
          <dt>Reported by</dt>
          <dd><?= e($reportedBy !== '' ? $reportedBy : '—') ?></dd>
        </div>
        <?php if ($resolvedAt !== ''): ?>
          <div class="admin-detail-grid__item">
            <dt>Closed</dt>
            <dd><?= e(admin_datetime($resolvedAt, $timezone)) ?></dd>
          </div>
        <?php endif; ?>
        <?php if ($incidentUuid !== ''): ?>
          <div class="admin-detail-grid__item">
            <dt>Reference</dt>
            <dd class="mono"><?= e($incidentUuid) ?></dd>
          </div>
        <?php endif; ?>
      </dl>

      <div class="admin-incident__description">
        <h3 class="admin-form__title"><?= admin_icon('list', 'ico ico--sm') ?> Description</h3>
        <p class="admin-incident__text"><?= nl2br(e($description !== '' ? $description : 'No description was provided.')) ?></p>
      </div>

      <?php if ($resolutionNotes !== ''): ?>
        <div class="admin-incident__resolution">
          <h3 class="admin-form__title"><?= admin_icon('check', 'ico ico--sm') ?> Resolution notes</h3>
          <p class="admin-incident__text"><?= nl2br(e($resolutionNotes)) ?></p>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<section class="admin-section" aria-labelledby="incident-status-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="incident-status-title">Status workflow</h2>
      <p class="admin-section__hint">Open → investigating → resolved or dismissed. Resolving requires resolution notes.</p>
    </div>
  </div>

  <?php if ($isClosed): ?>
    <div class="alert alert--success" role="status">
      <?= admin_icon('check') ?>
      <div class="alert__body">
        <p class="alert__title">Incident is closed</p>
        <p><?= e($statusLabel) ?> reports cannot be changed again in this workflow. The history and resolution notes stay available above.</p>
      </div>
    </div>
  <?php elseif ($incidentUuid === ''): ?>
    <p class="admin-field__hint">Status cannot be updated because this incident is unavailable.</p>
  <?php else: ?>
    <form method="post" action="/admin/incidents/<?= e(rawurlencode($incidentUuid)) ?>/status" class="admin-form card">
      <?= csrf_field() ?>

      <div class="admin-form__grid">
        <div class="admin-field<?= $fieldError('status') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="incident_status">New status</label>
          <select class="select<?= $fieldError('status') !== '' ? ' input--invalid' : '' ?>" id="incident_status" name="status" required>
            <?php $selectedStatus = $oldValue('status', $status); ?>
            <?php foreach ($statusMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $selectedStatus === (string) $value ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($fieldError('status') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('status')) ?></p>
          <?php endif; ?>
        </div>

        <div class="admin-field<?= $fieldError('resolution_notes') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="resolution_notes">
            Resolution notes
            <span class="muted text-xs">Required when resolving</span>
          </label>
          <textarea class="input<?= $fieldError('resolution_notes') !== '' ? ' input--invalid' : '' ?>"
                    id="resolution_notes" name="resolution_notes" rows="4" maxlength="500"
                    placeholder="What was found and what action was taken?"><?= e($oldValue('resolution_notes', $resolutionNotes)) ?></textarea>
          <?php if ($fieldError('resolution_notes') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('resolution_notes')) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="admin-form__actions">
        <button type="submit" class="btn btn--primary"><?= admin_icon('check', 'ico ico--sm') ?> Update status</button>
        <a class="btn btn--ghost" href="/admin/incidents">Back to incidents</a>
      </div>
    </form>
  <?php endif; ?>
</section>
