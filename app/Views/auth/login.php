<?php

declare(strict_types=1);

/**
 * R02 — Authentication: phone step + one-time-code step (guest layout).
 *
 * Variables (contract §8.2):
 *   $step ('phone'|'verify'), $phone, $devOtp, $devNote, $error, $errors,
 *   $csrfToken, $appVersion, $isDev, $firebase {enabled, provider, web{}}
 *
 * Both steps are rendered so JavaScript can move between them without a
 * network round trip; the plain server-rendered POST flow works without JS.
 *
 * Increment 12: Android/iPhone default OTP is Firebase Phone Auth. When
 * `$firebase.enabled` and `provider=firebase`, the phone step is enhanced by
 * the CSP-safe modular `/assets/js/firebase-auth.js` script (Firebase SDK
 * 13.2.0 from gstatic, public web config only in data-* attributes, invisible
 * reCAPTCHA, no inline scripts). The final step still posts to the existing
 * `POST /login/verify` with a hidden `firebase_token`. With `provider=server`
 * the original server-OTP UI renders exactly as before.
 */

$step = isset($step) && $step === 'verify' ? 'verify' : 'phone';
$isDev = (bool) ($isDev ?? false);
$phoneRaw = isset($phone) && is_string($phone) ? trim($phone) : '';
$devOtp = isset($devOtp) && is_string($devOtp) ? trim($devOtp) : '';
$devNote = isset($devNote) && is_string($devNote) ? trim($devNote) : '';
$error = isset($error) && is_string($error) ? trim($error) : '';
$errors = isset($errors) && is_array($errors) ? $errors : [];
$csrfToken = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$otpLength = max(4, (int) config('security.otp.length', 6));

// Firebase Phone Auth (Increment 12). Only PUBLIC web client config is rendered;
// server token verification is bound to config('firebase.provider'), never to
// this UI flag.
$firebase = isset($firebase) && is_array($firebase) ? $firebase : [];
$firebaseWeb = isset($firebase['web']) && is_array($firebase['web']) ? $firebase['web'] : [];
$firebaseProvider = strtolower(trim((string) ($firebase['provider'] ?? 'server')));
$firebaseWebValue = static function (string $key) use ($firebaseWeb): string {
    $value = $firebaseWeb[$key] ?? null;

    return is_scalar($value) ? trim((string) $value) : '';
};
$firebaseConfig = [
    'apiKey' => $firebaseWebValue('apiKey'),
    'authDomain' => $firebaseWebValue('authDomain'),
    'projectId' => $firebaseWebValue('projectId'),
    'storageBucket' => $firebaseWebValue('storageBucket'),
    'messagingSenderId' => $firebaseWebValue('messagingSenderId'),
    'appId' => $firebaseWebValue('appId'),
    'measurementId' => $firebaseWebValue('measurementId'),
];
$useFirebase = !empty($firebase['enabled'])
    && $firebaseProvider === 'firebase'
    && $firebaseConfig['apiKey'] !== ''
    && $firebaseConfig['authDomain'] !== ''
    && $firebaseConfig['projectId'] !== ''
    && $firebaseConfig['appId'] !== '';
$assetVersion = rawurlencode((string) ($appVersion ?? config('app.version', '0')));
$recaptchaContainerId = 'firebase-recaptcha';

$errorLines = [];

if ($error !== '') {
    $errorLines[] = $error;
}

foreach ($errors as $value) {
    if (is_array($value)) {
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $errorLines[] = trim((string) $item);
            }
        }
    } elseif (is_scalar($value) && trim((string) $value) !== '') {
        $errorLines[] = trim((string) $value);
    }
}

$errorLines = array_values(array_unique($errorLines));
$firstError = $errorLines[0] ?? '';
$extraErrors = array_slice($errorLines, 1, 4);

$phoneDisplay = $phoneRaw;
if ($phoneRaw !== '') {
    try {
        $phoneDisplay = \App\Core\Phone::display(\App\Core\Phone::normalize($phoneRaw));
    } catch (\Throwable) {
        $phoneDisplay = $phoneRaw;
    }
}

// The input shows the national part; the static +254 prefix carries the code.
$phoneValue = $phoneRaw;
if (str_starts_with($phoneValue, '+254')) {
    $phoneValue = substr($phoneValue, 4);
} elseif (str_starts_with($phoneValue, '254') && strlen($phoneValue) === 12) {
    $phoneValue = substr($phoneValue, 3);
}

$icon = static fn (string $name, string $extra = 'ico'): string => \App\Core\View::capture(
    'partials/icon',
    ['name' => $name, 'class' => $extra]
);
$formToken = $csrfToken !== '' ? $csrfToken : csrf_token();
?>
<div class="auth-wrap">
  <div class="auth-head">
    <div class="auth-head__top">
      <div class="stepper" aria-label="Sign-in progress">
        <span class="stepper__item <?= $step === 'phone' ? 'stepper__item--active' : 'stepper__item--done' ?>" <?= $step === 'phone' ? 'aria-current="step"' : '' ?>>
          <span class="stepper__dot">1</span>
          Phone
        </span>
        <span class="stepper__line" aria-hidden="true"></span>
        <span class="stepper__item <?= $step === 'verify' ? 'stepper__item--active' : '' ?>" <?= $step === 'verify' ? 'aria-current="step"' : '' ?>>
          <span class="stepper__dot">2</span>
          Code
        </span>
      </div>
      <?= \App\Core\View::capture('partials/theme-toggle') ?>
    </div>

    <h1 data-auth-title><?= $step === 'verify' ? 'Enter your code' : 'Sign in' ?></h1>
    <p data-auth-lede>
      <?php if ($step === 'verify'): ?>
        We sent a <?= e((string) $otpLength) ?>-digit one-time code to
        <strong><?= e($phoneDisplay !== '' ? $phoneDisplay : 'your phone') ?></strong>.
        It expires in a few minutes.
      <?php else: ?>
        Enter the phone number your estate registered for you. We will send a
        one-time code — no password needed.
      <?php endif; ?>
    </p>
  </div>

  <div class="card auth-card" data-auth>
    <?php if ($useFirebase): ?>
      <div hidden
           data-firebase-auth
           data-enabled="true"
           data-provider="firebase"
           data-api-key="<?= e($firebaseConfig['apiKey']) ?>"
           data-auth-domain="<?= e($firebaseConfig['authDomain']) ?>"
           data-project-id="<?= e($firebaseConfig['projectId']) ?>"
           data-storage-bucket="<?= e($firebaseConfig['storageBucket']) ?>"
           data-messaging-sender-id="<?= e($firebaseConfig['messagingSenderId']) ?>"
           data-app-id="<?= e($firebaseConfig['appId']) ?>"
           data-measurement-id="<?= e($firebaseConfig['measurementId']) ?>"
           data-recaptcha-container="<?= e($recaptchaContainerId) ?>"
           data-verify-action="/login/verify"
           data-otp-length="<?= e((string) $otpLength) ?>"
           data-default-country="+254"
           data-sdk-version="13.2.0"></div>
    <?php endif; ?>

    <div class="auth-error" data-auth-error tabindex="-1" role="alert" <?= $firstError === '' ? 'hidden' : '' ?>>
      <span class="auth-error__icon" aria-hidden="true"><?= $icon('alert') ?></span>
      <div class="auth-error__body">
        <p class="auth-error__title" data-auth-error-text><?= e($firstError !== '' ? $firstError : 'Please check your details and try again.') ?></p>
        <ul class="auth-error__list" data-auth-error-list>
          <?php foreach ($extraErrors as $line): ?>
            <li><?= e($line) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <?php if ($useFirebase): ?>
      <div id="<?= e($recaptchaContainerId) ?>" data-firebase-recaptcha aria-hidden="true"></div>
    <?php endif; ?>

    <div data-auth-panel="phone" <?= $step === 'phone' ? '' : 'hidden' ?>>
      <form method="post" action="<?= e('/login/otp') ?>" data-login-form="phone"<?= $useFirebase ? ' data-firebase-phone-form' : '' ?> class="stack">
        <input type="hidden" name="_token" value="<?= e($formToken) ?>">

        <div class="field">
          <label class="field__label" for="phone">
            Phone number
            <span class="muted text-xs">Required</span>
          </label>
          <div class="input-group">
            <span class="input-group__prefix" aria-hidden="true">+254</span>
            <input class="input" id="phone" name="phone" type="tel" inputmode="tel"
                   autocomplete="tel" required
                   value="<?= e($phoneValue) ?>"
                   placeholder="712 345 678"
                   aria-describedby="phone-hint"
                   <?= $step === 'phone' ? 'autofocus' : '' ?>>
          </div>
          <p class="field__hint" id="phone-hint">
            Local format (0712 345 678) or international (+254 712 345 678) both work.
          </p>
        </div>

        <div class="auth-actions">
          <button type="submit" class="btn btn--primary btn--lg btn--block">
            Send verification code
            <?= $icon('chevron', 'ico ico--sm') ?>
          </button>
        </div>
      </form>

      <?php if ($useFirebase): ?>
        <p class="field__hint">
          We send your code with Firebase by Google. Carrier SMS rates may apply.
        </p>
        <p class="field__hint" data-firebase-status role="status" aria-live="polite" hidden></p>
        <noscript>
          <p class="field__hint">
            Phone verification needs JavaScript. Turn it on and reload this page to sign in.
          </p>
        </noscript>
      <?php endif; ?>

      <?php if ($isDev && !$useFirebase): ?>
        <p class="auth-help">
          <?= $icon('info', 'ico ico--sm') ?>
          Local development: the SMS adapter is not live and the code is shown on the next screen.
        </p>
      <?php endif; ?>
    </div>

    <div data-auth-panel="verify" <?= $step === 'verify' ? '' : 'hidden' ?>>
      <div class="auth-phone-summary">
        <span class="icon-tile icon-tile--sm icon-tile--forest" aria-hidden="true"><?= $icon('phone', 'ico ico--sm') ?></span>
        <div class="auth-phone-summary__body">
          <p class="auth-phone-summary__label">Code sent to</p>
          <p class="auth-phone-summary__value" data-display-phone><?= e($phoneDisplay !== '' ? $phoneDisplay : 'your phone') ?></p>
        </div>
        <button type="button" class="text-action"<?= $useFirebase ? ' data-firebase-change-number' : ' data-change-number' ?>>Change</button>
      </div>

      <form method="post" action="<?= e('/login/verify') ?>" data-login-form="verify"<?= $useFirebase ? ' data-firebase-verify-form' : ' data-autosubmit' ?> class="stack">
        <input type="hidden" name="_token" value="<?= e($formToken) ?>">
        <input type="hidden" name="phone" value="<?= e($phoneRaw) ?>">
        <?php if ($useFirebase): ?>
          <input type="hidden" name="firebase_token" value="" data-firebase-token>
        <?php endif; ?>

        <div class="field">
          <label class="field__label" for="code">Verification code</label>
          <input class="otp-input" id="code" name="code" type="text"
                 inputmode="numeric" autocomplete="one-time-code"
                 pattern="[0-9]*" maxlength="<?= e((string) $otpLength) ?>"
                 required data-otp
                 aria-describedby="code-hint"
                 placeholder="<?= e(str_repeat('0', $otpLength)) ?>"
                 <?= $step === 'verify' ? 'autofocus' : '' ?>>
          <p class="field__hint" id="code-hint">
            Enter the <?= e((string) $otpLength) ?>-digit code from the SMS. Codes are single-use and expire quickly.
          </p>
        </div>

        <div class="auth-actions">
          <button type="submit" class="btn btn--primary btn--lg btn--block">
            Verify and sign in
            <?= $icon('check', 'ico ico--sm') ?>
          </button>
        </div>
      </form>

      <div class="resend">
        <?php if ($useFirebase): ?>
          <button type="button" class="text-action" data-firebase-resend>
            <?= $icon('refresh', 'ico ico--sm') ?>
            Resend code
          </button>
          <span class="resend__timer" data-firebase-timer hidden></span>
          <p class="field__hint" data-firebase-resend-status role="status" aria-live="polite" hidden></p>
        <?php else: ?>
          <form method="post" action="<?= e('/login/otp') ?>" data-resend-form>
            <input type="hidden" name="_token" value="<?= e($formToken) ?>">
            <input type="hidden" name="phone" value="<?= e($phoneRaw) ?>">
            <button type="submit" class="text-action" data-resend>
              <?= $icon('refresh', 'ico ico--sm') ?>
              Resend code
            </button>
          </form>
          <span class="resend__timer" data-resend-timer data-resend-seconds="60" hidden></span>
        <?php endif; ?>
      </div>

      <details class="disclosure">
        <summary>Use a different number</summary>
        <div class="disclosure__body">
          <?php if ($useFirebase): ?>
            <button type="button" class="btn btn--outline btn--block" data-firebase-change-number>
              Start over with a different number
            </button>
          <?php else: ?>
            <form method="post" action="<?= e('/login/otp') ?>" class="stack">
              <input type="hidden" name="_token" value="<?= e($formToken) ?>">
              <div class="field">
                <label class="field__label" for="phone-alt">Phone number</label>
                <input class="input" id="phone-alt" name="phone" type="tel" inputmode="tel"
                       autocomplete="tel" required placeholder="0712 345 678">
              </div>
              <button type="submit" class="btn btn--outline btn--block">Send a code to this number</button>
            </form>
          <?php endif; ?>
        </div>
      </details>
    </div>

    <?php if ($isDev && !$useFirebase): ?>
      <div class="dev-panel" data-dev-otp <?= ($devOtp !== '' && $step === 'verify') ? '' : 'hidden' ?>>
        <div class="dev-panel__head">
          <span class="chip chip--sunshine">Development only</span>
          <span>SMS adapter not live</span>
        </div>
        <p data-dev-otp-note><?= e($devNote !== '' ? $devNote : 'Development OTP adapter — never enabled in production.') ?></p>
        <div class="dev-panel__code">
          <span data-dev-otp-code><?= e($devOtp !== '' ? $devOtp : str_repeat('•', $otpLength)) ?></span>
          <span class="chip chip--sunshine">Dev OTP</span>
        </div>
        <p>
          The same code is written to <span class="mono">storage/logs/sms.log</span>.
          Production never returns the code to the browser.
        </p>
      </div>
    <?php endif; ?>
  </div>

  <p class="auth-help">
    Not registered yet? Ask your estate administrator to add your phone number,
    then sign in here. This screen never reveals whether a number is registered.
  </p>

  <a class="link-quiet" href="<?= e('/') ?>">← Back to welcome</a>
</div>
<?php if ($useFirebase): ?>
<script type="module" src="<?= e('/assets/js/firebase-auth.js?v=' . $assetVersion) ?>" defer></script>
<?php endif; ?>
