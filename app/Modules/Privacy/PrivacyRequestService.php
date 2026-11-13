<?php

declare(strict_types=1);

namespace App\Modules\Privacy;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Logger;
use App\Integrations\Mail\MailManager;
use App\Integrations\Mail\MailMessage;
use Throwable;

/**
 * Records a data-subject request and notifies the privacy inbox.
 *
 * Order matters: the request is written to the database *first* (that is the
 * durable record of the data subject exercising a right), then the internal
 * notification is attempted. A mail-provider outage must never make a valid
 * request disappear or bounce the visitor back with an error.
 *
 * No personal data is written to the application log, the audit trail or the
 * mail-provider log: only the reference, request type and status travel there.
 * Phone/email are stored on the request row (needed to verify and action it) and
 * included only in the internal notification body.
 */
final class PrivacyRequestService
{
    public function __construct(
        private readonly PrivacyRequestRepository $repository
    ) {
    }

    public static function make(Context $context): self
    {
        return new self(new PrivacyRequestRepository($context->db));
    }

    /**
     * @param array{request_type: string, subject_name: string, subject_phone: string, subject_email: string|null, message: string|null} $validated
     * @return array{reference: string, request_type: string, policy_version: string}
     */
    public function submit(array $validated, Context $context): array
    {
        $policyVersion = trim((string) Config::get('privacy.version', '1.0'));

        $record = $this->repository->create([
            'request_type' => $validated['request_type'],
            'subject_name' => $validated['subject_name'],
            'subject_phone' => $validated['subject_phone'],
            'subject_email' => $validated['subject_email'],
            'message' => $validated['message'],
            'policy_version' => $policyVersion !== '' ? $policyVersion : '1.0',
        ]);

        // The audit trail records that a right was exercised, not who exercised
        // it: reference + type + version only, no name/phone/email.
        $context->audit->record('privacy.request_created', 'privacy_request', $record['id'], [
            'reference' => $record['reference'],
            'request_type' => $validated['request_type'],
            'policy_version' => $policyVersion,
            'status' => 'pending',
        ]);

        $this->notify($record['reference'], $validated, $policyVersion);

        return [
            'reference' => $record['reference'],
            'request_type' => $validated['request_type'],
            'policy_version' => $policyVersion,
        ];
    }

    /**
     * @param array<string, string|null> $validated
     */
    private function notify(string $reference, array $validated, string $policyVersion): void
    {
        try {
            $recipients = $this->recipients();
            $typeLabel = PrivacyRequestRepository::TYPE_LABELS[$validated['request_type']]
                ?? $validated['request_type'];

            (new MailManager())->send(new MailMessage(
                $recipients,
                'Privacy request ' . $reference . ': ' . $typeLabel,
                $this->messageBody($reference, $validated, $policyVersion),
                null,
                is_string($validated['subject_email']) ? $validated['subject_email'] : null,
                ['event' => 'privacy.request']
            ));
        } catch (Throwable $exception) {
            // Never throw: the request row already exists and is the source of
            // truth. Log the failure class only (no PII, no provider response).
            Logger::warning('Privacy request notification failed.', [
                'event' => 'privacy.request',
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Valid privacy inboxes, most specific first.
     *
     * @return array<int, string>
     */
    private function recipients(): array
    {
        $candidates = [
            (string) Config::get('mail.privacy_to', ''),
            (string) Config::get('privacy.privacy_email', ''),
            (string) Config::get('mail.contact_to', ''),
            (string) Config::get('app.support_email', ''),
        ];

        $configured = Config::get('mail.alert_to', []);

        if (is_array($configured)) {
            foreach ($configured as $candidate) {
                if (is_string($candidate)) {
                    $candidates[] = $candidate;
                }
            }
        }

        $recipients = [];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $recipients[strtolower($candidate)] = $candidate;
        }

        if ($recipients === []) {
            throw new \RuntimeException('No valid privacy request recipient is configured.');
        }

        return array_values($recipients);
    }

    /**
     * @param array<string, string|null> $validated
     */
    private function messageBody(string $reference, array $validated, string $policyVersion): string
    {
        return implode("\n", [
            'New data-subject request from the public privacy pages.',
            '',
            'Reference: ' . $reference,
            'Type: ' . (PrivacyRequestRepository::TYPE_LABELS[$validated['request_type']] ?? $validated['request_type']),
            'Submitted: ' . Clock::nowDb() . ' UTC',
            'Notice version: ' . $policyVersion,
            '',
            'Name: ' . $this->singleLine((string) ($validated['subject_name'] ?? '')),
            'Phone: ' . $this->singleLine((string) ($validated['subject_phone'] ?? '')),
            'Email: ' . $this->singleLine((string) ($validated['subject_email'] ?? '')),
            '',
            'Message:',
            trim((string) ($validated['message'] ?? '')),
            '',
            'Action: verify the requester, then update privacy_requests.status for this',
            'reference and complete the request within ' . $this->responseDays() . ' days.',
        ]);
    }

    private function responseDays(): int
    {
        return max(1, (int) Config::get('privacy.response_days', 30));
    }

    /**
     * Collapse control characters/newlines so a value can never inject mail
     * headers when it reaches the provider.
     */
    private function singleLine(string $value): string
    {
        $collapsed = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;

        return trim((string) preg_replace('/\s+/u', ' ', $collapsed));
    }
}
