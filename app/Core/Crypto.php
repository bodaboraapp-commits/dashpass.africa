<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Keyed cryptography helpers.
 *
 * APP_KEY is a base64-encoded 32-byte secret supplied through the environment.
 * It is used to derive HMAC keys for short-lived credentials (OTP, access PIN)
 * so those values are never stored in plaintext (Blueprint §42).
 */
final class Crypto
{
    private static ?string $key = null;

    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $configured = (string) Config::get('app.key', '');

        if ($configured === '') {
            throw new RuntimeException('APP_KEY is not configured. Run `php scripts/keygen.php` and set APP_KEY.');
        }

        if (!str_starts_with($configured, 'base64:')) {
            throw new RuntimeException('APP_KEY must use the `base64:` format.');
        }

        $decoded = base64_decode(substr($configured, 7), true);

        if ($decoded === false || strlen($decoded) < 32) {
            throw new RuntimeException('APP_KEY must decode to at least 32 bytes.');
        }

        return self::$key = $decoded;
    }

    /**
     * Keyed digest scoped by context. The context prevents the same plaintext
     * value in two different columns from producing the same digest.
     */
    public static function hmac(string $context, string $value): string
    {
        return hash_hmac('sha256', $context . '|' . $value, self::key());
    }

    /**
     * Binary keyed material for purposes that need raw bytes.
     */
    public static function deriveKey(string $purpose): string
    {
        return hash_hmac('sha256', 'derive|' . $purpose, self::key(), true);
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(16, $bytes)));
    }

    /**
     * Cryptographically random numeric code, zero padded. Leading zeros are
     * preserved so all values in the range have equal probability.
     */
    public static function numericCode(int $length = 6): string
    {
        $length = max(4, $length);
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    public static function hashPassword(string $plaintext): string
    {
        return password_hash($plaintext, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $plaintext, string $hash): bool
    {
        return password_verify($plaintext, $hash);
    }

    public static function equals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    /**
     * Encrypt a value at rest with AES-256-GCM.
     *
     * Used only for secrets that the application must be able to present again
     * (for example the opaque QR token a resident re-displays). Access
     * credentials that only need verification (the six-digit PIN) are stored as
     * keyed HMACs and are never recoverable.
     *
     * The context binds the ciphertext to its purpose, so a value encrypted for
     * one column cannot be replayed into another.
     */
    public static function encrypt(string $plaintext, string $context): string
    {
        $key = hash_hmac('sha256', 'encrypt|' . $context, self::key(), true);
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if ($ciphertext === false) {
            throw new RuntimeException('Symmetric encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a value produced by encrypt(); returns null when the payload is
     * malformed or has been tampered with.
     */
    public static function decrypt(?string $payload, string $context): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $key = hash_hmac('sha256', 'encrypt|' . $context, self::key(), true);
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Reset cached key material (test bootstrap only).
     */
    public static function resetKey(): void
    {
        self::$key = null;
    }
}
