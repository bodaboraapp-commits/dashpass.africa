/* ==========================================================================
   MtaaPass theme bootstrap
   Loaded synchronously in <head> so the correct theme is applied before the
   first paint (no flash of the wrong colour scheme). Deliberately tiny.

   <html data-theme-default="dark"> makes the resident app dark by default even
   when the OS prefers light; a stored user preference always wins. Pages
   without the attribute keep following the OS preference (auth/guest screens).
   ========================================================================== */

(function () {
  'use strict';

  var STORAGE_KEY = 'mtaapass-theme';
  var root = document.documentElement;

  function systemTheme() {
    try {
      return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light';
    } catch (error) {
      return 'light';
    }
  }

  function storedTheme() {
    try {
      var value = window.localStorage.getItem(STORAGE_KEY);
      return value === 'light' || value === 'dark' ? value : null;
    } catch (error) {
      return null;
    }
  }

  /** Theme painted when the visitor has never chosen one. */
  function defaultTheme() {
    var configured = root.getAttribute('data-theme-default');

    return configured === 'light' || configured === 'dark' ? configured : systemTheme();
  }

  /** The theme currently painted, honouring an explicit stored choice. */
  function appliedTheme() {
    var explicit = root.getAttribute('data-theme');

    if (explicit === 'light' || explicit === 'dark') {
      return explicit;
    }

    return defaultTheme();
  }

  function paintMeta() {
    var meta = document.querySelector('meta[name="theme-color"][data-theme-color]');

    if (!meta) {
      return;
    }

    var residentApp = root.getAttribute('data-theme-default') === 'dark';
    var dark = appliedTheme() === 'dark';

    if (residentApp) {
      meta.setAttribute('content', dark ? '#070B16' : '#F4F6FB');
    } else {
      meta.setAttribute('content', dark ? '#0B1613' : '#123D35');
    }
  }

  function apply(theme) {
    if (theme === 'light' || theme === 'dark') {
      root.setAttribute('data-theme', theme);
    } else {
      root.removeAttribute('data-theme');
    }

    // data-mode always resolves to the painted theme (for icon-only toggles).
    root.setAttribute('data-mode', appliedTheme());
    paintMeta();
  }

  apply(storedTheme());

  // Mark that JS is available so CSS can reveal JS-only controls.
  root.classList.add('has-js');

  window.MtaaPass = window.MtaaPass || {};
  window.MtaaPass.theme = {
    key: STORAGE_KEY,
    system: systemTheme,
    stored: storedTheme,
    default: defaultTheme,
    current: appliedTheme,
    apply: apply,
    set: function (theme) {
      if (theme === 'light' || theme === 'dark') {
        try {
          window.localStorage.setItem(STORAGE_KEY, theme);
        } catch (error) {
          /* storage may be blocked; the theme still applies for this page */
        }
        apply(theme);

        return;
      }

      try {
        window.localStorage.removeItem(STORAGE_KEY);
      } catch (error) {
        /* ignore */
      }
      apply(null);
    },
    toggle: function () {
      this.set(this.current() === 'dark' ? 'light' : 'dark');

      return this.current();
    }
  };
})();
