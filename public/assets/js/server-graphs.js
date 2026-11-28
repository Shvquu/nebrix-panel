/*
 * Resource graphs of a server (CPU, memory, network, disk I/O).
 * Reads the JSON endpoint named in data-url of [data-server-graphs] and draws
 * small SVG line charts – no external libraries, no inline styles (CSP).
 */
(function () {
  'use strict';

  var SVG = 'http://www.w3.org/2000/svg';
  var W = 600;
  var H = 220;
  var PAD = { left: 64, right: 12, top: 12, bottom: 28 };

  var root = document.querySelector('[data-server-graphs]');
  if (!root) {
    return;
  }
  var labels = {};
  try {
    labels = JSON.parse(root.getAttribute('data-labels') || '{}');
  } catch (e) {
    labels = {};
  }
  var status = root.querySelector('[data-graph-status]');

  function el(name, attrs, parent) {
    var node = document.createElementNS(SVG, name);
    Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, String(attrs[key])); });
    if (parent) { parent.appendChild(node); }
    return node;
  }

  function bytes(value, perSecond) {
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = 0;
    var v = Math.abs(value);
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    var text = v >= 100 || i === 0 ? v.toFixed(0) : v.toFixed(1).replace(/\.0$/, '');
    return text + ' ' + units[i] + (perSecond ? '/s' : '');
  }

  // Axis maximum for byte values: a round number of KB/MB/GB whose quarters are round too.
  function niceBytes(max) {
    if (!(max > 0)) { return 1024; }
    var unit = 1;
    while (max / unit >= 1000 && unit < Math.pow(1024, 4)) { unit *= 1024; }
    var steps = [1, 2, 4, 8, 10, 20, 40, 80, 100, 200, 400, 800, 1000];
    for (var i = 0; i < steps.length; i++) {
      if (steps[i] * unit >= max) { return steps[i] * unit; }
    }
    return 1024 * unit;
  }

  function pad2(n) { return n < 10 ? '0' + n : String(n); }

  function timeLabel(t, timeframe) {
    var d = new Date(t * 1000);
    if (timeframe === 'hour' || timeframe === 'day') {
      return pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }
    return pad2(d.getDate()) + '.' + pad2(d.getMonth() + 1) + '.';
  }

  function niceMax(max) {
    if (!(max > 0)) { return 1; }
    var exp = Math.pow(10, Math.floor(Math.log10(max)));
    var steps = [1, 2, 2.5, 5, 10];
    for (var i = 0; i < steps.length; i++) {
      if (steps[i] * exp >= max) { return steps[i] * exp; }
    }
    return 10 * exp;
  }

  // series: [{key, label, cls, dashed}] ; format(value) for axis/tooltip
  function draw(container, points, timeframe, series, format, fixedMax, scale) {
    container.textContent = '';
    var values = [];
    points.forEach(function (p) { series.forEach(function (s) { if (typeof p[s.key] === 'number') { values.push(p[s.key]); } }); });
    if (points.length < 2 || values.length === 0) {
      var empty = document.createElement('p');
      empty.className = 'muted small';
      empty.textContent = labels.empty || 'No data.';
      container.appendChild(empty);
      return;
    }
    var max = fixedMax || (scale || niceMax)(Math.max.apply(null, values));
    var t0 = points[0].t;
    var t1 = points[points.length - 1].t;
    var x = function (t) { return PAD.left + (t - t0) / Math.max(1, t1 - t0) * (W - PAD.left - PAD.right); };
    var y = function (v) { return H - PAD.bottom - (v / max) * (H - PAD.top - PAD.bottom); };

    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'graph-svg', focusable: 'false' }, container);
    // Recessive grid and axis labels.
    for (var i = 0; i <= 4; i++) {
      var v = max * i / 4;
      el('line', { x1: PAD.left, x2: W - PAD.right, y1: y(v), y2: y(v), class: i === 0 ? 'graph-axis' : 'graph-grid' }, svg);
      el('text', { x: PAD.left - 8, y: y(v) + 4, class: 'graph-label graph-label-y' }, svg).textContent = format(v);
    }
    for (var j = 0; j <= 4; j++) {
      var t = t0 + (t1 - t0) * j / 4;
      el('text', { x: x(t), y: H - 8, class: 'graph-label graph-label-x' + (j === 0 ? ' is-first' : (j === 4 ? ' is-last' : '')) }, svg).textContent = timeLabel(t, timeframe);
    }

    // One path per series; gaps where Proxmox has no value.
    series.forEach(function (s) {
      var d = '';
      var pen = false;
      points.forEach(function (p) {
        if (typeof p[s.key] !== 'number') { pen = false; return; }
        d += (pen ? 'L' : 'M') + x(p.t).toFixed(1) + ' ' + y(Math.min(p[s.key], max)).toFixed(1) + ' ';
        pen = true;
      });
      if (d !== '') {
        el('path', { d: d, class: 'graph-line ' + s.cls + (s.dashed ? ' is-dashed' : '') }, svg);
      }
    });

    // Hover layer: crosshair, markers and a tooltip inside the SVG.
    var cross = el('line', { y1: PAD.top, y2: H - PAD.bottom, x1: 0, x2: 0, class: 'graph-cross is-hidden' }, svg);
    var dots = series.map(function (s) { return el('circle', { r: 4, cx: 0, cy: 0, class: 'graph-dot ' + s.cls + ' is-hidden' }, svg); });
    var tip = el('g', { class: 'graph-tip is-hidden' }, svg);
    var tipBox = el('rect', { x: 0, y: 0, width: 10, height: 10, rx: 6, class: 'graph-tip-box' }, tip);
    var tipLines = [el('text', { class: 'graph-tip-title' }, tip)].concat(series.map(function () { return el('text', { class: 'graph-tip-text' }, tip); }));
    var hit = el('rect', { x: PAD.left, y: PAD.top, width: W - PAD.left - PAD.right, height: H - PAD.top - PAD.bottom, class: 'graph-hit' }, svg);

    function hide() {
      [cross, tip].concat(dots).forEach(function (n) { n.classList.add('is-hidden'); });
    }
    function show(evt) {
      var box = svg.getBoundingClientRect();
      var px = (evt.clientX - box.left) / box.width * W;
      var best = points[0];
      points.forEach(function (p) { if (Math.abs(x(p.t) - px) < Math.abs(x(best.t) - px)) { best = p; } });
      var bx = x(best.t);
      cross.setAttribute('x1', bx); cross.setAttribute('x2', bx);
      cross.classList.remove('is-hidden');
      tipLines[0].textContent = new Date(best.t * 1000).toLocaleString();
      series.forEach(function (s, k) {
        var has = typeof best[s.key] === 'number';
        tipLines[k + 1].textContent = s.label + ': ' + (has ? format(best[s.key]) : '–');
        dots[k].setAttribute('cx', bx);
        dots[k].setAttribute('cy', has ? y(Math.min(best[s.key], max)) : -10);
        dots[k].classList.toggle('is-hidden', !has);
      });
      var width = 190;
      var height = 14 + tipLines.length * 16;
      var tx = bx + 12 + width > W ? bx - 12 - width : bx + 12;
      tipBox.setAttribute('x', tx); tipBox.setAttribute('y', PAD.top);
      tipBox.setAttribute('width', width); tipBox.setAttribute('height', height);
      tipLines.forEach(function (line, k) { line.setAttribute('x', tx + 10); line.setAttribute('y', PAD.top + 18 + k * 16); });
      tip.classList.remove('is-hidden');
    }
    hit.addEventListener('mousemove', show);
    hit.addEventListener('mouseleave', hide);

    if (series.length > 1) {
      var legend = document.createElement('div');
      legend.className = 'graph-legend';
      series.forEach(function (s) {
        var item = document.createElement('span');
        var swatch = document.createElement('span');
        swatch.className = 'graph-swatch ' + s.cls + (s.dashed ? ' is-dashed' : '');
        item.appendChild(swatch);
        item.appendChild(document.createTextNode(s.label));
        legend.appendChild(item);
      });
      container.appendChild(legend);
    }

    // Latest values as text (also for screen readers).
    var last = points[points.length - 1];
    var summary = document.createElement('p');
    summary.className = 'small muted graph-summary';
    summary.textContent = series.map(function (s) { return s.label + ': ' + (typeof last[s.key] === 'number' ? format(last[s.key]) : '–'); }).join(' · ');
    container.appendChild(summary);
  }

  function render(data) {
    var points = (data && Array.isArray(data.points)) ? data.points : [];
    var tf = data.timeframe;
    var maxMem = 0;
    points.forEach(function (p) { if (typeof p.maxmem === 'number' && p.maxmem > maxMem) { maxMem = p.maxmem; } });
    var graphs = {
      cpu: [[{ key: 'cpu', label: labels.cpu || 'CPU', cls: 'series-1' }], function (v) { return (v === 0 || v >= 10 ? v.toFixed(0) : v.toFixed(1)) + ' %'; }, 100],
      memory: [[{ key: 'mem', label: labels.used || 'used', cls: 'series-1' }, { key: 'maxmem', label: labels.total || 'total', cls: 'series-muted', dashed: true }], function (v) { return bytes(v, false); }, maxMem > 0 ? niceBytes(maxMem) : 0],
      network: [[{ key: 'netin', label: labels.in || 'in', cls: 'series-1' }, { key: 'netout', label: labels.out || 'out', cls: 'series-2' }], function (v) { return bytes(v, true); }, 0],
      disk: [[{ key: 'diskread', label: labels.read || 'read', cls: 'series-1' }, { key: 'diskwrite', label: labels.write || 'write', cls: 'series-2' }], function (v) { return bytes(v, true); }, 0]
    };
    Object.keys(graphs).forEach(function (name) {
      var container = root.querySelector('[data-graph="' + name + '"]');
      if (container) {
        draw(container, points, tf, graphs[name][0], graphs[name][1], graphs[name][2], name === 'cpu' ? niceMax : niceBytes);
      }
    });
  }

  function load(timeframe) {
    if (status) { status.textContent = labels.loading || 'Loading …'; status.classList.remove('is-hidden'); }
    var url = root.getAttribute('data-url') + '?timeframe=' + encodeURIComponent(timeframe);
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) { throw new Error('HTTP ' + response.status); }
        return response.json();
      })
      .then(function (data) {
        render(data);
        if (status) { status.classList.add('is-hidden'); }
      })
      .catch(function () {
        if (status) { status.textContent = labels.failed || 'The graph data could not be loaded.'; }
      });
  }

  root.querySelectorAll('[data-timeframe]').forEach(function (button) {
    button.addEventListener('click', function () {
      root.querySelectorAll('[data-timeframe]').forEach(function (other) {
        other.classList.toggle('is-active', other === button);
        other.setAttribute('aria-selected', other === button ? 'true' : 'false');
      });
      load(button.getAttribute('data-timeframe'));
    });
  });
  var first = root.querySelector('[data-timeframe]');
  load(first ? first.getAttribute('data-timeframe') : 'hour');
}());
