<?php

declare(strict_types=1);

/**
 * Resident incident detail (read-only, estate-wide; no reporter PII, no
 * internal notes/actions).
 *
 * Variables: $incident {uuid,incident_type,incident_type_label,severity,
 * severity_label,tone,summary,status,status_label,status_tone,occurred_at,
 * gate_name,reported_by,description,status_timeline[]} + shell.
 */

$incident = isset($incident) && is_array($incident) ? $incident : [];
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
        'open', 'investigating' => 'status--pending',
        'resolved' => 'status--active',
        default => 'status--inactive',
    };
};
$summary = trim((string) ($incident['summary'] ?? ''));
$description = trim((string) ($incident['description'] ?? ''));
$status = trim((string) ($incident['status'] ?? ''));
$statusLabel = trim((string) ($incident['status_label'] ?? '')) ?: ucfirst($status);
$statusTone = trim((string) ($incident['status_tone'] ?? ''));
$severityLabel = trim((string) ($incident['severity_label'] ?? ''));
$typeLabel = trim((string) ($incident['incident_type_label'] ?? '')) ?: $humanise(trim((string) ($incident['incident_type'] ?? '')));
$occurredAt = isset($incident['occurred_at']) ? (string) $incident['occurred_at'] : '';
$gateName = trim((string) ($incident['gate_name'] ?? ''));
$reportedBy = trim((string) ($incident['reported_by'] ?? 'Reported by estate team'));
$timeline = isset($incident['status_timeline']) && is_array($incident['status_timeline']) ? $incident['status_timeline'] : [];
?>
<div class="resident">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app/incidents" aria-label="Back to estate incidents" title="Back to estate incidents">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title"><?= e($summary !== '' ? $summary : 'Incident report') ?></h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <?php if ($summary === '' && $description === ''): ?>
    <div class="resident-empty">
      <span class="resident-empty__icon" aria-hidden="true"><?= $icon('alert') ?></span>
      <p class="resident-empty__title">Incident unavailable</p>
      <p class="resident-empty__text">This report may have been removed or is not part of your estate.</p>
      <p><a class="btn btn--outline btn--lg" href="/app/incidents">Back to estate incidents</a></p>
    </div>
  <?php else: ?>
    <article class="card incident-detail">
      <div class="notice-detail__meta">
        <?php if ($severityLabel !== ''): ?><span class="status <?= e($toneClass(trim((string) ($incident['tone'] ?? '')), '')) ?>"><?= e($severityLabel) ?> severity</span><?php endif; ?>
        <?php if ($typeLabel !== ''): ?><span class="chip chip--outline"><?= e($typeLabel) ?></span><?php endif; ?>
        <span class="status <?= e($toneClass($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
      </div>

      <p class="evidence-note">
        <?= $icon('shield', 'ico ico--xs') ?> <?= e($reportedBy) ?> · <?= $icon('clock', 'ico ico--xs') ?>
        <?= e($occurredAt !== '' ? format_datetime($occurredAt, $timezone, 'd M Y, H:i') : 'Time not recorded') ?>
        <?php if ($gateName !== ''): ?> · <?= $icon('gate', 'ico ico--xs') ?> <?= e($gateName) ?><?php endif; ?>
      </p>

      <?php if ($description !== ''): ?>
        <div class="incident-detail__body"><?= nl2br(e($description)) ?></div>
      <?php endif; ?>

      <section class="incident-timeline" aria-labelledby="incident-timeline-title">
        <h2 class="card__title" id="incident-timeline-title">Status timeline</h2>
        <?php if ($timeline !== []): ?>
          <ol class="timeline">
            <?php foreach ($timeline as $entry): ?>
              <?php if (!is_array($entry)) { continue; } ?>
              <li class="timeline__item">
                <span class="timeline__label"><?= e((string) ($entry['label'] ?? 'Update')) ?></span>
                <span class="timeline__meta"><?= e(format_datetime((string) ($entry['at'] ?? ''), $timezone, 'd M Y, H:i')) ?></span>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php else: ?>
          <p class="muted">No updates recorded yet.</p>
        <?php endif; ?>
      </section>

      <div class="notice-detail__foot">
        <a class="btn btn--ghost btn--block" href="/app/incidents"><?= $icon('chevron-left', 'ico ico--sm') ?> All estate incidents</a>
      </div>
    </article>
  <?php endif; ?>
</div>
