<?php

declare(strict_types=1);

/**
 * Public single-scroll landing page (guest layout). Marketing copy plus the
 * moved welcome/About content in #about. Auth/Firebase stays on /login.
 * No inline styles or scripts.
 */

$appVersion = isset($appVersion) ? (string) $appVersion : (string) config('app.version', '0');
$brand = (string) config('app.name', 'MtaaPass');
$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="landing">
  <section class="landing-hero" aria-labelledby="landing-title">
    <div class="landing-hero__copy">
      <p class="landing-hero__eyebrow"><?= $icon('sparkle', 'ico ico--sm') ?> ESTATE ACCESS &amp; VISITOR MANAGEMENT</p>
      <h1 id="landing-title">DashPASS: Visitor Pass Management System</h1>
      <p class="landing-hero__lede">
        Give residents, guards and estate teams one calm, secure place to invite visitors,
        manage gate access and keep an accountable record of every arrival.
      </p>
      <div class="landing-hero__actions">
        <a class="btn btn--primary btn--lg" href="<?= e('/login') ?>">Get started <?= $icon('chevron', 'ico ico--sm') ?></a>
        <a class="btn btn--ghost-light btn--lg" href="<?= e('/login?intent=scan') ?>">Scan QR</a>
      </div>
      <p class="landing-hero__note"><?= $icon('qr', 'ico ico--sm') ?> Scan QR? Sign in to scan a visitor pass.</p>
    </div>
    <div class="landing-hero__visual">
      <span class="landing-hero__glow" aria-hidden="true"></span>
      <span class="landing-hero__ring landing-hero__ring--one" aria-hidden="true"></span>
      <span class="landing-hero__ring landing-hero__ring--two" aria-hidden="true"></span>
      <img class="landing-hero__image" src="<?= e(asset('img/estate-hero.png')) ?>" alt="" width="1200" height="675" decoding="async">
    </div>
  </section>

  <section class="landing-values" aria-label="Why DashPASS">
    <article class="landing-value">
      <span class="landing-value__icon" aria-hidden="true"><?= $icon('ticket') ?></span>
      <h2>Invite in seconds</h2>
      <p>Share a visitor pass before they reach the gate.</p>
    </article>
    <article class="landing-value">
      <span class="landing-value__icon" aria-hidden="true"><?= $icon('gate') ?></span>
      <h2>Clear gate records</h2>
      <p>Every check-in is attributed and easy to review.</p>
    </article>
    <article class="landing-value">
      <span class="landing-value__icon" aria-hidden="true"><?= $icon('shield') ?></span>
      <h2>Private by design</h2>
      <p>Estate isolation and one-time sign-in keep access controlled.</p>
    </article>
  </section>

  <section id="about" class="landing-about" aria-labelledby="about-title">
    <div class="landing-about__head">
      <p class="landing-hero__eyebrow"><?= $icon('info', 'ico ico--sm') ?> ABOUT DASHPASS</p>
      <h2 id="about-title">About DashPASS</h2>
      <p>
        <?= e($brand) ?> gives residents, security teams and estate administrators one
        accountable place to manage estate access. Visitor passes, parking and gate records
        live together so the front door feels calm instead of crowded.
      </p>
    </div>

    <div class="welcome-features">
      <article class="welcome-feature">
        <span class="icon-tile icon-tile--sunshine" aria-hidden="true"><?= $icon('ticket') ?></span>
        <div>
          <h3>Instant visitor passes</h3>
          <p>Invite a visitor with a PIN and QR pass that expires on your schedule.</p>
        </div>
      </article>
      <article class="welcome-feature">
        <span class="icon-tile" aria-hidden="true"><?= $icon('vehicle') ?></span>
        <div>
          <h3>Parking, reserved</h3>
          <p>Reserve visitor parking with the invitation so arrival is never a guess.</p>
        </div>
      </article>
      <article class="welcome-feature">
        <span class="icon-tile icon-tile--forest" aria-hidden="true"><?= $icon('shield') ?></span>
        <div>
          <h3>Gate-ready security</h3>
          <p>Every check-in and override is attributed and recorded. Secure sessions and estate isolation are enforced server-side.</p>
        </div>
      </article>
    </div>

    <div class="landing-about__how">
      <div class="welcome-section__head">
        <h3>How sign-in works</h3>
        <p>Three steps, and no password to remember or reuse.</p>
      </div>
      <div class="welcome-steps">
        <article class="welcome-step">
          <span class="welcome-step__num" aria-hidden="true">1</span>
          <div><h4 class="welcome-step__title">Your phone number</h4><p class="welcome-step__text">The number your estate registered for you.</p></div>
        </article>
        <article class="welcome-step">
          <span class="welcome-step__num" aria-hidden="true">2</span>
          <div><h4 class="welcome-step__title">A one-time code</h4><p class="welcome-step__text">Short-lived, single-use, and never written to the audit log.</p></div>
        </article>
        <article class="welcome-step">
          <span class="welcome-step__num" aria-hidden="true">3</span>
          <div><h4 class="welcome-step__title">Your estate</h4><p class="welcome-step__text">You only ever see the estates your account belongs to.</p></div>
        </article>
      </div>
    </div>

    <p class="welcome-footnote">
      <?= e($brand) ?> <?= e($appVersion) ?> · Secure, Fast &amp; Accountable.
      By continuing you agree to your estate's access rules and <?= e($brand) ?> privacy notice.
      Questions? <a href="mailto:hello@dashpass.africa">hello@dashpass.africa</a>
    </p>
  </section>
</div>
