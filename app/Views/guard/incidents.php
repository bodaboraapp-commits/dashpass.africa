<?php

declare(strict_types=1);

/**
 * Guard incident list (staff-incidents contract §4).
 *
 * Shows the incident reports this guard filed while on duty, with severity and
 * status chips and a CTA to report a new incident at the current gate.
 *
 * Variables (T38 frozen keys): $incidents [{uuid,incident_type,severity,
 * severity_label,tone,summary,status,status_label,status_tone,occurred_at,
 * gate_name,household_label,reported_by}] + guard shell data.
 */

$incidents = isset($incidents) && is_array($incidents) ? $incidents : [];
$selectedGate = isset($selected_gate) && is_array($selected_gate) ? $selected_gate : null;
$gateName = trim((string) ($gate ?? ($selectedGate['name'] ?? '')));
$timezone = trim((string) ($estate['estate_timezone'] ?? $estate['timezone'] ?? (string) config('app.timezone', 'Africa/Nairobi')));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$humanise = static function (string $value): string {
    return $value === '' ? '' : ucwords(str_replace(['_', '-'], ' ', $value));
};

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
        'open' => 'g-chip--warn',
        'investigating' => 'g-chip--warn',
        'resolved' => 'g-chip--ok',
        'dismissed' => 'g-chip--neutral',
        default => 'g-chip--neutral',
    };
};

$rows = array_values(array_filter($incidents, 'is_array'));
?>
<div class="g-screen">
  <header class="g-head">
    <div>
      <p class="g-head__eyebrow"><?= $gateName !== '' ? e($gateName) : 'No gate selected' ?></p>
      <h1 class="g-head__title">Incident reports</h1>
    </div>
    <a class="g-head__link" href="/gate/incidents/new"><?= $icon('alert', 'ico ico--sm') ?> Report</a>
  </header>

  <div class="g-toolbar">
    <span class="g-count"><strong><?= e((string) count($rows)) ?></strong> <?= count($rows) === 1 ? 'report filed' : 'reports filed' ?></span>
    <a class="btn btn--ghost btn--sm" href="/gate/incidents"><?= $icon('refresh', 'ico ico--sm') ?> Refresh</a>
  </div>

  <?php if ($rows !== []): ?>
    <ul class="g-list g-list--spaced">
      <?php foreach ($rows as $incident): ?>
        <?php
        $severityLabel = trim((string) ($incident['severity_label'] ?? '')) ?: ucfirst(trim((string) ($incident['severity'] ?? '')));
        $severityTone = trim((string) ($incident['tone'] ?? ''));
        $status = trim((string) ($incident['status'] ?? ''));
        $statusLabel = trim((string) ($incident['status_label'] ?? '')) ?: ucfirst($status);
        $statusTone = trim((string) ($incident['status_tone'] ?? ''));
        $summary = trim((string) ($incident['summary'] ?? ''));
        $incidentType = trim((string) ($incident['incident_type'] ?? ''));
        $occurredAt = isset($incident['occurred_at']) ? (string) $incident['occurred_at'] : '';
        $rowGate = trim((string) ($incident['gate_name'] ?? ''));
        $householdLabel = trim((string) ($incident['household_label'] ?? ''));
        $reportedBy = trim((string) ($incident['reported_by'] ?? ''));
        ?>
        <li class="g-incident">
          <div class="g-incident__head">
            <div class="g-incident__body">
              <p class="g-incident__title"><?= e($summary !== '' ? $summary : 'Incident report') ?></p>
              <p class="g-incident__meta">
                <?php if ($incidentType !== ''): ?>
                  <?= e($humanise($incidentType)) ?>
                <?php endif; ?>
                <?php if ($severityLabel !== ''): ?>
                  <span class="g-chip <?= e($chipClass($severityTone)) ?>"><?= e($severityLabel) ?> severity</span>
                <?php endif; ?>
                <span class="g-chip <?= e($chipClass($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
              </p>
            </div>
          </div>

          <p class="g-incident__meta g-incident__meta--details">
            <?php if ($occurredAt !== ''): ?>
              <span><?= $icon('clock', 'ico ico--xs') ?> <?= e(format_datetime($occurredAt, $timezone)) ?></span>
            <?php endif; ?>
            <?php if ($rowGate !== ''): ?>
              <span><?= $icon('gate', 'ico ico--xs') ?> <?= e($rowGate) ?></span>
            <?php endif; ?>
            <?php if ($householdLabel !== ''): ?>
              <span><?= $icon('home', 'ico ico--xs') ?> <?= e($householdLabel) ?></span>
            <?php endif; ?>
            <?php if ($reportedBy !== ''): ?>
              <span><?= $icon('account', 'ico ico--xs') ?> <?= e($reportedBy) ?></span>
            <?php endif; ?>
          </p>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="g-muted g-note">
      These are the reports you filed while on duty. The estate security lead reviews and updates each status.
    </p>
  <?php else: ?>
    <div class="g-empty">
      <span class="g-empty__icon" aria-hidden="true"><?= $icon('shield') ?></span>
      <p class="g-empty__title">No incidents reported yet</p>
      <p class="g-empty__text">Record a security or access incident at your gate so the estate team can review it.</p>
      <p><a class="btn btn--primary btn--lg" href="/gate/incidents/new">Report an incident</a></p>
    </div>
  <?php endif; ?>
</div>
