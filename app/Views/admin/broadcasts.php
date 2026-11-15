<?php

declare(strict_types=1);

/**
 * Estate Admin WhatsApp broadcast composer + list (owner request 2026-11-15).
 *
 * Variables: $broadcasts, $audience_counts, $audiences, $whatsapp (masked
 * settings), $errors, $old + shell data.
 */

require __DIR__ . '/_helpers.php';

$estate = isset($estate) && is_array($estate) ? $estate : [];
$broadcasts = isset($broadcasts) && is_array($broadcasts) ? $broadcasts : [];
$audienceCounts = isset($audience_counts) && is_array($audience_counts) ? $audience_counts : [];
$audiences = isset($audiences) && is_array($audiences) ? $audiences : [];
$whatsapp = isset($whatsapp) && is_array($whatsapp) ? $whatsapp : [];
$templates = isset($templates) && is_array($templates) ? array_values(array_filter($templates, 'is_array')) : [];
$approvedTemplates = array_values(array_filter($templates, static fn (array $template): bool => (string) ($template['status'] ?? '') === 'APPROVED'));
$errors = isset($errors) && is_array($errors) ? $errors : [];
$old = isset($old) && is_array($old) ? $old : [];
$permissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$canManage = admin_can($permissions, 'estate.settings.manage');
$connected = (bool) ($whatsapp['configured'] ?? false);
$old = is_array($old) ? $old : [];

$errorsFlat = [];
foreach ($errors as $fieldErrors) {
    foreach ((array) $fieldErrors as $message) {
        $errorsFlat[] = (string) $message;
    }
}
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title">WhatsApp broadcasts</h1>
    <p class="admin-page__lede">
      Send an approved WhatsApp template to your estate's opted-in residents and household staff.
      Residents can opt out from their Account screen; opted-out numbers are never messaged.
    </p>
  </div>
  <a class="btn btn--outline" href="/admin/settings">WhatsApp settings</a>
</div>

<?php if (!$connected): ?>
  <div class="alert alert--warning" role="status">
    <?= admin_icon('alert', 'ico ico--sm') ?>
    <div class="alert__body">
      <p class="alert__title">WhatsApp is not connected</p>
      <p>Connect the estate WhatsApp channel in <a href="/admin/settings">Settings</a> (Meta credentials or the Connect button) before sending broadcasts.</p>
    </div>
  </div>
<?php endif; ?>

<?php if ($connected): ?>
  <div class="alert alert--info" role="note">
    <div class="alert__body">
      <p class="alert__title">Broadcast requirements</p>
      <p>Meta must approve the template selector and the parameter count must match the approved template exactly. Numbers must be international. If this estate uses Meta's test number (+1 555...), only recipient numbers added under <strong>Meta App Dashboard &rarr; WhatsApp &rarr; API Setup &rarr; To</strong> can receive messages; connect a real WhatsApp Business number for estate-wide broadcasts.</p>
    </div>
  </div>
<?php endif; ?>

<?php if ($errorsFlat !== []): ?>
  <div class="alert alert--error" role="alert">
    <?= admin_icon('alert', 'ico ico--sm') ?>
    <div class="alert__body">
      <p class="alert__title">Broadcast not sent</p>
      <?php foreach ($errorsFlat as $message): ?><p><?= e($message) ?></p><?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<section class="admin-section" aria-labelledby="broadcast-compose-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="broadcast-compose-title">New broadcast</h2>
      <p class="admin-section__hint">Templates must be approved in Meta first. Parameters fill {{1}}, {{2}} and {{3}}.</p>
    </div>
    <div class="admin-form__actions">
      <span class="chip"><?= e((string) ($audienceCounts['both'] ?? 0)) ?> opted-in people</span>
      <?php if ($connected && $canManage): ?>
        <form method="post" action="/admin/broadcasts/sync-templates" class="admin-inline-form">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn--outline btn--sm">Sync templates from Meta</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($canManage): ?>
    <form method="post" action="/admin/broadcasts" class="admin-form card">
      <?= csrf_field() ?>

      <div class="admin-form__grid">
        <div class="admin-field">
          <label class="admin-field__label" for="broadcast_title">Internal title</label>
          <input class="input" id="broadcast_title" name="title" type="text" maxlength="160" required
                 value="<?= e((string) ($old['title'] ?? '')) ?>" placeholder="e.g. Water maintenance Saturday">
        </div>

        <div class="admin-field">
          <label class="admin-field__label" for="broadcast_audience">Audience</label>
          <select class="select" id="broadcast_audience" name="audience" required>
            <?php foreach ($audiences as $value => $label): ?>
              <option value="<?= e((string) $value) ?>" <?= (string) ($old['audience'] ?? 'residents') === (string) $value ? 'selected' : '' ?>>
                <?= e((string) $label) ?> (<?= e((string) ($audienceCounts[$value] ?? 0)) ?> opted in)
              </option>
            <?php endforeach; ?>
          </select>
          <p class="admin-field__hint">Residents and staff who opted out are skipped automatically.</p>
        </div>

        <div class="admin-field">
          <?php if ($approvedTemplates !== []): ?>
            <label class="admin-field__label" for="broadcast_template_id">Approved template (synced from Meta)</label>
            <select class="select" id="broadcast_template_id" name="template_id" required>
              <option value="">Choose an approved template</option>
              <?php foreach ($approvedTemplates as $template): ?>
                <option value="<?= e((string) ($template['uuid'] ?? '')) ?>">
                  <?= e((string) $template['name']) ?> (<?= e((string) $template['language']) ?>) - <?= e((string) ($template['parameter_count'] ?? 0)) ?> parameter<?= ((int) ($template['parameter_count'] ?? 0)) === 1 ? '' : 's' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="admin-field__hint">
              <?= e((string) count($templates)) ?> template<?= count($templates) === 1 ? '' : 's' ?> synced from Meta.
              <?php if (count($templates) > count($approvedTemplates)): ?>Only APPROVED templates can be sent.<?php endif; ?>
            </p>
          <?php else: ?>
            <label class="admin-field__label" for="broadcast_template">Approved template name</label>
            <input class="input" id="broadcast_template" name="template_name" type="text" maxlength="120" required
                   value="<?= e((string) ($old['template_name'] ?? $whatsapp['template_name'] ?? 'estate_notification')) ?>">
            <p class="admin-field__hint">No templates synced yet. Use <strong>Sync templates from Meta</strong> above, or type the approved template name and language manually.</p>
          <?php endif; ?>
        </div>

        <?php if ($approvedTemplates === []): ?>
        <div class="admin-field">
          <label class="admin-field__label" for="broadcast_language">Template language</label>
          <input class="input" id="broadcast_language" name="template_language" type="text" maxlength="12"
                 value="<?= e((string) ($old['template_language'] ?? $whatsapp['template_language'] ?? 'en')) ?>">
        </div>
        <?php endif; ?>

        <div class="admin-field">
          <label class="admin-field__label" for="broadcast_param_1">Parameter 1 ({{1}})</label>
          <input class="input" id="broadcast_param_1" name="parameter_1" type="text" maxlength="200"
                 value="<?= e((string) ($old['parameter_1'] ?? '')) ?>">
        </div>

        <div class="admin-field">
          <label class="admin-field__label" for="broadcast_param_2">Parameter 2 ({{2}})</label>
          <input class="input" id="broadcast_param_2" name="parameter_2" type="text" maxlength="200"
                 value="<?= e((string) ($old['parameter_2'] ?? '')) ?>">
        </div>

        <div class="admin-field">
          <label class="admin-field__label" for="broadcast_param_3">Parameter 3 ({{3}})</label>
          <input class="input" id="broadcast_param_3" name="parameter_3" type="text" maxlength="200"
                 value="<?= e((string) ($old['parameter_3'] ?? '')) ?>">
        </div>

        <div class="admin-field">
          <label class="admin-field__label" for="broadcast_scheduled">Schedule (optional)</label>
          <input class="input" id="broadcast_scheduled" name="scheduled_at" type="datetime-local"
                 value="<?= e((string) ($old['scheduled_at'] ?? '')) ?>">
          <p class="admin-field__hint">Leave empty to send now. Scheduled broadcasts send when this screen is opened (or via cron).</p>
        </div>
      </div>

      <div class="admin-field">
        <label class="admin-field__label" for="broadcast_preview">Message preview (for your review)</label>
        <textarea class="input" id="broadcast_preview" name="preview_text" rows="3" maxlength="1000"
                  placeholder="Paste the approved template body with {{1}} etc. to preview it before sending"><?= e((string) ($old['preview_text'] ?? '')) ?></textarea>
        <p class="admin-field__hint">Stored with the broadcast so the delivery log keeps what was sent.</p>
      </div>

      <div class="admin-form__actions">
        <button type="submit" name="action" value="send" class="btn btn--primary" <?= $connected ? '' : 'disabled' ?>><?= admin_icon('campaign', 'ico ico--sm') ?> Send broadcast</button>
        <button type="submit" name="action" value="schedule" class="btn btn--outline" <?= $connected ? '' : 'disabled' ?>><?= admin_icon('calendar', 'ico ico--sm') ?> Schedule broadcast</button>
      </div>
    </form>
  <?php else: ?>
    <p class="admin-field__hint">Only the Estate Admin can send broadcasts.</p>
  <?php endif; ?>
</section>

<?php if ($templates !== []): ?>
<section class="admin-section" aria-labelledby="broadcast-templates-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="broadcast-templates-title">Synced templates</h2>
      <p class="admin-section__hint">Approved Meta templates for this estate, with their body and parameter count.</p>
    </div>
    <span class="chip"><?= e((string) count($templates)) ?></span>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th scope="col">Template</th>
          <th scope="col">Status</th>
          <th scope="col">Category</th>
          <th scope="col">Parameters</th>
          <th scope="col">Body</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($templates as $template): ?>
          <tr>
            <td data-label="Template"><code><?= e((string) ($template['name'] ?? '')) ?></code> (<?= e((string) ($template['language'] ?? 'en')) ?>)</td>
            <td data-label="Status"><?= e((string) ($template['status'] ?? '')) ?></td>
            <td data-label="Category"><?= e((string) ($template['category'] ?? '-')) ?></td>
            <td data-label="Parameters"><?= e((string) ($template['parameter_count'] ?? 0)) ?></td>
            <td data-label="Body"><?= e((string) ($template['body_text'] ?? '-')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="admin-section" aria-labelledby="broadcast-list-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="broadcast-list-title">Broadcast history</h2>
      <p class="admin-section__hint">Every send with its recipient-level delivery log.</p>
    </div>
    <?php if ($broadcasts !== []): ?><span class="chip"><?= e((string) count($broadcasts)) ?></span><?php endif; ?>
  </div>

  <?php if ($broadcasts === []): ?>
    <div class="admin-empty">
      <span class="admin-empty__icon" aria-hidden="true"><?= admin_icon('campaign') ?></span>
      <p class="admin-empty__title">No broadcasts yet</p>
      <p class="admin-empty__text">Compose the first broadcast above.</p>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Title</th>
            <th scope="col">Audience</th>
            <th scope="col">Status</th>
            <th scope="col">Recipients</th>
            <th scope="col">Scheduled</th>
            <th scope="col">Created</th>
            <th scope="col"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($broadcasts as $broadcast): ?>
            <tr>
              <td data-label="Title"><?= e((string) ($broadcast['title'] ?? '')) ?></td>
              <td data-label="Audience"><?= e((string) ($audiences[$broadcast['audience'] ?? ''] ?? $broadcast['audience'] ?? '')) ?></td>
              <td data-label="Status"><span class="status <?= in_array((string) ($broadcast['status'] ?? ''), ['sent'], true) ? 'status--active' : (in_array((string) ($broadcast['status'] ?? ''), ['failed'], true) ? 'status--danger' : 'status--pending') ?>"><?= e((string) ($broadcast['status_label'] ?? '')) ?></span></td>
              <td data-label="Recipients"><?= e((string) ($broadcast['recipients_total'] ?? 0)) ?> total / <?= e((string) ($broadcast['sent_count'] ?? 0)) ?> sent / <?= e((string) ($broadcast['failed_count'] ?? 0)) ?> failed</td>
              <td data-label="Scheduled"><?= e((string) ($broadcast['scheduled_at'] ?? '-')) ?></td>
              <td data-label="Created"><?= e((string) ($broadcast['created_at'] ?? '')) ?></td>
              <td data-label="">
                <a class="admin-table__link" href="/admin/broadcasts/<?= e(rawurlencode((string) ($broadcast['uuid'] ?? ''))) ?>">Delivery log</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
