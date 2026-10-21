/* Header search: debounced, keyboard-driven, built with safe DOM APIs only (no innerHTML). */
(function () {
  'use strict';
  var input = document.getElementById('search-input'), box = document.getElementById('search-results');
  if (!input || !box) return;
  input.disabled = false;
  var timer = null, seq = 0, items = [], active = -1, ctrl = null;

  function clear() { while (box.firstChild) box.removeChild(box.firstChild); }
  function show(open) {
    box.hidden = !open;
    input.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!open) { active = -1; input.removeAttribute('aria-activedescendant'); }
  }
  function status(text) {
    clear(); items = []; active = -1;
    var d = document.createElement('div'); d.className = 'sr-status'; d.textContent = text; d.setAttribute('role', 'status');
    box.appendChild(d); show(true);
  }
  function terms(q) {
    return (q.match(/"[^"]+"|\S+/g) || []).map(function (t) { return t.replace(/"/g, '').toLowerCase(); }).filter(Boolean);
  }
  /* Highlight matches by splitting text into text nodes and <mark> elements. */
  function appendHighlighted(parent, text, ts) {
    if (!ts.length) { parent.appendChild(document.createTextNode(text)); return; }
    var lower = text.toLowerCase(), i = 0;
    while (i < text.length) {
      var best = -1, bl = 0;
      ts.forEach(function (t) {
        var p = lower.indexOf(t, i);
        if (p !== -1 && (best === -1 || p < best)) { best = p; bl = t.length; }
      });
      if (best === -1) { parent.appendChild(document.createTextNode(text.slice(i))); break; }
      if (best > i) parent.appendChild(document.createTextNode(text.slice(i, best)));
      var m = document.createElement('mark'); m.textContent = text.slice(best, best + bl); parent.appendChild(m);
      i = best + bl;
    }
  }
  function render(data, q) {
    clear(); items = []; active = -1;
    if (!data.results.length) { status('No results for “' + q + '”.'); return; }
    var ts = terms(q);
    data.results.forEach(function (r, idx) {
      var a = document.createElement('a');
      a.className = 'sr-item'; a.href = r.url; a.id = 'sr-' + idx; a.setAttribute('role', 'option'); a.setAttribute('aria-selected', 'false');
      var t = document.createElement('span'); t.className = 'sr-title'; appendHighlighted(t, r.title, ts);
      var p = document.createElement('span'); p.className = 'sr-path'; p.textContent = r.path;
      a.appendChild(t); a.appendChild(p);
      if (r.excerpt) { var x = document.createElement('span'); x.className = 'sr-excerpt'; appendHighlighted(x, r.excerpt, ts); a.appendChild(x); }
      box.appendChild(a); items.push(a);
    });
    show(true);
  }
  function setActive(i) {
    if (!items.length) return;
    if (active >= 0) items[active].setAttribute('aria-selected', 'false');
    active = (i + items.length) % items.length;
    items[active].setAttribute('aria-selected', 'true');
    input.setAttribute('aria-activedescendant', items[active].id);
    items[active].scrollIntoView({ block: 'nearest' });
  }
  function run() {
    var q = input.value.trim();
    if (q.length < 2) { show(false); return; }
    var my = ++seq;
    if (ctrl && ctrl.abort) ctrl.abort();
    ctrl = window.AbortController ? new AbortController() : null;
    status('Searching…');
    fetch('/_wiki/search?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (my !== seq) return;
        if (!res.ok) { status(res.j && res.j.message ? res.j.message : 'Search is unavailable right now.'); return; }
        render(res.j, q);
      })
      .catch(function (e) { if (e && e.name === 'AbortError') return; if (my === seq) status('Search is unavailable right now.'); });
  }

  input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 200); });
  input.addEventListener('focus', function () { if (items.length) show(true); });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); if (box.hidden && items.length) show(true); setActive(active + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
    else if (e.key === 'Enter') {
      if (active >= 0) { e.preventDefault(); window.location.href = items[active].href; }
      else if (items.length) { e.preventDefault(); window.location.href = items[0].href; }
    } else if (e.key === 'Escape') { show(false); input.blur(); }
  });
  document.addEventListener('click', function (e) {
    if (!document.getElementById('search').contains(e.target)) show(false);
  });
  document.addEventListener('keydown', function (e) {
    var tag = (e.target && e.target.tagName) || '';
    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (e.target && e.target.isContentEditable);
    if ((e.key === '/' && !typing && !e.ctrlKey && !e.metaKey) || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) {
      e.preventDefault(); input.focus(); input.select();
    }
  });
  document.querySelectorAll('[data-open-search]').forEach(function (b) {
    b.addEventListener('click', function () { input.focus(); });
  });
})();
