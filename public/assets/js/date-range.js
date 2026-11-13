/* ==========================================================================
   MtaaPass date-range picker (progressive enhancement)
   --------------------------------------------------------------------------
   Turns every `input[type=date]` / `input[type=datetime-local]` pair marked
   with data-date-range / data-date-range-part into a two-month range calendar
   rendered as a popover. The native inputs stay in the DOM (and in the form) so
   every filter and form still works with JavaScript disabled; this script only
   replaces the browser's per-field picker with one shared range calendar.

   Markup contract (views):
     <input type="date" id="report_from" name="from"
            data-date-range="billing-report" data-date-range-part="from">
     <input type="date" id="report_to" name="to"
            data-date-range="billing-report" data-date-range-part="to">

   Optional attributes on the first input of the pair:
     data-date-range-label="Report period"  dialog aria-label
     data-date-range-min="2026-01-01"        first selectable day
     data-date-range-max="2026-12-31"        last selectable day

   Vanilla JS only, no build step, no inline handlers, CSP-safe.
   ========================================================================== */

(function () {
  'use strict';

  var M = (window.MtaaPass = window.MtaaPass || {});

  var MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
  ];
  var WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var WEEKDAYS_SHORT = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

  var uid = 0;

  /* ------------------------------------------------------------------ *
   * Date helpers (local dates at noon to stay clear of DST edges)
   * ------------------------------------------------------------------ */

  function pad(value) {
    return (value < 10 ? '0' : '') + value;
  }

  function toIso(date) {
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
  }

  function parseIso(value) {
    var match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
    if (!match) {
      return null;
    }
    var year = parseInt(match[1], 10);
    var month = parseInt(match[2], 10) - 1;
    var day = parseInt(match[3], 10);
    var date = new Date(year, month, day, 12, 0, 0, 0);
    if (date.getFullYear() !== year || date.getMonth() !== month || date.getDate() !== day) {
      return null;
    }
    return date;
  }

  function timePart(value) {
    var match = /T(\d{2}:\d{2})/.exec(String(value || ''));
    return match ? match[1] : '';
  }

  function addDays(date, days) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate() + days, 12, 0, 0, 0);
  }

  function addMonths(date, months) {
    return new Date(date.getFullYear(), date.getMonth() + months, 1, 12, 0, 0, 0);
  }

  function startOfMonth(date) {
    return new Date(date.getFullYear(), date.getMonth(), 1, 12, 0, 0, 0);
  }

  function sameDay(a, b) {
    return !!a && !!b &&
      a.getFullYear() === b.getFullYear() &&
      a.getMonth() === b.getMonth() &&
      a.getDate() === b.getDate();
  }

  function formatDate(date) {
    return date ? date.getDate() + ' ' + MONTHS[date.getMonth()].slice(0, 3) + ' ' + date.getFullYear() : '';
  }

  function formatLong(date) {
    return WEEKDAYS[date.getDay()] + ' ' + date.getDate() + ' ' + MONTHS[date.getMonth()] + ' ' + date.getFullYear();
  }

  function monthTitle(date) {
    return MONTHS[date.getMonth()] + ' ' + date.getFullYear();
  }

  /* ------------------------------------------------------------------ *
   * Tiny DOM helpers
   * ------------------------------------------------------------------ */

  function el(tag, className) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    return node;
  }

  function icon(pathData, className) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.8');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.setAttribute('class', className || 'ico ico--sm');
    pathData.forEach(function (d) {
      var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', d);
      svg.appendChild(path);
    });
    return svg;
  }

  function insertAfter(reference, node) {
    if (reference.parentNode) {
      reference.parentNode.insertBefore(node, reference.nextSibling);
    }
  }

  function fire(input) {
    try {
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (error) {
      /* Old browsers without the Event constructor: value changes are enough. */
    }
  }

  function listen(target, type, handler) {
    if (target) {
      target.addEventListener(type, handler);
    }
  }

  /* ------------------------------------------------------------------ *
   * Picker
   * ------------------------------------------------------------------ */

  function Picker(name, fromInput, toInput) {
    this.name = name;
    this.fromInput = fromInput;
    this.toInput = toInput;
    this.datetime = fromInput.type === 'datetime-local' || toInput.type === 'datetime-local';
    this.fromTime = timePart(fromInput.value) || '00:00';
    this.toTime = timePart(toInput.value) || '23:59';
    this.start = parseIso(fromInput.value);
    this.end = parseIso(toInput.value);
    this.today = (function () {
      var now = new Date();
      return new Date(now.getFullYear(), now.getMonth(), now.getDate(), 12, 0, 0, 0);
    })();
    this.view = startOfMonth(this.start || this.today);
    this.focusDate = this.start || this.today;
    this.hoverDate = null;
    this.open = false;
    this.panel = null;
    this.anchor = null;
    this.monthsContainer = null;
    this.timeFrom = null;
    this.timeTo = null;
    var minIso = fromInput.getAttribute('data-date-range-min') || toInput.getAttribute('data-date-range-min');
    var maxIso = fromInput.getAttribute('data-date-range-max') || toInput.getAttribute('data-date-range-max');
    this.min = parseIso(minIso);
    this.max = parseIso(maxIso);
    this.label = fromInput.getAttribute('data-date-range-label') || 'Select date range';
    this.inline = fromInput.hasAttribute('data-date-range-inline') || toInput.hasAttribute('data-date-range-inline');
    this.inlineHost = null;
    this.fromTrigger = null;
    this.toTrigger = null;
    this.id = 'date-range-' + (++uid);
    this.init();
  }

  Picker.prototype.init = function () {
    var self = this;
    if (this.inline) {
      this.initInline();
      return;
    }
    this.fromTrigger = this.makeTrigger('from');
    this.toTrigger = this.makeTrigger('to');
    this.relabel(this.fromInput, this.fromTrigger);
    this.relabel(this.toInput, this.toTrigger);
    this.fromInput.classList.add('date-range__native');
    this.toInput.classList.add('date-range__native');
    this.fromInput.setAttribute('tabindex', '-1');
    this.toInput.setAttribute('tabindex', '-1');
    this.updateTriggers();
    [this.fromTrigger, this.toTrigger].forEach(function (trigger) {
      listen(trigger, 'click', function (event) {
        event.preventDefault();
        self.toggle(trigger);
      });
      listen(trigger, 'keydown', function (event) {
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          self.openPanel(trigger);
        }
      });
    });
    listen(document, 'mousedown', function (event) {
      if (!self.open) {
        return;
      }
      if (self.panel && self.panel.contains(event.target)) {
        return;
      }
      if (self.fromTrigger.contains(event.target) || self.toTrigger.contains(event.target)) {
        return;
      }
      self.closePanel(false);
    });
    listen(document, 'keydown', function (event) {
      if (self.open && (event.key === 'Escape' || event.key === 'Esc')) {
        event.preventDefault();
        self.closePanel(true);
      }
    });
    listen(window, 'resize', function () {
      if (self.open) {
        self.position();
      }
    });
    listen(window, 'scroll', function () {
      if (self.open) {
        self.position();
      }
    }, true);
  };

  /**
   * Inline (always-open) mode: the two-month calendar replaces the two date
   * trigger boxes and renders directly in the form, so a pass validity window is
   * chosen with one tap on the start day and one on the end day. The native
   * inputs remain in the form (and are the no-JS fallback); fields that already
   * carry a server validation error stay visible so the message is readable.
   */
  Picker.prototype.initInline = function () {
    var self = this;
    var host = document.querySelector('[data-date-range-inline-host="' + this.name + '"]');

    if (!host) {
      host = el('div', 'date-range__inline-host');
      var grid = this.toInput.closest ? this.toInput.closest('.field-grid') : null;
      if (grid && grid.parentNode) {
        grid.parentNode.insertBefore(host, grid);
      } else {
        insertAfter(this.toInput, host);
      }
    }
    this.inlineHost = host;

    [this.fromInput, this.toInput].forEach(function (input) {
      input.classList.add('date-range__native');
      // Hidden required controls cannot be focused by native constraint
      // validation, so the inline mode relies on the server-side rules instead.
      input.removeAttribute('required');
      var field = input.closest ? input.closest('.field') : null;
      if (field && !field.querySelector('.field__error')) {
        field.classList.add('date-range__field--native');
      }
    });

    this.buildPanel();
    this.panel.classList.add('date-range__popover--inline');
    this.panel.hidden = false;
    this.panel.setAttribute('role', 'group');
    this.panel.removeAttribute('aria-modal');
    this.open = true;

    var caption = this.panel.querySelector('.date-range__caption');
    if (caption) {
      caption.textContent = 'Tap start and end day';
    }

    // Inline mode needs no "Done" button: every selection applies immediately.
    var done = this.panel.querySelector('[data-date-range-action="done"]');
    if (done && done.parentNode) {
      done.parentNode.removeChild(done);
    }

    if (this.panel.parentNode) {
      this.panel.parentNode.removeChild(this.panel);
    }
    host.appendChild(this.panel);
    this.render();
  };

  Picker.prototype.makeTrigger = function (part) {
    var trigger = el('button', 'date-range__trigger btn btn--outline');
    trigger.type = 'button';
    trigger.id = this.id + '-' + part;
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.appendChild(icon(['M8 3.2v4M16 3.2v4M3.6 10.4h16.8'], 'ico ico--sm'));
    var value = el('span', 'date-range__value');
    value.id = trigger.id + '-value';
    trigger.appendChild(value);
    insertAfter(part === 'from' ? this.fromInput : this.toInput, trigger);
    return trigger;
  };

  Picker.prototype.relabel = function (input, trigger) {
    if (!input.id) {
      input.id = this.id + '-native-' + input.name;
    }
    var label = document.querySelector('label[for="' + input.id.replace(/"/g, '\\"') + '"]');
    if (label) {
      label.setAttribute('for', trigger.id);
    }
    var hint = document.getElementById(input.id + '-hint');
    if (hint) {
      hint.id = trigger.id + '-hint';
      trigger.setAttribute('aria-describedby', hint.id);
    }
  };

  Picker.prototype.valueText = function (part) {
    var date = part === 'from' ? this.start : this.end;
    if (!date) {
      return part === 'from' ? 'Any date' : 'Any date';
    }
    if (!this.datetime) {
      return formatDate(date);
    }
    var time = part === 'from' ? this.fromTime : this.toTime;
    return formatDate(date) + ', ' + time;
  };

  Picker.prototype.updateTriggers = function () {
    if (!this.fromTrigger || !this.toTrigger) {
      return;
    }
    this.fromTrigger.querySelector('.date-range__value').textContent = this.valueText('from');
    this.toTrigger.querySelector('.date-range__value').textContent = this.valueText('to');
    this.fromTrigger.setAttribute('aria-label', 'From date: ' + this.valueText('from'));
    this.toTrigger.setAttribute('aria-label', 'Until date: ' + this.valueText('to'));
  };

  Picker.prototype.toggle = function (trigger) {
    if (this.open) {
      this.closePanel(true);
    } else {
      this.openPanel(trigger);
    }
  };

  Picker.prototype.openPanel = function (trigger) {
    if (!this.panel) {
      this.buildPanel();
    }
    this.anchor = trigger;
    this.view = startOfMonth(this.start || this.today);
    this.focusDate = this.start || this.today;
    this.hoverDate = null;
    this.panel.hidden = false;
    this.open = true;
    this.fromTrigger.setAttribute('aria-expanded', 'true');
    this.toTrigger.setAttribute('aria-expanded', 'true');
    this.render();
    this.position();
    this.focusDay(this.focusDate);
  };

  Picker.prototype.closePanel = function (returnFocus) {
    if (!this.panel) {
      return;
    }
    this.panel.hidden = true;
    this.open = false;
    this.hoverDate = null;
    this.fromTrigger.setAttribute('aria-expanded', 'false');
    this.toTrigger.setAttribute('aria-expanded', 'false');
    if (returnFocus && this.anchor) {
      this.anchor.focus();
    }
  };

  /* ------------------------------------------------------------------ *
   * Popover construction
   * ------------------------------------------------------------------ */

  Picker.prototype.buildPanel = function () {
    var self = this;
    var panel = el('div', 'date-range__popover');
    panel.id = this.id + '-dialog';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-label', this.label);
    panel.hidden = true;

    var head = el('div', 'date-range__head');
    var prev = el('button', 'date-range__nav');
    prev.type = 'button';
    prev.setAttribute('data-date-range-action', 'prev');
    prev.setAttribute('aria-label', 'Previous months');
    prev.appendChild(icon(['m14.4 6-6 6 6 6']));
    var next = el('button', 'date-range__nav');
    next.type = 'button';
    next.setAttribute('data-date-range-action', 'next');
    next.setAttribute('aria-label', 'Next months');
    next.appendChild(icon(['m9.5 6 6 6-6 6']));
    var caption = el('p', 'date-range__caption');
    caption.textContent = 'Two-month view';
    head.appendChild(prev);
    head.appendChild(caption);
    head.appendChild(next);

    var months = el('div', 'date-range__months');
    this.monthsContainer = months;

    var foot = el('div', 'date-range__foot');
    if (this.datetime) {
      var times = el('div', 'date-range__times');
      var fromLabel = el('label', 'date-range__time-label');
      fromLabel.textContent = 'From time';
      this.timeFrom = el('input', 'input date-range__time');
      this.timeFrom.type = 'time';
      this.timeFrom.value = this.fromTime;
      fromLabel.setAttribute('for', this.id + '-time-from');
      this.timeFrom.id = this.id + '-time-from';
      var toLabel = el('label', 'date-range__time-label');
      toLabel.textContent = 'Until time';
      this.timeTo = el('input', 'input date-range__time');
      this.timeTo.type = 'time';
      this.timeTo.value = this.toTime;
      toLabel.setAttribute('for', this.id + '-time-to');
      this.timeTo.id = this.id + '-time-to';
      times.appendChild(fromLabel);
      times.appendChild(this.timeFrom);
      times.appendChild(toLabel);
      times.appendChild(this.timeTo);
      listen(this.timeFrom, 'change', function () {
        self.fromTime = self.timeFrom.value || '00:00';
        self.writeValues();
      });
      listen(this.timeTo, 'change', function () {
        self.toTime = self.timeTo.value || '23:59';
        self.writeValues();
      });
      foot.appendChild(times);
    }

    var actions = el('div', 'date-range__actions');
    var clear = el('button', 'btn btn--ghost btn--sm');
    clear.type = 'button';
    clear.textContent = 'Clear';
    clear.setAttribute('data-date-range-action', 'clear');
    var done = el('button', 'btn btn--outline btn--sm');
    done.type = 'button';
    done.textContent = 'Done';
    done.setAttribute('data-date-range-action', 'done');
    actions.appendChild(clear);
    actions.appendChild(done);
    foot.appendChild(actions);

    panel.appendChild(head);
    panel.appendChild(months);
    panel.appendChild(foot);

    listen(panel, 'click', function (event) {
      var target = event.target.closest('[data-date-range-action]');
      if (!target) {
        return;
      }
      var action = target.getAttribute('data-date-range-action');
      if (action === 'prev' || action === 'next') {
        event.preventDefault();
        self.view = addMonths(self.view, action === 'prev' ? -1 : 1);
        self.render();
        self.focusDay(self.focusDate);
      } else if (action === 'clear') {
        event.preventDefault();
        self.start = null;
        self.end = null;
        self.writeValues();
        self.updateTriggers();
        self.render();
        self.focusDay(self.focusDate);
      } else if (action === 'done') {
        event.preventDefault();
        self.closePanel(true);
      } else if (action === 'day') {
        event.preventDefault();
        self.choose(target.getAttribute('data-date-range-iso'), target);
      }
    });

    listen(panel, 'mouseover', function (event) {
      var target = event.target.closest('[data-date-range-action="day"]');
      if (target) {
        self.hoverDate = parseIso(target.getAttribute('data-date-range-iso'));
        self.paintRange();
      }
    });

    listen(panel, 'keydown', function (event) {
      if (self.handleKey(event)) {
        event.preventDefault();
      }
    });

    document.body.appendChild(panel);
    this.panel = panel;
  };

  /* ------------------------------------------------------------------ *
   * Rendering
   * ------------------------------------------------------------------ */

  Picker.prototype.render = function () {
    if (!this.monthsContainer) {
      return;
    }
    this.monthsContainer.textContent = '';
    this.monthsContainer.appendChild(this.renderMonth(this.view));
    this.monthsContainer.appendChild(this.renderMonth(addMonths(this.view, 1)));
    this.paintRange();
  };

  Picker.prototype.renderMonth = function (firstOfMonth) {
    var self = this;
    var table = el('table', 'date-range__month');
    table.setAttribute('role', 'grid');
    var captionId = this.id + '-month-' + toIso(firstOfMonth);
    table.setAttribute('aria-labelledby', captionId);

    var caption = el('caption', 'date-range__month-title');
    caption.id = captionId;
    caption.textContent = monthTitle(firstOfMonth);
    table.appendChild(caption);

    var thead = el('thead');
    var headRow = el('tr');
    WEEKDAYS_SHORT.forEach(function (label, index) {
      var th = el('th');
      th.scope = 'col';
      th.abbr = WEEKDAYS[index];
      th.textContent = label;
      headRow.appendChild(th);
    });
    thead.appendChild(headRow);
    table.appendChild(thead);

    var tbody = el('tbody');
    var firstWeekday = firstOfMonth.getDay();
    var cellDate = addDays(firstOfMonth, -firstWeekday);
    for (var week = 0; week < 6; week++) {
      var row = el('tr');
      for (var day = 0; day < 7; day++) {
        var cell = el('td');
        cell.setAttribute('role', 'gridcell');
        if (cellDate.getMonth() === firstOfMonth.getMonth()) {
          var button = el('button', 'date-range__day');
          button.type = 'button';
          button.setAttribute('data-date-range-action', 'day');
          button.setAttribute('data-date-range-iso', toIso(cellDate));
          button.setAttribute('aria-label', formatLong(cellDate));
          button.textContent = String(cellDate.getDate());
          button.className += this.classNameFor(cellDate);
          cell.appendChild(button);
        }
        row.appendChild(cell);
        cellDate = addDays(cellDate, 1);
      }
      tbody.appendChild(row);
    }
    table.appendChild(tbody);
    return table;
  };

  Picker.prototype.classNameFor = function (date) {
    var classes = '';
    if (sameDay(date, this.today)) {
      classes += ' is-today';
    }
    if (this.min && date < this.min) {
      return classes + ' is-disabled';
    }
    if (this.max && date > this.max) {
      return classes + ' is-disabled';
    }
    return classes;
  };

  Picker.prototype.rangeBounds = function () {
    var start = this.start;
    var end = this.end;
    if (start && !end && this.hoverDate) {
      end = this.hoverDate;
    }
    if (start && end && end < start) {
      var swap = start;
      start = end;
      end = swap;
    }
    return { start: start, end: end };
  };

  Picker.prototype.paintRange = function () {
    if (!this.panel) {
      return;
    }
    var bounds = this.rangeBounds();
    var dayButtons = this.panel.querySelectorAll('[data-date-range-action="day"]');
    for (var i = 0; i < dayButtons.length; i++) {
      var button = dayButtons[i];
      var date = parseIso(button.getAttribute('data-date-range-iso'));
      if (!date) {
        continue;
      }
      var isStart = sameDay(date, bounds.start);
      var isEnd = sameDay(date, bounds.end);
      var inRange = bounds.start && bounds.end && date > bounds.start && date < bounds.end;
      button.classList.toggle('is-start', isStart);
      button.classList.toggle('is-end', isEnd);
      button.classList.toggle('is-in-range', !!inRange);
      button.setAttribute('aria-selected', isStart || isEnd ? 'true' : 'false');
      var disabled = this.min && date < this.min || this.max && date > this.max;
      button.disabled = !!disabled;
      button.setAttribute('tabindex', sameDay(date, this.focusDate) ? '0' : '-1');
    }
  };

  /* ------------------------------------------------------------------ *
   * Selection + values
   * ------------------------------------------------------------------ */

  Picker.prototype.choose = function (iso) {
    var date = parseIso(iso);
    if (!date) {
      return;
    }
    if (this.min && date < this.min || this.max && date > this.max) {
      return;
    }
    if (!this.start || this.start && this.end) {
      this.start = date;
      this.end = null;
    } else {
      this.end = date;
      if (this.end < this.start) {
        var swap = this.start;
        this.start = this.end;
        this.end = swap;
      }
    }
    this.focusDate = date;
    this.writeValues();
    this.updateTriggers();
    this.render();
    // Inline calendars stay on screen after the end date is chosen so the
    // resident can adjust the range or times without reopening anything.
    if (this.start && this.end && !this.inline) {
      this.closePanel(true);
      this.anchor = this.toTrigger;
    }
  };

  Picker.prototype.writeValues = function () {
    var fromValue = this.start ? toIso(this.start) + (this.datetime ? 'T' + this.fromTime : '') : '';
    var toValue = this.end ? toIso(this.end) + (this.datetime ? 'T' + this.toTime : '') : '';
    if (this.fromInput.value !== fromValue) {
      this.fromInput.value = fromValue;
      fire(this.fromInput);
    }
    if (this.toInput.value !== toValue) {
      this.toInput.value = toValue;
      fire(this.toInput);
    }
    this.updateTriggers();
  };

  /* ------------------------------------------------------------------ *
   * Focus + keyboard
   * ------------------------------------------------------------------ */

  Picker.prototype.focusDay = function (date) {
    if (!this.panel || !date) {
      return;
    }
    var iso = toIso(date);
    var button = this.panel.querySelector('[data-date-range-iso="' + iso + '"]');
    if (!button) {
      return;
    }
    this.focusDate = date;
    var buttons = this.panel.querySelectorAll('[data-date-range-action="day"]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].setAttribute('tabindex', buttons[i] === button ? '0' : '-1');
    }
    if (this.open) {
      button.focus();
    }
  };

  Picker.prototype.moveFocus = function (days) {
    var next = addDays(this.focusDate, days);
    if (next < this.view || next >= addMonths(this.view, 2)) {
      this.view = startOfMonth(next);
      this.render();
    }
    this.focusDay(next);
  };

  Picker.prototype.handleKey = function (event) {
    var key = event.key;
    if (!this.open) {
      return false;
    }
    if (key === 'ArrowLeft') {
      this.moveFocus(-1);
      return true;
    }
    if (key === 'ArrowRight') {
      this.moveFocus(1);
      return true;
    }
    if (key === 'ArrowUp') {
      this.moveFocus(-7);
      return true;
    }
    if (key === 'ArrowDown') {
      this.moveFocus(7);
      return true;
    }
    if (key === 'Home') {
      this.moveFocus(-this.focusDate.getDay());
      return true;
    }
    if (key === 'End') {
      this.moveFocus(6 - this.focusDate.getDay());
      return true;
    }
    if (key === 'PageUp') {
      this.view = addMonths(this.view, -1);
      this.render();
      this.focusDay(startOfMonth(addMonths(this.focusDate, -1)));
      return true;
    }
    if (key === 'PageDown') {
      this.view = addMonths(this.view, 1);
      this.render();
      this.focusDay(startOfMonth(addMonths(this.focusDate, 1)));
      return true;
    }
    return false;
  };

  /* ------------------------------------------------------------------ *
   * Positioning
   * ------------------------------------------------------------------ */

  Picker.prototype.position = function () {
    if (!this.panel || !this.anchor) {
      return;
    }
    var rect = this.anchor.getBoundingClientRect();
    var panelRect = this.panel.getBoundingClientRect();
    var width = panelRect.width || 620;
    var height = panelRect.height || 420;
    var gap = 8;
    var left = rect.left;
    if (left + width > window.innerWidth - 8) {
      left = Math.max(8, window.innerWidth - width - 8);
    }
    var top = rect.bottom + gap;
    if (top + height > window.innerHeight - 8) {
      top = Math.max(8, rect.top - gap - height);
    }
    this.panel.style.left = Math.round(left) + 'px';
    this.panel.style.top = Math.round(top) + 'px';
  };

  /* ------------------------------------------------------------------ *
   * Boot
   * ------------------------------------------------------------------ */

  function collectGroups() {
    var groups = {};
    var inputs = document.querySelectorAll('input[data-date-range][data-date-range-part]');
    for (var i = 0; i < inputs.length; i++) {
      var input = inputs[i];
      var name = input.getAttribute('data-date-range');
      var part = input.getAttribute('data-date-range-part');
      if (!name || (part !== 'from' && part !== 'to')) {
        continue;
      }
      if (!groups[name]) {
        groups[name] = {};
      }
      groups[name][part] = input;
    }
    return groups;
  }

  function init() {
    var groups = collectGroups();
    Object.keys(groups).forEach(function (name) {
      var group = groups[name];
      if (!group.from || !group.to || group.from === group.to) {
        return;
      }
      if (group.from.getAttribute('data-date-range-ready') === '1') {
        return;
      }
      group.from.setAttribute('data-date-range-ready', '1');
      new Picker(name, group.from, group.to);
    });
  }

  M.dateRange = { init: init };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
