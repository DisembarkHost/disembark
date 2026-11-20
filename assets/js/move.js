/**
 * Move: every move is "from here to there". Pick where it comes from (this
 * site, another site, a backup zip) and where it goes (this site, another
 * site, a new subsite or tenant), and the tab works out whether that's a
 * pull, a push or a restore. One panel asks for the one thing it needs (a
 * connect line or a zip), shows the one warning that matters, and starts
 * it. The move itself runs in D.runMove (restore.js), in this same tab.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h;

  D.section({ id: 'move', title: 'Move', icon: 'ship', tab: 1, mount: mount, onRoute: route, onHome: home });

  var inst = D.config.install;
  var isMain = !!(inst && inst.current === inst.main && (inst.sites || []).length);
  // A subsite or tenant: a move here would replace files the whole install
  // shares, so it only goes out from here (a push of this one site). The
  // server refuses the rest too (Import::here_refusal()).
  var subHere = !!(inst && inst.current !== inst.main);
  var net = !!(inst && inst.kind === 'multisite');
  var one = inst && !net ? 'tenant' : 'subsite';
  var here = D.host(D.config.homeUrl);
  var others = isMain ? inst.sites.filter(function (s) { return s.id !== inst.main; }) : [];

  var DEFAULTS = { from: subHere ? 'this' : 'other', to: subHere ? 'other' : 'this', connect: null, file: null, sourceUrl: '', into: 'new', quiet: true, catchUp: true };
  var S = Object.assign({}, DEFAULTS);
  var root = null, running = false, points = null;

  function valid(from, to) {
    if (from === 'this') return to === 'other';
    if (to === 'new') return isMain;
    return to === 'this' && !subHere;
  }
  function kind() { return S.from === 'this' ? 'push' : S.from === 'other' ? 'pull' : 'restore'; }
  function remote() { return S.connect ? D.host(S.connect.url) : ''; }
  function intoSite() { return others.filter(function (s) { return String(s.id) === String(S.into); })[0]; }
  function intoName() { var s = intoSite(); return s ? (s.name || D.host(s.url)) : ''; }
  function intoLabel() { var s = intoSite(); return s ? intoName() + ' (' + one + ' ' + s.id + ')' : ''; }

  function route(asked) {
    if (running) return;
    if (asked === 'pull') { S.from = 'other'; S.to = subHere ? null : 'this'; }
    else if (asked === 'push') { S.from = 'this'; S.to = 'other'; S.quiet = false; }
    else if (asked === 'restore') { S.from = 'zip'; S.to = subHere ? null : 'this'; }
    if (root) render();
  }
  function home() {
    if (running) return;
    S = Object.assign({}, DEFAULTS);
    render();
  }

  function mount(el) {
    root = el;
    D.pull.loadLastPulls().then(function () { if (!running && S.connect) render(); });
    loadPoints();
    render();
  }
  function loadPoints() {
    D.api.get('restore-points').then(function (list) { points = Array.isArray(list) ? list : []; var b = root && root.querySelector('.dsk-points-link'); if (b) D.mount(b, pointsLink()); }).catch(function () {});
  }
  function pointsLink() {
    if (!points) return null;
    var label = points.length ? D.fmt.plural(points.length, 'restore point') + ' · last one ' + D.fmt.ago(points[0].created_at) : 'No restore points yet';
    return h('a', { class: 'dsk-linkish', href: '#backups' }, h('span', { html: D.H.buoyIcon('#c8553d') }), label);
  }

  /* ------------------------------------------------------------ pictures */
  function pic(k) {
    var H = D.H;
    var o = '<svg viewBox="0 0 120 46" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><rect width="120" height="46" class="s-land"/><path d="M0 34 Q30 30 60 35 T120 33 V46 H0Z" class="s-shallow"/><path d="M0 38 Q30 34 60 39 T120 37 V46 H0Z" class="s-water"/>';
    if (k === 'this') o += H.warehouse(36, 6, 48, 24, true) + H.tree(18, 16, .55) + H.tree(102, 14, .5);
    else if (k === 'other') o += H.warehouse(36, 6, 48, 24, false) + H.tree(18, 16, .55) + H.tree(102, 14, .5) + '<g transform="translate(92 26)"><circle r="7" fill="var(--surface)" stroke="var(--line-2)"/><path d="M-4 0h8M0 -4.5c2 2 2 7 0 9M0 -4.5c-2 2 -2 7 0 9" stroke="var(--ink-3)" stroke-width="1.1" fill="none"/></g>';
    else if (k === 'zip') o += '<rect x="40" y="28" width="40" height="4" rx="1" class="s-wood"/><rect x="43" y="7" width="34" height="21" rx="3" fill="#d9a441"/><path d="M43 17.5h34M60 7v21" stroke="rgba(0,0,0,.18)" stroke-width="1.6"/><rect x="52" y="13" width="16" height="9" rx="1.5" fill="#fbf7ef"/><text x="60" y="20" text-anchor="middle" style="font:600 5.5px var(--mono);fill:#7a5a3a">.zip</text>';
    else if (k === 'new') o += H.warehouse(14, 8, 26, 20, false) + H.warehouse(46, 8, 26, 20, false) + '<rect x="78" y="8" width="28" height="20" rx="3" class="s-slot"/><path d="M92 13v10M87 18h10" stroke="var(--accent)" stroke-width="1.6" stroke-linecap="round"/>';
    return o + '</svg>';
  }
  function lighthouse() {
    var H = D.H;
    return '<svg viewBox="0 0 40 40" aria-hidden="true"><rect width="40" height="40" class="s-water"/><rect width="40" height="40" fill="var(--night)" opacity=".6"/><circle cx="31" cy="9" r="3.2" fill="#f4efe6"/><path d="M0 33 Q12 29 22 32 T40 31 V40 H0Z" class="s-land"/>' + H.lighthouse(17, 35, true, .5) + '</svg>';
  }

  /* ------------------------------------------------------------ the words */
  function verdict() {
    var k = kind(), r = remote();
    if (!S.to) return ['A site can’t be brought into this ' + one + ' from here: run it from the main site.'];
    if (k === 'pull' && S.to === 'this') return ['This site downloads from ', { b: r || 'the site you paste below' }, ' and becomes a copy of it. Nothing changes on ' + (r || 'that site') + '.'];
    if (k === 'pull') return [{ b: here }, ' downloads from ', { b: r || 'the site you paste below' }, S.into === 'new' ? ' and adds it as a new ' + one + '. The rest of the ' + (net ? 'network' : 'install') + ' stays as it is.' : ' and puts it in place of ' + intoLabel() + '.'];
    if (k === 'push') return [{ b: r || 'The other site' }, ' downloads from this site and becomes a copy of it. Nothing changes here.'];
    if (S.to === 'new') return S.into === 'new' ? ['The backup zip becomes a new ' + one + ' of ', { b: here }, '. Nothing that’s already here is replaced.'] : ['The backup zip replaces ', { b: intoLabel() }, ' on ' + here + '. The other sites stay as they are.'];
    return ['The backup zip replaces this site. Its files and database come from the zip.'];
  }
  function words(parts) { return parts.map(function (x) { return x && x.b ? h('b', null, x.b) : x; }); }

  /** The two shores for the scene: source on the left, destination on the right. */
  function scene() {
    var k = kind(), r = remote(), L, R, you;
    if (k === 'push') { L = { kind: 'site', label: here }; R = { kind: 'site', label: r || 'another site', hot: !!r }; you = 'left'; }
    else {
      L = k === 'pull' ? { kind: 'site', label: r || 'another site', hot: !!r } : { kind: 'zip', label: S.file ? S.file.name : 'backup.zip' };
      if (S.to === 'new') {
        var idx = others.indexOf(intoSite());
        R = net ? { kind: 'network', label: here, newSlot: S.into === 'new', pick: S.into === 'new' ? null : Math.min(Math.max(idx, 0), 1) }
          : { kind: 'freighter', label: here, newSlot: S.into === 'new', pick: S.into === 'new' ? null : Math.min(Math.max(idx, 0) + 2, 8) };
      } else R = { kind: 'site', label: here };
      you = 'right';
    }
    return { left: L, right: R, you: you };
  }

  /* ------------------------------------------------------------ render */
  function render() {
    if (running || !root) return;
    var unfinishedBox = h('div');
    D.mount(root,
      D.pageHead({ caps: 'Move', icon: 'ship', title: ['Where’s it ', 'going?'],
        text: 'Pick where it comes from and where it goes. A restore point is saved before anything changes.',
        side: h('span', { class: 'dsk-points-link' }, pointsLink()) }),
      unfinishedBox,
      builder(),
      voyage(),
      panel());
    D.pull.unfinished(unfinishedBox, function (o) {
      start(function (view, back) { D.pull.run(view, Object.assign(o, { scene: { left: { kind: 'site', label: D.host(o.site) }, right: { kind: 'site', label: here }, you: 'right' } }), back); });
    });
  }

  function tile(side, val, label, sub, mono) {
    var on = S[side] === val, dis = side === 'to' && !valid(S.from, val), why = '';
    if (dis) {
      if (val === 'this') why = S.from === 'this' ? 'It’s already here' : (subHere ? 'From the main site only' : '');
      else if (val === 'other') why = S.from === 'other' ? 'Run it on one of them' : 'Open Disembark there';
      else why = !isMain ? (inst ? 'From the main site only' : 'Network or Freighter only') : 'Bring in a site or a zip';
    }
    return h('button', { type: 'button', class: 'dsk-opt', role: 'radio', 'aria-checked': String(on), 'aria-disabled': dis ? 'true' : null, title: dis ? why : null,
      onclick: function (e) {
        if (dis) return;
        S[side] = val;
        if (side === 'from' && !valid(S.from, S.to)) S.to = ['this', 'new', 'other'].filter(function (x) { return valid(S.from, x); })[0];
        if (side === 'from') S.quiet = S.from !== 'this';
        render();
        // Keep keyboard users on the tile they chose (the page is redrawn);
        // a mouse click (e.detail > 0) shouldn't leave a focus ring behind.
        if (e.detail === 0) { var again = root.querySelector('.dsk-opt[data-k="' + side + '-' + val + '"]'); if (again) again.focus(); }
      }, 'data-k': side + '-' + val },
      h('span', { class: 'pic', html: pic(val) }), h('b', null, label), h('small', { class: mono && !dis ? 'mono' : null }, dis ? why : sub));
  }

  function builder() {
    var k = kind();
    return h('div', { class: 'dsk-builder', role: 'group', 'aria-label': 'Move builder' },
      h('div', null, h('div', { class: 'dsk-grp-h caps' }, 'From'), h('div', { class: 'dsk-grp', role: 'radiogroup', 'aria-label': 'From' },
        tile('from', 'this', 'This site', here, true), tile('from', 'other', 'Another site', 'Paste its connect line'), tile('from', 'zip', 'A backup zip', 'Drop it here'))),
      h('div', { class: 'dsk-mid', 'aria-hidden': 'true' }, h('span', { class: 'arr' }, D.icon('arrow')), h('span', { class: 'kind' }, k)),
      h('div', null, h('div', { class: 'dsk-grp-h caps' }, 'To'), h('div', { class: 'dsk-grp', role: 'radiogroup', 'aria-label': 'To' },
        tile('to', 'this', 'This site', here, true), tile('to', 'other', 'Another site', 'Paste its connect line'), tile('to', 'new', 'A new ' + one, isMain ? 'or replace one' : (inst ? 'From the main site only' : 'Network or Freighter only')))));
  }

  function voyage() {
    var k = kind(), sc = scene();
    var box = h('div', { class: 'dsk-scene', html: D.H.scene({ harbor: { left: sc.left, right: sc.right, you: sc.you, youText: 'You are here', t: null, alt: 'From ' + sc.left.label + ' to ' + sc.right.label }, arrow: true }) });
    return h('section', { class: 'dsk-card dsk-voy' },
      h('div', { class: 'dsk-voy-head' }, h('span', { class: 'k' }, k.charAt(0).toUpperCase() + k.slice(1) + '.'), h('p', null, words(verdict()))),
      box);
  }

  function connectField() {
    var k = kind(), p = S.connect;
    var lbl = k === 'pull' ? 'The source’s connect line' : 'The destination’s connect line';
    var head = h('div', { class: 'dsk-field-h' }, h('label', { class: 'dsk-label', for: 'dsk-cl' }, lbl), h('span', { class: 'muted' }, 'Settings → Connect line, in Disembark on ' + (k === 'pull' ? 'the source' : 'that site')));
    if (p) {
      return h('div', null, head,
        h('div', { class: 'dsk-cl ok' }, D.icon('check'), h('div', { class: 'filled' }, h('span', { class: 'u' }, p.url), h('span', { class: 't' }, 'token ' + p.token.slice(0, 4) + '…' + p.token.slice(-4))),
          h('button', { class: 'dsk-btn sm ghost', type: 'button', onclick: function () { S.connect = null; render(); var i = root.querySelector('#dsk-cl'); if (i) i.focus(); } }, 'Change')),
        h('p', { class: 'dsk-hint' }, 'Filled in from the connect line.'));
    }
    var input = h('input', { id: 'dsk-cl', placeholder: 'disembark connect https://… token', spellcheck: 'false', autocomplete: 'off', autocapitalize: 'none' });
    var hint = h('p', { class: 'dsk-hint' }, 'Paste the whole line: the address and token fill in together.');
    function take(text) {
      var parsed = D.parseConnect(text);
      if (!parsed) return false;
      if (D.address(parsed.url) === D.address(D.config.homeUrl)) { hint.textContent = 'That’s this site’s own connect line. Paste the one from ' + (k === 'pull' ? 'the source' : 'the destination') + '.'; return true; }
      S.connect = parsed;
      render();
      var go = root.querySelector('.dsk-go'); if (go) go.focus();
      return true;
    }
    input.addEventListener('paste', function (e) { if (take((e.clipboardData || window.clipboardData).getData('text'))) e.preventDefault(); });
    input.addEventListener('input', function () { take(input.value); });
    return h('div', null, head, h('div', { class: 'dsk-cl' }, D.icon('key'), input), hint);
  }

  function zipField() {
    var fileInput = h('input', { type: 'file', accept: '.zip', hidden: true, onchange: function () { pick(fileInput.files[0]); } });
    function pick(f) { if (!f) return; S.file = f; render(); }
    var f = S.file;
    var crate = '<svg class="crate" viewBox="0 0 92 64" aria-hidden="true">' + D.H.install('zip', 46, -34) + '</svg>';
    var drop = h('div', { class: 'dsk-zipdrop' + (f ? ' has' : ''), role: 'button', tabindex: '0', 'aria-label': f ? 'Choose another backup zip' : 'Choose a backup zip',
      onclick: function () { fileInput.click(); }, onkeydown: function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } } },
      h('span', { html: crate, style: { display: 'contents' } }),
      f ? h('div', { style: { minWidth: 0 } }, h('b', { class: 'mono', style: { fontSize: '13px', wordBreak: 'break-all' } }, f.name), h('div', { class: 'm' }, D.fmt.size(f.size) + ' · its address is read from the backup'))
        : h('div', null, h('b', null, 'Drop the zip here'), h('div', { class: 'm' }, 'or click to choose a file')),
      f ? h('span', { class: 'dsk-btn sm chg' }, 'Choose another') : null,
      fileInput);
    drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', function () { drop.classList.remove('over'); });
    drop.addEventListener('drop', function (e) { e.preventDefault(); drop.classList.remove('over'); pick(e.dataTransfer.files[0]); });
    var src = h('input', { class: 'dsk-input mono', placeholder: 'Read from the backup', value: S.sourceUrl, spellcheck: 'false', oninput: function () { S.sourceUrl = src.value.trim(); } });
    return h('div', null,
      h('div', { class: 'dsk-field-h' }, h('span', { class: 'dsk-label' }, 'The backup zip'), h('span', { class: 'muted' }, 'From Backups → Download, or ', h('code', null, 'disembark backup'))),
      drop,
      h('details', { class: 'dsk-gtk' }, h('summary', null, D.icon('globe'), 'Rewrite from a different address', D.icon('chevron', 'chev')),
        h('div', { class: 'dsk-gtk-body' }, src, h('p', { class: 'dsk-hint' }, 'Leave empty to use the address recorded in the backup. It’s rewritten to ' + (S.to === 'new' ? 'the ' + one + '’s address' : D.config.homeUrl) + '.'))));
  }

  function intoField() {
    var opts = [{ value: 'new', label: 'A new ' + one }];
    var control;
    if (others.length <= 4) {
      others.forEach(function (s) { opts.push({ value: String(s.id), label: 'Replace ' + (s.name || D.host(s.url)) }); });
      control = D.seg(opts, String(S.into), function (v) { S.into = v; render(); });
    } else {
      control = h('select', { class: 'dsk-select', onchange: function () { S.into = control.value; render(); } },
        opts.concat(others.map(function (s) { return { value: String(s.id), label: 'Replace ' + one + ' ' + s.id + ': ' + D.host(s.url) + (s.name ? ' (' + s.name + ')' : '') }; }))
          .map(function (o) { return h('option', { value: o.value, selected: String(S.into) === o.value }, o.label); }));
    }
    return h('div', { class: 'dsk-into' }, h('div', { class: 'dsk-field-h' }, h('span', { class: 'dsk-label' }, 'Where it lands on ' + here)), control);
  }

  function panel() {
    var k = kind(), r = remote(), body = [], ready = false, why = '', warn, good = [];
    // Nowhere this can go from a subsite or tenant: say which move does it.
    if (!S.to || !valid(S.from, S.to)) {
      return h('section', { class: 'dsk-card dsk-panel' },
        h('div', { class: 'dsk-panel-body' }, h('div', { class: 'dsk-warn1' }, D.icon('alert'), h('div', null,
          h('strong', null, 'This ' + one + ' can’t be replaced from here.'),
          D.config.refused || ('Run the move from the main site’s Disembark and pick “Replace” there.')))));
    }
    if (k === 'pull' || k === 'push') { body.push(connectField()); ready = !!S.connect; why = 'Paste a connect line first'; }
    else { body.push(zipField()); ready = !!S.file; why = 'Choose a backup zip first'; }
    // A local site can't push to a live one (the live one can't reach it): the zip route instead.
    var localToLive = k === 'push' && S.connect ? D.localToLive(S.connect.url) : '';
    if (localToLive) { ready = false; why = 'Use a backup zip instead'; }
    if (S.to === 'new') body.push(intoField());
    var last = k === 'pull' && S.connect && S.to === 'this' ? D.pull.lastPullFor(S.connect.url) : null;
    if (last) {
      body.push(h('div', { class: 'dsk-extra' },
        D.toggle('Only copy what changed since the last pull', S.catchUp, function (v) { S.catchUp = v; }),
        h('p', { class: 'dsk-hint' }, 'This site pulled ' + D.host(last.source) + ' ' + D.fmt.ago(last.at) + '. New and changed files come across, files removed there are removed here, and only the tables that changed on either side are copied.')));
    }

    // The one warning that matters.
    if (S.to === 'new' && S.into === 'new') warn = h('div', { class: 'dsk-warn1 calm' }, D.icon('check'), h('div', null, h('strong', null, 'Nothing here is replaced.'), 'It arrives as a new ' + one + ' with its own tables, uploads and settings' + (net ? ', and its users join the network’s' : '') + '. Rolling back removes the ' + one + '.'));
    else if (S.to === 'new') warn = h('div', { class: 'dsk-warn1' }, D.icon('alert'), h('div', null, h('strong', null, 'This replaces ' + intoLabel() + '.'), 'Its tables, uploads and settings are overwritten. The main site and the other ' + one + 's aren’t touched.'));
    else if (localToLive) warn = h('div', { class: 'dsk-warn1' }, D.icon('alert'), h('div', null, h('strong', null, 'A live site can’t reach this one.'), localToLive, ' ', h('a', { href: '#backups' }, 'Go to Backups')));
    else if (k === 'push') warn = h('div', { class: 'dsk-warn1' }, D.icon('alert'), h('div', null, h('strong', null, 'This replaces ' + (r || 'the destination') + '.'), 'Its files and database are overwritten with this site, including its user accounts: afterwards you log in there with this site’s credentials.'));
    else warn = h('div', { class: 'dsk-warn1' }, D.icon('alert'), h('div', null, h('strong', null, isMain ? 'This replaces the main site of the ' + (net ? 'network' : 'install') + '.' : 'This replaces this site.'), 'Its files and database are overwritten with ' + (k === 'pull' ? (r || 'the source') + '’s' : 'the backup’s') + '. ' + (isMain && net ? 'The network keeps its users, super admins and subsite members; the incoming site’s users join them (matched by email, never as super admins).' : 'User accounts come over too, so afterwards you log in with the source site’s credentials.') + (isMain ? ' To keep what’s here, choose “A new ' + one + '” instead.' : '')));

    // Good to know.
    if (k === 'pull') good = ['Nothing changes on ' + (r || 'the source') + ': it’s only read from.', [h('span', null, 'A restore point is saved here first, so '), h('strong', null, 'Undo'), ' puts ' + (S.to === 'new' ? here : 'this site') + ' back exactly as it was.'], 'If it stops (a closed tab, a lost connection), it can be resumed from this tab.', isMain && S.to === 'this' ? 'If the source is a network or WP Freighter install, you’ll be asked which of its sites to bring.' : null, ['Prefer the terminal? ', h('code', null, 'wp disembark pull <connect line>')]];
    if (k === 'push') good = [(r || 'The destination') + ' saves a restore point before anything changes, so the push can be undone from here, from its own dashboard, or with the CLI.', [(r || 'The destination') + ' downloads from ', h('code', null, here), ', so this site has to be reachable from it (not a local-only address).'], D.pushScopeNote() || null, 'Can’t reach it from this browser? Pull from the other side instead: paste this site’s connect line into Disembark there.'];
    if (k === 'restore') good = [[h('span', null, 'A restore point is saved first. '), h('strong', null, 'Undo'), ' puts ' + (S.to === 'new' ? here : 'this site') + ' back exactly as it was.'], 'Addresses are rewritten from the one recorded in the backup to ' + (S.to === 'new' ? 'the ' + one + '’s' : here) + '.', ['Prefer the terminal? ', h('code', null, 'disembark restore ' + D.config.homeUrl + ' <folder>')]];
    if (S.to === 'new') good.unshift([h('strong', null, 'This trusts the incoming site like a ' + (net ? 'super admin' : 'main-site admin') + '. '), 'Its plugins, themes and uploads join the ' + (net ? 'network' : 'install') + '\u2019s shared folders, so its code runs with access to every site here. Only bring in sites you trust.']);
    good = good.filter(Boolean);

    // Quiet mode.
    var quiet;
    if (S.to === 'new') quiet = h('div', { class: 'dsk-quiet off-net' }, h('span', { class: 'lh', html: lighthouse() }), h('div', null, h('b', null, 'Quiet mode stays off'), h('small', null, 'It would cover every site here, not just the new ' + one + '.')));
    else {
      var ql = k === 'push' ? 'Keep the destination quiet' : 'Keep this copy quiet';
      var qs = k === 'push' ? 'Turn it on when the destination is a staging or dev copy; leave it off for a live site.' : 'Holds emails, pauses jobs, closes checkout and blocks payment APIs, so the copy can’t reach real customers.';
      var sw = D.toggle(h('span', { class: 'sr' }, ql), S.quiet, function (v) { S.quiet = v; });
      quiet = h('div', { class: 'dsk-quiet' }, h('span', { class: 'lh', html: lighthouse() }), h('div', null, h('b', null, ql), h('small', null, qs)), sw);
    }

    var verb = k === 'pull' ? (S.to === 'new' ? (S.into === 'new' ? 'Pull in as a new ' + one : 'Pull into ' + intoName()) : 'Pull into this site')
      : k === 'push' ? 'Push to ' + (r || 'the destination')
      : (S.to === 'new' ? (S.into === 'new' ? 'Restore as a new ' + one : 'Restore into ' + intoName()) : 'Restore into this site');
    var btn = h('button', { class: 'dsk-btn primary lg dsk-go', type: 'button', 'aria-disabled': ready ? null : 'true', onclick: function (e) { if (ready) go(e.currentTarget); } }, D.icon(k === 'push' ? 'upload' : k === 'pull' ? 'download' : 'restore'), verb);

    return h('section', { class: 'dsk-card dsk-panel' },
      h('div', { class: 'dsk-panel-body' }, h('div', null, body), h('div', null, warn,
        h('details', { class: 'dsk-gtk' }, h('summary', null, D.icon('info'), 'Good to know', h('span', { class: 'muted', style: { fontSize: '12px' } }, '· ' + D.fmt.plural(good.length, 'note')), D.icon('chevron', 'chev')),
          h('ul', null, good.map(function (g) { return h('li', null, g); }))))),
      h('div', { class: 'dsk-panel-foot' }, quiet, h('div', { class: 'go' }, ready ? null : h('span', { class: 'why' }, why), btn)));
  }

  /* ------------------------------------------------------------ start */
  /** Swaps the form for the running move; back() brings the form back. */
  function start(runner) {
    running = true;
    var view = h('div');
    D.mount(root, view);
    runner(view, function () { running = false; S.connect = null; S.file = null; loadPoints(); render(); window.scrollTo({ top: 0 }); });
  }

  async function go(btn) {
    var k = kind(), r = remote(), into = S.to === 'new' ? S.into : (isMain ? 'site' : undefined), sc = scene();
    if (k === 'pull') {
      var catchUp = S.to === 'this' && S.catchUp && !!D.pull.lastPullFor(S.connect.url);
      var ok = await D.confirm(h('div', { class: 'dsk-stack', style: { gap: '10px' } },
        h('p', null, S.to === 'new' ? (S.into === 'new' ? 'Bring ' : 'Replace ' + intoLabel() + ' with ') : (catchUp ? 'Catch ' + here + ' up with ' : 'Replace ' + here + ' with a copy of '), h('strong', null, r), S.to === 'new' && S.into === 'new' ? ' in as a new ' + one + '?' : '?'),
        h('p', { class: 'muted' }, (catchUp ? 'Only changed files and tables come across. ' : '') + (S.to === 'this' ? (S.quiet ? 'Quiet mode goes on before anything arrives. ' : 'Quiet mode is off: this copy will send emails and run scheduled jobs. ') : '') + 'A restore point is saved first.' + (S.to === 'this' && !(isMain && net) ? ' You’ll need to log in again afterwards.' : ''))),
        { title: (catchUp ? 'Catch up from ' : 'Pull ') + r + '?', confirmLabel: catchUp ? 'Catch up' : 'Pull', danger: S.into !== 'new' || S.to === 'this' });
      if (!ok) return;
      var o = { site: S.connect.url, token: S.connect.token, quiet: S.to === 'this' && S.quiet, catchUp: catchUp, into: into, scene: sc, label: (catchUp ? 'Before catch-up from ' : 'Before pull from ') + r };
      start(function (view, back) { D.pull.run(view, o, back); });
      return;
    }
    if (k === 'push') {
      var ok2 = await D.confirm(h('div', { class: 'dsk-stack', style: { gap: '10px' } },
        h('p', null, 'Replace ', h('strong', null, r), ' with ', h('strong', null, here), '?'),
        h('p', { class: 'muted' }, 'The destination saves a restore point first.')), { title: 'Push this site?', confirmLabel: 'Push', danger: true });
      if (!ok2) return;
      // Reach the destination before swapping the form out, so a bad
      // connect line leaves the form as it was.
      var view = h('div'), reached = false;
      var st = { site: S.connect.url, token: S.connect.token, quiet: S.quiet, scene: sc };
      running = true;
      reached = await D.pushRun(view, st, btn, function () { running = false; S.connect = null; loadPoints(); render(); window.scrollTo({ top: 0 }); });
      if (!reached) { running = false; return; }
      D.mount(root, view);
      return;
    }
    var what = S.file.name;
    var ok3 = await D.confirm(h('div', { class: 'dsk-stack', style: { gap: '10px' } },
      h('p', null, S.to === 'new' ? (S.into === 'new' ? 'Restore ' : 'Replace ' + intoLabel() + ' with ') : 'Replace ' + here + ' with ', h('strong', null, what), S.to === 'new' && S.into === 'new' ? ' as a new ' + one + '?' : '?'),
      h('p', { class: 'muted' }, 'A restore point is saved first.' + (S.to === 'this' ? ' You’ll need to log in again afterwards.' : ''))), { title: 'Restore ' + (S.to === 'this' ? 'this site' : 'the backup') + '?', confirmLabel: 'Restore', danger: !(S.to === 'new' && S.into === 'new') });
    if (!ok3) return;
    var file = S.file, sourceUrl = S.sourceUrl, quiet = S.to === 'this' && S.quiet;
    start(function (view, back) {
      D.runMove(view, {
        api: D.api, local: true, mode: 'file', file: file, sourceUrl: sourceUrl, quiet: quiet, into: into, scene: sc, kind: 'restore',
        targetHome: D.config.homeUrl, label: 'Before restore from ' + what, title: 'Restoring', doneTitle: 'Restored', againLabel: 'Start another move', icon: 'ship',
        back: back,
        done: function (outcome, ctx) { D.arrivedView(outcome, ctx, 'Undo restore'); }
      });
    });
  }
})();
