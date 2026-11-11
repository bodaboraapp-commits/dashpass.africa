<?php

declare(strict_types=1);

/**
 * Admin notice detail + lifecycle actions (estate-notices contract §4, phase 1).
 *
 * Shows the full notice, its meta, a draft→published→archived timeline and the
 * publish/archive actions. Published notices are immutable apart from archive;
 * archived notices are terminal. CSRF-protected POST actions, escaped body.
 *
 * Variables (T53 frozen keys): $notice detail {$uuid,title,body,category,
 * category_label,priority,priority_label,priority_tone,status,status_label,
 * status_tone,audience,audience_label,published_at,expires_at,created_at,
 * updated_at} + errors [] + admin shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$notice = isset($notice) && is_array($notice) ? $notice : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$categoryOptions = isset($category_options) && is_array($category_options) ? $category_options : [];
$priorityOptions = isset($priority_options) && is_array($priority_options) ? $priority_options : [];
$audienceOptions = isset($audience_options) && is_array($audience_options) ? $audience_options : [];
$timezone = isset($estate_timezone) && is_string($estate_timezone) && trim($estate_timezone) !== ''
    ? $estate_timezone
    : (string) config('app.timezone', 'Africa/Nairobi');

$noticeUuid = trim((string) ($notice['uuid'] ?? ''));
$noticeImageUrl = trim((string) ($notice['image_url'] ?? ''));
$noticeImageAlt = trim((string) ($notice['image_alt'] ?? ''));
$title = trim((string) ($notice['title'] ?? ''));
$body = trim((string) ($notice['body'] ?? ''));
$category = trim((string) ($notice['category'] ?? ''));
$categoryLabel = trim((string) ($notice['category_label'] ?? '')) ?: admin_humanize($category);
$priority = trim((string) ($notice['priority'] ?? ''));
$priorityLabel = trim((string) ($notice['priority_label'] ?? '')) ?: admin_humanize($priority);
$priorityTone = trim((string) ($notice['priority_tone'] ?? ''));
$status = trim((string) ($notice['status'] ?? 'draft'));
$statusLabel = trim((string) ($notice['status_label'] ?? '')) ?: admin_humanize($status);
$statusTone = trim((string) ($notice['status_tone'] ?? ''));
$audience = trim((string) ($notice['audience'] ?? ''));
$audienceLabel = trim((string) ($notice['audience_label'] ?? '')) ?: admin_humanize($audience);
$publishedAt = isset($notice['published_at']) ? (string) $notice['published_at'] : '';
$expiresAt = isset($notice['expires_at']) ? (string) $notice['expires_at'] : '';
$createdAt = isset($notice['created_at']) ? (string) $notice['created_at'] : '';
$updatedAt = isset($notice['updated_at']) ? (string) $notice['updated_at'] : '';
$createdBy = trim((string) ($notice['created_by'] ?? ($notice['created_by_name'] ?? '')));

// Server-side capability flags (frozen keys) with safe status fallbacks.
$canEdit = isset($can_edit) ? (bool) $can_edit : ($status === 'draft');
$canPublish = isset($can_publish) ? (bool) $can_publish : ($status === 'draft');
$canArchive = isset($can_archive) ? (bool) $can_archive : ($status === 'published');

$optionMap = static function (array $items, array $fallback): array {
    $out = [];
    foreach ($items as $item) {
        if (is_string($item) && trim($item) !== '') {
            $out[trim($item)] = admin_humanize(trim($item));
            continue;
        }
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
$categoryMap = $optionMap($categoryOptions, [
    'general' => 'General', 'security' => 'Security', 'maintenance' => 'Maintenance',
    'event' => 'Event', 'emergency' => 'Emergency',
]);
$priorityMap = $optionMap($priorityOptions, [
    'normal' => 'Normal', 'important' => 'Important', 'urgent' => 'Urgent',
]);
$audienceMap = $optionMap($audienceOptions, [
    'all' => 'Everyone in the estate', 'residents' => 'Residents only', 'staff' => 'Estate staff and guards',
]);

$oldValue = static function (string $field, string $fallback = '') use ($old, $notice): string {
    if (array_key_exists($field, $old) && is_scalar($old[$field])) {
        return trim((string) $old[$field]);
    }
    $candidate = $notice[$field] ?? $fallback;

    return is_scalar($candidate) ? trim((string) $candidate) : $fallback;
};
$dateTimeLocal = static function (string $value): string {
    if ($value === '') {
        return '';
    }
    $parsed = substr(str_replace(' ', 'T', substr($value, 0, 19)), 0, 16);

    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $parsed) === 1 ? $parsed : '';
};

$isExpired = false;
if ($expiresAt !== '') {
    $expiresTs = strtotime($expiresAt);
    $isExpired = $expiresTs !== false && $expiresTs < time();
}
$statusClass = admin_tone_class($statusTone, $status);
?>
<div class="admin-page__head">
  <div>
    <nav class="admin-breadcrumb" aria-label="Breadcrumb">
      <a href="/admin/notices">Notices</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page"><?= e($title !== '' ? $title : 'Notice') ?></span>
    </nav>
    <h1 class="admin-page__title"><?= e($title !== '' ? $title : 'Notice') ?></h1>
    <p class="admin-page__lede">
      <?php if ($status === 'draft'): ?>
        This notice is a draft — only estate managers can see it until it is published.
      <?php elseif ($status === 'published'): ?>
        Published notice — visible to its audience on Home, the notices list and the gate.
      <?php else: ?>
        Archived notice — hidden from residents and guards but kept for the estate record.
      <?php endif; ?>
    </p>
  </div>
  <div class="admin-page__actions">
    <?php if ($canPublish && $noticeUuid !== ''): ?>
      <form method="post" action="/admin/notices/<?= e(rawurlencode($noticeUuid)) ?>/publish" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--primary"><?= admin_icon('check', 'ico ico--sm') ?> Publish notice</button>
      </form>
    <?php endif; ?>
    <?php if ($canArchive && $noticeUuid !== ''): ?>
      <form method="post" action="/admin/notices/<?= e(rawurlencode($noticeUuid)) ?>/archive" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--outline"><?= admin_icon('inbox', 'ico ico--sm') ?> Archive notice</button>
      </form>
    <?php endif; ?>
    <a class="btn btn--ghost" href="/admin/notices">All notices</a>
  </div>
</div>

<?= admin_summary($errors) ?>

<section class="admin-section" aria-labelledby="notice-overview-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="notice-overview-title">Overview</h2>
    <span class="admin-chip-row">
      <span class="status <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
      <?php if ($priorityLabel !== ''): ?>
        <span class="status <?= e(admin_tone_class($priorityTone)) ?>"><?= e($priorityLabel) ?> priority</span>
      <?php endif; ?>
      <?php if ($categoryLabel !== ''): ?>
        <span class="chip chip--outline"><?= e($categoryLabel) ?></span>
      <?php endif; ?>
      <?php if ($isExpired): ?>
        <span class="status status--danger">Expired</span>
      <?php endif; ?>
    </span>
  </div>

  <?php if ($title === '' && $body === ''): ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('bell') ?></span>
      <p class="admin-empty__title">Notice unavailable</p>
      <p class="admin-empty__text">This notice could not be loaded. Return to the notices queue and try again.</p>
      <a class="btn btn--outline" href="/admin/notices">Back to notices</a>
    </div>
  <?php else: ?>
    <div class="admin-card admin-notice">
      <dl class="admin-detail-grid">
        <div class="admin-detail-grid__item">
          <dt>Audience</dt>
          <dd><?= e($audienceLabel !== '' ? $audienceLabel : '—') ?></dd>
        </div>
        <div class="admin-detail-grid__item">
          <dt>Category</dt>
          <dd><?= e($categoryLabel !== '' ? $categoryLabel : '—') ?></dd>
        </div>
        <div class="admin-detail-grid__item">
          <dt>Priority</dt>
          <dd><?= e($priorityLabel !== '' ? $priorityLabel : '—') ?></dd>
        </div>
        <div class="admin-detail-grid__item">
          <dt>Published</dt>
          <dd><?= e($publishedAt !== '' ? admin_datetime($publishedAt, $timezone, 'd M Y, H:i') : 'Not published') ?></dd>
        </div>
        <div class="admin-detail-grid__item">
          <dt>Expires</dt>
          <dd><?= e($expiresAt !== '' ? admin_datetime($expiresAt, $timezone, 'd M Y, H:i') : 'Never') ?></dd>
        </div>
        <?php if ($createdAt !== ''): ?>
          <div class="admin-detail-grid__item">
            <dt>Created</dt>
            <dd><?= e(admin_datetime($createdAt, $timezone)) ?></dd>
          </div>
        <?php endif; ?>
        <?php if ($createdBy !== ''): ?>
          <div class="admin-detail-grid__item">
            <dt>Created by</dt>
            <dd><?= e($createdBy) ?></dd>
          </div>
        <?php endif; ?>
      </dl>

      <?php if ($noticeImageUrl !== ''): ?>
        <figure class="notice-detail__media admin-notice__media">
          <img src="<?= e($noticeImageUrl) ?>" alt="<?= e($noticeImageAlt !== '' ? $noticeImageAlt : 'Notice image') ?>" loading="lazy" decoding="async">
        </figure>
      <?php endif; ?>

      <div class="admin-notice__body">
        <h3 class="admin-form__title"><?= admin_icon('list', 'ico ico--sm') ?> Notice body</h3>
        <div class="admin-notice__text"><?= nl2br(e($body)) ?></div>
      </div>

      <?php if ($status === 'published'): ?>
        <div class="alert alert--info" role="status">
          <?= admin_icon('info') ?>
          <div class="alert__body">
            <p class="alert__title">Published notices are locked</p>
            <p>Editing is disabled after publishing. Archive this notice and create a new one to share corrected information.</p>
          </div>
        </div>
      <?php elseif ($status === 'archived'): ?>
        <div class="alert alert--warning" role="status">
          <?= admin_icon('info') ?>
          <div class="alert__body">
            <p class="alert__title">Notice archived</p>
            <p>Archived notices are terminal and hidden from residents and guards. The record stays available for audit.</p>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($canEdit && $status === 'draft' && $noticeUuid !== ''): ?>
  <section class="admin-section" aria-labelledby="notice-edit-title">
    <div class="admin-section__head">
      <div>
        <h2 class="admin-section__title" id="notice-edit-title">Edit draft</h2>
        <p class="admin-section__hint">Drafts can be edited until published. Saving uses the existing update handler — no new route.</p>
      </div>
    </div>

    <form method="post" action="/admin/notices/<?= e(rawurlencode($noticeUuid)) ?>" enctype="multipart/form-data" class="admin-form card">
      <?= csrf_field() ?>

      <div class="admin-field<?= admin_field_error($errors, 'title') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="edit_notice_title">Title</label>
        <input class="input<?= admin_field_error($errors, 'title') !== '' ? ' input--invalid' : '' ?>"
               id="edit_notice_title" name="title" type="text" maxlength="160" required
               value="<?= e($oldValue('title')) ?>">
        <?php if (admin_field_error($errors, 'title') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'title')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field<?= admin_field_error($errors, 'body') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="edit_notice_body">Body</label>
        <textarea class="input<?= admin_field_error($errors, 'body') !== '' ? ' input--invalid' : '' ?>"
                  id="edit_notice_body" name="body" rows="6" maxlength="2000" required><?= e($oldValue('body')) ?></textarea>
        <?php if (admin_field_error($errors, 'body') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'body')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-form__grid">
        <div class="admin-field">
          <label class="admin-field__label" for="edit_notice_category">Category</label>
          <select class="select" id="edit_notice_category" name="category" required>
            <?php $selectedCategory = $oldValue('category', 'general'); ?>
            <?php foreach ($categoryMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $selectedCategory === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-field">
          <label class="admin-field__label" for="edit_notice_priority">Priority</label>
          <select class="select" id="edit_notice_priority" name="priority" required>
            <?php $selectedPriority = $oldValue('priority', 'normal'); ?>
            <?php foreach ($priorityMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $selectedPriority === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-field">
          <label class="admin-field__label" for="edit_notice_audience">Audience</label>
          <select class="select" id="edit_notice_audience" name="audience" required>
            <?php $selectedAudience = $oldValue('audience', 'all'); ?>
            <?php foreach ($audienceMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $selectedAudience === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-field<?= admin_field_error($errors, 'expires_at') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="edit_notice_expires_at">
            Expires
            <span class="muted text-xs">Optional — clear to remove</span>
          </label>
          <input class="input" id="edit_notice_expires_at" name="expires_at" type="datetime-local"
                 value="<?= e($dateTimeLocal($oldValue('expires_at'))) ?>">
          <?php if (admin_field_error($errors, 'expires_at') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'expires_at')) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="admin-field<?= admin_field_error($errors, 'image') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="edit_notice_image">
          Notice image
          <span class="muted text-xs">Optional — JPEG, PNG or WebP, max 2 MB</span>
        </label>
        <?php if ($noticeImageUrl !== ''): ?>
          <div class="notice-image-current">
            <img src="<?= e($noticeImageUrl) ?>" alt="<?= e($noticeImageAlt !== '' ? $noticeImageAlt : 'Current notice image') ?>" loading="lazy" decoding="async">
            <label class="admin-check"><input type="checkbox" name="remove_image" value="1"> Remove image</label>
          </div>
        <?php endif; ?>
        <input class="input" id="edit_notice_image" name="image" type="file"
               accept="image/jpeg,image/png,image/webp" data-notice-image-input>
        <p class="admin-field__hint">Replacing the image removes the old file. Check “Remove image” to return to text-only.</p>
        <p class="admin-field__error" data-notice-image-error hidden></p>
        <?php if (admin_field_error($errors, 'image') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e(admin_field_error($errors, 'image')) ?></p>
        <?php endif; ?>
        <div class="notice-image-preview" data-notice-image-preview-wrap hidden>
          <img data-notice-image-preview alt="Selected notice image preview">
        </div>
      </div>

      <div class="admin-form__actions">
        <button type="submit" class="btn btn--primary"><?= admin_icon('check', 'ico ico--sm') ?> Save draft changes</button>
        <a class="btn btn--ghost" href="/admin/notices/<?= e(rawurlencode($noticeUuid)) ?>">Cancel</a>
      </div>
    </form>
  </section>
<?php endif; ?>

<section class="admin-section" aria-labelledby="notice-timeline-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="notice-timeline-title">Status timeline</h2>
  </div>

  <?php
  // T169: horizontal status stepper (was a vertical list). Current stage is
  // marked with aria-current="step"; done/current/pending are conveyed by both
  // text ("Done"/"Current"/"Pending") and tone, never colour alone.
  $timelineStatus = in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';
  $timelineSteps = [
      [
          'label' => 'Draft created',
          'when' => $createdAt !== '' ? admin_datetime($createdAt, $timezone, 'd M Y, H:i') : '—',
          'done' => true,
          'current' => $timelineStatus === 'draft',
      ],
      [
          'label' => 'Published',
          'when' => $publishedAt !== '' ? admin_datetime($publishedAt, $timezone, 'd M Y, H:i') : 'Not published yet',
          'done' => in_array($timelineStatus, ['published', 'archived'], true),
          'current' => $timelineStatus === 'published',
      ],
      [
          'label' => 'Archived',
          'when' => $timelineStatus === 'archived' && $updatedAt !== ''
              ? admin_datetime($updatedAt, $timezone, 'd M Y, H:i')
              : 'Not archived',
          'done' => $timelineStatus === 'archived',
          'current' => $timelineStatus === 'archived',
      ],
  ];
  ?>
  <ol class="admin-timeline" aria-label="Notice status timeline" tabindex="0">
    <?php foreach ($timelineSteps as $timelineStep): ?>
      <?php
      $timelineDone = (bool) $timelineStep['done'];
      $timelineCurrent = (bool) $timelineStep['current'];
      $timelineState = $timelineCurrent ? 'Current' : ($timelineDone ? 'Done' : 'Pending');
      ?>
      <li class="admin-timeline__item<?= $timelineDone ? ' is-done' : '' ?><?= $timelineCurrent ? ' is-current' : '' ?>"<?= $timelineCurrent ? ' aria-current="step"' : '' ?>>
        <span class="admin-timeline__dot" aria-hidden="true">
          <?php if ($timelineDone): ?><?= admin_icon('check', 'ico ico--xs') ?><?php endif; ?>
        </span>
        <div class="admin-timeline__body">
          <p class="admin-timeline__label"><?= e($timelineStep['label']) ?></p>
          <p class="admin-timeline__when"><?= e($timelineStep['when']) ?></p>
          <p class="admin-timeline__state"><?= e($timelineState) ?></p>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>
</section>
