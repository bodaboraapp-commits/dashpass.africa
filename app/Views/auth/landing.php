<?php

declare(strict_types=1);

/**
 * Public single-screen landing page (guest layout), cloned from the reference:
 * brand/nav header, two-column hero, rounded estate image and slim footer.
 * Auth/Firebase stays on /login; About content lives on /about.
 *
 * Owner request (2026-11-13): the hero's old helper sentence is replaced by six
 * short value-prop labels (icon + label only, no descriptions). Icons are inline
 * Material Symbols from Google Fonts Icons (Apache-2.0) rendered by
 * partials/icon.php — no emoji and no external font/CDN request (strict CSP +
 * offline PWA stay intact).
 */

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$landingValues = [
    ['icon' => 'access-lock', 'label' => 'Automated Access'],
    ['icon' => 'payments', 'label' => 'Frictionless Payment'],
    ['icon' => 'qr-scanner', 'label' => 'On spot Recognition'],
    ['icon' => 'campaign', 'label' => 'Instant Alerts'],
    ['icon' => 'monitoring', 'label' => 'Financial &amp; Security Logs'],
    ['icon' => 'block', 'label' => 'Blacklisting'],
];
?>
<div class="landing landing--reference">
  <section class="landing-hero" aria-labelledby="landing-title">
    <div class="landing-hero__copy landing-hero__content">
      <span class="landing-rule" aria-hidden="true"></span>
      <p class="landing-hero__eyebrow"><?= $icon('sparkle', 'ico ico--sm') ?> PASS &amp; PAY AUTOMATION</p>
      <h1 id="landing-title"><span class="landing-headline__line">DashPASS: Pass &amp; Pay</span> <span class="landing-headline__line">Management System</span></h1>
      <p class="landing-hero__lede">
        Invite visitors, manage gate access and keep every arrival accountable —
        without the queue.
      </p>
      <div class="landing-hero__actions">
        <a class="btn btn--primary btn--lg" id="check-in" href="<?= e('/login') ?>">Check in</a>
        <a class="btn btn--outline btn--lg" id="scan-qr" href="<?= e('/login?intent=scan') ?>">Scan QR</a>
      </div>

      <section class="landing-values landing-values--compact" aria-labelledby="landing-values-title">
        <h2 class="visually-hidden" id="landing-values-title">Why teams choose DashPASS</h2>
        <?php foreach ($landingValues as $landingValue): ?>
          <div class="landing-value">
            <span class="landing-value__icon" aria-hidden="true"><?= $icon($landingValue['icon'], 'ico ico--sm') ?></span>
            <h2><?= $landingValue['label'] ?></h2>
          </div>
        <?php endforeach; ?>
      </section>
    </div>
    <div class="landing-hero__visual">
      <span class="landing-accent landing-accent--diamond" aria-hidden="true"></span>
      <img class="landing-hero__image" src="<?= e(asset('img/landing-hero-dashpass.webp')) ?>"
           alt="Resident scanning their DashPASS QR code on a phone — it's super simple"
           width="1455" height="1345" decoding="async">
      <span class="landing-accent landing-accent--dot" aria-hidden="true"></span>
    </div>
  </section>
</div>
