<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Integrations\Sms\SmsManager;
use App\Integrations\Sms\SmsProvider;
use App\Modules\Notifications\NotificationLogger;
use Throwable;

/**
 * Dev SMS notifications for admin-created residents.
 *
 * The SMS provider is resolved lazily (same pattern as VisitorNotificationService)
 * so read-only admin pages never construct a provider, and a delivery failure is
 * logged without failing the admin transaction. Messages use the configured
 * product name (MtaaPass in dev, DashPASS in production) and never contain
 * credentials or PINs.
 */
final class AdminNotifier
{
    private ?SmsProvider $sms = null;

    private ?string $providerName = null;

    private ?NotificationLogger $notifications = null;

    public function __construct(private readonly Database $db)
    {
    }

    public static function make(?Database $db = null): self
    {
        return new self($db ?? Database::instance());
    }

    /**
     * "You've been added to {estate} on {product}. Sign in with this phone number."
     */
    public function sendResidentInvite(int $estateId, string $phone, string $estateName): void
    {
        $message = sprintf(
            "You've been added to %s on %s. Sign in with this phone number.",
            $estateName,
            $this->productName()
        );

        $status = 'sent';
        $messageId = null;
        $error = null;
        $providerName = $this->providerName();

        try {
            $delivery = $this->provider()->send($phone, $message);
            $messageId = isset($delivery['message_id']) ? (string) $delivery['message_id'] : null;
            $reported = (string) ($delivery['status'] ?? 'sent');
            $status = in_array($reported, ['queued', 'sent', 'delivered'], true) ? $reported : 'sent';
        } catch (Throwable $exception) {
            $status = 'failed';
            $error = $exception->getMessage();
            Logger::warning('Resident invite SMS delivery failed', [
                'template' => 'resident_invite',
                'error' => mb_substr($error, 0, 300),
            ]);
        }

        $this->notificationLogger()->logSms(
            $estateId,
            $phone,
            'resident_invite',
            $providerName,
            $messageId,
            $status,
            $error
        );
    }

    private function provider(): SmsProvider
    {
        if ($this->sms === null) {
            $manager = new SmsManager();
            $this->sms = $manager->make();
            $this->providerName = $manager->driver();
        }

        return $this->sms;
    }

    private function providerName(): string
    {
        if ($this->providerName === null) {
            $this->providerName = (new SmsManager())->driver();
        }

        return $this->providerName;
    }

    private function notificationLogger(): NotificationLogger
    {
        return $this->notifications ??= new NotificationLogger($this->db);
    }

    private function productName(): string
    {
        $name = trim((string) Config::get('app.name', 'MtaaPass'));

        return $name === '' ? 'MtaaPass' : $name;
    }
}
