<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Crypto;
use Tests\TestCase;

/**
 * Increment 2 credential crypto (visitor-access-module-contract §1, §3):
 * AES-256-GCM at-rest encryption for the re-displayable QR token, and keyed
 * HMAC determinism for the verify-only PIN credential.
 */
final class CryptoEncryptTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $plaintext = bin2hex(random_bytes(32));
        $payload = Crypto::encrypt($plaintext, 'access-qr:credential-uuid');

        $this->assertTrue($payload !== '');
        $this->assertFalse(str_contains($payload, $plaintext), 'Ciphertext must not contain the plaintext.');
        $this->assertSame($plaintext, Crypto::decrypt($payload, 'access-qr:credential-uuid'));
    }

    public function testCiphertextIsRandomisedPerCall(): void
    {
        $first = Crypto::encrypt('same-secret', 'access-qr:uuid');
        $second = Crypto::encrypt('same-secret', 'access-qr:uuid');

        $this->assertNotSame($first, $second, 'AES-GCM must use a fresh IV per encryption.');
        $this->assertSame('same-secret', Crypto::decrypt($first, 'access-qr:uuid'));
        $this->assertSame('same-secret', Crypto::decrypt($second, 'access-qr:uuid'));
    }

    public function testContextSeparationPreventsReplay(): void
    {
        $payload = Crypto::encrypt('token-value', 'access-qr:credential-uuid');

        $this->assertNull(
            Crypto::decrypt($payload, 'access-qr:other-credential-uuid'),
            'A ciphertext must not decrypt under a different context/credential uuid.'
        );
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $payload = Crypto::encrypt('token-value', 'access-qr:uuid');
        $raw = base64_decode($payload, true);
        $this->assertTrue(is_string($raw));

        // Flip a bit in the ciphertext body.
        $tampered = $raw;
        $last = strlen($tampered) - 1;
        $tampered[$last] = chr(ord($tampered[$last]) ^ 0x01);

        $this->assertNull(Crypto::decrypt(base64_encode($tampered), 'access-qr:uuid'));
        $this->assertNull(Crypto::decrypt('not-base64-at-all', 'access-qr:uuid'));
        $this->assertNull(Crypto::decrypt(null, 'access-qr:uuid'));
        $this->assertNull(Crypto::decrypt('', 'access-qr:uuid'));
    }

    public function testAccessPinHmacIsKeyedAndScoped(): void
    {
        $pin = '246810';
        $uuid = 'c1000001-0000-4000-8000-000000000005';

        $expected = Crypto::hmac('access-pin:' . $uuid, $pin);

        $this->assertMatches('/^[0-9a-f]{64}$/', $expected);
        $this->assertSame($expected, Crypto::hmac('access-pin:' . $uuid, $pin), 'PIN hash must be deterministic.');
        $this->assertNotSame($expected, Crypto::hmac('access-qr:' . $uuid, $pin), 'PIN and QR hashes must be context-separated.');
        $this->assertNotSame($expected, Crypto::hmac('access-pin:other-uuid', $pin));
        $this->assertNotSame($expected, Crypto::hmac('access-pin:' . $uuid, '111111'));
        $this->assertFalse(str_contains($expected, $pin), 'The PIN plaintext must not appear in its hash.');
    }

    public function testQrTokenHashFormulae(): void
    {
        $uuid = 'c1000001-0000-4000-8000-000000000006';
        $token = bin2hex(random_bytes(32));

        $hash = Crypto::hmac('access-qr:' . $uuid, $token);

        $this->assertMatches('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame($hash, Crypto::hmac('access-qr:' . $uuid, $token));
        $this->assertFalse(str_contains($hash, $token));
    }
}
