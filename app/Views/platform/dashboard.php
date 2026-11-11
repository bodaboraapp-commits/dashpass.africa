<?php

declare(strict_types=1);

/**
 * Platform dashboard (platform-admin contract §3).
 *
 * Real platform stats, permission-filtered management shortcuts and the platform
 * audit viewer (dashboard-only audit — there is no separate /platform/audit
 * route). Absent data renders honest empty states, never invented figures.
 *
 * Variables (T64 frozen keys, defensive): $stats {accounts, estates,
 * estates_active, estates_suspended, estates_archived, active_subscriptions,
 * plans}, $recent_audit|$audit [], $permissions + platform shell data.
 */

require_once __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$timezone = admin_timezone($estate);
$stats = isset($stats) && is_array($stats) ? $stats : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canAudit = in_array('platform.audit.view', array_map('strval', $permissions), true);
$canEstates = in_array('platform.estate.manage', array_map('strval', $permissions), true);
$canPlans = in_array('platform.plan.manage', array_map('strval', $permissions), true);
$canSubscriptions = in_array('platform.subscription.manage', array_map('strval', $permissions), true);

// T102: platform shell carries platform.* only, so billing capability comes from
// the operator's platform roles; the billing route still enforces billing.*.
$platformRoles = isset($platform_roles) && is_array($platform_roles) ? array_map('strval', $platform_roles) : [];
$canBillingRole = in_array('platform_super_admin', $platformRoles, true)
    || in_array('platform_finance', $platformRoles, true);
// Inc-10 gate: keep the role mapping ready but do not link to /platform/billing
// until its routes ship. Flip to true at the Inc-10 gate (T78 phase 2).
$billingLinkEnabled = false;
$canBilling = $billingLinkEnabled
    && (in_array('billing.invoice.view', array_map('strval', $permissions), true) || $canBillingRole);

// T64 also passes permission-filtered quick links; use them to fill gaps.
$quickLinks = isset($quick_links) && is_array($quick_links)
    ? array_values(array_filter($quick_links, 'is_array'))
    : [];

// T64 stats expose active/suspended/archived counts but no total: derive it
// defensively so the "Total estates" card appears on the landed payload.
if (!array_key_exists('estates', $stats)
    && !array_key_exists('estate_count', $stats)
    && !array_key_exists('total_estates', $stats)
    && !array_key_exists('estates_total', $stats)
) {
    $estateParts = [];
    foreach (['estates_active', 'estates_suspended', 'estates_archived'] as $estateKey) {
        if (isset($stats[$estateKey]) && is_numeric($stats[$estateKey])) {
            $estateParts[] = (int) $stats[$estateKey];
        }
    }
    if (count($estateParts) === 3) {
        $stats['estates_total'] = array_sum($estateParts);
    }
}

$auditRows = isset($recent_audit) && is_array($recent_audit) ? $recent_audit : [];
if ($auditRows === [] && isset($audit) && is_array($audit)) {
    $auditRows = $audit;
}
$auditRows = array_values(array_filter($auditRows, 'is_array'));

$statCards = [
    ['label' => 'Accounts', 'keys' => ['accounts', 'account_count', 'total_accounts'], 'icon' => 'building'],
    ['label' => 'Total estates', 'keys' => ['estates_total', 'estates', 'estate_count', 'total_estates'], 'icon' => 'home'],
    ['label' => 'Active estates', 'keys' => ['estates_active', 'active_estates'], 'icon' => 'check'],
    ['label' => 'Suspended', 'keys' => ['estates_suspended', 'suspended_estates'], 'icon' => 'info'],
    ['label' => 'Archived', 'keys' => ['estates_archived', 'archived_estates'], 'icon' => 'inbox'],
    ['label' => 'Active subscriptions', 'keys' => ['active_subscriptions', 'subscriptions_active'], 'icon' => 'activity'],
    ['label' => 'Plans', 'keys' => ['plans', 'plan_count', 'total_plans'], 'icon' => 'wallet'],
];
$cards = [];
foreach ($statCards as $card) {
    $value = platform_stat($stats, $card['keys']);
    if ($value !== null) {
        $card['value'] = $value;
        $cards[] = $card;
    }
}

$links = [
    ['href' => '/platform/estates', 'title' => 'Estates', 'icon' => 'building', 'text' => 'Review estates and provision new ones.', 'visible' => $canEstates],
    ['href' => '/platform/estates/new', 'title' => 'Provision estate', 'icon' => 'plus', 'text' => 'Create an estate, its first admin and a subscription.', 'visible' => $canEstates],
    ['href' => '/platform/plans', 'title' => 'Plans', 'icon' => 'wallet', 'text' => 'Create and retire subscription plans.', 'visible' => $canPlans],
    ['href' => '/platform/subscriptions', 'title' => 'Subscriptions', 'icon' => 'activity', 'text' => 'Change estate plans and subscription status.', 'visible' => $canSubscriptions],
    ['href' => '/platform/billing', 'title' => 'Billing & invoices', 'icon' => 'ticket', 'text' => 'Review invoices, record payments and chase past-due balances.', 'visible' => $canBilling],
];

// T64 quick_links (permission-filtered server-side): append anything not already
// covered by the richer local cards, so future platform destinations still appear.
if ($quickLinks !== []) {
    $knownHrefs = array_map(static fn (array $link): string => (string) $link['href'], $links);
    $quickIcons = [
        '/platform/estates' => 'building',
        '/platform/plans' => 'wallet',
        '/platform/subscriptions' => 'activity',
        '/platform/billing' => 'ticket',
    ];
    foreach ($quickLinks as $quickLink) {
        $quickHref = trim((string) ($quickLink['href'] ?? ''));
        $quickLabel = trim((string) ($quickLink['label'] ?? ''));
        if ($quickHref === '' || $quickLabel === '' || in_array($quickHref, $knownHrefs, true)) {
            continue;
        }
        if (!$billingLinkEnabled && str_starts_with($quickHref, '/platform/billing')) {
            continue; // Inc-10 gate: never link to unrouted billing pages.
        }
        $links[] = [
            'href' => $quickHref,
            'title' => $quickLabel,
            'icon' => $quickIcons[$quickHref] ?? 'grid',
            'text' => 'Platform shortcut.',
            'visible' => true,
        ];
        $knownHrefs[] = $quickHref;
    }
}
?>
<div class="platform-page__head">
  <div>
    <h1 class="platform-page__title">Platform overview</h1>
    <p class="platform-page__lede">
      Estate provisioning, plans, subscriptions and the platform audit trail across <?= e((string) config('app.name', 'MtaaPass')) ?>.
    </p>
  </div>
</div>

<?php if ($cards !== []): ?>
  <div class="platform-stats">
    <?php foreach ($cards as $card): ?>
      <div class="platform-stat">
        <span class="platform-stat__icon" aria-hidden="true"><?= admin_icon($card['icon']) ?></span>
        <span class="platform-stat__value"><?= e((string) $card['value']) ?></span>
        <span class="platform-stat__label"><?= e($card['label']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="platform-empty">
    <span class="platform-empty__icon" aria-hidden="true"><?= admin_icon('grid') ?></span>
    <p class="platform-empty__title">No platform statistics available yet</p>
    <p class="platform-empty__text">Counts appear once platform accounts, estates and plans exist.</p>
  </div>
<?php endif; ?>

<section class="platform-section" aria-labelledby="platform-manage-title">
  <div class="platform-section__head">
    <h2 class="platform-section__title" id="platform-manage-title">Manage the platform</h2>
  </div>
  <div class="platform-links">
    <?php foreach ($links as $link): ?>
      <?php if (!$link['visible']) {
          continue;
      } ?>
      <a class="platform-link-card" href="<?= e($link['href']) ?>">
        <span class="platform-link-card__icon" aria-hidden="true"><?= admin_icon($link['icon']) ?></span>
        <span class="platform-link-card__body">
          <span class="platform-link-card__title"><?= e($link['title']) ?></span>
          <span class="platform-link-card__text"><?= e($link['text']) ?></span>
        </span>
        <?= admin_icon('chevron', 'ico ico--sm') ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($canAudit): ?>
  <section class="platform-section" aria-labelledby="platform-audit-title">
    <div class="platform-section__head">
      <div>
        <h2 class="platform-section__title" id="platform-audit-title">Platform audit trail</h2>
        <p class="platform-section__hint">The most recent platform-scope activity, newest first.</p>
      </div>
      <?php if ($auditRows !== []): ?>
        <span class="chip"><?= e((string) count($auditRows)) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($auditRows !== []): ?>
      <div class="platform-table-wrap">
        <table class="platform-table">
          <thead>
            <tr>
              <th scope="col">When</th>
              <th scope="col">Action</th>
              <th scope="col">Target</th>
              <th scope="col">Actor</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($auditRows as $row): ?>
              <?php
              $when = platform_text($row, ['occurred_at', 'created_at', 'timestamp']);
              $action = platform_text($row, ['action', 'event', 'event_type']);
              $summary = platform_text($row, ['summary', 'description', 'label']);
              $actor = platform_text($row, ['actor_name', 'actor', 'user_name', 'performed_by']);
              $targetType = platform_text($row, ['target_type', 'entity_type']);
              $targetLabel = platform_text($row, ['target_label', 'target', 'entity_label', 'estate_name']);
              ?>
              <tr>
                <td data-label="When"><?= e($when !== '' ? admin_datetime($when, $timezone) : '—') ?></td>
                <td data-label="Action">
                  <span class="platform-audit__action"><?= e($summary !== '' ? $summary : ($action !== '' ? platform_humanize($action) : 'Platform action')) ?></span>
                  <?php if ($action !== ''): ?>
                    <span class="platform-table__sub mono"><?= e($action) ?></span>
                  <?php endif; ?>
                </td>
                <td data-label="Target">
                  <?= e($targetLabel !== '' ? $targetLabel : ($targetType !== '' ? platform_humanize($targetType) : '—')) ?>
                </td>
                <td data-label="Actor"><?= e($actor !== '' ? $actor : '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="platform-empty">
        <span class="platform-empty__icon" aria-hidden="true"><?= admin_icon('activity') ?></span>
        <p class="platform-empty__title">No platform activity recorded yet</p>
        <p class="platform-empty__text">Estate provisioning, plan changes and subscription updates appear here with their audit action.</p>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
