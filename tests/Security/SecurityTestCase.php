<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\TestCase;

/**
 * Base class for security tests. Same isolation contract as integration tests:
 * a freshly migrated + seeded `_test` database and cleared rate-limit windows.
 */
abstract class SecurityTestCase extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshDatabase();
        $this->clearRateLimits();
    }
}
