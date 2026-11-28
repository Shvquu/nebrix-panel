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

  // Configurator price preview: base + steps × step price (net), promotion and VAT rounded half up like the server.
  var roundShare = function (amount, bp) { return bp > 0 ? Math.floor((amount * bp + 5000) / 10000) : 0; };
  document.querySelectorAll('[data-configurator]').forEach(function (form) {
    var output = form.querySelector('[data-price]');
    if (!output) { return; }
    var base = parseInt(form.getAttribute('data-base'), 10) || 0;
    var reference = parseInt(form.getAttribute('data-reference'), 10) || base;
    var rate = parseInt(form.getAttribute('data-rate'), 10) || 0;
    var promo = parseInt(form.getAttribute('data-promo'), 10) || 0;
    var format;
    try {
      format = new Intl.NumberFormat(form.getAttribute('data-locale') || 'en', { style: 'currency', currency: form.getAttribute('data-currency') || 'EUR' });
    } catch (e) {
      format = { format: function (value) { return value.toFixed(2); } };
    }
    var money = function (minor) { return format.format(minor / 100); };
    var gross = function (net) { return net + roundShare(net, rate); };
    var ranges = form.querySelectorAll('input[type="range"][data-step-price]');
    var saving = form.querySelector('[data-saving]');
    var savingBox = form.querySelector('[data-saving-box]');
    var was = form.querySelector('[data-was]');
    var wasBox = form.querySelector('[data-was-box]');
    var update = function () {
      var extra = 0;
      ranges.forEach(function (range) {
        var min = parseInt(range.min, 10) || 0;
        var step = parseInt(range.step, 10) || 1;
        var steps = Math.floor(((parseInt(range.value, 10) || min) - min) / step);
        extra += steps * (parseInt(range.getAttribute('data-step-price'), 10) || 0);
        var item = range.getAttribute('data-item') ? document.querySelector(range.getAttribute('data-item')) : null;
        if (item && item.getAttribute('data-template')) {
          item.textContent = item.getAttribute('data-template').replace('{n}', range.value);
        }
      });
      var list = base + extra;
      var price = gross(list - roundShare(list, promo));
      output.textContent = money(price);
      if (promo > 0) {
        var previous = gross(reference + extra);
        var show = previous > price;
        if (was) { was.textContent = money(previous); }
        if (saving) { saving.textContent = money(previous - price); }
        if (wasBox) { wasBox.classList.toggle('hidden', !show); }
        if (savingBox) { savingBox.classList.toggle('hidden', !show); }
      }
    };
    ranges.forEach(function (range) { range.addEventListener('input', update); });
    update();
  });

  // Tables: filter (<input data-filter="#table">) and pages (<table data-paginate="10"> + <div data-pager-for="#table">)
  var tables = {};
  var renderTable = function (table) {
    var state = tables[table.id];
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
    var visible = rows.filter(function (row) { return row.getAttribute('data-filtered') !== '1'; });
    var size = parseInt(table.getAttribute('data-paginate'), 10) || 0;
    if (size <= 0) {
      rows.forEach(function (row) { row.classList.toggle('hidden', row.getAttribute('data-filtered') === '1'); });
      return;
    }
    var pages = Math.max(1, Math.ceil(visible.length / size));
    state.page = Math.min(Math.max(1, state.page), pages);
    rows.forEach(function (row) { row.classList.add('hidden'); });
    visible.slice((state.page - 1) * size, state.page * size).forEach(function (row) { row.classList.remove('hidden'); });
    var pager = document.querySelector('[data-pager-for="#' + table.id + '"]');
    if (!pager) { return; }
    pager.textContent = '';
    var info = document.createElement('span');
    info.className = 'site-pager-info';
    var from = visible.length === 0 ? 0 : (state.page - 1) * size + 1;
    info.textContent = (pager.getAttribute('data-label-info') || ':from – :to / :total')
      .replace(':from', from).replace(':to', Math.min(state.page * size, visible.length)).replace(':total', visible.length.toLocaleString());
    pager.appendChild(info);
    var nav = document.createElement('div');
    nav.className = 'site-pager-buttons';
    var button = function (label, page, active, disabled) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = label;
      if (active) { b.className = 'is-active'; }
      b.disabled = !!disabled;
      b.addEventListener('click', function () { state.page = page; renderTable(table); });
      nav.appendChild(b);
    };
    button(pager.getAttribute('data-label-prev') || '‹', state.page - 1, false, state.page <= 1);
    var shown = {};
    [1, state.page - 1, state.page, state.page + 1, pages].forEach(function (page) {
      if (page < 1 || page > pages || shown[page]) { return; }
      if (page > 1 && !shown[page - 1] && page !== 2) {
        var gap = document.createElement('span');
        gap.textContent = '…';
        nav.appendChild(gap);
      }
      shown[page] = true;
      button(String(page), page, page === state.page, false);
    });
    button(pager.getAttribute('data-label-next') || '›', state.page + 1, false, state.page >= pages);
    pager.appendChild(nav);
  };
  document.querySelectorAll('table[id]').forEach(function (table) {
    tables[table.id] = { page: 1 };
    if (table.hasAttribute('data-paginate')) { renderTable(table); }
  });
  document.querySelectorAll('input[data-filter]').forEach(function (input) {
    var table = document.querySelector(input.getAttribute('data-filter'));
    if (!table || !tables[table.id]) { return; }
    input.addEventListener('input', function () {
      var query = input.value.trim().toLowerCase().replace(/^\.+/, '');
      table.querySelectorAll('[data-filter-text]').forEach(function (row) {
        var match = query === '' || row.getAttribute('data-filter-text').indexOf(query) !== -1;
        row.setAttribute('data-filtered', match ? '0' : '1');
      });
      tables[table.id].page = 1;
      renderTable(table);
    });
  });

  // Submenus of the product menu open by click too (touch devices)
  document.querySelectorAll('.site-sub-toggle').forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.stopPropagation();
      var sub = button.parentElement;
      var open = !sub.classList.contains('is-open');
      sub.parentElement.querySelectorAll('.site-sub.is-open').forEach(function (other) { other.classList.remove('is-open'); });
      sub.classList.toggle('is-open', open);
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
