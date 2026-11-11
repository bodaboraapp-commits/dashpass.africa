<?php

declare(strict_types=1);

/**
 * Application key generator (CLI).
 *
 * Usage:
 *   php scripts/keygen.php
 *
 * Prints a fresh `base64:` APP_KEY for the `.env` file. The key is used for
 * keyed HMACs (OTP and access credentials), so rotating it invalidates any
 * outstanding hashed credentials. Never commit the generated value.
 */

$key = 'base64:' . base64_encode(random_bytes(32));

fwrite(STDOUT, 'MtaaPass APP_KEY generator' . PHP_EOL . PHP_EOL);
fwrite(STDOUT, 'APP_KEY=' . $key . PHP_EOL . PHP_EOL);
fwrite(STDOUT, 'Add (or replace) that line in your .env file. Keep the value secret:' . PHP_EOL);
fwrite(STDOUT, '  - .env is git-ignored and must never be committed.' . PHP_EOL);
fwrite(STDOUT, '  - Rotating APP_KEY invalidates outstanding OTP/credential HMACs.' . PHP_EOL);
fwrite(STDOUT, '  - APP_KEY must decode to at least 32 bytes.' . PHP_EOL);
