<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Session;
use DOMElement;

/**
 * Firebase phone-auth login error surfacing (URGENT support task, owner report:
 * "only Firebase test numbers can sign in; real numbers fail").
 *
 * Independent verification that a real-number user whose verification fails for
 * a browser-side (or Firebase-console) reason gets an actionable, cause-identifiable
 * message instead of a silent/generic failure. Static/source-level assertions, plus
 * rendered-login-page checks for the accessible error container, CSP and secret
 * hygiene. This file is verification only — it never edits product JS/controllers.
 *
 * Audit of the current tree (for the report):
 *   mapped in public/assets/js/firebase-auth.js describeError(): unauthorized-domain,
 *   missing-app-credential, invalid-app-credential, captcha-check-failed,
 *   quota-exceeded, too-many-requests, invalid-phone-number, operation-not-allowed,
 *   internal-error, code-expired, invalid-verification-code (all present);
 *   auth/billing-not-enabled was MISSING (generic "check your connection" fallback),
 *   which is the classic "test numbers work, real numbers fail" cause. The owner fix
 *   (task-8) is expected to add it; this test pins it.
 */
final class FirebaseLoginErrorTest extends VisitorModuleTestCase
{
    private const APP_ID = '1:99186909972:web:ad3900fddee22fe6b91801';
    private const MEASUREMENT_ID = 'G-D9JVCQJG9V';

    /**
     * Error codes that can reach a real-number user and must be surfaced with a
     * distinct, user-safe, cause-identifiable message.
     *
     * @var array<int, string>
     */
    private const CRITICAL_CODES = [
        'auth/billing-not-enabled',
        'auth/unauthorized-domain',
        'auth/missing-app-credential',
        'auth/invalid-app-credential',
        'auth/captcha-check-failed',
        'auth/quota-exceeded',
        'auth/too-many-requests',
        'auth/invalid-phone-number',
        'auth/operation-not-allowed',
        'auth/internal-error',
        'auth/code-expired',
        'auth/invalid-verification-code',
    ];

    /**
     * Per-code cause tokens: the mapped message must say something about the
     * actual cause so the user/owner can act on it (wording is the fix owner's
     * choice; at least one token must appear, case-insensitively).
     *
     * @var array<string, array<int, string>>
     */
    private const CAUSE_TOKENS = [
        'auth/billing-not-enabled' => ['billing', 'sms', 'quota', 'plan', 'payment', 'blaze'],
        'auth/unauthorized-domain' => ['domain', 'authoris', 'authoriz', 'address', 'origin'],
        'auth/missing-app-credential' => ['security check', 'captcha', 'recaptcha', 'refresh', 'config', 'browser'],
        'auth/invalid-app-credential' => ['config', 'firebase', 'api', 'project', 'key', 'app'],
        'auth/captcha-check-failed' => ['security check', 'captcha', 'recaptcha', 'refresh', 'config'],
        'auth/quota-exceeded' => ['quota', 'sms', 'limit', 'later', 'billing'],
        'auth/too-many-requests' => ['too many', 'wait', 'later', 'attempts', 'minute'],
        'auth/invalid-phone-number' => ['phone', 'number', 'check'],
        'auth/operation-not-allowed' => ['not enabled', 'enabled', 'sign-in', 'provider', 'firebase'],
        'auth/internal-error' => ['temporary', 'moment', 'try again', 'service', 'problem'],
        'auth/code-expired' => ['expired', 'resend', 'new code', 'code'],
        'auth/invalid-verification-code' => ['code', 'sms', 'correct', 'try again'],
    ];

    /** @var array<string, mixed> */
    private array $originalFirebase = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalFirebase = (array) Config::get('firebase', []);
    }

    public function tearDown(): void
    {
        Config::set('firebase', $this->originalFirebase);

        parent::tearDown();
    }

    private const PUBLIC_WEB_KEYS = [
        'apiKey' => 'test-public-api-key',
        'authDomain' => 'komiuta.firebaseapp.com',
        'projectId' => 'komiuta',
        'storageBucket' => 'komiuta.firebasestorage.app',
        'messagingSenderId' => '99186909972',
        'appId' => self::APP_ID,
        'measurementId' => self::MEASUREMENT_ID,
    ];

    private function enableFirebase(): void
    {
        Config::set('firebase', array_merge($this->originalFirebase, [
            'enabled' => true,
            'provider' => 'firebase',
            'project_id' => 'komiuta',
            'web' => self::PUBLIC_WEB_KEYS,
        ]));
    }

    private function firebaseJs(): string
    {
        $path = BASE_PATH . '/public/assets/js/firebase-auth.js';
        $this->assertTrue(is_file($path), 'public/assets/js/firebase-auth.js must exist.');
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source, 'firebase-auth.js must not be empty.');

        return $source;
    }

    private function loginPage(): \App\Core\Response
    {
        $this->enableFirebase();

        return $this->get('/login');
    }

    /**
     * Extract the `var map = {...}` / `const map = {...}` code => message object
     * from describeError(). Attribute/quote-order tolerant.
     *
     * @return array<string, string>
     */
    private function describeErrorMap(string $js): array
    {
        $start = strpos($js, 'function describeError');

        if ($start === false) {
            $this->fail('firebase-auth.js must keep a describeError() mapping function.');
        }

        $block = substr($js, $start);
        $mapStart = strpos($block, 'map = {');

        if ($mapStart === false) {
            $this->fail('firebase-auth.js describeError() must build a code => message map.');
        }

        $mapBody = substr($block, $mapStart);
        $end = strpos($mapBody, '};');

        if ($end === false) {
            $this->fail('firebase-auth.js describeError() map must be a closed object literal.');
        }

        $mapBody = substr($mapBody, 0, $end);
        $map = [];

        // Single-quoted keys/values (project style) …
        preg_match_all(
            "/'((?:auth\\/)[^']+)'\\s*:\\s*'((?:[^'\\\\]|\\\\.)*)'/",
            $mapBody,
            $single,
            PREG_SET_ORDER
        );

        foreach ($single as $match) {
            $map[(string) $match[1]] = (string) $match[2];
        }

        // … and double-quoted keys/values, tolerant of a refactor.
        preg_match_all(
            '/"((?:auth\/)[^"]+)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/',
            $mapBody,
            $double,
            PREG_SET_ORDER
        );

        foreach ($double as $match) {
            $map[(string) $match[1]] = (string) $match[2];
        }

        $this->assertNotEmpty($map, 'describeError() must map Firebase auth/* codes to messages.');

        return $map;
    }

    /* ------------------------------------------------------------------ */
    /* Rendered login page: accessible error surface                       */
    /* ------------------------------------------------------------------ */

    public function testLoginPageRendersAnAccessibleErrorContainer(): void
    {
        $response = $this->loginPage();
        $this->assertStatus(200, $response, 'GET /login must render for a guest.');
        $dom = $this->dom($response);

        $boxes = $this->xpath('//*[@data-auth-error]', $dom);
        $this->assertCount(1, $boxes, 'The login page must render exactly one [data-auth-error] container.');
        $box = $boxes->item(0);
        $this->assertInstanceOf(DOMElement::class, $box);

        // role="alert" is an assertive live region; accept an explicit aria-live too.
        $role = strtolower(trim($box->getAttribute('role')));
        $ariaLive = strtolower(trim($box->getAttribute('aria-live')));
        $this->assertTrue(
            $role === 'alert' || in_array($ariaLive, ['assertive', 'polite'], true),
            'The login error container must be a live region (role="alert" or aria-live).'
        );

        // Focusable: firebase-auth.js focuses it when a client-side error appears.
        $this->assertSame('-1', trim($box->getAttribute('tabindex')), 'The error container must be focusable (tabindex="-1").');

        // The JS writes the message into [data-auth-error-text] and extra lines into
        // the list; both hooks must exist even when the container is hidden.
        $this->assertCount(1, $this->xpath('.//*[@data-auth-error-text]', $box), 'The error container needs a [data-auth-error-text] message node.');
        $this->assertCount(1, $this->xpath('.//*[@data-auth-error-list]', $box), 'The error container needs a [data-auth-error-list] list node.');

        // Clean page: the container starts hidden (no stale error on first render).
        $this->assertTrue($box->hasAttribute('hidden'), 'The error container must start hidden when there is no error.');

        // Firebase status/resend announcements are polite live regions.
        $statusRegions = $this->xpath('//*[@data-firebase-status or @data-firebase-resend-status]', $dom);
        $this->assertTrue($statusRegions->length >= 1, 'The Firebase login flow must announce progress in a status region.');
        foreach ($statusRegions as $status) {
            if (!$status instanceof DOMElement) {
                continue;
            }
            $this->assertSame('status', strtolower(trim($status->getAttribute('role'))), 'Firebase progress must use role="status".');
            $this->assertSame('polite', strtolower(trim($status->getAttribute('aria-live'))), 'Firebase progress must be announced politely.');
        }
    }

    public function testServerErrorRendersEscapedInsideTheAlertContainer(): void
    {
        $this->enableFirebase();
        Session::flash('error', 'We could not verify that sign-in. <script>alert("x")</script>');

        $response = $this->get('/login');
        $this->assertStatus(200, $response);
        $dom = $this->dom($response);

        $boxes = $this->xpath('//*[@data-auth-error]', $dom);
        $this->assertCount(1, $boxes, 'The flash error must render in the error container.');
        $box = $boxes->item(0);
        $this->assertInstanceOf(DOMElement::class, $box);
        $this->assertFalse($box->hasAttribute('hidden'), 'A server-side login error must un-hide the error container.');

        $textNodes = $this->xpath('.//*[@data-auth-error-text]', $box);
        $textNode = $textNodes->item(0);
        $text = $textNode instanceof \DOMNode ? $this->text($textNode) : '';
        $this->assertStringContainsString('We could not verify that sign-in.', $text, 'The server error message must be shown to the user.');
        $this->assertStringNotContainsString('<script>', $response->body(), 'Server error text must be escaped, not injected as markup.');
        $this->assertStringContainsString('&lt;script&gt;', $response->body(), 'Server error text must be HTML-escaped.');
    }

    /* ------------------------------------------------------------------ */
    /* Browser-side error mapping                                          */
    /* ------------------------------------------------------------------ */

    public function testCriticalFirebaseCodesMapToDistinctActionableMessages(): void
    {
        $js = $this->firebaseJs();
        $map = $this->describeErrorMap($js);

        $missing = [];
        $notCauseIdentifiable = [];
        $messages = [];

        foreach (self::CRITICAL_CODES as $code) {
            if (!isset($map[$code])) {
                $missing[] = $code;
                continue;
            }

            $message = (string) $map[$code];
            $messages[$code] = $message;
            $this->assertTrue(trim($message) !== '', $code . ' must map to a non-empty user message.');
            $this->assertStringNotContainsString($code, $message, $code . ' must not show the raw provider code to the user.');

            $haystack = strtolower($message);
            $matched = false;

            foreach (self::CAUSE_TOKENS[$code] as $token) {
                if (str_contains($haystack, $token)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $notCauseIdentifiable[] = $code . ' => "' . $message . '"';
            }
        }

        $this->assertCount(
            0,
            $missing,
            'These Firebase error codes can reach a real-number user but are not mapped to a user message: '
                . implode(', ', $missing) . ' (they fall back to the generic "check your connection" copy).'
        );
        $this->assertCount(
            0,
            $notCauseIdentifiable,
            'Every critical Firebase error message must identify its cause so the user/owner can act on it: '
                . implode('; ', $notCauseIdentifiable)
        );

        // Distinct, cause-identifiable copy: no blanket single message for all codes.
        $this->assertTrue(
            count(array_unique($messages)) >= count(self::CRITICAL_CODES) - 2,
            'Critical Firebase errors must map to distinct messages (got duplicates): '
                . implode(' | ', array_keys(array_diff_assoc($messages, array_unique($messages))))
        );

        // No raw provider text, error objects or PII in user-facing mapped copy.
        foreach ($messages as $code => $message) {
            $this->assertDoesNotMatch('/Firebase:\s|auth\/|https?:\/\//i', $message, $code . ' must not leak raw provider text/URLs.');
            $this->assertDoesNotMatch('/\+?\d[\d\s().-]{7,}/', $message, $code . ' must not include phone numbers or numeric PII.');
            $this->assertStringNotContainsString('@', $message, $code . ' must not include email/identifier PII.');
            $this->assertDoesNotMatch(
                '/not registered|no account|unknown (?:user|number)|does not exist|unregistered|disabled account/i',
                $message,
                $code . ' must not enable account enumeration.'
            );
        }

        // The raw provider error object must never be written to the DOM: only
        // error.code (non-sensitive) may be surfaced, e.g. as a QA data attribute.
        $this->assertDoesNotMatch(
            '/(?:textContent|innerHTML|insertAdjacentHTML|alert)\s*[=(][^\n]*(?:error|err)\.message/i',
            $js,
            'firebase-auth.js must not display the raw provider error.message.'
        );
        $this->assertDoesNotMatch('/JSON\.stringify\((?:error|err)\)/', $js, 'firebase-auth.js must not render raw error objects.');
        $this->assertStringContainsString(
            'data-firebase-error-code',
            $js,
            'firebase-auth.js should expose only the Firebase error code as a non-sensitive QA hook.'
        );

        // Owner request (2026-11-13): user-facing error copy never shows a
        // "Reference: ..." line; the Firebase code stays in the non-visible QA
        // attribute only.
        $this->assertStringNotContainsString(
            'Reference:',
            $js,
            'Login error messages must not show a "Reference: ..." line.'
        );
        $this->assertStringNotContainsString('innerHTML', $js, 'firebase-auth.js must build error text with textContent only (no innerHTML).');
        $this->assertMatches('/errorText\.textContent = message;/', $js, 'The error message must be written with textContent.');

        // Console/config-level causes need human-action wording (contact admin /
        // check the Firebase config / refresh), not "check your connection".
        foreach (['auth/billing-not-enabled', 'auth/unauthorized-domain', 'auth/operation-not-allowed', 'auth/invalid-app-credential'] as $code) {
            $this->assertArrayHasKey($code, $messages, 'Console/config cause must be mapped: ' . $code);
            $this->assertDoesNotMatch(
                '/check your connection/i',
                $messages[$code],
                $code . ' must describe the real cause, not blame the user connection.'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Console causes: documented owner action                              */
    /* ------------------------------------------------------------------ */

    public function testDocumentedOwnerActionsExistForConsoleCauses(): void
    {
        $docs = (string) @file_get_contents(BASE_PATH . '/docs/firebase-sms-otp.md');
        $this->assertNotEmpty($docs, 'docs/firebase-sms-otp.md must exist (owner console runbook).');

        // The runbook keeps the console-level owner steps for the classic
        // "test numbers work, real numbers fail" causes.
        foreach ([
            'auth/operation-not-allowed' => 'Enable',
            'auth/unauthorized-domain' => 'Authorized domains',
            'auth/quota-exceeded' => 'Blaze',
            'auth/captcha-check-failed' => 'reCAPTCHA',
        ] as $code => $ownerAction) {
            $this->assertStringContainsString($code, $docs, 'The owner runbook must document ' . $code . '.');
            $this->assertStringContainsString($ownerAction, $docs, 'The owner runbook must keep the action for ' . $code . '.');
        }

        // Billing is the headline cause for real numbers after test numbers work.
        $this->assertMatches(
            '/billing|Blaze/i',
            $docs,
            'The owner runbook must document the billing/Blaze requirement for real SMS.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* CSP + reCAPTCHA origins                                             */
    /* ------------------------------------------------------------------ */

    public function testLoginPageCspAllowsRecaptchaAndFirebaseOrigins(): void
    {
        $response = $this->loginPage();
        $csp = (string) $response->header('Content-Security-Policy');
        $this->assertNotEmpty($csp, 'The login response must carry a Content-Security-Policy.');

        // Script origins the modular SDK + reCAPTCHA loader/Google API client need.
        $this->assertMatches('/script-src[^;]*https:\/\/www\.gstatic\.com/s', $csp, 'script-src must allow the gstatic Firebase SDK.');
        $this->assertMatches('/script-src[^;]*https:\/\/www\.google\.com\/recaptcha\//s', $csp, 'script-src must allow the reCAPTCHA loader.');
        $this->assertMatches('/script-src[^;]*https:\/\/apis\.google\.com/s', $csp, 'script-src must allow the Google API client used by Firebase Auth.');

        // Connect origins for token/sign-in exchanges.
        foreach (['https://identitytoolkit.googleapis.com', 'https://securetoken.googleapis.com', 'https://www.googleapis.com'] as $origin) {
            $this->assertStringContainsString($origin, $csp, 'connect-src must allow ' . $origin . ' for Firebase Auth.');
        }

        // reCAPTCHA challenge frames + the Firebase authDomain iframe.
        $this->assertMatches('/frame-src[^;]*https:\/\/www\.google\.com/s', $csp, 'frame-src must allow the reCAPTCHA challenge frames.');
        $this->assertMatches('/frame-src[^;]*https:\/\/recaptcha\.google\.com/s', $csp, 'frame-src must allow recaptcha.google.com.');
        $this->assertMatches('/frame-src[^;]*https:\/\/komiuta\.firebaseapp\.com/s', $csp, 'frame-src must allow the Firebase authDomain.');

        // Strict script policy must survive: no inline/eval scripts.
        $this->assertMatches('/script-src ([^;]+);/', $csp, 'The CSP must keep a script-src clause.');
        preg_match('/script-src ([^;]+);/', $csp, $scriptSrc);
        $scriptPolicy = strtolower((string) ($scriptSrc[1] ?? ''));
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptPolicy, 'script-src must never allow inline scripts.');
        $this->assertStringNotContainsString("'unsafe-eval'", $scriptPolicy, 'script-src must never allow eval.');
        $this->assertStringNotContainsString("'unsafe-eval'", strtolower($csp), 'The CSP must never allow eval.');

        // The login page loads the Firebase enhancer as an external module script.
        $body = $response->body();
        $this->assertStringContainsString('type="module"', $body, 'The login page must load firebase-auth.js as a module script.');
        $this->assertStringContainsString('/assets/js/firebase-auth.js', $body, 'The login page must load the Firebase enhancer from /assets.');
        $this->assertStringNotContainsString('https://www.gstatic.com/firebasejs/13.2.0/firebase-app.js', $body, 'The SDK must not be hard-coded into the page; the enhancer imports it.');
    }

    /* ------------------------------------------------------------------ */
    /* Secrets / config hygiene                                            */
    /* ------------------------------------------------------------------ */

    public function testLoginPageDoesNotLeakFirebaseSecretsOrRawErrors(): void
    {
        $response = $this->loginPage();
        $body = $response->body();
        $dom = $this->dom($response);

        // Only the documented PUBLIC web client config may be rendered.
        $nodes = $this->xpath('//*[@data-firebase-auth]', $dom);
        $this->assertCount(1, $nodes, 'The Firebase-enabled login page must render the [data-firebase-auth] config node.');
        $node = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);
        $this->assertSame('true', trim($node->getAttribute('data-enabled')));
        $this->assertSame('firebase', trim($node->getAttribute('data-provider')));
        $this->assertTrue($node->hasAttribute('hidden'), 'The Firebase config node must stay hidden.');

        foreach (self::PUBLIC_WEB_KEYS as $key => $expected) {
            $attribute = 'data-' . strtolower((string) preg_replace('/([A-Z])/', '-$1', $key));
            $this->assertSame($expected, trim($node->getAttribute($attribute)), 'The login page must expose the public Firebase web config ' . $key . '.');
        }

        // No server-side secret material in the rendered page (or its attributes).
        foreach ([
            'BEGIN PRIVATE KEY',
            'PRIVATE KEY',
            'private_key',
            'client_email',
            'service_account',
            'FIREBASE_PRIVATE_KEY',
            'FIREBASE_SERVICE_ACCOUNT',
            'FIREBASE_CLIENT_EMAIL',
            'RECAPTCHA_SECRET',
            'FCM_SERVER_KEY',
            'refresh_token',
            'admin_sdk',
        ] as $marker) {
            $this->assertStringNotContainsString($marker, $body, 'The login page must never render secret material: ' . $marker);
        }

        // No propagated Firebase ID token / JWT in the initial markup.
        $this->assertDoesNotMatch('/eyJ[A-Za-z0-9_-]{10,}\./', $body, 'The login page must not contain a Firebase ID token/JWT.');

        // Config-node attribute names must never look secret-shaped.
        foreach ($node->attributes as $attribute) {
            $this->assertDoesNotMatch(
                '/(?:private|secret|server[-_]?key|service[-_]?account|client[-_]?email|refresh[-_]?token)/i',
                $attribute->name,
                'The Firebase config node must not expose a secret-shaped data attribute: ' . $attribute->name
            );
        }

        // The controller allowlists the public web keys (defence in depth).
        $controller = (string) @file_get_contents(BASE_PATH . '/app/Modules/Authentication/AuthController.php');
        $this->assertNotEmpty($controller, 'AuthController must be readable for the allowlist check.');
        $this->assertMatches(
            '/foreach \(\[.apiKey., .authDomain., .projectId., .storageBucket., .messagingSenderId., .appId., .measurementId.\] as \$key\)/s',
            $controller,
            'AuthController::firebaseClientConfig() must expose only the public web keys.'
        );
        $this->assertDoesNotMatch(
            '/[\'"]web[\'"]\s*=>\s*\$this->firebaseConfig\(\)|firebase\.web.*private_key/s',
            $controller,
            'AuthController must not pass raw firebase config/env into the view.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Non-production gating of test shortcuts / dev fallbacks              */
    /* ------------------------------------------------------------------ */

    public function testNoHardCodedTestNumberBypassOrUngatedDevFallback(): void
    {
        $js = $this->firebaseJs();

        // Firebase's documented test numbers must never be hard-coded as a client
        // sign-in allowlist in our own JS (the platform lets you register test
        // numbers in the console instead; a code allowlist would be a real bypass).
        $this->assertDoesNotMatch(
            '/(?:\+?1[ .-]?650[ .-]?555|7444[ .-]?555|555[ .-]?1234|[\'"]\+?[0-9]{10,15}[\'"]\s*,)/',
            $js,
            'firebase-auth.js must not hard-code Firebase test phone numbers/allowlists.'
        );

        // If a future fix adds any test-number/dev bypass identifier, it must be
        // gated on a non-production signal; today none exists, so assert absence.
        if (preg_match('/\b(?:testNumbers?|testPhoneNumbers?|allowlist|devBypass|sandboxMode|devMode)\b/', $js) === 1) {
            $this->assertMatches(
                '/(?:data-is-dev|isDev|APP_ENV|app\.env|production|data-env|NODE_ENV)/',
                $js,
                'A test-number/dev bypass in firebase-auth.js must be gated to non-production.'
            );
        }

        // The real-number-only failure the owner reported (test numbers skip app
        // verification, real numbers do not) is fixed by clearing the stale reCAPTCHA
        // widget before re-rendering the verifier; pin that regression fix.
        $this->assertMatches(
            '/function resetVerifier\(\)[\s\S]{0,1600}while \(stale\.firstChild\)[\s\S]{0,300}removeChild/s',
            $js,
            'resetVerifier() must clear the rendered reCAPTCHA widget before the real-number retry re-renders it.'
        );

        // The client must never invent a token: it comes only from the verified
        // Firebase credential, and is posted in the hidden form for server checks.
        $this->assertStringContainsString('getIdToken(true)', $js, 'The Firebase flow must use the verified credential ID token.');

        // Every write to the hidden token field must be either the verified ID
        // token variable or an explicit clear — never a fabricated value.
        preg_match_all('/tokenInput\.value = ([^;]+);/', $js, $tokenWrites);
        $this->assertNotEmpty($tokenWrites[1], 'The Firebase flow must write the verified token into the hidden form field.');
        foreach ($tokenWrites[1] as $written) {
            $written = trim((string) $written);
            $this->assertTrue(
                in_array($written, ['token', "''", '""', 'tokenInput.defaultValue'], true),
                'firebase-auth.js must never fabricate a Firebase token client-side (got: ' . $written . ').'
            );
        }
        $this->assertDoesNotMatch(
            '/tokenInput\.value = (?:atob|btoa|Math\.random|Date\.now|location|crypto\.)/',
            $js,
            'firebase-auth.js must never fabricate a Firebase token client-side.'
        );

        // Server-side dev fallback (server OTP) is production-gated in code.
        $controller = (string) @file_get_contents(BASE_PATH . '/app/Modules/Authentication/AuthController.php');
        $this->assertMatches(
            '/function serverOtpFallbackAllowed\(\): bool\s*\{\s*if \(\(string\) Config::get\(\'app\.env\', \'production\'\) === \'production\'\)\s*\{\s*return false;\s*\}.*?Env::bool\(\'FIREBASE_ALLOW_SERVER_OTP_FALLBACK\', false\)/s',
            $controller,
            'The FIREBASE_ALLOW_SERVER_OTP_FALLBACK escape hatch must be forced off in production.'
        );

        // The dev OTP panel is rendered only for a non-production dev environment
        // and never alongside the Firebase UI.
        $loginView = (string) @file_get_contents(BASE_PATH . '/app/Views/auth/login.php');
        $this->assertNotEmpty($loginView, 'The login view must be readable.');
        $this->assertMatches(
            '/\$isDev && !\$useFirebase/',
            $loginView,
            'Dev-only OTP hints must be gated by $isDev and disabled when Firebase is active.'
        );
        $this->assertMatches(
            '/[\'"]isDev[\'"]\s*=>\s*\(string\)\s*Config::get\(\s*[\'"]app\.env[\'"]\s*,\s*[\'"]production[\'"]\s*\)\s*!==\s*[\'"]production[\'"]/s',
            (string) file_get_contents(BASE_PATH . '/app/Modules/Authentication/AuthController.php'),
            'The dev flag passed to the login view must mean "not production".'
        );

        // The Firebase client config is only used when enabled + provider=firebase.
        $this->assertMatches(
            '/if \(!cfg\.apiKey \|\| !cfg\.authDomain \|\| !cfg\.projectId \|\| !cfg\.appId\)/s',
            $js,
            'firebase-auth.js must bail out (no SDK calls) when the public config is incomplete.'
        );
    }
}
