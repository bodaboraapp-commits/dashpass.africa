<?php

declare(strict_types=1);

namespace App\Integrations\Mail;

use App\Core\Config;
use App\Core\Logger;

/**
 * Resolves and caches the configured mail driver.
 *
 * `log` is the development/test adapter and always works (no key required);
 * `resend` is the production provider and is only "configured" when an API key is
 * present. Selecting `log` in production emits a warning but never fails boot or
 * request handling. A test may inject a provider directly via
 * {@see MailManager::withProvider()}.
 */
final class MailManager
{
    /** @var array<int, string> */
    public const DRIVERS = ['log', 'resend'];

    private ?MailProvider $provider = null;

    private static bool $productionLogWarningEmitted = false;

    public function __construct(private readonly ?MailProvider $injectedProvider = null)
    {
    }

    /**
     * Build a manager around an explicit provider (tests / integrations).
     */
    public static function withProvider(MailProvider $provider): self
    {
        return new self($provider);
    }

    /**
     * The configured driver name. Unknown values fall back to `log` safely.
     */
    public function driver(): string
    {
        $driver = strtolower(trim((string) Config::get('mail.driver', 'log')));

        if (!in_array($driver, self::DRIVERS, true)) {
            Logger::warning('Unsupported mail driver configured; falling back to the log adapter.', [
                'driver' => $driver,
            ]);

            return 'log';
        }

        return $driver;
    }

    /**
     * True when the configured driver has everything it needs to send.
     */
    public function isConfigured(): bool
    {
        if ($this->injectedProvider !== null) {
            return true;
        }

        if ($this->driver() === 'resend') {
            return trim((string) Config::get('mail.resend.key', '')) !== '';
        }

        return true;
    }

    /**
     * Build a provider for the given (or configured) driver.
     *
     * A missing Resend key does not throw: the provider becomes a disabled no-op
     * that logs one warning per send, so development and partially-configured
     * deployments keep working.
     */
    public function make(?string $driver = null): MailProvider
    {
        if ($this->injectedProvider !== null) {
            return $this->injectedProvider;
        }

        $driver = strtolower(trim($driver ?? $this->driver()));

        if (!in_array($driver, self::DRIVERS, true)) {
            Logger::warning('Unsupported mail driver configured; falling back to the log adapter.', [
                'driver' => $driver,
            ]);
            $driver = 'log';
        }

        if ($driver === 'log' && $this->isProduction()) {
            self::warnLogDriverInProduction();
        }

        return match ($driver) {
            'resend' => new ResendMailProvider(),
            default => new LogMailProvider(),
        };
    }

    /**
     * Lazily constructed provider for the configured driver.
     */
    public function provider(): MailProvider
    {
        return $this->provider ??= $this->make();
    }

    /**
     * @return array{message_id: string, provider: string, status: string}
     */
    public function send(MailMessage $message): array
    {
        $provider = $this->provider();
        $delivery = $provider->send($message);

        return [
            'message_id' => (string) ($delivery['message_id'] ?? ''),
            'provider' => (string) ($delivery['provider'] ?? $provider->name()),
            'status' => (string) ($delivery['status'] ?? 'sent'),
        ];
    }

    private function isProduction(): bool
    {
        return (string) Config::get('app.env', 'production') === 'production';
    }

    private static function warnLogDriverInProduction(): void
    {
        if (self::$productionLogWarningEmitted) {
            return;
        }

        self::$productionLogWarningEmitted = true;

        Logger::warning(
            'MAIL_DRIVER=log is a development adapter; configure MAIL_DRIVER=resend '
            . 'and a verified sender for production email delivery.'
        );
    }
}
