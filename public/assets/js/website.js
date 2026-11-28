/*
 * Nebrix – public website. No inline scripts (strict CSP); behaviour is
 * attached through data attributes. Prices shown here are previews: the
 * order page calculates the binding price on the server.
 */
(function () {
  'use strict';

  // Mobile menu
  document.querySelectorAll('[data-site-menu]').forEach(function (button) {
    button.addEventListener('click', function () {
      var menu = document.getElementById(button.getAttribute('aria-controls'));
      if (!menu) { return; }
      var open = menu.classList.toggle('is-open');
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  // Dropdowns also open by click (touch devices, keyboard)
  document.querySelectorAll('.site-dropdown > button').forEach(function (button) {
    button.setAttribute('aria-expanded', 'false');
    button.addEventListener('click', function () {
      var dropdown = button.parentElement;
      var open = !dropdown.classList.contains('is-open');
      document.querySelectorAll('.site-dropdown.is-open').forEach(function (other) {
        other.classList.remove('is-open');
        other.querySelector('button').setAttribute('aria-expanded', 'false');
      });
      dropdown.classList.toggle('is-open', open);
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  document.addEventListener('click', function (event) {
    if (!event.target.closest('.site-dropdown')) {
      document.querySelectorAll('.site-dropdown.is-open').forEach(function (dropdown) {
        dropdown.classList.remove('is-open');
        dropdown.querySelector('button').setAttribute('aria-expanded', 'false');
      });
    }
  });

  // Tabs: packages | configurator
  document.querySelectorAll('[data-site-tabs]').forEach(function (list) {
    var tabs = list.querySelectorAll('[data-site-tab]');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (other) {
          var active = other === tab;
          other.classList.toggle('is-active', active);
          other.setAttribute('aria-selected', active ? 'true' : 'false');
          var panel = document.getElementById(other.getAttribute('data-site-tab'));
          if (panel) { panel.classList.toggle('hidden', !active); }
        });
      });
    });
  });

  // Slider values
  document.querySelectorAll('input[type="range"][data-output]').forEach(function (range) {
    var target = document.querySelector(range.getAttribute('data-output'));
    if (!target) { return; }
    var update = function () { target.textContent = range.value + (range.getAttribute('data-unit') || ''); };
    range.addEventListener('input', update);
    update();
  });

  // Configurator price preview: base + steps × step price (net), VAT rounded half up like the server.
  document.querySelectorAll('[data-configurator]').forEach(function (form) {
    var output = form.querySelector('[data-price]');
    if (!output) { return; }
    var base = parseInt(form.getAttribute('data-base'), 10) || 0;
    var rate = parseInt(form.getAttribute('data-rate'), 10) || 0;
    var format;
    try {
      format = new Intl.NumberFormat(form.getAttribute('data-locale') || 'en', { style: 'currency', currency: form.getAttribute('data-currency') || 'EUR' });
    } catch (e) {
      format = { format: function (value) { return value.toFixed(2); } };
    }
    var ranges = form.querySelectorAll('input[type="range"][data-step-price]');
    var update = function () {
      var net = base;
      ranges.forEach(function (range) {
        var min = parseInt(range.min, 10) || 0;
        var step = parseInt(range.step, 10) || 1;
        var steps = Math.floor(((parseInt(range.value, 10) || min) - min) / step);
        net += steps * (parseInt(range.getAttribute('data-step-price'), 10) || 0);
      });
      var tax = rate > 0 ? Math.floor((net * rate + 5000) / 10000) : 0;
      output.textContent = format.format((net + tax) / 100);
    };
    ranges.forEach(function (range) { range.addEventListener('input', update); });
    update();
  });

  // Table filter: <input data-filter="#table"> on rows with data-filter-text
  document.querySelectorAll('input[data-filter]').forEach(function (input) {
    var table = document.querySelector(input.getAttribute('data-filter'));
    if (!table) { return; }
    var rows = table.querySelectorAll('[data-filter-text]');
    input.addEventListener('input', function () {
      var query = input.value.trim().toLowerCase().replace(/^\.+/, '');
      rows.forEach(function (row) {
        row.classList.toggle('hidden', query !== '' && row.getAttribute('data-filter-text').indexOf(query) === -1);
      });
    });
  });

  // Trustpilot TrustBox: loaded only after the visitor clicks (no data goes to Trustpilot before).
  document.querySelectorAll('[data-trustpilot]').forEach(function (box) {
    var button = box.querySelector('[data-trustpilot-load]');
    if (!button) { return; }
    button.addEventListener('click', function () {
      var widget = document.createElement('div');
      widget.className = 'trustpilot-widget';
      widget.setAttribute('data-locale', box.getAttribute('data-locale'));
      widget.setAttribute('data-template-id', box.getAttribute('data-template'));
      widget.setAttribute('data-businessunit-id', box.getAttribute('data-business-unit'));
      widget.setAttribute('data-style-height', '150px');
      widget.setAttribute('data-style-width', '100%');
      widget.setAttribute('data-theme', 'dark');
      var link = document.createElement('a');
      link.href = box.getAttribute('data-review-url');
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = 'Trustpilot';
      widget.appendChild(link);
      box.textContent = '';
      box.appendChild(widget);
      box.classList.add('is-loaded');
      var script = document.createElement('script');
      script.src = 'https://widget.trustpilot.com/bootstrap/v5/tp.widget.bootstrap.min.js';
      script.async = true;
      script.addEventListener('load', function () {
        if (window.Trustpilot && typeof window.Trustpilot.loadFromElement === 'function') {
          window.Trustpilot.loadFromElement(widget, true);
        }
      });
      document.head.appendChild(script);
    });
  });
})();
