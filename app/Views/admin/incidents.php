<?php

declare(strict_types=1);

/**
 * Admin incident queue (staff-incidents contract §4).
 *
 * Estate-wide queue with status/severity filters, severity and status chips and
 * links into the incident detail/status screen.
 *
 * Variables (T38 frozen keys): $incidents [{uuid,incident_type,severity,
 * severity_label,tone,summary,status,status_label,status_tone,occurred_at,
 * gate_name,household_label,reported_by}], $filters {status,severity},
 * $status_options, $severity_options + admin shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$incidents = isset($incidents) && is_array($incidents) ? $incidents : [];
$filters = isset($filters) && is_array($filters) ? $filters : [];
$statusOptions = isset($status_options) && is_array($status_options) ? $status_options : [];
$severityOptions = isset($severity_options) && is_array($severity_options) ? $severity_options : [];

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
$severityMap = $optionMap($severityOptions, [
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
    'critical' => 'Critical',
]);
$currentStatus = trim((string) ($filters['status'] ?? ''));
$currentSeverity = trim((string) ($filters['severity'] ?? ''));
$hasFilters = $currentStatus !== '' || $currentSeverity !== '';

$rows = array_values(array_filter($incidents, 'is_array'));
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Incidents</h1>
    <p class="admin-page__lede">
      Estate security and access incidents reported by residents and guards. Open an incident to review it and update
      its status.
    </p>
  </div>
</div>

<section class="admin-section" aria-labelledby="incident-queue-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="incident-queue-title">Incident queue</h2>
    <?php if ($rows !== []): ?>
      <span class="chip"><?= e((string) count($rows)) ?> shown</span>
    <?php endif; ?>
  </div>

  <form method="get" action="/admin/incidents" class="admin-form card admin-filter-form">
    <div class="admin-form__grid admin-form__grid--inline">
      <div class="admin-field">
        <label class="admin-field__label" for="filter_status">Status</label>
        <select class="select" id="filter_status" name="status">
          <option value="">All statuses</option>
          <?php foreach ($statusMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentStatus === (string) $value ? 'selected' : '' ?>>
              <?= e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="filter_severity">Severity</label>
        <select class="select" id="filter_severity" name="severity">
          <option value="">All severities</option>
          <?php foreach ($severityMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentSeverity === (string) $value ? 'selected' : '' ?>>
              <?= e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-form__actions">
        <button type="submit" class="btn btn--outline btn--sm"><?= admin_icon('refresh', 'ico ico--sm') ?> Apply filters</button>
        <?php if ($hasFilters): ?>
          <a class="btn btn--ghost btn--sm" href="/admin/incidents">Clear filters</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($rows !== []): ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Status</th>
            <th scope="col">Severity</th>
            <th scope="col">Incident</th>
            <th scope="col">Reported</th>
            <th scope="col">Gate</th>
            <th scope="col">Household</th>
            <th scope="col">Reporter</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $incident): ?>
            <?php
            $incidentUuid = trim((string) ($incident['uuid'] ?? ''));
            $incidentType = trim((string) ($incident['incident_type'] ?? ''));
            $severity = trim((string) ($incident['severity'] ?? ''));
            $severityLabel = trim((string) ($incident['severity_label'] ?? '')) ?: ucfirst($severity);
            $severityTone = trim((string) ($incident['tone'] ?? ''));
            $summary = trim((string) ($incident['summary'] ?? ''));
            $status = trim((string) ($incident['status'] ?? ''));
            $statusLabel = trim((string) ($incident['status_label'] ?? '')) ?: ucfirst($status);
            $statusTone = trim((string) ($incident['status_tone'] ?? ''));
            $occurredAt = isset($incident['occurred_at']) ? (string) $incident['occurred_at'] : '';
            $gateName = trim((string) ($incident['gate_name'] ?? ''));
            $householdLabel = trim((string) ($incident['household_label'] ?? ''));
            $reportedBy = trim((string) ($incident['reported_by'] ?? ''));
            ?>
            <tr>
              <td data-label="Status"><span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span></td>
              <td data-label="Severity"><span class="status <?= e(admin_tone_class($severityTone)) ?>"><?= e($severityLabel) ?></span></td>
              <td data-label="Incident">
                <?php if ($incidentUuid !== ''): ?>
                  <a class="admin-table__link" href="/admin/incidents/<?= e(rawurlencode($incidentUuid)) ?>">
                    <?= e($summary !== '' ? $summary : 'Incident report') ?>
                  </a>
                <?php else: ?>
                  <?= e($summary !== '' ? $summary : 'Incident report') ?>
                <?php endif; ?>
                <?php if ($incidentType !== ''): ?>
                  <span class="admin-table__sub"><?= e(admin_humanize($incidentType)) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Reported"><?= e(admin_datetime($occurredAt, $timezone)) ?></td>
              <td data-label="Gate"><?= e($gateName !== '' ? $gateName : '-') ?></td>
              <td data-label="Household"><?= e($householdLabel !== '' ? $householdLabel : '-') ?></td>
              <td data-label="Reporter"><?= e($reportedBy !== '' ? $reportedBy : '-') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('shield') ?></span>
      <p class="admin-empty__title"><?= $hasFilters ? 'No incidents match these filters' : 'No incidents reported yet' ?></p>
      <p class="admin-empty__text">
        <?php if ($hasFilters): ?>
          Try clearing the filters to see the full estate incident queue.
        <?php else: ?>
          Resident and guard incident reports will appear here for triage.
        <?php endif; ?>
      </p>
    </div>
  <?php endif; ?>
</section>
