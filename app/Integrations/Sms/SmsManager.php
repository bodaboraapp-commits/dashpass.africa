<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Core\Config;
use RuntimeException;

/**
 * Resolves the configured SMS driver.
 *
 * Development adapters must never be able to silently pretend they delivered a
 * real message in production, so selecting one there is a hard failure
 * (Blueprint §28; foundation contract §6).
 */
final class SmsManager
{
    /** @var array<int, string> */
    public const DRIVERS = ['log', 'mock'];

    /**
     * The configured driver name (defaults to `log`).
     */
    public function driver(): string
    {
        $driver = strtolower(trim((string) Config::get('sms.driver', 'log')));

        return $driver === '' ? 'log' : $driver;
    }

    /**
     * Build the configured provider, or fail loudly.
     *
     * @throws RuntimeException When the driver is unknown or a development
     *                          adapter is requested in production.
     */
    public function make(?string $driver = null): SmsProvider
    {
        $driver = strtolower(trim($driver ?? $this->driver()));

        if (!in_array($driver, self::DRIVERS, true)) {
            throw new RuntimeException('Unsupported SMS driver: ' . $driver);
        }

        if ($this->isProduction()) {
            throw new RuntimeException(
                'SMS driver "' . $driver . '" is a development adapter and cannot be used when APP_ENV=production. '
                . 'Configure a real SMS provider before deploying.'
            );
        }

        return match ($driver) {
            'mock' => new MockSmsProvider(),
            default => new LogSmsProvider(),
        };
    }

    /**
     * @return array<int, string>
     */
    public function supportedDrivers(): array
    {
        return self::DRIVERS;
    }

    private function isProduction(): bool
    {
        return (string) Config::get('app.env', 'production') === 'production';
    }
}
