/* ==========================================================================
   MtaaPass guard console, progressive enhancement (visitor-access contract §7).

   Everything here is optional: every guard form is a real server-side POST with
   a CSRF token, so the console works with JavaScript disabled, slow devices or
   a blocked app.js. When app.js loaded and exposes MtaaPass.api the guard gets:

     - an on-screen PIN keypad (pure DOM, works even without the API),
     - inline verify via /api/v1/gate/verify-pin and verify-code,
     - a result panel with one-tap check-in,
     - one-tap checkout from the active/dashboard lists,
     - transient toasts for success/failure.

   CSP-safe: external file only, addEventListener, textContent for all data.
   ========================================================================== */

(function () {
  'use strict';

  var M = window.MtaaPass || null;
  var hasApi = !!(M && typeof M.api === 'function');

  var STATUS = {
    approved: ['APPROVED', 'ok'],
    pending: ['PENDING', 'warn'],
    checked_in: ['INSIDE', 'warn'],
    checked_out: ['USED', 'neutral'],
    revoked: ['DENIED', 'danger'],
    denied: ['DENIED', 'danger'],
    expired: ['EXPIRED', 'danger']
  };

  function $(selector, root) {
    return (root || document).querySelector(selector);
  }

  function $$(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  function on(element, type, handler) {
    if (element) {
      element.addEventListener(type, handler);
    }
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);

    if (className) {
      node.className = className;
    }

    if (text !== undefined && text !== null) {
      node.textContent = String(text);
    }

    return node;
  }

  function toast(message, options) {
    if (M && typeof M.toast === 'function') {
      M.toast(message, options || {});
    }
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

  function csrfTokenValue() {
    var meta = document.querySelector('meta[name="csrf-token"]');

    if (meta) {
      return meta.getAttribute('content') || '';
    }

    if (M && typeof M.csrfToken === 'function') {
      return M.csrfToken();
    }

    return '';
  }

  function statusInfo(data) {
    data = data || {};

    var raw = String(data.status || 'approved');
    var alreadyInside = !!data.already_inside || raw === 'checked_in';

    if (alreadyInside) {
      return { status: raw, label: 'ALREADY INSIDE', tone: 'warn', alreadyInside: true };
    }

    var pair = STATUS[raw] || [raw.replace(/_/g, ' ').toUpperCase(), 'neutral'];

    return { status: raw, label: pair[0], tone: pair[1], alreadyInside: false };
  }

  function formatWhen(iso) {
    if (!iso) {
      return '-';
    }

    var date = new Date(String(iso));

    if (isNaN(date.getTime())) {
      return String(iso);
    }

    try {
      return date.toLocaleString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
    } catch (error) {
      return String(iso);
    }
  }

  function clearChildren(node) {
    while (node.firstChild) {
      node.removeChild(node.firstChild);
    }
  }

  function linkButton(href, className, label) {
    var link = el('a', className, label);
    link.href = href;

    return link;
  }

  function buildChip(label, tone) {
    return el('span', 'g-chip g-chip--' + tone, label);
  }

  /* --------------------------------------------------------------- rendering */

  function buildCheckinForm(data) {
    var form = el('form', '');
    form.method = 'post';
    form.action = '/gate/checkin';
    form.setAttribute('data-guard-checkin', '');
    form.setAttribute('data-invitation-uuid', String(data.invitation_uuid || ''));

    var invitation = el('input', '');
    invitation.type = 'hidden';
    invitation.name = 'invitation_uuid';
    invitation.value = String(data.invitation_uuid || '');
    form.appendChild(invitation);

    if (data.credential_uuid) {
      var credential = el('input', '');
      credential.type = 'hidden';
      credential.name = 'credential_uuid';
      credential.value = String(data.credential_uuid);
      form.appendChild(credential);
    }

    var token = csrfTokenValue();

    if (token) {
      var csrf = el('input', '');
      csrf.type = 'hidden';
      csrf.name = '_token';
      csrf.value = token;
      form.appendChild(csrf);
    }

    var button = el('button', 'btn btn--primary btn--lg btn--block', 'Check in visitor');
    button.type = 'submit';
    form.appendChild(button);

    bindCheckin(form);

    return form;
  }

  function renderResult(region, data) {
    if (!region) {
      return;
    }

    data = data || {};
    clearChildren(region);

    var info = statusInfo(data);
    var card = el('section', 'g-result');
    card.setAttribute('data-tone', info.tone);
    card.setAttribute('tabindex', '-1');

    var head = el('div', 'g-result__head');
    head.appendChild(buildChip(info.label, info.tone));
    head.appendChild(el('h2', 'g-result__title', data.visitor_name || 'Visitor'));

    var sub = el('p', 'g-result__sub');

    if (data.household_label) {
      sub.appendChild(el('span', '', data.household_label));
    }

    if (data.purpose) {
      sub.appendChild(el('span', '', data.purpose));
    }

    if (sub.childNodes.length > 0) {
      head.appendChild(sub);
    }

    card.appendChild(head);

    var facts = el('dl', 'g-result__facts');
    [['Valid from', data.valid_from], ['Valid until', data.valid_until]].forEach(function (pair) {
      var fact = el('div', 'g-result__fact');
      fact.appendChild(el('dt', '', pair[0]));
      fact.appendChild(el('dd', '', formatWhen(pair[1])));
      facts.appendChild(fact);
    });
    card.appendChild(facts);

    if (info.alreadyInside) {
      var insideNote = el('div', 'g-result__note g-result__note--warn');
      insideNote.appendChild(el('p', '', 'This visitor is already inside. Do not admit them again, use the Active screen to check them out.'));
      card.appendChild(insideNote);
      card.appendChild(linkButton('/gate/active', 'btn btn--outline btn--lg btn--block', 'Open active visitors'));
    } else if (info.status === 'approved' && data.invitation_uuid) {
      card.appendChild(buildCheckinForm(data));
    } else {
      var deniedNote = el('div', 'g-result__note g-result__note--danger');
      deniedNote.appendChild(el('p', '', 'This pass cannot be used for entry. Ask the resident to issue a new one.'));
      card.appendChild(deniedNote);
    }

    card.appendChild(linkButton('/gate/verify', 'btn btn--ghost btn--block g-result__again', 'Verify another visitor'));
    region.appendChild(card);
    region.hidden = false;

    try {
      card.focus({ preventScroll: false });
    } catch (error) {
      card.focus();
    }
  }

  function renderError(region, message) {
    if (!region) {
      return;
    }

    clearChildren(region);

    var box = el('div', 'alert alert--error');
    box.setAttribute('role', 'alert');
    box.setAttribute('tabindex', '-1');

    var body = el('div', 'alert__body');
    var title = el('p', 'alert__title');
    title.appendChild(buildChip('INVALID', 'danger'));
    title.appendChild(document.createTextNode(' Pass not verified'));
    body.appendChild(title);
    body.appendChild(el('p', '', message || 'The pass could not be verified. Please try again.'));
    box.appendChild(body);
    region.appendChild(box);
    region.hidden = false;

    try {
      box.focus();
    } catch (error) {
      /* focus is best effort */
    }
  }

  /* ---------------------------------------------------------------- keypad */

  function initKeypad() {
    $$('[data-guard-keypad]').forEach(function (pad) {
      var form = pad.closest('form');
      var input = form ? $('[data-guard-pin]', form) : null;

      if (!input) {
        return;
      }

      $$('[data-guard-key]', pad).forEach(function (key) {
        on(key, 'click', function () {
          var action = key.getAttribute('data-guard-key') || '';
          var value = input.value.replace(/\D/g, '').slice(0, 6);

          if (action === 'clear') {
            value = '';
          } else if (action === 'back') {
            value = value.slice(0, -1);
          } else if (/^[0-9]$/.test(action)) {
            value = (value + action).slice(0, 6);
          }

          input.value = value;
          input.focus();

          try {
            input.dispatchEvent(new Event('input', { bubbles: true }));
          } catch (error) {
            /* event constructor is best effort */
          }
        });
      });

      on(input, 'input', function () {
        input.value = input.value.replace(/\D/g, '').slice(0, 6);
      });
    });
  }

  /* ------------------------------------------------------ verify (API path) */

  function submitVerify(form, endpoint, payload) {
    var region = $('[data-guard-result-region]');
    var button = form.querySelector('button[type="submit"]');

    setBusy(button, true);

    M.api(endpoint, { method: 'POST', json: payload })
      .then(function (data) {
        renderResult(region, data);
        toast('Pass verified.', { type: 'success' });
      })
      .catch(function (error) {
        var message = error && error.message ? error.message : 'The pass could not be verified. Please try again.';
        renderError(region, message);
        toast(message, { type: 'error' });
      })
      .then(function () {
        setBusy(button, false);
      });
  }

  function initVerifyForms() {
    if (!hasApi) {
      return;
    }

    var pinForm = $('[data-guard-verify-pin]');
    var codeForm = $('[data-guard-verify-code]');

    if (pinForm) {
      var pinInput = $('[data-guard-pin]', pinForm);

      on(pinForm, 'submit', function (event) {
        var pin = pinInput ? pinInput.value.replace(/\D/g, '').slice(0, 6) : '';

        if (pin.length !== 6) {
          event.preventDefault();
          toast('Enter the six-digit visitor PIN.', { type: 'error' });

          if (pinInput) {
            pinInput.focus();
          }

          return;
        }

        event.preventDefault();
        submitVerify(pinForm, '/api/v1/gate/verify-pin', { pin: pin });
      });
    }

    if (codeForm) {
      var codeInput = $('[data-guard-code]', codeForm);

      on(codeForm, 'submit', function (event) {
        var code = codeInput ? codeInput.value.trim() : '';

        if (code === '') {
          event.preventDefault();
          toast('Paste the pass code or pass link first.', { type: 'error' });

          if (codeInput) {
            codeInput.focus();
          }

          return;
        }

        event.preventDefault();
        submitVerify(codeForm, '/api/v1/gate/verify-code', { code: code });
      });
    }
  }

  /* ------------------------------------------------ check-in / check-out ---- */

  function bindCheckin(form) {
    on(form, 'submit', function (event) {
      if (!hasApi) {
        return; // native POST fallback
      }

      event.preventDefault();

      var invitationUuid = form.getAttribute('data-invitation-uuid') || '';
      var button = form.querySelector('button[type="submit"]');

      setBusy(button, true);

      M.api('/api/v1/gate/visitors/checkin', { method: 'POST', json: { invitation_uuid: invitationUuid } })
        .then(function () {
          toast('Visitor checked in.', { type: 'success' });
          window.setTimeout(function () {
            window.location.assign('/gate/active');
          }, 500);
        })
        .catch(function (error) {
          if (error && error.code === 'ALREADY_INSIDE') {
            toast('This visitor is already inside.', { type: 'error' });
            window.setTimeout(function () {
              window.location.assign('/gate/active');
            }, 500);

            return;
          }

          toast(error && error.message ? error.message : 'Check-in failed. Please try again.', { type: 'error' });
        })
        .then(function () {
          setBusy(button, false);
        });
    });
  }

  function bindCheckout(form) {
    on(form, 'submit', function (event) {
      if (!hasApi) {
        return; // native POST fallback
      }

      event.preventDefault();

      var invitationUuid = form.getAttribute('data-invitation-uuid') || '';
      var button = form.querySelector('button[type="submit"]');

      setBusy(button, true);

      M.api('/api/v1/gate/visitors/checkout', { method: 'POST', json: { invitation_uuid: invitationUuid } })
        .then(function () {
          toast('Visitor checked out.', { type: 'success' });
          window.setTimeout(function () {
            window.location.reload();
          }, 600);
        })
        .catch(function (error) {
          toast(error && error.message ? error.message : 'Checkout failed. Please try again.', { type: 'error' });
        })
        .then(function () {
          setBusy(button, false);
        });
    });
  }

  function initServerForms() {
    $$('form[data-guard-checkin]').forEach(bindCheckin);
    $$('form[data-guard-checkout]').forEach(bindCheckout);
  }

  /* ------------------------------------------------------------- polish ---- */

  function initNativeBusyStates() {
    $$('form[action="/gate/select-gate"]').forEach(function (form) {
      on(form, 'submit', function () {
        setBusy(form.querySelector('button[type="submit"]'), true);
      });
    });

    $$('form[data-guard-walkin]').forEach(function (form) {
      on(form, 'submit', function () {
        setBusy(form.querySelector('button[type="submit"]'), true);
      });
    });
  }

  function boot() {
    initKeypad();
    initVerifyForms();
    initServerForms();
    initNativeBusyStates();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
