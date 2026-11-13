<?php

declare(strict_types=1);

/**
 * Public Privacy Choices page (guest layout).
 *
 * No-JS, CSRF-protected forms that let anyone:
 *  - opt in or opt out of optional communications (consent), and
 *  - request access to or correction of their personal data.
 * Deletion has its own dedicated page (/data-deletion) so it can be linked
 * directly (for example from an app store data-deletion requirement).
 *
 * Variables: $errors, $old, $feedback, $requestTypeLabels, $privacyEmail,
 * $supportEmail, $responseDays, $policyVersion, $policyEffective.
 */

$legalActive = 'choices';
$privacyEmail = isset($privacyEmail) ? (string) $privacyEmail : 'privacy@dashpass.africa';
$supportEmail = isset($supportEmail) ? (string) $supportEmail : $privacyEmail;
$policyVersion = isset($policyVersion) ? (string) $policyVersion : '1.0';
$policyEffective = isset($policyEffective) ? (string) $policyEffective : '13 November 2026';
$responseDays = isset($responseDays) ? max(1, (int) $responseDays) : 30;

$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];

$oldValue = static function (string $field) use ($old): string {
    $value = $old[$field] ?? '';

    return is_scalar($value) ? trim((string) $value) : '';
};

$activeForm = $oldValue('form');

/**
 * Field errors are only shown in the form that was actually submitted, so a
 * failed communications submission never decorates the rights form (and vice
 * versa). $form is one of communications|rights|deletion.
 *
 * @return array<int, string>
 */
$formErrors = static function (string $form, string $field) use ($errors, $activeForm): array {
    if ($activeForm !== $form) {
        return [];
    }

    $messages = $errors[$field] ?? [];

    if (is_string($messages)) {
        $messages = [$messages];
    }

    return is_array($messages)
        ? array_values(array_map('strval', array_filter($messages, 'is_scalar')))
        : [];
};

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$toc = [
    ['id' => 'essential', 'label' => 'Essential messages'],
    ['id' => 'communications', 'label' => 'Opt in or opt out'],
    ['id' => 'data-rights', 'label' => 'Access or correct data'],
    ['id' => 'delete', 'label' => 'Delete my data'],
];
?>
<div class="doc-page">
  <a class="about-back" href="<?= e('/') ?>">
    <?= $icon('arrow-left', 'ico ico--sm') ?>
    Back to home
  </a>

  <header class="doc-hero">
    <p class="landing-hero__eyebrow"><?= $icon('settings', 'ico ico--sm') ?> PRIVACY CENTRE</p>
    <h1>Your privacy choices</h1>
    <p class="doc-meta">
      Notice version <?= e($policyVersion) ?> · Effective <?= e($policyEffective) ?>
    </p>
    <p class="page-lede">
      You are in control of optional communications and of the personal data we hold about you.
      Choose what suits you below. No account or password is needed.
    </p>
  </header>

  <div class="doc-layout">
    <?= \App\Core\View::capture('marketing/_doc-sidebar', [
        'legalActive' => $legalActive,
        'toc' => $toc,
        'privacyEmail' => $privacyEmail,
        'supportEmail' => $supportEmail,
        'responseDays' => $responseDays,
        'sideTitle' => 'Prefer email?',
        'sideText' => 'Send your request to our data protection team and we will confirm it in writing.',
        'sideHref' => '/data-deletion',
        'sideLabel' => 'Delete my data',
    ]) ?>

    <div class="doc-main">
      <?= \App\Core\View::capture('marketing/_privacy-feedback', [
          'feedback' => $feedback ?? [],
          'errors' => $errors,
          'requestTypeLabels' => $requestTypeLabels ?? [],
          'responseDays' => $responseDays,
          'privacyEmail' => $privacyEmail,
      ]) ?>

      <section class="doc-grid" id="essential" aria-label="What you can control">
    <article class="card privacy-note" aria-labelledby="essential-title">
      <h2 class="card__title" id="essential-title"><?= $icon('lock', 'ico ico--sm') ?> Essential messages</h2>
      <p class="muted">
        Some messages are part of the service and cannot be opted out of: sign-in codes, security
        and access alerts, bills and notices from your estate, and important changes to the
        platform. These are sent because you or your estate rely on them.
      </p>
    </article>
    <article class="card privacy-note" aria-labelledby="optional-title">
      <h2 class="card__title" id="optional-title"><?= $icon('mail', 'ico ico--sm') ?> Optional messages</h2>
      <p class="muted">
        Product updates, surveys and estate offers are optional. They are sent only if you opt in,
        and you can opt out at any time. Opting out never affects essential messages or your access
        to the platform.
      </p>
    </article>
  </section>

  <section class="card privacy-card" id="communications" aria-labelledby="communications-title">
    <h2 class="card__title" id="communications-title">
      <?= $icon('mail', 'ico ico--sm') ?> Optional communications: opt in or opt out
    </h2>
    <p class="muted">
      Choose one option and tell us the phone number (and optionally email) we hold for you. We
      verify your identity before changing a preference.
    </p>

    <form method="post" action="<?= e('/privacy/choices') ?>" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="return_to" value="/privacy/choices">
      <input type="hidden" name="form" value="communications">

      <?php /* Honeypot: hidden from people and assistive tech; bots that fill it are dropped. */ ?>
      <div hidden aria-hidden="true">
        <label for="privacy_communications_website">Website</label>
        <input id="privacy_communications_website" name="website" type="text" tabindex="-1" autocomplete="off">
      </div>

      <fieldset class="field<?= $formErrors('communications', 'consent_choice') !== [] ? ' field--invalid' : '' ?>">
        <legend class="field__label">Your choice</legend>
        <div class="privacy-choices">
          <label class="privacy-choice">
            <input type="radio" name="consent_choice" value="opt_in" required
                   <?= $oldValue('request_type') === 'opt_in' ? 'checked' : '' ?>
                   <?= $formErrors('communications', 'consent_choice') !== [] ? 'aria-invalid="true"' : '' ?>>
            <span>
              <strong>Opt in</strong>
              <span class="privacy-choice__hint">Send me product updates, surveys and estate offers by email or SMS.</span>
            </span>
          </label>
          <label class="privacy-choice">
            <input type="radio" name="consent_choice" value="opt_out" required
                   <?= $oldValue('request_type') === 'opt_out' ? 'checked' : '' ?>
                   <?= $formErrors('communications', 'consent_choice') !== [] ? 'aria-invalid="true"' : '' ?>>
            <span>
              <strong>Opt out</strong>
              <span class="privacy-choice__hint">Do not send me optional communications. Essential messages continue.</span>
            </span>
          </label>
        </div>
        <?php foreach ($formErrors('communications', 'consent_choice') as $message): ?>
          <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
        <?php endforeach; ?>
      </fieldset>

      <?= \App\Core\View::capture('marketing/_privacy-contact-fields', [
          'form' => 'communications',
          'old' => $old,
          'activeForm' => $activeForm,
          'errors' => $errors,
          'messageLabel' => 'Anything we should know? (optional)',
          'messageRequired' => false,
      ]) ?>

      <div class="form-actions">
        <button type="submit" class="btn btn--primary btn--lg">
          <?= $icon('check', 'ico ico--sm') ?>
          Save my preference
        </button>
      </div>
    </form>
  </section>

  <section class="card privacy-card" id="data-rights" aria-labelledby="data-rights-title">
    <h2 class="card__title" id="data-rights-title">
      <?= $icon('download', 'ico ico--sm') ?> Access or correct my data
    </h2>
    <p class="muted">
      Ask for a copy of the personal data we hold about you, or ask us to correct something that
      is wrong or incomplete. For corrections, describe exactly what should change.
    </p>

    <form method="post" action="<?= e('/privacy/choices') ?>" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="return_to" value="/privacy/choices">
      <input type="hidden" name="form" value="rights">

      <div hidden aria-hidden="true">
        <label for="privacy_rights_website">Website</label>
        <input id="privacy_rights_website" name="website" type="text" tabindex="-1" autocomplete="off">
      </div>

      <div class="field<?= $formErrors('rights', 'request_type') !== [] ? ' field--invalid' : '' ?>">
        <label class="field__label" for="privacy_rights_type">What would you like to do?</label>
        <select class="input<?= $formErrors('rights', 'request_type') !== [] ? ' input--invalid' : '' ?>"
                id="privacy_rights_type" name="request_type" required
                <?= $formErrors('rights', 'request_type') !== [] ? 'aria-invalid="true"' : '' ?>>
          <option value="">Choose an option</option>
          <option value="access" <?= $oldValue('request_type') === 'access' ? 'selected' : '' ?>>Send me a copy of my data (access request)</option>
          <option value="correction" <?= $oldValue('request_type') === 'correction' ? 'selected' : '' ?>>Correct my data</option>
        </select>
        <?php foreach ($formErrors('rights', 'request_type') as $message): ?>
          <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
        <?php endforeach; ?>
      </div>

      <?= \App\Core\View::capture('marketing/_privacy-contact-fields', [
          'form' => 'rights',
          'old' => $old,
          'activeForm' => $activeForm,
          'errors' => $errors,
          'messageLabel' => 'Details (required for a correction, optional otherwise)',
          'messageRequired' => false,
      ]) ?>

      <div class="form-actions">
        <button type="submit" class="btn btn--primary btn--lg">
          <?= $icon('mail', 'ico ico--sm') ?>
          Send my request
        </button>
      </div>
    </form>
  </section>

  <section class="card privacy-card privacy-card--danger" id="delete" aria-labelledby="delete-title">
    <h2 class="card__title" id="delete-title">
      <?= $icon('close', 'ico ico--sm') ?> Delete my data
    </h2>
    <p class="muted">
      You can ask us to delete your personal data. Deletion removes your live account data and the
      data we no longer need; a limited set of records may be kept where the law requires it, such
      as billing and tax records or an open security investigation. We explain exactly what
      happens before you submit the request.
    </p>
    <div class="form-actions">
      <a class="btn btn--danger-ghost btn--lg" href="<?= e('/data-deletion') ?>">
        <?= $icon('close', 'ico ico--sm') ?>
        Go to the delete my data page
      </a>
    </div>
  </section>

      <p class="doc-contact muted">
        Prefer email? Write to <a href="mailto:<?= e($privacyEmail) ?>"><?= e($privacyEmail) ?></a>
        or for general help <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.
        Requests are answered within <?= e((string) $responseDays) ?> days.
      </p>
    </div>
  </div>
</div>
