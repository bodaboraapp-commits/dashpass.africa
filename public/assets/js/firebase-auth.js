/**
 * Firebase Phone Auth enhancement for the sign-in screen (Increment 12).
 *
 * CSP-safe progressive enhancement:
 *  - no inline scripts/styles; all markup is server-rendered and only public
 *    Firebase web config is read from data-* attributes;
 *  - the Firebase compat SDK is loaded dynamically from the official gstatic
 *    CDN (10.x) once, after the user asks for a code;
 *  - invisible reCAPTCHA protects the SMS request;
 *  - the verified Firebase ID token is written into the existing
 *    `POST /login/verify` form as a hidden `firebase_token` and submitted
 *    natively (no new routes).
 *
 * The server-side script (/assets/js/app.js) also enhances these forms for the
 * legacy server-OTP flow. This script intercepts the two forms in the capture
 * phase and stops propagation so the two enhancements never fight: app.js only
 * runs when Firebase is disabled (in which case this file is not loaded at all).
 */
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
    sdkVersion: attr('data-sdk-version', '10.14.1'),
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
    firebase: null,
    auth: null,
    verifier: null,
    confirmation: null,
    phoneE164: '',
    busy: false,
    cooldownUntil: 0,
    cooldownTimer: null,
    sdkPromise: null
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
      setTitle('Sign in', 'Enter the phone number your estate registered for you. We will send a one-time code — no password needed.');
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

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = src;
      script.async = false;
      script.defer = true;
      script.onload = function () {
        resolve();
      };
      script.onerror = function () {
        reject(new Error('SDK_LOAD_FAILED'));
      };
      document.head.appendChild(script);
    });
  }

  function loadSdk() {
    if (window.firebase && window.firebase.auth) {
      return Promise.resolve(window.firebase);
    }
    if (state.sdkPromise) {
      return state.sdkPromise;
    }
    var base = 'https://www.gstatic.com/firebasejs/' + cfg.sdkVersion + '/';
    state.sdkPromise = loadScript(base + 'firebase-app-compat.js')
      .then(function () {
        return loadScript(base + 'firebase-auth-compat.js');
      })
      .then(function () {
        if (!window.firebase || !window.firebase.auth) {
          throw new Error('SDK_LOAD_FAILED');
        }
        return window.firebase;
      })
      .catch(function (error) {
        state.sdkPromise = null;
        throw error;
      });

    return state.sdkPromise;
  }

  function initAuth() {
    return loadSdk().then(function (firebase) {
      state.firebase = firebase;
      if (!state.auth) {
        var app;
        try {
          app = firebase.app();
        } catch (error) {
          app = firebase.initializeApp({
            apiKey: cfg.apiKey,
            authDomain: cfg.authDomain,
            projectId: cfg.projectId,
            storageBucket: cfg.storageBucket,
            messagingSenderId: cfg.messagingSenderId,
            appId: cfg.appId,
            measurementId: cfg.measurementId
          });
        }
        state.auth = firebase.auth(app);
        try {
          state.auth.useDeviceLanguage();
        } catch (error) {
          /* optional */
        }
      }

      return state.auth;
    });
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
      var host = phonePanel || screen;
      host.appendChild(container);
    }
    var parameters = {
      size: 'invisible',
      callback: function () {
        /* token solved */
      },
      'expired-callback': function () {
        setStatus('The security check expired. Please request a new code.', statusEl);
      }
    };
    try {
      state.verifier = new state.firebase.auth.RecaptchaVerifier(state.auth, cfg.recaptchaContainer, parameters);
    } catch (error) {
      state.verifier = new state.firebase.auth.RecaptchaVerifier(cfg.recaptchaContainer, parameters);
    }

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
      'auth/web-storage-unsupported': 'Your browser blocks sign-in storage. Try a different browser or disable private mode.',
      'SDK_LOAD_FAILED': 'The secure sign-in library could not load. Check your connection and try again.'
    };

    return map[code] || 'We could not complete sign-in. Try again in a moment.';
  }

  function requestCode(phone) {
    setBusy(true);
    clearError();
    setStatus('Sending a code to ' + phone + '…');
    return initAuth()
      .then(function () {
        return makeVerifier();
      })
      .then(function (verifier) {
        return state.auth.signInWithPhoneNumber(phone, verifier);
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
      setStatus('No active code. Tap “Resend code” to get a fresh SMS.', statusEl);
      return Promise.resolve(false);
    }
    setBusy(true);
    clearError();
    setStatus('Verifying…');
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
        setStatus('Signing you in…');
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

  function onPhoneSubmit(event) {
    event.preventDefault();
    event.stopImmediatePropagation();
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

  function onVerifySubmit(event) {
    event.preventDefault();
    event.stopImmediatePropagation();
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

  phoneForm.addEventListener('submit', onPhoneSubmit, true);
  verifyForm.addEventListener('submit', onVerifySubmit, true);

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

  if (resendButton) {
    resendButton.addEventListener('click', function (event) {
      event.preventDefault();
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
    });
  }

  Array.prototype.forEach.call(changeButtons, function (button) {
    button.addEventListener('click', function (event) {
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
    });
  });

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
