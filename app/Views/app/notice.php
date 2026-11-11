<?php

declare(strict_types=1);

/**
 * Resident estate notice detail (estate-notices contract §4, phase 1).
 *
 * Full notice body with priority/category/audience meta and published/expiry
 * dates. Unpublished or foreign notices never reach this view (404 upstream);
 * absent data still renders an honest unavailable state.
 *
 * Variables (T53 frozen keys): $notice {uuid,title,body,category,category_label,
 * priority,priority_label,priority_tone,published_at,expires_at,audience,
 * audience_label} + shell data.
 */

$notice = isset($notice) && is_array($notice) ? $notice : [];
$timezone = trim((string) ($estate['estate_timezone'] ?? $estate['timezone'] ?? (string) config('app.timezone', 'Africa/Nairobi')));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$humanise = static function (string $value): string {
    return $value === '' ? '' : ucwords(str_replace(['_', '-'], ' ', $value));
};

$title = trim((string) ($notice['title'] ?? ''));
$body = trim((string) ($notice['body'] ?? ''));
$category = trim((string) ($notice['category'] ?? ''));
$categoryLabel = trim((string) ($notice['category_label'] ?? '')) ?: $humanise($category);
$priority = trim((string) ($notice['priority'] ?? ''));
$priorityLabel = trim((string) ($notice['priority_label'] ?? '')) ?: $humanise($priority);
$priorityTone = trim((string) ($notice['priority_tone'] ?? ''));
$audience = trim((string) ($notice['audience'] ?? ''));
$audienceLabel = trim((string) ($notice['audience_label'] ?? '')) ?: $humanise($audience);
$publishedAt = isset($notice['published_at']) ? (string) $notice['published_at'] : '';
$expiresAt = isset($notice['expires_at']) ? (string) $notice['expires_at'] : '';

$priorityClass = static function (string $tone, string $priority): string {
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

    return match ($priority) {
        'urgent' => 'status--danger',
        'important' => 'status--pending',
        default => 'status--inactive',
    };
};
?>
<div class="resident">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app/notices" aria-label="Back to notices" title="Back to notices">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title"><?= e($title !== '' ? $title : 'Estate notice') ?></h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <?php if ($title === '' && $body === ''): ?>
    <div class="resident-empty">
      <span class="resident-empty__icon" aria-hidden="true"><?= $icon('bell') ?></span>
      <p class="resident-empty__title">Notice unavailable</p>
      <p class="resident-empty__text">This notice may have been archived or is not shared with your household.</p>
      <p><a class="btn btn--outline btn--lg" href="/app/notices">Back to notices</a></p>
    </div>
  <?php else: ?>
    <article class="card notice-detail">
      <div class="notice-detail__meta">
        <?php if ($priorityLabel !== ''): ?>
          <span class="status <?= e($priorityClass($priorityTone, $priority)) ?>"><?= e($priorityLabel) ?></span>
        <?php endif; ?>
        <?php if ($categoryLabel !== ''): ?>
          <span class="chip chip--outline"><?= e($categoryLabel) ?></span>
        <?php endif; ?>
        <?php if ($audienceLabel !== ''): ?>
          <span class="chip">For <?= e($audienceLabel) ?></span>
        <?php endif; ?>
      </div>

      <p class="notice-detail__dates">
        <?php if ($publishedAt !== ''): ?>
          <span><?= $icon('clock', 'ico ico--xs') ?> Published <?= e(format_datetime($publishedAt, $timezone, 'd M Y, H:i')) ?></span>
        <?php endif; ?>
        <?php if ($expiresAt !== ''): ?>
          <span><?= $icon('calendar', 'ico ico--xs') ?> Expires <?= e(format_datetime($expiresAt, $timezone, 'd M Y, H:i')) ?></span>
        <?php endif; ?>
      </p>

      <div class="notice-detail__body">
        <?= nl2br(e($body)) ?>
      </div>

      <div class="notice-detail__foot">
        <a class="btn btn--ghost btn--block" href="/app/notices"><?= $icon('chevron-left', 'ico ico--sm') ?> All notices</a>
      </div>
    </article>
  <?php endif; ?>
</div>
