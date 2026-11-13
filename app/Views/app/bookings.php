<?php

declare(strict_types=1);

/**
 * Bookings screen (vehicles-parking contract §4).
 *
 * Facilities tab = real visitor-bay availability for a selected window
 * (server-side window selector). My Bookings tab = the household's visitor
 * parking reservations. The old "Layout preview" content is gone; both tabs
 * render honest empty states when the estate has no bays/reservations yet.
 *
 * Variables (T21 view-data contract): $bookings_tab, $visitor_bays (alias
 * $bays), $availability_from, $availability_to, $reservations + shell data.
 */

$activeTab = isset($bookings_tab) && $bookings_tab === 'bookings' ? 'bookings' : 'facilities';
$visitorBays = [];
if (isset($visitor_bays) && is_array($visitor_bays)) {
    $visitorBays = $visitor_bays;
} elseif (isset($bays) && is_array($bays)) {
    $visitorBays = $bays;
}
$reservations = isset($reservations) && is_array($reservations) ? $reservations : [];

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estateName = trim((string) ($estate['estate_name'] ?? ''));
$timezone = trim((string) ($estate['estate_timezone'] ?? (string) config('app.timezone', 'Africa/Nairobi')));

$availabilityFrom = isset($availability_from) && is_string($availability_from) ? trim($availability_from) : '';
$availabilityTo = isset($availability_to) && is_string($availability_to) ? trim($availability_to) : '';

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$formatWhen = static function (?string $iso) use ($timezone): string {
    if (!is_string($iso) || trim($iso) === '') {
        return '-';
    }

    return format_datetime($iso, $timezone, 'd M Y, H:i');
};

// datetime-local inputs need `Y-m-d\TH:i`; accept already-local values as-is.
$formValue = static function (string $iso) use ($timezone): string {
    if ($iso === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $iso) === 1) {
        return substr($iso, 0, 16);
    }

    return format_datetime($iso, $timezone, 'Y-m-d\TH:i');
};

$bayTypeLabels = [
    'visitor' => 'Visitor bay',
    'accessible' => 'Accessible visitor bay',
    'resident' => 'Resident bay',
    'service' => 'Service bay',
];

$toneClass = static function (string $tone, string $status, bool $available): string {
    if ($available) {
        return 'status--active';
    }

    $mapped = match ($tone) {
        'ok' => 'status--active',
        'warn' => 'status--pending',
        'danger' => 'status--danger',
        'neutral' => 'status--inactive',
        default => '',
    };
    if ($mapped !== '') {
        return $mapped;
    }

    return match ($status) {
        'reserved' => 'status--pending',
        'occupied' => 'status--danger',
        'maintenance' => 'status--inactive',
        default => 'status--inactive',
    };
};

$statusText = static function (string $status, bool $available): string {
    if ($available) {
        return 'Available';
    }

    return match ($status) {
        'reserved' => 'Reserved',
        'occupied' => 'Occupied',
        'maintenance' => 'Maintenance',
        default => ucfirst($status),
    };
};

$reservationTone = static function (string $tone, string $status): string {
    $mapped = match ($tone) {
        'ok' => 'status--active',
        'warn' => 'status--pending',
        'danger' => 'status--danger',
        'neutral' => 'status--inactive',
        default => '',
    };
    if ($mapped !== '') {
        return $mapped;
    }

    return match ($status) {
        'reserved' => 'status--active',
        'checked_in' => 'status--pending',
        'completed' => 'status--inactive',
        'cancelled', 'expired' => 'status--inactive',
        default => 'status--inactive',
    };
};
?>
<div class="resident resident--pushed">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">Bookings</h1>
    <a class="screen-bar__action" href="/app/parking" aria-label="My parking" title="My parking">
      <?= $icon('parking', 'ico') ?>
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

  <?php if ($activeTab === 'facilities'): ?>
    <section class="resident-section" aria-labelledby="availability-title">
      <div class="resident-section__head">
        <div>
          <h2 class="resident-section__title" id="availability-title">Visitor parking</h2>
          <p class="resident-section__hint">
            Visitor bay availability for the selected window.
            <?php if ($estateName !== ''): ?>
              Bays at <?= e($estateName) ?>.
            <?php endif; ?>
          </p>
        </div>
        <?php if ($visitorBays !== []): ?>
          <span class="chip"><?= e((string) count($visitorBays)) ?></span>
        <?php endif; ?>
      </div>

      <form method="get" action="/app/bookings" class="availability-form">
        <input type="hidden" name="tab" value="facilities">
        <div class="field-grid">
          <div class="field">
            <label class="field__label" for="availability_from">From</label>
            <input class="input" id="availability_from" name="from" type="datetime-local"
                   value="<?= e($formValue($availabilityFrom)) ?>">
          </div>
          <div class="field">
            <label class="field__label" for="availability_to">Until</label>
            <input class="input" id="availability_to" name="to" type="datetime-local"
                   value="<?= e($formValue($availabilityTo)) ?>">
          </div>
        </div>
        <div class="availability-form__actions">
          <button type="submit" class="btn btn--outline btn--sm"><?= $icon('refresh', 'ico ico--sm') ?> Update window</button>
          <?php if ($availabilityFrom !== '' || $availabilityTo !== ''): ?>
            <p class="availability-form__window">
              <?= $icon('clock', 'ico ico--sm') ?>
              <?= e($formatWhen($availabilityFrom)) ?>, <?= e($formatWhen($availabilityTo)) ?>
            </p>
          <?php else: ?>
            <p class="availability-form__window"><?= $icon('clock', 'ico ico--sm') ?> Showing current availability.</p>
          <?php endif; ?>
        </div>
      </form>

      <?php if ($visitorBays !== []): ?>
        <ul class="bay-list">
          <?php foreach ($visitorBays as $bay): ?>
            <?php
            if (!is_array($bay)) {
                continue;
            }
            $bayCode = trim((string) ($bay['bay_code'] ?? ''));
            $zoneName = trim((string) ($bay['zone_name'] ?? ''));
            $bayType = trim((string) ($bay['bay_type'] ?? 'visitor'));
            $status = trim((string) ($bay['status'] ?? 'available'));
            $available = (bool) ($bay['available'] ?? ($status === 'available'));
            $tone = trim((string) ($bay['status_tone'] ?? ''));
            $typeLabel = $bayTypeLabels[$bayType] ?? 'Visitor bay';
            ?>
            <li class="bay-card">
              <span class="bay-card__icon" aria-hidden="true"><?= $icon('parking') ?></span>
              <div class="bay-card__body">
                <p class="bay-card__code"><?= e($bayCode !== '' ? $bayCode : 'Unnamed bay') ?></p>
                <p class="bay-card__meta">
                  <?= e($typeLabel) ?>
                  <?php if ($zoneName !== ''): ?>
                    · <?= e($zoneName) ?>
                  <?php endif; ?>
                </p>
              </div>
              <span class="status <?= e($toneClass($tone, $status, $available)) ?>">
                <?= e($statusText($status, $available)) ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="parking-note">
          <?= $icon('info', 'ico ico--sm') ?>
          Visitor bays are reserved when you invite a visitor and tick “Visitor parking required”. The bay is held for
          the invitation window and becomes occupied once the guard checks the visitor in.
        </p>
        <p class="parking-note">
          <a class="text-action" href="/app/visitors/invite"><?= $icon('plus', 'ico ico--sm') ?> Invite a visitor with parking</a>
        </p>
      <?php else: ?>
        <div class="resident-empty">
          <span class="resident-empty__icon" aria-hidden="true"><?= $icon('parking') ?></span>
          <p class="resident-empty__title">No visitor bays listed yet</p>
          <p class="resident-empty__text">
            Visitor bay availability will appear here once estate management configures parking zones and bays.
          </p>
        </div>
      <?php endif; ?>
    </section>
  <?php else: ?>
    <section class="resident-section" aria-labelledby="my-bookings-title">
      <div class="resident-section__head">
        <div>
          <h2 class="resident-section__title" id="my-bookings-title">My visitor parking</h2>
          <p class="resident-section__hint">Bay reservations created with your visitor invitations.</p>
        </div>
        <?php if ($reservations !== []): ?>
          <span class="chip"><?= e((string) count($reservations)) ?></span>
        <?php endif; ?>
      </div>

      <?php if ($reservations !== []): ?>
        <ul class="reservation-list">
          <?php foreach ($reservations as $reservation): ?>
            <?php
            if (!is_array($reservation)) {
                continue;
            }
            $invitationUuid = trim((string) ($reservation['invitation_uuid'] ?? ''));
            $visitorName = trim((string) ($reservation['visitor_name'] ?? 'Visitor'));
            $plate = trim((string) ($reservation['plate_number'] ?? ''));
            $bayCode = trim((string) ($reservation['bay_code'] ?? ''));
            $zoneName = trim((string) ($reservation['zone_name'] ?? ''));
            $status = trim((string) ($reservation['status'] ?? 'reserved'));
            $statusLabel = trim((string) ($reservation['status_label'] ?? '')) ?: ucfirst(str_replace('_', ' ', $status));
            $statusTone = trim((string) ($reservation['status_tone'] ?? ''));
            $startsAt = isset($reservation['starts_at']) ? (string) $reservation['starts_at'] : '';
            $endsAt = isset($reservation['ends_at']) ? (string) $reservation['ends_at'] : '';
            ?>
            <li class="reservation-card">
              <div class="reservation-card__head">
                <span class="reservation-card__avatar" aria-hidden="true">
                  <?= e(mb_strtoupper(mb_substr($visitorName !== '' ? $visitorName : 'V', 0, 1))) ?>
                </span>
                <div class="reservation-card__body">
                  <p class="reservation-card__name"><?= e($visitorName) ?></p>
                  <p class="reservation-card__meta">
                    <?php if ($bayCode !== ''): ?>
                      Bay <?= e($bayCode) ?><?php if ($zoneName !== ''): ?> · <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($zoneName !== ''): ?><?= e($zoneName) ?><?php endif; ?>
                    <?php if ($bayCode === '' && $zoneName === ''): ?>Visitor bay<?php endif; ?>
                  </p>
                </div>
                <span class="status <?= e($reservationTone($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
              </div>
              <p class="reservation-card__when">
                <?= $icon('clock', 'ico ico--sm') ?>
                <?= e($formatWhen($startsAt)) ?>, <?= e($formatWhen($endsAt)) ?>
              </p>
              <?php if ($plate !== ''): ?>
                <p class="reservation-card__plate"><?= $icon('vehicle', 'ico ico--sm') ?> <?= e($plate) ?></p>
              <?php endif; ?>
              <?php if ($invitationUuid !== ''): ?>
                <div class="reservation-card__actions">
                  <a class="btn btn--outline btn--sm" href="/app/passes/<?= e(rawurlencode($invitationUuid)) ?>">
                    <?= $icon('ticket', 'ico ico--sm') ?>
                    View pass
                  </a>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="resident-empty">
          <span class="resident-empty__icon" aria-hidden="true"><?= $icon('calendar') ?></span>
          <p class="resident-empty__title">No bookings yet</p>
          <p class="resident-empty__text">
            Visitor parking reservations appear here once you invite a visitor and tick “Visitor parking required”.
          </p>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <div class="cta-dock">
    <a class="btn btn--primary btn--lg btn--block" href="/app/visitors/invite">+ New Booking</a>
  </div>
</div>
