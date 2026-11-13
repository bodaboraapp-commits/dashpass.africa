<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Core\Config;
use App\Core\Context;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Integrations\Mail\MailManager;
use App\Integrations\Mail\MailMessage;
use Throwable;

/**
 * Public Help centre (`GET /help`) and its contact form (`POST /help/contact`).
 *
 * The FAQ uses native <details>/<summary> so it works without JavaScript. The
 * contact form validates server-side, is CSRF-protected by the global
 * middleware, route-throttled, and delivered through the existing mail
 * infrastructure (log adapter in dev/test, SMTP/Resend in production). No
 * database tables are added: feedback is flash + redirect.
 */
final class HelpController
{
    use BuildsGuestMarketingPage;

    public const THANKS_MESSAGE = 'Thanks — your message has been sent. We will reply to your email as soon as we can.';

    public const GENERIC_SEND_ERROR = 'We could not send your message right now. Please email hello@dashpass.africa directly.';

    public function index(Request $request, array $params, Context $context): Response
    {
        $errors = Session::pullFlash('errors', []);
        $old = Session::pullFlash('old', []);
        $success = Session::pullFlash('success', '');
        $sendError = Session::pullFlash('error', '');

        return $this->renderMarketingPage(
            'marketing/help',
            'Help',
            'DashPASS help centre: frequently asked questions about passes, QR and PIN codes, '
                . 'offline access, payments, visitor invitations, accounts and security — plus a contact form.',
            [
                'errors' => is_array($errors) ? $errors : [],
                'old' => is_array($old) ? $old : [],
                'success' => is_string($success) ? trim($success) : '',
                'sendError' => is_string($sendError) ? trim($sendError) : '',
            ]
        );
    }

    public function contact(Request $request, array $params, Context $context): Response
    {
        // Cheap anti-spam honeypot: hidden field humans never fill in. A filled
        // honeypot silently "succeeds" so bots learn nothing.
        if (trim($request->string('website')) !== '') {
            Session::flash('success', self::THANKS_MESSAGE);

            return Response::redirect('/help');
        }

        $old = [
            'name' => trim($request->string('name')),
            'email' => trim($request->string('email')),
            'subject' => trim($request->string('subject')),
            'message' => trim($request->string('message')),
        ];

        try {
            $data = Validator::validate(
                $old,
                [
                    'name' => 'required|string|min:2|max:120',
                    'email' => 'required|email|max:190',
                    'subject' => 'required|string|min:3|max:120',
                    'message' => 'required|string|min:10|max:4000',
                ],
                [
                    'name' => 'your name',
                    'email' => 'email address',
                    'subject' => 'subject',
                    'message' => 'message',
                ]
            );
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $old);

            return Response::redirect('/help');
        }

        $subject = $this->singleLine((string) $data['subject']);
        if ($subject === '') {
            $subject = 'General enquiry';
        }

        try {
            (new MailManager())->send(new MailMessage(
                $this->recipients(),
                'DashPASS contact form: ' . mb_substr($subject, 0, 120),
                $this->messageBody($data),
                null,
                (string) $data['email'],
                ['event' => 'marketing.contact']
            ));
        } catch (Throwable $exception) {
            // Never leak provider details or the visitor's PII into logs.
            Logger::warning('Contact form delivery failed.', [
                'event' => 'marketing.contact',
                'exception' => $exception::class,
            ]);
            Session::flash('error', self::GENERIC_SEND_ERROR);
            Session::flash('old', $old);

            return Response::redirect('/help');
        }

        Session::flash('success', self::THANKS_MESSAGE);

        return Response::redirect('/help');
    }

    /**
     * Valid support inboxes: configured alert recipients, else the public support
     * address. Always returns at least one valid address or throws.
     *
     * @return array<int, string>
     */
    private function recipients(): array
    {
        $candidates = [];
        $configured = Config::get('mail.alert_to', []);

        if (is_array($configured)) {
            foreach ($configured as $candidate) {
                if (is_string($candidate)) {
                    $candidates[] = $candidate;
                }
            }
        }

        $candidates[] = (string) Config::get('app.support_email', 'hello@dashpass.africa');

        $recipients = [];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $recipients[strtolower($candidate)] = $candidate;
        }

        if ($recipients === []) {
            throw new \RuntimeException('No valid support recipient is configured.');
        }

        return array_values($recipients);
    }

    /**
     * @param array<string, mixed> $data Validated contact-form data.
     */
    private function messageBody(array $data): string
    {
        return implode("\n", [
            'New message from the DashPASS Help page contact form.',
            '',
            'Name: ' . $this->singleLine((string) $data['name']),
            'Email: ' . $this->singleLine((string) $data['email']),
            'Subject: ' . $this->singleLine((string) $data['subject']),
            '',
            'Message:',
            trim((string) $data['message']),
            '',
            'Sent from the public Help page (/help).',
        ]);
    }

    /**
     * Collapse all control characters/newlines into single spaces (also prevents
     * header injection in the subject line).
     */
    private function singleLine(string $value): string
    {
        $collapsed = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;

        return trim((string) preg_replace('/\s+/u', ' ', $collapsed));
    }
}
