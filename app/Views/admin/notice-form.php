<?php

declare(strict_types=1);

/**
 * Admin notice create/edit form (estate-notices contract §4, phase 1).
 *
 * Shared compose screen for GET /admin/notices/new (no $notice) and the draft
 * edit route (existing $notice). Published/archived notices are immutable in
 * this increment, so a non-draft notice renders a locked notice instead of the
 * form. CSRF, inline errors and old-value preservation throughout.
 *
 * Variables (T53 frozen keys): $notice|null, $category_options $priority_options
 * $audience_options [], $errors [], $old [] + admin shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$notice = isset($notice) && is_array($notice) ? $notice : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];

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
$audienceMap = $optionMap($audience_options ?? [], [
    'all' => 'Everyone in the estate',
    'residents' => 'Residents only',
    'staff' => 'Estate staff and guards',
]);

$noticeUuid = trim((string) ($notice['uuid'] ?? ''));
$noticeImageUrl = trim((string) ($notice['image_url'] ?? ''));
$noticeImageAlt = trim((string) ($notice['image_alt'] ?? ''));
$isEdit = $noticeUuid !== '';
$status = trim((string) ($notice['status'] ?? ($isEdit ? 'draft' : 'draft')));
$isEditable = !$isEdit || $status === 'draft';

$oldValue = static function (string $field, string $fallback = '') use ($old, $notice): string {
    if (array_key_exists($field, $old) && is_scalar($old[$field])) {
        return trim((string) $old[$field]);
    }
    $candidate = $notice[$field] ?? $fallback;

    return is_scalar($candidate) ? trim((string) $candidate) : $fallback;
};

/** Convert a stored DATETIME string into a datetime-local value. */
$dateTimeLocal = static function (string $value): string {
    if ($value === '') {
        return '';
    }
    $normalised = str_replace(' ', 'T', substr($value, 0, 19));
    $parsed = substr($normalised, 0, 16);

    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $parsed) === 1 ? $parsed : '';
};

$fieldError = static function (string $field) use ($errors): string {
    return admin_field_error($errors, $field);
};
?>
<div class="admin-page__head">
  <div>
    <nav class="admin-breadcrumb" aria-label="Breadcrumb">
      <a href="/admin/notices">Notices</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page"><?= $isEdit ? 'Edit' : 'New notice' ?></span>
    </nav>
    <h1 class="admin-page__title"><?= $isEdit ? 'Edit notice' : 'New notice' ?></h1>
    <p class="admin-page__lede">
      <?php if ($isEdit): ?>
        Update this draft before publishing it to residents and guards.
      <?php else: ?>
        Write an estate announcement. It starts as a draft until you publish it.
      <?php endif; ?>
    </p>
  </div>
</div>

<?= admin_summary($errors) ?>

<?php if (!$isEditable): ?>
  <section class="admin-section">
    <div class="alert alert--warning" role="status">
      <?= admin_icon('info') ?>
      <div class="alert__body">
        <p class="alert__title">Published notices are locked</p>
        <p>This notice has already been published or archived and cannot be edited. Archive it instead, or create a new notice to correct the information.</p>
      </div>
    </div>
    <div class="admin-form__actions">
      <a class="btn btn--outline" href="/admin/notices/<?= e(rawurlencode($noticeUuid)) ?>">View notice</a>
      <a class="btn btn--ghost" href="/admin/notices">Back to notices</a>
    </div>
  </section>
<?php else: ?>
  <section class="admin-section" aria-labelledby="notice-form-title">
    <h2 class="admin-section__title" id="notice-form-title">Notice content</h2>

    <form method="post"
          action="<?= $isEdit ? '/admin/notices/' . e(rawurlencode($noticeUuid)) : '/admin/notices' ?>"
          enctype="multipart/form-data"
          class="admin-form card">
      <?= csrf_field() ?>

      <div class="admin-field<?= $fieldError('title') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="notice_title">Title</label>
        <input class="input<?= $fieldError('title') !== '' ? ' input--invalid' : '' ?>" id="notice_title" name="title"
               type="text" maxlength="160" required value="<?= e($oldValue('title')) ?>"
               placeholder="e.g. Water supply maintenance on Saturday">
        <p class="admin-field__hint">3 to 160 characters. Shown in the notice list and on Home.</p>
        <?php if ($fieldError('title') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('title')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-field<?= $fieldError('body') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="notice_body">Body</label>
        <textarea class="input<?= $fieldError('body') !== '' ? ' input--invalid' : '' ?>" id="notice_body" name="body"
                  rows="7" maxlength="2000" required placeholder="Write the full announcement for residents and guards..."><?= e($oldValue('body')) ?></textarea>
        <p class="admin-field__hint">10 to 2000 characters. Line breaks are preserved.</p>
        <?php if ($fieldError('body') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('body')) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-form__grid">
        <div class="admin-field<?= $fieldError('category') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="notice_category">Category</label>
          <select class="select" id="notice_category" name="category" required>
            <?php $selectedCategory = $oldValue('category', 'general'); ?>
            <?php foreach ($categoryMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $selectedCategory === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($fieldError('category') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('category')) ?></p>
          <?php endif; ?>
        </div>

        <div class="admin-field<?= $fieldError('priority') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="notice_priority">Priority</label>
          <select class="select" id="notice_priority" name="priority" required>
            <?php $selectedPriority = $oldValue('priority', 'normal'); ?>
            <?php foreach ($priorityMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $selectedPriority === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($fieldError('priority') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('priority')) ?></p>
          <?php endif; ?>
        </div>

        <div class="admin-field<?= $fieldError('audience') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="notice_audience">Audience</label>
          <select class="select" id="notice_audience" name="audience" required>
            <?php $selectedAudience = $oldValue('audience', 'all'); ?>
            <?php foreach ($audienceMap as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= $selectedAudience === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($fieldError('audience') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('audience')) ?></p>
          <?php endif; ?>
        </div>

        <div class="admin-field<?= $fieldError('expires_at') !== '' ? ' admin-field--invalid' : '' ?>">
          <label class="admin-field__label" for="notice_expires_at">
            Expires
            <span class="muted text-xs">Optional</span>
          </label>
          <input class="input<?= $fieldError('expires_at') !== '' ? ' input--invalid' : '' ?>" id="notice_expires_at"
                 name="expires_at" type="datetime-local" value="<?= e($dateTimeLocal($oldValue('expires_at'))) ?>">
          <p class="admin-field__hint">Leave blank to keep the notice visible until archived.</p>
          <?php if ($fieldError('expires_at') !== ''): ?>
            <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('expires_at')) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="admin-field<?= $fieldError('image') !== '' ? ' admin-field--invalid' : '' ?>">
        <label class="admin-field__label" for="notice_image">
          Notice image
          <span class="muted text-xs">Optional, JPEG, PNG or WebP, max 2 MB</span>
        </label>
        <?php if ($noticeImageUrl !== ''): ?>
          <div class="notice-image-current">
            <img src="<?= e($noticeImageUrl) ?>" alt="<?= e($noticeImageAlt !== '' ? $noticeImageAlt : 'Current notice image') ?>" loading="lazy" decoding="async">
            <label class="admin-check"><input type="checkbox" name="remove_image" value="1"> Remove image</label>
          </div>
        <?php endif; ?>
        <input class="input" id="notice_image" name="image" type="file"
               accept="image/jpeg,image/png,image/webp" data-notice-image-input>
        <p class="admin-field__hint">Shown as the notice hero image for residents and guards. Leave empty to keep it text-only.</p>
        <p class="admin-field__error" data-notice-image-error hidden></p>
        <?php if ($fieldError('image') !== ''): ?>
          <p class="admin-field__error"><?= admin_icon('alert', 'ico ico--xs') ?> <?= e($fieldError('image')) ?></p>
        <?php endif; ?>
        <div class="notice-image-preview" data-notice-image-preview-wrap hidden>
          <img data-notice-image-preview alt="Selected notice image preview">
        </div>
      </div>

      <div class="admin-form__actions">
        <button type="submit" class="btn btn--primary"><?= admin_icon('check', 'ico ico--xs') ?> <?= $isEdit ? 'Save draft' : 'Create notice' ?></button>
        <a class="btn btn--ghost" href="/admin/notices">Cancel</a>
      </div>
    </form>
  </section>
<?php endif; ?>
