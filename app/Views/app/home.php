<?php

declare(strict_types=1);

/**
 * Resident Home (addendum §2A).
 *
 * Greeting, hero estate banner, six quick-action tiles, announcements with
 * pagination dots, and a compact household/estate details card. Bottom
 * navigation (including the centre QR button) is rendered by the app layout.
 *
 * Variables: shell data + $dashboard (contract §8.1), $preview (bool),
 * $announcements (addendum §4). Everything is defensive — partial integration
 * data must still render without notices.
 */

$dashboard = isset($dashboard) && is_array($dashboard) ? $dashboard : [];
$summary = isset($dashboard['estate']) && is_array($dashboard['estate']) ? $dashboard['estate'] : [];
$shellEstate = isset($estate) && is_array($estate) ? $estate : [];
$households = isset($dashboard['households']) && is_array($dashboard['households']) ? $dashboard['households'] : [];
$gates = isset($dashboard['gates']) && is_array($dashboard['gates']) ? $dashboard['gates'] : [];
$memberships = (isset($memberships) && is_array($memberships) && $memberships !== [])
    ? $memberships
    : (isset($dashboard['memberships']) && is_array($dashboard['memberships']) ? $dashboard['memberships'] : []);
$announcements = isset($announcements) && is_array($announcements)
    ? $announcements
    : (isset($dashboard['announcements']) && is_array($dashboard['announcements']) ? $dashboard['announcements'] : []);
$preview = (bool) ($preview ?? false);
$appVersion = isset($appVersion) ? (string) $appVersion : (string) config('app.version', '0');
$assetVersion = rawurlencode($appVersion);

$estateName = trim((string) ($summary['estate_name'] ?? $shellEstate['estate_name'] ?? ''));
if ($estateName === '') {
    $estateName = 'Your estate';
}
$roleName = trim((string) ($summary['role_name'] ?? $shellEstate['role_name'] ?? ''));
$estateType = trim((string) ($summary['estate_type'] ?? $shellEstate['estate_type'] ?? ''));
$timezone = trim((string) ($summary['estate_timezone'] ?? $shellEstate['estate_timezone'] ?? ''));
$planName = trim((string) ($summary['plan_name'] ?? ''));

$humanise = static function (string $value): string {
    return $value === '' ? '' : ucwords(str_replace(['_', '-'], ' ', $value));
};

$roleLabel = $humanise($roleName) !== '' ? $humanise($roleName) : 'Resident';
$typeLabel = $humanise($estateType) !== '' ? $humanise($estateType) : 'Estate';

// Split the estate name over at most two hero lines (e.g. Greenview / Residences).
$estateWords = preg_split('/\s+/', $estateName) ?: [];
$heroLineOne = $estateWords[0] ?? $estateName;
$heroLineTwo = count($estateWords) > 1 ? implode(' ', array_slice($estateWords, 1)) : '';

$quickActions = [
    ['primary' => 'Access', 'secondary' => 'My QR', 'icon' => 'qr', 'kind' => 'qr', 'href' => '', 'soon' => ''],
    ['primary' => 'Visitors', 'secondary' => 'Pre-Register', 'icon' => 'users', 'kind' => 'link', 'href' => '/app/visitors/invite', 'soon' => ''],
    ['primary' => 'Vehicles', 'secondary' => 'My Vehicles', 'icon' => 'car', 'kind' => 'soon', 'href' => '', 'soon' => 'Vehicle registration arrives with the Vehicles module.'],
    ['primary' => 'Bookings', 'secondary' => 'Facilities', 'icon' => 'calendar', 'kind' => 'link', 'href' => '/app/bookings', 'soon' => ''],
    ['primary' => 'Payments', 'secondary' => '& Bills', 'icon' => 'wallet', 'kind' => 'soon', 'href' => '', 'soon' => 'Payments and bills arrive with the Billing module.'],
    ['primary' => 'Notices', 'secondary' => '& Updates', 'icon' => 'bell', 'kind' => 'soon', 'href' => '', 'soon' => 'Estate notices arrive with the Notices module.'],
];

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="resident">
  <header class="home-head">
    <div class="home-head__greeting">
      <p class="home-head__hello">Hello,</p>
      <h1 class="home-head__title">Welcome Home</h1>
    </div>
    <div class="home-head__actions">
      <button type="button" class="icon-btn" data-soon="Notifications arrive with the Notifications module."
              aria-label="Notifications" title="Notifications">
        <?= $icon('bell', 'ico') ?>
      </button>
    </div>
  </header>

  <section class="hero-estate" aria-labelledby="hero-estate-name">
    <img class="hero-estate__img" src="/assets/img/estate-hero.png?v=<?= e($assetVersion) ?>"
         alt="" width="1600" height="900" decoding="async">
    <div class="hero-estate__shade" aria-hidden="true"></div>
    <div class="hero-estate__body">
      <h2 class="hero-estate__name" id="hero-estate-name">
        <span class="hero-estate__line"><?= e($heroLineOne) ?></span>
        <?php if ($heroLineTwo !== ''): ?>
          <span class="hero-estate__line"><?= e($heroLineTwo) ?></span>
        <?php endif; ?>
      </h2>
      <p class="hero-estate__meta"><?= e($typeLabel) ?><?php if ($gates !== []): ?> · <?= e((string) count($gates)) ?> gates<?php endif; ?></p>
    </div>
    <a class="hero-estate__go" href="/app/estates" aria-label="View and switch estates" title="View and switch estates">
      <?= $icon('chevron', 'ico') ?>
    </a>
  </section>

  <section class="quick-grid" aria-label="Quick actions">
    <?php foreach ($quickActions as $action): ?>
      <?php if ($action['kind'] === 'link'): ?>
        <a class="quick-tile" href="<?= e($action['href']) ?>">
          <span class="quick-tile__icon" aria-hidden="true"><?= $icon($action['icon']) ?></span>
          <span class="quick-tile__primary"><?= e($action['primary']) ?></span>
          <span class="quick-tile__secondary"><?= e($action['secondary']) ?></span>
        </a>
      <?php elseif ($action['kind'] === 'qr'): ?>
        <button type="button" class="quick-tile" data-qr-open aria-haspopup="dialog" aria-controls="qr-sheet">
          <span class="quick-tile__icon" aria-hidden="true"><?= $icon($action['icon']) ?></span>
          <span class="quick-tile__primary"><?= e($action['primary']) ?></span>
          <span class="quick-tile__secondary"><?= e($action['secondary']) ?></span>
        </button>
      <?php else: ?>
        <button type="button" class="quick-tile quick-tile--soon" data-soon="<?= e($action['soon']) ?>"
                aria-disabled="true">
          <span class="quick-tile__icon" aria-hidden="true"><?= $icon($action['icon']) ?></span>
          <span class="quick-tile__primary"><?= e($action['primary']) ?></span>
          <span class="quick-tile__secondary"><?= e($action['secondary']) ?></span>
        </button>
      <?php endif; ?>
    <?php endforeach; ?>
  </section>

  <section class="resident-section" aria-labelledby="announcements-title">
    <div class="resident-section__head">
      <h2 class="resident-section__title" id="announcements-title">Announcements</h2>
      <button type="button" class="text-action" data-soon="Estate notices arrive with the Notices module.">View All</button>
    </div>

    <?php if ($announcements !== []): ?>
      <div class="announce-list">
        <?php foreach ($announcements as $announcement): ?>
          <?php
          if (!is_array($announcement)) {
              continue;
          }
          $noticeTitle = trim((string) ($announcement['title'] ?? 'Estate notice'));
          $noticeBody = trim((string) ($announcement['body'] ?? $announcement['message'] ?? ''));
          ?>
          <article class="announce-card">
            <h3 class="announce-card__title"><?= e($noticeTitle) ?></h3>
            <?php if ($noticeBody !== ''): ?>
              <p class="announce-card__body"><?= e($noticeBody) ?></p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if (count($announcements) > 1): ?>
        <div class="announcement-dots" aria-hidden="true">
          <?php for ($dot = 0; $dot < count($announcements); $dot++): ?>
            <span class="dot<?= $dot === 0 ? ' dot--active' : '' ?>"></span>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="resident-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('bell') ?></span>
        <p class="resident-empty__title">No estate notices yet</p>
        <p class="resident-empty__text">When your estate team shares an update it will appear here.</p>
      </div>
    <?php endif; ?>
  </section>

  <section class="resident-section" aria-labelledby="details-title">
    <div class="resident-section__head">
      <h2 class="resident-section__title" id="details-title">Your estate</h2>
      <a class="text-action" href="/app/estates">Details <?= $icon('chevron', 'ico ico--sm') ?></a>
    </div>

    <div class="detail-card">
      <div class="detail-line">
        <span class="detail-line__icon" aria-hidden="true"><?= $icon('shield', 'ico ico--sm') ?></span>
        <span class="detail-line__label">Your role</span>
        <span class="detail-line__value"><?= e($roleLabel) ?></span>
      </div>
      <div class="detail-line">
        <span class="detail-line__icon" aria-hidden="true"><?= $icon('building', 'ico ico--sm') ?></span>
        <span class="detail-line__label">Estate</span>
        <span class="detail-line__value"><?= e($typeLabel) ?><?php if ($timezone !== ''): ?> · <?= e($timezone) ?><?php endif; ?></span>
      </div>

      <?php if ($households !== []): ?>
        <?php foreach ($households as $household): ?>
          <?php
          if (!is_array($household)) {
              continue;
          }
          $unit = trim((string) ($household['unit_number'] ?? ''));
          $building = trim((string) ($household['building_name'] ?? ''));
          $relationship = trim((string) ($household['relationship'] ?? ''));
          $isPrimary = (bool) ($household['is_primary'] ?? false);
          ?>
          <div class="detail-line">
            <span class="detail-line__icon" aria-hidden="true"><?= $icon('home', 'ico ico--sm') ?></span>
            <span class="detail-line__label">Your household</span>
            <span class="detail-line__value">
              <?= e($unit !== '' ? $unit : 'Linked unit') ?>
              <?php if ($building !== ''): ?> · <?= e($building) ?><?php endif; ?>
              <?php if ($relationship !== ''): ?> · <?= e($humanise($relationship)) ?><?php endif; ?>
              <?php if ($isPrimary): ?> <span class="chip chip--accent">Primary</span><?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="detail-line">
          <span class="detail-line__icon" aria-hidden="true"><?= $icon('home', 'ico ico--sm') ?></span>
          <span class="detail-line__label">Your household</span>
          <span class="detail-line__value">No household linked yet</span>
        </div>
      <?php endif; ?>

      <?php foreach ($gates as $gate): ?>
        <?php
        if (!is_array($gate)) {
            continue;
        }
        $gateName = trim((string) ($gate['name'] ?? ''));
        $gateStatus = trim((string) ($gate['status'] ?? 'inactive'));
        ?>
        <div class="detail-line">
          <span class="detail-line__icon" aria-hidden="true"><?= $icon('gate', 'ico ico--sm') ?></span>
          <span class="detail-line__label">Gate</span>
          <span class="detail-line__value">
            <?= e($gateName !== '' ? $gateName : 'Gate') ?>
            <span class="status <?= $gateStatus === 'active' ? 'status--active' : 'status--inactive' ?>">
              <?= e($humanise($gateStatus) !== '' ? $humanise($gateStatus) : 'Unknown') ?>
            </span>
          </span>
        </div>
      <?php endforeach; ?>

      <?php if ($preview): ?>
        <p class="detail-card__note">Demo estate details shown for layout review.</p>
      <?php endif; ?>

      <?php if ($memberships !== [] && count($memberships) > 1): ?>
        <div class="detail-line">
          <span class="detail-line__icon" aria-hidden="true"><?= $icon('building', 'ico ico--sm') ?></span>
          <span class="detail-line__label">Estates</span>
          <span class="detail-line__value"><?= e((string) count($memberships)) ?> memberships <a href="/app/estates">Switch</a></span>
        </div>
      <?php endif; ?>

      <?php if ($planName !== ''): ?>
        <div class="detail-line">
          <span class="detail-line__icon" aria-hidden="true"><?= $icon('sparkle', 'ico ico--sm') ?></span>
          <span class="detail-line__label">Plan</span>
          <span class="detail-line__value"><?= e($planName) ?></span>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>
