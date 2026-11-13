<?php

declare(strict_types=1);

/**
 * Shared identity + message fields for the public privacy-request forms.
 *
 * Variables: $form (communications|rights|deletion), $old, $activeForm, $errors,
 * $messageLabel, $messageRequired (bool), $showConfirm (bool).
 *
 * Field errors and old values are scoped to the form that was submitted so a
 * failed communications request does not decorate the rights or deletion forms.
 */

$form = isset($form) && is_string($form) ? $form : 'rights';
$old = isset($old) && is_array($old) ? $old : [];
$activeForm = isset($activeForm) && is_string($activeForm) ? $activeForm : '';
$errors = isset($errors) && is_array($errors) ? $errors : [];
$messageLabel = isset($messageLabel) && is_string($messageLabel) && $messageLabel !== ''
    ? $messageLabel
    : 'Details (optional)';
$messageRequired = (bool) ($messageRequired ?? false);
$showConfirm = (bool) ($showConfirm ?? false);

$formErrors = static function (string $field) use ($errors, $activeForm, $form): array {
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

$oldValue = static function (string $field) use ($old, $activeForm, $form): string {
    if ($activeForm !== $form) {
        return '';
    }

    $value = $old[$field] ?? '';

    return is_scalar($value) ? trim((string) $value) : '';
};

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);

$id = static fn (string $field): string => 'privacy_' . $form . '_' . $field;
?>
<div class="field-grid">
  <div class="field<?= $formErrors('subject_name') !== [] ? ' field--invalid' : '' ?>">
    <label class="field__label" for="<?= e($id('name')) ?>">Your full name</label>
    <input class="input<?= $formErrors('subject_name') !== [] ? ' input--invalid' : '' ?>"
           id="<?= e($id('name')) ?>" name="subject_name" type="text" required
           minlength="2" maxlength="120" autocomplete="name"
           value="<?= e($oldValue('subject_name')) ?>"
           <?= $formErrors('subject_name') !== [] ? 'aria-invalid="true"' : '' ?>>
    <?php foreach ($formErrors('subject_name') as $message): ?>
      <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
    <?php endforeach; ?>
  </div>

  <div class="field<?= $formErrors('subject_phone') !== [] ? ' field--invalid' : '' ?>">
    <label class="field__label" for="<?= e($id('phone')) ?>">Phone number</label>
    <input class="input<?= $formErrors('subject_phone') !== [] ? ' input--invalid' : '' ?>"
           id="<?= e($id('phone')) ?>" name="subject_phone" type="tel" required
           inputmode="tel" maxlength="20" autocomplete="tel"
           placeholder="0712 345 678"
           value="<?= e($oldValue('subject_phone')) ?>"
           <?= $formErrors('subject_phone') !== [] ? 'aria-invalid="true"' : '' ?>>
    <?php foreach ($formErrors('subject_phone') as $message): ?>
      <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
    <?php endforeach; ?>
  </div>
</div>

<div class="field<?= $formErrors('subject_email') !== [] ? ' field--invalid' : '' ?>">
  <label class="field__label" for="<?= e($id('email')) ?>">Email address (optional)</label>
  <input class="input<?= $formErrors('subject_email') !== [] ? ' input--invalid' : '' ?>"
         id="<?= e($id('email')) ?>" name="subject_email" type="email"
         maxlength="190" autocomplete="email"
         value="<?= e($oldValue('subject_email')) ?>"
         <?= $formErrors('subject_email') !== [] ? 'aria-invalid="true"' : '' ?>>
  <?php foreach ($formErrors('subject_email') as $message): ?>
    <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
  <?php endforeach; ?>
</div>

<div class="field<?= $formErrors('message') !== [] ? ' field--invalid' : '' ?>">
  <label class="field__label" for="<?= e($id('message')) ?>"><?= e($messageLabel) ?></label>
  <textarea class="input<?= $formErrors('message') !== [] ? ' input--invalid' : '' ?>"
            id="<?= e($id('message')) ?>" name="message" rows="4" maxlength="2000"
            <?= $messageRequired ? 'required minlength="10"' : '' ?>
            <?= $formErrors('message') !== [] ? 'aria-invalid="true"' : '' ?>><?= e($oldValue('message')) ?></textarea>
  <?php foreach ($formErrors('message') as $message): ?>
    <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
  <?php endforeach; ?>
</div>

<?php if ($showConfirm): ?>
  <div class="field field--check<?= $formErrors('confirm') !== [] ? ' field--invalid' : '' ?>">
    <label class="privacy-choice privacy-choice--check">
      <input type="checkbox" name="confirm" value="1" required
             <?= $oldValue('confirm') === '1' ? 'checked' : '' ?>
             <?= $formErrors('confirm') !== [] ? 'aria-invalid="true"' : '' ?>>
      <span>
        I understand that deleting my data removes my live account and that a written record of
        this request will be kept for accountability.
      </span>
    </label>
    <?php foreach ($formErrors('confirm') as $message): ?>
      <p class="field__error"><?= $icon('alert', 'ico ico--xs') ?> <?= e($message) ?></p>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
