<?php

declare(strict_types=1);

/**
 * Bookings screen (addendum §2C).
 *
 * Facilities is the default segmented tab (My Bookings is reachable via
 * `?tab=bookings`). Tabs are server-rendered links and work without JavaScript.
 * Facility rows use local CSS/icon art — no external images. Preview content is
 * clearly labelled; My Bookings is an honest empty state this increment.
 *
 * Variables (addendum §4): $preview, $bookings_tab, $facilities, $bookings,
 * plus shell data.
 */

$preview = (bool) ($preview ?? false);
$activeTab = isset($bookings_tab) && $bookings_tab === 'bookings' ? 'bookings' : 'facilities';
$facilities = isset($facilities) && is_array($facilities) ? $facilities : [];
$bookings = isset($bookings) && is_array($bookings) ? $bookings : [];

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

// Facility art is CSS + inline SVG keyed by a small allow-list (never a raw
// class name from data).
$facilityIcons = [
    'room' => 'room',
    'bbq' => 'bbq',
    'gym' => 'gym',
    'court' => 'court',
];
$toneClass = static function (string $tone): string {
    return $tone === 'warn' ? 'facility-status--warn' : 'facility-status--ok';
};
?>
<div class="resident resident--pushed">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">Bookings</h1>
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= $icon('arrow-up-right', 'ico') ?>
    </a>
  </header>

  <div class="seg-tabs" role="group" aria-label="Booking lists">
    <a class="seg-tab<?= $activeTab === 'bookings' ? ' seg-tab--active' : '' ?>"
       href="/app/bookings?tab=bookings"
       <?= $activeTab === 'bookings' ? 'aria-current="page"' : '' ?>>My Bookings</a>
    <a class="seg-tab<?= $activeTab === 'facilities' ? ' seg-tab--active' : '' ?>"
       href="/app/bookings"
       <?= $activeTab === 'facilities' ? 'aria-current="page"' : '' ?>>Facilities</a>
  </div>

  <?php if ($preview): ?>
    <p class="preview-note">
      <span class="chip chip--accent">Layout preview</span>
      <span>Facility bookings ship in the Bookings increment.</span>
    </p>
  <?php endif; ?>

  <?php if ($activeTab === 'facilities'): ?>
    <?php if ($facilities !== []): ?>
      <ul class="facility-list">
        <?php foreach ($facilities as $facility): ?>
          <?php
          if (!is_array($facility)) {
              continue;
          }
          $facilityName = trim((string) ($facility['name'] ?? 'Facility'));
          $status = trim((string) ($facility['status'] ?? 'Available'));
          $tone = trim((string) ($facility['tone'] ?? 'ok'));
          $iconName = $facilityIcons[trim((string) ($facility['icon'] ?? ''))] ?? 'room';
          ?>
          <li class="facility-card">
            <span class="facility-card__thumb facility-card__thumb--<?= e($iconName) ?>" aria-hidden="true">
              <?= $icon($iconName) ?>
            </span>
            <span class="facility-card__body">
              <span class="facility-card__name"><?= e($facilityName) ?></span>
              <span class="facility-status <?= e($toneClass($tone)) ?>">
                <span class="facility-status__dot" aria-hidden="true"></span>
                <?= e($status) ?>
              </span>
            </span>
            <span class="facility-card__chevron" aria-hidden="true"><?= $icon('chevron', 'ico ico--sm') ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="resident-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('calendar') ?></span>
        <p class="resident-empty__title">No facilities listed yet</p>
        <p class="resident-empty__text">Bookable estate facilities will appear here once the Bookings module is enabled.</p>
      </div>
    <?php endif; ?>
  <?php elseif ($bookings !== []): ?>
    <ul class="booking-list">
      <?php foreach ($bookings as $booking): ?>
        <?php
        if (!is_array($booking)) {
            continue;
        }
        $bookingName = trim((string) ($booking['facility'] ?? $booking['name'] ?? 'Booking'));
        $bookingWhen = trim((string) ($booking['when'] ?? ''));
        ?>
        <li class="facility-card">
          <span class="facility-card__thumb facility-card__thumb--room" aria-hidden="true"><?= $icon('calendar') ?></span>
          <span class="facility-card__body">
            <span class="facility-card__name"><?= e($bookingName) ?></span>
            <?php if ($bookingWhen !== ''): ?>
              <span class="facility-status facility-status--ok"><?= e($bookingWhen) ?></span>
            <?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <div class="resident-empty">
      <span class="resident-empty__icon" aria-hidden="true"><?= $icon('calendar') ?></span>
      <p class="resident-empty__title">No bookings yet</p>
      <p class="resident-empty__text">Your facility bookings will appear here once the Bookings module is enabled.</p>
    </div>
  <?php endif; ?>

  <div class="cta-dock">
    <button type="button" class="btn btn--primary btn--lg btn--block"
            data-soon="New facility bookings ship in the Bookings increment.">
      + New Booking
    </button>
  </div>
</div>
