<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

/**
 * Base class for integration tests.
 *
 * Every integration test starts from a freshly migrated and seeded `_test`
 * database (contract §11) with all OTP/throttle rate-limit windows cleared, so
 * tests cannot leak state into one another.
 */
abstract class IntegrationTestCase extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshDatabase();
        $this->clearRateLimits();
    }
}
