<?php

declare(strict_types=1);

/**
 * Public About page (guest layout). Semantic marketing page for DashPASS —
 * Pass & Pay positioning, the six value props (same Material icons as the
 * landing page) and who the product serves.
 */

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$aboutValues = [
    ['icon' => 'access-lock', 'label' => 'Automated Access'],
    ['icon' => 'payments', 'label' => 'Frictionless Payment'],
    ['icon' => 'qr-scanner', 'label' => 'On spot Recognition'],
    ['icon' => 'campaign', 'label' => 'Instant Alerts'],
    ['icon' => 'monitoring', 'label' => 'Financial &amp; Security Logs'],
    ['icon' => 'block', 'label' => 'Blacklisting'],
];

$supportEmail = (string) config('app.support_email', 'hello@dashpass.africa');
?>
<div class="about-page stack stack--loose">
  <a class="about-back" href="<?= e('/') ?>">
    <?= $icon('arrow-left', 'ico ico--sm') ?>
    Back to home
  </a>

  <header class="stack stack--tight">
    <p class="landing-hero__eyebrow"><?= $icon('sparkle', 'ico ico--sm') ?> ABOUT DASHPASS</p>
    <h1>About DashPASS</h1>
    <p class="page-lede">
      DashPASS is the Pass &amp; Pay management system for estates. One secure place to automate
      access, take payments and keep a complete, auditable record of every arrival.
    </p>
  </header>

  <section class="stack" aria-labelledby="about-what-title">
    <h2 id="about-what-title">What DashPASS does</h2>
    <p class="muted">
      DashPASS replaces paper visitor books and cash-at-the-gate with a single system. Residents
      pre-register visitors, invitees receive a single-use pass with a QR code or PIN, and guards
      verify it at the gate in seconds. Every check-in and check-out is recorded against the
      household that hosted it.
    </p>
    <p class="muted">
      On the payment side, DashPASS issues bills and payment links so residents can pay from their
      phone while estate administrators see the same ledger. Passes and payments share one record,
      which makes reconciliation and reporting straightforward.
    </p>
  </section>

  <section class="stack" aria-labelledby="about-values-title">
    <h2 id="about-values-title">Why estates choose DashPASS</h2>
    <div class="landing-values">
      <?php foreach ($aboutValues as $aboutValue): ?>
        <div class="landing-value">
          <span class="landing-value__icon" aria-hidden="true"><?= $icon($aboutValue['icon'], 'ico ico--sm') ?></span>
          <h2><?= $aboutValue['label'] ?></h2>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="stack" aria-labelledby="about-who-title">
    <h2 id="about-who-title">Who it serves</h2>
    <ul class="stack--tight">
      <li><strong>Residents and tenants</strong> — invite visitors, share passes and pay bills from one app.</li>
      <li><strong>Estate administrators</strong> — manage buildings, households, residents, notices and billing.</li>
      <li><strong>Security teams and guards</strong> — verify passes at the gate and see who is on site right now.</li>
      <li><strong>Platform operators</strong> — provision estates, plans and subscriptions across the platform.</li>
    </ul>
  </section>

  <section class="card stack" aria-labelledby="about-cta-title">
    <h2 class="card__title" id="about-cta-title">Ready when you are</h2>
    <p class="muted">Sign in to check in, or talk to us about bringing DashPASS to your estate.</p>
    <div class="cluster">
      <a class="btn btn--primary btn--lg" href="<?= e('/login') ?>">Check in</a>
      <a class="btn btn--outline btn--lg" href="<?= e('/help') ?>">Contact us</a>
    </div>
    <p class="muted">Email <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.</p>
  </section>
</div>
