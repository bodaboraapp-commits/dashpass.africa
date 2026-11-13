<?php

declare(strict_types=1);

/**
 * Public "Delete my data" page (guest layout).
 *
 * A direct, linkable page for requesting erasure — the place to point app-store
 * data-deletion requirements, support replies and the footer link at. The form
 * posts to /privacy/choices with the deletion request type; the server records
 * the request, issues an opaque reference and notifies the privacy inbox.
 *
 * Variables: $errors, $old, $feedback, $privacyEmail, $dpoEmail, $supportEmail,
 * $responseDays, $policyVersion, $policyEffective.
 */

$legalActive = 'deletion';
$privacyEmail = isset($privacyEmail) ? (string) $privacyEmail : 'privacy@dashpass.africa';
$dpoEmail = isset($dpoEmail) ? (string) $dpoEmail : $privacyEmail;
$supportEmail = isset($supportEmail) ? (string) $supportEmail : $privacyEmail;
$policyVersion = isset($policyVersion) ? (string) $policyVersion : '1.0';
$policyEffective = isset($policyEffective) ? (string) $policyEffective : '13 November 2026';
$responseDays = isset($responseDays) ? max(1, (int) $responseDays) : 30;

$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$activeForm = isset($old['form']) && is_scalar($old['form']) ? (string) $old['form'] : '';

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$toc = [
    ['id' => 'deletion-scope', 'label' => 'What deletion means'],
    ['id' => 'request-deletion', 'label' => 'Request deletion'],
];
?>
<div class="legal-page">
  <a class="about-back" href="<?= e('/') ?>">
    <?= $icon('arrow-left', 'ico ico--sm') ?>
    Back to home
  </a>

  <header class="legal-hero">
    <p class="landing-hero__eyebrow"><?= $icon('close', 'ico ico--sm') ?> PRIVACY · DELETION</p>
    <h1>Delete my data</h1>
    <p class="legal-meta">
      Notice version <?= e($policyVersion) ?> · Effective <?= e($policyEffective) ?>
    </p>
    <p class="page-lede">
      Use this page to ask <?= e((string) ($legalController ?? 'DashPASS')) ?> to delete your
      personal data. You do not need an account to make the request. We verify your identity and
      respond within <?= e((string) $responseDays) ?> days.
    </p>
  </header>

  <div class="legal-layout">
    <?= \App\Core\View::capture('marketing/_legal-sidebar', [
        'legalActive' => $legalActive,
        'toc' => $toc,
        'privacyEmail' => $privacyEmail,
        'supportEmail' => $supportEmail,
        'responseDays' => $responseDays,
        'sideTitle' => 'Not sure?',
        'sideText' => 'Read the Data Policy or email us before submitting a deletion request.',
        'sideHref' => '/data-policy',
        'sideLabel' => 'Read the Data Policy',
    ]) ?>

    <div class="legal-main">
      <?= \App\Core\View::capture('marketing/_privacy-feedback', [
          'feedback' => $feedback ?? [],
          'errors' => $errors,
          'requestTypeLabels' => $requestTypeLabels ?? [],
          'responseDays' => $responseDays,
          'privacyEmail' => $privacyEmail,
      ]) ?>

      <section class="legal-grid" id="deletion-scope" aria-label="What deletion means">
    <article class="card privacy-note" aria-labelledby="deleted-title">
      <h2 class="card__title" id="deleted-title"><?= $icon('check', 'ico ico--sm') ?> What we delete</h2>
      <ul class="legal-list legal-list--compact">
        <li>Your account and profile (name, phone, optional email, sign-in history).</li>
        <li>Household and membership links we hold for you, subject to your estate's records.</li>
        <li>Optional-communication preferences and consent records.</li>
        <li>Data we no longer need for the purpose it was collected for.</li>
      </ul>
      <p class="muted">
        Where your estate is the controller of a record (for example a household or billing
        record), we refer the request to the estate and support its response.
      </p>
    </article>
    <article class="card privacy-note" aria-labelledby="retained-title">
      <h2 class="card__title" id="retained-title"><?= $icon('lock', 'ico ico--sm') ?> What we may keep</h2>
      <ul class="legal-list legal-list--compact">
        <li>Invoices, payments and tax records for the statutory accounting period (at least 5 years).</li>
        <li>Security and incident records needed for an open investigation, legal claim or safety duty.</li>
        <li>A minimal record of this deletion request, for accountability.</li>
      </ul>
      <p class="muted">
        Retained records are restricted to that purpose and are not used for anything else. They are
        deleted when the retention period ends.
      </p>
    </article>
  </section>

  <section class="card privacy-card privacy-card--danger" id="request-deletion" aria-labelledby="request-deletion-title">
    <h2 class="card__title" id="request-deletion-title">
      <?= $icon('close', 'ico ico--sm') ?> Request deletion
    </h2>
    <p class="muted">
      Tell us which phone number and, if you know it, which email address or estate your data
      relates to. If you are a visitor whose details were recorded by a household, include the
      estate name and visit date so we can locate the record.
    </p>

    <form method="post" action="<?= e('/privacy/choices') ?>" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="return_to" value="/data-deletion">
      <input type="hidden" name="form" value="deletion">
      <input type="hidden" name="request_type" value="deletion">

      <div hidden aria-hidden="true">
        <label for="privacy_deletion_website">Website</label>
        <input id="privacy_deletion_website" name="website" type="text" tabindex="-1" autocomplete="off">
      </div>

      <?= \App\Core\View::capture('marketing/_privacy-contact-fields', [
          'form' => 'deletion',
          'old' => $old,
          'activeForm' => $activeForm,
          'errors' => $errors,
          'messageLabel' => 'Which data should be deleted? (estate, household or visit details)',
          'messageRequired' => false,
          'showConfirm' => true,
      ]) ?>

      <div class="form-actions">
        <button type="submit" class="btn btn--danger-ghost btn--lg">
          <?= $icon('close', 'ico ico--sm') ?>
          Submit deletion request
        </button>
        <a class="btn btn--outline btn--lg" href="<?= e('/privacy/choices') ?>">Just manage my preferences</a>
      </div>
    </form>
  </section>

      <p class="legal-contact muted">
        Need help first? Email <a href="mailto:<?= e($privacyEmail) ?>"><?= e($privacyEmail) ?></a>
        (data protection) or <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>
        (general support). Data Protection Officer:
        <a href="mailto:<?= e($dpoEmail) ?>"><?= e($dpoEmail) ?></a>.
        Read the <a href="<?= e('/data-policy') ?>">Data Policy</a> for the full deletion process.
      </p>
    </div>
  </div>
</div>
