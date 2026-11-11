/* ==========================================================================
   MtaaPass progressive enhancement
   Vanilla JS only — no build step, no framework, no inline handlers.
   Everything here is an enhancement: every form and link also works with
   JavaScript disabled (server-rendered POST routes with CSRF tokens).
   ========================================================================== */

(function () {
  'use strict';

  var M = (window.MtaaPass = window.MtaaPass || {});

  /* ------------------------------------------------------------------------
     Small helpers
     ------------------------------------------------------------------------ */

  function $(selector, scope) {
    return (scope || document).querySelector(selector);
  }

  function $$(selector, scope) {
    return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
  }

  function on(target, type, handler, options) {
    if (target) {
      target.addEventListener(type, handler, options);
    }
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') || '' : '';
  }

  function safePath(value) {
    return typeof value === 'string' && value.charAt(0) === '/' && value.charAt(1) !== '/'
      ? value
      : '/app';
  }

  /**
   * Mirror App\Core\Phone::normalize for Kenyan numbers so the enhanced API
   * flow sends the same E.164 value the server stores. The server normalises
   * again — this only avoids a needless validation round trip.
   */
  function normalisePhone(value) {
    var digits = String(value || '').replace(/\D/g, '');
    if (!digits) {
      return '';
    }
    if (digits.indexOf('00') === 0) {
      digits = digits.slice(2);
    }
    if (digits.charAt(0) === '0') {
      digits = '254' + digits.slice(1);
    } else if (digits.length <= 9) {
      digits = '254' + digits;
    }
    digits = digits.replace(/^0+/, '');
    return digits ? '+' + digits : '';
  }

  function nativeSubmit(form) {
    if (!form) {
      return;
    }
    // Calling the native submit() bypasses this submit listener, so this is
    // the graceful fallback when the JSON API is unavailable.
    window.HTMLFormElement.prototype.submit.call(form);
  }

  function setBusy(button, busy) {
    if (!button) {
      return;
    }
    if (busy) {
      button.setAttribute('aria-busy', 'true');
      button.disabled = true;
    } else {
      button.removeAttribute('aria-busy');
      button.disabled = false;
    }
  }

  /* ------------------------------------------------------------------------
     API wrapper — CSRF-aware, envelope-aware
     ------------------------------------------------------------------------ */

  class ApiError extends Error {
    constructor(message, code, status, payload) {
      super(message);
      this.name = 'ApiError';
      this.code = code || 'UNKNOWN_ERROR';
      this.status = status || 0;
      this.payload = payload || null;
    }
  }

  function errorMessageForStatus(status) {
    if (status === 400) return 'Something in the request was not valid. Please check and try again.';
    if (status === 401) return 'Your session has expired. Please sign in again.';
    if (status === 403) return 'Your session security token was rejected. Refresh the page and try again.';
    if (status === 404) return 'That resource is not available.';
    if (status === 419) return 'Your session has expired. Please sign in again.';
    if (status === 429) return 'Too many attempts. Please wait a moment and try again.';
    if (status >= 500) return 'MtaaPass is having trouble right now. Please try again shortly.';
    return 'The request could not be completed.';
  }

  /**
   * MtaaPass.api('/api/v1/...', { method: 'POST', json: {...} })
   * Resolves with the response envelope `data` payload; rejects with ApiError.
   */
  async function api(path, options) {
    options = options || {};

    var method = (options.method || 'GET').toUpperCase();
    var headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');

    var body = options.body;
    if (Object.prototype.hasOwnProperty.call(options, 'json')) {
      body = JSON.stringify(options.json);
    }
    if (body !== undefined && body !== null && typeof body === 'object' && !(window.FormData && body instanceof window.FormData)) {
      body = JSON.stringify(body);
    }
    if (typeof body === 'string' && body !== '') {
      headers.set('Content-Type', 'application/json');
    }

    var token = options.csrfToken || csrfToken();
    if (token && method !== 'GET' && method !== 'HEAD') {
      headers.set('X-CSRF-Token', token);
    }

    var response = await fetch(path, {
      method: method,
      headers: headers,
      body: method === 'GET' || method === 'HEAD' ? undefined : body,
      credentials: 'same-origin',
      redirect: 'follow'
    });

    var text = await response.text();
    var payload = null;
    if (text) {
      try {
        payload = JSON.parse(text);
      } catch (error) {
        payload = null;
      }
    }

    if (!response.ok || (payload && payload.success === false)) {
      var apiError = (payload && payload.error) || {};
      throw new ApiError(
        apiError.message || errorMessageForStatus(response.status),
        apiError.code || 'HTTP_' + response.status,
        response.status,
        payload
      );
    }

    if (payload && Object.prototype.hasOwnProperty.call(payload, 'data')) {
      return payload.data;
    }

    return payload;
  }

  /* ------------------------------------------------------------------------
     Toast system
     ------------------------------------------------------------------------ */

  var TOAST_ICONS = {
    success:
      '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="m8.5 12.2 2.4 2.4 4.6-4.9"></path></svg>',
    error:
      '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 7.5v5.5"></path><path d="M12 16.2h.01"></path></svg>',
    info:
      '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 7.7h.01"></path></svg>'
  };

  var CLOSE_ICON =
    '<svg class="ico ico--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"></path></svg>';

  function ensureToastRegion() {
    var region = document.getElementById('toast-region');
    if (region) {
      return region;
    }
    region = document.createElement('div');
    region.id = 'toast-region';
    region.className = 'toast-region';
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('aria-atomic', 'false');
    document.body.appendChild(region);
    return region;
  }

  function dismissToast(element) {
    if (!element || element.classList.contains('toast--leaving')) {
      return;
    }
    element.classList.add('toast--leaving');
    window.setTimeout(function () {
      if (element.parentNode) {
        element.parentNode.removeChild(element);
      }
    }, 180);
  }

  function toast(message, options) {
    options = options || {};
    var region = ensureToastRegion();
    var type = options.type === 'success' || options.type === 'error' ? options.type : 'info';

    var element = document.createElement('div');
    element.className = 'toast toast--' + type;
    element.setAttribute('role', type === 'error' ? 'alert' : 'status');

    var icon = document.createElement('span');
    icon.className = 'toast__icon';
    icon.innerHTML = TOAST_ICONS[type];
    element.appendChild(icon);

    var body = document.createElement('div');
    body.className = 'toast__body';

    if (options.title) {
      var title = document.createElement('p');
      title.className = 'toast__title';
      title.textContent = options.title;
      body.appendChild(title);
    }

    var text = document.createElement('p');
    text.className = 'toast__message';
    text.textContent = message;
    body.appendChild(text);
    element.appendChild(body);

    if (options.action && options.action.label) {
      var action = document.createElement('button');
      action.type = 'button';
      action.className = 'text-action';
      action.textContent = options.action.label;
      on(action, 'click', function () {
        try {
          options.action.onClick();
        } finally {
          dismissToast(element);
        }
      });
      element.appendChild(action);
    }

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast__close';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.innerHTML = CLOSE_ICON;
    on(close, 'click', function () {
      dismissToast(element);
    });
    element.appendChild(close);

    region.appendChild(element);

    while (region.querySelectorAll('.toast').length > 3) {
      dismissToast(region.querySelector('.toast'));
    }

    var duration = typeof options.duration === 'number' ? options.duration : type === 'error' ? 8000 : 5200;
    if (duration > 0) {
      window.setTimeout(function () {
        dismissToast(element);
      }, duration);
    }

    return element;
  }

  function bindServerToasts() {
    $$('.toast[data-autodismiss]').forEach(function (element) {
      var close = element.querySelector('.toast__close');
      if (close) {
        on(close, 'click', function () {
          dismissToast(element);
        });
      }
      var duration = parseInt(element.getAttribute('data-autodismiss'), 10);
      window.setTimeout(function () {
        dismissToast(element);
      }, isNaN(duration) || duration <= 0 ? 6500 : duration);
    });
  }

  /* ------------------------------------------------------------------------
     Theme toggle (theme.js applies the theme before paint)
     ------------------------------------------------------------------------ */

  function currentThemeMode() {
    return window.MtaaPass && window.MtaaPass.theme ? window.MtaaPass.theme.stored() || 'system' : 'system';
  }

  function syncThemeControls() {
    if (!window.MtaaPass || !window.MtaaPass.theme) {
      return;
    }
    var mode = currentThemeMode();
    $$('[data-theme-set]').forEach(function (button) {
      var value = button.getAttribute('data-theme-set');
      button.setAttribute('aria-pressed', value === mode ? 'true' : 'false');
    });
    var dark = window.MtaaPass.theme.current() === 'dark';
    $$('[data-theme-toggle]').forEach(function (button) {
      button.hidden = false;
      button.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
      button.setAttribute('title', dark ? 'Switch to light theme' : 'Switch to dark theme');
    });
  }

  function initTheme() {
    if (!window.MtaaPass || !window.MtaaPass.theme) {
      return;
    }

    $$('[data-theme-toggle]').forEach(function (button) {
      on(button, 'click', function () {
        window.MtaaPass.theme.toggle();
        syncThemeControls();
      });
    });

    $$('[data-theme-set]').forEach(function (button) {
      on(button, 'click', function () {
        window.MtaaPass.theme.set(button.getAttribute('data-theme-set'));
        syncThemeControls();
      });
    });

    syncThemeControls();

    if (window.matchMedia) {
      var query = window.matchMedia('(prefers-color-scheme: dark)');
      var handler = function () {
        if (currentThemeMode() === 'system') {
          syncThemeControls();
        }
      };
      if (query.addEventListener) {
        query.addEventListener('change', handler);
      } else if (query.addListener) {
        query.addListener(handler);
      }
    }
  }

  /* ------------------------------------------------------------------------
     Connectivity banner
     ------------------------------------------------------------------------ */

  function initConnectivity() {
    var banner = $('[data-net-banner]');
    if (!banner) {
      return;
    }
    var label = $('[data-net-text]', banner);
    var hideTimer = null;

    function show(online) {
      if (hideTimer) {
        window.clearTimeout(hideTimer);
        hideTimer = null;
      }
      banner.classList.toggle('net-banner--offline', !online);
      banner.classList.toggle('net-banner--online', online);
      if (label) {
        label.textContent = online
          ? 'Back online — you can continue.'
          : 'You are offline. Fresh estate data and sign-in are unavailable.';
      }
      banner.hidden = online;
      if (online) {
        hideTimer = window.setTimeout(function () {
          banner.hidden = true;
        }, 4000);
      }
    }

    on(window, 'online', function () {
      show(true);
    });
    on(window, 'offline', function () {
      show(false);
    });

    if (!navigator.onLine) {
      show(false);
    }
  }

  /* ------------------------------------------------------------------------
     Service worker: offline shell, updates, cache clearing on sign-out
     ------------------------------------------------------------------------ */

  var hadServiceWorker = false;

  function offerUpdate(worker) {
    toast('A new version of MtaaPass is ready. Refresh to load it.', {
      type: 'info',
      title: 'Update available',
      duration: 0,
      action: {
        label: 'Refresh',
        onClick: function () {
          if (worker) {
            worker.postMessage({ type: 'SKIP_WAITING' });
          }
        }
      }
    });
  }

  function initServiceWorker() {
    if (!('serviceWorker' in navigator) || location.protocol === 'file:') {
      return;
    }

    hadServiceWorker = !!navigator.serviceWorker.controller;

    navigator.serviceWorker.addEventListener('controllerchange', function () {
      if (!hadServiceWorker) {
        return; // first install: no reload needed
      }
      hadServiceWorker = false;
      window.location.reload();
    });

    on(window, 'load', function () {
      navigator.serviceWorker
        .register('/service-worker.js', { scope: '/', updateViaCache: 'none' })
        .then(function (registration) {
          if (registration.waiting && navigator.serviceWorker.controller) {
            offerUpdate(registration.waiting);
          }
          registration.addEventListener('updatefound', function () {
            var worker = registration.installing;
            if (!worker) {
              return;
            }
            worker.addEventListener('statechange', function () {
              if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                offerUpdate(worker);
              }
            });
          });
        })
        .catch(function () {
          /* Offline support is progressive; the app works without it. */
        });
    });
  }

  function clearServiceWorkerCaches() {
    if (!('serviceWorker' in navigator) || !navigator.serviceWorker.controller) {
      return Promise.resolve();
    }

    return new Promise(function (resolve) {
      var finished = false;
      var finish = function () {
        if (!finished) {
          finished = true;
          resolve();
        }
      };

      try {
        var channel = new MessageChannel();
        channel.port1.onmessage = finish;
        navigator.serviceWorker.controller.postMessage({ type: 'LOGOUT' }, [channel.port2]);
      } catch (error) {
        finish();
      }

      window.setTimeout(finish, 800);
    });
  }

  /* ------------------------------------------------------------------------
     PWA install prompt
     ------------------------------------------------------------------------ */

  var deferredInstallPrompt = null;

  function initInstall() {
    var triggers = $$('[data-install-trigger]');

    on(window, 'beforeinstallprompt', function (event) {
      event.preventDefault();
      deferredInstallPrompt = event;
      $$('[data-install-bar]').forEach(function (bar) {
        bar.hidden = false;
      });
      triggers.forEach(function (trigger) {
        trigger.hidden = false;
      });
    });

    $$('[data-install]').forEach(function (button) {
      on(button, 'click', function () {
        if (!deferredInstallPrompt) {
          toast('To install MtaaPass, open your browser menu and choose "Add to Home screen".', {
            type: 'info',
            title: 'Install MtaaPass'
          });
          return;
        }
        var prompt = deferredInstallPrompt;
        deferredInstallPrompt = null;
        prompt.prompt();
        if (prompt.userChoice) {
          prompt.userChoice.then(function (choice) {
            if (choice && choice.outcome === 'dismissed') {
              toast('You can install MtaaPass any time from your browser menu.', { type: 'info' });
            }
          });
        }
      });
    });

    $$('[data-install-dismiss]').forEach(function (button) {
      on(button, 'click', function () {
        var bar = button.closest('[data-install-bar]');
        if (bar) {
          bar.hidden = true;
        }
      });
    });

    on(window, 'appinstalled', function () {
      deferredInstallPrompt = null;
      $$('[data-install-bar]').forEach(function (bar) {
        bar.hidden = true;
      });
      toast('MtaaPass is installed. Open it any time from your home screen.', { type: 'success' });
    });
  }

  /* ------------------------------------------------------------------------
     Sticky header shadow
     ------------------------------------------------------------------------ */

  function initHeader() {
    var header = $('.app-header');
    if (!header) {
      return;
    }
    var update = function () {
      header.classList.toggle('app-header--scrolled', window.scrollY > 6);
    };
    on(window, 'scroll', update, { passive: true });
    update();
  }

  /* ------------------------------------------------------------------------
     Access-pass sheet (addendum §2D)
     Native <dialog> gives Escape handling; this adds backdrop-click close,
     scroll locking and focus return. Openers: Home "Access / My QR" tile and
     the raised centre QR button in the bottom navigation.
     ------------------------------------------------------------------------ */

  function initQrSheet() {
    var sheet = document.getElementById('qr-sheet');
    if (!sheet) {
      return;
    }

    var openers = $$('[data-qr-open]');
    var closers = $$('[data-qr-close]', sheet);
    var lastFocus = null;
    var supportsDialog = typeof sheet.showModal === 'function';

    function focusFirst() {
      var target = sheet.querySelector('[data-qr-close]');
      if (target) {
        window.setTimeout(function () {
          target.focus();
        }, 20);
      }
    }

    function focusFirst() {
      var target = sheet.querySelector('[data-qr-close]');
      if (target) {
        window.setTimeout(function () {
          target.focus();
        }, 20);
      }
    }

    function restoreFocus() {
      if (lastFocus && typeof lastFocus.focus === 'function') {
        try {
          lastFocus.focus();
        } catch (error) {
          /* best effort focus return */
        }
      }
      lastFocus = null;
    }

    function open(event) {
      if (event) {
        event.preventDefault();
      }

      if (!supportsDialog) {
        toast('Your browser cannot display the in-app pass sheet. Open the pass from the Visitors screen instead.', {
          type: 'info',
          title: 'Access pass'
        });
        return;
      }

      lastFocus = document.activeElement;

      // Populate from controller-provided data attributes. The server renders
      // the same values, so this only ensures the QR image is (re)loaded lazily
      // when the sheet actually opens.
      var qrUrl = sheet.getAttribute('data-qr-url') || '';
      var qrImage = sheet.querySelector('[data-qr-image]');
      if (qrImage && qrUrl && qrImage.getAttribute('src') !== qrUrl) {
        qrImage.setAttribute('src', qrUrl);
      }
      var passLink = sheet.querySelector('[data-qr-pass-link]');
      var passUrl = sheet.getAttribute('data-pass-url') || '';
      if (passLink && passUrl && passLink.getAttribute('href') !== passUrl) {
        passLink.setAttribute('href', passUrl);
      }

      // The sheet ships with the `hidden` attribute so it can never appear
      // without JavaScript; reveal it just before promoting it to a modal.
      sheet.removeAttribute('hidden');
      if (!sheet.open) {
        try {
          sheet.showModal();
        } catch (error) {
          sheet.setAttribute('open', '');
        }
      }
      document.body.classList.add('qr-open');
      focusFirst();
    }

    function close() {
      document.body.classList.remove('qr-open');
      if (supportsDialog && sheet.open) {
        try {
          sheet.close();
          return; // the 'close' event restores focus + re-hides the sheet
        } catch (error) {
          sheet.removeAttribute('open');
        }
      }
      sheet.removeAttribute('open');
      sheet.setAttribute('hidden', '');
      restoreFocus();
    }

    openers.forEach(function (opener) {
      on(opener, 'click', open);
    });

    closers.forEach(function (closer) {
      on(closer, 'click', function (event) {
        event.preventDefault();
        close();
      });
    });

    // Backdrop click: the dialog element itself receives clicks outside the panel.
    on(sheet, 'click', function (event) {
      if (event.target === sheet) {
        close();
      }
    });

    // Escape closes even in browsers where the native dialog cancel event is
    // not delivered; the dialog fires 'close' either way.
    on(sheet, 'keydown', function (event) {
      if (event.key === 'Escape' || event.key === 'Esc') {
        event.preventDefault();
        close();
      }
    });

    on(sheet, 'cancel', function () {
      document.body.classList.remove('qr-open');
    });

    on(sheet, 'close', function () {
      document.body.classList.remove('qr-open');
      sheet.setAttribute('hidden', '');
      restoreFocus();
    });

    window.MtaaPass = window.MtaaPass || {};
    window.MtaaPass.qrSheet = { open: open, close: close };
  }

  /* ------------------------------------------------------------------------
     Visitor pass screens (Increment 2)
     Confirmation prompts for destructive pass actions and a small progressive
     enhancement for the quick-invite form. Every form still submits normally
     without JavaScript.
     ------------------------------------------------------------------------ */

  function initPassScreens() {
    // Destructive/irreversible actions ask for confirmation when JS is present.
    $$('form[data-confirm]').forEach(function (form) {
      on(form, 'submit', function (event) {
        var message = form.getAttribute('data-confirm') || 'Continue with this action?';
        if (!window.confirm(message)) {
          event.preventDefault();
          event.stopImmediatePropagation();
        }
      });
    });

    // Invite form: keep the "valid until" input after "valid from", and offer a
    // sensible end time when the browser leaves it empty. Server defaults win.
    var inviteForm = $('[data-invite-form]');
    if (!inviteForm) {
      return;
    }

    var fromInput = $('[data-valid-from]', inviteForm);
    var untilInput = $('[data-valid-until]', inviteForm);
    if (!fromInput || !untilInput) {
      return;
    }

    var defaultMinutes = parseInt(inviteForm.getAttribute('data-default-validity'), 10);
    if (isNaN(defaultMinutes) || defaultMinutes < 15) {
      defaultMinutes = 120;
    }

    function pad(value) {
      return (value < 10 ? '0' : '') + value;
    }

    function syncUntil(suggest) {
      if (fromInput.value) {
        untilInput.setAttribute('min', fromInput.value);
      }

      if (!suggest || untilInput.value !== '' || !fromInput.value) {
        return;
      }

      var start = new Date(fromInput.value);
      if (isNaN(start.getTime())) {
        return;
      }

      var end = new Date(start.getTime() + defaultMinutes * 60000);
      untilInput.value =
        end.getFullYear() +
        '-' +
        pad(end.getMonth() + 1) +
        '-' +
        pad(end.getDate()) +
        'T' +
        pad(end.getHours()) +
        ':' +
        pad(end.getMinutes());
    }

    on(fromInput, 'change', function () {
      syncUntil(true);
    });
    syncUntil(false);
  }

  /* ------------------------------------------------------------------------
     Features planned for the next increment — honest "coming next" feedback
     ------------------------------------------------------------------------ */

  function initComingSoon() {
    $$('[data-soon]').forEach(function (element) {
      on(element, 'click', function (event) {
        if (element.tagName === 'A') {
          event.preventDefault();
        }
        toast(element.getAttribute('data-soon') || 'This feature arrives in the next release.', {
          type: 'info',
          title: 'Coming next'
        });
      });
    });
  }

  /* ------------------------------------------------------------------------
     Copy-to-clipboard (correlation ids, pass codes in later increments)
     ------------------------------------------------------------------------ */

  function initCopy() {
    $$('[data-copy]').forEach(function (button) {
      on(button, 'click', function () {
        var value = button.getAttribute('data-copy') || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard
            .writeText(value)
            .then(function () {
              toast('Copied to clipboard.', { type: 'success' });
            })
            .catch(function () {
              toast('Could not copy automatically. Select the text and copy it manually.', { type: 'error' });
            });
        } else {
          toast('Copy the reference below by selecting it.', { type: 'info' });
        }
      });
    });
  }

  /* ------------------------------------------------------------------------
     Authentication: phone step → OTP step, dev OTP, resend countdown
     ------------------------------------------------------------------------ */

  function initAuth() {
    var screen = $('[data-auth]');
    if (!screen) {
      return;
    }

    var phonePanel = $('[data-auth-panel="phone"]', screen);
    var verifyPanel = $('[data-auth-panel="verify"]', screen);
    var phoneForm = $('[data-login-form="phone"]', screen);
    var verifyForm = $('[data-login-form="verify"]', screen);
    var resendForm = $('[data-resend-form]', screen);
    var errorBox = $('[data-auth-error]', screen);
    var errorText = $('[data-auth-error-text]', screen);
    var devPanel = $('[data-dev-otp]', screen);
    var resendTimerHandle = null;

    function showError(message) {
      if (!errorBox) {
        toast(message, { type: 'error' });
        return;
      }
      if (errorText) {
        errorText.textContent = message;
      }
      errorBox.hidden = false;
      errorBox.setAttribute('tabindex', '-1');
      try {
        errorBox.focus();
      } catch (error) {
        /* focus is best effort */
      }
    }

    function clearError() {
      if (errorBox) {
        errorBox.hidden = true;
      }
    }

    function showPanel(name) {
      if (phonePanel) {
        phonePanel.hidden = name !== 'phone';
      }
      if (verifyPanel) {
        verifyPanel.hidden = name !== 'verify';
      }
      var focusTarget =
        name === 'verify'
          ? $('input[name="code"]', verifyPanel)
          : $('input[name="phone"]', phonePanel);
      if (focusTarget) {
        window.setTimeout(function () {
          focusTarget.focus();
        }, 40);
      }
    }

    function setDevOtp(code, note) {
      if (!devPanel || !code) {
        return;
      }
      devPanel.hidden = false;
      $$('[data-dev-otp-code]', devPanel).forEach(function (element) {
        element.textContent = code;
      });
      if (note) {
        $$('[data-dev-otp-note]', devPanel).forEach(function (element) {
          element.textContent = note;
        });
      }
    }

    function fillPhone(phone) {
      if (verifyForm) {
        var hidden = verifyForm.querySelector('input[name="phone"]');
        if (hidden) {
          hidden.value = phone;
        }
      }
      $$('[data-display-phone]', screen).forEach(function (element) {
        element.textContent = phone;
      });
    }

    function startResendCountdown(seconds) {
      var button = $('[data-resend]', screen);
      var timer = $('[data-resend-timer]', screen);
      var remaining = typeof seconds === 'number' ? seconds : 60;

      if (resendTimerHandle) {
        window.clearInterval(resendTimerHandle);
        resendTimerHandle = null;
      }
      if (!button) {
        return;
      }

      function tick() {
        if (remaining > 0) {
          button.disabled = true;
          button.setAttribute('aria-disabled', 'true');
          if (timer) {
            timer.hidden = false;
            timer.textContent = 'Resend available in ' + remaining + 's';
          }
          remaining -= 1;
          return;
        }
        button.disabled = false;
        button.removeAttribute('aria-disabled');
        if (timer) {
          timer.hidden = true;
          timer.textContent = '';
        }
        if (resendTimerHandle) {
          window.clearInterval(resendTimerHandle);
          resendTimerHandle = null;
        }
      }

      tick();
      resendTimerHandle = window.setInterval(tick, 1000);
    }

    if (phoneForm) {
      on(phoneForm, 'submit', function (event) {
        var input = phoneForm.querySelector('input[name="phone"]');
        if (!input || !input.value.trim()) {
          return; // let native validation show its message
        }

        event.preventDefault();
        clearError();
        var submit = phoneForm.querySelector('button[type="submit"]');
        setBusy(submit, true);
        var phone = normalisePhone(input.value.trim());

        api('/api/v1/auth/request-otp', { method: 'POST', json: { phone: phone } })
          .then(function (data) {
            fillPhone(phone);
            showPanel('verify');
            startResendCountdown(60);
            if (data && data.dev_otp) {
              setDevOtp(data.dev_otp, data.dev_note || '');
            }
            toast('Verification code sent.', { type: 'success' });
          })
          .catch(function (error) {
            if (error instanceof ApiError && error.status === 404) {
              nativeSubmit(phoneForm); // API not available: use the HTML form route
              return;
            }
            if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
              showError(error.message);
              return;
            }
            nativeSubmit(phoneForm);
          })
          .finally(function () {
            setBusy(submit, false);
          });
      });
    }

    if (verifyForm) {
      on(verifyForm, 'submit', function (event) {
        var codeInput = verifyForm.querySelector('input[name="code"]');
        var phoneInput = verifyForm.querySelector('input[name="phone"]');
        var code = codeInput ? codeInput.value.replace(/\D/g, '') : '';

        if (code.length < 4) {
          event.preventDefault();
          showError('Enter the full code from the SMS. It is usually 6 digits.');
          if (codeInput) {
            codeInput.focus();
          }
          return;
        }

        event.preventDefault();
        clearError();
        var submit = verifyForm.querySelector('button[type="submit"]');
        setBusy(submit, true);

        api('/api/v1/auth/verify-otp', {
          method: 'POST',
          json: { phone: normalisePhone(phoneInput ? phoneInput.value : ''), code: code }
        })
          .then(function (data) {
            window.location.assign(safePath(data && data.redirect));
          })
          .catch(function (error) {
            if (error instanceof ApiError && error.status === 404) {
              nativeSubmit(verifyForm);
              return;
            }
            if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
              showError(error.message);
              if (codeInput) {
                codeInput.focus();
                codeInput.select();
              }
              return;
            }
            nativeSubmit(verifyForm);
          })
          .finally(function () {
            setBusy(submit, false);
          });
      });

      var otpInput = verifyForm.querySelector('input[data-otp]');
      if (otpInput) {
        var maxLength = parseInt(otpInput.getAttribute('maxlength'), 10) || 6;
        on(otpInput, 'input', function () {
          var digits = otpInput.value.replace(/\D/g, '').slice(0, maxLength);
          if (otpInput.value !== digits) {
            otpInput.value = digits;
          }
          clearError();
          if (digits.length === maxLength && verifyForm.hasAttribute('data-autosubmit')) {
            verifyForm.requestSubmit ? verifyForm.requestSubmit() : verifyForm.dispatchEvent(new Event('submit', { cancelable: true }));
          }
        });
      }
    }

    if (resendForm) {
      on(resendForm, 'submit', function (event) {
        if (!verifyPanel) {
          return;
        }
        event.preventDefault();
        var phoneInput = verifyForm ? verifyForm.querySelector('input[name="phone"]') : null;
        var phone = normalisePhone(phoneInput ? phoneInput.value : '');
        var button = resendForm.querySelector('button[type="submit"]');
        setBusy(button, true);

        api('/api/v1/auth/request-otp', { method: 'POST', json: { phone: phone } })
          .then(function (data) {
            clearError();
            startResendCountdown(60);
            if (data && data.dev_otp) {
              setDevOtp(data.dev_otp, data.dev_note || '');
            }
            toast('A new verification code has been sent.', { type: 'success' });
          })
          .catch(function (error) {
            if (error instanceof ApiError && error.status === 404) {
              nativeSubmit(resendForm);
              return;
            }
            if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
              showError(error.message);
              return;
            }
            nativeSubmit(resendForm);
          })
          .finally(function () {
            setBusy(button, false);
          });
      });
    }

    $$('[data-change-number]', screen).forEach(function (button) {
      on(button, 'click', function () {
        clearError();
        showPanel('phone');
      });
    });

    // Arriving on the server-rendered verify step (native form POST): start the
    // resend cooldown so the button cannot hammer the rate limiter.
    if (verifyPanel && !verifyPanel.hidden) {
      var timerElement = $('[data-resend-timer]', screen);
      var seconds = timerElement ? parseInt(timerElement.getAttribute('data-resend-seconds'), 10) : 0;
      if (seconds > 0) {
        startResendCountdown(seconds);
      }
    }
  }

  /* ------------------------------------------------------------------------
     Estate switching
     ------------------------------------------------------------------------ */

  function initEstateSwitch() {
    $$('form[data-estate-switch]').forEach(function (form) {
      on(form, 'submit', function (event) {
        var action = form.getAttribute('action') || '';
        var match = action.match(/\/app\/estates\/([0-9a-fA-F-]{36})\/switch/);
        if (!match) {
          return; // unknown shape — let the server-rendered form handle it
        }

        event.preventDefault();
        var button = form.querySelector('button[type="submit"]');
        setBusy(button, true);

        api('/api/v1/estates/' + encodeURIComponent(match[1]) + '/switch', { method: 'POST' })
          .then(function (data) {
            var name = data && data.estate && data.estate.estate_name ? data.estate.estate_name : 'the selected estate';
            toast('Switched to ' + name + '.', { type: 'success' });
            window.setTimeout(function () {
              window.location.assign('/app');
            }, 300);
          })
          .catch(function (error) {
            if (error instanceof ApiError && error.status === 404) {
              setBusy(button, false);
              nativeSubmit(form);
              return;
            }
            if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
              toast(error.message, { type: 'error' });
            } else {
              toast('We could not switch estate right now. Please try again.', { type: 'error' });
            }
            setBusy(button, false);
          });
      });
    });
  }

  /* ------------------------------------------------------------------------
     Sign out: API session logout, clear caches, then leave the shell
     ------------------------------------------------------------------------ */

  function initSignOut() {
    $$('form[data-logout]').forEach(function (form) {
      on(form, 'submit', function (event) {
        event.preventDefault();
        var button = form.querySelector('button[type="submit"]');
        setBusy(button, true);

        api('/api/v1/auth/logout', { method: 'POST' })
          .then(function () {
            return 'logout';
          })
          .catch(function (error) {
            if (error instanceof ApiError) {
              // 404 = API route unavailable during integration: use the HTML
              // form route. 401 = session already gone: finish signing out.
              return error.status === 404 ? 'native' : 'logout';
            }
            return 'offline';
          })
          .then(function (outcome) {
            if (outcome === 'offline') {
              setBusy(button, false);
              toast('You need a connection to sign out. Your session will stay open.', { type: 'error' });
              return;
            }
            if (outcome === 'native') {
              clearServiceWorkerCaches().then(function () {
                nativeSubmit(form);
              });
              return;
            }
            clearServiceWorkerCaches().then(function () {
              window.location.assign('/login');
            });
          });
      });
    });
  }

  /* ------------------------------------------------------------------------
     Boot
     ------------------------------------------------------------------------ */

  function safe(name, fn) {
    try {
      fn();
    } catch (error) {
      if (window.console && window.console.error) {
        window.console.error('[MtaaPass] ' + name + ' failed to initialise', error);
      }
    }
  }

  function boot() {
    safe('theme', initTheme);
    safe('connectivity', initConnectivity);
    safe('toasts', bindServerToasts);
    safe('header', initHeader);
    safe('install', initInstall);
    safe('qrSheet', initQrSheet);
    safe('passScreens', initPassScreens);
    safe('comingSoon', initComingSoon);
    safe('copy', initCopy);
    safe('auth', initAuth);
    safe('estateSwitch', initEstateSwitch);
    safe('signOut', initSignOut);
    safe('serviceWorker', initServiceWorker);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  // Public surface for future increments and for debugging in the console.
  M.api = api;
  M.toast = toast;
  M.ApiError = ApiError;
  M.clearCaches = clearServiceWorkerCaches;
  M.csrfToken = csrfToken;
})();
