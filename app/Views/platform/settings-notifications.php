<?php
declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';
$channels = isset($channels) && is_array($channels) ? $channels : [];
$statusMap = isset($channel_status) && is_array($channel_status) ? $channel_status : [];
$smsDriver = (string) ($sms_driver ?? 'log');
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$selected = isset($old['channels']) && is_array($old['channels']) ? array_map('strval', $old['channels']) : array_keys(array_filter($channels, static fn ($v): bool => (bool) $v));
$selected = array_values(array_unique(array_merge(['email', 'in_app'], $selected)));
$errorFor = static function (string $key) use ($errors): string {
    $messages = $errors[$key] ?? [];
    return is_array($messages) && $messages !== [] ? (string) reset($messages) : '';
};
$channelRows = [
    'email' => ['Email', 'Transactional email via the configured mail provider.', true, 'Always on for billing and account mail.'],
    'in_app' => ['In-app', 'Notification bell and inbox rows.', true, 'Always on for platform and estate notices.'],
    'sms' => ['SMS', 'Requires a non-log SMS gateway.', false, $smsDriver === 'log' ? 'Unavailable: SMS gateway is in log/test mode.' : ''],
    'whatsapp' => ['WhatsApp', 'Requires an approved WhatsApp gateway.', false, empty($statusMap['whatsapp']['configured']) ? 'Unavailable: no WhatsApp gateway is configured.' : ''],
];
?>
<div class="platform-page">
  <header class="platform-page__head">
    <div><p class="platform-page__eyebrow">Platform settings</p><h1 class="platform-page__title">Notification channels</h1><p class="platform-page__lede">Global channel availability. Email and in-app are always on; SMS and WhatsApp only appear enabled when a gateway is configured.</p></div>
    <a class="btn btn--ghost btn--sm" href="/platform">Back to platform</a>
  </header>

<?php
$firstError = '';
foreach ($errors as $errorMessages) {
    if (is_array($errorMessages) && $errorMessages !== []) {
        $firstError = (string) reset($errorMessages);
        break;
    }
    if (is_string($errorMessages) && $errorMessages !== '') {
        $firstError = $errorMessages;
        break;
    }
}
?>
  <?php if ($errors !== []): ?>    <div class="alert alert--danger" role="alert"><div class="alert__body"><p class="alert__title">Check the channel settings</p><p><?= e($firstError !== '' ? $firstError : 'Some values could not be saved.') ?></p></div></div>
  <?php endif; ?>

  <form method="post" action="/platform/settings/notifications" class="card admin-form">
    <?= csrf_field() ?>
    <?php if ($errorFor('channels') !== ''): ?><p class="admin-field__error" role="alert"><?= e($errorFor('channels')) ?></p><?php endif; ?>
    <div class="admin-section__head"><h2 class="admin-section__title">Global channels</h2><span class="chip">SMS driver: <?= e($smsDriver) ?></span></div>
    <div class="admin-form__grid">
      <?php foreach ($channelRows as $key => [$label, $hint, $always, $reason]): ?>
        <?php
        $configured = (bool) ($channels[$key] ?? ($key === 'email' || $key === 'in_app'));
        $status = $statusMap[$key]['status'] ?? ($configured ? (($statusMap[$key]['test'] ?? false) ? 'test' : 'configured') : 'not_configured');
        $statusLabel = $status === 'configured' ? 'Configured' : ($status === 'test' ? 'Test mode' : 'Not configured');
        $statusTone = $status === 'configured' ? 'status--active' : ($status === 'test' ? 'status--pending' : 'status--inactive');
        $disabled = $always || $status === 'not_configured';
        ?>
        <fieldset class="admin-field">
          <legend class="admin-field__label"><?= e($label) ?></legend>
          <label class="admin-check" for="channel_<?= e($key) ?>">
            <input type="checkbox" id="channel_<?= e($key) ?>" name="channels[]" value="<?= e($key) ?>"
                   <?= in_array($key, $selected, true) || $always ? 'checked' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
            <?= $always ? 'Always on' : 'Enable ' . e($label) ?>
          </label>
          <p class="admin-field__hint"><?= e($hint) ?></p>
          <?php if ($reason !== ''): ?><p class="admin-field__hint"><?= e($reason) ?></p><?php endif; ?>
          <p><span class="status <?= e($statusTone) ?>"><?= e($statusLabel) ?></span></p>
        </fieldset>
      <?php endforeach; ?>
    </div>
    <p class="admin-field__hint">Provider credentials stay in environment configuration and are never shown or saved in this form.</p>
    <div class="admin-form__actions"><button type="submit" class="btn btn--primary">Save channels</button></div>
  </form>
</div>
