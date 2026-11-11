/**
 * Firebase Phone Auth enhancement for the sign-in screen (Increment 12 / T131).
 *
 * Firebase **modular SDK 13.2.0** (ESM) loaded straight from the official
 * gstatic CDN via static imports below — no bundler, no compat globals, and no
 * analytics import (intentionally skipped: analytics is not needed for phone
 * auth and would add an extra module graph/requests).
 *
 * CSP-safe progressive enhancement:
 *  - loaded by the login view as an external `type="module"` script (no inline
 *    scripts/styles; all public Firebase web config is read from data-*);
 *  - invisible reCAPTCHA protects the SMS request;
 *  - the verified Firebase ID token is written into the existing
 *    `POST /login/verify` form as a hidden `firebase_token` and submitted
 *    natively (no new routes);
 *  - document-level capture listeners run before `/assets/js/app.js` (the
 *    legacy server-OTP enhancer), so the two flows never fight.
 */
import { initializeApp, getApps } from 'https://www.gstatic.com/firebasejs/13.2.0/firebase-app.js';
import {
  getAuth,
  RecaptchaVerifier,
  signInWithPhoneNumber
} from 'https://www.gstatic.com/firebasejs/13.2.0/firebase-auth.js';

(function () {
  'use strict';

  var configNode = document.querySelector('[data-firebase-auth]');
  if (!configNode) {
    return;
  }

  function attr(name, fallback) {
    var value = configNode.getAttribute(name);
    return value === null || value === '' ? fallback : value;
  }

  if (attr('data-enabled', '') !== 'true' || attr('data-provider', '') !== 'firebase') {
    return;
  }

  var cfg = {
    apiKey: attr('data-api-key', ''),
    authDomain: attr('data-auth-domain', ''),
    projectId: attr('data-project-id', ''),
    storageBucket: attr('data-storage-bucket', ''),
    messagingSenderId: attr('data-messaging-sender-id', ''),
    appId: attr('data-app-id', ''),
    measurementId: attr('data-measurement-id', ''),
    recaptchaContainer: attr('data-recaptcha-container', 'firebase-recaptcha'),
    sdkVersion: attr('data-sdk-version', '13.2.0'),
    otpLength: parseInt(attr('data-otp-length', '6'), 10) || 6,
    defaultCountry: attr('data-default-country', '+254')
  };

  if (!cfg.apiKey || !cfg.authDomain || !cfg.projectId || !cfg.appId) {
    return;
  }

  var screen = document.querySelector('[data-auth]');
  if (!screen) {
    return;
  }

  var phonePanel = screen.querySelector('[data-auth-panel="phone"]');
  var verifyPanel = screen.querySelector('[data-auth-panel="verify"]');
  var phoneForm = screen.querySelector('[data-firebase-phone-form]') || screen.querySelector('[data-login-form="phone"]');
  var verifyForm = screen.querySelector('[data-firebase-verify-form]') || screen.querySelector('[data-login-form="verify"]');
  if (!phoneForm || !verifyForm) {
    return;
  }

  var phoneInput = phoneForm.querySelector('input[name="phone"]');
  var codeInput = verifyForm.querySelector('input[data-otp]');
  var phoneHidden = verifyForm.querySelector('input[name="phone"]');
  var tokenInput = verifyForm.querySelector('[data-firebase-token]');
  var errorBox = screen.querySelector('[data-auth-error]');
  var errorText = screen.querySelector('[data-auth-error-text]');
  var errorList = screen.querySelector('[data-auth-error-list]');
  var statusEl = screen.querySelector('[data-firebase-status]');
  var resendButton = screen.querySelector('[data-firebase-resend]');
  var resendTimer = screen.querySelector('[data-firebase-timer]');
  var resendStatus = screen.querySelector('[data-firebase-resend-status]');
  var titleEl = document.querySelector('[data-auth-title]');
  var ledeEl = document.querySelector('[data-auth-lede]');
  var changeButtons = document.querySelectorAll('[data-firebase-change-number]');
  var phoneSubmit = phoneForm.querySelector('button[type="submit"]');
  var verifySubmit = verifyForm.querySelector('button[type="submit"]');

  var state = {
    auth: null,
    verifier: null,
    confirmation: null,
    phoneE164: '',
    busy: false,
    cooldownUntil: 0,
    cooldownTimer: null
  };

  function setBusy(busy) {
    state.busy = busy;
    [phoneSubmit, verifySubmit].forEach(function (button) {
      if (!button) {
        return;
      }
      button.disabled = busy;
      if (busy) {
        button.setAttribute('aria-disabled', 'true');
      } else {
        button.removeAttribute('aria-disabled');
      }
    });
    phoneForm.setAttribute('aria-busy', busy ? 'true' : 'false');
  }

  function clearError() {
    if (errorBox) {
      errorBox.hidden = true;
    }
    if (errorList) {
      errorList.textContent = '';
    }
  }

  function showError(message, details) {
    if (errorList) {
      errorList.textContent = '';
      (details || []).forEach(function (line) {
        var item = document.createElement('li');
        item.textContent = line;
        errorList.appendChild(item);
      });
    }
    if (!errorBox) {
      window.alert(message);
      return;
    }
    if (errorText) {
      errorText.textContent = message;
    }
    errorBox.hidden = false;
    try {
      errorBox.focus();
    } catch (error) {
      /* focus is best effort */
    }
  }

  function setStatus(message, element) {
    var target = element || statusEl;
    if (!target) {
      return;
    }
    if (message) {
      target.textContent = message;
      target.hidden = false;
    } else {
      target.textContent = '';
      target.hidden = true;
    }
  }

  function setTitle(title, lede) {
    if (titleEl && title) {
      titleEl.textContent = title;
    }
    if (ledeEl && lede) {
      ledeEl.textContent = lede;
    }
  }

  function setStepper(step) {
    var items = document.querySelectorAll('.stepper__item');
    if (!items || items.length < 2) {
      return;
    }
    items[0].classList.toggle('stepper__item--done', step === 'verify');
    items[0].classList.toggle('stepper__item--active', step === 'phone');
    if (step === 'verify') {
      items[0].removeAttribute('aria-current');
    } else {
      items[0].setAttribute('aria-current', 'step');
    }
    items[1].classList.toggle('stepper__item--active', step === 'verify');
    if (step === 'verify') {
      items[1].setAttribute('aria-current', 'step');
    } else {
      items[1].removeAttribute('aria-current');
    }
  }

  function showPanel(step) {
    if (phonePanel) {
      phonePanel.hidden = step !== 'phone';
    }
    if (verifyPanel) {
      verifyPanel.hidden = step !== 'verify';
    }
    setStepper(step);
    if (step === 'verify') {
      setTitle('Enter your code', 'We sent a ' + cfg.otpLength + '-digit code to ' + (state.phoneE164 || 'your phone') + '. It expires in a few minutes.');
    } else {
      setTitle('Sign in', 'Enter the phone number your estate registered for you. We will send a one-time code \u2014 no password needed.');
    }
    var focusTarget = step === 'verify' ? codeInput : phoneInput;
    if (focusTarget) {
      window.setTimeout(function () {
        try {
          focusTarget.focus();
        } catch (error) {
          /* best effort */
        }
      }, 40);
    }
  }

  /**
   * Normalise Kenyan-default phone input to E.164. Accepts: +254712345678,
   * 00254712345678, 0712345678, 712345678. Anything else keeps its digits.
   */
  function normalisePhone(raw) {
    var trimmed = String(raw || '').trim();
    if (!trimmed) {
      return '';
    }
    var digits = trimmed.replace(/\D/g, '');
    if (!digits) {
      return '';
    }
    var phone;
    if (trimmed.charAt(0) === '+') {
      phone = '+' + digits;
    } else if (digits.indexOf('00') === 0) {
      phone = '+' + digits.slice(2);
    } else if (digits.charAt(0) === '0') {
      phone = cfg.defaultCountry + digits.slice(1);
    } else if (digits.length === 9) {
      phone = cfg.defaultCountry + digits;
    } else {
      phone = '+' + digits;
    }
    var numeric = phone.replace(/\D/g, '');
    return numeric.length >= 10 && numeric.length <= 15 ? phone : '';
  }

  function getFirebaseAuth() {
    if (state.auth) {
      return state.auth;
    }
    var app = null;
    try {
      getApps().forEach(function (existing) {
        if (!app && existing && existing.options && existing.options.appId === cfg.appId) {
          app = existing;
        }
      });
    } catch (error) {
      app = null;
    }
    if (!app) {
      app = initializeApp({
        apiKey: cfg.apiKey,
        authDomain: cfg.authDomain,
        projectId: cfg.projectId,
        storageBucket: cfg.storageBucket,
        messagingSenderId: cfg.messagingSenderId,
        appId: cfg.appId,
        measurementId: cfg.measurementId
      });
    }
    state.auth = getAuth(app);
    try {
      state.auth.languageCode = (navigator.language || 'en').split('-')[0];
    } catch (error) {
      /* language is best effort */
    }

    return state.auth;
  }

  function resetVerifier() {
    if (state.verifier) {
      try {
        state.verifier.clear();
      } catch (error) {
        /* ignore */
      }
    }
    state.verifier = null;
  }

  function makeVerifier() {
    resetVerifier();
    var container = document.getElementById(cfg.recaptchaContainer);
    if (!container) {
      container = document.createElement('div');
      container.id = cfg.recaptchaContainer;
      container.setAttribute('aria-hidden', 'true');
      (phonePanel || screen).appendChild(container);
    }
    state.verifier = new RecaptchaVerifier(getFirebaseAuth(), container, {
      size: 'invisible',
      callback: function () {
        /* token solved */
      },
      'expired-callback': function () {
        setStatus('The security check expired. Please request a new code.', statusEl);
      }
    });

    return state.verifier;
  }

  function startCooldown(seconds) {
    var remaining = typeof seconds === 'number' ? seconds : 60;
    state.cooldownUntil = Date.now() + remaining * 1000;
    if (state.cooldownTimer) {
      window.clearInterval(state.cooldownTimer);
      state.cooldownTimer = null;
    }
    if (!resendButton) {
      return;
    }
    function tick() {
      remaining = Math.max(0, Math.ceil((state.cooldownUntil - Date.now()) / 1000));
      if (remaining > 0) {
        resendButton.disabled = true;
        resendButton.setAttribute('aria-disabled', 'true');
        if (resendTimer) {
          resendTimer.hidden = false;
          resendTimer.textContent = 'You can resend in ' + remaining + 's';
        }
        return;
      }
      resendButton.disabled = false;
      resendButton.removeAttribute('aria-disabled');
      if (resendTimer) {
        resendTimer.hidden = true;
        resendTimer.textContent = '';
      }
      if (state.cooldownTimer) {
        window.clearInterval(state.cooldownTimer);
        state.cooldownTimer = null;
      }
    }
    tick();
    state.cooldownTimer = window.setInterval(tick, 1000);
  }

  function describeError(error) {
    var code = error && error.code ? String(error.code) : '';
    var map = {
      'auth/invalid-phone-number': 'That phone number does not look right. Check the number and try again.',
      'auth/too-many-requests': 'Too many sign-in attempts were made. Wait a few minutes and try again.',
      'auth/quota-exceeded': 'The SMS quota is exhausted right now. Try again later or contact your estate administrator.',
      'auth/invalid-verification-code': 'That code is not correct. Check the latest SMS and try again.',
      'auth/missing-verification-code': 'Enter the code from the SMS.',
      'auth/code-expired': 'That code has expired. Resend a new code.',
      'auth/invalid-verification-id': 'That code is no longer valid. Resend a new code.',
      'auth/captcha-check-failed': 'The security check failed. Refresh the page and try again.',
      'auth/network-request-failed': 'The network could not reach the SMS service. Check your connection and try again.',
      'auth/operation-not-allowed': 'Phone sign-in is not enabled for this estate yet. Contact your estate administrator.',
      'auth/unauthorized-domain': 'This address is not authorised for sign-in. Contact your estate administrator.',
      'auth/internal-error': 'The SMS service had a temporary problem. Try again in a moment.',
      'auth/web-storage-unsupported': 'Your browser blocks sign-in storage. Try a different browser or disable private mode.'
    };

    return map[code] || 'We could not complete sign-in. Try again in a moment.';
  }

  function requestCode(phone) {
    setBusy(true);
    clearError();
    setStatus('Sending a code to ' + phone + '\u2026');
    return Promise.resolve()
      .then(function () {
        makeVerifier();
        return signInWithPhoneNumber(getFirebaseAuth(), phone, state.verifier);
      })
      .then(function (confirmation) {
        state.confirmation = confirmation;
        state.phoneE164 = phone;
        if (phoneHidden) {
          phoneHidden.value = phone;
        }
        if (resendStatus) {
          resendStatus.hidden = true;
        }
        setStatus('');
        showPanel('verify');
        startCooldown(60);
        if (codeInput) {
          codeInput.value = '';
        }
        return true;
      })
      .catch(function (error) {
        resetVerifier();
        showError(describeError(error));
        return false;
      })
      .then(function (result) {
        setBusy(false);
        return result;
      });
  }

  function confirmCode(code) {
    if (!state.confirmation) {
      showError('Request a new code to continue.');
      setStatus('No active code. Tap \u201cResend code\u201d to get a fresh SMS.', statusEl);
      return Promise.resolve(false);
    }
    setBusy(true);
    clearError();
    setStatus('Verifying\u2026');
    return state.confirmation
      .confirm(code)
      .then(function (credential) {
        return credential.user.getIdToken(true);
      })
      .then(function (token) {
        if (!token || !tokenInput) {
          throw new Error('missing-token');
        }
        tokenInput.value = token;
        if (phoneHidden) {
          phoneHidden.value = state.phoneE164;
        }
        var verifyCode = verifyForm.querySelector('input[name="code"]');
        if (verifyCode) {
          verifyCode.value = code;
        }
        setStatus('Signing you in\u2026');
        // Native submit bypasses app.js API enhancers; the server verifies the
        // Firebase ID token and creates the session.
        verifyForm.submit();
        return true;
      })
      .catch(function (error) {
        showError(describeError(error));
        setStatus('');
        return false;
      })
      .then(function (result) {
        setBusy(false);
        return result;
      });
  }

  function handlePhoneSubmit() {
    if (state.busy) {
      return;
    }
    var raw = phoneInput ? phoneInput.value : '';
    var phone = normalisePhone(raw);
    if (!phone) {
      showError('Enter a valid phone number, for example 0712 345 678.');
      if (phoneInput) {
        phoneInput.focus();
      }
      return;
    }
    if (phoneInput) {
      phoneInput.value = phone;
    }
    requestCode(phone);
  }

  function handleVerifySubmit() {
    if (state.busy) {
      return;
    }
    var code = codeInput ? codeInput.value.replace(/\D/g, '') : '';
    if (code.length !== cfg.otpLength) {
      showError('Enter the full ' + cfg.otpLength + '-digit code from the SMS.');
      if (codeInput) {
        codeInput.focus();
      }
      return;
    }
    confirmCode(code);
  }

  /**
   * Document-level capture listeners: module scripts execute after the deferred
   * app.js, so a listener registered directly on the form could lose to app.js's
   * bubble handler. Capture phase on document always runs first, so stopping
   * propagation here keeps the legacy API enhancer out of the Firebase flow.
   */
  document.addEventListener('submit', function (event) {
    var target = event.target;
    if (target === phoneForm) {
      event.preventDefault();
      event.stopImmediatePropagation();
      handlePhoneSubmit();
    } else if (target === verifyForm) {
      event.preventDefault();
      event.stopImmediatePropagation();
      handleVerifySubmit();
    }
  }, true);

  document.addEventListener('click', function (event) {
    var target = event.target;
    var resend = target && target.closest ? target.closest('[data-firebase-resend]') : null;
    var change = target && target.closest ? target.closest('[data-firebase-change-number]') : null;
    if (resend) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (state.busy || Date.now() < state.cooldownUntil) {
        return;
      }
      var phone = state.phoneE164 || normalisePhone(phoneInput ? phoneInput.value : '');
      if (!phone) {
        showError('Enter your phone number again to resend a code.');
        showPanel('phone');
        return;
      }
      state.phoneE164 = phone;
      requestCode(phone);
    } else if (change) {
      event.preventDefault();
      event.stopImmediatePropagation();
      state.confirmation = null;
      state.phoneE164 = '';
      if (tokenInput) {
        tokenInput.value = '';
      }
      clearError();
      setStatus('');
      if (resendTimer) {
        resendTimer.hidden = true;
      }
      showPanel('phone');
      if (phoneInput) {
        phoneInput.value = '';
      }
    }
  }, true);

  if (codeInput) {
    codeInput.addEventListener('input', function () {
      var digits = codeInput.value.replace(/\D/g, '').slice(0, cfg.otpLength);
      if (codeInput.value !== digits) {
        codeInput.value = digits;
      }
      clearError();
      if (digits.length === cfg.otpLength && !state.busy) {
        confirmCode(digits);
      }
    });
  }

  // A server-rendered verify step under the Firebase provider has no live
  // confirmation object (the page was reloaded): ask the user to request a code.
  if (verifyPanel && !verifyPanel.hidden) {
    state.phoneE164 = normalisePhone(phoneHidden ? phoneHidden.value : '') || '';
    if (!state.phoneE164 && phoneInput) {
      state.phoneE164 = normalisePhone(phoneInput.value);
    }
    setStatus('For your security, request a new code to continue.', resendStatus || statusEl);
    startCooldown(0);
  } else if (phoneInput) {
    phoneInput.focus();
  }
})();
