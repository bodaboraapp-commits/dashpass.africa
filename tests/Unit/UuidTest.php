<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Uuid;
use Tests\TestCase;

/**
 * Blueprint §14/§16: public identifiers are UUID v4 values, never sequential ids.
 */
final class UuidTest extends TestCase
{
    public function testGeneratesValidVersion4Uuid(): void
    {
        $uuid = Uuid::v4();

        $this->assertMatches(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
            'Uuid::v4() must return a lowercase RFC 4122 version 4 UUID.'
        );
        $this->assertTrue(Uuid::isValid($uuid), 'Uuid::isValid() must accept generated UUIDs.');
        $this->assertSame('4', $uuid[14], 'The version nibble must be 4.');
        $this->assertContains($uuid[19], ['8', '9', 'a', 'b'], 'The variant nibble must be RFC 4122.');
    }

    public function testUuidsAreUnique(): void
    {
        $values = [];

        for ($i = 0; $i < 500; $i++) {
            $values[] = Uuid::v4();
        }

        $this->assertCount(500, array_unique($values), 'Uuid::v4() produced duplicate values.');
    }

    public function testAcceptsUppercaseUuid(): void
    {
        $this->assertTrue(Uuid::isValid(strtoupper(Uuid::v4())));
    }

    public function testAcceptsOtherRfc4122Versions(): void
    {
        $this->assertTrue(Uuid::isValid('123e4567-e89b-12d3-a456-426614174000'));
        $this->assertTrue(Uuid::isValid('123e4567-e89b-52d3-a456-426614174000'));
    }

    public function testRejectsInvalidValues(): void
    {
        $invalid = [
            '',
            'not-a-uuid',
            '123',
            '123e4567e89b12d3a456426614174000',
            '123e4567-e89b-62d3-a456-426614174000', // version 6 is not valid here
            '123e4567-e89b-12d3-0456-426614174000', // wrong variant
            '123e4567-e89b-12d3-a456-42661417400',  // too short
            '123e4567-e89b-12d3-a456-4266141740000', // too long
            '123e4567-e89b-12d3-a456_426614174000',
            'gggggggg-e89b-12d3-a456-426614174000',
        ];

        foreach ($invalid as $value) {
            $this->assertFalse(
                Uuid::isValid($value),
                sprintf('Uuid::isValid() must reject "%s".', $value)
            );
        }
    }
}
