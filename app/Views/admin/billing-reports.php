<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';
$report = isset($report) && is_array($report) ? $report : [];
$key = (string) ($report['key'] ?? 'summary');
$rows = isset($report['rows']) && is_array($report['rows']) ? array_values(array_filter($report['rows'], 'is_array')) : [];
$headers = $rows === [] ? [] : array_keys($rows[0]);
$labels = ['summary' => 'Summary', 'outstanding' => 'Outstanding', 'payments' => 'Payments', 'utility' => 'Utility'];
$human = static fn (string $h): string => ucwords(str_replace('_', ' ', $h));
?>
<div class="admin-page">
  <div class="admin-page__head"><div><nav class="admin-breadcrumb" aria-label="Breadcrumb"><a href="/admin/billing">Billing</a><span aria-hidden="true">/</span><span aria-current="page">Reports</span></nav><h1 class="admin-page__title">Billing reports</h1><p class="admin-page__lede">Read-only estate billing reports with CSV export.</p></div><div class="cluster"><a class="btn btn--ghost btn--sm" href="/admin/billing">Overview</a><a class="btn btn--primary btn--sm" href="/admin/billing/export.csv?report=<?= e(rawurlencode($key)) ?>">Export CSV</a></div></div>
  <nav class="admin-subnav" aria-label="Report">
    <?php foreach ($labels as $k => $label): ?><a class="admin-subnav__link<?= $key === $k ? ' is-active' : '' ?>" href="/admin/billing/reports?report=<?= e($k) ?>"><?= e($label) ?></a><?php endforeach; ?>
  </nav>
  <section class="admin-section" aria-labelledby="report-title">
    <div class="admin-section__head"><h2 class="admin-section__title" id="report-title"><?= e($labels[$key] ?? 'Report') ?></h2><span class="chip"><?= e((string) count($rows)) ?> rows</span></div>
    <?php if ($rows === []): ?>
      <div class="admin-empty"><p class="admin-empty__title">No report rows</p><p class="admin-empty__text">This report will populate once billing records exist for the estate.</p><a class="btn btn--ghost" href="/admin/billing/export.csv?report=<?= e(rawurlencode($key)) ?>">Export empty CSV</a></div>
    <?php else: ?>
      <div class="table-wrap"><table class="admin-table"><thead><tr><?php foreach ($headers as $header): ?><th scope="col"><?= e($human((string) $header)) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr><?php foreach ($headers as $header): ?><td data-label="<?= e($human((string) $header)) ?>"><?= e((string) ($row[$header] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
</div>
