#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Config;
use App\Integrations\Mail\MailManager;
use App\Integrations\Mail\MailMessage;
use App\Integrations\Mail\SmtpMailProvider;

/**
 * SMTP mail diagnostic CLI (Increment 6 T42).
 *
 * Usage:
 *   php scripts/mail-test.php --check          Connect + EHLO + AUTH only (no mail sent).
 *   php scripts/mail-test.php --to=you@x.test  Send one explicit test email via the
 *                                              configured MailManager driver.
 *
 * A message is only ever sent when an explicit --to recipient is supplied.
 * Output never contains passwords, API keys or other credential material.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "mail-test must be run from the command line.\n");
    exit(1);
}

$application = require dirname(__DIR__) . '/app/bootstrap.php';

$check = false;
$to = null;
$usage = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--check') {
        $check = true;

        continue;
    }

    if (str_starts_with($argument, '--to=')) {
        $to = substr($argument, strlen('--to='));

        continue;
    }

    if ($argument === '--to') {
        $usage = true;

        continue;
    }

    if (in_array($argument, ['--help', '-h'], true)) {
        $usage = true;

        continue;
    }

    fwrite(STDERR, "Unknown argument. Use --check and/or --to=address.\n");
    exit(2);
}

$printUsage = static function (): void {
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php scripts/mail-test.php --check          Check SMTP connection + authentication only.\n");
    fwrite(STDOUT, "  php scripts/mail-test.php --to=you@x.test  Send one test email (explicit recipient required).\n");
};

if ($usage || (!$check && ($to === null || $to === ''))) {
    $printUsage();
    exit($usage ? 0 : 2);
}

$exitCode = 0;

if ($check) {
    $smtp = (array) Config::get('mail.smtp', []);
    $provider = new SmtpMailProvider();

    $endpoint = sprintf(
        '%s:%d (%s)',
        (string) ($smtp['host'] ?? ''),
        (int) ($smtp['port'] ?? 0),
        (string) ($smtp['encryption'] ?? '')
    );

    if (!$provider->hasCredentials()) {
        fwrite(STDERR, "FAIL MAIL_SMTP_HOST / MAIL_SMTP_USERNAME / MAIL_SMTP_PASSWORD are not fully configured.\n");
        $exitCode = 1;
    } else {
        try {
            $provider->check();
            fwrite(STDOUT, "OK SMTP authentication succeeded ({$endpoint}).\n");
        } catch (Throwable $exception) {
            fwrite(STDERR, 'FAIL ' . $exception->getMessage() . "\n");
            $exitCode = 1;
        }
    }
}

if ($to !== null && $to !== '') {
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        fwrite(STDERR, "FAIL --to requires a valid email address.\n");
        exit(2);
    }

    $product = trim((string) Config::get('app.name', 'MtaaPass')) ?: 'MtaaPass';

    try {
        $delivery = (new MailManager())->send(new MailMessage(
            [$to],
            '[' . $product . '] email test',
            sprintf(
                "This is a %s email test.\n\nDriver: %s\nSent: %s\n\nIf you received this message, transactional email delivery is working.",
                $product,
                (string) Config::get('mail.driver', 'log'),
                gmdate('Y-m-d H:i:s \U\T\C')
            )
        ));

        fwrite(STDOUT, sprintf(
            "OK email accepted (provider=%s, status=%s, message_id=%s).\n",
            (string) ($delivery['provider'] ?? 'unknown'),
            (string) ($delivery['status'] ?? 'unknown'),
            (string) ($delivery['message_id'] ?? '')
        ));
    } catch (Throwable $exception) {
        fwrite(STDERR, 'FAIL ' . $exception->getMessage() . "\n");
        $exitCode = 1;
    }
}

exit($exitCode);
