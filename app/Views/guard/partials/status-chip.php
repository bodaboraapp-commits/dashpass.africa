<?php

declare(strict_types=1);

/**
 * Guard status chip: pairs colour with an explicit text label (§7 T14).
 *
 * Variables: $status (machine value), $statusLabel (string|null),
 * $statusTone ('ok'|'warn'|'danger'|'neutral').
 */

$status = isset($status) && is_string($status) ? $status : '';
$label = isset($statusLabel) && is_string($statusLabel) ? trim($statusLabel) : '';
$tone = isset($statusTone) && is_string($statusTone) ? $statusTone : '';

if ($label === '') {
    $label = $status !== '' ? strtoupper(str_replace('_', ' ', $status)) : 'UNKNOWN';
}

// The guard console always states the state in capitals (contract §7 T14):
// status is conveyed by text as well as colour, never by colour alone.
$label = mb_strtoupper($label);

if (!in_array($tone, ['ok', 'warn', 'danger', 'neutral'], true)) {
    $tone = 'neutral';
}
?>
<span class="g-chip g-chip--<?= e($tone) ?>"><?= e($label) ?></span>
