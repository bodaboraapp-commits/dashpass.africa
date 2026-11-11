<?php
declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';
$channels = isset($channels) && is_array($channels) ? $channels : [];
$smsDriver = (string) ($sms_driver ?? 'log');
$channelRows = [
    'email' => ['Email', 'Transactional email via the configured mail provider.', true],
    'in_app' => ['In-app', 'Notification bell and inbox rows.', true],
    'sms' => ['SMS', 'Requires a non-log SMS gateway.', false],
    'whatsapp' => ['WhatsApp', 'Requires an approved WhatsApp gateway.', false],
];
?>
<div class="platform-page">
  <header class="platform-page__head">
    <div><p class="platform-page__eyebrow">Platform settings</p><h1 class="platform-page__title">Notification channels</h1><p class="platform-page__lede">Global channel availability. Provider credentials stay in environment configuration and are never shown here.</p></div>
    <a class="btn btn--ghost btn--sm" href="/platform">Back to platform</a>
  </header>
  <form method="post" action="/platform/settings/notifications" class="card admin-form">
    <?= csrf_field() ?>
    <div class="admin-section__head"><h2 class="admin-section__title">Channels</h2><span class="chip">SMS driver: <?= e($smsDriver) ?></span></div>
    <div class="admin-form__grid">
      <?php foreach ($channelRows as $key => [$label, $hint, $always]): ?>
        <?php $enabled = (bool) ($channels[$key] ?? ($key === 'email' || $key === 'in_app')); ?>
        <div class="admin-field">
          <label class="admin-field__label" for="channel_<?= e($key) ?>">
            <input type="checkbox" id="channel_<?= e($key) ?>" name="<?= e($key) ?>" value="1"
                   <?= $enabled ? 'checked' : '' ?> <?= $always ? 'disabled' : '' ?>>
            <?= e($label) ?>
          </label>
          <p class="admin-field__hint"><?= e($hint) ?></p>
          <p><span class="status <?= $enabled ? 'status--active' : 'status--inactive' ?>"><?= $enabled ? 'Configured' : 'Not configured' ?></span></p>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($smsDriver === 'log'): ?><p class="admin-field__hint">SMS stays unavailable while the log driver is active. Configure the gateway in the environment, not in this form.</p><?php endif; ?>
    <div class="admin-form__actions"><button type="submit" class="btn btn--primary">Save channels</button></div>
  </form>
</div>
