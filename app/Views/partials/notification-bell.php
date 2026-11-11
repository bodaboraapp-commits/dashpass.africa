<?php

declare(strict_types=1);

/**
 * Shared notification bell + dialog panel (Inc-13 P2a, T172).
 *
 * Renders nothing when notifications are disabled/absent so the shell stays
 * safe before the feature flag/schema is enabled. The bell is a plain link to
 * /notifications (works without JS); app.js enhances it to open the native
 * <dialog> panel. All dynamic output is escaped; no inline handlers/styles.
 *
 * Data (from BuildsShell -> NotificationService::shellData()):
 * $notifications_enabled, $notifications_unread_count, $notifications_latest
 * plus $csrfToken and $estate (estate timezone).
 */

$notificationsEnabled = (bool) ($notifications_enabled ?? false);
$notificationIcon = static fn (string $name, string $class = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $class]
);

// The top-right bell is always visible (owner requirement). When notifications
// are disabled/unavailable/schema-absent it renders as a non-submitting
// disabled state with a clear accessible label instead of disappearing.
if (!$notificationsEnabled) {
    ?>
    <div class="notification-bell-wrap" data-notification-bell-root>
      <span class="icon-btn notification-bell notification-bell--disabled"
            role="img"
            aria-label="Notifications unavailable"
            aria-disabled="true"
            title="Notifications unavailable">
        <?= $notificationIcon('bell', 'ico') ?>
      </span>
    </div>
    <?php
    return;
}

$notificationUnreadCount = max(0, (int) ($notifications_unread_count ?? 0));
$notificationLatest = is_array($notifications_latest ?? null) ? $notifications_latest : [];
$notificationLatest = array_values(array_filter($notificationLatest, static fn (mixed $item): bool => is_array($item)));
$notificationBadge = $notificationUnreadCount > 9 ? '9+' : (string) $notificationUnreadCount;
$notificationAriaLabel = $notificationUnreadCount > 0
    ? 'Notifications, ' . $notificationUnreadCount . ' unread'
    : 'Notifications, no unread';
$notificationTimezone = trim((string) (($estate['estate_timezone'] ?? '') ?: ($estate['timezone'] ?? '')));
if ($notificationTimezone === '') {
    $notificationTimezone = (string) config('app.timezone', 'Africa/Nairobi');
}
$notificationTypeIcon = static fn (string $type): string => match ($type) {
    'notice' => 'bell',
    'billing' => 'wallet',
    'reminder' => 'clock',
    default => 'info',
};
?>
<div class="notification-bell-wrap" data-notification-bell-root>
  <a class="icon-btn notification-bell"
     href="/notifications"
     data-notification-bell
     aria-label="<?= e($notificationAriaLabel) ?>"
     aria-haspopup="dialog"
     aria-controls="notification-panel"
     aria-expanded="false"
     title="Notifications">
    <?= $notificationIcon('bell', 'ico') ?>
    <?php if ($notificationUnreadCount > 0): ?>
      <span class="notification-bell__badge" aria-hidden="true"><?= e($notificationBadge) ?></span>
    <?php endif; ?>
  </a>

  <dialog class="notification-panel" id="notification-panel" aria-labelledby="notification-panel-title">
    <div class="notification-panel__head">
      <h2 class="notification-panel__title" id="notification-panel-title">Notifications</h2>
      <button type="button" class="icon-btn notification-panel__close" data-notification-close aria-label="Close notifications">
        <?= $notificationIcon('close', 'ico') ?>
      </button>
    </div>

    <?php if ($notificationLatest === []): ?>
      <div class="notification-panel__empty">
        <span class="notification-panel__empty-icon" aria-hidden="true"><?= $notificationIcon('bell', 'ico') ?></span>
        <p class="notification-panel__empty-title">No notifications yet</p>
        <p class="notification-panel__empty-text">New notices and reminders will appear here.</p>
      </div>
    <?php else: ?>
      <ul class="notification-list">
        <?php foreach ($notificationLatest as $notificationItem): ?>
          <?php
          $notificationUuid = trim((string) ($notificationItem['uuid'] ?? ''));
          $notificationTitle = trim((string) ($notificationItem['title'] ?? 'Notification'));
          $notificationExcerpt = trim((string) ($notificationItem['body_excerpt'] ?? ''));
          $notificationType = trim((string) ($notificationItem['type'] ?? 'system'));
          $notificationTypeLabel = trim((string) ($notificationItem['type_label'] ?? 'System'));
          $notificationLink = trim((string) ($notificationItem['link'] ?? '/notifications'));
          $notificationCreated = trim((string) ($notificationItem['created_at'] ?? ''));
          $notificationUnread = (bool) ($notificationItem['unread'] ?? false);
          $notificationWhen = $notificationCreated !== ''
              ? format_datetime($notificationCreated, $notificationTimezone, 'd M Y, H:i')
              : '';
          $notificationState = $notificationUnread ? 'Unread' : 'Read';
          ?>
          <li class="notification-list__item">
            <?php if ($notificationUnread && $notificationUuid !== ''): ?>
              <form method="post" action="/notifications/<?= e(rawurlencode($notificationUuid)) ?>/read" class="notification-list__form">
                <?= csrf_field() ?>
                <button type="submit" class="notification-item notification-item--unread" data-notification-item>
                  <span class="notification-item__icon" aria-hidden="true"><?= $notificationIcon($notificationTypeIcon($notificationType), 'ico ico--sm') ?></span>
                  <span class="notification-item__body">
                    <span class="notification-item__head">
                      <span class="notification-item__type"><?= e($notificationTypeLabel) ?></span>
                      <?php if ($notificationWhen !== ''): ?><span class="notification-item__time"><?= e($notificationWhen) ?></span><?php endif; ?>
                    </span>
                    <span class="notification-item__title"><?= e($notificationTitle) ?></span>
                    <?php if ($notificationExcerpt !== ''): ?>
                      <span class="notification-item__excerpt"><?= e($notificationExcerpt) ?></span>
                    <?php endif; ?>
                    <span class="notification-item__state">
                      <span class="notification-item__dot" aria-hidden="true"></span>
                      <?= e($notificationState) ?>
                    </span>
                  </span>
                </button>
              </form>
            <?php else: ?>
              <a class="notification-item" href="<?= e($notificationLink) ?>" data-notification-item>
                <span class="notification-item__icon" aria-hidden="true"><?= $notificationIcon($notificationTypeIcon($notificationType), 'ico ico--sm') ?></span>
                <span class="notification-item__body">
                  <span class="notification-item__head">
                    <span class="notification-item__type"><?= e($notificationTypeLabel) ?></span>
                    <?php if ($notificationWhen !== ''): ?><span class="notification-item__time"><?= e($notificationWhen) ?></span><?php endif; ?>
                  </span>
                  <span class="notification-item__title"><?= e($notificationTitle) ?></span>
                  <?php if ($notificationExcerpt !== ''): ?>
                    <span class="notification-item__excerpt"><?= e($notificationExcerpt) ?></span>
                  <?php endif; ?>
                  <span class="notification-item__state"><?= e($notificationState) ?></span>
                </span>
              </a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="notification-panel__foot">
      <a class="btn btn--ghost btn--sm notification-panel__viewall" href="/notifications">View all notifications</a>
    </div>
  </dialog>
</div>
