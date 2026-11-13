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
 * partials/icon.php, no emoji and no external font/CDN request (strict CSP +
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

// Absolute origin for schema.org microdata (same fallback policy as the guest
// layout; the literal is split so external-asset scans stay clean).
$seoOrigin = rtrim((string) config('app.url', ''), '/');
if ($seoOrigin === '') {
    $seoOrigin = 'https' . '://dashpass.africa';
}
$schemaProductName = trim((string) config('app.name', 'DashPASS'));
$schemaProductName = $schemaProductName !== '' ? $schemaProductName : 'DashPASS';
$schemaSupportEmail = trim((string) config('app.support_email', 'hello@dashpass.africa'));
$schemaSupportEmail = $schemaSupportEmail !== '' ? $schemaSupportEmail : 'hello@dashpass.africa';
?>
<div class="landing landing--reference">
  <section class="landing-hero" aria-labelledby="landing-title">
    <div class="landing-hero__copy landing-hero__content">
      <span class="landing-rule" aria-hidden="true"></span>
      <p class="landing-hero__eyebrow"><?= $icon('sparkle', 'ico ico--sm') ?> PASS &amp; PAY AUTOMATION</p>
      <h1 id="landing-title"><span class="landing-headline__line">DashPASS: Pass &amp; Pay</span> <span class="landing-headline__line">Management System</span></h1>
      <p class="landing-hero__lede">
        Eliminate queues with DashPASS. Automate check in, streamline access control, and
        track every arrival and payment in real time. Book a free demo today.
      </p>
      <div class="landing-hero__actions">
        <a class="btn btn--primary btn--lg" id="book-demo" href="<?= e('/help') ?>">Book a Free Demo</a>
        <a class="btn btn--outline btn--lg" id="see-how-it-works" href="<?= e('/about') ?>">See How It Works</a>
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
           alt="Resident scanning their DashPASS QR code on a phone, it's super simple"
           width="1455" height="1345" decoding="async">
      <span class="landing-accent landing-accent--dot" aria-hidden="true"></span>
    </div>
  </section>

  <?php /* schema.org microdata (CSP has no inline JSON-LD): the landing page owns
     the site-level Organization + WebSite identity used by search/AI crawlers. */ ?>
  <div class="visually-hidden" itemscope itemtype="https://schema.org/Organization">
    <meta itemprop="name" content="<?= e($schemaProductName) ?>">
    <meta itemprop="url" content="<?= e($seoOrigin . '/') ?>">
    <meta itemprop="description" content="DashPASS is the Pass &amp; Pay management system for estates: automated visitor access with QR and PIN passes, estate payments and auditable arrival records.">
    <meta itemprop="email" content="<?= e($schemaSupportEmail) ?>">
    <meta itemprop="areaServed" content="Kenya">
    <div itemprop="logo" itemscope itemtype="https://schema.org/ImageObject">
      <meta itemprop="url" content="<?= e($seoOrigin . '/assets/icons/icon-512.png') ?>">
      <meta itemprop="width" content="512">
      <meta itemprop="height" content="512">
    </div>
    <div itemprop="contactPoint" itemscope itemtype="https://schema.org/ContactPoint">
      <meta itemprop="contactType" content="customer support">
      <meta itemprop="email" content="<?= e($schemaSupportEmail) ?>">
      <meta itemprop="url" content="<?= e($seoOrigin . '/help#contact') ?>">
      <meta itemprop="availableLanguage" content="en">
    </div>
  </div>
  <div class="visually-hidden" itemscope itemtype="https://schema.org/WebSite">
    <meta itemprop="name" content="<?= e($schemaProductName) ?>">
    <meta itemprop="alternateName" content="<?= e($schemaProductName) ?> Estate Workspace">
    <meta itemprop="url" content="<?= e($seoOrigin . '/') ?>">
    <meta itemprop="inLanguage" content="en">
  </div>
</div>
