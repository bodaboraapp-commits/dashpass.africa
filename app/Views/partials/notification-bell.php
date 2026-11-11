<?php

declare(strict_types=1);

/**
 * Shared estate-notices bell (Inc-13 P2a, T172/T177).
 *
 * The top-right bell is a direct link to the role's notices screen:
 *  - app/resident/manager shells -> /app/notices
 *  - guard shell                -> /gate/notices
 * It keeps an unread badge and "Notices, N unread" accessible label; zero
 * unread shows no badge. When notifications are disabled/unavailable/schema-
 * absent the same icon renders as a non-submitting disabled state. All output
 * is escaped; no inline handlers/styles and no dialog/JS toggle.
 *
 * Data (from BuildsShell -> NotificationService::shellData()):
 * $notifications_enabled, $notifications_unread_count, $notifications_latest
 * plus $estate (role context).
 */

$notificationsEnabled = (bool) ($notifications_enabled ?? false);
$notificationIcon = static fn (string $name, string $class = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $class]
);
$notificationPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$notificationRole = strtolower(trim((string) ($estate['role_name'] ?? '')));
$notificationGuardContext = str_starts_with($notificationPath, '/gate')
    || in_array($notificationRole, ['security_guard', 'security_supervisor', 'parking_manager'], true);
$notificationHref = $notificationGuardContext ? '/gate/notices' : '/app/notices';

if (!$notificationsEnabled) {
    ?>
    <div class="notification-bell-wrap">
      <span class="icon-btn notification-bell notification-bell--disabled"
            role="img"
            aria-label="Notices unavailable"
            aria-disabled="true"
            title="Notices unavailable">
        <?= $notificationIcon('bell', 'ico') ?>
      </span>
    </div>
    <?php
    return;
}

// Badge = unread estate notices. The shell provides the overall in-app unread
// count plus the latest rows; when only notice rows are present the overall
// count is the notice count, otherwise fall back to the visible notice rows.
$notificationUnreadTotal = max(0, (int) ($notifications_unread_count ?? 0));
$notificationLatestAll = is_array($notifications_latest ?? null) ? $notifications_latest : [];
$notificationLatestAll = array_values(array_filter($notificationLatestAll, static fn (mixed $item): bool => is_array($item)));
$notificationNoticeRows = array_values(array_filter(
    $notificationLatestAll,
    static fn (array $item): bool => (string) ($item['type'] ?? '') === 'notice'
));
$notificationVisibleNonNotice = count($notificationLatestAll) !== count($notificationNoticeRows);
$notificationUnreadFromRows = count(array_filter(
    $notificationNoticeRows,
    static fn (array $item): bool => (bool) ($item['unread'] ?? false)
));
$notificationUnreadCount = $notificationVisibleNonNotice ? $notificationUnreadFromRows : $notificationUnreadTotal;
$notificationBadge = $notificationUnreadCount > 9 ? '9+' : (string) $notificationUnreadCount;
$notificationAriaLabel = $notificationUnreadCount > 0
    ? 'Notices, ' . $notificationUnreadCount . ' unread'
    : 'Notices, no unread';
?>
<div class="notification-bell-wrap">
  <a class="icon-btn notification-bell"
     href="<?= e($notificationHref) ?>"
     aria-label="<?= e($notificationAriaLabel) ?>"
     title="Notices">
    <?= $notificationIcon('bell', 'ico') ?>
    <?php if ($notificationUnreadCount > 0): ?>
      <span class="notification-bell__badge" aria-hidden="true"><?= e($notificationBadge) ?></span>
    <?php endif; ?>
  </a>
</div>
