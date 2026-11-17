/**
 * Disembark dashboard core: DOM helpers, icons, formatting, the REST client,
 * a tiny store, UI primitives (toast, modal, command blocks) and the shared
 * site scan used by Back up and Files. Everything hangs off window.Disembark.
 */
(function () {
  'use strict';

  var D = window.Disembark = window.Disembark || {};
  var cfg = D.config = Object.assign({
    apiRoot: '', nonce: '', token: '', homeUrl: '', loginUrl: '', version: '', fileEdits: true, multisite: false
  }, window.DisembarkConfig || {});

  /* ------------------------------------------------------------------ DOM */

  /**
   * h('div', { class: 'x', onclick: fn }, 'text', child, [more]) → Element.
   * Strings become text nodes (never HTML); pass { html } only for markup we built.
   */
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
    ship: '<path d="M3 15h18l-2.4 5.2Q12 22.4 5.4 20.2Z"/><path d="M6 15V9h12v6"/><path d="M12 9V4"/>',
    anchor: '<circle cx="12" cy="5" r="2"/><path d="M12 7v14M8 11h8"/><path d="M5 14c0 4 3 7 7 7s7-3 7-7"/>'
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

  function apiUrl(path, query) {
    var root = cfg.apiRoot;
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
   * Calls the Disembark REST API as the logged-in admin (cookie + nonce), with
   * the site token as a fallback: a restore replaces the users table and ends
   * the session mid-flow, and the token keeps the remaining steps authorized.
   */
  async function request(method, path, opts) {
    opts = opts || {};
    var token = D.store.state.token;
    var init = { method: method, headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' };
    var query = Object.assign({}, opts.query || {});
    if (method === 'GET' || method === 'HEAD') {
      query.token = token;
    } else if (opts.form) {
      opts.form.append('token', token);
      init.body = opts.form;
    } else {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(Object.assign({ token: token }, opts.body || {}));
    }
    var res;
    try {
      res = await fetch(opts.url || apiUrl(path, query), init);
    } catch (e) {
      throw new ApiError('Could not reach the site (' + e.message + ').', 0);
    }
    if (opts.blob) {
      if (!res.ok) throw new ApiError((await res.text()).slice(0, 200) || ('HTTP ' + res.status), res.status);
      return { blob: await res.blob(), type: res.headers.get('content-type') };
    }
    var text = await res.text(), data = text;
    try { data = text === '' ? null : JSON.parse(text); } catch (e) { /* plain text */ }
    if (!res.ok || (data && typeof data === 'object' && data.code && data.message && data.data && data.data.status >= 400)) {
      var msg = (data && data.message) ? data.message : (typeof data === 'string' && data ? data.slice(0, 200) : 'HTTP ' + res.status);
      throw new ApiError(msg, res.status, data);
    }
    return data;
  }
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
  D.downloadBlob = function (blob, name) {
    var url = URL.createObjectURL(blob);
    D.download(url, name);
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
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
    var tables = await D.api.get('database');
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
    var token = D.randomHex(12);
    var step = function (name, extra) { return D.api.post('regenerate-manifest', Object.assign({ backup_token: token, step: name }, extra || {})); };
    onProgress = onProgress || function () {};
    await step('initiate');
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

  /** Reloads the file list from an earlier scan session. */
  D.resumeSession = async function (session, onProgress) {
    var manifests = await D.api.get('manifest', { backup_token: session.token });
    if (!Array.isArray(manifests)) throw new Error('That scan is no longer available.');
    var files = await loadManifests(manifests, onProgress);
    setFiles(files, session.token);
    return files;
  };
})();
