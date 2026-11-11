<?php

declare(strict_types=1);

/**
 * Notifications inbox (Inc-13 P2a, T172).
 *
 * Auth-only, owner-scoped list rendered by NotificationController inside the
 * resident app shell. Frozen payload keys: $notifications, $filters, $counts,
 * $notifications_enabled; shell/CSRF/estate from BuildsShell.
 */

$notificationInboxEnabled = (bool) ($notifications_enabled ?? false);
$notificationInboxRows = is_array($notifications ?? null) ? $notifications : [];
$notificationInboxRows = array_values(array_filter($notificationInboxRows, static fn (mixed $item): bool => is_array($item)));
$notificationInboxFilters = is_array($filters ?? null) ? $filters : ['state' => 'all', 'type' => 'all'];
$notificationInboxCounts = is_array($counts ?? null) ? $counts : ['unread' => 0, 'total' => 0];
$notificationInboxState = (string) ($notificationInboxFilters['state'] ?? 'all') === 'unread' ? 'unread' : 'all';
$notificationInboxType = (string) ($notificationInboxFilters['type'] ?? 'all');
if (!in_array($notificationInboxType, ['notice', 'billing', 'reminder', 'system'], true)) {
    $notificationInboxType = 'all';
}
$notificationInboxUnread = max(0, (int) ($notificationInboxCounts['unread'] ?? 0));
$notificationInboxTotal = max(0, (int) ($notificationInboxCounts['total'] ?? 0));
$notificationInboxTimezone = trim((string) (($estate['estate_timezone'] ?? '') ?: ($estate['timezone'] ?? '')));
if ($notificationInboxTimezone === '') {
    $notificationInboxTimezone = (string) config('app.timezone', 'Africa/Nairobi');
}
$notificationInboxIcon = static fn (string $name, string $class = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $class]
);
$notificationInboxTypeIcon = static fn (string $type): string => match ($type) {
    'notice' => 'bell',
    'billing' => 'wallet',
    'reminder' => 'clock',
    default => 'info',
};
$notificationInboxHref = static function (string $state, string $type): string {
    $query = [];
    if ($state !== 'all') {
        $query['state'] = $state;
    }
    if ($type !== 'all') {
        $query['type'] = $type;
    }

    return '/notifications' . ($query === [] ? '' : '?' . http_build_query($query));
};
$notificationInboxTypeLabels = ['all' => 'All', 'notice' => 'Notice', 'billing' => 'Billing', 'reminder' => 'Reminder', 'system' => 'System'];
$notificationInboxFiltered = $notificationInboxState !== 'all' || $notificationInboxType !== 'all';
?>
<div class="resident">
  <header class="page-head">
    <div>
      <p class="page-head__eyebrow">Your account</p>
      <h1 class="page-head__title">Notifications</h1>
      <p class="page-head__lede">Notices, bills and reminders for you<?= trim((string) ($estate['estate_name'] ?? '')) !== '' ? ' at ' . e((string) $estate['estate_name']) : '' ?>.</p>
    </div>
  </header>

  <?php if (!$notificationInboxEnabled): ?>
    <div class="resident-empty">
      <span class="resident-empty__icon" aria-hidden="true"><?= $notificationInboxIcon('bell') ?></span>
      <p class="resident-empty__title">Notifications are not available yet</p>
      <p class="resident-empty__text">New notices will appear here when notifications are enabled for your estate.</p>
      <a class="btn btn--ghost btn--sm" href="/app/notices">Browse estate notices</a>
    </div>
  <?php else: ?>
    <div class="notification-inbox__summary" role="status">
      <span class="notification-inbox__count"><?= e((string) $notificationInboxUnread) ?> unread</span>
      <span class="notification-inbox__count-total"><?= e((string) $notificationInboxTotal) ?> total</span>
    </div>

    <nav class="notification-filters" aria-label="Filter notifications">
      <div class="notification-filters__group" aria-label="Read state">
        <a class="notification-filter<?= $notificationInboxState === 'all' ? ' notification-filter--active' : '' ?>"
           href="<?= e($notificationInboxHref('all', $notificationInboxType)) ?>"
           <?= $notificationInboxState === 'all' ? 'aria-current="true"' : '' ?>>All</a>
        <a class="notification-filter<?= $notificationInboxState === 'unread' ? ' notification-filter--active' : '' ?>"
           href="<?= e($notificationInboxHref('unread', $notificationInboxType)) ?>"
           <?= $notificationInboxState === 'unread' ? 'aria-current="true"' : '' ?>>Unread <span class="notification-filter__count"><?= e((string) $notificationInboxUnread) ?></span></a>
      </div>
      <div class="notification-filters__group" aria-label="Notification type">
        <?php foreach ($notificationInboxTypeLabels as $notificationInboxTypeKey => $notificationInboxTypeLabel): ?>
          <a class="notification-filter notification-filter--type<?= $notificationInboxType === $notificationInboxTypeKey ? ' notification-filter--active' : '' ?>"
             href="<?= e($notificationInboxHref($notificationInboxState, $notificationInboxTypeKey)) ?>"
             <?= $notificationInboxType === $notificationInboxTypeKey ? 'aria-current="true"' : '' ?>><?= e($notificationInboxTypeLabel) ?></a>
        <?php endforeach; ?>
      </div>
    </nav>

    <?php if ($notificationInboxRows === []): ?>
      <div class="resident-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $notificationInboxIcon('bell') ?></span>
        <?php if ($notificationInboxFiltered): ?>
          <p class="resident-empty__title">No notifications match these filters</p>
          <p class="resident-empty__text">Try “All” notifications or a different type.</p>
          <a class="btn btn--ghost btn--sm" href="/notifications">Clear filters</a>
        <?php else: ?>
          <p class="resident-empty__title">You're all caught up</p>
          <p class="resident-empty__text">Notices, bills and reminders will appear here when your estate sends them.</p>
          <a class="btn btn--ghost btn--sm" href="/app/notices">Browse estate notices</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <ul class="notification-inbox__list">
        <?php foreach ($notificationInboxRows as $notificationInboxItem): ?>
          <?php
          $notificationInboxUuid = trim((string) ($notificationInboxItem['uuid'] ?? ''));
          $notificationInboxTitle = trim((string) ($notificationInboxItem['title'] ?? 'Notification'));
          $notificationInboxExcerpt = trim((string) ($notificationInboxItem['body_excerpt'] ?? ''));
          $notificationInboxItemType = trim((string) ($notificationInboxItem['type'] ?? 'system'));
          $notificationInboxTypeLabel = trim((string) ($notificationInboxItem['type_label'] ?? 'System'));
          $notificationInboxLink = trim((string) ($notificationInboxItem['link'] ?? '/notifications'));
          $notificationInboxCreated = trim((string) ($notificationInboxItem['created_at'] ?? ''));
          $notificationInboxIsUnread = (bool) ($notificationInboxItem['unread'] ?? false);
          $notificationInboxWhen = $notificationInboxCreated !== ''
              ? format_datetime($notificationInboxCreated, $notificationInboxTimezone, 'd M Y, H:i')
              : '';
          $notificationInboxStateLabel = $notificationInboxIsUnread ? 'Unread' : 'Read';
          ?>
          <li class="notification-inbox__list-item">
            <?php if ($notificationInboxIsUnread && $notificationInboxUuid !== ''): ?>
              <form method="post" action="/notifications/<?= e(rawurlencode($notificationInboxUuid)) ?>/read" class="notification-inbox__form">
                <?= csrf_field() ?>
                <button type="submit" class="notification-inbox__row notification-inbox__row--unread">
                  <span class="notification-inbox__icon" aria-hidden="true"><?= $notificationInboxIcon($notificationInboxTypeIcon($notificationInboxItemType)) ?></span>
                  <span class="notification-inbox__body">
                    <span class="notification-inbox__meta">
                      <span class="notification-inbox__type"><?= e($notificationInboxTypeLabel) ?></span>
                      <?php if ($notificationInboxWhen !== ''): ?><span class="notification-inbox__time"><?= e($notificationInboxWhen) ?></span><?php endif; ?>
                    </span>
                    <span class="notification-inbox__title"><?= e($notificationInboxTitle) ?></span>
                    <?php if ($notificationInboxExcerpt !== ''): ?>
                      <span class="notification-inbox__excerpt"><?= e($notificationInboxExcerpt) ?></span>
                    <?php endif; ?>
                    <span class="notification-inbox__state"><span class="notification-inbox__dot" aria-hidden="true"></span><?= e($notificationInboxStateLabel) ?> · open to read</span>
                  </span>
                </button>
              </form>
            <?php else: ?>
              <a class="notification-inbox__row" href="<?= e($notificationInboxLink) ?>">
                <span class="notification-inbox__icon" aria-hidden="true"><?= $notificationInboxIcon($notificationInboxTypeIcon($notificationInboxItemType)) ?></span>
                <span class="notification-inbox__body">
                  <span class="notification-inbox__meta">
                    <span class="notification-inbox__type"><?= e($notificationInboxTypeLabel) ?></span>
                    <?php if ($notificationInboxWhen !== ''): ?><span class="notification-inbox__time"><?= e($notificationInboxWhen) ?></span><?php endif; ?>
                  </span>
                  <span class="notification-inbox__title"><?= e($notificationInboxTitle) ?></span>
                  <?php if ($notificationInboxExcerpt !== ''): ?>
                    <span class="notification-inbox__excerpt"><?= e($notificationInboxExcerpt) ?></span>
                  <?php endif; ?>
                  <span class="notification-inbox__state"><?= e($notificationInboxStateLabel) ?></span>
                </span>
              </a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>
