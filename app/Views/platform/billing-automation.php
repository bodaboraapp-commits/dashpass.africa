<?php
declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';
$schedules = isset($schedules) && is_array($schedules) ? array_values(array_filter($schedules, 'is_array')) : [];
$lastRun = isset($last_run) && is_array($last_run) ? $last_run : null;
$timezone = admin_timezone(isset($estate) && is_array($estate) ? $estate : []);
$meta = [];
if ($lastRun !== null) {
    $decoded = json_decode((string) ($lastRun['metadata'] ?? ''), true);
    $meta = is_array($decoded) ? $decoded : [];
}
?>
<div class="platform-page">
  <header class="platform-page__head">
    <div><p class="platform-page__eyebrow">Platform billing</p><h1 class="platform-page__title">Billing automation</h1><p class="platform-page__lede">Schedules, next runs and the latest automation outcome (audit-only history).</p></div>
    <a class="btn btn--ghost btn--sm" href="/platform/billing">Back to billing</a>
  </header>
  <section class="card" aria-labelledby="automation-schedules-title">
    <div class="admin-section__head"><h2 class="admin-section__title" id="automation-schedules-title">Schedules</h2><span class="chip"><?= e((string) count($schedules)) ?></span></div>
    <?php if ($schedules === []): ?>
      <div class="admin-empty"><p class="admin-empty__title">No billing schedules yet</p><p class="admin-empty__text">Schedule management routes are not registered in this build; this summary will populate when they land.</p></div>
    <?php else: ?>
      <div class="table-wrap"><table class="admin-table"><thead><tr><th scope="col">Estate</th><th scope="col">Scope</th><th scope="col">Status</th><th scope="col">Next run</th><th scope="col">Last period</th></tr></thead><tbody>
      <?php foreach ($schedules as $schedule): ?>
        <tr><td data-label="Estate"><?= e((string) ($schedule['estate_name'] ?? 'Platform')) ?></td><td data-label="Scope"><?= e((string) ($schedule['scope'] ?? '—')) ?></td><td data-label="Status"><span class="status <?= (string) ($schedule['status'] ?? '') === 'active' ? 'status--active' : 'status--inactive' ?>"><?= e((string) ($schedule['status'] ?? '—')) ?></span></td><td data-label="Next run"><?= e((string) ($schedule['next_run_at'] ?? '—')) ?></td><td data-label="Last period"><?= e((string) ($schedule['last_period_key'] ?? '—')) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
  <section class="card" aria-labelledby="automation-last-title">
    <div class="admin-section__head"><h2 class="admin-section__title" id="automation-last-title">Last automation run</h2></div>
    <?php if ($lastRun === null): ?>
      <p class="admin-field__hint">No automation run has been recorded yet.</p>
    <?php else: ?>
      <p class="admin-field__hint">Recorded <?= e((string) ($lastRun['created_at'] ?? '—')) ?> Africa/Nairobi display may vary by estate.</p>
      <dl class="admin-detail-list">
        <?php foreach ($meta as $key => $value): ?>
          <div><dt><?= e(ucwords(str_replace('_', ' ', (string) $key))) ?></dt><dd><?= e(is_scalar($value) ? (string) $value : json_encode($value)) ?></dd></div>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>
  </section>
</div>
