/**
 * App shell: header, section navigation (hash routing), and the theme.
 * Sections register themselves with D.section() and mount on first visit.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h;
  var root = document.getElementById('disembark-app');
  if (!root) return;

  /* ---------------------------------------------------------------- Theme */
  var KEY = 'disembark-theme';
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  D.themePref = function () {
    try { var v = localStorage.getItem(KEY); return v === 'light' || v === 'dark' ? v : 'system'; } catch (e) { return 'system'; }
  };
  function applyTheme() {
    var pref = D.themePref(), dark = pref === 'dark' || (pref === 'system' && mq && mq.matches);
    root.setAttribute('data-theme', dark ? 'dark' : 'light');
    document.body.classList.toggle('dsk-dark', dark);
    var btn = root.querySelector('#dsk-theme');
    if (btn) D.mount(btn, D.icon(dark ? 'sun' : 'moon'));
  }
  D.setTheme = function (pref) {
    try { if (pref === 'system') localStorage.removeItem(KEY); else localStorage.setItem(KEY, pref); } catch (e) { /* private mode */ }
    applyTheme();
  };
  if (mq && mq.addEventListener) mq.addEventListener('change', function () { if (D.themePref() === 'system') applyTheme(); });

  /* -------------------------------------------------------- Admin notices */
  // WordPress and other plugins print admin notices on every screen. Gather
  // them into a tray in the header so they don't push the dashboard down. The
  // notices are moved, not copied, so their buttons and dismiss links still
  // work. Core moves notices around once the page is ready, so gather after
  // that, and again for any that arrive late.
  var NOTICE = '.notice, div.updated, div.error, .update-nag';
  var trayList = h('div', { class: 'dsk-tray-list' });
  var tray = h('div', { class: 'dsk-tray', id: 'dsk-tray', role: 'region', 'aria-label': 'Notices from WordPress and other plugins', hidden: true },
    h('div', { class: 'dsk-tray-head' },
      h('span', { class: 'caps' }, 'From WordPress & other plugins'),
      h('button', { class: 'dsk-btn ghost icon sm', type: 'button', 'aria-label': 'Close notices', onclick: function () { openTray(false); } }, D.icon('x'))),
    trayList);
  var trayBtn = h('button', { class: 'dsk-btn ghost sm dsk-tray-btn', type: 'button', 'aria-expanded': 'false', 'aria-controls': 'dsk-tray', hidden: true, onclick: function () { openTray(tray.hidden); } });
  function shown(n) { return !n.classList.contains('hidden') && n.style.display !== 'none'; }
  function refreshTray() {
    var items = Array.prototype.filter.call(trayList.children, shown);
    trayBtn.hidden = !items.length;
    if (!items.length) openTray(false);
    trayBtn.classList.toggle('has-error', items.some(function (n) { return n.matches('.notice-error, div.error'); }));
    D.mount(trayBtn, D.icon('bell'), items.length + (items.length === 1 ? ' notice' : ' notices'));
  }
  function openTray(on) {
    tray.hidden = !on;
    trayBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
  }
  function gather() {
    var body = document.getElementById('wpbody-content');
    if (!body) return;
    var found = Array.prototype.filter.call(body.querySelectorAll(NOTICE), function (n) {
      return !root.contains(n) && !(n.parentElement && n.parentElement.closest(NOTICE));
    });
    if (!found.length) return;
    found.forEach(function (n) { trayList.appendChild(n); });
    refreshTray();
  }
  new MutationObserver(refreshTray).observe(trayList, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style'] });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !tray.hidden) { openTray(false); trayBtn.focus(); } });
  document.addEventListener('click', function (e) { if (!tray.hidden && !tray.contains(e.target) && !trayBtn.contains(e.target)) openTray(false); });

  /* ---------------------------------------------------------------- Shell */
  var BRAND = '<svg viewBox="0 0 30 30" aria-hidden="true"><circle cx="15" cy="15" r="14" fill="#c8553d"/><path d="M8.5 16.5h13l-2 4.3Q15 22.6 10.6 20.6Z" fill="#fff8f3"/><path d="M10.6 16.5v-4.6h8.8v4.6" fill="none" stroke="#fff8f3" stroke-width="1.6"/><path d="M15 11.9V7.6" stroke="#fff8f3" stroke-width="1.6" stroke-linecap="round"/></svg>';
  var nav = h('nav', { class: 'dsk-nav', 'aria-label': 'Disembark sections' });
  var main = h('main', { class: 'dsk-main' });
  var panes = {};

  D.sections.forEach(function (s) {
    nav.appendChild(h('a', { href: '#' + s.id, 'data-id': s.id }, D.icon(s.icon), s.title));
    var pane = h('div', { class: 'dsk-section', id: 'dsk-section-' + s.id, hidden: true });
    panes[s.id] = { def: s, el: pane, mounted: false };
    main.appendChild(pane);
  });
  nav.appendChild(h('div', { class: 'dsk-nav-foot' }, 'Moves run through the ', h('a', { href: '#settings' }, 'Disembark CLI'), ' or straight from site to site.'));

  D.mount(root, h('div', { class: 'dsk-shell' },
    h('header', { class: 'dsk-top' },
      h('a', { class: 'dsk-brand', href: '#backup' }, D.svg(BRAND), 'Disembark'),
      h('span', { class: 'dsk-site', title: D.config.homeUrl }, h('i'), D.host(D.config.homeUrl)),
      h('div', { class: 'dsk-top-tools' },
        trayBtn, tray,
        h('span', { class: 'dsk-ver' }, 'v' + D.config.version),
        h('button', { class: 'dsk-btn ghost icon', id: 'dsk-theme', type: 'button', 'aria-label': 'Switch between light and dark', onclick: function () {
          D.setTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        } }))),
    nav, main));
  applyTheme();

  if (window.jQuery) window.jQuery(function () { setTimeout(gather, 0); }); else gather();
  window.addEventListener('load', gather);
  var wpbody = document.getElementById('wpbody-content');
  if (wpbody) {
    var late = new MutationObserver(function (list) {
      if (list.some(function (m) { return Array.prototype.some.call(m.addedNodes, function (n) { return n.nodeType === 1 && !root.contains(n) && (n.matches(NOTICE) || n.querySelector(NOTICE)); }); })) gather();
    });
    late.observe(wpbody, { childList: true });
    if (root.parentElement && root.parentElement !== wpbody) late.observe(root.parentElement, { childList: true });
  }

  /* --------------------------------------------------------------- Router */
  function show() {
    var id = (location.hash || '').replace('#', '');
    if (!panes[id]) id = D.sections[0].id;
    Object.keys(panes).forEach(function (k) {
      var p = panes[k], on = k === id;
      p.el.hidden = !on;
      nav.querySelector('[data-id="' + k + '"]').setAttribute('aria-current', on ? 'page' : 'false');
      if (on && !p.mounted) { p.mounted = true; p.def.mount(p.el); }
      if (on && p.def.onShow) p.def.onShow();
    });
    document.title = panes[id].def.title + ' ‹ ' + baseTitle;
  }
  var baseTitle = document.title;
  window.addEventListener('hashchange', show);
  show();
  D.loadStatus();
})();
