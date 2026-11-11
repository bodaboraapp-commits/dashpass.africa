#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Billing past-due sweep (Increment 10 §3).
 *
 * Usage: php scripts/billing-past-due.php [--dry-run] [--estate=<uuid>]
 *
 * Flags `open` invoices with `due_at < now` as `past_due`, audits
 * `billing.invoice_status_changed` per invoice plus one `billing.past_due_swept`
 * aggregate, and is idempotent. `--dry-run` reports counts only (no writes, no
 * audit). Safe for cron; no HTTP; no secrets printed.
 */

use App\Core\Database;
use App\Modules\Billing\BillingPastDueService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "billing-past-due must be run from the command line.\n");
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

$dryRun = false;
$estateUuid = null;
$usage = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;

        continue;
    }

    if (str_starts_with($argument, '--estate=')) {
        $value = substr($argument, strlen('--estate='));

        if ($value === '') {
            fwrite(STDERR, "FAIL --estate requires a uuid value (use --estate=<uuid>).\n");
            exit(2);
        }

        $estateUuid = $value;

        continue;
    }

    if (in_array($argument, ['--help', '-h'], true)) {
        $usage = true;

        continue;
    }

    fwrite(STDERR, "Unknown argument. Use --dry-run and/or --estate=<uuid>.\n");
    exit(2);
}

if ($usage) {
    fwrite(STDOUT, "Usage: php scripts/billing-past-due.php [--dry-run] [--estate=<uuid>]\n");
    exit(0);
}

$estateId = null;
$db = Database::instance();

if (is_string($estateUuid) && $estateUuid !== '') {
    $row = $db->selectOne('SELECT id FROM estates WHERE uuid = :uuid LIMIT 1', ['uuid' => $estateUuid]);

    if ($row === null) {
        fwrite(STDERR, "FAIL unknown estate uuid.\n");
        exit(2);
    }

    $estateId = (int) $row['id'];
}

try {
    $result = BillingPastDueService::make()->run($dryRun, $estateId);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL billing past-due sweep failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Billing past-due (%s): scanned=%d eligible=%d flagged=%d estates=%d\n",
    $dryRun ? 'dry-run' : 'apply',
    (int) $result['scanned'],
    (int) $result['eligible'],
    (int) $result['flagged'],
    (int) $result['estates']
));

exit(0);
