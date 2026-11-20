/**
 * Disembark dashboard core: DOM helpers, icons, formatting, the REST client,
 * a tiny store, UI primitives (toast, modal, command blocks) and the shared
 * site scan used by Back up and Files. Everything hangs off window.Disembark.
 */
(function () {
  'use strict';

  var D = window.Disembark = window.Disembark || {};
  var cfg = D.config = Object.assign({
    apiRoot: '', nonce: '', token: '', homeUrl: '', loginUrl: '', userEmail: '', version: '', fileEdits: true, multisite: false
  }, window.DisembarkConfig || {});

  /* ------------------------------------------------------------------ DOM */

  /**
   * h('div', { class: 'x', onclick: fn }, 'text', child, [more]) → Element.
   * Strings become text nodes (never HTML); pass { html } only for markup we built.
   */
  /**
   * url when it is a web address (http or https), else null. Links built
   * from what another site reports (its home address) go through this, so a
   * javascript: or data: address can't become a link. loose also allows
   * blob: (local downloads) and mailto:, which h() permits on any href.
   */
  function safeHref(url, loose) {
    var protocol;
    try { protocol = new URL(String(url), location.href).protocol; } catch (e) { return null; }
    return (loose ? ['http:', 'https:', 'blob:', 'mailto:'] : ['http:', 'https:']).indexOf(protocol) !== -1 ? String(url) : null;
  }
  D.safeHref = function (url) { return safeHref(url, false); };

  function h(tag, attrs) {
    var el = document.createElement(tag);
    var kids = Array.prototype.slice.call(arguments, 2);
    if (attrs && (typeof attrs !== 'object' || attrs instanceof Node || Array.isArray(attrs))) {
      kids.unshift(attrs);
      attrs = null;
    }
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) return;
        if (k === 'class') el.className = Array.isArray(v) ? v.filter(Boolean).join(' ') : v;
        else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
        else if (k === 'dataset') Object.assign(el.dataset, v);
        else if (k === 'html') el.innerHTML = v;
        else if (k === 'text') el.textContent = v;
        else if (k.slice(0, 2) === 'on' && typeof v === 'function') el.addEventListener(k.slice(2), v);
        else if (k === 'value' || k === 'checked' || k === 'disabled' || k === 'selected' || k === 'hidden') el[k] = v;
        else if (k === 'href' && !safeHref(v, true)) return; // never a javascript: or data: link
        else el.setAttribute(k, v === true ? '' : v);
      });
    }
    append(el, kids);
    return el;
  }
  function append(el, kids) {
    kids.forEach(function (k) {
      if (k === null || k === undefined || k === false) return;
      if (Array.isArray(k)) append(el, k);
      else el.appendChild(k instanceof Node ? k : document.createTextNode(String(k)));
    });
    return el;
  }
  function mount(el) {
    while (el.firstChild) el.removeChild(el.firstChild);
    append(el, Array.prototype.slice.call(arguments, 1));
    return el;
  }
  D.h = h;
  D.mount = mount;

  /* ---------------------------------------------------------------- Icons */

  var ICONS = {
    backup: '<path d="M12 3v11"/><path d="m7.5 9.5 4.5 4.5 4.5-4.5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
    restore: '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/>',
    folder: '<path d="M3 7.5A1.5 1.5 0 0 1 4.5 6H9l2 2h8.5A1.5 1.5 0 0 1 21 9.5v8A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5z"/>',
    file: '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/>',
    code: '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="m10 12-2 2 2 2M14 12l2 2-2 2"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-9 9"/>',
    database: '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6"/><path d="M4.5 12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3"/>',
    table: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 10v10"/>',
    settings: '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    moon: '<path d="M20.5 13.5A8.5 8.5 0 1 1 10.5 3.5a6.5 6.5 0 0 0 10 10z"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    checkCircle: '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 3 3 5-6"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 6 8.5-6"/>',
    xCircle: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
    chevron: '<path d="m9 6 6 6-6 6"/>',
    download: '<path d="M12 4v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M5 20h14"/>',
    upload: '<path d="M12 20V9"/><path d="m7.5 13.5 4.5-4.5 4.5 4.5"/><path d="M5 4h14"/>',
    edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
    rename: '<path d="M4 7V5h10v2M9 5v14M7 19h4"/><path d="M15 12h5M15 16h5"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    refresh: '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
    terminal: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3M13 15h4"/>',
    link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    key: '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3M15 8l2 2"/>',
    alert: '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5h.01"/>',
    history: '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/>',
    scan: '<path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M7 12h10"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-5.5-3.5-9s1-6.5 3.5-9z"/>',
    zip: '<path d="M6 3h8l4 4v14H6z"/><path d="M11 3v2h2v2h-2v2h2v2h-2v2"/><rect x="10" y="14" width="4" height="4" rx="1"/>',
    play: '<path d="M7 5v14l11-7z"/>',
    arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    ship: '<path d="M3 15h18l-2.4 5.2Q12 22.4 5.4 20.2Z"/><path d="M6 15V9h12v6"/><path d="M12 9V4"/>',
    anchor: '<circle cx="12" cy="5" r="2"/><path d="M12 7v14M8 11h8"/><path d="M5 14c0 4 3 7 7 7s7-3 7-7"/>',
    bell: '<path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
    sparkle: '<path d="M12 3.5l1.9 5.1 5.1 1.9-5.1 1.9L12 17.5l-1.9-5.1L5 10.5l5.1-1.9z"/><path d="M18.5 15.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/>'
  };
  D.icon = function (name, cls) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('class', 'i' + (cls ? ' ' + cls : ''));
    svg.setAttribute('aria-hidden', 'true');
    svg.innerHTML = ICONS[name] || '';
    return svg;
  };
  D.svg = function (markup, cls) {
    var wrap = document.createElement('div');
    wrap.innerHTML = markup;
    var el = wrap.firstElementChild;
    if (cls) el.setAttribute('class', cls);
    return el;
  };

  /**
   * The brand: a freighter in a coral disc. The window is knocked out with a
   * mask so the disc shows through in both themes. Each call gets its own
   * mask id, since the mark can appear more than once on a page.
   */
  var markSeq = 0;
  D.mark = function (size) {
    var id = 'dsk-mark-' + (++markSeq), s = size || 30;
    return D.svg('<svg class="dsk-mark" viewBox="0 0 32 32" width="' + s + '" height="' + s + '" aria-hidden="true" focusable="false">' +
      '<defs><mask id="' + id + '" maskUnits="userSpaceOnUse" x="0" y="0" width="32" height="32"><rect width="32" height="32" fill="#fff"/><rect x="21.6" y="10.8" width="3.3" height="2.2" rx=".5" fill="#000"/></mask></defs>' +
      '<circle cx="16" cy="16" r="16" style="fill:var(--accent)"/>' +
      '<g transform="translate(16 16) scale(.66) translate(-16 -16)" style="fill:var(--accent-ink)"><g mask="url(#' + id + ')">' +
      '<path d="M2.5 19h27l-3.5 6.3q-.7 1.2-2 1.2H8q-1.3 0-2-1.2Z"/>' +
      '<rect x="4.5" y="12.5" width="6" height="5.5" rx=".8"/><rect x="11.5" y="12.5" width="6" height="5.5" rx=".8"/><rect x="8" y="5.5" width="6" height="6" rx=".8"/>' +
      '<rect x="20" y="9" width="6.5" height="10" rx="1"/><path d="M23.25 9V4.5" style="fill:none;stroke:var(--accent-ink);stroke-width:1.8;stroke-linecap:round"/>' +
      '</g></g></svg>');
  };

  /**
   * A page header: small caps label, a serif title (pass an array to put
   * part of it in the italic accent, e.g. ['Where’s it ', 'going?']),
   * a line of text, and optional tools on the right.
   * o: { caps, icon, title, text, side, live: 'run' | 'wait' | 'ok' }
   */
  D.pageHead = function (o) {
    var title = Array.isArray(o.title) ? [o.title[0], h('em', { class: 'hl' }, o.title[1]), o.title[2] || null] : o.title;
    return h('div', { class: 'dsk-ph' },
      h('div', { class: 'dsk-ph-main' },
        o.caps ? h('div', { class: 'caps' }, o.live ? h('span', { class: 'dsk-live ' + o.live }) : (o.icon ? D.icon(o.icon) : null), o.caps) : null,
        h('h1', null, title),
        o.text ? h('p', null, o.text) : null),
      o.side ? h('div', { class: 'dsk-ph-side' }, o.side) : null);
  };

  /* ----------------------------------------------------------- Formatting */

  D.fmt = {
    size: function (bytes) {
      bytes = Number(bytes) || 0;
      if (bytes < 1024) return bytes + ' B';
      var units = ['KB', 'MB', 'GB', 'TB', 'PB'], i = -1;
      do { bytes /= 1024; i++; } while (bytes >= 1024 && i < units.length - 1);
      return (bytes >= 100 ? bytes.toFixed(0) : bytes.toFixed(1)) + ' ' + units[i];
    },
    num: function (n) { return (Number(n) || 0).toLocaleString(); },
    date: function (ts) { return ts ? new Date(ts * 1000).toLocaleString() : ''; },
    ago: function (ts) {
      if (!ts) return '';
      var s = Math.floor(Date.now() / 1000) - ts;
      if (s < 60) return 'just now';
      var m = Math.floor(s / 60); if (m < 60) return m + 'm ago';
      var hrs = Math.floor(m / 60); if (hrs < 24) return hrs + 'h ago';
      return Math.floor(hrs / 24) + 'd ago';
    },
    plural: function (n, one, many) { return D.fmt.num(n) + ' ' + (Number(n) === 1 ? one : (many || one + 's')); }
  };

  /* ---------------------------------------------------------------- Store */

  var listeners = [];
  D.store = {
    state: {
      token: cfg.token,
      backupToken: '',
      site: '',              // one site of a network or WP Freighter install ('' = all of it)
      database: [],          // [{ table, size, row_count }]
      included: new Set(),   // table names included in a backup
      includeDb: true,
      includeFiles: true,
      files: [],             // [{ name, size }], sorted
      index: null,           // folder tree built from files
      excluded: new Set(),   // excluded file/folder paths
      scanned: false,
      lastScan: null,
      sessions: [],
      diskSize: 0
    },
    set: function (patch) {
      Object.assign(this.state, patch);
      var keys = Object.keys(patch);
      listeners.slice().forEach(function (l) {
        if (!l.keys || l.keys.some(function (k) { return keys.indexOf(k) !== -1; })) l.fn(D.store.state, keys);
      });
    },
    on: function (keys, fn) {
      var l = { keys: keys ? [].concat(keys) : null, fn: fn };
      listeners.push(l);
      return function () { listeners.splice(listeners.indexOf(l), 1); };
    },
    touch: function (key) { var p = {}; p[key] = this.state[key]; this.set(p); }
  };
  /**
   * A bundle of store subscriptions owned by one rendered view. dispose()
   * drops them all, so re-rendering a view never stacks old listeners.
   */
  D.scope = function () {
    var subs = [];
    return {
      on: function (keys, fn) { subs.push(D.store.on(keys, fn)); },
      dispose: function () { subs.splice(0).forEach(function (off) { off(); }); }
    };
  };

  /* ------------------------------------------------------------------ API */

  // A restore can bring a database with plain permalinks, after which
  // /wp-json/ no longer reaches the REST API (it answers with a page): the
  // ?rest_route= form always does, so requests switch to it.
  var apiRoot = cfg.apiRoot;
  function plainRoot() { return String(cfg.homeUrl).replace(/\/+$/, '') + '/?rest_route=/disembark/v1/'; }
  function apiUrl(path, query) {
    var root = apiRoot;
    var url = root + String(path).replace(/^\//, '');
    if (query) {
      var qs = Object.keys(query).filter(function (k) { return query[k] !== undefined && query[k] !== null; })
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]); }).join('&');
      if (qs) url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
    }
    return url;
  }
  function ApiError(message, status, data) { this.message = message; this.status = status; this.data = data; }
  ApiError.prototype = Object.create(Error.prototype);

  /**
   * Calls the Disembark REST API as the logged-in admin (cookie + nonce). The
   * site token is the fallback: a restore replaces the users table and ends
   * the session mid-flow, and the token keeps the remaining steps authorized.
   * It goes in a header, never in a URL (URLs land in access logs), and only
   * once the login stops working: the first request refused for want of a
   * login switches to it and is sent again.
   */
  var useToken = false;
  var LOGIN_ERRORS = ['rest_cookie_invalid_nonce', 'rest_forbidden', 'rest_not_logged_in'];
  async function request(method, path, opts) {
    opts = opts || {};
    var init = { method: method, headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' };
    if (useToken) init.headers['X-Disembark-Token'] = D.store.state.token;
    var query = Object.assign({}, opts.query || {});
    if (method === 'GET' || method === 'HEAD') {
      // nothing in the body
    } else if (opts.form) {
      init.body = opts.form;
    } else {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body || {});
    }
    var res, timer = null;
    // A paced step gives up on an answer that never comes (opts.timeout,
    // ms) and asks again; the server finishes or replays it (see Steps).
    if (opts.timeout && window.AbortController) {
      var ctl = new AbortController();
      init.signal = ctl.signal;
      timer = setTimeout(function () { ctl.abort(); }, opts.timeout);
    }
    try {
      res = await fetch(opts.url || apiUrl(path, query), init);
    } catch (e) {
      var err = new ApiError(init.signal && init.signal.aborted ? 'No answer from the site in time.' : 'Could not reach the site (' + e.message + ').', 0);
      err.timeout = !!(init.signal && init.signal.aborted);
      throw err;
    } finally { if (timer) clearTimeout(timer); }
    if (!useToken && (res.status === 401 || res.status === 403)) {
      var code = '';
      try { code = (JSON.parse(await res.clone().text()) || {}).code || ''; } catch (e) { /* not JSON */ }
      if (res.status === 401 || LOGIN_ERRORS.indexOf(code) !== -1) {
        useToken = true;
        return request(method, path, opts);
      }
    }
    if (opts.blob) {
      if (!res.ok) throw new ApiError((await res.text()).slice(0, 200) || ('HTTP ' + res.status), res.status);
      return { blob: await res.blob(), type: res.headers.get('content-type') };
    }
    if (!opts.url && !opts.retried && apiRoot.indexOf('rest_route=') === -1 && /text\/html/i.test(res.headers.get('content-type') || '')) {
      apiRoot = plainRoot();
      return request(method, path, Object.assign({}, opts, { retried: true }));
    }
    var text = await res.text(), data = text;
    try { data = text === '' ? null : JSON.parse(text); } catch (e) { /* plain text */ }
    if (!res.ok || (data && typeof data === 'object' && data.code && data.message && data.data && data.data.status >= 400)) {
      var msg = (data && data.message) ? data.message : (typeof data === 'string' && data ? data.slice(0, 200) : 'HTTP ' + res.status);
      var fail = new ApiError(msg, res.status, data);
      fail.retryAfter = parseInt(res.headers.get('Retry-After') || '', 10) || 0;
      throw fail;
    }
    return data;
  }
  /**
   * Calls a paced step (see app/Pace.php) on api (D.api, or a destination's
   * API for a push) until it's through: an answer with done: false means
   * "call again", and onStep(answer) hears each one. A request the host cut
   * off, or a busy answer, is sent again after a pause (up to six times in
   * a row), asking for half the time after a cut-off.
   */
  D.paced = async function (api, endpoint, body, onStep) {
    var budget = 0, fails = 0, r;
    do {
      try {
        r = await api.post(endpoint, Object.assign({}, body, { paced: 1 }, budget ? { budget: budget } : {}));
        fails = 0;
        if (r && r.done === false && onStep) onStep(r);
      } catch (e) {
        var again = !e.status || [429, 500, 502, 503, 504].indexOf(e.status) !== -1;
        if (!again || ++fails > 6) throw e;
        if (e.status !== 429 && e.status !== 503) budget = Math.max(5, (budget || 20) / 2);
        await new Promise(function (ok) { setTimeout(ok, 1000 * (e.retryAfter || 2 * fails)); });
        r = { done: false };
      }
    } while (r && r.done === false);
    return r;
  };

  D.api = {
    url: apiUrl,
    get: function (path, query, opts) { return request('GET', path, Object.assign({ query: query }, opts)); },
    post: function (path, body, opts) { return request('POST', path, Object.assign({ body: body }, opts)); },
    upload: function (path, form) { return request('POST', path, { form: form }); },
    blob: function (path, body) { return request('POST', path, { body: body, blob: true }); },
    fetchUrl: async function (url) {
      var res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new ApiError('HTTP ' + res.status + ' for ' + url, res.status);
      return res.json();
    },
    Error: ApiError
  };
  D.errorText = function (e) { return (e && e.message) ? e.message : String(e); };

  /* ------------------------------------------------------------------- UI */

  var toastBox;
  D.toast = function (message, kind) {
    if (!toastBox) { toastBox = h('div', { class: 'dsk-toasts', role: 'status', 'aria-live': 'polite' }); (document.querySelector('.dsk') || document.body).appendChild(toastBox); }
    var t = h('div', { class: 'dsk-toast' + (kind === 'error' ? ' error' : '') }, D.icon(kind === 'error' ? 'alert' : 'checkCircle'), h('div', null, message));
    toastBox.appendChild(t);
    setTimeout(function () { t.remove(); }, kind === 'error' ? 7000 : 3200);
  };

  /**
   * Opens a modal dialog. actions: [{ label, kind, value, close: false, onClick }].
   * Resolves with the clicked action's value (or null when dismissed).
   */
  D.modal = function (o) {
    return new Promise(function (resolve) {
      var dlg = h('dialog', { class: 'dsk-modal' + (o.wide ? ' wide' : '') });
      var done = function (v) { if (dlg.open) dlg.close(); dlg.remove(); resolve(v); };
      var footer = h('footer');
      (o.actions || [{ label: 'Close', value: null }]).forEach(function (a) {
        var b = h('button', { type: 'button', class: 'dsk-btn ' + (a.kind || '') + (a.left ? ' left' : '') }, a.icon ? D.icon(a.icon) : null, a.label);
        b.addEventListener('click', async function () {
          if (a.onClick) {
            var r = await D.busy(b, function () { return a.onClick(dlg); });
            if (r === false) return;
            done(r === undefined ? a.value : r);
          } else done(a.value);
        });
        footer.appendChild(b);
      });
      append(dlg, [h('form', { method: 'dialog', onsubmit: function (e) { e.preventDefault(); } },
        h('header', null, h('h2', null, o.title || ''), h('button', { type: 'button', class: 'dsk-btn ghost icon sm', 'aria-label': 'Close', onclick: function () { done(null); } }, D.icon('x'))),
        h('div', { class: 'body' }, o.body || null),
        footer)]);
      dlg.addEventListener('cancel', function (e) { e.preventDefault(); if (!o.persistent) done(null); });
      (document.querySelector('.dsk') || document.body).appendChild(dlg);
      dlg.showModal();
      var first = dlg.querySelector('.body input, .body textarea, .body select');
      if (first) first.focus();
      if (o.onOpen) o.onOpen(dlg, done);
    });
  };
  D.confirm = function (message, o) {
    o = o || {};
    return D.modal({
      title: o.title || 'Are you sure?',
      body: typeof message === 'string' ? h('p', null, message) : message,
      actions: [{ label: o.cancelLabel || 'Cancel', value: false }, { label: o.confirmLabel || 'Continue', kind: o.danger ? 'danger solid' : 'primary', value: true }]
    }).then(function (v) { return v === true; });
  };
  D.prompt = function (o) {
    var input = h('input', { class: 'dsk-input', value: o.value || '', spellcheck: 'false' });
    var p = D.modal({
      title: o.title, body: h('div', { class: 'dsk-field' }, o.label ? h('label', null, o.label) : null, input),
      actions: [{ label: 'Cancel', value: null }, { label: o.confirmLabel || 'Save', kind: 'primary', onClick: function () { return input.value; } }]
    });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); input.closest('dialog').querySelector('footer .primary').click(); } });
    return p;
  };

  /** Disables a button and shows a spinner while fn() runs. */
  D.busy = async function (btn, fn) {
    if (!btn) return fn();
    if (btn.classList.contains('is-busy')) return false;
    var spin = h('span', { class: 'dsk-spin' });
    btn.classList.add('is-busy'); btn.disabled = true; btn.prepend(spin);
    try { return await fn(); }
    finally { btn.classList.remove('is-busy'); btn.disabled = false; spin.remove(); }
  };

  D.copy = async function (text) {
    try { await navigator.clipboard.writeText(text); }
    catch (e) {
      var ta = h('textarea', { style: { position: 'fixed', left: '-9999px' } }, text);
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (e2) { D.toast('Could not copy.', 'error'); ta.remove(); return; }
      ta.remove();
    }
    D.toast('Copied to clipboard.');
  };

  /** A terminal-style command line with a copy button. text can be a function (re-read on copy). */
  D.cmd = function (text, o) {
    o = o || {};
    var pre = h('pre');
    var block = h('div', { class: 'dsk-cmd' + (o.plain ? ' plain' : '') }, pre,
      h('button', { type: 'button', class: 'dsk-btn', onclick: function () { D.copy(pre.textContent); } }, 'copy'));
    block.set = function (t) { pre.textContent = t; };
    block.set(text);
    if (!o.label) return block;
    return h('div', null, h('div', { class: 'dsk-cmd-label' }, h('strong', null, o.label), o.hint ? h('span', null, o.hint) : null), block);
  };

  D.note = function (kind, content, icon) {
    return h('div', { class: 'dsk-note ' + (kind || '') }, D.icon(icon || ({ warn: 'alert', error: 'alert', ok: 'checkCircle' }[kind] || 'info')), h('div', { class: 'grow' }, content));
  };

  D.progress = function () {
    var bar = h('i', { style: { width: '0%' } });
    var el = h('div', { class: 'dsk-progress' }, bar);
    el.set = function (pct) {
      if (pct === null || pct === undefined) { el.classList.add('indeterminate'); return; }
      el.classList.remove('indeterminate');
      bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
    };
    return el;
  };

  D.toggle = function (label, checked, onChange) {
    var input = h('input', { type: 'checkbox', checked: !!checked, onchange: function () { onChange(input.checked); } });
    var el = h('label', { class: 'dsk-switch' }, input, h('span', { class: 'track' }), label);
    el.input = input;
    return el;
  };

  D.seg = function (options, value, onChange) {
    var el = h('div', { class: 'dsk-seg', role: 'group' });
    function render() {
      mount(el, options.map(function (o) {
        return h('button', { type: 'button', 'aria-pressed': String(o.value === value), disabled: el.disabled, onclick: function () { if (o.value !== value) { value = o.value; render(); onChange(value); } } }, o.icon ? D.icon(o.icon) : null, o.label);
      }));
    }
    el.setDisabled = function (d) { el.disabled = d; render(); };
    render();
    return el;
  };

  D.search = function (placeholder, onInput) {
    var input = h('input', { class: 'dsk-input', type: 'search', placeholder: placeholder, spellcheck: 'false', oninput: function () { onInput(input.value.trim().toLowerCase()); } });
    var el = h('div', { class: 'dsk-search' }, D.icon('search'), input);
    el.input = input;
    return el;
  };

  D.download = function (url, name) {
    var a = h('a', { href: url, download: name || '' });
    document.body.appendChild(a); a.click(); a.remove();
  };
  /**
   * Downloads a file Disembark made in uploads/disembark. Checks the direct
   * link first; if the host won't serve it (permissions, a security rule),
   * falls back to a form post to /stream-file so PHP streams it straight to
   * disk, without holding a big file in the browser's memory.
   */
  /* o.stream: always go through /stream-file. o.deleteAfter: the server
   * deletes the file once it has sent it (database exports). */
  D.downloadSiteFile = async function (url, name, o) {
    o = o || {};
    var ok = false;
    if (!o.stream) {
      try { ok = (await fetch(url, { method: 'HEAD', credentials: 'same-origin', cache: 'no-store' })).ok; } catch (e) { ok = false; }
    }
    if (ok) { D.download(url, name); return 'direct'; }
    var path = new URL(url, location.href).pathname;
    var home = new URL(cfg.homeUrl, location.href).pathname.replace(/\/$/, '');
    if (home && path.indexOf(home + '/') === 0) path = path.slice(home.length);
    // A form post can't carry headers: the nonce, or the token once the
    // login no longer works, goes in the (POST) body.
    var form = h('form', { method: 'POST', action: apiUrl('stream-file'), style: { display: 'none' } },
      h('input', { type: 'hidden', name: '_wpnonce', value: cfg.nonce }),
      useToken ? h('input', { type: 'hidden', name: 'token', value: D.store.state.token }) : null,
      h('input', { type: 'hidden', name: 'file', value: decodeURIComponent(path.replace(/^\//, '')) }),
      name ? h('input', { type: 'hidden', name: 'download_name', value: name }) : null,
      o.deleteAfter ? h('input', { type: 'hidden', name: 'delete_after', value: '1' }) : null);
    document.body.appendChild(form);
    form.submit();
    form.remove();
    return 'stream';
  };
  D.downloadBlob = function (blob, name) {
    var url = URL.createObjectURL(blob);
    D.download(url, name);
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  };
  /** The current scan's session, or a new one minted by the server. */
  D.backupSession = async function () {
    if (D.store.state.backupToken) return D.store.state.backupToken;
    var r = await D.api.post('backup/session', {});
    if (!r || !r.backup_token) throw new Error('The site did not start a session.');
    return r.backup_token;
  };
  D.randomHex = function (len) {
    var bytes = new Uint8Array(Math.ceil(len / 2));
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('').slice(0, len);
  };
  D.sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
  D.host = function (url) { return String(url || '').replace(/^https?:\/\//, '').replace(/\/$/, ''); };

  /** The small harbor drawing used for empty states. */
  D.art = function (kind) {
    var crates = kind === 'db'
      ? '<rect x="66" y="27" width="9" height="9" rx="1.5" fill="#2b8a7e"/><rect x="77" y="27" width="9" height="9" rx="1.5" fill="#3f78a8"/><rect x="66" y="38" width="9" height="9" rx="1.5" fill="#3f78a8"/><rect x="77" y="38" width="9" height="9" rx="1.5" fill="#2b8a7e"/>'
      : '<rect x="66" y="27" width="9" height="9" rx="1.5" fill="#c8553d"/><rect x="77" y="27" width="9" height="9" rx="1.5" fill="#d9a441"/><rect x="66" y="38" width="9" height="9" rx="1.5" fill="#d9a441"/><rect x="77" y="38" width="9" height="9" rx="1.5" fill="#c8553d"/>';
    return D.svg('<svg viewBox="0 0 120 80" aria-hidden="true"><rect class="dsk-art-water" x="0" y="0" width="120" height="80" rx="12"/>' +
      '<rect class="dsk-art-land" x="0" y="0" width="120" height="16"/><rect class="dsk-art-wood" x="40" y="16" width="12" height="44"/>' +
      '<path class="dsk-art-wave" d="M10 66q4-4 8 0t8 0M90 70q4-4 8 0t8 0M14 34q4-4 8 0t8 0" fill="none" stroke-width="1.6" stroke-linecap="round" opacity=".6"/>' +
      '<g class="dsk-boat"><path class="dsk-art-hull" d="M76 14c7 5 10 12 10 20v26c0 3-2 5-5 5H71c-3 0-5-2-5-5V34c0-8 3-15 10-20z"/><path class="dsk-art-deck" d="M76 19c5 4 7 9 7 15v25c0 2-1 3-3 3h-8c-2 0-3-1-3-3V34c0-6 2-11 7-15z"/>' + crates + '</g></svg>', 'art');
  };
  D.empty = function (title, text, kind, action) {
    return h('div', { class: 'dsk-empty' }, h('div', null, D.art(kind), h('h3', null, title), text ? h('p', null, text) : null, action ? h('div', { style: { marginTop: '14px' } }, action) : null));
  };

  /**
   * Plans a database export the way the CLI does: small tables batched up to
   * 200 MB per dump, big tables split into parts of at most 200 MB / 1M rows.
   * tables: [{ table, size, row_count }] → [{ tables } | { table, part, parts, rows }].
   */
  D.planDbExport = function (tables) {
    var MAX_SIZE = 200 * 1024 * 1024, MAX_ROWS = 1000000, queue = [], batch = [], batchSize = 0;
    tables.forEach(function (t) {
      if ((t.size > MAX_SIZE || t.row_count > MAX_ROWS) && t.row_count > 0) {
        if (batch.length) { queue.push({ tables: batch, size: batchSize }); batch = []; batchSize = 0; }
        var parts = Math.max(Math.ceil(t.size / MAX_SIZE), Math.ceil(t.row_count / MAX_ROWS));
        for (var i = 1; i <= parts; i++) queue.push({ table: t.table, part: i, parts: parts, rows: Math.ceil(t.row_count / parts), size: t.size / parts });
      } else {
        if (batchSize + t.size > MAX_SIZE && batch.length) { queue.push({ tables: batch, size: batchSize }); batch = []; batchSize = 0; }
        batch.push(t.table); batchSize += t.size;
      }
    });
    if (batch.length) queue.push({ tables: batch, size: batchSize });
    return queue;
  };

  /* ------------------------------------------------------------ Navigation */

  D.sections = [];
  D.section = function (def) { D.sections.push(def); };
  D.go = function (id) { if (location.hash !== '#' + id) location.hash = id; };

  /* ------------------------------------------------- Site data (shared) */

  /** Folder index built once from the flat file list: path → node. */
  D.buildIndex = function (files) {
    var nodes = new Map();
    var root = { path: '', name: '', dir: true, size: 0, files: 0, kids: [] };
    nodes.set('', root);
    for (var i = 0; i < files.length; i++) {
      var f = files[i], size = Number(f.size) || 0;
      var parts = f.name.split('/'), parent = root, path = '';
      root.size += size; root.files++;
      for (var j = 0; j < parts.length; j++) {
        path = j ? path + '/' + parts[j] : parts[j];
        var isDir = j < parts.length - 1;
        var node = nodes.get(path);
        if (!node) {
          node = { path: path, name: parts[j], dir: isDir, size: 0, files: 0, kids: isDir ? [] : null };
          nodes.set(path, node);
          parent.kids.push(node);
        }
        node.size += size;
        if (isDir) node.files++;
        parent = node;
      }
    }
    nodes.forEach(function (n) {
      if (n.kids) n.kids.sort(function (a, b) { return (a.dir === b.dir) ? a.name.localeCompare(b.name) : (a.dir ? -1 : 1); });
    });
    return { root: root, nodes: nodes };
  };

  /** True when path or any of its parent folders is excluded. */
  D.isExcluded = function (path) {
    var ex = D.store.state.excluded;
    if (!ex.size) return false;
    if (ex.has(path)) return true;
    var i = path.lastIndexOf('/');
    while (i > 0) { path = path.slice(0, i); if (ex.has(path)) return true; i = path.lastIndexOf('/'); }
    return false;
  };
  /** Excluded paths with any path under an excluded parent dropped. */
  D.minimalExclusions = function () {
    var ex = D.store.state.excluded;
    return Array.from(ex).filter(function (p) {
      var i = p.lastIndexOf('/');
      while (i > 0) { p = p.slice(0, i); if (ex.has(p)) return false; i = p.lastIndexOf('/'); }
      return true;
    }).sort();
  };

  D.loadStatus = async function () {
    try {
      var r = await D.api.get('backup-size');
      D.store.set({ diskSize: r.size || 0, lastScan: r.scan_stats || null, sessions: r.sessions || [] });
    } catch (e) { /* the dashboard still works without it */ }
  };

  D.loadDatabase = async function (force) {
    if (D.store.state.database.length && !force) return D.store.state.database;
    var tables = await D.api.get('database', D.store.state.site ? { site: D.store.state.site } : undefined);
    if (!Array.isArray(tables)) throw new Error('Could not read the database tables.');
    tables.forEach(function (t) { t.size = Number(t.size) || 0; t.row_count = Number(t.row_count) || 0; });
    D.store.set({ database: tables, included: new Set(tables.map(function (t) { return t.table; })) });
    return tables;
  };

  async function loadManifests(manifests, onProgress) {
    var all = [], done = 0;
    for (var i = 0; i < manifests.length; i += 3) {
      var batch = await Promise.all(manifests.slice(i, i + 3).map(function (m) { return D.api.fetchUrl(m.url); }));
      batch.forEach(function (list) {
        if (!Array.isArray(list)) throw new Error('A file list part could not be read.');
        all.push.apply(all, list);
      });
      done += batch.length;
      if (onProgress) onProgress({ phase: 'loading', done: done, total: manifests.length });
    }
    all.sort(function (a, b) { return a.name < b.name ? -1 : a.name > b.name ? 1 : 0; });
    return all;
  }
  function setFiles(files, token) {
    D.store.set({ files: files, index: D.buildIndex(files), excluded: new Set(), backupToken: token, scanned: true });
  }

  /**
   * Scans the site's files (regenerate-manifest steps) and loads the result.
   * onProgress receives { phase: 'scanning'|'chunking'|'loading', done, total }.
   */
  D.scanSite = async function (onProgress) {
    var token = '';
    var site = D.store.state.site;
    var step = function (name, extra) { return D.api.post('regenerate-manifest', Object.assign({ backup_token: token, step: name }, site ? { site: site } : {}, extra || {})); };
    onProgress = onProgress || function () {};
    // The server mints the session id (an unguessable folder name).
    token = (await step('initiate')).backup_token;
    if (!token) throw new Error('The site did not start a scan session.');
    for (var guard = 0; guard < 100000; guard++) {
      var s = await step('scan', { exclude_files: '' });
      onProgress({ phase: 'scanning', done: s.scanned_dirs || 0, total: s.total_dirs || 0 });
      if (s.status === 'scan_complete') break;
    }
    var ch = await step('chunkify');
    var total = ch.total_chunks || 0;
    for (var c = 1; c <= total; c++) {
      await step('process_chunk', { chunk: c });
      onProgress({ phase: 'chunking', done: c, total: total });
    }
    var manifests = await step('finalize');
    var files = await loadManifests(Array.isArray(manifests) ? manifests : [], onProgress);
    setFiles(files, token);
    D.loadStatus();
    return files;
  };

  /**
   * A network or WP Freighter install: which site the Backup section works
   * on. Files and Database always show the whole install, so they stand
   * aside while one site is chosen (its file and table names are the ones
   * a standalone copy uses, not where they live here).
   */
  D.siteName = function (id) {
    var inst = cfg.install;
    if (!inst || !id) return '';
    var s = (inst.sites || []).find(function (x) { return x.id === id; });
    return s ? D.host(s.url) + (s.name ? ' (' + s.name + ')' : '') + (id === 'main' ? ', main site' : ', ' + (inst.kind === 'multisite' ? 'site ' : 'tenant ') + id) : id;
  };
  D.setSite = function (id) {
    D.store.set({ site: id || '', scanned: false, files: [], index: null, database: [], included: new Set(), excluded: new Set(), backupToken: '' });
  };
  D.oneSiteNote = function (what) {
    return h('div', { class: 'dsk-card dsk-card-pad' }, D.note('', [
      h('strong', null, 'Back up is set to one site: ' + D.siteName(D.store.state.site) + '. '),
      what + ' show the whole install, so they wait until Back up is set back to it. ',
      h('div', { style: { marginTop: '10px' } }, h('button', { class: 'dsk-btn sm', onclick: function () { D.setSite(''); } }, 'Show the whole install'))
    ], 'alert'));
  };

  /** Reloads the file list from an earlier scan session. */
  D.resumeSession = async function (session, onProgress) {
    var manifests = await D.api.get('manifest', { backup_token: session.token });
    if (!Array.isArray(manifests)) throw new Error('That scan is no longer available.');
    var files = await loadManifests(manifests, onProgress);
    setFiles(files, session.token);
    return files;
  };
})();
