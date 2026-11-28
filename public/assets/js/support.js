/*
 * Nebrix – support enhancements (strict CSP: no inline scripts, data
 * attributes only, text is inserted with textContent / value).
 *  - Ticket form: help articles matching the subject (GET /help/suggest).
 *  - Admin ticket reply: insert a canned response into the reply box.
 */
(function () {
  'use strict';

  // Help article suggestions: <input data-help-suggest="/help/suggest"> + <div data-help-suggestions><ul></ul></div>
  document.querySelectorAll('input[data-help-suggest]').forEach(function (input) {
    var url = input.getAttribute('data-help-suggest');
    var box = document.querySelector('[data-help-suggestions]');
    if (!url || !box) { return; }
    var list = box.querySelector('ul');
    var timer = null;
    var last = '';
    var render = function (articles) {
      while (list.firstChild) { list.removeChild(list.firstChild); }
      articles.forEach(function (article) {
        if (typeof article.url !== 'string' || article.url.charAt(0) !== '/' || article.url.charAt(1) === '/') { return; }
        var item = document.createElement('li');
        var link = document.createElement('a');
        link.href = article.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = String(article.title || '');
        item.appendChild(link);
        if (article.category) {
          var category = document.createElement('span');
          category.className = 'small muted';
          category.textContent = ' · ' + String(article.category);
          item.appendChild(category);
        }
        list.appendChild(item);
      });
      box.classList.toggle('hidden', list.children.length === 0);
    };
    var lookup = function () {
      var query = input.value.trim();
      if (query === last) { return; }
      last = query;
      if (query.length < 3) { render([]); return; }
      fetch(url + '?q=' + encodeURIComponent(query), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (response) { return response.ok ? response.json() : { articles: [] }; })
        .then(function (data) { if (input.value.trim() === query) { render(Array.isArray(data.articles) ? data.articles : []); } })
        .catch(function () { render([]); });
    };
    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(lookup, 350);
    });
    if (input.value.trim().length >= 3) { lookup(); }
  });

  // Canned responses: <div data-canned-target="textarea-id"><select data-canned-select><option data-canned-body="…">
  document.querySelectorAll('[data-canned-target]').forEach(function (picker) {
    var select = picker.querySelector('[data-canned-select]');
    var target = document.getElementById(picker.getAttribute('data-canned-target'));
    if (!select || !target) { return; }
    select.addEventListener('change', function () {
      var option = select.options[select.selectedIndex];
      var text = option ? option.getAttribute('data-canned-body') : null;
      if (text) {
        var start = typeof target.selectionStart === 'number' ? target.selectionStart : target.value.length;
        var end = typeof target.selectionEnd === 'number' ? target.selectionEnd : target.value.length;
        target.value = target.value.slice(0, start) + text + target.value.slice(end);
        target.focus();
        target.selectionStart = target.selectionEnd = start + text.length;
      }
      select.selectedIndex = 0;
    });
  });
})();
