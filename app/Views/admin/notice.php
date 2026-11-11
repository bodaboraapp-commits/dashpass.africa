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

$noticeUuid = trim((string) ($notice['uuid'] ?? ''));
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
    <?php if ($status === 'draft' && $noticeUuid !== ''): ?>
      <form method="post" action="/admin/notices/<?= e(rawurlencode($noticeUuid)) ?>/publish" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--primary"><?= admin_icon('check', 'ico ico--sm') ?> Publish notice</button>
      </form>
    <?php elseif ($status === 'published' && $noticeUuid !== ''): ?>
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

<section class="admin-section" aria-labelledby="notice-timeline-title">
  <div class="admin-section__head">
    <h2 class="admin-section__title" id="notice-timeline-title">Status timeline</h2>
  </div>

  <ol class="admin-timeline">
    <li class="admin-timeline__item <?= $status !== '' ? 'is-done' : '' ?>">
      <span class="admin-timeline__dot" aria-hidden="true"></span>
      <div>
        <p class="admin-timeline__label">Draft created</p>
        <p class="admin-timeline__when"><?= e($createdAt !== '' ? admin_datetime($createdAt, $timezone, 'd M Y, H:i') : '—') ?></p>
      </div>
    </li>
    <li class="admin-timeline__item <?= in_array($status, ['published', 'archived'], true) ? 'is-done' : '' ?>">
      <span class="admin-timeline__dot" aria-hidden="true"></span>
      <div>
        <p class="admin-timeline__label">Published</p>
        <p class="admin-timeline__when"><?= e($publishedAt !== '' ? admin_datetime($publishedAt, $timezone, 'd M Y, H:i') : 'Not published yet') ?></p>
      </div>
    </li>
    <li class="admin-timeline__item <?= $status === 'archived' ? 'is-done' : '' ?>">
      <span class="admin-timeline__dot" aria-hidden="true"></span>
      <div>
        <p class="admin-timeline__label">Archived</p>
        <p class="admin-timeline__when"><?= e($status === 'archived' && $updatedAt !== '' ? admin_datetime($updatedAt, $timezone, 'd M Y, H:i') : 'Not archived') ?></p>
      </div>
    </li>
  </ol>
</section>
