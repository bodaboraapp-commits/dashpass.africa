<?php

declare(strict_types=1);

/**
 * Public single-screen landing page (guest layout), cloned from the reference:
 * brand/nav header, two-column hero, rounded estate image and slim footer.
 * Auth/Firebase stays on /login; About content lives on /about.
 *
 * Owner request (2026-11-13): the old "Sign in to check in or scan a visitor
 * pass." helper is replaced by five short value-prop rows in the hero copy.
 * Icons are inline Material Symbols from Google Fonts Icons (Apache-2.0)
 * rendered by partials/icon.php — no emoji and no external font/CDN request
 * (strict CSP + offline PWA stay intact).
 */

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
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

        <div class="landing-value">
          <span class="landing-value__icon" aria-hidden="true"><?= $icon('access-lock', 'ico ico--sm') ?></span>
          <h2>Automated Access Rules</h2>
          <p>Set strict entry windows, single-use passes, or recurring schedules for contractors, tenants, and guests.</p>
        </div>

        <div class="landing-value">
          <span class="landing-value__icon" aria-hidden="true"><?= $icon('payments', 'ico ico--sm') ?></span>
          <h2>Frictionless Payment Collection</h2>
          <p>Collect parking fees, event tickets, or facility access charges automatically before the barrier lifts.</p>
        </div>

        <div class="landing-value">
          <span class="landing-value__icon" aria-hidden="true"><?= $icon('qr-scanner', 'ico ico--sm') ?></span>
          <h2>Instant QR &amp; Plate Recognition</h2>
          <p>Scan passes with any smartphone or integrate with automated boom barriers and turnstiles.</p>
        </div>

        <div class="landing-value">
          <span class="landing-value__icon" aria-hidden="true"><?= $icon('monitoring', 'ico ico--sm') ?></span>
          <h2>Real-Time Financial &amp; Security Logs</h2>
          <p>Reconcile daily collections and audit visitor traffic with one-click automated reports.</p>
        </div>

        <div class="landing-value">
          <span class="landing-value__icon" aria-hidden="true"><?= $icon('campaign', 'ico ico--sm') ?></span>
          <h2>Instant Alerts &amp; Blacklisting</h2>
          <p>Flag unauthorized attempts, expired passes, or overstayed vehicles immediately.</p>
        </div>
      </section>
    </div>
    <div class="landing-hero__visual">
      <span class="landing-accent landing-accent--diamond" aria-hidden="true"></span>
      <img class="landing-hero__image" src="<?= e(asset('img/landing-hero-dashpass.webp')) ?>"
           alt="Resident scanning their DashPASS QR code on a phone — it's super simple"
           width="1514" height="1332" decoding="async">
      <span class="landing-accent landing-accent--dot" aria-hidden="true"></span>
    </div>
  </section>
</div>
