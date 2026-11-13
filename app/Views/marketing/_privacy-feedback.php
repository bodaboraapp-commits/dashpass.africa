<?php

declare(strict_types=1);

/**
 * Success / validation feedback for the privacy-choice forms.
 *
 * Variables: $feedback (reference, request_type), $errors (field => messages),
 * $responseDays, $requestTypeLabels. Only the opaque reference is ever echoed;
 * the contact details a person submitted are never re-displayed.
 */

$feedback = isset($feedback) && is_array($feedback) ? $feedback : [];
$errors = isset($errors) && is_array($errors) ? $errors : [];
$requestTypeLabels = isset($requestTypeLabels) && is_array($requestTypeLabels) ? $requestTypeLabels : [];
$responseDays = isset($responseDays) ? max(1, (int) $responseDays) : 30;

$reference = isset($feedback['reference']) && is_scalar($feedback['reference'])
    ? trim((string) $feedback['reference'])
    : '';
$feedbackType = isset($feedback['request_type']) && is_scalar($feedback['request_type'])
    ? (string) $feedback['request_type']
    : '';
$feedbackLabel = $requestTypeLabels[$feedbackType] ?? 'Privacy request';

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

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
?>
<?php if ($reference !== ''): ?>
  <div class="alert alert--success" role="status" id="request-received">
    <?= $icon('check', 'ico ico--sm') ?>
    <div class="alert__body">
      <p class="alert__title">Request received</p>
      <p>
        <?= e($feedbackLabel) ?> · reference
        <strong class="mono"><?= e($reference) ?></strong>
      </p>
      <p>
        We will verify your identity and respond within <?= e((string) $responseDays) ?> days.
        Keep this reference for any follow-up. You can also email
        <a href="mailto:<?= e((string) ($privacyEmail ?? 'privacy@dashpass.africa')) ?>"><?= e((string) ($privacyEmail ?? 'privacy@dashpass.africa')) ?></a>.
      </p>
    </div>
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
