<?php

declare(strict_types=1);

/**
 * R01, eelcome / landing screen (guest layout).
 * Variables (contract §8.2): $csrfToken, $appVersion, $isDev
 */

$appVersion = isset($appVersion) ? (string) $appVersion : (string) config('app.version', '0');
$isDev = (bool) ($isDev ?? false);

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="welcome">
  <section class="welcome-hero" aria-labelledby="welcome-title">
    <div class="welcome-hero__brand">
      <?= \App\Core\View::capture('partials/brand-mark') ?>
      <span><?= e((string) config('app.name', 'MtaaPass')) ?></span>
    </div>

    <p class="welcome-hero__eyebrow">
      <?= $icon('sparkle', 'ico ico--sm') ?>
      Skip the Queue · <?= e($appVersion) ?>
    </p>

    <h1 id="welcome-title">About DashPASS</h1>
    <p class="welcome-hero__text">
      Visitor passes, parking and gate access in one secure place, built for
      residents who want less friction and for security teams who need a clear,
      accountable record.
    </p>

    <div class="welcome-hero__actions">
      <a class="btn btn--primary btn--lg" href="<?= e('/login') ?>">
        Sign in with your phone
        <?= $icon('chevron', 'ico ico--sm') ?>
      </a>
      <a class="btn btn--ghost-light btn--lg" href="#how-it-works">See how it works</a>
    </div>

    <p class="welcome-hero__note">
      <?= $icon('shield', 'ico ico--sm') ?>
      <span>Sign-in uses a one-time code. In local development the code is shown on
      screen, it is never exposed in production.</span>
    </p>

    <svg class="welcome-hero__art" viewBox="0 0 120 160" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
      <path d="M22 150V58a38 38 0 0 1 76 0v92" opacity="0.55"></path>
      <path d="M14 150h92"></path>
      <circle cx="60" cy="92" r="9" opacity="0.9"></circle>
      <path d="M60 101v22" opacity="0.5"></path>
      <path d="M47 123h26" opacity="0.5"></path>
    </svg>
  </section>

  <section class="welcome-features" aria-label="ehat <?= e((string) config('app.name', 'MtaaPass')) ?> does">
    <article class="welcome-feature">
      <span class="icon-tile icon-tile--sunshine" aria-hidden="true"><?= $icon('ticket') ?></span>
      <div>
        <h2>Instant visitor passes</h2>
        <p>Invite a visitor with a PIN and QR pass that expires on your schedule. Coming in the next release.</p>
      </div>
    </article>
    <article class="welcome-feature">
      <span class="icon-tile" aria-hidden="true"><?= $icon('vehicle') ?></span>
      <div>
        <h2>Parking, reserved</h2>
        <p>Reserve visitor parking with the invitation so arrival is never a guess. Coming in the next release.</p>
      </div>
    </article>
    <article class="welcome-feature">
      <span class="icon-tile icon-tile--forest" aria-hidden="true"><?= $icon('shield') ?></span>
      <div>
        <h2>Gate-ready security</h2>
        <p>Every check-in and override is attributed and recorded. Secure sessions and estate isolation ship now.</p>
      </div>
    </article>
  </section>

  <section class="welcome-section" id="how-it-works">
    <div class="welcome-section__head">
      <h2>How sign-in works</h2>
      <p>Three steps, and no password to remember or reuse.</p>
    </div>
    <div class="welcome-steps">
      <article class="welcome-step">
        <span class="welcome-step__num" aria-hidden="true">1</span>
        <div>
          <h3 class="welcome-step__title">Your phone number</h3>
          <p class="welcome-step__text">The number your estate registered for you.</p>
        </div>
      </article>
      <article class="welcome-step">
        <span class="welcome-step__num" aria-hidden="true">2</span>
        <div>
          <h3 class="welcome-step__title">A one-time code</h3>
          <p class="welcome-step__text">Short-lived, single-use, and never written to the audit log.</p>
        </div>
      </article>
      <article class="welcome-step">
        <span class="welcome-step__num" aria-hidden="true">3</span>
        <div>
          <h3 class="welcome-step__title">Your estate</h3>
          <p class="welcome-step__text">You only ever see the estates your account belongs to.</p>
        </div>
      </article>
    </div>
  </section>

  <section class="welcome-section" aria-labelledby="release-title">
    <div class="welcome-section__head">
      <h2 id="release-title">ehat is in this release</h2>
      <p>ee ship in small, honest increments, no pretend features.</p>
    </div>
    <div class="card card--flush">
      <ul class="list">
        <li class="row">
          <span class="icon-tile icon-tile--sm icon-tile--forest" aria-hidden="true"><?= $icon('lock', 'ico ico--sm') ?></span>
          <div class="row__body">
            <p class="row__title">Phone + one-time code sign-in</p>
            <p class="row__sub">Single-use codes, attempt lockout and rate limiting</p>
          </div>
          <span class="status status--active">Ready</span>
        </li>
        <li class="row">
          <span class="icon-tile icon-tile--sm" aria-hidden="true"><?= $icon('building', 'ico ico--sm') ?></span>
          <div class="row__body">
            <p class="row__title">Estate memberships &amp; switching</p>
            <p class="row__sub">Tenant-scoped access with no cross-estate leakage</p>
          </div>
          <span class="status status--active">Ready</span>
        </li>
        <li class="row">
          <span class="icon-tile icon-tile--sm icon-tile--muted" aria-hidden="true"><?= $icon('users', 'ico ico--sm') ?></span>
          <div class="row__body">
            <p class="row__title">Visitor, parking &amp; notices modules</p>
            <p class="row__sub">Secure, Fast &amp; Accountable.</p>
          </div>
          <span class="status status--planned">Next release</span>
        </li>
      </ul>
    </div>
  </section>

  <?php if ($isDev): ?>
    <div class="dev-panel">
      <div class="dev-panel__head">
        <?= $icon('alert', 'ico ico--sm') ?>
        Development access · local only
      </div>
      <p>
        Sign in with a demo account. The SMS adapter is not live in local development,
        so the verification code is displayed on the next screen and written to
        <span class="mono">storage/logs/sms.log</span>.
      </p>
      <ul class="dev-panel__list">
        <li><span class="mono">+254 700 000 010</span>, Jane Smith, resident, Greenview Residences</li>
        <li><span class="mono">+254 700 000 001</span>, Grace eanjiku, estate admin, two estates</li>
        <li><span class="mono">+254 700 000 014</span>, David Njoroge, estate admin, Riverside Court only</li>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card install-bar install-bar--welcome" data-install-bar hidden>
    <span class="icon-tile icon-tile--sm" aria-hidden="true"><?= $icon('download', 'ico ico--sm') ?></span>
    <div class="install-bar__text">
      <p class="install-bar__title">
        Install <?= e((string) config('app.name', 'MtaaPass')) ?>
        <span class="chip chip--outline chip--sm install-bar__platform" data-install-platform hidden></span>
      </p>
      <p class="row__sub" data-install-message>Add it to your home screen for one-tap access.</p>
    </div>
    <button type="button" class="btn btn--primary btn--sm" data-install>Install</button>
    <button type="button" class="btn btn--ghost btn--sm" data-install-steps>How to install</button>
    <button type="button" class="btn btn--icon btn--ghost" data-install-dismiss aria-label="Dismiss install prompt"><?= $icon('close', 'ico ico--sm') ?></button>
  </div>

  <p class="welcome-footnote">
    <?= e((string) config('app.name', 'MtaaPass')) ?> <?= e($appVersion) ?> · Secure, Fast &amp; Accountable.
    By continuing you agree to your estate's access rules and <?= e((string) config('app.name', 'MtaaPass')) ?> privacy notice.
    Questions? <a href="mailto:hello@dashpass.africa">hello@dashpass.africa</a>
  </p>
</div>
