<?php

declare(strict_types=1);

/**
 * Visitor pass screen, one invitation (visitor-access contract §7, §7.1).
 *
 * Shows status, validity, visitor/household/host, the PIN (revealed once only
 * when the controller passes `pin_display`, otherwise the resident can rotate
 * it), the server-rendered QR image, the public pass link with copy, and
 * revoke/extend actions. All actions are plain CSRF-protected POST forms so the
 * screen works without JavaScript.
 *
 * Variables (§7.1): $pass (Item + estate_name, visitor_count, host_label,
 * created_at), $pin_display (?string), $qr_url, $pass_url, $isDev, $errors +
 * shell data.
 */

$pass = isset($pass) && is_array($pass) ? $pass : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$isDev = (bool) ($isDev ?? false);

$estate = isset($estate) && is_array($estate) ? $estate : [];
$estateName = trim((string) ($pass['estate_name'] ?? $estate['estate_name'] ?? ''));
$timezone = trim((string) ($estate['estate_timezone'] ?? (string) config('app.timezone', 'Africa/Nairobi')));

$invitationUuid = trim((string) ($pass['uuid'] ?? ''));
$visitorName = trim((string) ($pass['visitor_name'] ?? 'Visitor'));
$purpose = trim((string) ($pass['purpose'] ?? ''));
$householdLabel = trim((string) ($pass['household_label'] ?? ''));
$hostLabel = trim((string) ($pass['host_label'] ?? ''));
$status = trim((string) ($pass['status'] ?? ''));
$statusLabel = trim((string) ($pass['status_label'] ?? ''));
$statusTone = trim((string) ($pass['status_tone'] ?? 'neutral'));
$visitorCount = isset($pass['visitor_count']) ? max(1, (int) $pass['visitor_count']) : 1;
$alreadyInside = (bool) ($pass['already_inside'] ?? false);
$validFrom = isset($pass['valid_from']) ? (string) $pass['valid_from'] : '';
$validUntil = isset($pass['valid_until']) ? (string) $pass['valid_until'] : '';
$createdAt = isset($pass['created_at']) ? (string) $pass['created_at'] : '';

// Optional visitor parking reservation attached to this invitation.
$parking = isset($pass['parking']) && is_array($pass['parking']) ? $pass['parking'] : null;
$parkingBay = $parking !== null ? trim((string) ($parking['bay_code'] ?? '')) : '';
$parkingZone = $parking !== null ? trim((string) ($parking['zone_name'] ?? '')) : '';
$parkingStartsAt = $parking !== null && isset($parking['starts_at']) ? (string) $parking['starts_at'] : '';
$parkingEndsAt = $parking !== null && isset($parking['ends_at']) ? (string) $parking['ends_at'] : '';
$parkingStatus = $parking !== null ? trim((string) ($parking['status'] ?? '')) : '';
$parkingStatusLabel = $parking !== null ? trim((string) ($parking['status_label'] ?? '')) : '';
$parkingStatusTone = $parking !== null ? trim((string) ($parking['status_tone'] ?? '')) : '';
$hasParking = $parking !== null && $parkingBay !== '';

$pinDisplay = isset($pin_display) && is_string($pin_display) && $pin_display !== '' ? $pin_display : null;

// The controller may pass absolute APP_URL links; markup must stay
// root-relative (CSP/no-inline rule and port-independent deployment).
$normaliseAppUrl = static function (string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $appUrl = rtrim((string) config('app.url', ''), '/');
    if ($appUrl !== '' && str_starts_with($url, $appUrl)) {
        return substr($url, strlen($appUrl)) ?: '/';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*://[^/]+(/.*)?$#i', $url, $matches) === 1) {
        return $matches[1] ?? '/';
    }

    return $url;
};

$qrUrl = isset($qr_url) && is_string($qr_url) && $qr_url !== ''
    ? $normaliseAppUrl($qr_url)
    : ($invitationUuid !== '' ? '/app/passes/' . rawurlencode($invitationUuid) . '/qr.png' : '');
$publicPassUrl = isset($pass_url) && is_string($pass_url) ? trim($pass_url) : '';
$rootRelativePassUrl = $normaliseAppUrl($publicPassUrl);

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

$statusClass = static function (string $tone): string {
    return match ($tone) {
        'ok' => 'status--active',
        'warn' => 'status--pending',
        'danger' => 'status--danger',
        default => 'status--inactive',
    };
};

$allErrors = [];
foreach ($errors as $messages) {
    if (is_array($messages)) {
        foreach ($messages as $message) {
            if (is_scalar($message) && trim((string) $message) !== '') {
                $allErrors[] = trim((string) $message);
            }
        }
    } elseif (is_scalar($messages) && trim((string) $messages) !== '') {
        $allErrors[] = trim((string) $messages);
    }
}
$allErrors = array_values(array_unique($allErrors));

$canRotate = $status === 'approved';
$canExtend = in_array($status, ['approved', 'checked_in'], true);
$canRevoke = in_array($status, ['approved', 'checked_in'], true);

$statusNote = match ($status) {
    'approved' => 'The visitor can enter once, during the validity window.',
    'checked_in' => 'The visitor is currently inside the estate.',
    'checked_out' => 'This visit is complete. The pass can no longer be used.',
    'revoked' => 'This pass was revoked and can no longer be used.',
    'expired' => 'This pass has expired. Create a new invitation for another visit.',
    'denied' => 'This visit was denied.',
    default => 'The visitor can enter during the validity window.',
};
?>
<div class="resident resident--pushed" data-offline-pass
     data-offline-uuid="<?= e($invitationUuid) ?>"
     data-offline-visitor="<?= e($visitorName) ?>"
     data-offline-household="<?= e($householdLabel) ?>"
     data-offline-valid-until="<?= e($validUntil) ?>"
     data-offline-status="<?= e($status) ?>"
     data-offline-status-label="<?= e($statusLabel) ?>"
     data-offline-qr-url="<?= e($qrUrl) ?>">
  <header class="screen-bar">
    <a class="screen-bar__action" href="/app/visitors" aria-label="Back to visitors" title="Back to visitors">
      <?= $icon('chevron-left', 'ico') ?>
    </a>
    <h1 class="screen-bar__title">Visitor pass</h1>
    <span class="screen-bar__spacer" aria-hidden="true"></span>
  </header>

  <?php if ($allErrors !== []): ?>
    <div class="form-error-summary" role="alert">
      <?= $icon('alert', 'ico ico--sm') ?>
      <div>
        <p class="alert__title">That action could not be completed</p>
        <ul>
          <?php foreach (array_slice($allErrors, 0, 6) as $message): ?>
            <li><?= e($message) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <?php $hasPassLink = $publicPassUrl !== ''; ?>
  <section class="pass-card pass-share" aria-labelledby="pass-qr-title">
    <div class="pass-card__head">
      <h2 class="pass-card__title" id="pass-qr-title"><?= $icon('qr', 'ico ico--sm') ?> Gate QR code</h2>
      <span class="chip chip--outline">No camera scanning yet</span>
    </div>

    <p class="pass-card__hint">
      The guard can verify this code at the gate. Your visitor can also use the pass link.
    </p>

    <div class="pass-share__hero">
      <div class="pass-qr pass-share__canvas">
        <?php if ($qrUrl !== ''): ?>
          <img src="<?= e($qrUrl) ?>" alt="Access QR code" width="640" height="640" decoding="async">
        <?php else: ?>
          <span class="pass-share__caption">QR code unavailable for this pass</span>
        <?php endif; ?>
      </div>
      <?php if ($qrUrl !== ''): ?>
        <span class="pass-share__caption">Scan at the gate</span>
      <?php endif; ?>
    </div>

    <?php if ($hasPassLink): ?>
      <div class="pass-share__stub">
        <h3 class="pass-share__title"><?= $icon('link', 'ico ico--sm') ?> Visitor pass link</h3>
        <div class="pass-share__actions">
          <button type="button" class="btn btn--outline btn--sm" data-copy="<?= e($publicPassUrl) ?>">
            <?= $icon('copy', 'ico ico--sm') ?>
            Copy link
          </button>
          <a class="btn btn--ghost btn--sm" href="<?= e($rootRelativePassUrl) ?>" target="_blank" rel="noopener noreferrer">
            <?= $icon('arrow-up-right', 'ico ico--sm') ?>
            Open pass page
          </a>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <section class="pass-hero" aria-labelledby="pass-visitor-title">
    <div class="pass-hero__head">
      <h2 class="pass-hero__visitor" id="pass-visitor-title"><?= e($visitorName) ?></h2>
      <span class="status <?= e($statusClass($statusTone)) ?>"><?= e($statusLabel !== '' ? $statusLabel : 'Pass') ?></span>
      <?php if ($alreadyInside): ?>
        <span class="chip chip--emerald">Inside now</span>
      <?php endif; ?>
    </div>

    <?php if ($purpose !== ''): ?>
      <p class="pass-hero__purpose"><?= e($purpose) ?></p>
    <?php endif; ?>

    <dl class="pass-meta">
      <div class="pass-meta__item">
        <dt>Estate</dt>
        <dd><?= e($estateName !== '' ? $estateName : 'Estate') ?></dd>
      </div>
      <?php if ($hostLabel !== ''): ?>
        <div class="pass-meta__item">
          <dt>Host</dt>
          <dd><?= e($hostLabel) ?></dd>
        </div>
      <?php endif; ?>
      <div class="pass-meta__item">
        <dt>Household</dt>
        <dd><?= e($householdLabel !== '' ? $householdLabel : 'Household') ?></dd>
      </div>
      <div class="pass-meta__item">
        <dt>Visitors</dt>
        <dd><?= e((string) $visitorCount) ?></dd>
      </div>
    </dl>

    <div class="pass-validity">
      <span class="pass-validity__icon" aria-hidden="true"><?= $icon('clock', 'ico ico--sm') ?></span>
      <span>
        <span class="pass-validity__label">Valid window</span>
        <span class="pass-validity__value"><?= e($formatWhen($validFrom)) ?>, <?= e($formatWhen($validUntil)) ?></span>
        <span class="pass-validity__tz"><?= e($timezone) ?></span>
      </span>
    </div>

    <p class="pass-hero__note"><?= e($statusNote) ?></p>
  </section>

  <section class="pass-card" aria-labelledby="pass-pin-title">
    <div class="pass-card__head">
      <h2 class="pass-card__title" id="pass-pin-title"><?= $icon('key', 'ico ico--sm') ?> Access PIN</h2>
      <?php if ($pinDisplay !== null): ?>
        <span class="chip chip--sunshine">Shown once</span>
      <?php endif; ?>
    </div>

    <?php if ($pinDisplay !== null): ?>
      <p class="pass-pin" data-pass-pin aria-label="Visitor access PIN"><?= e($pinDisplay) ?></p>
      <p class="pass-card__hint">
        Share this PIN with your visitor now, it is shown only once. They can also open the pass link below.
      </p>
      <button type="button" class="btn btn--outline btn--sm" data-copy="<?= e($pinDisplay) ?>">
        <?= $icon('copy', 'ico ico--sm') ?>
        Copy PIN
      </button>
      <button type="button" class="btn btn--outline btn--sm" data-offline-save-pin>
        <?= $icon('download', 'ico ico--sm') ?>
        Save PIN on this device for offline access
      </button>
      <p class="pass-card__hint pass-card__offline-note" data-offline-save-note hidden>
        The PIN and QR are stored encrypted on this device only; signing out removes them.
      </p>
      <?php if ($canRotate): ?>
        <form method="post" action="/app/passes/<?= e(rawurlencode($invitationUuid)) ?>/rotate-pin"
              class="pass-inline-form"
              data-confirm="Rotate the PIN? The current PIN stops working immediately.">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn--ghost btn--sm"><?= $icon('refresh', 'ico ico--sm') ?> Rotate PIN</button>
        </form>
      <?php endif; ?>
    <?php elseif ($canRotate): ?>
      <p class="pass-card__hint">
        The PIN was sent to your visitor by SMS. If they did not receive it, rotate the PIN to reveal a new one here.
      </p>
      <form method="post" action="/app/passes/<?= e(rawurlencode($invitationUuid)) ?>/rotate-pin"
            data-confirm="Rotate the PIN? The current PIN stops working immediately.">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--outline btn--sm"><?= $icon('refresh', 'ico ico--sm') ?> Rotate PIN</button>
      </form>
      <p class="pass-card__hint pass-card__offline-note">
        Offline access: this PIN is not saved on this device. Rotate the PIN while online to view and save a new one.
      </p>
    <?php else: ?>
      <p class="pass-card__hint">No PIN is available for this pass status.</p>
    <?php endif; ?>

    <?php if ($isDev): ?>
      <p class="pass-card__dev">
        Development: SMS is logged to <span class="mono">storage/logs/sms.log</span>; the PIN above is only
        revealed to you once.
      </p>
    <?php endif; ?>
  </section>

  <?php if ($hasParking): ?>
    <section class="pass-card" aria-labelledby="pass-parking-title">
      <div class="pass-card__head">
        <h2 class="pass-card__title" id="pass-parking-title"><?= $icon('parking', 'ico ico--sm') ?> Visitor parking</h2>
        <?php if ($parkingStatusLabel !== ''): ?>
          <span class="status <?= e($statusClass($parkingStatusTone)) ?>"><?= e($parkingStatusLabel) ?></span>
        <?php elseif ($parkingStatus !== ''): ?>
          <span class="status status--inactive"><?= e(ucfirst(str_replace('_', ' ', $parkingStatus))) ?></span>
        <?php endif; ?>
      </div>

      <dl class="pass-meta">
        <div class="pass-meta__item">
          <dt>Reserved bay</dt>
          <dd><?= e($parkingBay) ?></dd>
        </div>
        <?php if ($parkingZone !== ''): ?>
          <div class="pass-meta__item">
            <dt>Zone</dt>
            <dd><?= e($parkingZone) ?></dd>
          </div>
        <?php endif; ?>
        <?php if ($parkingStartsAt !== '' && $parkingEndsAt !== ''): ?>
          <div class="pass-meta__item">
            <dt>Parking window</dt>
            <dd><?= e($formatWhen($parkingStartsAt)) ?>, <?= e($formatWhen($parkingEndsAt)) ?></dd>
          </div>
        <?php endif; ?>
      </dl>

      <p class="pass-card__hint">
        The bay is held for the invitation window. It becomes occupied when the guard checks the visitor in and is
        released at checkout.
      </p>
    </section>
  <?php endif; ?>

  <?php if ($canRevoke || $canExtend): ?>
    <section class="pass-card" aria-labelledby="pass-actions-title">
      <div class="pass-card__head">
        <h2 class="pass-card__title" id="pass-actions-title"><?= $icon('shield', 'ico ico--sm') ?> Pass actions</h2>
      </div>

      <?php if ($canExtend): ?>
        <form method="post" action="/app/passes/<?= e(rawurlencode($invitationUuid)) ?>/extend" class="pass-action-row">
          <?= csrf_field() ?>
          <label class="field__label" for="extend_minutes">Extend validity</label>
          <div class="pass-action-row__controls">
            <select class="select" id="extend_minutes" name="minutes">
              <option value="30">+30 minutes</option>
              <option value="60">+1 hour</option>
              <option value="120" selected>+2 hours</option>
              <option value="240">+4 hours</option>
              <option value="480">+8 hours</option>
              <option value="1440">+1 day</option>
            </select>
            <button type="submit" class="btn btn--outline btn--sm">Extend</button>
          </div>
        </form>
      <?php endif; ?>

      <?php if ($canRevoke): ?>
        <form method="post" action="/app/passes/<?= e(rawurlencode($invitationUuid)) ?>/revoke" class="signout-form"
              data-confirm="Revoke this pass? The visitor will no longer be able to enter.">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn--danger-ghost btn--block">
            <?= $icon('close', 'ico ico--sm') ?>
            Revoke pass
          </button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <div class="pass-footer">
    <?php if ($createdAt !== ''): ?>
      <p class="pass-footer__meta">Created <?= e($formatWhen($createdAt)) ?></p>
    <?php endif; ?>
    <a class="btn btn--ghost btn--sm" href="/app/visitors"><?= $icon('arrow-left', 'ico ico--sm') ?> All visitors</a>
  </div>
</div>
