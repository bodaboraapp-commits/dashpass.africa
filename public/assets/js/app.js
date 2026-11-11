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

  /** Product name for user-facing strings (per-deployment branding). */
  function appName() {
    var meta = document.querySelector('meta[name="application-name"]');
    var value = meta ? meta.getAttribute('content') || '' : '';
    return value.trim() || 'the app';
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
    if (status >= 500) return appName() + ' is having trouble right now. Please try again shortly.';
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
    toast('A new version of ' + appName() + ' is ready. Refresh to load it.', {
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

  function registerServiceWorker(attempt) {
    var retry = typeof attempt === 'number' ? attempt : 0;

    if (!('serviceWorker' in navigator)) {
      setInstallServiceWorkerState('unsupported');
      return;
    }

    navigator.serviceWorker
      .register('/service-worker.js', { scope: '/', updateViaCache: 'none' })
      .then(function (registration) {
        setInstallServiceWorkerState(navigator.serviceWorker.controller ? 'active' : 'registered');

        navigator.serviceWorker.ready
          .then(function () {
            setInstallServiceWorkerState('active');
          })
          .catch(function () {
            /* registration is still valid even if ready never settles */
          });

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
        // Retry once before reporting failure; never throw/unhandled-reject.
        if (retry < 1) {
          setInstallServiceWorkerState('checking');
          window.setTimeout(function () {
            registerServiceWorker(retry + 1);
          }, 2500);
          return;
        }
        setInstallServiceWorkerState('failed');
      });
  }

  function initServiceWorker() {
    try {
      if (!('serviceWorker' in navigator) || location.protocol === 'file:') {
        setInstallServiceWorkerState('unsupported');
        return;
      }

      hadServiceWorker = !!navigator.serviceWorker.controller;
      setInstallServiceWorkerState(hadServiceWorker ? 'active' : 'checking');

      navigator.serviceWorker.addEventListener('controllerchange', function () {
        setInstallServiceWorkerState('active');
        if (!hadServiceWorker) {
          return; // first install: no reload needed
        }
        hadServiceWorker = false;
        window.location.reload();
      });

      // Attempt registration on every page load (welcome included), immediately,
      // with one retry on failure so the self-check can surface the real status.
      registerServiceWorker(0);
    } catch (error) {
      setInstallServiceWorkerState('failed');
    }
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
  var INSTALL_DISMISS_KEY = 'mtaapass.install.dismissed';
  var installServiceWorkerState = 'checking';

  // Capture beforeinstallprompt during script evaluation (before DOMContentLoaded)
  // so the deferred event stays usable for the banner and the account self-check.
  try {
    on(window, 'beforeinstallprompt', function (event) {
      event.preventDefault();
      captureDeferredInstallPrompt(event);
    });
  } catch (error) {
    /* capture is best-effort — never fatal */
  }

  function installPad(value) {
    return (value < 10 ? '0' : '') + value;
  }

  function installTodayStamp() {
    var now = new Date();

    return now.getFullYear() + '-' + installPad(now.getMonth() + 1) + '-' + installPad(now.getDate());
  }

  function installDisplayMode() {
    try {
      if (window.matchMedia) {
        if (window.matchMedia('(display-mode: standalone)').matches) {
          return 'standalone';
        }
        if (window.matchMedia('(display-mode: minimal-ui)').matches) {
          return 'minimal-ui';
        }
        if (window.matchMedia('(display-mode: fullscreen)').matches) {
          return 'fullscreen';
        }
      }
    } catch (error) {
      /* feature detection only — never surface console noise */
    }

    return 'browser';
  }

  function installStandalone() {
    if (installDisplayMode() !== 'browser') {
      return true;
    }

    // iOS Safari exposes navigator.standalone instead of display-mode.
    try {
      return navigator.standalone === true;
    } catch (error) {
      return false;
    }
  }

  function installIsIos() {
    var ua = navigator.userAgent || '';

    if (/iPad|iPhone|iPod/.test(ua)) {
      return true;
    }

    // iPadOS 13+ reports a desktop Safari user agent.
    return navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
  }

  /**
   * In-app browsers (WhatsApp/Instagram/Facebook/LinkedIn/X/TikTok/Proxysite/
   * WebView wrappers) cannot install PWAs. Detect them so the UI can explain
   * "open in Chrome/Safari" instead of showing a dead Install button.
   */
  function installInAppBrowser() {
    var ua = navigator.userAgent || '';

    if (/(FBAN|FBAV|FB_IAB|Instagram|LinkedInApp|Twitter|TikTok|BytedanceWebview|Proxysite|Snapchat|Pinterest|MicroMessenger|Line\/|WhatsApp)/i.test(ua)) {
      return true;
    }

    // Android WebView (often used by in-app browsers) carries a "; wv)" marker.
    if (/Android/i.test(ua) && /;\s*wv[)/]/i.test(ua)) {
      return true;
    }

    return false;
  }

  function installPlatform() {
    var ua = navigator.userAgent || '';
    var inApp = installInAppBrowser();
    var ios = installIsIos();
    var android = /Android/i.test(ua);
    var samsung = /SamsungBrowser/i.test(ua);
    var edge = /Edg\//i.test(ua);
    var opera = /OPR\//i.test(ua);
    var chrome = /Chrome\//i.test(ua) && !edge && !opera;
    var chromeIos = /CriOS/i.test(ua);
    var firefoxIos = /FxiOS/i.test(ua);
    var edgeIos = /EdgiOS/i.test(ua);
    var label = 'Your browser';

    if (inApp) {
      label = 'In-app browser';
    } else if (ios) {
      label = chromeIos ? 'Chrome on iOS' : (firefoxIos ? 'Firefox on iOS' : (edgeIos ? 'Edge on iOS' : 'Safari on iOS'));
    } else if (samsung) {
      label = 'Samsung Internet';
    } else if (android) {
      label = chrome ? 'Chrome on Android' : 'Android browser';
    } else if (chrome) {
      label = 'Chrome';
    } else if (edge) {
      label = 'Edge';
    } else if (/Firefox/i.test(ua)) {
      label = 'Firefox';
    } else if (/Safari/i.test(ua)) {
      label = 'Safari';
    }

    return {
      label: label,
      inApp: inApp,
      ios: ios,
      android: android,
      samsung: samsung,
      chrome: chrome,
      chromeIos: chromeIos,
      firefoxIos: firefoxIos
    };
  }

  function installDismissedToday() {
    try {
      var stored = window.localStorage.getItem(INSTALL_DISMISS_KEY);
      if (!stored) {
        return false;
      }

      return String(stored).split('T')[0] === installTodayStamp();
    } catch (error) {
      return false;
    }
  }

  function rememberInstallDismissal() {
    try {
      window.localStorage.setItem(INSTALL_DISMISS_KEY, installTodayStamp());
    } catch (error) {
      /* private mode or storage disabled: dismissal is best-effort */
    }
  }

  function installBars() {
    return $$('[data-install-bar]');
  }

  function setInstallBars(visible) {
    installBars().forEach(function (bar) {
      bar.hidden = !visible;
    });
  }

  function setInstallBannerMessage(text) {
    if (typeof text !== 'string' || text === '') {
      return;
    }
    installBars().forEach(function (bar) {
      var message = bar.querySelector('[data-install-message]');
      if (message) {
        message.textContent = text;
      }
    });
  }

  /** Human-readable, platform-specific install steps (used by buttons/toasts). */
  function installStepsMessage() {
    var platform = installPlatform();
    var name = appName();

    if (platform.inApp) {
      return 'You are in an in-app browser, which cannot install apps. Open this page in Chrome or Safari, then install ' + name + ' from the browser menu.';
    }
    if (platform.ios) {
      return 'On iPhone or iPad: tap the Share button, then “Add to Home Screen”. iOS does not offer a native install prompt.';
    }
    if (platform.samsung) {
      return 'In Samsung Internet: tap the menu (☰) → “Add page to” → “Home screen”. For a native prompt, open this site in Chrome.';
    }
    if (platform.android) {
      return 'In Chrome on Android: tap the ⋮ menu → “Install app” (or “Add to Home screen”). If neither is visible yet, keep using the site — Chrome offers install after brief engagement.';
    }
    if (platform.ios) {
      return 'iOS browsers install via Share → “Add to Home Screen”; Safari is the most reliable.';
    }

    return 'To install ' + name + ': open this site in Chrome (Android) or Safari (iOS), then use the browser menu → Install app / Add to Home screen.';
  }

  function installBannerCopy() {
    var platform = installPlatform();

    if (deferredInstallPrompt) {
      return {
        message: 'Tap Install to add ' + appName() + ' to your home screen.',
        action: 'Install'
      };
    }

    if (platform.inApp) {
      return {
        message: 'You are in an in-app browser. Open this page in Chrome or Safari to install ' + appName() + '.',
        action: 'How to install'
      };
    }

    if (platform.ios) {
      return {
        message: 'On iPhone/iPad: tap Share → “Add to Home Screen”.',
        action: 'How to install'
      };
    }

    if (platform.android) {
      return {
        message: 'In Chrome: tap ⋮ → “Install app” (or Add to Home screen).',
        action: 'How to install'
      };
    }

    return null;
  }

  function setInstallCheck(selector, text, tone) {
    $$(selector).forEach(function (element) {
      element.textContent = text;
      element.classList.remove('is-ok', 'is-warn', 'is-bad');
      if (tone) {
        element.classList.add('is-' + tone);
      }
    });
  }

  function installOriginIsSecure() {
    try {
      return location.protocol === 'https:'
        || location.hostname === 'localhost'
        || location.hostname === '127.0.0.1';
    } catch (error) {
      return false;
    }
  }

  function syncInstallState() {
    var standalone = installStandalone();

    $$('[data-install-state]').forEach(function (element) {
      element.textContent = standalone
        ? element.getAttribute('data-installed-text') || 'Installed on this device'
        : element.getAttribute('data-browser-text') || 'Not installed on this device';
    });

    $$('[data-install-trigger]').forEach(function (trigger) {
      trigger.hidden = standalone;
    });

    $$('[data-install-hint]').forEach(function (hint) {
      hint.hidden = standalone;
    });

    syncInstallCheck();
  }

  /** Fill the account install self-check so a phone screenshot shows the blocker. */
  function syncInstallCheck() {
    var platform = installPlatform();
    var standalone = installStandalone();
    var secure = installOriginIsSecure();
    var manifestLink = document.querySelector('link[rel="manifest"]');

    setInstallCheck('[data-install-check-https]', secure ? 'Secure (HTTPS)' : 'Not secure — HTTPS required', secure ? 'ok' : 'bad');
    setInstallCheck('[data-install-check-manifest]', manifestLink ? 'Manifest linked' : 'Manifest link missing', manifestLink ? 'ok' : 'bad');

    var swTone = 'warn';
    var swText = 'Checking…';
    if (installServiceWorkerState === 'active') {
      swTone = 'ok';
      swText = 'Active (controlling page)';
    } else if (installServiceWorkerState === 'registered') {
      swTone = 'ok';
      swText = 'Registered (activating)';
    } else if (installServiceWorkerState === 'unsupported') {
      swTone = 'bad';
      swText = 'Service workers unsupported';
    } else if (installServiceWorkerState === 'failed') {
      swTone = 'bad';
      swText = 'Registration failed — try reloading';
    }
    setInstallCheck('[data-install-check-sw]', swText, swTone);

    var promptTone = 'warn';
    var promptText = 'Not offered by this browser';
    if (standalone) {
      promptTone = 'ok';
      promptText = 'Already installed';
    } else if (deferredInstallPrompt) {
      promptTone = 'ok';
      promptText = 'Available — tap Install';
    } else if (platform.inApp) {
      promptTone = 'bad';
      promptText = 'Unavailable inside an in-app browser';
    } else if (platform.ios) {
      promptText = 'Not offered on iOS — use Share → Add to Home Screen';
    } else if (platform.android) {
      promptText = 'Not offered yet — Chrome adds it after engagement';
    }
    setInstallCheck('[data-install-check-prompt]', promptText, promptTone);

    setInstallCheck(
      '[data-install-check-display]',
      standalone ? installDisplayMode() + ' mode' : 'Browser tab',
      standalone ? 'ok' : 'warn'
    );

    setInstallCheck(
      '[data-install-check-platform]',
      platform.label + (platform.inApp ? ' (embedded)' : ''),
      platform.inApp ? 'bad' : 'ok'
    );

    var verdict = 'Install guidance is being checked…';
    var verdictTone = 'warn';
    if (standalone) {
      verdict = 'Installed — you are using the installed app.';
      verdictTone = 'ok';
    } else if (deferredInstallPrompt) {
      verdict = 'Ready to install — use the Install button.';
      verdictTone = 'ok';
    } else if (platform.inApp) {
      verdict = 'Open this page in Chrome or Safari to install.';
      verdictTone = 'bad';
    } else if (platform.ios) {
      verdict = 'Use Share → “Add to Home Screen” (iOS has no install prompt).';
    } else if (platform.android) {
      verdict = 'Use Chrome’s ⋮ menu → “Install app”, or keep using the site so Chrome offers install.';
    } else if (!secure) {
      verdict = 'Install needs HTTPS; open the secure site address.';
      verdictTone = 'bad';
    } else {
      verdict = 'This browser does not offer app install.';
    }
    setInstallCheck('[data-install-check-verdict]', verdict, verdictTone);

    $$('[data-install-steps-text]').forEach(function (element) {
      element.textContent = installStepsMessage();
    });

    $$('[data-install-platform]').forEach(function (element) {
      element.textContent = platform.label;
      element.hidden = false;
    });
  }

  function checkManifestAvailability() {
    if (typeof fetch !== 'function' || !installOriginIsSecure()) {
      return;
    }

    fetch('/manifest.json', { headers: { Accept: 'application/manifest+json' }, cache: 'no-store' })
      .then(function (response) {
        if (!response.ok) {
          setInstallCheck('[data-install-check-manifest]', 'Manifest request failed (' + response.status + ')', 'bad');
          return null;
        }
        return response.json();
      })
      .then(function (manifest) {
        if (!manifest) {
          return;
        }
        var ok = !!(manifest.start_url && manifest.icons && manifest.icons.length);
        setInstallCheck(
          '[data-install-check-manifest]',
          ok ? 'Manifest loaded with icons' : 'Manifest incomplete',
          ok ? 'ok' : 'bad'
        );
      })
      .catch(function () {
        setInstallCheck('[data-install-check-manifest]', 'Manifest could not be read', 'bad');
      });
  }

  function captureDeferredInstallPrompt(event) {
    deferredInstallPrompt = event;
    syncInstallState();
    maybeShowInstallBanner();
  }

  function maybeShowInstallBanner() {
    if (installStandalone() || installDismissedToday()) {
      setInstallBars(false);
      return;
    }

    var copy = installBannerCopy();
    if (!copy) {
      setInstallBars(false);
      return;
    }

    setInstallBannerMessage(copy.message);
    $$('[data-install]').forEach(function (button) {
      button.textContent = copy.action;
    });
    $$('[data-install-steps]').forEach(function (stepButton) {
      stepButton.hidden = false;
    });
    setInstallBars(true);
  }

  function runInstallPrompt() {
    if (!deferredInstallPrompt) {
      toast(installStepsMessage(), { type: 'info', title: 'Install ' + appName() });
      return;
    }

    var prompt = deferredInstallPrompt;
    deferredInstallPrompt = null;
    try {
      prompt.prompt();
    } catch (error) {
      toast(installStepsMessage(), { type: 'info', title: 'Install ' + appName() });
      return;
    }
    if (prompt.userChoice) {
      prompt.userChoice.then(function (choice) {
        if (choice && choice.outcome === 'dismissed') {
          toast('You can install ' + appName() + ' any time from your browser menu.', {
            type: 'info',
            title: 'Install ' + appName()
          });
        }
        syncInstallState();
      }).catch(function () { /* user-choice rejection is harmless */ });
    }
  }

  function setInstallServiceWorkerState(state) {
    installServiceWorkerState = state || 'checking';
    syncInstallState();
  }

  function initInstall() {
    syncInstallState();
    syncInstallCheck();
    checkManifestAvailability();
    maybeShowInstallBanner();

    $$('[data-install]').forEach(function (button) {
      on(button, 'click', function () {
        if (installStandalone()) {
          return;
        }
        runInstallPrompt();
      });
    });

    $$('[data-install-steps]').forEach(function (button) {
      on(button, 'click', function () {
        toast(installStepsMessage(), { type: 'info', title: 'Install ' + appName() });
      });
    });

    $$('[data-install-dismiss]').forEach(function (button) {
      on(button, 'click', function () {
        rememberInstallDismissal();
        setInstallBars(false);
      });
    });

    on(window, 'appinstalled', function () {
      deferredInstallPrompt = null;
      setInstallBars(false);
      syncInstallState();
      toast('Installed — open it from your home screen.', { type: 'success', title: appName() });
    });

    // If the display mode changes (e.g. the user opens the installed app in the
    // same tab), refresh the banner and account state without a reload.
    try {
      if (window.matchMedia) {
        var displayMode = window.matchMedia('(display-mode: standalone)');
        if (displayMode.addEventListener) {
          displayMode.addEventListener('change', function () {
            syncInstallState();
            maybeShowInstallBanner();
          });
        }
      }
    } catch (error) {
      /* best effort only */
    }
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
     Visitor parking availability on the quick-invite form (Increment 3)
     Progressive enhancement only: the form submits normally without JS and the
     server re-validates availability inside the invitation transaction.
     ------------------------------------------------------------------------ */

  function initParkingAvailability() {
    var toggle = $('[data-parking-toggle]');
    var output = $('[data-parking-availability]');
    if (!toggle || !output) {
      return;
    }

    var form = toggle.form || document;
    var fromInput = form.querySelector('[data-valid-from]');
    var untilInput = form.querySelector('[data-valid-until]');
    var endpoint = output.getAttribute('data-availability-url') || '/api/v1/parking/availability';
    var requestSequence = 0;
    var debounceTimer = null;

    function renderText(text) {
      output.textContent = '';
      var note = document.createElement('span');
      note.className = 'parking-availability__note';
      note.textContent = text;
      output.appendChild(note);
    }

    function renderCount(available, total) {
      output.textContent = '';
      var chip = document.createElement('span');
      chip.className = 'chip ' + (available > 0 ? 'chip--sunshine' : 'chip--danger');
      chip.textContent = available > 0
        ? available + ' of ' + total + ' visitor bays available'
        : 'No visitor bays available';
      output.appendChild(chip);

      if (available === 0) {
        var note = document.createElement('span');
        note.className = 'parking-availability__note';
        note.textContent = ' Choose different times or untick visitor parking.';
        output.appendChild(note);
      }
    }

    function fetchAvailability() {
      if (!toggle.checked) {
        renderText(output.getAttribute('data-text-idle') || 'Availability is checked when visitor parking is required.');
        return;
      }

      if (!fromInput || !untilInput || !fromInput.value || !untilInput.value) {
        renderText(output.getAttribute('data-text-checking') || 'Checking visitor bay availability…');
        return;
      }

      var api = window.MtaaPass && window.MtaaPass.api;
      if (typeof api !== 'function') {
        return;
      }

      var sequence = ++requestSequence;
      renderText(output.getAttribute('data-text-checking') || 'Checking visitor bay availability…');

      api(
        endpoint +
          '?from=' +
          encodeURIComponent(fromInput.value) +
          '&to=' +
          encodeURIComponent(untilInput.value) +
          '&type=visitor',
        { method: 'GET' }
      )
        .then(function (data) {
          if (sequence !== requestSequence) {
            return;
          }
          var bays = data && Array.isArray(data.bays) ? data.bays : [];
          var available = bays.filter(function (bay) {
            return bay && bay.available === true;
          }).length;
          renderCount(available, bays.length);
        })
        .catch(function () {
          if (sequence !== requestSequence) {
            return;
          }
          renderText(output.getAttribute('data-text-error') || 'Availability could not be checked right now.');
        });
    }

    function debouncedFetch() {
      window.clearTimeout(debounceTimer);
      debounceTimer = window.setTimeout(fetchAvailability, 350);
    }

    on(toggle, 'change', fetchAvailability);
    if (fromInput) {
      on(fromInput, 'change', debouncedFetch);
    }
    if (untilInput) {
      on(untilInput, 'change', debouncedFetch);
    }

    if (toggle.checked) {
      // M.api is exported at the end of the IIFE, after boot in some browsers.
      window.setTimeout(fetchAvailability, 0);
    } else {
      renderText(output.getAttribute('data-text-idle') || 'Availability is checked when visitor parking is required.');
    }
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
    safe('parkingAvailability', initParkingAvailability);
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
  M.install = {
    isStandalone: installStandalone,
    isIos: installIsIos,
    platform: installPlatform,
    steps: installStepsMessage,
    syncState: syncInstallState,
    syncCheck: syncInstallCheck
  };
})();
