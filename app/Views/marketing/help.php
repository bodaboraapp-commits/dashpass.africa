<?php

declare(strict_types=1);

/**
 * Public Help centre (guest layout): native <details> FAQ + contact form.
 *
 * The FAQ works without JavaScript; the contact form is a plain CSRF-protected
 * POST handled by HelpController::contact(). Variables (all defensive):
 * $errors (field => messages), $old (field => value), $success, $sendError.
 */

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$success = isset($success) && is_string($success) ? trim($success) : '';
$sendError = isset($sendError) && is_string($sendError) ? trim($sendError) : '';
$supportEmail = (string) config('app.support_email', 'hello@dashpass.africa');

$fieldErrors = static function (string $field) use ($errors): array {
    $messages = $errors[$field] ?? [];

    if (is_string($messages)) {
        $messages = [$messages];
    }

    return is_array($messages)
        ? array_values(array_map('strval', array_filter($messages, 'is_scalar')))
        : [];
};

$oldValue = static function (string $field) use ($old): string {
    $value = $old[$field] ?? '';

    return is_scalar($value) ? trim((string) $value) : '';
};

$allErrors = [];
foreach ($errors as $messages) {
    if (is_string($messages)) {
        $allErrors[] = $messages;
    } elseif (is_array($messages)) {
        foreach ($messages as $message) {
            if (is_scalar($message) && trim((string) $message) !== '') {
                $allErrors[] = trim((string) $message);
            }
        }
    }
}
$allErrors = array_values(array_unique($allErrors));

$faqs = [
    [
        'question' => 'How does check-in work?',
        'answer' => 'Residents pre-register a visit and the visitor receives a single-use pass with a QR code or a PIN. At the gate the guard verifies the pass in DashPASS, and the arrival is recorded against the hosting household.',
    ],
    [
        'question' => 'How do visitor passes and QR/PIN codes work?',
        'answer' => 'Every invitation issues its own pass. The QR code and the six-digit PIN both point at the same single-use credential, so either can be used at the gate. A pass expires with the invitation and can be revoked or rotated at any time by the resident who created it.',
    ],
    [
        'question' => 'Can I use my pass offline?',
        'answer' => 'Yes — if you save the pass on your device while online, the encrypted QR image and (only if you explicitly save it) the PIN stay available in your browser when the network drops. DashPASS never stores visitor credentials in plain text, and signing out clears them from the device.',
    ],
    [
        'question' => 'How do payments work?',
        'answer' => 'Your estate office issues bills with a payment link. Pay from your phone using the link on the bill, and the estate reconciles the payment against the same ledger. Always use the payment link shown on your bill, or contact the estate office if a link is unavailable.',
    ],
    [
        'question' => 'How do I invite a visitor?',
        'answer' => 'Open Visitors in the resident app and choose Invite visitor. Add the visitor details and the visit window, and share the pass with your guest. If the estate supports visitor parking, the reserved bay is confirmed with the invitation.',
    ],
    [
        'question' => 'How do I get an account or a sign-in code?',
        'answer' => 'Your phone number is registered by your estate administrator. Sign in with that number and a one-time code sent by SMS — there is no password. If your number is not recognised, ask your estate office to add or reactivate it.',
    ],
    [
        'question' => 'What can estate administrators manage?',
        'answer' => 'Estate administrators manage buildings, households and residents, guards and gates, parking, notices and estate billing from the admin console. Access is role-based, so each person only sees the tools their permissions allow.',
    ],
    [
        'question' => 'How is my data kept secure — and how do I contact support?',
        'answer' => 'Passes are single-use, PINs are stored only as keyed hashes and QR credentials are encrypted at rest. Access is role-based and every access and payment change is written to an append-only audit log. For privacy or support questions, use the contact form below or email us.',
    ],
];
?>
<div class="about-page stack stack--loose">
  <a class="about-back" href="<?= e('/') ?>">
    <?= $icon('arrow-left', 'ico ico--sm') ?>
    Back to home
  </a>

  <header class="stack stack--tight">
    <p class="landing-hero__eyebrow"><?= $icon('info', 'ico ico--sm') ?> HELP &amp; SUPPORT</p>
    <h1>Help centre</h1>
    <p class="page-lede">
      Answers to the most common DashPASS questions. If you cannot find what you need,
      send us a message and we will get back to you.
    </p>
  </header>

  <section class="stack" aria-labelledby="help-faq-title">
    <h2 id="help-faq-title">Frequently asked questions</h2>

    <?php foreach ($faqs as $faq): ?>
      <details class="disclosure">
        <summary><?= e($faq['question']) ?></summary>
        <div class="disclosure__body">
          <p class="muted"><?= e($faq['answer']) ?></p>
        </div>
      </details>
    <?php endforeach; ?>
  </section>

  <section class="card stack" id="contact" aria-labelledby="help-contact-title">
    <h2 class="card__title" id="help-contact-title">Contact us</h2>
    <p class="muted">
      Send us a message and the DashPASS team will reply by email. We normally respond within
      two working days.
    </p>

    <?php if ($success !== ''): ?>
      <div class="alert alert--success" role="status">
        <?= $icon('check', 'ico ico--sm') ?>
        <div class="alert__body"><p><?= e($success) ?></p></div>
      </div>
    <?php endif; ?>

    <?php if ($sendError !== ''): ?>
      <div class="alert alert--error" role="alert">
        <?= $icon('alert', 'ico ico--sm') ?>
        <div class="alert__body"><p><?= e($sendError) ?></p></div>
      </div>
    <?php endif; ?>

    <?php if ($allErrors !== []): ?>
      <div class="form-error-summary" role="alert">
        <div>
          <p class="alert__title">Please check the highlighted fields</p>
          <ul>
            <?php foreach ($allErrors as $errorLine): ?>
              <li><?= e($errorLine) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e('/help/contact') ?>" class="stack">
      <?= csrf_field() ?>

      <?php /* Honeypot: hidden from people and assistive tech; bots that fill it are dropped. */ ?>
      <div hidden aria-hidden="true">
        <label for="help_contact_website">Website</label>
        <input id="help_contact_website" name="website" type="text" tabindex="-1" autocomplete="off">
      </div>

      <div class="field-grid">
        <div class="field<?= $fieldErrors('name') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="help_contact_name">Your name</label>
          <input class="input<?= $fieldErrors('name') !== [] ? ' input--invalid' : '' ?>"
                 id="help_contact_name" name="name" type="text" required
                 minlength="2" maxlength="120" autocomplete="name"
                 value="<?= e($oldValue('name')) ?>"
                 <?= $fieldErrors('name') !== [] ? 'aria-invalid="true"' : '' ?>>
          <?php foreach ($fieldErrors('name') as $message): ?>
            <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
          <?php endforeach; ?>
        </div>

        <div class="field<?= $fieldErrors('email') !== [] ? ' field--invalid' : '' ?>">
          <label class="field__label" for="help_contact_email">Email address</label>
          <input class="input<?= $fieldErrors('email') !== [] ? ' input--invalid' : '' ?>"
                 id="help_contact_email" name="email" type="email" required
                 maxlength="190" autocomplete="email"
                 value="<?= e($oldValue('email')) ?>"
                 <?= $fieldErrors('email') !== [] ? 'aria-invalid="true"' : '' ?>>
          <?php foreach ($fieldErrors('email') as $message): ?>
            <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="field<?= $fieldErrors('subject') !== [] ? ' field--invalid' : '' ?>">
        <label class="field__label" for="help_contact_subject">Subject</label>
        <input class="input<?= $fieldErrors('subject') !== [] ? ' input--invalid' : '' ?>"
               id="help_contact_subject" name="subject" type="text" required
               minlength="3" maxlength="120"
               value="<?= e($oldValue('subject')) ?>"
               <?= $fieldErrors('subject') !== [] ? 'aria-invalid="true"' : '' ?>>
        <?php foreach ($fieldErrors('subject') as $message): ?>
          <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
        <?php endforeach; ?>
      </div>

      <div class="field<?= $fieldErrors('message') !== [] ? ' field--invalid' : '' ?>">
        <label class="field__label" for="help_contact_message">Message</label>
        <textarea class="input<?= $fieldErrors('message') !== [] ? ' input--invalid' : '' ?>"
                  id="help_contact_message" name="message" rows="6" required
                  minlength="10" maxlength="4000"
                  <?= $fieldErrors('message') !== [] ? 'aria-invalid="true"' : '' ?>><?= e($oldValue('message')) ?></textarea>
        <?php foreach ($fieldErrors('message') as $message): ?>
          <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
        <?php endforeach; ?>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn--primary btn--lg">
          <?= $icon('mail', 'ico ico--sm') ?>
          Send message
        </button>
      </div>
    </form>

    <p class="muted">
      Prefer email? Write to <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a>.
    </p>
  </section>
</div>
