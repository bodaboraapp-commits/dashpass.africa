<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Core\Validator;
use RuntimeException;
use Tests\TestCase;

/**
 * Server-side validation (Blueprint §32): whitelist-only output, 422 payload
 * shape, and the Kenyan phone normalisation used by the login form.
 */
final class ValidatorTest extends TestCase
{
    public function testRequiredFieldProduces422Payload(): void
    {
        try {
            Validator::validate([], ['full_name' => 'required|string']);
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status());
            $this->assertSame('VALIDATION_FAILED', $exception->errorCode());
            $this->assertArrayHasKey('full_name', $exception->errors());
            $this->assertTrue(is_array($exception->errors()['full_name']));
            $this->assertCount(1, $exception->errors()['full_name']);
            $this->assertContains('required', strtolower($exception->errors()['full_name'][0]));

            return;
        }

        $this->fail('A missing required field must throw a ValidationException.');
    }

    public function testNullableFieldReturnsNull(): void
    {
        $this->assertSame(['email' => null], Validator::validate(['email' => null], ['email' => 'nullable|email']));
        $this->assertSame(['email' => null], Validator::validate(['email' => ''], ['email' => 'nullable|email']));
    }

    public function testOptionalFieldIsOmittedFromOutput(): void
    {
        $this->assertSame([], Validator::validate([], ['nickname' => 'string|max:10']));
    }

    public function testMaxAndMinLengthRules(): void
    {
        $this->assertSame(['name' => 'Grace'], Validator::validate(['name' => ' Grace '], ['name' => 'required|string|max:10']));

        try {
            Validator::validate(['name' => str_repeat('a', 11)], ['name' => 'required|string|max:10']);
            $this->fail('max:10 must reject an 11 character value.');
        } catch (ValidationException $exception) {
            $this->assertContains('longer than 10', $exception->errors()['name'][0]);
        }

        try {
            Validator::validate(['name' => 'ab'], ['name' => 'required|string|min:3']);
            $this->fail('min:3 must reject a 2 character value.');
        } catch (ValidationException $exception) {
            $this->assertContains('at least 3', $exception->errors()['name'][0]);
        }
    }

    public function testPhoneRuleNormalisesToE164(): void
    {
        $validated = Validator::validate(
            ['phone' => '0712 345 678'],
            ['phone' => 'required|phone'],
            ['phone' => 'Phone number']
        );

        $this->assertSame(['phone' => '+254712345678'], $validated);

        try {
            Validator::validate(['phone' => 'not-a-number'], ['phone' => 'required|phone']);
            $this->fail('The phone rule must reject invalid numbers.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('phone', $exception->errors());
            $this->assertContains('valid phone', $exception->errors()['phone'][0]);
        }
    }

    public function testUuidRule(): void
    {
        $uuid = \App\Core\Uuid::v4();
        $this->assertSame(['id' => $uuid], Validator::validate(['id' => $uuid], ['id' => 'required|uuid']));

        try {
            Validator::validate(['id' => 'nope'], ['id' => 'required|uuid']);
            $this->fail('The uuid rule must reject non-UUID values.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('id', $exception->errors());
        }
    }

    public function testDigitsRule(): void
    {
        $this->assertSame(['code' => '123456'], Validator::validate(['code' => '123456'], ['code' => 'required|digits:6']));

        foreach (['12345', '1234567', 'abcdef', '12 34 56'] as $bad) {
            try {
                Validator::validate(['code' => $bad], ['code' => 'required|digits:6']);
                $this->fail(sprintf('digits:6 must reject "%s".', $bad));
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testInRule(): void
    {
        $this->assertSame(
            ['purpose' => 'login'],
            Validator::validate(['purpose' => 'login'], ['purpose' => 'required|in:login,staff_login'])
        );

        try {
            Validator::validate(['purpose' => 'owner'], ['purpose' => 'required|in:login,staff_login']);
            $this->fail('The in rule must reject values outside the list.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purpose', $exception->errors());
        }
    }

    public function testDateRuleConvertsToUtcDatabaseFormat(): void
    {
        $this->assertSame(
            ['starts_at' => '2026-11-11 08:00:00.000'],
            Validator::validate(['starts_at' => '2026-11-11T08:00:00Z'], ['starts_at' => 'required|date'])
        );

        try {
            Validator::validate(['starts_at' => 'not-a-date'], ['starts_at' => 'required|date']);
            $this->fail('The date rule must reject invalid dates.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }
    }

    public function testIntegerRule(): void
    {
        $this->assertSame(['count' => 42], Validator::validate(['count' => '42'], ['count' => 'required|integer']));

        try {
            Validator::validate(['count' => '4.2'], ['count' => 'required|integer']);
            $this->fail('The integer rule must reject non-integers.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('count', $exception->errors());
        }
    }

    public function testUnknownFieldsAreDropped(): void
    {
        $validated = Validator::validate(
            [
                'full_name' => 'Grace Wanjiku',
                'role_id' => 1,
                'estate_id' => 99,
                'is_admin' => true,
                'permissions' => ['platform.audit.view'],
            ],
            ['full_name' => 'required|string|max:180']
        );

        $this->assertSame(['full_name' => 'Grace Wanjiku'], $validated);
        $this->assertFalse(array_key_exists('role_id', $validated), 'Authority-bearing fields must never be mass assigned.');
        $this->assertFalse(array_key_exists('is_admin', $validated));
    }

    public function testMultipleFieldsReportIndependentErrors(): void
    {
        try {
            Validator::validate(
                ['email' => 'not-an-email'],
                ['full_name' => 'required|string', 'email' => 'required|email']
            );
            $this->fail('Two invalid fields must throw.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('full_name', $exception->errors());
            $this->assertArrayHasKey('email', $exception->errors());
            $this->assertCount(2, $exception->errors());
        }
    }

    public function testUnknownRuleIsProgrammerError(): void
    {
        try {
            Validator::validate(['x' => 'y'], ['x' => 'definitely-not-a-rule']);
            $this->fail('An unknown validation rule must throw a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertContains('Unknown validation rule', $exception->getMessage());
        }
    }
}
