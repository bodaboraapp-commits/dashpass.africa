<?php

declare(strict_types=1);

/**
 * Public single-screen landing page (guest layout), cloned from the reference:
 * brand/nav header, two-column hero, rounded estate image and slim footer.
 * Auth/Firebase stays on /login; About content lives on /about.
 */

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<div class="landing landing--reference">
  <section class="landing-hero" aria-labelledby="landing-title">
    <div class="landing-hero__copy">
      <span class="landing-rule" aria-hidden="true"></span>
      <p class="landing-hero__eyebrow"><?= $icon('sparkle', 'ico ico--sm') ?> ESTATE ACCESS &amp; VISITOR MANAGEMENT</p>
      <h1 id="landing-title"><span class="landing-headline__line">DashPASS: Visitor Pass</span> <span class="landing-headline__line">Management System</span></h1>
      <p class="landing-hero__lede">
        Invite visitors, manage gate access and keep every arrival accountable —
        without the queue.
      </p>
      <div class="landing-hero__actions">
        <a class="btn btn--primary btn--lg" href="<?= e('/login') ?>">Check in</a>
        <a class="btn btn--outline btn--lg" href="<?= e('/login?intent=scan') ?>">Scan QR</a>
      </div>
      <p class="landing-hero__note"><?= $icon('shield', 'ico ico--sm') ?> Sign in to check in or scan a visitor pass.</p>
    </div>
    <div class="landing-hero__visual">
      <span class="landing-accent landing-accent--diamond" aria-hidden="true"></span>
      <img class="landing-hero__image" src="<?= e(asset('img/estate-hero.png')) ?>" alt="" width="1200" height="675" decoding="async">
      <span class="landing-accent landing-accent--dot" aria-hidden="true"></span>
    </div>
  </section>
</div>
