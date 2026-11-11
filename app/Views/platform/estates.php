<?php

declare(strict_types=1);

/**
 * Platform estates list (platform-admin contract §3).
 *
 * Estate table with status/account/plan/admin summaries (phone masked) and
 * status/search filters. Provisioning CTA only with platform.estate.manage.
 *
 * Variables (T64 frozen keys, defensive): $estates [], $filters {status,q},
 * $status_options [], $errors [], $old [] + platform shell data.
 */

require_once __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$estates = isset($estates) && is_array($estates) ? $estates : [];
$filters = isset($filters) && is_array($filters) ? $filters : [];
$statusOptions = isset($status_options) && is_array($status_options) ? $status_options : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = in_array('platform.estate.manage', array_map('strval', $permissions), true);

$statusMap = platform_option_map($statusOptions, [
    'active' => 'Active',
    'suspended' => 'Suspended',
    'archived' => 'Archived',
]);
$currentStatus = trim((string) ($filters['status'] ?? ''));
$hasFilters = $currentStatus !== '';
$rows = array_values(array_filter($estates, 'is_array'));
?>
<div class="platform-page__head">
  <div>
    <h1 class="platform-page__title">Estates</h1>
    <p class="platform-page__lede">
      Every estate provisioned on the platform, with its account, plan and estate administrator.
    </p>
  </div>
  <?php if ($canManage): ?>
    <div class="platform-page__actions">
      <a class="btn btn--primary" href="/platform/estates/new"><?= admin_icon('plus', 'ico ico--sm') ?> Provision estate</a>
    </div>
  <?php endif; ?>
</div>

<section class="platform-section" aria-labelledby="estates-list-title">
  <div class="platform-section__head">
    <h2 class="platform-section__title" id="estates-list-title">All estates</h2>
    <?php if ($rows !== []): ?>
      <span class="chip"><?= e((string) count($rows)) ?> shown</span>
    <?php endif; ?>
  </div>

  <form method="get" action="/platform/estates" class="platform-form platform-filters">
    <div class="platform-form__grid platform-form__grid--inline">
      <div class="platform-field">
        <label class="platform-field__label" for="estate_filter_status">Status</label>
        <select class="select" id="estate_filter_status" name="status">
          <option value="">All statuses</option>
          <?php foreach ($statusMap as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $currentStatus === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="platform-form__actions">
        <button type="submit" class="btn btn--outline btn--sm"><?= admin_icon('refresh', 'ico ico--sm') ?> Apply filters</button>
        <?php if ($hasFilters): ?>
          <a class="btn btn--ghost btn--sm" href="/platform/estates">Clear filters</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($rows !== []): ?>
    <div class="platform-table-wrap">
      <table class="platform-table">
        <thead>
          <tr>
            <th scope="col">Estate</th>
            <th scope="col">Status</th>
            <th scope="col">Account</th>
            <th scope="col">Plan</th>
            <th scope="col">Admin</th>
            <th scope="col">Created</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
            $uuid = platform_text($row, ['uuid']);
            $name = platform_text($row, ['name', 'estate_name'], 'Estate');
            $slug = platform_text($row, ['slug']);
            $status = platform_text($row, ['status'], 'active');
            $statusLabel = platform_text($row, ['status_label']) ?: ($statusMap[$status] ?? platform_humanize($status));
            $statusTone = platform_text($row, ['status_tone']);
            $accountName = platform_text($row, ['account_name', 'account_label']);
            $planName = platform_text($row, ['plan_name', 'plan']);
            $adminName = platform_text($row, ['admin_name', 'estate_admin_name', 'owner_name']);
            $adminPhone = platform_text($row, ['admin_phone_masked', 'admin_phone']);
            $createdAt = platform_text($row, ['created_at', 'provisioned_at']);
            ?>
            <tr>
              <td data-label="Estate">
                <?php if ($uuid !== ''): ?>
                  <a class="platform-table__link" href="/platform/estates/<?= e(rawurlencode($uuid)) ?>"><?= e($name) ?></a>
                <?php else: ?>
                  <?= e($name) ?>
                <?php endif; ?>
                <?php if ($slug !== ''): ?>
                  <span class="platform-table__sub mono"><?= e($slug) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Status"><span class="status <?= e(admin_tone_class($statusTone, $status)) ?>"><?= e($statusLabel) ?></span></td>
              <td data-label="Account"><?= e($accountName !== '' ? $accountName : '—') ?></td>
              <td data-label="Plan"><?= e($planName !== '' ? $planName : '—') ?></td>
              <td data-label="Admin">
                <?= e($adminName !== '' ? $adminName : '—') ?>
                <?php if ($adminPhone !== ''): ?>
                  <span class="platform-table__sub mono"><?= e($adminPhone) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Created"><?= e($createdAt !== '' ? admin_datetime($createdAt, $timezone, 'd M Y') : '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="platform-empty">
      <span class="platform-empty__icon" aria-hidden="true"><?= admin_icon('building') ?></span>
      <p class="platform-empty__title"><?= $hasFilters ? 'No estates match these filters' : 'No estates provisioned yet' ?></p>
      <p class="platform-empty__text">
        <?php if ($hasFilters): ?>
          Try clearing the filters to see every estate on the platform.
        <?php else: ?>
          Provision the first estate to create its administrator, settings and subscription.
        <?php endif; ?>
      </p>
      <?php if ($canManage && !$hasFilters): ?>
        <a class="btn btn--primary" href="/platform/estates/new"><?= admin_icon('plus', 'ico ico--sm') ?> Provision estate</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
