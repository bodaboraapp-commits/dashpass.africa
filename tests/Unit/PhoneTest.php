<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Phone;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Blueprint §14: phone numbers are normalised to E.164 before storage or lookup.
 */
final class PhoneTest extends TestCase
{
    public function testNormalisesLocalTrunkZeroFormat(): void
    {
        $this->assertSame('+254712345678', Phone::normalize('0712345678'));
        $this->assertSame('+254712345678', Phone::normalize('0712 345 678'));
        $this->assertSame('+254712345678', Phone::normalize('07-12-345-678'));
        $this->assertSame('+254712345678', Phone::normalize('(0712) 345 678'));
    }

    public function testNormalisesCountryCodeWithoutPlus(): void
    {
        $this->assertSame('+254712345678', Phone::normalize('254712345678'));
        $this->assertSame('+254712345678', Phone::normalize('254 712 345 678'));
    }

    public function testNormalisesE164Input(): void
    {
        $this->assertSame('+254712345678', Phone::normalize('+254712345678'));
        $this->assertSame('+254712345678', Phone::normalize('+254 712 345 678'));
    }

    public function testNormalisesBareSubscriberNumber(): void
    {
        $this->assertSame('+254712345678', Phone::normalize('712345678'));
    }

    public function testNormalisesInternationalDoubleZeroPrefix(): void
    {
        $this->assertSame('+254712345678', Phone::normalize('00254712345678'));
    }

    public function testNormalisesSeededGreenviewNumbers(): void
    {
        foreach ([
            '+254700000001',
            '+254700000010',
            '+254700000014',
            '+254700000020',
        ] as $e164) {
            $this->assertSame($e164, Phone::normalize($e164));
        }

        $this->assertSame('+254700000010', Phone::normalize('0700000010'));
    }

    public function testRejectsInvalidInput(): void
    {
        $invalid = ['', '   ', 'abc', 'abc123', '+', '+254123', '0712', '12345678901234567890'];

        foreach ($invalid as $input) {
            try {
                $normalised = Phone::normalize($input);
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);

                continue;
            }

            $this->fail(sprintf('Phone::normalize("%s") should have thrown but returned "%s".', $input, $normalised));
        }
    }

    public function testIsValid(): void
    {
        $this->assertTrue(Phone::isValid('+254712345678'));
        $this->assertTrue(Phone::isValid('+12025550123'));
        $this->assertFalse(Phone::isValid('0712345678'));
        $this->assertFalse(Phone::isValid('+0123456789'));
        $this->assertFalse(Phone::isValid('+254712345678901234567'));
        $this->assertFalse(Phone::isValid(''));
    }

    public function testDisplayFormatsKenyanNumber(): void
    {
        $this->assertSame('+254 712 345 678', Phone::display('+254712345678'));
        $this->assertSame('+254 700 000 010', Phone::display('+254700000010'));
        $this->assertSame('not-a-phone', Phone::display('not-a-phone'));
    }

    public function testMaskHidesMiddleDigits(): void
    {
        $masked = Phone::mask('+254712345678');

        $this->assertSame('+2547*****678', $masked);
        $this->assertFalse(str_contains($masked, '12345'), 'Phone::mask() must not expose the middle digits.');
        $this->assertSame('***', Phone::mask('123'));
    }
}
