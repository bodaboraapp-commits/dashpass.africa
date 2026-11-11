<?php

declare(strict_types=1);

/**
 * Resident estate notices list (estate-notices contract §4, phase 1).
 *
 * Shows published, unexpired notices for the signed-in resident's estate with
 * priority/category chips, published date and a short excerpt. A detail link
 * opens the full notice. All data is defensive so partial integration renders an
 * honest empty state instead of failing.
 *
 * Variables (T53 frozen keys): $notices [{uuid,title,body_excerpt,category,
 * category_label,priority,priority_label,priority_tone,published_at}],
 * $filters, $errors, $old + shell data.
 */

$notices = isset($notices) && is_array($notices) ? $notices : [];
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
<div class="resident">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">Notices</h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <p class="page-lede">
    Estate announcements from your management team, newest and most important first.
  </p>

  <section class="resident-section" aria-labelledby="notices-list-title">
    <div class="resident-section__head">
      <div>
        <h2 class="resident-section__title" id="notices-list-title">Published notices</h2>
        <p class="resident-section__hint">Only notices shared with your estate appear here.</p>
      </div>
      <?php if ($rows !== []): ?>
        <span class="chip"><?= e((string) count($rows)) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($rows !== []): ?>
      <ul class="notice-list">
        <?php foreach ($rows as $notice): ?>
          <?php
          $noticeUuid = trim((string) ($notice['uuid'] ?? ''));
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
          <li class="notice-card">
            <?php if ($imageUrl !== ''): ?>
              <a class="notice-card__thumb" href="/app/notices/<?= e(rawurlencode($noticeUuid)) ?>" tabindex="-1" aria-hidden="true">
                <img src="<?= e($imageUrl) ?>" alt="" loading="lazy" decoding="async">
              </a>
            <?php endif; ?>
            <div class="notice-card__head">
              <span class="notice-card__icon" aria-hidden="true"><?= $icon('bell') ?></span>
              <div class="notice-card__body">
                <h3 class="notice-card__title">
                  <?php if ($noticeUuid !== ''): ?>
                    <a class="notice-card__link" href="/app/notices/<?= e(rawurlencode($noticeUuid)) ?>"><?= e($title) ?></a>
                  <?php else: ?>
                    <?= e($title) ?>
                  <?php endif; ?>
                </h3>
                <p class="notice-card__meta">
                  <?php if ($priorityLabel !== ''): ?>
                    <span class="status <?= e($priorityClass($priorityTone, $priority)) ?>"><?= e($priorityLabel) ?></span>
                  <?php endif; ?>
                  <?php if ($categoryLabel !== ''): ?>
                    <span class="chip chip--outline"><?= e($categoryLabel) ?></span>
                  <?php endif; ?>
                  <?php if ($publishedAt !== ''): ?>
                    <span class="notice-card__date"><?= $icon('clock', 'ico ico--xs') ?> <?= e(format_datetime($publishedAt, $timezone, 'd M Y')) ?></span>
                  <?php endif; ?>
                </p>
              </div>
            </div>
            <?php if ($excerpt !== ''): ?>
              <p class="notice-card__excerpt"><?= e($excerpt) ?></p>
            <?php endif; ?>
            <?php if ($noticeUuid !== ''): ?>
              <a class="notice-card__more" href="/app/notices/<?= e(rawurlencode($noticeUuid)) ?>">
                Read notice <?= $icon('chevron', 'ico ico--xs') ?>
              </a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="resident-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('bell') ?></span>
        <p class="resident-empty__title">No notices right now</p>
        <p class="resident-empty__text">When your estate team shares an update it will appear here.</p>
      </div>
    <?php endif; ?>
  </section>
</div>
