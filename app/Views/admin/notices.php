<?php

declare(strict_types=1);

/**
 * Admin notices queue (estate-notices contract §4, phase 1).
 *
 * Estate-wide notice queue with status/category/priority filters, counts, a
 * "New notice" CTA and links into the compose/detail screens. Draft and archived
 * notices are visible to managers only.
 *
 * Variables (T53 frozen keys): $notices [], $filters {status,category,priority},
 * $status_options [], $category_options [], $priority_options [], $counts +
 * admin shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$notices = isset($notices) && is_array($notices) ? $notices : [];
$filters = isset($filters) && is_array($filters) ? $filters : [];
$counts = isset($counts) && is_array($counts) ? $counts : [];

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

$statusMap = $optionMap($status_options ?? [], [
    'draft' => 'Draft',
    'published' => 'Published',
    'archived' => 'Archived',
]);
$categoryMap = $optionMap($category_options ?? [], [
    'general' => 'General',
    'security' => 'Security',
    'maintenance' => 'Maintenance',
    'event' => 'Event',
    'emergency' => 'Emergency',
]);
$priorityMap = $optionMap($priority_options ?? [], [
    'normal' => 'Normal',
    'important' => 'Important',
    'urgent' => 'Urgent',
]);

$currentStatus = trim((string) ($filters['status'] ?? ''));
$currentCategory = trim((string) ($filters['category'] ?? ''));
$currentPriority = trim((string) ($filters['priority'] ?? ''));
$hasFilters = $currentStatus !== '' || $currentCategory !== '' || $currentPriority !== '';

$rows = array_values(array_filter($notices, 'is_array'));
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Notices</h1>
    <p class="admin-page__lede">
      Compose estate announcements, publish them to residents and guards, and archive notices that no longer apply.
    </p>
  </div>
  <div class="admin-page__actions">
    <a class="btn btn--primary" href="/admin/notices/new"><?= admin_icon('plus', 'ico ico--sm') ?> New notice</a>
  </div>
</div>

<?php if ($counts !== []): ?>
  <div class="admin-stats admin-stats--inline">
    <?php if (isset($counts['published'])): ?>
      <div class="admin-stat"><span class="admin-stat__value"><?= e((string) $counts['published']) ?></span><span class="admin-stat__label">Published</span></div>
    <?php endif; ?>
    <?php if (isset($counts['draft'])): ?>
      <div class="admin-stat"><span class="admin-stat__value"><?= e((string) $counts['draft']) ?></span><span class="admin-stat__label">Drafts</span></div>
    <?php endif; ?>
    <?php if (isset($counts['archived'])): ?>
      <div class="admin-stat"><span class="admin-stat__value"><?= e((string) $counts['archived']) ?></span><span class="admin-stat__label">Archived</span></div>
    <?php endif; ?>
    <?php if (isset($counts['total']) && !isset($counts['published'], $counts['draft'], $counts['archived'])): ?>
      <div class="admin-stat"><span class="admin-stat__value"><?= e((string) $counts['total']) ?></span><span class="admin-stat__label">Total</span></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<section class="admin-section" aria-labelledby="notices-queue-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="notices-queue-title">Notice queue</h2>
    <?php if ($rows !== []): ?>
      <span class="chip"><?= e((string) count($rows)) ?> shown</span>
    <?php endif; ?>
  </div>

  <form method="get" action="/admin/notices" class="admin-form card admin-filter-form">
    <div class="admin-form__grid admin-form__grid--inline">
      <div class="admin-field">
        <label class="admin-field__label" for="filter_status">Status</label>
        <select class="select" id="filter_status" name="status">
          <option value="">All statuses</option>
          <?php foreach ($statusMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentStatus === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="filter_category">Category</label>
        <select class="select" id="filter_category" name="category">
          <option value="">All categories</option>
          <?php foreach ($categoryMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentCategory === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label class="admin-field__label" for="filter_priority">Priority</label>
        <select class="select" id="filter_priority" name="priority">
          <option value="">All priorities</option>
          <?php foreach ($priorityMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentPriority === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-form__actions">
        <button type="submit" class="btn btn--outline btn--sm"><?= admin_icon('refresh', 'ico ico--sm') ?> Apply filters</button>
        <?php if ($hasFilters): ?>
          <a class="btn btn--ghost btn--sm" href="/admin/notices">Clear filters</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($rows !== []): ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Notice</th>
            <th scope="col">Status</th>
            <th scope="col">Priority</th>
            <th scope="col">Category</th>
            <th scope="col">Audience</th>
            <th scope="col">Published</th>
            <th scope="col">Expires</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $notice): ?>
            <?php
            $noticeUuid = trim((string) ($notice['uuid'] ?? ''));
            $title = trim((string) ($notice['title'] ?? 'Estate notice'));
            $imageUrl = trim((string) ($notice['image_url'] ?? ''));
            $imageAlt = trim((string) ($notice['image_alt'] ?? ''));
            $status = trim((string) ($notice['status'] ?? 'draft'));
            $statusLabel = trim((string) ($notice['status_label'] ?? '')) ?: admin_humanize($status);
            $statusTone = trim((string) ($notice['status_tone'] ?? ''));
            $priority = trim((string) ($notice['priority'] ?? ''));
            $priorityLabel = trim((string) ($notice['priority_label'] ?? '')) ?: ($priorityMap[$priority] ?? admin_humanize($priority));
            $priorityTone = trim((string) ($notice['priority_tone'] ?? ''));
            $category = trim((string) ($notice['category'] ?? ''));
            $categoryLabel = trim((string) ($notice['category_label'] ?? '')) ?: ($categoryMap[$category] ?? admin_humanize($category));
            $audience = trim((string) ($notice['audience'] ?? ''));
            $audienceLabel = trim((string) ($notice['audience_label'] ?? '')) ?: admin_humanize($audience);
            $publishedAt = isset($notice['published_at']) ? (string) $notice['published_at'] : '';
            $expiresAt = isset($notice['expires_at']) ? (string) $notice['expires_at'] : '';
            ?>
            <tr>
              <td data-label="Notice">
                <?php if ($imageUrl !== ''): ?>
                  <img class="admin-table__thumb" src="<?= e($imageUrl) ?>" alt="" loading="lazy" decoding="async" aria-hidden="true">
                <?php endif; ?>
                <?php if ($noticeUuid !== ''): ?>
                  <a class="admin-table__link" href="/admin/notices/<?= e(rawurlencode($noticeUuid)) ?>"><?= e($title) ?></a>
                <?php else: ?>
                  <?= e($title) ?>
                <?php endif; ?>
                <?php if ($priorityLabel !== ''): ?>
                  <span class="admin-table__sub"><?= e($priorityLabel) ?> priority</span>
                <?php endif; ?>
              </td>
              <td data-label="Status"><span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span></td>
              <td data-label="Priority"><?= e($priorityLabel !== '' ? $priorityLabel : '-') ?></td>
              <td data-label="Category"><?= e($categoryLabel !== '' ? $categoryLabel : '-') ?></td>
              <td data-label="Audience"><?= e($audienceLabel !== '' ? $audienceLabel : '-') ?></td>
              <td data-label="Published"><?= e($publishedAt !== '' ? admin_datetime($publishedAt, $timezone, 'd M Y') : '-') ?></td>
              <td data-label="Expires"><?= e($expiresAt !== '' ? admin_datetime($expiresAt, $timezone, 'd M Y') : '-') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('bell') ?></span>
      <p class="admin-empty__title"><?= $hasFilters ? 'No notices match these filters' : 'No notices yet' ?></p>
      <p class="admin-empty__text">
        <?php if ($hasFilters): ?>
          Try clearing the filters to see every notice for this estate.
        <?php else: ?>
          Create the first estate notice to keep residents and guards informed.
        <?php endif; ?>
      </p>
      <?php if ($hasFilters): ?>
        <a class="btn btn--outline" href="/admin/notices">Clear filters</a>
      <?php else: ?>
        <a class="btn btn--primary" href="/admin/notices/new"><?= admin_icon('plus', 'ico ico--sm') ?> New notice</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
