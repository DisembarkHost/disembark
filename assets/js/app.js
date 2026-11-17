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
        h('span', { class: 'dsk-ver' }, 'v' + D.config.version),
        h('button', { class: 'dsk-btn ghost icon', id: 'dsk-theme', type: 'button', 'aria-label': 'Switch between light and dark', onclick: function () {
          D.setTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        } }))),
    nav, main));
  applyTheme();

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
