<?php

declare(strict_types=1);

namespace App\Modules\Visitors;

use App\Core\Database;
use App\Core\Logger;
use App\Integrations\Sms\SmsManager;
use App\Integrations\Sms\SmsProvider;
use App\Modules\Notifications\NotificationLogger;
use Throwable;

/**
 * Dev SMS notifications for the visitor workflow (contract §4.1, §4.5, §4.6, §8).
 *
 * Every send is recorded in `notification_logs` with the template name and
 * provider message id. The message body is never logged by the application, and
 * `LogSmsProvider` redacts credential-shaped tokens, so PINs and pass tokens
 * never reach log files or the audit trail. Delivery failures are logged and
 * never roll a business transaction back.
 */
final class VisitorNotificationService
{
    private ?SmsProvider $sms;

    private ?string $providerName;

    private ?NotificationLogger $notifications = null;

    public function __construct(
        private readonly Database $db,
        ?SmsProvider $sms = null,
        ?string $providerName = null
    ) {
        $this->sms = $sms;
        $this->providerName = $providerName;
    }

    /**
     * The SMS provider is resolved lazily so read-only pages never construct a
     * provider (and a production deployment without a real adapter fails only
     * when a notification genuinely has to be sent).
     */
    public static function make(?Database $db = null): self
    {
        return new self($db ?? Database::instance());
    }

    /**
     * Visitor invitation (contract §4.1 template).
     */
    public function invitation(
        int $estateId,
        string $recipient,
        string $estateName,
        string $householdLabel,
        string $pin,
        string $passUrl,
        string $validFrom,
        string $validUntil,
        string $timezone
    ): void {
        $window = AccessPresenter::window($validFrom, $validUntil, $timezone);
        $message = sprintf(
            'MtaaPass: You are invited to visit %s, %s. Your access PIN is %s. '
            . 'Valid %s %s-%s. Show this code at the gate. Pass: %s',
            $estateName,
            $householdLabel,
            $pin,
            $window['date'],
            $window['start'],
            $window['end'],
            $passUrl
        );

        $this->send($estateId, $recipient, $message, 'visitor_invitation');
    }

    public function checkedIn(
        int $estateId,
        string $hostPhone,
        string $visitorName,
        string $estateName,
        string $occurredAt,
        string $timezone
    ): void {
        $message = sprintf(
            '%s has entered %s at %s.',
            $visitorName,
            $estateName,
            AccessPresenter::time($occurredAt, $timezone)
        );

        $this->send($estateId, $hostPhone, $message, 'visitor_checked_in');
    }

    public function checkedOut(
        int $estateId,
        string $hostPhone,
        string $visitorName,
        string $estateName,
        string $occurredAt,
        string $timezone
    ): void {
        $message = sprintf(
            '%s departed %s at %s.',
            $visitorName,
            $estateName,
            AccessPresenter::time($occurredAt, $timezone)
        );

        $this->send($estateId, $hostPhone, $message, 'visitor_checked_out');
    }

    public function walkInRequest(
        int $estateId,
        string $recipient,
        string $visitorName,
        string $gateName,
        string $householdLabel
    ): void {
        $message = sprintf(
            'MtaaPass: %s is at %s and requests access to %s. Approve in MtaaPass.',
            $visitorName,
            $gateName,
            $householdLabel
        );

        $this->send($estateId, $recipient, $message, 'walkin_request');
    }

    public function walkInApproved(
        int $estateId,
        string $recipient,
        string $estateName,
        string $householdLabel,
        string $pin,
        string $passUrl,
        string $validUntil,
        string $timezone
    ): void {
        $message = sprintf(
            'MtaaPass: Your walk-in visit to %s, %s is approved. Your access PIN is %s. '
            . 'Valid until %s. Show this code at the gate. Pass: %s',
            $estateName,
            $householdLabel,
            $pin,
            AccessPresenter::time($validUntil, $timezone),
            $passUrl
        );

        $this->send($estateId, $recipient, $message, 'walkin_approved');
    }

    private function send(?int $estateId, string $recipient, string $message, string $template): void
    {
        $status = 'sent';
        $messageId = null;
        $error = null;
        $providerName = $this->providerName();

        try {
            $delivery = $this->provider()->send($recipient, $message);
            $messageId = isset($delivery['message_id']) ? (string) $delivery['message_id'] : null;
            $reported = (string) ($delivery['status'] ?? 'sent');
            $status = in_array($reported, ['queued', 'sent', 'delivered'], true) ? $reported : 'sent';
        } catch (Throwable $exception) {
            $status = 'failed';
            $error = $exception->getMessage();
            // Never log the message body: it can contain a PIN or pass token.
            Logger::warning('Visitor SMS delivery failed', [
                'template' => $template,
                'provider' => $providerName,
                'error' => mb_substr($error, 0, 300),
            ]);
        }

        $this->notificationLogger()->logSms(
            $estateId,
            $recipient,
            $template,
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
}
