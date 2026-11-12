<?php
declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';
$schedule = isset($schedule) && is_array($schedule) ? $schedule : [];
require __DIR__ . '/billing-schedule-form.php';
