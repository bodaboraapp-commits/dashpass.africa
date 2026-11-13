<?php

declare(strict_types=1);

/**
 * Resident Activity screen, own-household visitor timeline.
 *
 * Server-rendered list merging gate access events (checked in/out, denied,
 * manual override) with invitation issue events for the signed-in resident's
 * households, newest first. Reuses the resident design-system classes; there is
 * no inline script/style and every dynamic value is escaped with e().
 *
 * Variables: $activity (normalised timeline items from
 * App\Modules\Residents\ResidentActivityService) plus shell data.
 */

$activity = isset($activity) && is_array($activity) ? $activity : [];
$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = trim((string) ($estate['estate_timezone'] ?? (string) config('app.timezone', 'Africa/Nairobi')));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$initialsFor = static function (string $name): string {
    $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: [], static fn (string $part): bool => $part !== ''));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }

    return $initials !== '' ? $initials : 'V';
};

$kindMeta = static function (string $kind): array {
    return match ($kind) {
        'check_in' => ['label' => 'Checked in', 'tone' => 'ok', 'icon' => 'check'],
        'check_out' => ['label' => 'Checked out', 'tone' => 'neutral', 'icon' => 'logout'],
        'denied' => ['label' => 'Denied', 'tone' => 'danger', 'icon' => 'alert'],
        'manual_override' => ['label' => 'Manual override', 'tone' => 'warn', 'icon' => 'shield'],
        'invitation' => ['label' => 'Invitation issued', 'tone' => 'info', 'icon' => 'ticket'],
        default => ['label' => 'Activity', 'tone' => 'neutral', 'icon' => 'activity'],
    };
};

$statusClass = static function (string $tone): string {
    return match ($tone) {
        'ok' => 'status--active',
        'warn' => 'status--pending',
        'danger' => 'status--danger',
        default => 'status--inactive',
    };
};

$rows = array_values(array_filter($activity, 'is_array'));
?>
<div class="resident">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">Activity</h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <p class="page-lede">Visitor passes and gate activity for your household, newest first.</p>

  <section class="resident-section" aria-labelledby="activity-list-title">
    <div class="resident-section__head">
      <div>
        <h2 class="resident-section__title" id="activity-list-title">Visitor timeline</h2>
        <p class="resident-section__hint">Only activity for your own household appears here.</p>
      </div>
      <?php if ($rows !== []): ?>
        <span class="chip"><?= e((string) count($rows)) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($rows !== []): ?>
      <ul class="visitor-list" data-activity-list>
        <?php foreach ($rows as $row): ?>
          <?php
          $kind = trim((string) ($row['kind'] ?? ''));
          $meta = $kindMeta($kind);
          $at = trim((string) ($row['at'] ?? ''));
          $visitorName = trim((string) ($row['visitor_name'] ?? ''));
          if ($visitorName === '') {
              $visitorName = 'Visitor';
          }
          $householdLabel = trim((string) ($row['household_label'] ?? ''));
          $gateName = trim((string) ($row['gate_name'] ?? ''));
          $href = trim((string) ($row['href'] ?? ''));
          ?>
          <li class="visitor-card visitor-card--rich visitor-card--activity"
              data-activity-kind="<?= e($kind) ?>"
              data-activity-at="<?= e($at) ?>">
            <span class="visitor-card__avatar" aria-hidden="true"><?= e($initialsFor($visitorName)) ?></span>

            <div class="visitor-card__body">
              <span class="visitor-card__name"><?= e($visitorName) ?></span>
              <span class="visitor-card__purpose"><?= e($meta['label']) ?></span>
              <?php if ($householdLabel !== ''): ?>
                <span class="visitor-card__meta"><?= $icon('home', 'ico ico--sm') ?> <?= e($householdLabel) ?></span>
              <?php endif; ?>
            </div>

            <span class="status <?= e($statusClass($meta['tone'])) ?>"><?= e($meta['label']) ?></span>

            <p class="visitor-card__when">
              <?= $icon('clock', 'ico ico--sm') ?>
              <?php if ($at !== ''): ?>
                <span><?= e(format_datetime($at, $timezone, 'd M Y, H:i')) ?></span>
              <?php else: ?>
                <span>Time not recorded</span>
              <?php endif; ?>
              <?php if ($gateName !== ''): ?>
                <span class="visitor-card__meta"><?= $icon('gate', 'ico ico--sm') ?> <?= e($gateName) ?></span>
              <?php endif; ?>
            </p>

            <?php if ($href !== ''): ?>
              <div class="visitor-card__actions">
                <a class="btn btn--outline btn--sm" href="<?= e($href) ?>">
                  <?= $icon('ticket', 'ico ico--sm') ?>
                  View pass
                </a>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="resident-empty" data-activity-empty>
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('activity') ?></span>
        <p class="resident-empty__title">No activity yet</p>
        <p class="resident-empty__text">When a visitor is invited or checks in at the gate, it will appear here.</p>
        <a class="btn btn--primary btn--sm" href="/app/visitors/invite">+ Invite Visitor</a>
      </div>
    <?php endif; ?>
  </section>
</div>
