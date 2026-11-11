<?php

declare(strict_types=1);

/**
 * Resident incident list (staff-incidents contract §4).
 *
 * Shows only the incidents the signed-in resident reported: type, severity and
 * status chips, summary, when/where, and the responsible reporter label. The
 * report CTA links to the incident form.
 *
 * Variables (T38 frozen keys): $incidents [{uuid,incident_type,severity,
 * severity_label,tone,summary,status,status_label,status_tone,occurred_at,
 * gate_name,household_label,reported_by}], $filters, $errors, $old + shell.
 */

$incidents = isset($incidents) && is_array($incidents) ? $incidents : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];

$timezone = trim((string) ($estate['estate_timezone'] ?? $estate['timezone'] ?? (string) config('app.timezone', 'Africa/Nairobi')));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$humanise = static function (string $value): string {
    return $value === '' ? '' : ucwords(str_replace(['_', '-'], ' ', $value));
};

$toneClass = static function (string $tone, string $status = ''): string {
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
        'open' => 'status--pending',
        'investigating' => 'status--pending',
        'resolved' => 'status--active',
        'dismissed' => 'status--inactive',
        default => 'status--inactive',
    };
};

$incidentRows = array_values(array_filter($incidents, 'is_array'));
?>
<div class="resident">
  <header class="screen-bar screen-bar--root">
    <h1 class="screen-bar__title">My incidents</h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
    <a class="screen-bar__action" href="/app/incidents/new" aria-label="Report an incident" title="Report an incident">
      <?= $icon('plus', 'ico') ?>
    </a>
  </header>

  <p class="page-lede">
    Security or access incidents you have reported at the estate. The security team reviews each report and updates
    its status.
  </p>

  <section class="resident-section" aria-labelledby="incidents-list-title">
    <div class="resident-section__head">
      <div>
        <h2 class="resident-section__title" id="incidents-list-title">Your reports</h2>
        <p class="resident-section__hint">Only reports you filed are listed here.</p>
      </div>
      <?php if ($incidentRows !== []): ?>
        <span class="chip"><?= e((string) count($incidentRows)) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($incidentRows !== []): ?>
      <ul class="incident-list">
        <?php foreach ($incidentRows as $incident): ?>
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
          <li class="incident-card">
            <div class="incident-card__head">
              <span class="incident-card__icon" aria-hidden="true"><?= $icon('alert') ?></span>
              <div class="incident-card__body">
                <p class="incident-card__title"><?= e($summary !== '' ? $summary : 'Incident report') ?></p>
                <p class="incident-card__meta">
                  <?= e($severityLabel !== '' ? $severityLabel . ' severity' : 'Incident') ?>
                  <?php if ($incidentType !== ''): ?>
                    · <?= e($humanise($incidentType)) ?>
                  <?php endif; ?>
                </p>
              </div>
              <div class="incident-card__side">
                <span class="status <?= e($toneClass($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
              </div>
            </div>

            <p class="incident-card__meta incident-card__meta--details">
              <?php if ($occurredAt !== ''): ?>
                <span><?= $icon('clock', 'ico ico--xs') ?> <?= e(format_datetime($occurredAt, $timezone)) ?></span>
              <?php endif; ?>
              <?php if ($gateName !== ''): ?>
                <span><?= $icon('gate', 'ico ico--xs') ?> <?= e($gateName) ?></span>
              <?php endif; ?>
              <?php if ($householdLabel !== ''): ?>
                <span><?= $icon('home', 'ico ico--xs') ?> <?= e($householdLabel) ?></span>
              <?php endif; ?>
              <?php if ($reportedBy !== ''): ?>
                <span><?= $icon('account', 'ico ico--xs') ?> Reported by <?= e($reportedBy) ?></span>
              <?php endif; ?>
            </p>

            <?php if ($severityLabel !== ''): ?>
              <p class="incident-card__severity">
                <span class="chip chip--severity">Severity: <?= e($severityLabel) ?></span>
                <?php if ($severityTone !== ''): ?>
                  <span class="muted text-xs"><?= e($humanise($severityTone)) ?></span>
                <?php endif; ?>
              </p>
            <?php endif; ?>

            <?php if ($incidentUuid !== ''): ?>
              <p class="incident-card__ref">Reference: <span class="mono"><?= e($incidentUuid) ?></span></p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="resident-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('shield') ?></span>
        <p class="resident-empty__title">No incidents reported</p>
        <p class="resident-empty__text">Use “Report an incident” to tell the security team about anything suspicious or broken.</p>
      </div>
    <?php endif; ?>
  </section>

  <div class="cta-dock">
    <a class="btn btn--primary btn--lg btn--block" href="/app/incidents/new"><?= $icon('plus', 'ico ico--sm') ?> Report an incident</a>
  </div>
</div>
