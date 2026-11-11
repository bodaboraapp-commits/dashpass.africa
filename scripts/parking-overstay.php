#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Parking overstay detection job (Increment 8 §2).
 *
 * Usage:
 *   php scripts/parking-overstay.php [--dry-run] [--estate=<uuid>]
 *
 * Flags active parking sessions past their visit window + grace as overstays and
 * creates one open `overstay` violation per bay. Idempotent and safe to run from
 * cron. `--dry-run` only reports; no writes. Prints counts. No HTTP, no secrets.
 */

use App\Core\Config;
use App\Core\Database;
use App\Modules\Parking\ParkingOverstayService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "parking-overstay must be run from the command line.\n");
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
        $estateUuid = substr($argument, strlen('--estate='));

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
    fwrite(STDOUT, "Usage: php scripts/parking-overstay.php [--dry-run] [--estate=<uuid>]\n");
    exit(0);
}

$db = Database::instance();
$estateId = null;

if (is_string($estateUuid) && $estateUuid !== '') {
    $row = $db->selectOne('SELECT id FROM estates WHERE uuid = :uuid LIMIT 1', ['uuid' => $estateUuid]);

    if ($row === null) {
        fwrite(STDERR, "FAIL unknown estate uuid.\n");
        exit(2);
    }

    $estateId = (int) $row['id'];
}

try {
    $result = ParkingOverstayService::make()->run($dryRun, $estateId);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL parking overstay job failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

$mode = $dryRun ? 'dry-run' : 'apply';

fwrite(STDOUT, sprintf(
    "Parking overstay (%s): estates=%d scanned=%d overstayed=%d flagged=%d violations_created=%d skipped=%d\n",
    $mode,
    (int) $result['estates'],
    (int) $result['scanned'],
    (int) $result['overstayed'],
    (int) $result['flagged'],
    (int) $result['violations_created'],
    (int) $result['skipped']
));

if (!$dryRun) {
    fwrite(STDOUT, 'Grace setting: ' . (string) Config::get('parking.overstay_grace_minutes', 30) . " minutes (estate settings override).\n");
}

exit(0);
