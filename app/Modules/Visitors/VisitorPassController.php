<?php

declare(strict_types=1);

namespace App\Modules\Visitors;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Public visitor pass page: GET /v/{token} (contract §4.7).
 *
 * No authentication. The page shows only the estate name, visitor name,
 * household label, validity window, status and instructions — never the PIN,
 * the resident's phone/email/user id or any other visitor.
 */
final class VisitorPassController
{
    public function show(Request $request, array $params, Context $context): Response
    {
        $token = (string) ($params['token'] ?? '');
        $limit = max(1, (int) Config::get('visitors.pass.page_rate_limit', 60));
        $window = max(1, (int) Config::get('visitors.pass.page_rate_window', 300));
        $attempt = $context->rateLimiter->attempt('pass:page:ip:' . $request->ip(), $limit, $window);

        if (!$attempt['allowed']) {
            throw HttpException::tooManyRequests(
                'Too many pass page requests. Please wait a moment and try again.',
                $attempt['retry_after']
            );
        }

        $row = InvitationService::make($context)->findByPassToken($token);

        $base = [
            'title' => 'Visitor pass',
            'appVersion' => (string) Config::get('app.version', '0.1.0-foundation'),
            'isDev' => (string) Config::get('app.env', 'production') !== 'production',
            'csrfToken' => Csrf::token(),
        ];

        if ($row === null) {
            return View::render('public/pass', $base + ['pass' => null, 'invalid' => true], 'guest')
                ->withStatus(404)
                ->withHeader('Cache-Control', 'no-store');
        }

        $status = AccessPresenter::publicStatus($row);
        $timezone = (string) ($row['estate_timezone'] ?? 'Africa/Nairobi');

        $pass = [
            'estate_name' => (string) ($row['estate_name'] ?? ''),
            'visitor_name' => (string) ($row['visitor_name'] ?? ''),
            'household_label' => AccessPresenter::householdLabel($row),
            'valid_from' => AccessPresenter::iso(isset($row['valid_from']) ? (string) $row['valid_from'] : null),
            'valid_until' => AccessPresenter::iso(isset($row['valid_until']) ? (string) $row['valid_until'] : null),
            'valid_window' => $this->windowLabel($row, $timezone),
            'status' => $status,
            'status_label' => AccessPresenter::statusLabel($status),
            'status_tone' => AccessPresenter::statusTone($status),
            'instructions' => $this->instructions($status),
        ];

        return View::render('public/pass', $base + ['pass' => $pass, 'invalid' => false], 'guest')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function windowLabel(array $row, string $timezone): string
    {
        $from = AccessPresenter::date((string) ($row['valid_from'] ?? ''), $timezone);
        $to = AccessPresenter::date((string) ($row['valid_until'] ?? ''), $timezone);
        $start = AccessPresenter::time((string) ($row['valid_from'] ?? ''), $timezone);
        $end = AccessPresenter::time((string) ($row['valid_until'] ?? ''), $timezone);

        if ($from === '—' || $to === '—') {
            return '';
        }

        return $from === $to
            ? sprintf('%s, %s – %s', $from, $start, $end)
            : sprintf('%s %s – %s %s', $from, $start, $to, $end);
    }

    private function instructions(string $status): string
    {
        return match ($status) {
            'approved' => 'Show this pass at the estate gate. The guard will verify your access.',
            'checked_in' => 'You are currently checked in. Enjoy your visit.',
            'checked_out' => 'This visit has been checked out. Thank you for visiting.',
            'revoked' => 'This pass has been revoked. Please contact your host.',
            'expired' => 'This pass has expired. Please ask your host for a new invitation.',
            'denied' => 'This visit was not approved.',
            default => 'This pass is pending approval.',
        };
    }
}
