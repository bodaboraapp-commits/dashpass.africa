<?php

declare(strict_types=1);

/**
 * My Parking screen (vehicles-parking contract §4, §2).
 *
 * Shows the household's parking allocation(s) and upcoming visitor bay
 * reservations made with visitor invitations. No fabricated data: honest empty
 * states when no allocation or reservation exists yet.
 *
 * Variables (T21 view-data contract): $allocations, $reservations + shell data.
 */

$allocations = isset($allocations) && is_array($allocations) ? $allocations : [];
$reservations = isset($reservations) && is_array($reservations) ? $reservations : [];

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estateName = trim((string) ($estate['estate_name'] ?? ''));
$timezone = trim((string) ($estate['estate_timezone'] ?? (string) config('app.timezone', 'Africa/Nairobi')));

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$formatWhen = static function (?string $iso) use ($timezone): string {
    if (!is_string($iso) || trim($iso) === '') {
        return '—';
    }

    return format_datetime($iso, $timezone, 'd M Y, H:i');
};

$toneClass = static function (string $tone, string $status = ''): string {
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
        'active', 'reserved' => 'status--active',
        'checked_in' => 'status--pending',
        'cancelled', 'expired', 'suspended', 'ended' => 'status--inactive',
        default => 'status--inactive',
    };
};

$typeLabels = [
    'car' => 'Car',
    'motorcycle' => 'Motorcycle',
    'van' => 'Van',
    'truck' => 'Truck',
    'other' => 'Other',
];
?>
<div class="resident resident--pushed">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app" aria-label="Back to home" title="Back to home">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">My parking</h1>
    <a class="screen-bar__action" href="/app/vehicles" aria-label="Household vehicles" title="Household vehicles">
      <?= $icon('car', 'ico') ?>
    </a>
  </header>

  <section class="resident-section" aria-labelledby="allocation-title">
    <div class="resident-section__head">
      <div>
        <h2 class="resident-section__title" id="allocation-title">Household parking</h2>
        <p class="resident-section__hint">
          <?php if ($estateName !== ''): ?>
            Allocated resident bays at <?= e($estateName) ?>.
          <?php else: ?>
            Allocated resident bays for your household.
          <?php endif; ?>
        </p>
      </div>
      <?php if ($allocations !== []): ?>
        <span class="chip"><?= e((string) count($allocations)) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($allocations !== []): ?>
      <ul class="allocation-list">
        <?php foreach ($allocations as $allocation): ?>
          <?php
          if (!is_array($allocation)) {
              continue;
          }
          $bayCode = trim((string) ($allocation['bay_code'] ?? ''));
          $zoneName = trim((string) ($allocation['zone_name'] ?? ''));
          $status = trim((string) ($allocation['status'] ?? 'active'));
          $statusLabel = trim((string) ($allocation['status_label'] ?? '')) ?: ucfirst($status);
          $statusTone = trim((string) ($allocation['status_tone'] ?? ''));
          $vehicle = isset($allocation['vehicle']) && is_array($allocation['vehicle']) ? $allocation['vehicle'] : null;
          $plate = $vehicle !== null ? trim((string) ($vehicle['plate_number'] ?? '')) : '';
          $vehicleType = $vehicle !== null ? trim((string) ($vehicle['vehicle_type'] ?? '')) : '';
          $vehicleColour = $vehicle !== null ? trim((string) ($vehicle['colour'] ?? '')) : '';
          ?>
          <li class="allocation-card">
            <span class="allocation-card__bay" aria-hidden="true">
              <?= $icon('parking') ?>
              <?php if ($bayCode !== ''): ?>
                <span class="allocation-card__code"><?= e($bayCode) ?></span>
              <?php endif; ?>
            </span>
            <div class="allocation-card__body">
              <p class="allocation-card__title"><?= e($bayCode !== '' ? 'Bay ' . $bayCode : 'Resident bay') ?></p>
              <?php if ($zoneName !== ''): ?>
                <p class="allocation-card__meta"><?= e($zoneName) ?></p>
              <?php endif; ?>
              <?php if ($vehicle !== null): ?>
                <p class="allocation-card__vehicle">
                  <?= $icon('car', 'ico ico--sm') ?>
                  <?= e($plate !== '' ? $plate : 'Household vehicle') ?>
                  <?php if ($vehicleType !== '' && isset($typeLabels[$vehicleType])): ?>
                    · <?= e($typeLabels[$vehicleType]) ?>
                  <?php endif; ?>
                  <?php if ($vehicleColour !== ''): ?>
                    · <?= e($vehicleColour) ?>
                  <?php endif; ?>
                </p>
              <?php else: ?>
                <p class="allocation-card__vehicle">
                  <?= $icon('car', 'ico ico--sm') ?>
                  No vehicle linked to this bay yet.
                </p>
              <?php endif; ?>
            </div>
            <span class="status <?= e($toneClass($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="parking-note">
        Resident allocations are managed by estate management in this release. Contact the estate office to change your bay.
      </p>
    <?php else: ?>
      <div class="resident-empty">
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('parking') ?></span>
        <p class="resident-empty__title">No parking allocation yet</p>
        <p class="resident-empty__text">
          Your estate management assigns resident bays. Once a bay is allocated it will appear here with the linked vehicle.
        </p>
      </div>
    <?php endif; ?>
  </section>

  <section class="resident-section" aria-labelledby="reservations-title">
    <div class="resident-section__head">
      <div>
        <h2 class="resident-section__title" id="reservations-title">Visitor reservations</h2>
        <p class="resident-section__hint">Visitor bays reserved with your visitor invitations.</p>
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
          $reservationUuid = trim((string) ($reservation['uuid'] ?? ''));
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
              <span class="status <?= e($toneClass($statusTone, $status)) ?>"><?= e($statusLabel) ?></span>
            </div>

            <p class="reservation-card__when">
              <?= $icon('clock', 'ico ico--sm') ?>
              <?= e($formatWhen($startsAt)) ?> — <?= e($formatWhen($endsAt)) ?>
            </p>
            <?php if ($plate !== ''): ?>
              <p class="reservation-card__plate"><?= $icon('car', 'ico ico--sm') ?> <?= e($plate) ?></p>
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
        <span class="resident-empty__icon" aria-hidden="true"><?= $icon('parking') ?></span>
        <p class="resident-empty__title">No visitor reservations</p>
        <p class="resident-empty__text">
          Tick “Visitor parking required” when you invite a visitor and the reserved bay will appear here.
        </p>
      </div>
    <?php endif; ?>
  </section>

  <div class="cta-dock">
    <a class="btn btn--primary btn--lg btn--block" href="/app/visitors/invite">+ Invite Visitor</a>
  </div>
</div>
