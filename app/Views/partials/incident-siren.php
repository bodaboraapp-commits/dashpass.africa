<?php

declare(strict_types=1);

/**
 * Incident triage siren indicator (Inc-13 P1b).
 *
 * Server-rendered only: shows when the signed-in admin/manager has unread
 * incident-report notifications (incidents_unread_count > 0). Links to the
 * latest report from the shell, falling back to the incidents queue. The
 * count/link normally arrive via notification_shell(); supplied data wins.
 * Renders nothing at 0/absent so reading the report clears it on next load.
 */

$incidentSirenCount = isset($incidents_unread_count) && is_numeric($incidents_unread_count)
    ? max(0, (int) $incidents_unread_count)
    : null;
$incidentSirenLink = isset($incidents_latest_link) && is_string($incidents_latest_link)
    ? trim($incidents_latest_link)
    : null;

if ($incidentSirenCount === null || $incidentSirenLink === null) {
    $incidentSirenShell = function_exists('notification_shell') ? notification_shell() : [];
    if ($incidentSirenCount === null) {
        $incidentSirenCount = max(0, (int) ($incidentSirenShell['incidents_unread_count'] ?? 0));
    }
    if ($incidentSirenLink === null) {
        $incidentSirenLink = (string) ($incidentSirenShell['incidents_latest_link'] ?? '');
    }
}

if ($incidentSirenCount <= 0) {
    return;
}

$incidentSirenHref = $incidentSirenLink !== '' && str_starts_with($incidentSirenLink, '/')
    ? $incidentSirenLink
    : '/admin/incidents';
$incidentSirenLabel = 'New incident reports, ' . $incidentSirenCount . ' unread';
$incidentSirenCountLabel = $incidentSirenCount > 9 ? '9+' : (string) $incidentSirenCount;
$incidentSirenIcon = \App\Core\View::capture('partials/icon', ['name' => 'siren', 'class' => 'ico']);
?>
<a class="incident-siren"
   href="<?= e($incidentSirenHref) ?>"
   aria-label="<?= e($incidentSirenLabel) ?>"
   title="<?= e($incidentSirenLabel) ?>">
  <span class="incident-siren__bulb" aria-hidden="true">
    <?= \App\Core\View::capture('partials/icon', ['name' => 'siren', 'class' => 'ico']) ?>
    <span class="incident-siren__glow" aria-hidden="true"></span>
  </span>
  <span class="incident-siren__count" aria-hidden="true"><?= e($incidentSirenCountLabel) ?></span>
</a>
