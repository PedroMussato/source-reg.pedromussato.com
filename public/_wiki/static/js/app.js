/* Interactions only. Nothing here is authoritative: all decisions stay on the server. */
(function () {
  'use strict';
  var doc = document, root = doc.documentElement;
  function store(get, key, val) {
    try { return get ? localStorage.getItem(key) : localStorage.setItem(key, val); } catch (e) { return null; }
  }

  /* ---- Theme ---- */
  var themeBtn = doc.getElementById('theme-toggle');
  if (themeBtn) themeBtn.addEventListener('click', function () {
    var cur = root.getAttribute('data-theme') ||
      (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    var next = cur === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    store(false, 'wiki-theme', next);
  });

  /* ---- Mobile drawer (focus management, Escape, scroll lock) ---- */
  var menuBtn = doc.getElementById('menu-toggle'), sidebar = doc.getElementById('sidebar'), scrim = doc.getElementById('scrim');
  function drawerOpen() { return doc.body.classList.contains('drawer-open'); }
  function setDrawer(open) {
    if (!sidebar || !menuBtn) return;
    doc.body.classList.toggle('drawer-open', open);
    menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (scrim) scrim.hidden = !open;
    if (open) {
      var first = sidebar.querySelector('a, summary');
      if (first) first.focus();
    } else {
      menuBtn.focus();
    }
  }
  if (menuBtn) menuBtn.addEventListener('click', function () { setDrawer(!drawerOpen()); });
  if (scrim) scrim.addEventListener('click', function () { setDrawer(false); });
  doc.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && drawerOpen()) setDrawer(false);
  });
  window.matchMedia('(min-width: 821px)').addEventListener('change', function (m) {
    if (m.matches && drawerOpen()) { doc.body.classList.remove('drawer-open'); if (scrim) scrim.hidden = true; }
  });

  /* ---- Sidebar expansion state (harmless UI preference only) ---- */
  var open = {};
  try { open = JSON.parse(store(true, 'wiki-nav-open') || '{}') || {}; } catch (e) { open = {}; }
  doc.querySelectorAll('.sidebar details[data-path]').forEach(function (d) {
    var p = d.getAttribute('data-path');
    if (!d.open && open[p]) d.open = true;
    d.addEventListener('toggle', function () {
      if (d.open) open[p] = 1; else delete open[p];
      store(false, 'wiki-nav-open', JSON.stringify(open));
    });
  });
  var cur = doc.querySelector('.sidebar .current');
  if (cur && cur.scrollIntoView) cur.scrollIntoView({ block: 'nearest' });

  /* ---- Rendered content: tables, code blocks, highlighting ---- */
  var article = doc.getElementById('wiki-article');
  if (article) {
    article.querySelectorAll('table').forEach(function (t) {
      var w = doc.createElement('div'); w.className = 'table-wrap';
      t.parentNode.insertBefore(w, t); w.appendChild(t);
    });
    article.querySelectorAll('pre').forEach(function (pre) {
      var code = pre.querySelector('code');
      var wrap = doc.createElement('div'); wrap.className = 'code-block';
      pre.parentNode.insertBefore(wrap, pre); wrap.appendChild(pre);
      var btn = doc.createElement('button');
      btn.type = 'button'; btn.className = 'copy-btn'; btn.textContent = 'Copy';
      btn.setAttribute('aria-label', 'Copy code to clipboard');
      btn.addEventListener('click', function () {
        var text = (code || pre).textContent || '';
        function done(ok) {
          btn.textContent = ok ? 'Copied' : 'Press Ctrl+C';
          setTimeout(function () { btn.textContent = 'Copy'; }, 1600);
        }
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
        } else {
          var ta = doc.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
          doc.body.appendChild(ta); ta.select();
          var ok = false; try { ok = doc.execCommand('copy'); } catch (e) { /* ignore */ }
          doc.body.removeChild(ta); done(ok);
        }
      });
      wrap.appendChild(btn);
      if (code && window.hljs && /(^|\s)language-/.test(code.className)) {
        try { window.hljs.highlightElement(code); } catch (e) { /* unsupported language: plain text fallback */ }
      }
    });
  }

  /* ---- Table of contents scroll-spy ---- */
  var toc = doc.getElementById('toc');
  if (toc && 'IntersectionObserver' in window) {
    var links = {};
    toc.querySelectorAll('a[href^="#"]').forEach(function (a) { links[decodeURIComponent(a.getAttribute('href').slice(1))] = a; });
    var heads = Object.keys(links).map(function (id) { return doc.getElementById(id); }).filter(Boolean);
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          Object.keys(links).forEach(function (k) { links[k].classList.remove('active'); });
          if (links[en.target.id]) links[en.target.id].classList.add('active');
        }
      });
    }, { rootMargin: '-70px 0px -70% 0px' });
    heads.forEach(function (h) { io.observe(h); });
  }

  /* ---- Local time for the footer + modest status polling (read-only; never triggers Git) ---- */
  var synced = doc.getElementById('synced-at');
  if (synced) {
    var d = new Date(synced.getAttribute('datetime'));
    if (!isNaN(d)) synced.textContent = d.toLocaleString();
  }
  var indicator = doc.getElementById('sync-indicator');
  if (indicator && window.fetch) {
    var delay = 60000, timer = null;
    var poll = function () {
      if (doc.hidden) { timer = setTimeout(poll, delay); return; }
      fetch('/_wiki/status', { headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (s) {
          if (s) { indicator.hidden = !s.syncing; delay = s.syncing ? 10000 : 120000; }
        })
        .catch(function () { delay = 300000; })
        .then(function () { timer = setTimeout(poll, delay); });
    };
    timer = setTimeout(poll, 5000);
  }
})();
