<?php

declare(strict_types=1);

/**
 * Broadcast delivery log (owner request 2026-11-15).
 *
 * Variables: $broadcast, $recipients, $audiences + shell data.
 */

require __DIR__ . '/_helpers.php';

$broadcast = isset($broadcast) && is_array($broadcast) ? $broadcast : [];
$recipients = isset($recipients) && is_array($recipients) ? $recipients : [];
$audiences = isset($audiences) && is_array($audiences) ? \App\Modules\Notifications\WhatsAppBroadcastService::AUDIENCE_LABELS : [];
$status = (string) ($broadcast['status'] ?? 'draft');
$canSend = in_array($status, ['scheduled', 'draft', 'failed', 'partial'], true);
$parameters = is_array($broadcast['parameters'] ?? null) ? $broadcast['parameters'] : [];
?>
<div class="admin-page__head">
  <div>
    <h1 class="admin-page__title"><?= e((string) ($broadcast['title'] ?? 'Broadcast')) ?></h1>
    <p class="admin-page__lede">
      <?= e((string) ($broadcast['status_label'] ?? '')) ?> ·
      <?= e((string) ($broadcast['recipients_total'] ?? 0)) ?> recipients ·
      <?= e((string) ($broadcast['sent_count'] ?? 0)) ?> sent ·
      <?= e((string) ($broadcast['failed_count'] ?? 0)) ?> failed
    </p>
  </div>
  <a class="btn btn--outline" href="/admin/broadcasts">Back to broadcasts</a>
</div>

<?php if ($broadcast['preview_text'] ?? null): ?>
  <section class="admin-section" aria-labelledby="broadcast-preview-title">
    <div class="admin-section__head"><h2 class="admin-section__title" id="broadcast-preview-title">Message preview</h2></div>
    <div class="card admin-pre"><?= e((string) $broadcast['preview_text']) ?></div>
  </section>
<?php endif; ?>

<section class="admin-section" aria-labelledby="broadcast-facts-title">
  <div class="admin-section__head"><h2 class="admin-section__title" id="broadcast-facts-title">Details</h2></div>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <tbody>
        <tr><th scope="row">Template</th><td><?= e((string) ($broadcast['template_name'] ?? '')) ?> (<?= e((string) ($broadcast['template_language'] ?? 'en')) ?>)</td></tr>
        <tr><th scope="row">Parameters</th><td><?= e($parameters === [] ? '-' : implode(' | ', array_map('strval', $parameters))) ?></td></tr>
        <tr><th scope="row">Audience</th><td><?= e((string) ($audiences[$broadcast['audience'] ?? ''] ?? $broadcast['audience'] ?? '')) ?></td></tr>
        <tr><th scope="row">Scheduled</th><td><?= e((string) ($broadcast['scheduled_at'] ?? '-')) ?></td></tr>
        <tr><th scope="row">Sent by</th><td><?= e((string) ($broadcast['created_by_name'] ?? '')) ?></td></tr>
        <tr><th scope="row">Completed</th><td><?= e((string) ($broadcast['completed_at'] ?? '-')) ?></td></tr>
      </tbody>
    </table>
  </div>

  <?php if ($canSend): ?>
    <form method="post" action="/admin/broadcasts/<?= e(rawurlencode((string) ($broadcast['uuid'] ?? ''))) ?>/send" class="admin-form__actions">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn--primary"><?= admin_icon('campaign', 'ico ico--sm') ?> Send now</button>
    </form>
  <?php endif; ?>
</section>

<section class="admin-section" aria-labelledby="broadcast-recipients-title">
  <div class="admin-section__head">
    <div>
      <h2 class="admin-section__title" id="broadcast-recipients-title">Delivery log</h2>
      <p class="admin-section__hint">Per-recipient result; opted-out people are excluded before sending.</p>
    </div>
    <span class="chip"><?= e((string) count($recipients)) ?></span>
  </div>

  <?php if ($recipients === []): ?>
    <p class="admin-field__hint">No recipients recorded yet (scheduled or not sent).</p>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th scope="col">Name</th>
            <th scope="col">Type</th>
            <th scope="col">Phone</th>
            <th scope="col">Status</th>
            <th scope="col">Error</th>
            <th scope="col">Sent at</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recipients as $recipient): ?>
            <tr>
              <td data-label="Name"><?= e((string) ($recipient['name'] ?? '')) ?></td>
              <td data-label="Type"><?= e(admin_humanize((string) ($recipient['kind'] ?? ''))) ?></td>
              <td data-label="Phone"><?= e((string) ($recipient['phone'] ?? '')) ?></td>
              <td data-label="Status"><span class="status <?= ($recipient['status'] ?? '') === 'sent' ? 'status--active' : (($recipient['status'] ?? '') === 'failed' ? 'status--danger' : 'status--pending') ?>"><?= e(admin_humanize((string) ($recipient['status'] ?? ''))) ?></span></td>
              <td data-label="Error"><?= e((string) ($recipient['error_code'] ?? '-')) ?></td>
              <td data-label="Sent at"><?= e((string) ($recipient['sent_at'] ?? '-')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
