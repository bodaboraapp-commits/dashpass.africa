<?php

declare(strict_types=1);

/**
 * Estate Admin goods approvals (owner request 2026-11-13).
 *
 * Variables: $movements (array from GoodsMovementService::adminList()),
 * $status_filter ('pending'|'approved'|'declined'|'completed'|'all'),
 * $errors, $old + shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$movements = isset($movements) && is_array($movements) ? $movements : [];
$statusFilter = isset($status_filter) && is_string($status_filter) ? $status_filter : 'pending';
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = admin_can($permissions, 'estate.user.manage');

$filters = [
    'pending' => 'Pending approval',
    'approved' => 'Approved',
    'completed' => 'Verified at gate',
    'declined' => 'Declined',
    'all' => 'All',
];

$errorsFlat = [];
foreach ($errors as $fieldErrors) {
    foreach ((array) $fieldErrors as $message) {
        $errorsFlat[] = (string) $message;
    }
}
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">Goods in and out</h1>
    <p class="admin-page__lede">
      Tenants request goods check-in/check-out for their household. Approve or decline here; the guard
      then verifies the approved goods at the gate.
    </p>
  </div>
</div>

<?php if ($errorsFlat !== []): ?>
  <div class="alert alert--error" role="alert">
    <?= admin_icon('alert', 'ico ico--sm') ?>
    <div class="alert__body">
      <p class="alert__title">Goods request not updated</p>
      <?php foreach ($errorsFlat as $message): ?><p><?= e($message) ?></p><?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<section class="admin-section" aria-labelledby="goods-list-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="goods-list-title">Goods requests</h2>
      <p class="admin-section__hint">Goods out is the theft-control step: only approved movements may leave the estate.</p>
    </div>
    <span class="chip"><?= e((string) count($movements)) ?></span>
  </div>

  <div class="admin-tabs" role="tablist" aria-label="Goods request filters">
    <?php foreach ($filters as $value => $label): ?>
      <a class="admin-tab<?= $statusFilter === $value ? ' admin-tab--active' : '' ?>"
         href="/admin/goods?status=<?= e(rawurlencode($value)) ?>"
         <?= $statusFilter === $value ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($movements === []): ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('list') ?></span>
      <p class="admin-empty__title">No goods requests in this view</p>
      <p class="admin-empty__text">Tenant requests appear here for approval.</p>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Reference</th>
            <th scope="col">Movement</th>
            <th scope="col">Goods</th>
            <th scope="col">Resident / unit</th>
            <th scope="col">Carrier / plate</th>
            <th scope="col">Expected</th>
            <th scope="col">Status</th>
            <?php if ($canManage): ?><th scope="col">Decision</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($movements as $movement): ?>
            <?php
            $uuid = (string) ($movement['uuid'] ?? '');
            $status = (string) ($movement['status'] ?? 'pending');
            $tone = match ((string) ($movement['status_tone'] ?? 'warn')) {
                'ok' => 'status--active',
                'danger' => 'status--danger',
                default => 'status--pending',
            };
            $expected = trim((string) ($movement['expected_at'] ?? ''));
            ?>
            <tr>
              <td data-label="Reference"><code><?= e((string) ($movement['reference_code'] ?? '')) ?></code></td>
              <td data-label="Movement"><?= e((string) ($movement['direction_label'] ?? '')) ?></td>
              <td data-label="Goods">
                <?= e((string) ($movement['description'] ?? '')) ?>
                <span class="muted">(x<?= e((string) ($movement['quantity'] ?? 1)) ?>)</span>
              </td>
              <td data-label="Resident / unit">
                <?= e((string) ($movement['resident_name'] ?? '')) ?><br>
                <span class="muted"><?= e((string) ($movement['household_label'] ?? '')) ?></span>
              </td>
              <td data-label="Carrier / plate">
                <?= e(trim((string) ($movement['carrier_name'] ?? '')) ?: '-') ?>
                <?php if (($movement['vehicle_plate'] ?? null) !== null): ?><br><span class="muted"><?= e((string) $movement['vehicle_plate']) ?></span><?php endif; ?>
              </td>
              <td data-label="Expected"><?= e($expected !== '' ? $expected : '-') ?></td>
              <td data-label="Status"><span class="status <?= e($tone) ?>"><?= e((string) ($movement['status_label'] ?? '')) ?></span></td>
              <?php if ($canManage): ?>
                <td data-label="Decision">
                  <?php if ($status === 'pending'): ?>
                    <form method="post" action="/admin/goods/<?= e(rawurlencode($uuid)) ?>/approve" class="admin-inline-form">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn--primary btn--sm">Approve</button>
                    </form>
                    <form method="post" action="/admin/goods/<?= e(rawurlencode($uuid)) ?>/decline" class="admin-inline-form">
                      <?= csrf_field() ?>
                      <input class="input input--sm" name="decision_note" type="text" maxlength="500" required placeholder="Reason for decline" aria-label="Decline reason">
                      <button type="submit" class="btn btn--outline btn--sm">Decline</button>
                    </form>
                  <?php else: ?>
                    <span class="muted"><?= e(trim((string) ($movement['decided_by_name'] ?? '')) ?: '-') ?></span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
