<?php

declare(strict_types=1);

/**
 * Resident Home (addendum §2A).
 *
 * Greeting, hero estate banner (with a Details link to /app/estates), six
 * quick-action tiles and announcements with pagination dots. The estate details
 * card lives on the estates screen now (T97). Bottom navigation (including the
 * centre QR button) is rendered by the app layout.
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
    ['primary' => 'Vehicles', 'secondary' => 'My Vehicles', 'icon' => 'vehicle', 'kind' => 'link', 'href' => '/app/vehicles', 'soon' => ''],
    ['primary' => 'Nav', 'secondary' => 'WayFinder', 'icon' => 'nav', 'kind' => 'link', 'href' => '/app/navigation', 'soon' => ''],
    ['primary' => 'Bookings', 'secondary' => 'Facilities', 'icon' => 'calendar', 'kind' => 'link', 'href' => '/app/bookings', 'soon' => ''],
    ['primary' => 'Payments', 'secondary' => '& Bills', 'icon' => 'receipt', 'kind' => 'soon', 'href' => '', 'soon' => 'Payments and bills arrive with the Billing module.'],
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
      <?= \App\Core\View::capture('partials/notification-bell', [
          'notifications_enabled' => (bool) ($notifications_enabled ?? false),
          'notifications_unread_count' => (int) ($notifications_unread_count ?? 0),
          'notifications_latest' => is_array($notifications_latest ?? null) ? $notifications_latest : [],
          'csrfToken' => (string) ($csrfToken ?? ''),
          'estate' => $shellEstate,
      ]) ?>
    </div>
  </header>

  <section class="hero-estate" aria-labelledby="hero-estate-name">
    <img class="hero-estate__img" src="<?= e(asset('img/estate-hero.png')) ?>"
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
      <a class="text-action" href="/app/estates">Details <?= $icon('chevron', 'ico ico--sm') ?></a>
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

  // T173: horizontal auto-scrolling announcements carousel. Real notices (≤3)
  // win; preview mode keeps the labelled demo cards. One or two cards render a
  // static scroll-snap row (no clone, no animation); three cards get a seamless
  // duplicate track (clone hidden from AT) and JS auto-scroll with pause/IO.
  $previewAnnouncements = [];
  if (!$hasRealAnnouncements && $preview && $announcements !== []) {
      $previewAnnouncements = array_values(array_filter(
          $announcements,
          static fn (mixed $announcement): bool => is_array($announcement)
      ));
      $previewAnnouncements = array_slice($previewAnnouncements, 0, 3);
  }
  $carouselItems = $hasRealAnnouncements ? $realAnnouncements : $previewAnnouncements;
  $carouselIsReal = $hasRealAnnouncements;
  $carouselCount = count($carouselItems);
  $carouselAnimate = $carouselCount >= 2;
  $renderAnnouncementCard = static function (array $announcement, bool $isClone) use ($icon, $timezone, $carouselIsReal): string {
      $noticeUuid = trim((string) ($announcement['uuid'] ?? ''));
      $noticeTitle = trim((string) ($announcement['title'] ?? 'Estate notice'));
      $noticeExcerpt = trim((string) ($announcement['body_excerpt'] ?? $announcement['excerpt'] ?? ($announcement['body'] ?? '')));
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
      $cloneAttr = $isClone ? ' data-announce-clone-card="true"' : '';
      $linkTab = $isClone ? ' tabindex="-1"' : '';

      ob_start();
      if ($carouselIsReal) {
          ?>
          <article class="announce-card announce-card--real"<?= $isClone ? ' data-announce-clone-card="true"' : '' ?>>
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
              <a class="announce-card__link" href="/app/notices/<?= e(rawurlencode($noticeUuid)) ?>"<?= $isClone ? ' tabindex="-1"' : '' ?>><?= e($noticeTitle) ?></a>
            </h3>
            <?php if ($noticeExcerpt !== ''): ?>
              <p class="announce-card__body"><?= e($noticeExcerpt) ?></p>
            <?php endif; ?>
          </article>
          <?php
      } else {
          ?>
          <article class="announce-card"<?= $isClone ? ' data-announce-clone-card="true"' : '' ?>>
            <h3 class="announce-card__title"><?= e($noticeTitle) ?></h3>
            <?php if ($noticeExcerpt !== ''): ?>
              <p class="announce-card__body"><?= e($noticeExcerpt) ?></p>
            <?php endif; ?>
          </article>
          <?php
      }

      return (string) ob_get_clean();
  };
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

    <?php if ($carouselCount > 0): ?>
      <div class="announce-carousel<?= $carouselAnimate ? ' announce-carousel--auto' : ' announce-carousel--static' ?>"
           data-announce-carousel
           data-announce-count="<?= e((string) $carouselCount) ?>"
           data-announce-animate="<?= $carouselAnimate ? 'true' : 'false' ?>"
           data-announce-duration="32"
           role="region"
           aria-roledescription="carousel"
           aria-label="Announcements"
           tabindex="0">
        <div class="announce-track" data-announce-track>
          <?php foreach ($carouselItems as $announcement): ?>
            <?= $renderAnnouncementCard($announcement, false) ?>
          <?php endforeach; ?>
        </div>
        <?php if ($carouselAnimate): ?>
          <?php
          // Repeat the clone sequence enough times to fill at least ~2 desktop
          // viewports (10 cards at the 19.5rem desktop card width); the single
          // clone track stays aria-hidden and its links are tabindex="-1".
          $cloneRepeats = max(1, (int) ceil(10 / max(1, $carouselCount)));
          ?>
          <div class="announce-track announce-track--clone" data-announce-clone aria-hidden="true">
            <?php for ($cloneCopy = 0; $cloneCopy < $cloneRepeats; $cloneCopy++): ?>
              <?php foreach ($carouselItems as $announcement): ?>
                <?= $renderAnnouncementCard($announcement, true) ?>
              <?php endforeach; ?>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="resident-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('bell') ?></span>
        <p class="resident-empty__title">No estate notices yet</p>
        <p class="resident-empty__text">When your estate team shares an update it will appear here.</p>
      </div>
    <?php endif; ?>
  </section>

</div>
