<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Logger;
use App\Integrations\Mail\MailManager;
use App\Integrations\Mail\MailMessage;
use App\Integrations\Mail\MailSendException;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Security-event email alerts (Increment 5 §1.3/§1.4).
 *
 * Alert events: `visitor.checked_in`, `visitor.checked_out`, `walkin.created`.
 * Recipients are the host's `users.email` when present plus the configured
 * `mail.alert_to` fallback inboxes (deduped, validated). Emails are sent only from
 * post-commit notification points and every failure is swallowed and audited, so
 * a mail problem can never block or 500 a visitor flow.
 *
 * Privacy: email bodies never contain PINs, OTPs, QR/pass tokens or phone numbers;
 * audit metadata carries masked recipients only.
 */
final class EmailAlertService
{
    /** @var array<int, string> */
    private const EVENTS = ['visitor.checked_in', 'visitor.checked_out', 'walkin.created', 'incident.reported'];

    public function __construct(
        private readonly Context $context,
        private readonly MailManager $mail
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, new MailManager());
    }

    /**
     * Build an alert service around an explicit mail provider (tests).
     */
    public static function withProvider(Context $context, MailManager $mail): self
    {
        return new self($context, $mail);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function sendSecurityAlert(string $event, array $data): void
    {
        $estateId = 0;

        try {
            $membership = $this->context->tenant->active();

            if ($membership === null) {
                return;
            }

            $estateId = (int) $membership['estate_id'];

            if (!in_array($event, self::EVENTS, true)) {
                Logger::warning('Unknown email alert event ignored.', ['event' => $event]);

                return;
            }

            $recipients = $this->recipients($data);

            if ($recipients === []) {
                Logger::debug('Email alert skipped: no valid recipients.', ['event' => $event]);

                return;
            }

            $message = $this->buildMessage($event, $data, $recipients, $membership);
            $delivery = $this->mail->send($message);
            $status = (string) ($delivery['status'] ?? 'sent');
            $provider = (string) ($delivery['provider'] ?? 'unknown');

            if ($status === 'disabled') {
                Logger::warning('Email alert skipped: mail provider is disabled.', [
                    'event' => $event,
                    'provider' => $provider,
                ]);

                return;
            }

            $this->context->audit->record(
                'email.alert_sent',
                'email_alert',
                null,
                [
                    'event' => $event,
                    'provider' => $provider,
                    'status' => $status,
                    'recipients_count' => count($recipients),
                    'recipients' => $message->maskedRecipients(),
                ],
                $estateId
            );
        } catch (Throwable $exception) {
            $this->auditFailure($estateId, $event, $exception);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, string> Valid, deduplicated recipient addresses.
     */
    private function recipients(array $data): array
    {
        $candidates = [];

        foreach (['host_email', 'host_emails', 'recipient_emails'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value)) {
                $candidates[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $candidate) {
                    if (is_string($candidate)) {
                        $candidates[] = $candidate;
                    }
                }
            }
        }

        $fallback = Config::get('mail.alert_to', []);
        if (is_array($fallback)) {
            foreach ($fallback as $candidate) {
                if (is_string($candidate)) {
                    $candidates[] = $candidate;
                }
            }
        }

        $recipients = [];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $recipients[strtolower($candidate)] = $candidate;
        }

        return array_values($recipients);
    }

    /**
     * @param array<int, string> $recipients
     * @param array<string, mixed> $membership
     */
    private function buildMessage(
        string $event,
        array $data,
        array $recipients,
        array $membership
    ): MailMessage {
        $product = $this->productName();
        $visitorName = $this->clean((string) ($data['visitor_name'] ?? 'A visitor'));
        $gateName = $this->clean((string) ($data['gate_name'] ?? 'the gate'));
        $estateName = $this->clean((string) ($data['estate_name'] ?? ($membership['estate_name'] ?? '')));
        $household = $this->clean((string) ($data['household_label'] ?? ''));
        $time = $this->formatTime(
            isset($data['occurred_at']) ? (string) $data['occurred_at'] : null,
            (string) ($data['timezone'] ?? ($membership['estate_timezone'] ?? 'Africa/Nairobi'))
        );

        $place = $estateName === '' ? '' : $estateName;
        $for = $household === '' ? '' : $household;

        $severityLabel = $this->clean(
            (string) ($data['severity_label'] ?? ucfirst((string) ($data['severity'] ?? 'medium')))
        );
        $incidentTypeLabel = $this->clean(
            (string) ($data['incident_type_label']
                ?? ucfirst(str_replace('_', ' ', (string) ($data['incident_type'] ?? 'other'))))
        );
        $incidentSummary = $this->clean((string) ($data['summary'] ?? ''));

        [$subject, $lines] = match ($event) {
            'visitor.checked_out' => [
                sprintf('%s: Visitor checked out — %s', $product, $visitorName),
                [
                    sprintf('Visitor checked out — %s', $visitorName),
                    sprintf('Gate: %s', $gateName),
                    $place === '' ? null : sprintf('Estate: %s', $place),
                    $for === '' ? null : sprintf('Household: %s', $for),
                    sprintf('Time: %s', $time),
                ],
            ],
            'walkin.created' => [
                sprintf('%s: Walk-in visitor awaiting approval — %s', $product, $visitorName),
                [
                    sprintf('Walk-in visitor awaiting approval — %s', $visitorName),
                    sprintf('Gate: %s', $gateName),
                    $place === '' ? null : sprintf('Estate: %s', $place),
                    $for === '' ? null : sprintf('Household: %s', $for),
                    sprintf('Time: %s', $time),
                ],
            ],
            'incident.reported' => [
                sprintf('%s: Security incident reported — %s severity', $product, $severityLabel),
                [
                    'A security incident was reported.',
                    sprintf('Type: %s', $incidentTypeLabel),
                    sprintf('Severity: %s', $severityLabel),
                    $place === '' ? null : sprintf('Estate: %s', $place),
                    sprintf('Occurred: %s', $time),
                    $incidentSummary === '' ? null : sprintf('Summary: %s', $incidentSummary),
                ],
            ],
            default => [
                sprintf('%s: Visitor checked in — %s', $product, $visitorName),
                [
                    sprintf('Visitor checked in — %s', $visitorName),
                    sprintf('Gate: %s', $gateName),
                    $place === '' ? null : sprintf('Estate: %s', $place),
                    $for === '' ? null : sprintf('Household: %s', $for),
                    sprintf('Time: %s', $time),
                ],
            ],
        };

        $lines = array_values(array_filter($lines, static fn (?string $line): bool => $line !== null && $line !== ''));
        $lines[] = '';
        $lines[] = sprintf(
            'You are receiving this %s security alert because you are the host or a designated estate contact.',
            $product
        );

        return new MailMessage(
            $recipients,
            $subject,
            implode("\n", $lines),
            null,
            $this->replyTo(),
            ['event' => $event]
        );
    }

    private function auditFailure(int $estateId, string $event, Throwable $exception): void
    {
        $provider = $exception instanceof MailSendException ? $exception->provider() : $this->safeProviderName();
        $status = $exception instanceof MailSendException && $exception->httpStatus() !== null
            ? 'http_' . $exception->httpStatus()
            : 'failed';

        Logger::warning('Email alert delivery failed; business flow continues.', [
            'event' => $event,
            'provider' => $provider,
            'status' => $status,
            'error' => mb_substr($exception->getMessage(), 0, 300),
        ]);

        if ($estateId === 0) {
            return;
        }

        try {
            $this->context->audit->record(
                'email.alert_failed',
                'email_alert',
                null,
                [
                    'event' => $event,
                    'provider' => $provider,
                    'status' => $status,
                ],
                $estateId
            );
        } catch (Throwable) {
            // Audit is best-effort here: never let alert bookkeeping fail a flow.
        }
    }

    private function safeProviderName(): string
    {
        try {
            return $this->mail->driver();
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function replyTo(): ?string
    {
        $replyTo = trim((string) Config::get('mail.reply_to', ''));

        return $replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : null;
    }

    private function productName(): string
    {
        $name = trim((string) Config::get('app.name', 'MtaaPass'));

        return $name === '' ? 'MtaaPass' : $name;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function formatTime(?string $utc, string $timezone): string
    {
        $moment = Clock::fromDb($utc) ?? Clock::now();

        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable) {
            $zone = new DateTimeZone('Africa/Nairobi');
        }

        /** @var DateTimeImmutable $local */
        $local = $moment->setTimezone($zone);

        return $local->format('D, d M Y H:i T');
    }
}
