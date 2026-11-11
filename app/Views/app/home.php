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
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
// Household services shortcuts mirror the route permissions (Increment 6).
$canStaffDirectory = in_array('household.staff.manage', $permissions, true);
$canReportIncident = in_array('visitor.incident.create', $permissions, true);
$appVersion = isset($appVersion) ? (string) $appVersion : (string) config('app.version', '0');
$assetVersion = rawurlencode($appVersion);

$estateName = trim((string) ($summary['estate_name'] ?? $shellEstate['estate_name'] ?? ''));
if ($estateName === '') {
    $estateName = 'Your estate';
}
$roleName = trim((string) ($summary['role_name'] ?? $shellEstate['role_name'] ?? ''));
$estateType = trim((string) ($summary['estate_type'] ?? $shellEstate['estate_type'] ?? ''));
$timezone = trim((string) ($summary['estate_timezone'] ?? $shellEstate['estate_timezone'] ?? $shellEstate['timezone'] ?? ''));
$planName = trim((string) ($summary['plan_name'] ?? ''));

// Time-based greeting (email-pwa-greeting contract §3). Server-rendered so it
// works without JavaScript; estate-local timezone with a safe night fallback.
$greeting = 'Hello,';
$greetingTimezone = $timezone !== '' ? $timezone : (string) config('app.timezone', 'Africa/Nairobi');
if (trim($greetingTimezone) === '') {
    $greetingTimezone = 'Africa/Nairobi';
}
try {
    $localNow = \App\Core\Clock::now()->setTimezone(new \DateTimeZone($greetingTimezone));
    $localHour = (int) $localNow->format('G');
    $greeting = match (true) {
        $localHour >= 5 && $localHour <= 11 => 'Good morning,',
        $localHour >= 12 && $localHour <= 16 => 'Good afternoon,',
        $localHour >= 17 && $localHour <= 21 => 'Good evening,',
        default => 'Hello,',
    };
} catch (\Throwable) {
    // Invalid/unknown timezone: keep the neutral greeting rather than failing.
    $greeting = 'Hello,';
}

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
    ['primary' => 'Vehicles', 'secondary' => 'My Vehicles', 'icon' => 'car', 'kind' => 'link', 'href' => '/app/vehicles', 'soon' => ''],
    ['primary' => 'Bookings', 'secondary' => 'Facilities', 'icon' => 'calendar', 'kind' => 'link', 'href' => '/app/bookings', 'soon' => ''],
    ['primary' => 'Payments', 'secondary' => '& Bills', 'icon' => 'wallet', 'kind' => 'soon', 'href' => '', 'soon' => 'Payments and bills arrive with the Billing module.'],
    ['primary' => 'Notices', 'secondary' => '& Updates', 'icon' => 'bell', 'kind' => 'link', 'href' => '/app/notices', 'soon' => ''],
];

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="resident">
  <header class="home-head">
    <div class="home-head__greeting">
      <p class="home-head__hello"><?= e($greeting) ?></p>
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
          <span class="quick-tile__text">
            <span class="quick-tile__primary"><?= e($action['primary']) ?></span>
            <span class="quick-tile__secondary"><?= e($action['secondary']) ?></span>
          </span>
        </a>
      <?php elseif ($action['kind'] === 'qr'): ?>
        <button type="button" class="quick-tile" data-qr-open aria-haspopup="dialog" aria-controls="qr-sheet">
          <span class="quick-tile__icon" aria-hidden="true"><?= $icon($action['icon']) ?></span>
          <span class="quick-tile__text">
            <span class="quick-tile__primary"><?= e($action['primary']) ?></span>
            <span class="quick-tile__secondary"><?= e($action['secondary']) ?></span>
          </span>
        </button>
      <?php else: ?>
        <button type="button" class="quick-tile quick-tile--soon" data-soon="<?= e($action['soon']) ?>"
                aria-disabled="true">
          <span class="quick-tile__icon" aria-hidden="true"><?= $icon($action['icon']) ?></span>
          <span class="quick-tile__text">
            <span class="quick-tile__primary"><?= e($action['primary']) ?></span>
            <span class="quick-tile__secondary"><?= e($action['secondary']) ?></span>
          </span>
        </button>
      <?php endif; ?>
    <?php endforeach; ?>
  </section>

  <?php if ($canStaffDirectory || $canReportIncident): ?>
    <section class="resident-section" aria-labelledby="household-services-title">
      <div class="resident-section__head">
        <div>
          <h2 class="resident-section__title" id="household-services-title">Household services</h2>
          <p class="resident-section__hint">Household staff directory and security incident reporting.</p>
        </div>
      </div>
      <div class="home-links">
        <?php if ($canStaffDirectory): ?>
          <a class="home-link" href="/app/staff">
            <span class="home-link__icon" aria-hidden="true"><?= $icon('users') ?></span>
            <span class="home-link__title">Household staff</span>
            <span class="home-link__sub">Directory &amp; roles</span>
          </a>
        <?php endif; ?>
        <?php if ($canReportIncident): ?>
          <a class="home-link" href="/app/incidents/new">
            <span class="home-link__icon" aria-hidden="true"><?= $icon('alert') ?></span>
            <span class="home-link__title">Report incident</span>
            <span class="home-link__sub">Security &amp; access</span>
          </a>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php
  // Real notices come from the estate notices feed (published, unexpired, ≤3).
  // Preview mode keeps the labelled demo announcements so layout review never
  // shows demo content once real notices exist.
  $realAnnouncements = array_values(array_filter(
      $announcements,
      static fn (mixed $announcement): bool => is_array($announcement)
          && trim((string) ($announcement['uuid'] ?? '')) !== ''
  ));
  $hasRealAnnouncements = $realAnnouncements !== [];
  $realAnnouncements = array_slice($realAnnouncements, 0, 3);
  ?>
  <section class="resident-section" aria-labelledby="announcements-title">
    <div class="resident-section__head">
      <h2 class="resident-section__title" id="announcements-title">Announcements</h2>
      <?php if ($hasRealAnnouncements || (bool) ($announcements_real ?? false) || !$preview): ?>
        <a class="text-action" href="/app/notices">View All</a>
      <?php else: ?>
        <button type="button" class="text-action" data-soon="The full notices screen arrives with the Notices module.">View All</button>
      <?php endif; ?>
    </div>

    <?php if ($hasRealAnnouncements): ?>
      <div class="announce-list">
        <?php foreach ($realAnnouncements as $announcement): ?>
          <?php
          $noticeUuid = trim((string) ($announcement['uuid'] ?? ''));
          $noticeTitle = trim((string) ($announcement['title'] ?? 'Estate notice'));
          $noticeExcerpt = trim((string) ($announcement['body_excerpt'] ?? $announcement['excerpt'] ?? ''));
          if ($noticeExcerpt === '') {
              $noticeExcerpt = trim((string) ($announcement['body'] ?? ''));
          }
          $categoryLabel = trim((string) ($announcement['category_label'] ?? ''));
          if ($categoryLabel === '') {
              $categoryLabel = ucwords(str_replace('_', ' ', trim((string) ($announcement['category'] ?? ''))));
          }
          $priorityLabel = trim((string) ($announcement['priority_label'] ?? ''));
          if ($priorityLabel === '') {
              $priorityLabel = ucwords(str_replace('_', ' ', trim((string) ($announcement['priority'] ?? ''))));
          }
          $priorityTone = trim((string) ($announcement['priority_tone'] ?? ''));
          $publishedAt = isset($announcement['published_at']) ? (string) $announcement['published_at'] : '';
          $priorityClass = match ($priorityTone) {
              'ok' => 'status--active',
              'warn' => 'status--pending',
              'danger' => 'status--danger',
              'neutral' => 'status--inactive',
              default => trim((string) ($announcement['priority'] ?? '')) === 'urgent' ? 'status--danger' : (trim((string) ($announcement['priority'] ?? '')) === 'important' ? 'status--pending' : 'status--inactive'),
          };
          ?>
          <article class="announce-card announce-card--real">
            <div class="announce-card__meta">
              <?php if ($priorityLabel !== ''): ?>
                <span class="status <?= e($priorityClass) ?>"><?= e($priorityLabel) ?></span>
              <?php endif; ?>
              <?php if ($categoryLabel !== ''): ?>
                <span class="chip chip--outline"><?= e($categoryLabel) ?></span>
              <?php endif; ?>
              <?php if ($publishedAt !== ''): ?>
                <span class="announce-card__date"><?= $icon('clock', 'ico ico--xs') ?> <?= e(format_datetime($publishedAt, $timezone, 'd M Y')) ?></span>
              <?php endif; ?>
            </div>
            <h3 class="announce-card__title">
              <a class="announce-card__link" href="/app/notices/<?= e(rawurlencode($noticeUuid)) ?>"><?= e($noticeTitle) ?></a>
            </h3>
            <?php if ($noticeExcerpt !== ''): ?>
              <p class="announce-card__body"><?= e($noticeExcerpt) ?></p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if (count($realAnnouncements) > 1): ?>
        <div class="announcement-dots" aria-hidden="true">
          <?php for ($dot = 0; $dot < count($realAnnouncements); $dot++): ?>
            <span class="dot<?= $dot === 0 ? ' dot--active' : '' ?>"></span>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php elseif ($preview && $announcements !== []): ?>
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
