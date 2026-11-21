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
  // Disembark's own notices (quiet mode) get their own heading, so they
  // don't read as coming from another plugin.
  var ownList = h('div', { class: 'dsk-tray-list' }), otherList = h('div', { class: 'dsk-tray-list' });
  var ownSec = h('div', { class: 'dsk-tray-sec' }, h('span', { class: 'caps' }, 'From Disembark'), ownList);
  var otherSec = h('div', { class: 'dsk-tray-sec' }, h('span', { class: 'caps' }, 'From WordPress & other plugins'), otherList);
  var tray = h('div', { class: 'dsk-tray', id: 'dsk-tray', role: 'region', 'aria-label': 'Notices', hidden: true },
    h('div', { class: 'dsk-tray-head' },
      h('strong', null, 'Notices'),
      h('button', { class: 'dsk-btn ghost icon sm', type: 'button', 'aria-label': 'Close notices', onclick: function () { openTray(false); } }, D.icon('x'))),
    ownSec, otherSec);
  function isOwn(n) { return n.classList.contains('disembark-notice') || /wp disembark quiet-mode|Disembark quiet mode|Quiet mode:/.test(n.textContent || ''); }
  var trayBtn = h('button', { class: 'dsk-btn ghost sm dsk-tray-btn', type: 'button', 'aria-expanded': 'false', 'aria-controls': 'dsk-tray', hidden: true, onclick: function () { openTray(tray.hidden); } });
  function shown(n) { return !n.classList.contains('hidden') && n.style.display !== 'none'; }
  function refreshTray() {
    var own = Array.prototype.filter.call(ownList.children, shown), other = Array.prototype.filter.call(otherList.children, shown);
    var items = own.concat(other);
    ownSec.hidden = !own.length; otherSec.hidden = !other.length;
    trayBtn.hidden = !items.length;
    if (!items.length) openTray(false);
    trayBtn.classList.toggle('has-error', items.some(function (n) { return n.matches('.notice-error, div.error'); }));
    D.mount(trayBtn, D.icon('bell'), h('span', { class: 'lbl' }, items.length + (items.length === 1 ? ' notice' : ' notices')), h('span', { class: 'n' }, String(items.length)));
    trayBtn.setAttribute('aria-label', items.length + (items.length === 1 ? ' notice' : ' notices'));
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
    found.forEach(function (n) { (isOwn(n) ? ownList : otherList).appendChild(n); });
    refreshTray();
  }
  /**
   * Quiet mode went off (Settings, or a rollback that lifted it): its admin
   * bar item and notice, printed when the page loaded, go now rather than on
   * the next load.
   */
  D.quietOff = function () {
    var bar = document.getElementById('wp-admin-bar-disembark-quiet-mode');
    if (bar) bar.remove();
    Array.prototype.slice.call(ownList.children).forEach(function (n) { if (/Quiet mode/i.test(n.textContent || '')) n.remove(); });
    refreshTray();
  };
  [ownList, otherList].forEach(function (l) { new MutationObserver(refreshTray).observe(l, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style'] }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !tray.hidden) { openTray(false); trayBtn.focus(); } });
  document.addEventListener('click', function (e) { if (!tray.hidden && !tray.contains(e.target) && !trayBtn.contains(e.target)) openTray(false); });

  /* ---------------------------------------------------------------- Shell */
  // Four tabs. Sections register with D.section({ id, title, icon, tab: n }),
  // n being the tab's place. Older addresses (#pull, #files, ...) still work:
  // they open the tab that now holds them, and the section's onRoute(hint)
  // hears which one was asked for.
  var ALIAS = { backup: 'backups', restore: 'move', push: 'move', pull: 'move', bring: 'move', files: 'browse', database: 'browse' };
  var tabs = D.sections.filter(function (s) { return s.tab; }).sort(function (a, b) { return a.tab - b.tab; });
  var nav = h('nav', { class: 'dsk-tabs', 'aria-label': 'Disembark' });
  var main = h('main', { class: 'dsk-page' });
  var panes = {};
  tabs.forEach(function (s) {
    nav.appendChild(h('a', { href: '#' + s.id, 'data-id': s.id }, D.icon(s.icon), s.title));
    var pane = h('div', { class: 'dsk-section', id: 'dsk-section-' + s.id, hidden: true });
    panes[s.id] = { def: s, el: pane, mounted: false };
    main.appendChild(pane);
  });

  function hereNote() {
    var inst = D.config.install;
    if (!inst) return '';
    var net = inst.kind === 'multisite';
    if (inst.current === inst.main) return (net ? 'network' : 'Freighter') + ' · ' + D.fmt.plural(inst.total || (inst.sites || []).length, 'site');
    return (net ? 'subsite ' : 'tenant ') + inst.current;
  }
  var note = hereNote();
  D.mount(root, h('div', { class: 'dsk-shell' },
    h('header', { class: 'dsk-dh' }, h('div', { class: 'dsk-dh-in' },
      h('a', { class: 'dsk-brand', href: '#' + (tabs[0] ? tabs[0].id : 'move'), 'aria-label': 'Disembark: back to ' + (tabs[0] ? tabs[0].title : 'Move') }, D.mark(30), h('span', { class: 'dsk-word' }, 'Disembark')),
      nav,
      h('span', { class: 'dsk-site', title: 'This site: ' + D.config.homeUrl + (note ? ' · ' + note : '') }, h('i'), h('span', { class: 'host' }, D.host(D.config.homeUrl)), note ? h('small', null, '· ' + note) : null),
      h('div', { class: 'dsk-top-tools' },
        trayBtn, tray,
        h('button', { class: 'dsk-btn ghost icon', id: 'dsk-theme', type: 'button', 'aria-label': 'Switch between light and dark', onclick: function () {
          D.setTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        } })))),
    main));
  applyTheme();
  // The brand goes back to the first tab even when it's already showing
  // (the hash wouldn't change), so its section can reset to its start.
  root.querySelector('.dsk-brand').addEventListener('click', function (e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey) return;
    var first = tabs[0] && panes[tabs[0].id];
    if (first && (location.hash || '#' + first.def.id) === '#' + first.def.id) {
      e.preventDefault();
      if (first.def.onHome) first.def.onHome();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  });

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
    var asked = (location.hash || '').replace('#', '').split('/')[0], id = asked;
    if (!panes[id] && ALIAS[id]) id = ALIAS[id];
    if (!panes[id]) id = tabs[0].id;
    Object.keys(panes).forEach(function (k) {
      var p = panes[k], on = k === id;
      p.el.hidden = !on;
      nav.querySelector('[data-id="' + k + '"]').setAttribute('aria-current', on ? 'page' : 'false');
      if (on && !p.mounted) { p.mounted = true; p.def.mount(p.el); }
      if (on && asked !== id && p.def.onRoute) p.def.onRoute(asked);
      if (on && p.def.onShow) p.def.onShow();
    });
    document.title = panes[id].def.title + ' ‹ ' + baseTitle;
  }
  var baseTitle = document.title;
  window.addEventListener('hashchange', show);
  show();
  D.loadStatus();
})();
