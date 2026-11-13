<?php

declare(strict_types=1);

namespace App\Modules\Authentication;

use App\Core\Config;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Phone;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Modules\Estates\EstateRepository;
use App\Modules\Platform\PlatformAccess;

/**
 * Web (HTML) and JSON authentication endpoints.
 *
 * Identity and authority are always resolved through App\Core\Context; this
 * controller never reads a user id, estate id or role from request input.
 * Plaintext OTPs are never logged or audited.
 */
final class AuthController
{
    /* ------------------------------------------------------------------ */
    /* Web                                                                */
    /* ------------------------------------------------------------------ */

    public function webWelcome(Request $request, array $params, Context $context): Response
    {
        if ($context->auth->check()) {
            // T152: platform-only operators must land on the platform console, not
            // the resident no-estate page.
            return Response::redirect($this->postLoginRedirect($context, null));
        }

        return View::render('auth/landing', [
            'title' => 'Welcome',
            'csrfToken' => Csrf::token(),
            'appVersion' => (string) Config::get('app.version', '0.1.0-foundation'),
            'isDev' => (string) Config::get('app.env', 'production') !== 'production',
            'guestNav' => true,
            'landingMode' => true,
        ], 'guest');
    }

    public function webLogin(Request $request, array $params, Context $context): Response
    {
        $loginPhone = Session::get('login_phone');
        $errors = Session::pullFlash('errors', []);

        return View::render('auth/login', [
            'title' => 'Sign in',
            'step' => is_string($loginPhone) && $loginPhone !== '' ? 'verify' : 'phone',
            'phone' => is_string($loginPhone) ? $loginPhone : '',
            'devOtp' => Session::pullFlash('dev_otp'),
            'devNote' => Session::pullFlash('dev_note'),
            'error' => Session::pullFlash('error'),
            'errors' => is_array($errors) ? $errors : [],
            'firebase' => $this->firebaseClientConfig(),
            'csrfToken' => Csrf::token(),
            'appVersion' => (string) Config::get('app.version', '0.1.0-foundation'),
            'isDev' => (string) Config::get('app.env', 'production') !== 'production',
        ], 'guest');
    }

    /**
     * Pre-flight registration check for the Firebase login UI.
     *
     * The browser calls this BEFORE it asks Firebase to send an SMS OTP: only
     * numbers registered on the platform (an active user with at least one
     * active estate membership) may trigger an OTP, so unregistered numbers no
     * longer waste Firebase SMS quota. Returns a small JSON document only, and
     * is throttled + CSRF-protected like the other login POSTs.
     */
    public function webCheckPhone(Request $request, array $params, Context $context): Response
    {
        $raw = trim((string) ($request->string('phone') ?? ''));

        try {
            $phone = Phone::normalize($raw);
        } catch (\InvalidArgumentException) {
            return Response::json(['ok' => false, 'registered' => false, 'error' => 'invalid_phone'], 422);
        }

        $row = $context->db->selectOne(
            "SELECT u.id
             FROM users u
             INNER JOIN estate_memberships em ON em.user_id = u.id AND em.status = 'active'
             WHERE u.phone = :phone AND u.status = 'active'
             LIMIT 1",
            ['phone' => $phone]
        );

        return Response::json(['ok' => true, 'registered' => $row !== null]);
    }

    public function webRequestOtp(Request $request, array $params, Context $context): Response
    {
        // Increment 12 provider policy: when Firebase Phone Auth is the configured
        // provider there is no server-generated OTP challenge path. Production is
        // always strict; a non-production fallback exists only behind the explicit
        // FIREBASE_ALLOW_SERVER_OTP_FALLBACK=true env flag (never in production).
        if (!$this->serverOtpAllowed()) {
            $this->auditProviderMismatch($context, 'web_request_otp');
            Session::flash('error', 'Code sign-in is not available. Verify your phone number to continue.');

            return Response::redirect('/login');
        }

        try {
            $data = Validator::validate(
                $request->all(),
                ['phone' => 'required|phone'],
                ['phone' => 'phone number']
            );
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('error', 'Enter a valid phone number, for example 0712 345 678.');

            return Response::redirect('/login');
        }

        try {
            $result = $this->otpService()->request(
                (string) $data['phone'],
                'login',
                $request->ip(),
                $request->userAgent()
            );
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());

            return Response::redirect('/login');
        }

        Session::put('login_phone', (string) $data['phone']);

        if (isset($result['dev_otp'])) {
            Session::flash('dev_otp', (string) $result['dev_otp']);
        }

        if (isset($result['dev_note'])) {
            Session::flash('dev_note', (string) $result['dev_note']);
        }

        return Response::redirect('/login');
    }

    public function webVerifyOtp(Request $request, array $params, Context $context): Response
    {
        // Increment 12: owner-configured provider is Firebase Phone Auth by
        // default. POST /login/verify then accepts a Firebase ID token instead of
        // a server OTP (route count unchanged). No server-OTP login may complete
        // when the provider is Firebase, except behind the explicit non-production
        // FIREBASE_ALLOW_SERVER_OTP_FALLBACK=true tooling flag.
        if ($this->firebaseProvider() === 'firebase') {
            $submittedToken = $request->string('firebase_token');

            if ($submittedToken !== '' || !$this->serverOtpFallbackAllowed()) {
                return $this->webVerifyFirebase($request, $context);
            }
        }

        $phone = (string) (Session::get('login_phone') ?? '');
        $entered = $request->string('code');

        try {
            $data = Validator::validate(
                ['phone' => $phone, 'code' => $entered],
                ['phone' => 'required|phone', 'code' => 'required|digits:' . $this->otpLength()],
                ['phone' => 'phone number', 'code' => 'verification code']
            );
        } catch (ValidationException) {
            Session::flash('error', 'Enter the ' . $this->otpLength() . '-digit verification code.');

            return Response::redirect('/login');
        }

        try {
            $userId = $this->otpService()->verify((string) $data['phone'], (string) $data['code'], 'login', $request->ip());
        } catch (HttpException $exception) {
            $context->audit->record('auth.login_failed', 'user', null, [
                'phone' => Phone::mask((string) $data['phone']),
                'reason' => $exception->errorCode(),
            ]);
            Session::flash('error', $exception->publicMessage());

            return Response::redirect('/login');
        }

        Session::forget('login_phone');
        $context->auth->login($userId);
        $context->audit->record('auth.login', 'user', $userId);

        $intended = Session::pull('intended_url');

        return Response::redirect($this->postLoginRedirect($context, is_string($intended) ? $intended : null));
    }

    public function webLogout(Request $request, array $params, Context $context): Response
    {
        $actorId = $context->auth->id();
        $context->audit->record('auth.logout', 'user', $actorId);
        $context->auth->logout();

        return Response::redirect('/login');
    }

    public function webNoEstate(Request $request, array $params, Context $context): Response
    {
        $platformAccess = PlatformAccess::userHasPlatformAccess($context);

        return View::render('app/no-estate', [
            'title' => 'No estate access',
            'csrfToken' => Csrf::token(),
            'appVersion' => (string) Config::get('app.version', '0.1.0-foundation'),
            'isDev' => (string) Config::get('app.env', 'production') !== 'production',
            // T152/T153: platform-only operators get a "Platform console" CTA on this
            // page instead of being asked to join an estate.
            'platform_access' => $platformAccess,
            'platform_permissions' => PlatformAccess::platformPermissionsFor($context),
            'platform_roles' => PlatformAccess::make($context)->roleNames(),
            'user' => ['platform_access' => $platformAccess],
        ], 'guest');
    }

    /* ------------------------------------------------------------------ */
    /* API                                                                */
    /* ------------------------------------------------------------------ */

    public function apiCsrf(Request $request, array $params, Context $context): Response
    {
        return Response::success('CSRF token issued.', [
            'csrf_token' => Csrf::token(),
        ]);
    }

    public function apiRequestOtp(Request $request, array $params, Context $context): Response
    {
        // Same provider policy as the web entry point: no server OTP challenge
        // when Firebase is the configured provider (unless explicitly allowed in
        // non-production tooling via FIREBASE_ALLOW_SERVER_OTP_FALLBACK=true).
        if (!$this->serverOtpAllowed()) {
            $this->auditProviderMismatch($context, 'api_request_otp');

            throw new HttpException(
                403,
                'AUTH_PROVIDER_MISMATCH',
                'Code sign-in is not available. Verify your phone number to continue.'
            );
        }

        $data = Validator::validate(
            $request->all(),
            ['phone' => 'required|phone'],
            ['phone' => 'phone number']
        );

        $result = $this->otpService()->request(
            (string) $data['phone'],
            'login',
            $request->ip(),
            $request->userAgent()
        );

        return Response::success(
            'If the number is registered, a verification code has been sent.',
            $result
        );
    }

    public function apiVerifyOtp(Request $request, array $params, Context $context): Response
    {
        // Security: never complete the legacy server-OTP login when the configured
        // provider is Firebase. Rejected generically (no user enumeration) and
        // audited before any OTP validation/rate-limit work.
        if (!$this->serverOtpAllowed()) {
            $this->auditProviderMismatch($context, 'api_verify_otp');

            throw new HttpException(
                403,
                'AUTH_PROVIDER_MISMATCH',
                'Code sign-in is not available. Verify your phone number to continue.'
            );
        }

        $data = Validator::validate(
            $request->all(),
            [
                'phone' => 'required|phone',
                'code' => 'required|digits:' . $this->otpLength(),
            ],
            ['phone' => 'phone number', 'code' => 'verification code']
        );

        try {
            $userId = $this->otpService()->verify(
                (string) $data['phone'],
                (string) $data['code'],
                'login',
                $request->ip()
            );
        } catch (HttpException $exception) {
            $context->audit->record('auth.login_failed', 'user', null, [
                'phone' => Phone::mask((string) $data['phone']),
                'reason' => $exception->errorCode(),
            ]);

            throw $exception;
        }

        $context->auth->login($userId);
        $context->audit->record('auth.login', 'user', $userId);

        $payload = $this->meData($context, $context->auth->requireUser());
        $payload['redirect'] = '/app';

        return Response::success('Signed in successfully.', $payload);
    }

    public function apiLogout(Request $request, array $params, Context $context): Response
    {
        $actorId = $context->auth->id();
        $context->audit->record('auth.logout', 'user', $actorId);
        $context->auth->logout();

        return Response::noContent();
    }

    public function apiMe(Request $request, array $params, Context $context): Response
    {
        $user = $context->auth->requireUser();

        return Response::success('Authenticated user loaded.', $this->meData($context, $user));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Firebase Phone Auth browser path for POST /login/verify (Increment 12).
     *
     * The token is verified server-side (no service account); the phone is then
     * resolved to an **active** user and the normal session/CSRF rotation/landing
     * runs. Every failure is generic and audited without token/phone content.
     */
    private function webVerifyFirebase(Request $request, Context $context): Response
    {
        $token = $request->string('firebase_token');

        if ($token === '' || mb_strlen($token) > 4096) {
            Session::flash('errors', ['firebase_token' => ['Verify your phone number to continue.']]);

            return Response::redirect('/login');
        }

        $ipLimits = (array) Config::get('firebase.rate_limits.ip', ['max' => 20, 'window' => 900]);
        $ipAttempt = $context->rateLimiter->attempt(
            'firebase:verify:ip:' . $request->ip(),
            max(1, (int) ($ipLimits['max'] ?? 20)),
            max(1, (int) ($ipLimits['window'] ?? 900))
        );

        if (!$ipAttempt['allowed']) {
            $this->auditFirebaseFailure($context, 'RATE_LIMITED');
            Session::flash('error', 'Too many sign-in attempts. Please wait and try again.');

            return Response::redirect('/login');
        }

        try {
            $verified = FirebaseTokenVerifier::make()->verify($token);
        } catch (HttpException $exception) {
            $this->auditFirebaseFailure($context, $exception->errorCode());
            Session::flash('error', $exception->publicMessage());

            return Response::redirect('/login');
        }

        $phone = (string) $verified['phone'];
        $phoneLimits = (array) Config::get('firebase.rate_limits.phone', ['max' => 10, 'window' => 900]);
        $phoneAttempt = $context->rateLimiter->attempt(
            'firebase:verify:phone:' . hash('sha256', $phone),
            max(1, (int) ($phoneLimits['max'] ?? 10)),
            max(1, (int) ($phoneLimits['window'] ?? 900))
        );

        if (!$phoneAttempt['allowed']) {
            $this->auditFirebaseFailure($context, 'RATE_LIMITED');
            Session::flash('error', 'Too many sign-in attempts. Please wait and try again.');

            return Response::redirect('/login');
        }

        $user = (new UserRepository($context->db))->findActiveByPhone($phone);

        if ($user === null) {
            // Unknown or disabled accounts get the same generic failure.
            $this->auditFirebaseFailure($context, 'USER_NOT_FOUND');
            Session::flash('error', 'We could not sign you in. Check that this number is registered, then try again.');

            return Response::redirect('/login');
        }

        Session::forget('login_phone');
        $userId = (int) $user['id'];
        $context->auth->login($userId);
        $context->audit->record('auth.firebase_login', 'user', $userId, [
            'provider' => 'firebase',
            'method' => 'phone',
        ]);

        $intended = Session::pull('intended_url');

        return Response::redirect($this->postLoginRedirect($context, is_string($intended) ? $intended : null));
    }

    /**
     * Post-login landing target (T152).
     *
     * - An explicit, safe intended URL always wins (deep links).
     * - A user with **no active estate** but with platform access (active platform
     *   membership + platform capability) lands on the platform dashboard instead
     *   of the resident "/app/no-estate" page.
     * - Estate-less non-platform users keep the normal `/app` flow (EnsureEstate
     *   redirects them to `/app/no-estate`); estate members land on `/app`.
     */
    private function postLoginRedirect(Context $context, ?string $intended): string
    {
        if ($intended !== null && $intended !== '') {
            return self::safeRedirect($intended);
        }

        if ($context->tenant->active() === null && PlatformAccess::userHasPlatformAccess($context)) {
            return '/platform';
        }

        return '/app';
    }

    private function auditFirebaseFailure(Context $context, string $reason): void
    {
        $context->audit->record('auth.login_failed', 'user', null, [
            'provider' => 'firebase',
            'reason' => $reason,
        ]);
    }

    /**
     * Server-generated OTP entry points are available when the configured provider
     * is `server`, or only through the explicitly documented non-production tooling
     * flag. Production can never enable the fallback.
     */
    private function serverOtpAllowed(): bool
    {
        return $this->firebaseProvider() === 'server' || $this->serverOtpFallbackAllowed();
    }

    private function serverOtpFallbackAllowed(): bool
    {
        if ((string) Config::get('app.env', 'production') === 'production') {
            return false;
        }

        return Env::bool('FIREBASE_ALLOW_SERVER_OTP_FALLBACK', false);
    }

    private function auditProviderMismatch(Context $context, string $endpoint): void
    {
        // Generic, PII-free audit for attempts to use the disabled server-OTP path.
        $context->audit->record('auth.login_failed', 'user', null, [
            'provider' => $this->firebaseProvider(),
            'reason' => 'PROVIDER_MISMATCH',
            'endpoint' => $endpoint,
        ]);
    }

    /**
     * @return array{enabled: bool, provider: string, web: array<string, string>}
     */
    private function firebaseClientConfig(): array
    {
        $web = (array) Config::get('firebase.web', []);
        $public = [];

        foreach (['apiKey', 'authDomain', 'projectId', 'storageBucket', 'messagingSenderId', 'appId', 'measurementId'] as $key) {
            $value = $web[$key] ?? null;
            $public[$key] = is_scalar($value) ? (string) $value : '';
        }

        return [
            'enabled' => (bool) Config::get('firebase.enabled', false),
            'provider' => $this->firebaseProvider(),
            'web' => $public,
        ];
    }

    private function firebaseProvider(): string
    {
        return (string) Config::get('firebase.provider', 'server') === 'firebase' ? 'firebase' : 'server';
    }

    private function otpService(): OtpService
    {
        try {
            return OtpService::make();
        } catch (HttpException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            Logger::error('OTP service unavailable', ['error' => $exception->getMessage()]);

            throw new HttpException(
                503,
                'SERVICE_UNAVAILABLE',
                'Sign-in is temporarily unavailable. Please try again shortly.'
            );
        }
    }

    /**
     * `/auth/me` payload (contract §8.1).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function meData(Context $context, array $user): array
    {
        $memberships = $context->tenant->memberships();
        $active = $context->tenant->active();
        $activeEstateId = $active === null ? null : (int) $active['estate_id'];

        $shaped = array_map(
            static fn (array $membership): array => EstateRepository::membershipShape($membership, $activeEstateId),
            $memberships
        );

        return [
            'user' => [
                'uuid' => (string) $user['uuid'],
                'full_name' => (string) $user['full_name'],
                'phone' => (string) $user['phone'],
                'email' => $user['email'] === null ? null : (string) $user['email'],
            ],
            'memberships' => $shaped,
            'active_estate' => $active === null ? null : EstateRepository::activeEstateShape($active),
            'permissions' => $context->permission->slugs(),
            'csrf_token' => Csrf::token(),
        ];
    }

    private function otpLength(): int
    {
        return max(4, (int) Config::get('security.otp.length', 6));
    }

    /**
     * Only allow a same-site absolute path as a post-login redirect target.
     */
    private static function safeRedirect(?string $candidate): string
    {
        if ($candidate === null || $candidate === '') {
            return '/app';
        }

        if (!str_starts_with($candidate, '/')) {
            return '/app';
        }

        if (str_starts_with($candidate, '//') || str_starts_with($candidate, '/\\')) {
            return '/app';
        }

        return $candidate;
    }
}
