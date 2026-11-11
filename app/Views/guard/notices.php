<?php

declare(strict_types=1);

/**
 * Guard estate notices list (estate-notices contract §4, phase 1).
 *
 * Read-only list of published notices shared with guards (audience all/staff),
 * with priority/category chips and published date. No compose/publish actions.
 *
 * Variables (T53 frozen keys): $notices [{uuid,title,body_excerpt,category,
 * category_label,priority,priority_label,priority_tone,published_at}] +
 * guard shell data.
 */

$notices = isset($notices) && is_array($notices) ? $notices : [];
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

$priorityClass = static function (string $tone, string $priority = ''): string {
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

$rows = array_values(array_filter($notices, 'is_array'));
?>
<div class="g-screen">
  <header class="g-head">
    <div>
      <p class="g-head__eyebrow"><?= $gateName !== '' ? e($gateName) : 'Estate notices' ?></p>
      <h1 class="g-head__title">Notices</h1>
    </div>
    <a class="g-head__link" href="/gate"><?= $icon('home', 'ico ico--sm') ?> Dashboard</a>
  </header>

  <div class="g-toolbar">
    <span class="g-count"><strong><?= e((string) count($rows)) ?></strong> <?= count($rows) === 1 ? 'notice' : 'notices' ?></span>
    <a class="btn btn--ghost btn--sm" href="/gate/notices"><?= $icon('refresh', 'ico ico--sm') ?> Refresh</a>
  </div>

  <?php if ($rows !== []): ?>
    <ul class="g-list g-list--spaced">
      <?php foreach ($rows as $notice): ?>
        <?php
        $title = trim((string) ($notice['title'] ?? 'Estate notice'));
        $imageUrl = trim((string) ($notice['image_url'] ?? ''));
        $imageAlt = trim((string) ($notice['image_alt'] ?? ''));
        $excerpt = trim((string) ($notice['body_excerpt'] ?? ''));
        if ($excerpt === '') {
            $excerpt = trim((string) ($notice['excerpt'] ?? ''));
        }
        $category = trim((string) ($notice['category'] ?? ''));
        $categoryLabel = trim((string) ($notice['category_label'] ?? '')) ?: $humanise($category);
        $priority = trim((string) ($notice['priority'] ?? ''));
        $priorityLabel = trim((string) ($notice['priority_label'] ?? '')) ?: $humanise($priority);
        $priorityTone = trim((string) ($notice['priority_tone'] ?? ''));
        $publishedAt = isset($notice['published_at']) ? (string) $notice['published_at'] : '';
        ?>
        <li class="g-notice">
          <?php if ($imageUrl !== ''): ?>
            <div class="g-notice__thumb" aria-hidden="true">
              <img src="<?= e($imageUrl) ?>" alt="" loading="lazy" decoding="async">
            </div>
          <?php endif; ?>
          <div class="g-notice__head">
            <span class="g-notice__icon" aria-hidden="true"><?= $icon('bell') ?></span>
            <div class="g-notice__body">
              <p class="g-notice__title"><?= e($title) ?></p>
              <p class="g-notice__meta">
                <?php if ($priorityLabel !== ''): ?>
                  <span class="status <?= e($priorityClass($priorityTone, $priority)) ?>"><?= e($priorityLabel) ?></span>
                <?php endif; ?>
                <?php if ($categoryLabel !== ''): ?>
                  <span class="g-chip g-chip--neutral"><?= e($categoryLabel) ?></span>
                <?php endif; ?>
              </p>
            </div>
          </div>
          <?php if ($excerpt !== ''): ?>
            <p class="g-notice__excerpt"><?= e($excerpt) ?></p>
          <?php endif; ?>
          <?php if ($publishedAt !== ''): ?>
            <p class="g-notice__meta g-notice__meta--date">
              <?= $icon('clock', 'ico ico--xs') ?> <?= e(format_datetime($publishedAt, $timezone, 'd M Y, H:i')) ?>
            </p>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="g-muted g-note">Estate notices are read-only at the gate. Contact your supervisor if a notice needs correcting.</p>
  <?php else: ?>
    <div class="g-empty">
      <span class="g-empty__icon" aria-hidden="true"><?= $icon('bell') ?></span>
      <p class="g-empty__title">No notices right now</p>
      <p class="g-empty__text">Estate announcements shared with gate staff will appear here.</p>
      <p><a class="btn btn--primary btn--lg" href="/gate">Back to dashboard</a></p>
    </div>
  <?php endif; ?>
</div>
