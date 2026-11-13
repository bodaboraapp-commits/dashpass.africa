<?php

declare(strict_types=1);

/**
 * Resident Activity screen, own-household visitor timeline.
 *
 * Server-rendered list merging gate access events (checked in/out, denied,
 * manual override) with invitation issue events for the signed-in resident's
 * households, newest first. The timeline itself lives in
 * `app/partials/activity-timeline.php` so the Visitors screen can show it as its
 * Activity tab (owner request 2026-11-13: Activity moved out of the bottom nav).
 *
 * Variables: $activity (normalised timeline items from
 * App\Modules\Residents\ResidentActivityService) plus shell data.
 */

$activity = isset($activity) && is_array($activity) ? $activity : [];
$estate = isset($estate) && is_array($estate) ? $estate : [];

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
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

  <?= \App\Core\View::capture('app/partials/activity-timeline', ['activity' => $activity, 'estate' => $estate]) ?>
</div>
