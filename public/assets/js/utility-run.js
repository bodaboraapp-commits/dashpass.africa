/**
 * Estate utility run cockpit: live usage + amount as the manager types readings.
 *
 * Progressive enhancement only: without JS the form still posts and the server
 * validates + computes the same amounts. All money is handled in integer cents.
 */
(function () {
  'use strict';

  var MONEY = new Intl.NumberFormat('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  function formatCents(cents) {
    return 'KES ' + MONEY.format(cents / 100);
  }

  function number(value) {
    var parsed = Number(value);
    return isFinite(parsed) ? parsed : null;
  }

  function updateRow(row) {
    var rate = Number(row.getAttribute('data-rate') || '0');
    var flat = Number(row.getAttribute('data-flat') || '0');
    var last = number(row.getAttribute('data-last'));
    var input = row.querySelector('[data-utility-reading]');
    var usageCell = row.querySelector('[data-utility-usage]');
    var amountCell = row.querySelector('[data-utility-amount]');
    var issued = row.querySelector('.status');
    var usage = null;
    var valid = true;

    if (issued) {
      return null;
    }

    var entered = true;

    if (input) {
      var raw = input.value.trim();
      entered = raw !== '';

      if (entered) {
        var reading = number(raw);

        if (reading === null || reading < 0 || (last !== null && reading < last)) {
          valid = false;
        } else {
          usage = Math.max(0, reading - (last === null ? 0 : last));
        }
      }
    }

    if (usageCell) {
      usageCell.textContent = usage === null ? '—' : String(Math.round(usage * 1000) / 1000);
    }

    // An untouched metered row is neutral (not an error): show just the flat part.
    var amount = valid ? flat + (usage === null ? 0 : usage * rate) : null;

    if (amount !== null && input !== null && !entered && flat === 0) {
      amount = null;
    }

    amount = amount === null ? null : Math.round(amount);

    if (amountCell) {
      amountCell.textContent = amount === null ? '—' : formatCents(amount);
    }

    if (valid) {
      row.removeAttribute('data-invalid');
    } else {
      row.setAttribute('data-invalid', 'true');
    }

    return amount;
  }

  function updateForm(form) {
    var total = 0;
    var rows = form.querySelectorAll('[data-utility-row]');

    for (var i = 0; i < rows.length; i++) {
      var amount = updateRow(rows[i]);

      if (amount !== null) {
        total += amount;
      }
    }

    var totalCell = form.querySelector('[data-utility-total]');

    if (totalCell) {
      totalCell.textContent = formatCents(total);
    }
  }

  function init() {
    var forms = document.querySelectorAll('form[data-utility-run]');

    for (var i = 0; i < forms.length; i++) {
      (function (form) {
        updateForm(form);
        form.addEventListener('input', function () { updateForm(form); });
        form.addEventListener('change', function () { updateForm(form); });
      })(forms[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
