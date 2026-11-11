<?php

declare(strict_types=1);

/**
 * Guard verify screen (visitor-access-module-contract §7, §7.1).
 *
 * Two large, minimal-typing ways to check a pass: a numeric keypad for the
 * six-digit PIN and a paste box for the QR pass code / pass link. Both forms are
 * plain POSTs to /gate/verify, so they work with JavaScript disabled; guard.js
 * additionally verifies against the JSON API and renders the result panel
 * without a page reload, then offers a one-tap check-in.
 *
 * Variables: shell + $result (null|§6 verify payload), $error (string|null),
 * $prefill_code (string|null).
 */

$result = isset($result) && is_array($result) ? $result : null;
$error = isset($error) && is_string($error) && trim($error) !== '' ? trim($error) : null;
$prefillCode = isset($prefill_code) && is_string($prefill_code) ? trim($prefill_code) : '';
$selectedGate = isset($selected_gate) && is_array($selected_gate) ? $selected_gate : null;
$gateName = trim((string) ($gate ?? ($selectedGate['name'] ?? '')));
$timezone = (string) config('app.timezone', 'Africa/Nairobi');

$statusTones = [
    'approved' => ['APPROVED', 'ok'],
    'pending' => ['PENDING', 'warn'],
    'checked_in' => ['INSIDE', 'warn'],
    'checked_out' => ['USED', 'neutral'],
    'expired' => ['EXPIRED', 'danger'],
    'revoked' => ['DENIED', 'danger'],
    'denied' => ['DENIED', 'danger'],
];

$status = '';
$statusLabel = '';
$statusTone = 'neutral';
$alreadyInside = false;
$invitationUuid = '';
$credentialUuid = '';
$visitorName = '';
$householdLabel = '';
$purpose = '';
$validFrom = '';
$validUntil = '';

if ($result !== null) {
    $status = trim((string) ($result['status'] ?? 'approved'));
    $alreadyInside = (bool) ($result['already_inside'] ?? false) || $status === 'checked_in';
    $invitationUuid = trim((string) ($result['invitation_uuid'] ?? ''));
    $credentialUuid = trim((string) ($result['credential_uuid'] ?? ''));
    $visitorName = trim((string) ($result['visitor_name'] ?? ''));
    $householdLabel = trim((string) ($result['household_label'] ?? ''));
    $purpose = trim((string) ($result['purpose'] ?? ''));
    $validFrom = trim((string) ($result['valid_from'] ?? ''));
    $validUntil = trim((string) ($result['valid_until'] ?? ''));

    if ($alreadyInside) {
        $statusLabel = 'ALREADY INSIDE';
        $statusTone = 'warn';
    } else {
        [$statusLabel, $statusTone] = $statusTones[$status] ?? [strtoupper(str_replace('_', ' ', $status)), 'neutral'];
    }
}

$canCheckIn = $result !== null && !$alreadyInside && $status === 'approved' && $invitationUuid !== '';
$pinPrefill = $prefillCode !== '' && preg_match('/^\d{6}$/', $prefillCode) === 1 ? $prefillCode : '';
$codePrefill = $pinPrefill === '' ? $prefillCode : '';

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$formatWhen = static fn (string $iso): string => $iso === '' ? '—' : format_datetime($iso, $timezone, 'd M Y, H:i');
?>
<div class="g-screen">
  <header class="g-head">
    <div>
      <p class="g-head__eyebrow"><?= $gateName !== '' ? e($gateName) : 'No gate selected' ?></p>
      <h1 class="g-head__title">Verify a visitor pass</h1>
    </div>
    <a class="g-head__link" href="/gate/active"><?= $icon('users', 'ico ico--sm') ?> Inside</a>
  </header>

  <?php if ($gateName === ''): ?>
    <section class="g-panel">
      <div class="g-empty">
        <span class="g-empty__icon" aria-hidden="true"><?= $icon('gate') ?></span>
        <p class="g-empty__title">Choose a gate first</p>
        <p class="g-empty__text">Verification happens against the gate you are working at. Select your gate to begin.</p>
        <p><a class="btn btn--primary btn--lg" href="/gate">Go to dashboard</a></p>
      </div>
    </section>
  <?php else: ?>
    <?php if ($error !== null): ?>
      <div class="alert alert--error" role="alert">
        <?= $icon('alert') ?>
        <div class="alert__body">
          <p class="alert__title"><span class="g-chip g-chip--danger">INVALID</span> Pass not verified</p>
          <p><?= e($error) ?></p>
        </div>
      </div>
    <?php endif; ?>

    <div class="g-result-region" data-guard-result-region aria-live="polite">
      <?php if ($result !== null): ?>
        <section class="g-result" data-guard-result data-tone="<?= e($statusTone) ?>">
          <div class="g-result__head">
            <?= \App\Core\View::capture('guard/partials/status-chip', [
                'status' => $status,
                'statusLabel' => $statusLabel,
                'statusTone' => $statusTone,
            ]) ?>
            <h2 class="g-result__title"><?= e($visitorName !== '' ? $visitorName : 'Visitor') ?></h2>
            <p class="g-result__sub">
              <?php if ($householdLabel !== ''): ?>
                <span><?= e($householdLabel) ?></span>
              <?php endif; ?>
              <?php if ($purpose !== ''): ?>
                <span><?= e($purpose) ?></span>
              <?php endif; ?>
            </p>
          </div>

          <dl class="g-result__facts">
            <div class="g-result__fact">
              <dt>Valid from</dt>
              <dd><?= e($formatWhen($validFrom)) ?></dd>
            </div>
            <div class="g-result__fact">
              <dt>Valid until</dt>
              <dd><?= e($formatWhen($validUntil)) ?></dd>
            </div>
          </dl>

          <?php if ($alreadyInside): ?>
            <div class="g-result__note g-result__note--warn">
              <?= $icon('info') ?>
              <p>This visitor is already inside. Do not admit them again — use the Active screen to check them out.</p>
            </div>
            <a class="btn btn--outline btn--lg btn--block" href="/gate/active">Open active visitors</a>
          <?php elseif ($canCheckIn): ?>
            <form method="post" action="/gate/checkin" data-guard-checkin data-invitation-uuid="<?= e($invitationUuid) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="invitation_uuid" value="<?= e($invitationUuid) ?>">
              <?php if ($credentialUuid !== ''): ?>
                <input type="hidden" name="credential_uuid" value="<?= e($credentialUuid) ?>">
              <?php endif; ?>
              <button type="submit" class="btn btn--primary btn--lg btn--block">
                <?= $icon('check') ?>
                Check in visitor
              </button>
            </form>
          <?php else: ?>
            <div class="g-result__note g-result__note--danger">
              <?= $icon('alert') ?>
              <p>This pass cannot be used for entry. Ask the resident to issue a new one.</p>
            </div>
          <?php endif; ?>

          <a class="btn btn--ghost btn--block g-result__again" href="/gate/verify">Verify another visitor</a>
        </section>
      <?php endif; ?>
    </div>

    <section class="g-panel" aria-labelledby="pin-verify-title">
      <div class="g-panel__head">
        <h2 class="g-panel__title" id="pin-verify-title">Enter visitor PIN</h2>
        <p class="g-panel__hint">Six digits, shown on the visitor's pass screen.</p>
      </div>

      <form method="post" action="/gate/verify" class="g-form" data-guard-verify-pin>
        <?= csrf_field() ?>

        <label class="g-pin-label" for="guard-pin">Visitor PIN</label>
        <input class="g-pin-input" id="guard-pin" name="code" type="text"
               inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}"
               placeholder="••••••" aria-describedby="guard-pin-help"
               value="<?= e($pinPrefill) ?>" data-guard-pin>
        <p class="g-panel__hint" id="guard-pin-help">Use the keypad or type the six-digit code.</p>

        <div class="g-keypad" data-guard-keypad role="group" aria-label="PIN keypad">
          <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit): ?>
            <button type="button" class="g-keypad__key" data-guard-key="<?= e($digit) ?>"
                    aria-label="Digit <?= e($digit) ?>"><?= e($digit) ?></button>
          <?php endforeach; ?>
          <button type="button" class="g-keypad__key g-keypad__key--utility" data-guard-key="clear"
                  aria-label="Clear PIN">Clear</button>
          <button type="button" class="g-keypad__key" data-guard-key="0" aria-label="Digit 0">0</button>
          <button type="button" class="g-keypad__key g-keypad__key--utility" data-guard-key="back"
                  aria-label="Delete last digit">Del</button>
        </div>

        <button type="submit" class="btn btn--primary btn--lg btn--block g-submit">
          <?= $icon('key') ?>
          Verify PIN
        </button>
      </form>
    </section>

    <section class="g-panel" aria-labelledby="code-verify-title">
      <div class="g-panel__head">
        <h2 class="g-panel__title" id="code-verify-title">Paste pass code</h2>
        <p class="g-panel__hint">Paste the long pass code or its /v/ link when a visitor shows a QR pass.</p>
      </div>

      <form method="post" action="/gate/verify" class="g-form" data-guard-verify-code>
        <?= csrf_field() ?>

        <label class="field__label" for="guard-code">Pass code</label>
        <textarea class="input g-code-input" id="guard-code" name="code" rows="3"
                  spellcheck="false" autocapitalize="off" autocomplete="off"
                  placeholder="Paste the 64-character pass code or the /v/ pass link"
                  data-guard-code><?= e($codePrefill) ?></textarea>

        <button type="submit" class="btn btn--outline btn--lg btn--block g-submit">
          <?= $icon('qr') ?>
          Verify pass code
        </button>
      </form>
    </section>

    <section class="g-legend" aria-label="Status key">
      <p class="g-legend__title">Status key</p>
      <ul class="g-legend__list">
        <?= \App\Core\View::capture('guard/partials/status-chip', ['status' => 'approved', 'statusLabel' => 'APPROVED', 'statusTone' => 'ok']) ?>
        <?= \App\Core\View::capture('guard/partials/status-chip', ['status' => 'checked_in', 'statusLabel' => 'INSIDE', 'statusTone' => 'warn']) ?>
        <?= \App\Core\View::capture('guard/partials/status-chip', ['status' => 'expired', 'statusLabel' => 'EXPIRED', 'statusTone' => 'danger']) ?>
        <?= \App\Core\View::capture('guard/partials/status-chip', ['status' => 'denied', 'statusLabel' => 'INVALID', 'statusTone' => 'danger']) ?>
      </ul>
    </section>
  <?php endif; ?>
</div>
