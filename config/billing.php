<?php

declare(strict_types=1);

/*
 * Billing automation (Inc-13 P1+). Runtime defaults; no secrets.
 */

return [
    'automation' => [
        'enabled' => App\Core\Env::bool('AUTOMATED_BILLING_ENABLED', false),
        'lead_days' => 0,
        'due_days' => 14,
        'max_catchup' => 6,
        'max_generate' => 50,
        'lock_path' => 'storage/temporary/billing-automate.lock',
    ],
];
