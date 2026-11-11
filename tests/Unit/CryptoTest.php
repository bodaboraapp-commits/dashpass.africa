<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Crypto;
use Tests\TestCase;

/**
 * Blueprint §42: OTP/PIN material is stored as a keyed HMAC, never plaintext.
 */
final class CryptoTest extends TestCase
{
    public function testHmacIsDeterministic(): void
    {
        $first = Crypto::hmac('otp:challenge-uuid', '123456');
        $second = Crypto::hmac('otp:challenge-uuid', '123456');

        $this->assertSame($first, $second);
        $this->assertMatches('/^[0-9a-f]{64}$/', $first, 'HMAC-SHA256 digests are 64 lowercase hex characters.');
    }

    public function testHmacSeparatesContextAndValue(): void
    {
        $base = Crypto::hmac('otp:challenge-uuid', '123456');

        $this->assertNotSame($base, Crypto::hmac('pin:challenge-uuid', '123456'), 'Context must scope the digest.');
        $this->assertNotSame($base, Crypto::hmac('otp:other-uuid', '123456'), 'The challenge uuid must scope the digest.');
        $this->assertNotSame($base, Crypto::hmac('otp:challenge-uuid', '654321'), 'The value must change the digest.');
    }

    public function testNumericCodeLengthAndAlphabet(): void
    {
        foreach ([4, 6, 8] as $length) {
            $code = Crypto::numericCode($length);

            $this->assertSame($length, strlen($code));
            $this->assertMatches('/^\d+$/', $code, 'Crypto::numericCode() must only contain digits.');
        }

        $this->assertSame(6, strlen(Crypto::numericCode()), 'The default OTP length is 6 digits.');
        $this->assertSame(4, strlen(Crypto::numericCode(1)), 'The minimum code length is 4 digits.');
    }

    public function testNumericCodeIsRandom(): void
    {
        $codes = [];

        for ($i = 0; $i < 100; $i++) {
            $codes[] = Crypto::numericCode(6);
        }

        $this->assertTrue(
            count(array_unique($codes)) > 50,
            'Crypto::numericCode() must produce random, non-repeating codes.'
        );
    }

    public function testEquals(): void
    {
        $this->assertTrue(Crypto::equals('abc', 'abc'));
        $this->assertFalse(Crypto::equals('abc', 'abd'));
        $this->assertFalse(Crypto::equals('abc', ''));
        $this->assertFalse(Crypto::equals('', 'abc'));
        $this->assertTrue(Crypto::equals('', ''));
    }

    public function testRandomTokenIsHexAndLongEnough(): void
    {
        $default = Crypto::randomToken();
        $this->assertSame(64, strlen($default), 'The default random token is 32 bytes => 64 hex chars.');
        $this->assertMatches('/^[0-9a-f]+$/', $default);

        $this->assertSame(32, strlen(Crypto::randomToken(8)), 'The minimum token entropy is 16 bytes.');
        $this->assertSame(128, strlen(Crypto::randomToken(64)));
        $this->assertNotSame(Crypto::randomToken(), Crypto::randomToken(), 'Tokens must not repeat.');
    }
}
