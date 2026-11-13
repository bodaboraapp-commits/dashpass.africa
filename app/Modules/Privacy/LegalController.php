<?php

declare(strict_types=1);

namespace App\Modules\Privacy;

use App\Core\Config;
use App\Core\Context;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Modules\Marketing\BuildsGuestMarketingPage;

/**
 * Public legal + privacy centre.
 *
 *  - GET /privacy            Privacy Policy
 *  - GET /terms              Terms & Conditions
 *  - GET /data-policy        Data Protection & Processing Policy
 *  - GET /privacy/choices    Opt in / opt out and data-rights requests
 *  - GET /data-deletion      Dedicated "delete my data" request page
 *  - POST /privacy/choices   Records a data-subject request + notifies the team
 *
 * Everything is public (guest layout), rendered without authentication. The
 * POST is CSRF-protected by the global middleware, throttled by the route, and
 * carries a honeypot for bots. No personal data is ever echoed back on the
 * public pages: the requester receives only an opaque reference code.
 */
final class LegalController
{
    use BuildsGuestMarketingPage;

    private const RETURN_PATHS = ['/privacy/choices', '/data-deletion'];

    public function privacy(Request $request, array $params, Context $context): Response
    {
        return $this->renderMarketingPage(
            'marketing/privacy',
            'Privacy Policy',
            'How DashPASS collects, uses, shares and protects personal data, and the rights '
                . 'you can exercise under the Kenya Data Protection Act, 2019.',
            $this->legalShellData()
        );
    }

    public function terms(Request $request, array $params, Context $context): Response
    {
        return $this->renderMarketingPage(
            'marketing/terms',
            'Terms & Conditions',
            'The terms that govern use of DashPASS, the Pass & Pay management system for estates, '
                . 'by residents, estate administrators, guards and platform operators.',
            $this->legalShellData()
        );
    }

    public function dataPolicy(Request $request, array $params, Context $context): Response
    {
        return $this->renderMarketingPage(
            'marketing/data-policy',
            'Data Policy',
            'The DashPASS Data Protection & Processing Policy: what data we process, why, '
                . 'where it is held, how long it is kept and how it is secured.',
            $this->legalShellData()
        );
    }

    public function choices(Request $request, array $params, Context $context): Response
    {
        return $this->renderMarketingPage(
            'marketing/privacy-choices',
            'Your privacy choices',
            'Opt in or opt out of optional communications and exercise your data rights: '
                . 'access, correction or deletion of your personal data.',
            $this->choiceShellData() + $this->legalShellData()
        );
    }

    public function dataDeletion(Request $request, array $params, Context $context): Response
    {
        return $this->renderMarketingPage(
            'marketing/data-deletion',
            'Delete my data',
            'Request deletion of your personal data from DashPASS, and understand what is '
                . 'deleted, what is retained for legal reasons, and how long it takes.',
            $this->choiceShellData() + $this->legalShellData()
        );
    }

    public function submitRequest(Request $request, array $params, Context $context): Response
    {
        $returnTo = $this->returnPath($request);

        // Anti-spam honeypot: humans never fill a hidden field. Bots get the same
        // success shape (an opaque reference) so they learn nothing.
        if (trim($request->string('website')) !== '') {
            Session::flash('privacy_feedback', [
                'reference' => PrivacyRequestRepository::newReference(),
                'request_type' => 'opt_in',
            ]);

            return Response::redirect($returnTo);
        }

        // Three public forms post here: the communications opt in/out form, the
        // access/correction rights form and the dedicated deletion form. The
        // `form` marker keeps old input and error rendering attached to the form
        // the visitor actually submitted (no JS required).
        $form = $request->string('form');
        $form = in_array($form, ['communications', 'rights', 'deletion'], true) ? $form : 'rights';

        $rawType = match ($form) {
            'communications' => $request->string('consent_choice'),
            'deletion' => 'deletion',
            default => $request->string('request_type'),
        };

        $old = [
            'form' => $form,
            'request_type' => trim($rawType),
            'subject_name' => trim($request->string('subject_name')),
            'subject_phone' => trim($request->string('subject_phone')),
            'subject_email' => trim($request->string('subject_email')),
            'message' => trim($request->string('message')),
        ];

        $requestType = in_array($old['request_type'], PrivacyRequestRepository::TYPES, true)
            ? $old['request_type']
            : ($form === 'communications' ? 'opt_in' : 'access');

        $rules = [
            'request_type' => 'required|in:' . implode(',', PrivacyRequestRepository::TYPES),
            'subject_name' => 'required|string|min:2|max:120',
            'subject_phone' => 'required|phone',
            'subject_email' => 'nullable|email|max:190',
            'message' => 'nullable|string|max:2000',
        ];

        // The communications form uses its own field name so its required radio
        // error is attributed to the communications card, not the rights select.
        // The mapped `request_type` then only needs to stay in the allowed set.
        if ($form === 'communications') {
            $rules['request_type'] = 'in:opt_in,opt_out';
            $rules['consent_choice'] = 'required|in:opt_in,opt_out';
        }

        // A correction request is meaningless without the corrected value(s).
        if ($requestType === 'correction') {
            $rules['message'] = 'required|string|min:10|max:2000';
        }

        // Deletion is irreversible for the live account: require an explicit
        // acknowledgement, not just a click on a generic submit button.
        if ($requestType === 'deletion') {
            $rules['confirm'] = 'required|in:1';
        }

        try {
            $data = Validator::validate(
                $old + [
                    'consent_choice' => trim($request->string('consent_choice')),
                    'confirm' => (string) $request->string('confirm'),
                ],
                $rules,
                [
                    'request_type' => 'request type',
                    'consent_choice' => 'communication preference',
                    'subject_name' => 'full name',
                    'subject_phone' => 'phone number',
                    'subject_email' => 'email address',
                    'message' => 'details',
                    'confirm' => 'deletion acknowledgement',
                ]
            );
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $old);

            return Response::redirect($returnTo);
        }

        try {
            $result = PrivacyRequestService::make($context)->submit([
                'request_type' => (string) $data['request_type'],
                'subject_name' => (string) $data['subject_name'],
                'subject_phone' => (string) $data['subject_phone'],
                'subject_email' => isset($data['subject_email']) && is_string($data['subject_email'])
                    ? $data['subject_email']
                    : null,
                'message' => isset($data['message']) && is_string($data['message']) && $data['message'] !== ''
                    ? $data['message']
                    : null,
            ], $context);
        } catch (\Throwable $exception) {
            // The database write failed (not a mail failure, which the service
            // already swallows): no request exists, so the person must be told
            // to retry through the documented contact channel.
            Logger::error('Privacy request could not be recorded.', [
                'event' => 'privacy.request',
                'exception' => $exception::class,
            ]);
            Session::flash('errors', [
                'request_type' => ['We could not record your request right now. Please try again, or email '
                    . $this->privacyEmail() . '.'],
            ]);
            Session::flash('old', $old);

            return Response::redirect($returnTo);
        }

        Session::flash('privacy_feedback', [
            'reference' => $result['reference'],
            'request_type' => $result['request_type'],
        ]);

        return Response::redirect($returnTo);
    }

    /**
     * @return array<string, mixed>
     */
    private function legalShellData(): array
    {
        return [
            'legalController' => (string) Config::get('privacy.controller', 'DashPASS'),
            'legalEntity' => (string) Config::get('privacy.legal_entity', 'DashPASS'),
            'legalAddress' => (string) Config::get('privacy.registered_address', 'Nairobi, Kenya'),
            'privacyEmail' => $this->privacyEmail(),
            'dpoEmail' => (string) Config::get('privacy.dpo_email', 'dpo@dashpass.africa'),
            'supportEmail' => (string) Config::get('app.support_email', 'hello@dashpass.africa'),
            'policyVersion' => (string) Config::get('privacy.version', '1.0'),
            'policyEffective' => (string) Config::get('privacy.effective_date', '13 November 2026'),
            'responseDays' => max(1, (int) Config::get('privacy.response_days', 30)),
            'supervisoryAuthority' => (string) Config::get(
                'privacy.supervisory_authority',
                'Office of the Data Protection Commissioner (ODPC), Kenya'
            ),
            'supervisoryUrl' => (string) Config::get('privacy.supervisory_url', 'https://www.odpc.go.ke'),
            'governingLaw' => (string) Config::get('privacy.governing_law', 'the Republic of Kenya'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function choiceShellData(): array
    {
        $errors = Session::pullFlash('errors', []);
        $old = Session::pullFlash('old', []);
        $feedback = Session::pullFlash('privacy_feedback', []);

        return [
            'errors' => is_array($errors) ? $errors : [],
            'old' => is_array($old) ? $old : [],
            'feedback' => is_array($feedback) ? $feedback : [],
            'requestTypes' => PrivacyRequestRepository::TYPES,
            'requestTypeLabels' => PrivacyRequestRepository::TYPE_LABELS,
        ];
    }

    private function privacyEmail(): string
    {
        return (string) Config::get('privacy.privacy_email', 'privacy@dashpass.africa');
    }

    private function returnPath(Request $request): string
    {
        $candidate = $request->string('return_to');

        return in_array($candidate, self::RETURN_PATHS, true) ? $candidate : '/privacy/choices';
    }
}
