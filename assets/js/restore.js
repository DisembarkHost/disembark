/**
 * The move runner shared by every move in the Move tab (move.js): pull,
 * push and restore. D.runMove() drives any site's import API (this one, or
 * a destination's) through the same steps and draws them as a voyage: the
 * harbor scene, six stops, a card for any question that pauses the move,
 * and the arrival. Also the restore points timeline (Backups) and the
 * connect-line parser.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h;

  /** Parses "disembark connect <url> <token>" (or "<url> <token>"). */
  D.parseConnect = function (text) {
    var parts = String(text || '').trim().split(/\s+/).filter(Boolean);
    if (parts.length >= 2 && parts[0].toLowerCase() === 'disembark' && parts[1].toLowerCase() === 'connect') parts.splice(0, 2);
    if (parts.length !== 2) return null;
    var url = parts[0], token = parts[1];
    if (!/^https?:\/\//i.test(url)) {
      if (/^[a-z][a-z0-9+.-]*:/i.test(url) && !/^[a-z0-9.-]+:\d+(\/|$)/i.test(url)) return null;
      if (url.indexOf('.') === -1) return null;
      url = 'https://' + url;
    }
    var parsed;
    try { parsed = new URL(url); } catch (e) { return null; }
    if ((parsed.protocol !== 'http:' && parsed.protocol !== 'https:') || !parsed.hostname) return null;
    if (!/^[A-Za-z0-9]{20,}$/.test(token)) return null;
    return { url: parsed.href.replace(/\/+$/, ''), token: token };
  };

  /** "example.com/path": scheme, www. and trailing slash don't count (matches Quiet::address). */
  D.address = function (url) {
    return String(url || '').trim().toLowerCase().replace(/^[a-z]+:\/\//, '').replace(/^www\./, '').replace(/\/+$/, '');
  };

  /** Shown after a move that turned quiet mode on (a row of the checks card). */
  D.quietNote = function (home, source) {
    return h('div', { class: 'dsk-chk q' }, D.icon('moon'), h('div', null, h('strong', null, 'Quiet mode is on. '),
      D.host(home) + ' is a copy' + (source ? ' of ' + D.host(source) : '') + ': emails are held, scheduled jobs paused, checkout closed, and payment and marketing APIs blocked. ',
      'Lift it in Settings there, or with ', h('code', null, 'wp disembark quiet-mode off'), '.'));
  };

  var STEPS = [
    { key: 'pre', label: 'Checking the destination' },
    { key: 'stage', label: 'Bringing the backup over' },
    { key: 'read', label: 'Reading the backup' },
    { key: 'snap', label: 'Saving a rollback point' },
    { key: 'files', label: 'Putting files in place' },
    { key: 'db', label: 'Importing the database' },
    { key: 'urls', label: 'Rewriting URLs' },
    { key: 'fin', label: 'Finishing up' },
    { key: 'check', label: 'Checking the result' }
  ];

  /**
   * Runs an import against a site's API and renders its progress into view.
   * opts: {
   *   api: { post(endpoint, body), upload(endpoint, form) },
   *   mode: 'site' | 'file', site, token (source), file, sourceUrl,
   *   targetHome, label (snapshot label), title ('Restoring' / 'Pushing'),
   *   quiet (turn on quiet mode when the address changes),
   *   done(outcome, ctx), back()   // renders the finished / form view
   * }
   */
  /**
   * A question that pauses a move, shown right under the voyage.
   * o: { title: [plain, accent], text, body, go (button), cancel(), foot }
   */
  D.decideCard = function (o) {
    return h('section', { class: 'dsk-card dsk-decide dsk-fade' },
      h('div', { class: 'dsk-decide-h' },
        h('div', { class: 'q' }, h('span', { class: 'dsk-tag warn' }, D.icon('alert'), 'Waiting on you'), h('h2', null, o.title[0], h('em', { class: 'hl' }, o.title[1])),
          o.text ? h('div', { class: 'sum' }, o.text) : null),
        o.go ? h('div', { class: 'acts' }, o.go) : null),
      o.body ? h('div', { class: 'dsk-decide-b' }, o.body) : null,
      h('div', { class: 'dsk-decide-f' }, h('p', { class: 'muted', style: { fontSize: '12.5px' } }, o.foot || 'Nothing on the destination changes until you answer.'),
        o.cancel ? h('div', { class: 'acts' }, h('button', { class: 'dsk-btn ghost sm', type: 'button', onclick: o.cancel }, 'Cancel the move')) : null));
  };

  /**
   * "Which site?" for a source that is a multisite network or a WP Freighter
   * install. Resolves with a site ID, or null when cancelled. Moving the
   * whole install as one isn't offered: it isn't built yet (the server
   * refuses it too, see Import::whole_install_refusal()).
   */
  D.chooseSite = function (box, install, preselect) {
    var kind = install.kind === 'multisite' ? 'network' : 'WP Freighter install';
    return new Promise(function (resolve) {
      var sel = h('select', { class: 'dsk-select' },
        (install.sites || []).map(function (s) {
          return h('option', { value: s.id, selected: preselect && String(s.id) === String(preselect) ? true : null }, D.host(s.url) + (s.name ? ' \u2014 ' + s.name : '') + ' (' + (s.id === 'main' ? 'main site' : (install.kind === 'multisite' ? 'site ' : 'tenant ') + s.id) + ')' + (preselect && String(s.id) === String(preselect) ? ' \u00b7 pulled before' : ''));
        }));
      function done(v) { box.cancelChoice = null; D.mount(box); resolve(v); }
      box.cancelChoice = function () { done(null); };
      D.mount(box, D.decideCard({ title: ['Which ', 'site?'],
        text: D.host(install.home) + ' is a ' + kind + '. Bring one of its sites as a standalone site: its own tables, its users, its uploads, and the plugins and themes it uses. Moving the whole ' + kind + ' as one is planned for a later version.',
        body: sel,
        go: h('button', { class: 'dsk-btn primary', type: 'button', onclick: function () { done(sel.value); } }, D.icon('ship'), 'Continue'),
        cancel: function () { done(null); } }));
    });
  };

  /**
   * "Where should it go?" when the destination is the main site of a
   * network or a WP Freighter install. Resolves with "site" (replace the
   * main site), "new", a subsite or tenant ID to replace, or null.
   */
  D.chooseInto = function (box, install, destHost) {
    var net = install.kind === 'multisite', one = net ? 'subsite' : 'tenant';
    return new Promise(function (resolve) {
      var sel = h('select', { class: 'dsk-select' },
        h('option', { value: 'new' }, 'A new ' + one),
        (install.sites || []).filter(function (s) { return s.id !== install.main; }).map(function (s) {
          return h('option', { value: s.id }, 'Replace ' + one + ' ' + s.id + ': ' + D.host(s.url) + (s.name ? ' (' + s.name + ')' : ''));
        }),
        h('option', { value: 'site' }, 'Replace the main site, ' + destHost + ' (the whole ' + (net ? 'network\u2019s users too' : 'install\u2019s main site') + ')'));
      function done(v) { box.cancelChoice = null; D.mount(box); resolve(v); }
      box.cancelChoice = function () { done(null); };
      D.mount(box, D.decideCard({ title: ['Where should it ', 'go?'],
        text: destHost + ' is the main site of a ' + (net ? 'multisite network' : 'WP Freighter install') + '. Bring the site in as its own ' + one + ' (its tables, uploads and settings stay its own' + (net ? ', and its users join the network\u2019s' : '') + '), or replace one.',
        body: sel,
        go: h('button', { class: 'dsk-btn primary', type: 'button', onclick: function () { done(sel.value); } }, D.icon('ship'), 'Continue'),
        cancel: function () { done(null); } }));
    });
  };

  /**
   * "Which database?" for a backup zip whose database isn't clear: several
   * dumps and no clear winner, or only ones deep in its folders (db.ask
   * "pick"), or none at all (db.ask "none"). Lists every dump found with
   * what a quick look at it showed, the best guess picked. Resolves with
   * the dump's path in the zip, "none" for the files only, or null when
   * cancelled. Nothing has changed on the site yet.
   */
  D.chooseDatabase = function (box, db, zipName) {
    return new Promise(function (resolve) {
      function done(v) { box.cancelChoice = null; D.mount(box); resolve(v); }
      box.cancelChoice = function () { done(null); };
      if (db.ask === 'none') {
        D.mount(box, D.decideCard({ title: ['No ', 'database.'],
          text: 'This zip has no database Disembark can use: there’s no .sql or .sql.gz file in ' + zipName + '. Restore the files only (this site keeps its own database), or cancel?',
          go: h('button', { class: 'dsk-btn primary', type: 'button', onclick: function () { done('none'); } }, D.icon('ship'), 'Restore the files only'),
          cancel: function () { done(null); } }));
        return;
      }
      var list = db.candidates || [], pick = 'none';
      list.forEach(function (c) { if (c.guess) pick = c.file; });
      var row = function (value, title, lines, plain) {
        var input = h('input', { type: 'radio', name: 'dsk-db-pick', value: value, style: { marginTop: '3px' } });
        input.checked = value === pick;
        input.addEventListener('change', function () { pick = value; });
        return h('label', { style: { display: 'flex', gap: '10px', alignItems: 'flex-start', padding: '9px 0', borderTop: '1px solid var(--line)', cursor: 'pointer' } }, input,
          h('span', { style: { minWidth: 0 } }, h('strong', { style: plain ? null : { fontFamily: 'var(--mono)', fontSize: '12.5px', overflowWrap: 'anywhere' } }, title),
            lines.map(function (l) { return h('small', { class: 'muted', style: { display: 'block', marginTop: '2px' } }, l); })));
      };
      var rows = list.map(function (c) {
        var seen = [D.fmt.size(c.size || 0), c.where];
        if (!c.usable) seen.push('Not usable: ' + (c.note || 'it doesn’t read as a database dump'));
        else {
          if (c.prefix) seen.push('tables ' + c.prefix + '*');
          seen.push(c.options ? 'options table ' + c.options : (c.whole ? 'no options table' : 'no options table in the first ' + D.fmt.size(c.looked || 0)));
          if (c.home) seen.push(c.home);
        }
        return row(c.file, c.file + (c.guess ? '  · best guess' : ''), [seen.join(' · ')]);
      });
      rows.push(row('none', 'No database: restore the files only', ['This site keeps its own database.'], true));
      var deep = list.every(function (c) { return c.spot === 'deep'; });
      D.mount(box, D.decideCard({ title: ['Which ', 'database?'],
        text: deep ? zipName + ' has no database dump at its root, only ' + (list.length === 1 ? 'this one' : 'these') + ' inside the site’s files. Restore ' + (list.length === 1 ? 'it' : 'one of them') + ', or the files only?'
          : zipName + ' holds ' + D.fmt.plural(list.length, 'database dump') + ' and it isn’t clear which is the site’s. Pick the one to restore.',
        body: h('div', null, rows),
        go: h('button', { class: 'dsk-btn primary', type: 'button', onclick: function () { done(pick); } }, D.icon('ship'), 'Continue'),
        cancel: function () { done(null); } }));
    });
  };

  /** How many moves this page is driving right now (Settings' cleanup waits for them). */
  D.movesRunning = 0;
  D.runMove = function (view, opts) {
    D.movesRunning++;
    var importId = opts.resume ? opts.resume.id : D.randomHex(24), rollbackId = '', snapLabel = String(opts.label || ''), snapSent = false, codeMentions = null, quietOn = false, quietSource = '';
    // Request ids follow the import id and the step's place in the move, so
    // the same move asks for the same ids. Resuming replays the steps the
    // server already finished (opts.resume.steps) and carries on from the
    // first one it didn't.
    var superBrought = [], tablePlan = null, catchUpInfo = null, target = null, seq = 0, replay = (opts.resume && opts.resume.steps) || {}, replaying = Object.keys(replay).length > 0;
    function ridAt(n) { return importId.slice(0, 24) + ('00000000' + n.toString(16)).slice(-8); }
    var log = h('div', { class: 'dsk-log' });
    var kind = opts.kind || (!opts.local ? 'push' : (opts.mode === 'site' ? 'pull' : 'restore'));
    var KIND = kind.charAt(0).toUpperCase() + kind.slice(1);
    // The nine steps show as six stops on the voyage; the boat sails to each.
    var STOPS = [
      { b: 'Loading', done: 'Loaded', keys: ['pre', 'stage', 'read'] },
      { b: 'Restore point', keys: ['snap'] },
      { b: 'Files', keys: ['files'] },
      { b: 'Database', keys: ['db'] },
      { b: 'Addresses', keys: ['urls'] },
      { b: 'Checks', keys: ['fin', 'check'] }
    ];
    var SAIL = [0, .1, .4, .66, .86, 1];
    var stopOf = {};
    STOPS.forEach(function (st, i) { st.keys.forEach(function (k) { stopOf[k] = i; }); });
    var stopEls = STOPS.map(function (st) {
      var small = h('small', null, ' '), dot = h('span', { class: 'dot' }), b = h('b', null, st.b);
      return { li: h('li', { class: 'dsk-st todo' }, dot, b, small), dot: dot, b: b, small: small };
    });
    var stepEls = {}, current = null, curStop = -1;
    STEPS.forEach(function (s) { var se = stopEls[stopOf[s.key]]; stepEls[s.key] = { el: se.li, meta: se.small }; });
    var strip = h('ol', { class: 'dsk-strip', 'aria-label': 'Voyage' }, stopEls.map(function (se) { return se.li; }));
    // Under the stops: a spinner, what's happening, a bar (filled when the
    // message says "N of M", sliding otherwise), the percent and a running
    // clock, so a long step still visibly moves.
    var lineText = h('span', { class: 't', 'aria-live': 'polite' }), lineClock = h('span', { class: 'clock', title: 'Time since the move started' });
    var lineBar = D.progress(); lineBar.set(null);
    var line = h('div', { class: 'dsk-voy-line' }, h('div', { class: 'row' }, h('span', { class: 'dsk-spin', 'aria-hidden': 'true' }), lineText, lineClock), lineBar);
    var began = Date.now(), clockTimer = 0;
    function tickClock() {
      var s = Math.max(0, Math.round((Date.now() - began) / 1000)), m = Math.floor(s / 60), hr = Math.floor(m / 60);
      lineClock.textContent = hr ? hr + 'h ' + (m % 60) + 'm' : m ? m + 'm ' + ('0' + (s % 60)).slice(-2) + 's' : s + 's';
    }

    // Who's where: the source on the left, the destination on the right.
    var here = D.host(D.config.homeUrl);
    var srcLabel = opts.mode === 'file' ? D.midTrim(opts.file ? opts.file.name : 'backup.zip', 40) : D.host(opts.site);
    var dstLabel = D.host(opts.targetHome);
    var scene = opts.scene || {
      left: { kind: opts.mode === 'file' ? 'zip' : 'site', label: srcLabel },
      right: { kind: 'site', label: dstLabel },
      you: opts.local ? 'right' : 'left'
    };
    var sceneBox = h('div', { class: 'dsk-scene' });
    var boat = { t: null, cargo: 0, dock: 'left', arrived: false, raf: 0 };
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function drawScene() {
      var right = boat.arrived ? Object.assign({}, scene.right, { arrived: true, hot: scene.right.kind === 'site' ? true : scene.right.hot }) : scene.right;
      sceneBox.innerHTML = D.H.scene({ harbor: { left: scene.left, right: right, you: scene.you, youText: 'You are here', t: boat.t, cargo: boat.cargo, alt: 'From ' + scene.left.label + ' to ' + scene.right.label }, dock: boat.dock });
    }
    function sail(to) {
      cancelAnimationFrame(boat.raf);
      var from = boat.t == null ? 0 : boat.t;
      boat.dock = null; boat.cargo = 4;
      if (reduce || to <= from) { boat.t = Math.max(.001, to); drawScene(); return; }
      var start = null, ms = 1100;
      (function frame(now) {
        if (start === null) start = now;
        var k = Math.min(1, (now - start) / ms), e = k < .5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;
        boat.t = Math.max(.001, from + (to - from) * e);
        drawScene();
        if (k < 1) boat.raf = requestAnimationFrame(frame);
      })(performance.now());
    }
    if (D.H.phone.addEventListener) D.H.phone.addEventListener('change', drawScene);

    var outcome = h('div');
    var live = h('span', { class: 'dsk-live' });
    var capsText = h('span', null, KIND + ' in progress · started ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }));
    var title = h('h1');
    var sub = h('p', null, kind === 'pull' ? 'This site is downloading from ' + srcLabel + ', server to server. You can close this tab: the move keeps going, and you can pick it up here.'
      : kind === 'push' ? dstLabel + ' is downloading from this site. You can close this tab: the move keeps going.'
      : 'The backup is coming ashore. Keep this tab open while it uploads.');
    function setTitle(words, accent) { D.mount(title, words ? words + ' ' : '', accent ? h('em', { class: 'hl' }, accent) : null); }
    setTitle(opts.title || 'Restoring', kind === 'push' ? dstLabel : srcLabel);
    var headK = h('span', { class: 'k' }, KIND + '.'), headP = h('p'), headTag = h('span', { class: 'dsk-tag hot tagline' });
    function setHead(parts, tag, tagKind) {
      D.mount(headP, parts.map(function (x) { return x && x.b ? h('b', null, x.b) : x; }));
      headTag.className = 'dsk-tag tagline ' + (tagKind || 'hot');
      D.mount(headTag, tag || '');
      headTag.hidden = !tag;
    }
    var HEADS = [
      kind === 'push' ? ['Loading the boat here. Nothing on ', { b: dstLabel }, ' has changed yet.']
        : kind === 'pull' ? ['Loading the boat at ', { b: srcLabel }, '. Nothing on this site has changed yet.']
        : ['Unloading ', { b: srcLabel }, ' onto the dock. Nothing on this site has changed yet.'],
      ['Saving a restore point. Whatever happens next, this puts ', { b: dstLabel }, ' back as it was.'],
      ['Putting files in place.'],
      ['Importing the database.'],
      ['Rewriting addresses from ', { b: srcLabel }, ' to ', { b: dstLabel }, '.'],
      ['Checking the result.']
    ];
    var cancelBtn = h('button', { class: 'dsk-btn ghost', type: 'button', onclick: askCancel }, D.icon('x'), 'Cancel');
    var notifyBtn = h('button', { class: 'dsk-btn', type: 'button', title: 'Get an email or a Slack/Discord message when this finishes or stops', onclick: askNotify }, D.icon('bell'), h('span', null, 'Notify me'));
    var chooser = h('div', { class: 'dsk-chooser' });
    var headEl = h('div', { class: 'dsk-ph dsk-fade' },
      h('div', { class: 'dsk-ph-main' }, h('div', { class: 'caps' }, live, capsText), title, sub),
      h('div', { class: 'dsk-ph-side' }, notifyBtn, cancelBtn));
    D.mount(view,
      headEl,
      h('section', { class: 'dsk-card dsk-voy' }, h('div', { class: 'dsk-voy-head' }, headK, headP, headTag), sceneBox, strip, line),
      chooser,
      outcome,
      h('details', { class: 'dsk-movelog' }, h('summary', null, D.icon('terminal'), 'Show details'), log));
    setHead(HEADS[0], 'Stop 1 of 6');
    drawScene();
    window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });

    // A question for the user (which site, where it goes, what to bring)
    // pauses the voyage: the header and the current stop say so.
    new MutationObserver(function () {
      var waiting = chooser.childNodes.length > 0;
      if (curStop < 0) return;
      var se = stopEls[curStop];
      if (waiting) {
        live.className = 'dsk-live wait';
        capsText.textContent = 'Paused · waiting on you';
        se.li.className = 'dsk-st wait'; D.mount(se.dot, '?');
        setHead(HEADS[curStop], 'Paused: waiting on you', 'warn');
      } else if (se.li.classList.contains('wait')) {
        live.className = 'dsk-live';
        capsText.textContent = KIND + ' in progress';
        se.li.className = 'dsk-st cur'; D.mount(se.dot);
        setHead(HEADS[curStop], 'Stop ' + (curStop + 1) + ' of 6');
      }
    }).observe(chooser, { childList: true });

    // Cancel: the next request isn't sent (and a long step on a current
    // Disembark stops early). Before the rollback point nothing here has
    // changed, so the copied files are just deleted; after it, the site is
    // rolled back first.
    var cancelled = false, settled = false;
    function Cancelled() { var e = new Error('Cancelled.'); e.cancelled = true; return e; }
    async function askCancel() {
      if (cancelled || settled) return;
      // Once the restore point is asked for, the site may change (a new
      // subsite is made with it). If that happens while the question is
      // open, it's asked again in the right words.
      var after, ok;
      for (var ask = 0; ask < 3; ask++) {
        after = !!rollbackId || snapSent;
        ok = await D.confirm(after
          ? h('div', { class: 'dsk-stack', style: { gap: '10px' } }, h('p', null, D.host(opts.targetHome) + (rollbackId ? ' is partly changed by now.' : ' may be changing already: its restore point is being saved.')), h('p', { class: 'muted' }, 'Cancelling stops the move and rolls it back to the restore point, then deletes the copied files and the restore point.'))
          : h('div', { class: 'dsk-stack', style: { gap: '10px' } }, h('p', null, 'Nothing on ' + D.host(opts.targetHome) + ' has changed yet.'), h('p', { class: 'muted' }, 'Cancelling stops the move and deletes what was copied so far.')),
          { title: after ? 'Cancel and roll back?' : 'Cancel this move?', confirmLabel: after ? 'Cancel and roll back' : 'Cancel the move', cancelLabel: 'Keep going', danger: true });
        if (!ok || cancelled || settled) return;
        if (after === (!!rollbackId || snapSent)) break;
      }
      cancelled = true;
      cancelBtn.disabled = true;
      rlog('Cancelling after the current step\u2026', 'dim');
      progress('Cancelling after the current step\u2026');
      if (chooser.cancelChoice) { chooser.cancelChoice(); return; }
      opts.api.post('import/cancel', t({ action: 'stop' })).catch(function () { /* older Disembark: it stops between steps */ });
    }
    async function cleanUp() {
      cancelBtn.hidden = true; notifyBtn.hidden = true;
      setTitle('Cancelling', '');
      capsText.textContent = 'Cancelling';
      var rolledBack = false;
      // Cancelled while the restore point was being saved: the answer may
      // never have reached this page, so ask the site whether there is one.
      var pointId = rollbackId || (snapSent ? importId : '');
      if (pointId) {
        rlog('Rolling back to the snapshot\u2026');
        try {
          var r = await D.paced(opts.api, 'import/rollback', { import_id: pointId }, function (x) { progress('Rolling back\u2026 ' + (x.phase === 'files' ? D.fmt.num(x.files_done || 0) + ' of ' + D.fmt.plural(x.files_total || 0, 'file') : 'the database')); });
          progress('');
          rollbackId = pointId;
          rolledBack = !(r.errors && r.errors.length);
          (r.errors || []).slice(0, 5).forEach(function (e) { rlog('  ' + e, 'err'); });
          if (r.quiet_mode_lifted) { rlog('Quiet mode lifted with it.', 'dim'); if (opts.local && D.quietOff) D.quietOff(); }
        } catch (e) {
          if (!rollbackId && e.status === 404 && e.data && e.data.code === 'no_rollback') rlog('No restore point was saved, so nothing here changed.', 'dim');
          else {
            rollbackId = pointId;
            if (opts.local && D.quietLifted(e)) { rlog('Quiet mode lifted with it.', 'dim'); D.quietOff(); }
            rlog('ERROR: ' + (D.laterPointsText(e) || D.errorText(e)), 'err');
          }
        }
      }
      var kept = !!rollbackId && !rolledBack;
      try {
        var cl = await opts.api.post('import/cancel', t({ action: 'cleanup', delete_restore_point: rolledBack }));
        // The site still holds a restore point this page didn't hear about: never say nothing changed.
        if (cl && cl.restore_point_kept && !rolledBack) { rollbackId = rollbackId || importId; kept = true; }
        rlog('Cleaned up the copied files' + (rolledBack ? ' and the rollback point.' : '.'), 'ok');
      } catch (e) {
        if (e.status === 404) rlog('This Disembark can\u2019t clean up on cancel; the copied files stay in uploads/disembark until you delete them (Settings \u2192 clean up).', 'dim');
        else rlog('Cleanup: ' + D.errorText(e), 'err');
      }
      setTitle('', 'Cancelled.');
      sub.textContent = kept ? 'The move stopped, but the rollback didn\u2019t finish. The restore point is kept.' : (rollbackId ? D.host(opts.targetHome) + ' is back to how it was, and what was copied is gone.' : 'Nothing on ' + D.host(opts.targetHome) + ' changed, and what was copied is gone.');
      live.className = 'dsk-live stop'; capsText.textContent = KIND + ' cancelled';
      progress('');
      failStop('cancelled');
      setHead(['The move stopped and was cleaned up.'], 'Cancelled', 'warn');
      D.mount(chooser);
      D.mount(outcome, h('div', { class: 'dsk-stack', style: { marginTop: '16px' } },
        kept
          ? D.note('error', [h('strong', null, 'Cancelled, but the rollback didn\u2019t finish. '), 'The restore point is kept: roll back from Backups \u2192 Restore points or with ', h('code', null, 'wp disembark rollback ' + rollbackId), '.'])
          : D.note('ok', [h('strong', null, 'Cancelled. '), rollbackId ? D.host(opts.targetHome) + ' is back to how it was, and the copied files are gone.' : 'Nothing on ' + D.host(opts.targetHome) + ' changed, and the copied files are gone.']),
        h('div', null, h('button', { class: 'dsk-btn', type: 'button', onclick: opts.back }, D.icon('ship'), 'Back to Move'))));
      if (opts.onFinish) opts.onFinish();
    }

    // Calls outside the numbered steps (checks, notifications): they run
    // again on a resume rather than being replayed, and a button press
    // between steps doesn't shift the step numbers a resume relies on. One
    // request id per call, so a retry is still answered once. An older
    // Disembark without the endpoint answers 404, which counts as "no".
    async function side(endpoint, body) {
      var rid = D.randomHex(24);
      try { return await retrying(function () { return opts.api.post(endpoint, Object.assign({ request_id: rid }, t(body))); }); }
      catch (e) { if (e.status === 404) return null; throw e; }
    }
    var startedAt = Date.now(), notifyTo = '';
    var moveKind = !opts.local ? 'push' : (opts.mode === 'site' ? 'pull' : 'restore');
    async function askNotify() {
      var to = await D.prompt({ title: 'Tell me when it\u2019s done', label: 'Email address, or a Slack, Discord or other https:// webhook URL (comma-separated for more than one)', value: notifyTo || D.config.userEmail || '', confirmLabel: 'Notify me' });
      if (to === null) return;
      try {
        var r = await side('import/notify', { action: 'set', to: to });
        if (!r) { D.toast(D.host(opts.targetHome) + ' runs an older Disembark without notifications.', 'error'); return; }
        notifyTo = to.trim();
        notifyBtn.lastChild.textContent = notifyTo ? 'Notifying' : 'Notify me';
        D.toast(notifyTo ? 'You\u2019ll hear when it finishes or stops.' : 'Notifications off.');
      } catch (e) { D.toast(D.errorText(e), 'error'); }
    }
    function sendNotice(event, details) {
      if (!notifyTo) return Promise.resolve();
      return side('import/notify', { action: 'send', event: event, details: Object.assign({
        kind: moveKind, source: opts.site || opts.sourceUrl || (opts.file && opts.file.name) || '', destination: opts.targetHome,
        rollback_id: rollbackId, seconds: Math.round((Date.now() - startedAt) / 1000)
      }, details || {}) }).catch(function () { /* the move's own result matters more */ });
    }

    // Checks before anything changes (app/Checks.php). Problems that would
    // stop the move halfway ask before going on.
    var checked = false;
    async function runChecks() {
      if (checked) return;
      checked = true;
      progress('Checking that the move fits\u2026');
      var r = await side('import/checks', {});
      progress('');
      if (!r) return;
      // A move that can't land here at all (a whole network or install as one).
      if (r.refused) throw new Error(r.refused);
      var marks = { block: '\u2717 ', warn: '! ', info: '\u00b7 ' };
      (r.checks || []).forEach(function (c) { rlog(marks[c.level] + c.title + (c.detail ? ': ' + c.detail : ''), c.level === 'block' ? 'err' : (c.level === 'info' ? 'dim' : '')); });
      if (!(r.checks || []).length) rlog('Checks before the move: nothing in the way.', 'dim');
      if (!r.blocked) return;
      var blocks = r.checks.filter(function (c) { return c.level === 'block'; });
      var go = await D.confirm(h('div', { class: 'dsk-stack', style: { gap: '10px' } },
        h('p', null, 'These would most likely stop the move partway. Nothing on ' + D.host(opts.targetHome) + ' has changed yet.'),
        h('ul', { style: { margin: 0, paddingLeft: '18px' } }, blocks.map(function (c) { return h('li', { style: { marginBottom: '6px' } }, h('strong', null, c.title + '. '), c.detail); }))),
        { title: 'Checks found ' + (blocks.length === 1 ? 'a problem' : blocks.length + ' problems'), confirmLabel: 'Continue anyway', cancelLabel: 'Cancel the move', danger: true });
      if (!go) { cancelled = true; throw Cancelled(); }
      rlog('Going ahead anyway.', 'dim');
    }

    // Checks after the move (app/Verify.php), shown under the result.
    function verifyCard(v) {
      var items = [], good = [];
      var rows = v.rows;
      if (rows) {
        if (!(rows.mismatched || []).length) good.push(D.fmt.plural(rows.checked, 'table') + ' hold every row the source exported');
        (rows.mismatched || []).forEach(function (m) { items.push(h('li', null, h('strong', null, m.table), m.actual === null ? ' is missing.' : ' has ' + D.fmt.num(m.actual) + ' rows; the source exported ' + D.fmt.num(m.expected) + '.')); });
      }
      var pages = v.pages || {}, fine = 0;
      (pages.list || []).forEach(function (p) {
        if (p.problem) items.push(h('li', null, h('strong', null, p.label), ' (', h('a', { href: D.safeHref(p.url), target: '_blank', rel: 'noopener' }, p.url), '): ' + p.problem));
        else fine++;
        if (p.old_address) items.push(h('li', null, h('strong', null, p.label), ' still mentions the old address ' + D.fmt.plural(p.old_address, 'time') + '.'));
      });
      (pages.culprits || []).forEach(function (c) { items.push(h('li', null, 'PHP error' + (c.component ? ' in ' + c.component : '') + ': ', h('code', null, c.message))); });
      if (fine && !pages.note) good.push(D.fmt.plural(fine, 'page') + ' loaded fine');
      var left = v.leftovers;
      if (left && left.rows) {
        items.push(h('li', null, 'The old address is still in ' + D.fmt.plural(left.rows, 'database row') + ': ' + Object.keys(left.tables).slice(0, 5).map(function (t) { return t + ' (' + D.fmt.num(left.tables[t].rows) + (left.tables[t].no_primary_key ? ', no primary key, so the rewrite skipped it' : '') + ')'; }).join(', ') + '.'));
      } else if (left && !left.note) good.push('the old address is gone from the database');
      var body = [h('strong', null, v.ok ? 'Checks after the move: all good. ' : 'Checks after the move found ' + D.fmt.plural(v.problems, 'problem') + '. ')];
      if (good.length) body.push(good.join(', ').replace(/^./, function (c) { return c.toUpperCase(); }) + '.');
      if (pages.note) body.push(' ' + pages.note);
      if (items.length) body.push(h('ul', { style: { margin: '8px 0 0', paddingLeft: '18px' } }, items));
      return h('div', { class: 'dsk-chk' + (v.ok ? '' : ' warn') }, D.icon(v.ok ? 'check' : 'alert'), h('div', null, body));
    }

    function rlog(msg, cls) { log.appendChild(h('div', { class: cls || '' }, msg)); log.scrollTop = log.scrollHeight; }
    function setStop(i, cls) {
      var se = stopEls[i], st = STOPS[i];
      se.li.className = 'dsk-st ' + cls;
      D.mount(se.dot, cls === 'done' ? D.icon('check') : cls === 'failed' ? D.icon('x') : null);
      if (i === 0) se.b.textContent = cls === 'done' ? st.done : st.b;
    }
    function step(key, meta) {
      current = key;
      var i = stopOf[key];
      if (i !== curStop) {
        for (var j = 0; j < i; j++) if (!stopEls[j].li.classList.contains('done')) setStop(j, 'done');
        curStop = i;
        setStop(i, 'cur');
        strip.style.setProperty('--p', i / 5);
        setHead(HEADS[i], 'Stop ' + (i + 1) + ' of 6');
        if (i > 0) sail(SAIL[i]);
      }
      if (meta) stepEls[key].meta.textContent = meta;
    }
    function skip(key, why) { stepEls[key].meta.textContent = why; }
    function failStop(why) {
      if (curStop < 0) return;
      setStop(curStop, 'failed');
      stepEls[current].meta.textContent = why;
    }
    function progress(text) {
      lineText.textContent = text || (line.classList.contains('busy') ? 'Working\u2026' : '');
      var m = /([\d][\d,]*)\s+of\s+([\d][\d,]*)/.exec(text || ''), n = m ? +m[1].replace(/,/g, '') : 0, of = m ? +m[2].replace(/,/g, '') : 0;
      lineBar.set(of > 0 ? Math.min(100, n / of * 100) : null);
    }
    // Running, paused or ended follows the header's live dot.
    function lineState() {
      var c = live.className, busy = c === 'dsk-live', paused = / wait/.test(c) && !/finished/.test(capsText.textContent);
      line.classList.toggle('busy', busy);
      line.classList.toggle('paused', paused && !busy);
      line.hidden = !busy && !paused;
      clearInterval(clockTimer);
      if (busy || paused) { tickClock(); clockTimer = setInterval(tickClock, 1000); }
      if (busy && !lineText.textContent) lineText.textContent = 'Working\u2026';
    }
    new MutationObserver(lineState).observe(live, { attributes: true, attributeFilter: ['class'] });
    lineState();
    var t = function (body) { return Object.assign({ import_id: importId }, body || {}); };

    // Every step carries a request_id. A site that says it's resumable
    // (preflight.resumable, see app/Steps.php) runs each step once, finishes
    // it even when the browser goes away, and answers a resent request_id
    // with the running step's result. So a lost request (a network switch, a
    // gateway timeout) is resent with the same id until the site is back.
    // An older site gets the old behavior: three quick retries on a dropped
    // connection only.
    var resumable = false, outage = 0;
    var TRANSIENT = [0, 408, 429, 502, 503, 504, 520, 521, 522, 523, 524];
    var GIVE_UP_MS = 15 * 60 * 1000;
    function waitForNetwork(ms) {
      return new Promise(function (resolve) {
        var timer, done = function () { clearTimeout(timer); window.removeEventListener('online', done); resolve(); };
        timer = setTimeout(done, ms);
        window.addEventListener('online', done);
      });
    }
    function back(note) {
      if (!outage) return;
      outage = 0;
      rlog(note || 'Reconnected. Carrying on.', 'ok');
      progress('');
    }
    async function retrying(fn) {
      var delay = 2000;
      for (var attempt = 0; ; attempt++) {
        try {
          var r = await fn();
          if (r && r.code === 'disembark_step_running') {
            // The server held the retry while the step kept running; ask again.
            back('Reconnected. The server is still working on this step.');
            delay = 2000;
            continue;
          }
          back();
          busyRun = 0; pauseLevel = 0;
          return r;
        } catch (e) {
          if (!resumable) {
            // Before the preflight (or on an older site): a dropped
            // connection or a busy answer is tried again a few times.
            if (cancelled || [0, 429, 502, 503, 504].indexOf(e.status) === -1 || attempt >= 3) throw e;
            rlog((e.status ? 'The site answered ' + e.status : 'Connection dropped') + ', retrying in ' + (attempt + 1) * 3 + 's\u2026', 'dim');
            await D.sleep((attempt + 1) * 3000);
            continue;
          }
          if (cancelled || TRANSIENT.indexOf(e.status) === -1) throw e;
          // A pacing destination: a host answering busy again and again
          // gets a pause, and a slow host never ends the move.
          if (pacing) await noteFailure(e);
          if (!outage) {
            outage = Date.now();
            rlog(e.status === 0 ? 'Lost the connection. The server keeps working; waiting to reconnect\u2026' : 'The site answered ' + e.status + '. Trying again\u2026', 'dim');
          }
          if (!pacing && Date.now() - outage > GIVE_UP_MS) {
            throw new Error('Couldn\u2019t reach ' + D.host(opts.targetHome) + ' for 15 minutes, so this stopped. The step it was on may still finish on the server. (' + D.errorText(e) + ')');
          }
          progress(e.status === 0 ? (navigator.onLine === false ? 'Offline. Waiting for a connection\u2026' : 'Connection lost. Retrying\u2026') : 'The site answered ' + e.status + '. Retrying\u2026');
          await waitForNetwork(delay);
          delay = Math.min(delay * 2, 15000);
        }
      }
    }
    var api = {
      post: async function (endpoint, body) {
        if (cancelled) throw Cancelled();
        var rid = ridAt(++seq);
        if (Object.prototype.hasOwnProperty.call(replay, rid)) return replay[rid];
        if (replaying) { replaying = false; rlog('Picked up where it stopped.', 'ok'); }
        try {
          var r = await retrying(function () { return opts.api.post(endpoint, Object.assign({ request_id: rid }, body || {})); });
        } catch (e) { if (cancelled) throw Cancelled(); throw e; }
        if (cancelled) throw Cancelled();
        return r;
      },
      upload: async function (endpoint, form) {
        if (cancelled) throw Cancelled();
        form.append('request_id', D.randomHex(24));
        try {
          var r = await retrying(function () { return opts.api.upload(endpoint, form); });
        } catch (e) { if (cancelled) throw Cancelled(); throw e; }
        if (cancelled) throw Cancelled();
        return r;
      }
    };

    // Pace to the host (app/Pace.php). A destination that says "pacing" in
    // its preflight runs each long step a piece at a time: every request
    // stays within a time budget, answers { done: false }, and is asked
    // again. The pull's batches are sized to the host as it goes: bigger
    // after quick answers, halved after slow or failed ones, and up to three
    // at once on a fast host. A failed request is sent again with the same
    // request id after a short wait; a batch that fails twice is split. A
    // host that keeps answering "busy" gets a longer pause, and the move
    // never stops just because the host is slow. The pace learned is kept
    // with the move, so a resume starts there. Anything older gets the old
    // behavior: each step in one request.
    var MB = 1024 * 1024;
    var pacing = false, serverBudget = 20;
    var pace = { files: 400, bytes: 48 * MB, db: 16 * MB, budget: 0, par: 1 };
    var LIMIT = { files: [50, 5000], bytes: [4 * MB, 512 * MB], db: [1 * MB, 128 * MB] };
    var START = { files: pace.files, bytes: pace.bytes, db: pace.db };
    if (opts.resume && opts.resume.pace) Object.keys(pace).forEach(function (k) { var v = +opts.resume.pace[k]; if (v > 0) pace[k] = v; });
    var lastTrouble = 0, fastStreak = 0, busyRun = 0, pauseLevel = 0, inFiles = false;
    function h8(n) { return ('00000000' + n.toString(16)).slice(-8); }
    // A step that takes several requests uses one number of the move's
    // sequence and counts its own requests under it, so a resumed move
    // lines up whatever pace it ran at.
    function ridFor(s, k) { return importId.slice(0, 24) + h8(s) + h8(k); }
    function clamp(v, lim) { return Math.max(lim[0], Math.min(lim[1], Math.round(v))); }
    function paceNote(files) {
      if (files && pace.par > 1) return 'Fast host: ' + pace.par + ' batches at once';
      if (pace.budget && pace.budget < serverBudget) return 'Slow host: working in smaller pieces';
      if (pace.files < START.files || pace.bytes < START.bytes || pace.db < START.db) return 'Slow host: working in smaller pieces';
      return '';
    }
    function paced(text, files) { var n = paceNote(files); progress(text + (n ? ' · ' + n : '')); }
    function paceBody(extra) { return Object.assign(t(), { paced: 1, pace: pace }, pace.budget ? { budget: pace.budget } : {}, extra || {}); }
    // WordPress's own answer when PHP dies mid-request (a time limit the
    // host won't lift, memory) is a coded JSON 500 too, but it's the host
    // cutting the request off, not a deliberate error from Disembark.
    function hasCode(e) { return !!(e && e.data && typeof e.data === 'object' && e.data.code && e.data.code !== 'internal_server_error'); }
    // Worth asking again: dropped, timed out, busy, or cut off by the host.
    // A deliberate error from Disembark (a JSON error with a code) isn't,
    // except the source failing or not answering in time.
    function transientError(e) {
      if (!e || e.cancelled) return false;
      if (e.status === 0 || e.timeout) return true;
      if ([408, 425, 429, 503, 504, 520, 521, 522, 523, 524].indexOf(e.status) !== -1) return true;
      if (e.status === 500 || e.status === 502) return !hasCode(e) || ['source_error', 'source_unreachable', 'http_request_failed'].indexOf(e.data.code) !== -1;
      return false;
    }
    // The host killed the request (no answer, or a gateway's): the next one asks for less.
    function killedError(e) {
      return e.status === 0 || e.timeout || ((e.status === 504 || e.status === 500 || e.status === 502 || e.status >= 520) && !hasCode(e));
    }
    // Too much asked at once: this host or the source cut the request off,
    // or the source didn't answer in time (not a busy answer, which is luck).
    function sizeError(e) {
      if (killedError(e)) return true;
      var c = e.data && e.data.code, st = e.data && e.data.data && e.data.data.source_status;
      return c === 'source_unreachable' || (c === 'source_error' && (st === 500 || st === 502 || st === 504 || st >= 520));
    }
    // Halved when the host cuts a request off (never below 5s); after a run
    // of answers it grows back, though never to within a quarter of a
    // budget that was cut off.
    var cutAt = 0, budgetRun = 0;
    function shrinkBudget() {
      var cur = pace.budget || serverBudget, next = Math.max(5, Math.floor(cur / 2));
      cutAt = cutAt ? Math.min(cutAt, cur) : cur;
      budgetRun = 0;
      if (next >= cur) return;
      pace.budget = next;
      rlog('The host cut off a request. Asking for ' + next + 's of work per request from now on.', 'dim');
    }
    function growBudget() {
      if (!pace.budget || ++budgetRun < 8) return;
      budgetRun = 0;
      var cap = Math.min(serverBudget, Math.floor((cutAt || serverBudget / 0.75) * 0.75)), next = Math.min(cap, Math.floor(pace.budget * 1.5));
      if (next <= pace.budget) return;
      pace.budget = next;
      rlog('The host has kept up. Asking for ' + next + 's of work per request.', 'dim');
    }
    function backoffMs(n, e) {
      var ms = Math.min(30000, 1000 * Math.pow(2, n - 1));
      ms = ms * (0.7 + Math.random() * 0.6);
      if (e && e.retryAfter) ms = Math.max(ms, Math.min(30, e.retryAfter) * 1000);
      return Math.round(ms);
    }
    async function wait(ms) {
      var end = Date.now() + ms;
      while (Date.now() < end) {
        if (cancelled) throw Cancelled();
        await D.sleep(Math.min(500, end - Date.now()));
      }
    }
    // Several "busy" answers in a row: the host is struggling. Pause
    // (30s, then 60s, up to 5 minutes) and carry on.
    async function hostPause() {
      var secs = [30, 60, 120, 240, 300][Math.min(pauseLevel, 4)];
      pauseLevel++;
      rlog('The host answered busy ' + busyRun + ' times in a row. Pausing ' + secs + 's before going on.', 'dim');
      var line = 'Slow host: waiting ' + secs + 's and trying again';
      progress(line);
      await wait(secs * 1000);
      busyRun = 0;
    }
    async function noteFailure(e) {
      lastTrouble = Date.now(); fastStreak = 0;
      if (pace.par > 1) { pace.par = 1; if (inFiles) rlog('A request failed. One batch at a time from now on.', 'dim'); }
      busyRun = (e.status === 503 || e.status === 429) ? busyRun + 1 : 0;
      if (busyRun >= 5) await hostPause();
    }
    /**
     * Sends one paced request with request id rid, again after each
     * failure with the same id. body() makes the body afresh each time (the
     * budget may have shrunk). onFail(e, n) answers 'retry' or 'split'
     * (throws an error with .split set). Resolves { r, ms, failures }.
     */
    async function pacedPost(endpoint, body, rid, onFail) {
      if (Object.prototype.hasOwnProperty.call(replay, rid)) return { r: replay[rid], ms: 0, failures: 0, replayed: true };
      if (replaying) { replaying = false; rlog('Picked up where it stopped.', 'ok'); }
      for (var n = 0; ;) {
        if (cancelled) throw Cancelled();
        var t0 = Date.now(), r;
        try {
          r = await opts.api.post(endpoint, Object.assign({ request_id: rid }, body()), { timeout: 300000 });
        } catch (e) {
          if (cancelled) throw Cancelled();
          if (!transientError(e)) throw e;
          n++;
          await noteFailure(e);
          var what = onFail ? onFail(e, n) : 'retry';
          if (what === 'split') { var se = new Error('split'); se.split = true; se.cause = e; throw se; }
          if (what === 'stop') throw e;
          var ms = backoffMs(n, e);
          rlog((e.status ? 'The site answered ' + e.status : (e.timeout ? 'No answer in time' : 'The connection dropped')) + '. Trying again in ' + Math.max(1, Math.round(ms / 1000)) + 's (attempt ' + (n + 1) + ').', 'dim');
          await wait(ms);
          continue;
        }
        if (r && r.code === 'disembark_step_running') { await wait(1500); continue; }
        busyRun = 0; pauseLevel = 0;
        if (cancelled) throw Cancelled();
        return { r: r, ms: Date.now() - t0, failures: n };
      }
    }
    /** A step that works until its budget and is asked again until done. Returns its last answer. */
    async function budgeted(endpoint, body, onStep) {
      var s = ++seq, k = 0, r;
      do {
        var res = await pacedPost(endpoint, function () { return paceBody(body); }, ridFor(s, ++k), function (e) {
          if (killedError(e)) shrinkBudget();
          return 'retry';
        });
        r = res.r;
        if (!res.replayed && !res.failures) growBudget(); else if (res.failures) budgetRun = 0;
        if (r && r.done === false && onStep) onStep(r);
      } while (r && r.done === false);
      return r;
    }
    /** Grows or shrinks a batch size after an answer that took ms (never after a retry). */
    function adapt(key, ms, failures) {
      if (failures) return;
      var before = pace[key];
      if (ms < 4000) pace[key] = clamp(pace[key] * 1.5, LIMIT[key]);
      else if (ms < 8000) pace[key] = clamp(pace[key] * 1.25, LIMIT[key]);
      else if (ms > 18000) pace[key] = clamp(pace[key] / 2, LIMIT[key]);
      else if (ms > 12000) pace[key] = clamp(pace[key] * 0.8, LIMIT[key]);
      if (pace[key] < before && ms > 18000) rlog('Slow answer (' + Math.round(ms / 1000) + 's). Smaller batches from now on.', 'dim');
    }
    function halve(key, why) {
      var before = pace[key];
      pace[key] = clamp(pace[key] / 2, LIMIT[key]);
      if (pace[key] < before) rlog(typeof why === 'function' ? why() : why, 'dim');
    }
    function ranges(list) {
      var n = 0;
      (list || []).forEach(function (r) { n += r[1] - r[0]; });
      return n;
    }
    function mergeRanges(a, b) {
      var all = (a || []).concat(b || []).map(function (r) { return [r[0], r[1]]; }).sort(function (x, y) { return x[0] - y[0]; }), out = [];
      all.forEach(function (r) {
        if (r[1] <= r[0]) return;
        var last = out[out.length - 1];
        if (last && r[0] <= last[1]) last[1] = Math.max(last[1], r[1]); else out.push(r);
      });
      return out;
    }
    function firstGap(list, total) {
      var at = 0;
      for (var i = 0; i < list.length; i++) {
        if (list[i][0] > at) return [at, Math.min(list[i][0], total)];
        at = Math.max(at, list[i][1]);
      }
      return at < total ? [at, total] : null;
    }
    function covered(list, a, b) {
      return list.some(function (r) { return r[0] <= a && r[1] >= b; });
    }

    /**
     * The pull's files, in batches sized to the host, up to three at once.
     * What is left always comes from the ranges the server says arrived.
     */
    async function pullFiles() {
      var s = ++seq, k = 0, total = null, done = [], busy = [], forced = [], running = 0;
      function show() {
        if (total === null) return;
        var n = Math.min(total, ranges(done));
        paced('Pulling files… ' + D.fmt.num(n) + ' of ' + D.fmt.num(total), true);
        stepEls.stage.meta.textContent = D.fmt.num(n) + ' / ' + D.fmt.num(total);
      }
      function take(r) {
        if (!r || typeof r !== 'object') return;
        if (typeof r.total === 'number') total = r.total;
        if (r.ranges) done = mergeRanges(done, r.ranges);
      }
      function next() {
        while (forced.length) {
          var f = forced.shift();
          if (!covered(done, f.offset, f.offset + f.count)) return f;
        }
        if (total === null) return running ? null : { offset: 0, count: pace.files };
        var gap = firstGap(mergeRanges(done, busy), total);
        return gap ? { offset: gap[0], count: Math.min(gap[1] - gap[0], pace.files) } : null;
      }
      async function byRanges(task) {
        var chunk = 8 * MB;
        for (;;) {
          var res;
          try {
            res = await pacedPost('import/pull/fetch-files', function () { return paceBody({ offset: task.offset, count: 1, range: 1, chunk: chunk }); }, ridFor(s, ++k), function (e, n) {
              if (killedError(e)) shrinkBudget();
              if (n >= 2 && chunk > 256 * 1024) { chunk = Math.max(256 * 1024, Math.floor(chunk / 2)); rlog('Smaller pieces for that file (' + D.fmt.size(chunk) + ').', 'dim'); }
              return 'retry';
            });
          } catch (e) {
            if (e.data && e.data.code === 'no_ranges') { forced.unshift({ offset: task.offset, count: 1, whole: true }); return; }
            throw e;
          }
          take(res.r);
          if (covered(done, task.offset, task.offset + 1) || (res.r && res.r.file_done)) return;
          if (res.r && res.r.size) paced('Pulling a large file in pieces… ' + D.fmt.size(res.r.got || 0) + ' of ' + D.fmt.size(res.r.size));
        }
      }
      async function run(task) {
        if (task.range) return byRanges(task);
        var res;
        try {
          res = await pacedPost('import/pull/fetch-files', function () { return paceBody({ offset: task.offset, count: task.count, max_bytes: task.bytes || pace.bytes }); }, ridFor(s, ++k), function (e, n) {
            if (sizeError(e)) task.killed = true;
            // One file the source keeps refusing stops the move (resumable)
            // instead of being asked for again forever.
            if (task.whole) return n >= 6 ? 'stop' : 'retry';
            return n >= 2 ? 'split' : 'retry';
          });
        } catch (e) {
          if (!e.split && task.whole) rlog('The source kept refusing file ' + D.fmt.num(task.offset + 1) + '.', 'err');
          if (!e.split) throw e;
          if (task.count > 1) {
            var half = Math.ceil(task.count / 2), bytes = Math.max(LIMIT.bytes[0], Math.round((task.bytes || pace.bytes) / 2));
            rlog('A batch of ' + D.fmt.plural(task.count, 'file') + ' failed twice. Splitting it in two.', 'dim');
            // Cut off by the host (not just a busy answer): too big for it.
            if (task.killed) {
              pace.bytes = clamp(pace.bytes / 2, LIMIT.bytes);
              halve('files', function () { return 'Smaller file batches from now on (' + D.fmt.num(pace.files) + ' files).'; });
            }
            forced.unshift({ offset: task.offset, count: half, bytes: bytes }, { offset: task.offset + half, count: task.count - half, bytes: bytes });
          } else {
            rlog('File ' + D.fmt.num(task.offset + 1) + ' failed twice in a batch. Fetching it in pieces.', 'dim');
            forced.unshift({ offset: task.offset, count: 1, range: true });
          }
          return;
        }
        take(res.r);
        if (res.replayed) return;
        adapt('files', res.ms, res.failures);
        adapt('bytes', res.ms, res.failures);
        if (res.failures || res.ms >= 8000) {
          fastStreak = 0;
          if (res.ms > 12000 && pace.par > 1) { pace.par--; rlog('Answers are slowing down. ' + D.fmt.plural(pace.par, 'batch', 'batches') + ' at once.', 'dim'); }
        } else if (Date.now() - lastTrouble > 60000 && total !== null && ++fastStreak >= 3 && pace.par < 3) {
          fastStreak = 0;
          pace.par++;
          rlog('Fast host: ' + pace.par + ' batches at once.', 'dim');
        }
      }
      inFiles = true;
      await new Promise(function (resolve, reject) {
        var failed = false;
        function pump() {
          if (failed) return;
          for (;;) {
            if (running >= (total === null ? 1 : pace.par)) break;
            var task = next();
            if (!task) break;
            running++;
            var held = [task.offset, task.offset + task.count];
            busy.push(held);
            run(task).then(function () {
              running--; busy.splice(busy.indexOf(held), 1); show(); pump();
            }, function (e) { failed = true; reject(e); });
          }
          if (!running && total !== null && ranges(done) >= total) resolve();
          else if (!running && total !== null && !forced.length && !firstGap(done, total)) resolve();
        }
        pump();
      }).finally(function () { inFiles = false; });
      pace.par = 1;
      return total || 0;
    }

    /** The pull's database: a batch of tables (or part of a big one) per request, sized to the host. */
    async function pullDatabase() {
      var s = ++seq, k = 0, r;
      do {
        var tries = 0;
        var cut = false;
        // Fingerprints are taken for the next catch-up; on a catch-up,
        // tables unchanged on both sides since the last pull stay as they are.
        var res = await pacedPost('import/pull/fetch-database', function () { return paceBody({ batch_bytes: pace.db, fingerprints: 1, skip_unchanged: opts.catchUp && !opts.fullDatabase ? 1 : 0 }); }, ridFor(s, ++k), function (e, n) {
          tries = n;
          cut = cut || sizeError(e);
          // Twice, and cut off at least once (not just busy): a smaller batch.
          if (n >= 2 && cut) halve('db', function () { return 'A database batch failed twice. Smaller ones from now on (' + D.fmt.size(pace.db) + ').'; });
          return 'retry';
        });
        r = res.r;
        if (r && r.phase === 'compare') { paced('Comparing tables with the last pull (' + r.side + ')\u2026 ' + D.fmt.num(r.compared || 0) + ' of ' + D.fmt.num(r.total || 0)); continue; }
        if (!res.replayed) adapt('db', res.ms, res.failures || tries);
        if (r && r.done === false) paced('Pulling the database… ' + D.fmt.num(r.tables_done || 0) + ' of ' + D.fmt.plural(r.total_tables || 0, 'table') + ' (' + D.fmt.size(r.bytes || 0) + ')');
      } while (r && r.done === false);
      return r;
    }

    async function stageFromSite() {
      rlog('Connecting to ' + opts.site + '…');
      var c = await api.post('import/pull/connect', t({ source_url: opts.site, source_token: opts.token, catch_up: !!opts.catchUp, site: opts.sourceSite || null,
        move: { kind: opts.local ? 'pull' : 'push', quiet: !!opts.quiet, label: opts.label || '' } }));
      // A network or WP Freighter install reached at its main address: ask
      // which site to bring (replayed rather than asked again on a resume).
      if (c.install && !c.scope && (c.install.sites || []).length > 1 && !opts.sourceSite) {
        var pick = 'all';
        if (!Object.prototype.hasOwnProperty.call(replay, ridAt(seq + 1))) {
          progress('Choose which site to bring\u2026');
          pick = await D.chooseSite(chooser, c.install, opts.lastSite);
          if (!pick) { cancelled = true; throw Cancelled(); }
        }
        c = await api.post('import/pull/site', t({ site: pick }));
        progress('');
      }
      rlog('Connected. Source: ' + c.source_home + ' (' + c.table_count + ' tables).');
      // One site of an install comes through the install's address: name
      // the move after the site that comes over.
      if (c.scope && c.source_home && kind === 'pull') { srcLabel = D.host(c.source_home); setTitle(opts.title || 'Restoring', srcLabel); }
      if (c.scope) {
        rlog('Bringing one site: ' + c.scope.label + '. It arrives as a standalone site.', 'ok');
        if (c.scope.users !== null && c.scope.users !== undefined) rlog(D.fmt.plural(c.scope.users, 'user') + (c.scope.users === 1 ? ' comes' : ' come') + ' across: its members, the authors of its posts, and the network\u2019s super admins (as administrators, unless you leave out the ones with no role there).', 'dim');
        var lo = c.scope.left_out || {};
        if ((lo.plugins || []).length || (lo.themes || []).length) rlog('Leaving out ' + D.fmt.plural((lo.plugins || []).length, 'plugin') + ' and ' + D.fmt.plural((lo.themes || []).length, 'theme') + ' that this site doesn\u2019t use.', 'dim');
        if ((c.scope.shared_tables || []).length) rlog(D.fmt.plural(c.scope.shared_tables.length, 'network-wide table') + (c.scope.shared_tables.length === 1 ? ' comes' : ' come') + ' across empty, since ' + (c.scope.shared_tables.length === 1 ? 'it holds' : 'they hold') + ' every site\u2019s data: ' + c.scope.shared_tables.slice(0, 6).join(', ') + (c.scope.shared_tables.length > 6 ? ', \u2026' : '') + '.', 'dim');
      } else if (c.install) rlog('Bringing the whole ' + (c.install.kind === 'multisite' ? 'network' : 'WP Freighter install') + ' (' + D.fmt.plural(c.install.total || 0, 'site') + ').', 'dim');
      var scan = function (s, chunk) { return api.post('import/pull/scan', t({ step: s, chunk: chunk || 0 })); };
      await scan('initiate');
      for (var i = 0; i < 100000; i++) {
        var s = await scan('scan');
        if (s.scanned_dirs && s.total_dirs) progress('Scanning source folders… ' + D.fmt.num(s.scanned_dirs) + ' of ' + D.fmt.num(s.total_dirs));
        if (s.status === 'scan_complete') break;
      }
      progress('Listing the source\u2019s files\u2026');
      var ch = await scan('chunkify');
      for (var c2 = 1; c2 <= (ch.total_chunks || 0); c2++) {
        progress('Listing the source\u2019s files\u2026 part ' + c2 + ' of ' + ch.total_chunks);
        await scan('process_chunk', c2);
      }
      progress('Reading the file list\u2026');
      var fin = await scan('finalize');
      catchUpInfo = fin.catch_up || null;
      if (fin.catch_up) {
        var cu = fin.catch_up;
        var lb = cu.left_before;
        rlog('Catching up: ' + (lb === null || lb === undefined ? D.fmt.plural(cu.changed, 'file') + ' new, changed or left behind since the last pull (' + D.fmt.date(cu.since) + '), '
          : D.fmt.plural(cu.changed, 'file') + ' new or changed since the last pull (' + D.fmt.date(cu.since) + '), ' + (lb ? D.fmt.plural(lb, 'file') + ' that pull left behind (What to bring decides again), ' : '')) + D.fmt.plural(cu.removed, 'file') + ' removed on the source. ' + D.fmt.plural(cu.unchanged, 'unchanged file') + ' stay as they are.');
        if (cu.approx) rlog('The source runs an older Disembark that doesn\u2019t report file times, so files were compared by size only.', 'dim');
      } else {
        if (fin.catch_up === false) rlog('Nothing is remembered from an earlier pull of this source, so everything comes across.', 'dim');
        rlog(D.fmt.plural(fin.total_files || 0, 'file') + ' found.');
      }
      await choose();
      await runChecks();
      var done = false, fetched = 0;
      if (pacing) fetched = await pullFiles();
      while (!pacing && !done) {
        var r = await api.post('import/pull/fetch-files', t({ offset: fetched, count: 1000 }));
        fetched = r.fetched; done = r.done;
        progress('Pulling files… ' + D.fmt.num(fetched) + ' of ' + D.fmt.num(r.total));
        stepEls.stage.meta.textContent = D.fmt.num(fetched) + ' / ' + D.fmt.num(r.total);
      }
      rlog('Pulled ' + D.fmt.plural(fetched, 'file') + '.');
      progress('Pulling the database…');
      var db = pacing ? await pullDatabase() : await api.post('import/pull/fetch-database', t());
      if (db && db.single) rlog('The source runs an older Disembark, so its database came in one request.', 'dim');
      if (db && db.plan) tablePlan = db.plan;
      if (tablePlan && tablePlan.nofp) rlog('Every table comes across: ' + tablePlan.nofp + '.', 'dim');
      else if (tablePlan) {
        rlog('Comparing with the last pull: ' + D.fmt.plural(tablePlan.copied, 'table') + ' to copy, ' + D.fmt.num(tablePlan.skipped.length) + ' unchanged on both sides.', 'dim');
        Object.keys(tablePlan.changed || {}).forEach(function (tb) { rlog('  ' + tb + ': ' + tablePlan.changed[tb] + '.', 'dim'); });
      }
      rlog('Database pulled (' + (db.tables || 0) + ' tables).');
      return String(c.source_home || '').replace(/\/$/, '');
    }
    // "What to bring" (bring.js): pause after the scan so old backups,
    // caches and logs can stay behind. Skipped by a destination that
    // predates it.
    async function choose() {
      var contents;
      progress('Sizing up the source\u2026');
      try { contents = await api.post('import/pull/contents', t()); }
      catch (e) {
        if (e.status === 404) { rlog(D.host(opts.targetHome) + ' runs an older Disembark, so everything comes across.', 'dim'); return; }
        throw e;
      }
      // Resuming after the choice was made: replay it rather than asking again.
      var choice = {};
      if (!Object.prototype.hasOwnProperty.call(replay, ridAt(seq + 1))) {
        progress('Choose what to bring\u2026');
        // srcLabel: the site that comes over (one site of an install is reached through its address).
        choice = await D.whatToBring(chooser, contents, { source: kind === 'pull' ? srcLabel : opts.site, quiet: opts.quiet, catchUp: catchUpInfo });
      }
      if (!choice) { cancelled = true; throw Cancelled(); }
      if (cancelled) throw Cancelled();
      var sel = await api.post('import/pull/select', t(choice));
      superBrought = (sel && sel.super_admins) || [];
      var left = sel.left_out || {};
      if (left.files || (left.rows && left.rows.length)) {
        rlog('Leaving behind ' + D.fmt.plural(left.files || 0, 'file') + ' (' + D.fmt.size(left.bytes || 0) + ')' + (left.rows && left.rows.length ? ' and the rows of ' + D.fmt.plural(left.rows.length, 'table') : '') + '.', 'dim');
        (left.paths || []).slice(0, 8).forEach(function (p) { rlog('  ' + p, 'dim'); });
        if ((left.paths || []).length > 8) rlog('  \u2026 and ' + (left.paths.length - 8) + ' more', 'dim');
      }
      rlog(D.fmt.plural(sel.files || 0, 'file') + ' to pull (' + D.fmt.size(sel.bytes || 0) + ').');
      progress('');
    }

    async function upload(file, chunkBytes) {
      var size = chunkBytes > 0 ? chunkBytes : 20 * 1024 * 1024;
      var total = Math.max(1, Math.ceil(file.size / size));
      if (total === 1) {
        var fd = new FormData(); fd.append('import_id', importId); fd.append('file', file, 'restore.zip');
        progress('Uploading ' + D.fmt.size(file.size) + '…');
        await api.upload('import/upload-zip', fd);
        return;
      }
      for (var i = 0; i < total; i++) {
        if (cancelled) throw Cancelled();
        var part = new FormData();
        part.append('import_id', importId); part.append('file_path', 'restore.zip');
        part.append('chunk_index', i); part.append('total_chunks', total);
        part.append('file', file.slice(i * size, Math.min(file.size, (i + 1) * size)), 'restore.zip');
        await api.upload('import/upload-chunk', part);
        progress('Uploading… part ' + (i + 1) + ' of ' + total);
        stepEls.stage.meta.textContent = Math.round(((i + 1) / total) * 100) + '%';
      }
      progress('Unpacking the backup…');
      if (pacing) await budgeted('import/extract-zip', t({ file_path: 'restore.zip' }), function (r) { paced('Unpacking the backup\u2026 ' + D.fmt.num(r.entries_done || 0) + ' of ' + D.fmt.num(r.entries_total || 0)); });
      else await api.post('import/extract-zip', t({ file_path: 'restore.zip' }));
    }

    // The arrival: every stop done, the boat moored on the far side, and two
    // cards under the voyage (what to do next, and the checks).
    var doneBox = h('div', { class: 'dsk-card' }), checks = h('div', { class: 'dsk-card' });
    function arrive(verify) {
      settled = true;
      cancelAnimationFrame(boat.raf);
      stopEls.forEach(function (se, i) { setStop(i, 'done'); });
      strip.style.setProperty('--p', 1);
      boat.t = 1; boat.cargo = 0; boat.dock = 'right'; boat.arrived = true; drawScene();
      var bad = !!(verify && !verify.ok);
      live.className = 'dsk-live ' + (bad ? 'wait' : 'ok');
      var mins = Math.max(1, Math.round((Date.now() - startedAt) / 60000));
      capsText.textContent = KIND + ' finished \u00b7 ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + ' \u00b7 took ' + D.fmt.plural(mins, 'minute');
      if (bad) setTitle('Ashore, with', D.fmt.plural(verify.problems, 'problem') + '.'); else setTitle('Safely', 'ashore.');
      sub.textContent = kind === 'push' ? (ctx.target ? D.host(ctx.target.home) : dstLabel) + ' now holds a copy of this site.' : (ctx.target ? D.host(ctx.target.home) : dstLabel) + ' now holds a copy of ' + srcLabel + '.';
      headK.textContent = 'Arrived.';
      setHead([bad ? 'Everything came across, but the checks after the move found something to look at (below). The restore point is kept if you\u2019d rather undo.' : 'Everything on the manifest came ashore. The restore point is kept in case you change your mind.'], bad ? D.fmt.plural(verify.problems, 'problem') : 'All 6 stops', bad ? 'warn' : 'ok');
      progress('');
      cancelBtn.hidden = true;
      D.mount(chooser);
      D.mount(outcome, h('div', { class: 'dsk-after dsk-fade' }, doneBox, checks));
      if (opts.onFinish) opts.onFinish();
    }

    var ctx = {
      undoDetails: function () {
        return h('details', { class: 'dsk-gtk', style: { marginTop: '16px' } },
          h('summary', null, D.icon('terminal'), 'Undo from the terminal', D.icon('chevron', 'chev')),
          h('div', { class: 'dsk-gtk-body' }, ctx.cliUndo()));
      },
      importId: importId,
      rollbackId: function () { return rollbackId; },
      cliUndo: function () { return D.cmd('disembark restore ' + opts.targetHome + ' --rollback=' + rollbackId); },
      rollback: rollback,
      back: opts.back,
      againLabel: opts.againLabel || 'Start another move',
      icon: opts.icon || 'ship',
      log: rlog,
      quietOn: function () { return quietOn; }
    };

    (async function () {
      try {
        step('pre');
        if (opts.resume) rlog('Resuming the move started ' + D.fmt.date(opts.resume.started) + ': replaying ' + D.fmt.plural(Object.keys(replay).length, 'finished step') + '\u2026', 'dim');
        var pre = await api.post('import/preflight', t());
        // A subsite or tenant reached directly (a push to its own connect
        // line, or its own dashboard): a whole-site move would replace the
        // files every site there shares. Stopped before anything changes.
        if (pre.refused) throw new Error(pre.refused);
        resumable = !!pre.resumable;
        pacing = !!pre.pacing;
        if (pre.budget > 0) serverBudget = +pre.budget;
        if (pacing && opts.resume && opts.resume.pace) rlog('Carrying on at the pace learned before: ' + D.fmt.plural(pace.files, 'file') + ' a batch' + (pace.budget ? ', ' + pace.budget + 's a request' : '') + '.', 'dim');
        var destPrefix = pre.db_prefix || '', destHome = String(pre.home_url || opts.targetHome).replace(/\/$/, '');
        opts.targetHome = destHome;
        rlog('Destination: ' + destHome + ' (prefix ' + destPrefix + ').', 'dim');
        // The main site of a network or WP Freighter install: a new subsite
        // or tenant, or one to replace (asked once; replayed on a resume).
        // A resumed pull that originally targeted the main site skipped
        // import/target, so replay[seq+1] is import/pull/connect (no 'target'
        // key) — skip import/target here too so step replay stays aligned.
        var inst = pre.install;
        var nextKey = ridAt(seq + 1), hasNext = Object.prototype.hasOwnProperty.call(replay, nextKey);
        var resumedMain = !!(opts.resume && !opts.into && hasNext && !(replay[nextKey] && typeof replay[nextKey] === 'object' && 'target' in replay[nextKey]));
        if (inst && inst.current === inst.main && (inst.sites || []).length && opts.into !== 'site' && !resumedMain) {
          var into = opts.into || 'site';
          if (!opts.into && !hasNext) {
            progress('Choose where it goes\u2026');
            into = await D.chooseInto(chooser, inst, D.host(destHome));
            if (!into) { cancelled = true; throw Cancelled(); }
            progress('');
          }
          var tg = await api.post('import/target', t({ into: into }));
          target = tg && tg.target;
          if (target) { opts.catchUp = false; ctx.target = target; rlog('It goes into ' + target.label + '. The rest of ' + D.host(destHome) + ' stays as it is.', 'ok'); }
        }

        step('stage');
        var siteHome = '';
        if (opts.mode === 'site') siteHome = await stageFromSite();
        else { rlog('Uploading ' + opts.file.name + '…'); await upload(opts.file, pre.upload_chunk_bytes); await runChecks(); }

        step('read'); progress('');
        var info = await api.post('import/staged-info', t());
        if (info.refused) throw new Error(info.refused);
        // A zip whose database isn't clear (or has none) asks before anything changes.
        var dbi = info.database || {};
        if (kind === 'restore' && dbi.ask) {
          var dbPick = 'none';
          if (!Object.prototype.hasOwnProperty.call(replay, ridAt(seq + 1))) {
            progress('Choose the database\u2026');
            dbPick = await D.chooseDatabase(chooser, dbi, srcLabel);
            if (dbPick === null) { cancelled = true; throw Cancelled(); }
            progress('');
          }
          info = await api.post('import/staged-info', t({ database: dbPick }));
          if (info.refused) throw new Error(info.refused);
          dbi = info.database || {};
        }
        var srcPrefix = (info.source && info.source.db_prefix) || '';
        var srcHome = String(opts.sourceUrl || siteHome || (info.source && info.source.home_url) || '').replace(/\/$/, '');
        rlog(D.fmt.plural(info.files || 0, 'file') + ' staged' + (info.sql_file ? ', database ' + (kind === 'restore' && dbi.why ? info.sql_file + ' (' + dbi.why + ').' : 'found.') : dbi.files_only ? ', no database: the files only, as you chose.' : ', no database.'));
        stepEls.read.meta.textContent = D.fmt.plural(info.files || 0, 'file');
        if (info.multisite && info.multisite.length) rlog('The source is a multisite network. This site keeps its own wp-config.php, so add: ' + info.multisite.join(' '), 'err');

        step('snap');
        // Named for the site that came over (see srcLabel above).
        snapLabel = String(opts.label || '');
        var siteHost = kind === 'pull' && opts.site ? D.host(opts.site) : '';
        if (siteHost && siteHost !== srcLabel) snapLabel = snapLabel.replace(siteHost, srcLabel);
        snapSent = true;
        var snap = pacing ? await budgeted('import/snapshot', t({ label: snapLabel }), function (r) { paced('Saving a rollback point\u2026 ' + D.fmt.num(r.tables_done || 0) + ' of ' + D.fmt.plural(r.tables_total || 0, 'table')); })
          : await api.post('import/snapshot', t({ label: snapLabel }));
        progress('');
        rollbackId = importId;
        rlog('Rollback point saved (id ' + importId + ').', 'ok');
        if (snap && snap.target) {
          target = ctx.target = snap.target;
          destHome = String(target.home).replace(/\/$/, ''); destPrefix = target.prefix;
          rlog((target.created ? 'Created ' : 'Replacing ') + (target.kind === 'multisite' ? 'subsite ' : 'tenant ') + target.id + ' at ' + destHome + ' (tables ' + destPrefix + '*)' + (target.created ? '. Rolling back removes it.' : '.'), 'ok');
        }
        stepEls.snap.meta.textContent = 'saved \u00b7 ' + importId.slice(0, 6);
        rlog('Whatever happens next, this puts ' + D.host(destHome) + ' back as it was: disembark restore ' + opts.targetHome + ' --rollback=' + rollbackId, 'dim');

        // Quiet mode goes on before the files and database arrive, so no
        // request in between can run the source's jobs or send its emails.
        if (opts.quiet && target) rlog('Quiet mode stays off: it would cover every site here, not just this ' + (target.kind === 'multisite' ? 'subsite' : 'tenant') + '.', 'dim');
        else if (opts.quiet) {
          if (srcHome && D.address(srcHome) !== D.address(destHome)) {
            try { await api.post('quiet-mode', t({ action: 'on', source: srcHome })); }
            catch (e) {
              if (e.status === 404) throw new Error(D.host(destHome) + ' runs an older Disembark without quiet mode. Update Disembark there, or turn quiet mode off for this move.');
              throw e;
            }
            quietOn = true; quietSource = srcHome;
            rlog('Quiet mode on: emails held, scheduled jobs paused, checkout closed, payment and marketing APIs blocked.', 'ok');
          } else rlog(srcHome ? 'Same address as the source, so quiet mode isn\u2019t needed.' : info.sql_file ? 'The backup doesn\u2019t say its address, so quiet mode waits for its database.' : 'No database comes in, so quiet mode isn\u2019t needed.', 'dim');
        }

        step('files');
        var dep = pacing ? await budgeted('import/deploy-files', t({ files: ['*'] }), function (r) { paced('Putting files in place\u2026 ' + D.fmt.num(r.placed || 0) + ' of ' + D.fmt.num(r.total || 0)); })
          : await api.post('import/deploy-files', t({ files: ['*'] }));
        progress('');
        rlog('Placed ' + D.fmt.plural(dep.deployed || 0, 'file') + '.');
        if (dep.removed) rlog('Removed ' + D.fmt.plural(dep.removed, 'file') + ' that are gone from the source (kept in the rollback point).');
        (dep.loaders_disabled || []).forEach(function (l) { rlog('Disabled a firewall loader that points at a file not on this server: ' + l, 'dim'); });
        if (dep.host_files_kept && dep.host_files_kept.length) rlog('Left out host files (this site keeps its own): ' + dep.host_files_kept.join(', ') + '.', 'dim');
        if (dep.shared_kept && dep.shared_kept.length) rlog('Kept the versions already here of ' + D.fmt.plural(dep.shared_kept.length, 'shared plugin or theme folder') + ' (other sites run them): ' + dep.shared_kept.map(function (p) { return p.split('/').pop(); }).slice(0, 8).join(', ') + '.', 'dim');
        stepEls.files.meta.textContent = D.fmt.num(dep.deployed || 0);
        if (dep.errors && dep.errors.length) {
          dep.errors.slice(0, 5).forEach(function (e) { rlog('  ' + e, 'err'); });
          throw new Error(D.fmt.plural(dep.errors.length, 'file') + ' could not be put in place. Stopped before the database import.');
        }

        if (info.sql_file) {
          step('db');
          progress('This can take a while on large databases…');
          var sqBody = t({ sql_file: info.sql_file, old_prefix: srcPrefix, new_prefix: destPrefix });
          var sq = pacing ? await budgeted('import/import-staged-sql', sqBody, function (r) {
            paced(r.phase === 'users'
              ? 'Merging users\u2026 ' + (r.users_total ? D.fmt.num(r.users_done || 0) + ' of ' + D.fmt.num(r.users_total) : '')
              : 'Importing the database\u2026 ' + Math.floor(100 * (r.bytes_done || 0) / Math.max(1, r.bytes_total || 1)) + '%');
          })
            : await api.post('import/import-staged-sql', sqBody);
          progress('');
          rlog(D.fmt.num(sq.statements_executed || 0) + ' statements executed.');
          // The old address, when only the imported database tells it.
          if (!srcHome && sq.source_home && !(sq.errors && sq.errors.length)) {
            srcHome = String(sq.source_home).replace(/\/$/, '');
            rlog('The database is ' + D.host(srcHome) + '\u2019s.', 'dim');
            if (opts.quiet && !target && !quietOn && D.address(srcHome) !== D.address(destHome)) {
              await api.post('quiet-mode', t({ action: 'on', source: srcHome }));
              quietOn = true; quietSource = srcHome;
              rlog('Quiet mode on: emails held, scheduled jobs paused, checkout closed, payment and marketing APIs blocked.', 'ok');
            }
          }
          stepEls.db.meta.textContent = D.fmt.num(sq.statements_executed || 0) + ' statements';
          Object.keys(sq.converted || {}).forEach(function (c) { rlog('This database server doesn\u2019t support ' + c.split(' \u2192 ')[0] + ', so ' + D.fmt.plural(sq.converted[c], 'table') + ' use ' + c.split(' \u2192 ')[1] + ' instead.', 'dim'); });
          if (sq.definers_removed) rlog('Removed the source server\u2019s DEFINER account from ' + D.fmt.plural(sq.definers_removed, 'view, trigger or routine', 'views, triggers or routines') + '.', 'dim');
          if (sq.errors && sq.errors.length) {
            sq.errors.slice(0, 5).forEach(function (e) { rlog('  ' + e, 'err'); });
            throw new Error('The database import reported ' + D.fmt.plural(sq.errors.length, 'error') + '. ' + (sq.database_unchanged ? 'This site\u2019s database wasn\u2019t changed: the import works on copies of the tables and only swaps them in when every statement succeeds.' : 'Stopped before rewriting URLs.'));
          }
          if (sq.tables_swapped) rlog('Swapped in ' + D.fmt.plural(sq.tables_swapped, 'imported table') + '.', 'dim');
          if (sq.tables_left_out) rlog('Left out ' + D.fmt.plural(sq.tables_left_out, 'table') + ' without the source\u2019s prefix, so nothing else here is touched.', 'dim');
          if (sq.left_out_words) rlog(sq.left_out_words, 'warn');
          if (superBrought.length) rlog('Brought ' + D.fmt.plural(superBrought.length, 'super admin') + ' as administrators: ' + superBrought.join(', ') + '.', 'ok');
          // Into the network's main site: the network kept its users, and the incoming ones joined them.
          if (sq.users && !target) ctx.keptUsers = true;
          if (sq.users) {
            (sq.users.renamed || []).forEach(function (r) { rlog('The login ' + r.from + ' (' + r.email + ') ' + (r.why || 'was taken') + ' on this network, so that user logs in as ' + r.to + '.', 'ok'); });
            (sq.users.unattributed || []).forEach(function (r) { rlog(r.login + ' (' + r.email + ') couldn\u2019t be added (' + r.error + '): their posts, comments and orders here have no author now.', 'err'); });
            if (sq.users.orphaned) rlog(D.fmt.plural(sq.users.orphaned, 'row') + ' named a user the source no longer has, so they have no author now (not someone else here).', 'dim');
          }
          if (sq.users && (sq.users.created || sq.users.matched)) rlog((target ? '' : 'The network keeps its users. ') + 'Users joined the network: ' + D.fmt.plural(sq.users.created, 'new account') + ', ' + D.fmt.plural(sq.users.matched, 'existing account') + ' matched by email' + (sq.users.renumbered ? ' (' + D.fmt.plural(sq.users.renumbered, 'row') + ' of posts, comments and orders renumbered)' : '') + '.');
          if (srcPrefix && destPrefix && srcPrefix !== destPrefix) {
            var rp = await api.post('import/remap-prefix', t({ old_prefix: srcPrefix, new_prefix: destPrefix }));
            rlog('Table prefix remapped ' + srcPrefix + ' → ' + destPrefix + (rp.keys_changed ? ' (' + D.fmt.plural(rp.keys_changed, 'user and role key') + ').' : '.'));
          }
          step('urls'); progress('');
          var paths = info.paths || {};
          if ((srcHome && destHome && srcHome !== destHome) || Object.keys(paths).length || Object.keys(info.moved || {}).length) {
            if (srcHome && srcHome !== destHome) rlog('Rewriting ' + srcHome + ' → ' + destHome + ' (and its http, https and www spellings)…');
            Object.keys(paths).forEach(function (p) { rlog('Rewriting the old server\u2019s path ' + p + ' → ' + paths[p] + '…'); });
            var srBody = t({ from: srcHome || destHome, to: destHome });
            var sr = pacing ? await budgeted('import/search-replace', srBody, function (r) { paced('Rewriting addresses\u2026 table ' + D.fmt.num((r.tables_done || 0) + 1) + ' of ' + D.fmt.num(r.tables_total || 0)); })
              : await api.post('import/search-replace', srBody);
            progress('');
            rlog(D.fmt.num(sr.cells_changed || 0) + ' cells updated.');
            stepEls.urls.meta.textContent = D.fmt.num(sr.cells_changed || 0) + ' cells' + (sr.files_rewritten ? ' · ' + D.fmt.plural(sr.files_rewritten, 'file') : '');
            if (sr.files_rewritten) rlog('Rewrote the old address in ' + D.fmt.plural(sr.files_rewritten, 'file') + ' under uploads (' + (sr.files_rewritten_list || []).slice(0, 3).join(', ') + (sr.files_rewritten > 3 ? ', \u2026' : '') + ').');
            if (sr.files_too_big_total) rlog(D.fmt.plural(sr.files_too_big_total, 'file') + ' under uploads ' + (sr.files_too_big_total === 1 ? 'mentions' : 'mention') + ' the old address but ' + (sr.files_too_big_total === 1 ? 'is' : 'are') + ' over 5 MB, so ' + (sr.files_too_big_total === 1 ? 'it was' : 'they were') + ' left as ' + (sr.files_too_big_total === 1 ? 'it is' : 'they are') + ': ' + (sr.files_too_big || []).slice(0, 5).join(', ') + (sr.files_too_big_total > 5 ? ', \u2026' : '') + '.', 'warn');
            if (sr.elementor_css_cleared) rlog('Cleared Elementor\u2019s generated CSS so it rebuilds with the new address.');
            if (sr.files_mentioning_total) {
              codeMentions = { total: sr.files_mentioning_total, list: sr.files_mentioning_source || [], from: srcHome };
              rlog(D.fmt.plural(sr.files_mentioning_total, 'theme or plugin file') + (sr.files_mentioning_total === 1 ? ' still mentions ' : ' still mention ') + srcHome + ' (left as is).', 'dim');
            }
          } else skip('urls', 'same address');
        } else { skip('db', 'none in backup'); skip('urls', 'not needed'); }

        // A pull: tables a catch-up left alone are checked on the source once
        // more (and copied again if they changed meanwhile), and both sides'
        // fingerprints are kept for the next catch-up.
        if (pacing && pre.fingerprint && opts.mode === 'site' && info.sql_file) {
          var fp = await budgeted('import/pull/fingerprints', t({ from: srcHome || destHome, to: destHome }), function (r) { if (r.what) paced(r.what + '\u2026'); });
          progress('');
          (fp.recopied || []).forEach(function (tb) { rlog(tb + ' changed during the catch-up, copied again.', 'ok'); });
          if (fp.skip && !fp.nofp) {
            stepEls.db.meta.textContent = D.fmt.num(fp.copied || 0) + ' copied \u00b7 ' + D.fmt.num((fp.skipped || []).length) + ' unchanged';
            stepEls.db.meta.title = D.fmt.plural(fp.copied || 0, 'table') + ' copied \u00b7 ' + D.fmt.num((fp.skipped || []).length) + ' unchanged, skipped';
            if ((fp.skipped || []).length) rlog('Unchanged on both sides since the last pull, left as they were: ' + fp.skipped.join(', ') + '.', 'dim');
            Object.keys(fp.soft || {}).forEach(function (tb) { rlog('Copied ' + tb + ' rather than compare it: ' + fp.soft[tb] + '.', 'dim'); });
          }
        }

        step('fin');
        var fin = await api.post('import/finalize', t());
        var tidy = (fin && fin.tidy) || {};
        var gone = tidy.tables_removed || [];
        if (gone.length) rlog('Removed ' + D.fmt.plural(gone.length, 'table') + ' the incoming site doesn\u2019t have (kept in the restore point): ' + gone.slice(0, 8).join(', ') + (gone.length > 8 ? ', \u2026' : '') + '.');
        var caches = Object.keys(tidy.caches_cleared || {});
        if (caches.length) rlog('Cleared page caches so nothing serves the old site: ' + caches.join(', ') + '.', 'dim');
        (tidy.plugins_turned_off || []).forEach(function (p) { rlog('Turned off ' + p.name + ': ' + p.why + (p.plugin === 'redirection' || String(p.plugin || '').indexOf('sqlite-database-integration/') === 0 ? '.' : '. Turn it back on once this site has its own keys or certificate.'), 'dim'); });
        if (tidy.login_url) { ctx.loginUrl = tidy.login_url.url; rlog('The login page is moved by ' + tidy.login_url.plugin + ': log in at ' + tidy.login_url.url, 'ok'); }

        step('check');
        cancelBtn.hidden = true;
        progress('Loading a few pages and looking for the old address\u2026');
        var verify = null;
        try { verify = await side('import/verify', {}); } catch (e) { rlog('The checks after the move didn\u2019t run: ' + D.errorText(e), 'dim'); }
        progress('');
        if (verify) {
          stepEls.check.meta.textContent = verify.ok ? 'all good' : D.fmt.plural(verify.problems, 'problem');
          rlog(verify.ok ? 'Checks after the move: all good.' : 'Checks after the move found ' + D.fmt.plural(verify.problems, 'problem') + '.', verify.ok ? 'ok' : 'err');
        } else skip('check', 'not available');
        rlog('Done.', 'ok');
        notifyBtn.hidden = true;
        arrive(verify);
        sendNotice('finished', { lines: [verify ? (verify.ok ? 'Checks after the move: all good.' : 'Checks after the move found ' + D.fmt.plural(verify.problems, 'problem') + ': see Tools \u2192 Disembark on ' + D.host(opts.targetHome) + ', or run wp disembark verify ' + importId + '.') : ''].concat(ctx.loginUrl ? ['Log in at ' + ctx.loginUrl] : []).filter(Boolean) });
        opts.done(doneBox, ctx);
        if (verify) checks.appendChild(verifyCard(verify));
        if (quietOn) checks.appendChild(D.quietNote(destHome, quietSource));
        checks.appendChild(h('div', { class: 'dsk-chk' }, D.icon('anchor'), h('div', null, h('strong', null, 'Restore point kept. '), 'Find it under ', h('a', { href: '#backups' }, 'Backups'), ' with the others, or undo from the left.')));
        if (codeMentions) {
          checks.appendChild(h('div', { class: 'dsk-chk warn' }, D.icon('alert'), h('div', null,
            h('strong', null, D.fmt.plural(codeMentions.total, 'theme or plugin file') + (codeMentions.total === 1 ? ' still mentions ' : ' still mention ') + D.host(codeMentions.from) + '. '),
            'Disembark doesn\u2019t edit code, so check these by hand:',
            h('ul', { style: { fontFamily: 'var(--mono)', fontSize: '12px', overflowWrap: 'anywhere' } }, codeMentions.list.map(function (f) { return h('li', null, f); }),
              codeMentions.total > codeMentions.list.length ? h('li', null, '\u2026 and ' + (codeMentions.total - codeMentions.list.length) + ' more') : null))));
        }
      } catch (e) {
        notifyBtn.hidden = true;
        if (e.cancelled || cancelled) { settled = true; sendNotice('cancelled'); await cleanUp(); return; }
        settled = true;
        cancelBtn.hidden = true;
        var msg = D.errorText(e);
        sendNotice('failed', { error: msg, step: current ? (STEPS.filter(function (s) { return s.key === current; })[0] || {}).label : '' });
        rlog('ERROR: ' + msg, 'err');
        setTitle('It', 'stopped.');
        var unsure = !rollbackId && snapSent;
        sub.textContent = rollbackId ? D.host(opts.targetHome) + ' may be partly changed. Roll back below to put it as it was.' : unsure ? 'It stopped while saving the restore point. If one was saved, it’s under Backups → Restore points.' : 'Nothing on ' + D.host(opts.targetHome) + ' changed.';
        live.className = 'dsk-live stop'; capsText.textContent = KIND + ' stopped';
        progress('');
        cancelAnimationFrame(boat.raf);
        failStop('stopped');
        setHead([rollbackId ? D.host(opts.targetHome) + ' may be partly changed. Roll back to put it as it was.' : unsure ? 'It stopped while saving the restore point.' : 'Nothing on ' + D.host(opts.targetHome) + ' changed.'], 'Stopped', 'warn');
        D.mount(chooser);
        D.mount(outcome, h('div', { class: 'dsk-stack', style: { marginTop: '16px' } },
          D.note('error', [h('strong', null, 'It stopped. '), msg]),
          rollbackId ? h('div', { class: 'dsk-card dsk-card-pad dsk-stack', style: { gap: '12px' } },
            h('h3', null, D.icon('restore'), D.host(opts.targetHome) + ' may be partly changed'),
            h('p', { class: 'muted' }, 'Roll back to the snapshot taken before it started.'),
            h('div', { class: 'dsk-row' }, h('button', { class: 'dsk-btn primary', type: 'button', onclick: function (ev) { rollback(ev.currentTarget, outcome); } }, D.icon('restore'), 'Roll back'),
              h('button', { class: 'dsk-btn ghost', type: 'button', onclick: opts.back }, 'Back to Move')), ctx.cliUndo())
            : h('div', null, h('button', { class: 'dsk-btn', type: 'button', onclick: opts.back }, D.icon('ship'), 'Back to Move'))));
        if (opts.onFinish) opts.onFinish();
      }
    })().finally(function () { D.movesRunning = Math.max(0, (D.movesRunning || 1) - 1); });

    async function rollback(btn, into) {
      // The same question as Backups → Restore points, and the same
      // rollback: any move made since this one is undone first.
      var pick;
      try { pick = await D.askRollback(opts.api, rollbackId, ctx.target ? ctx.target.home : opts.targetHome, btn); }
      catch (e) { D.toast('Can’t roll back: ' + D.errorText(e), 'error'); return; }
      if (!pick) return;
      await D.busy(btn, async function () {
        var prevLive = live.className, prevCaps = capsText.textContent;
        live.className = 'dsk-live';
        capsText.textContent = 'Rolling back';
        lineState();
        rlog('Rolling back to the snapshot…');
        progress('Rolling back to the snapshot\u2026');
        try {
          var r = await D.paced(opts.api, 'import/rollback', { import_id: rollbackId, files: pick.files, later: true }, function (x) { progress(x.steps ? 'Undoing ' + x.step + ' of ' + x.steps + (x.label ? ': ' + x.label : '') + '\u2026' : 'Rolling back\u2026 ' + (x.phase === 'files' ? D.fmt.num(x.files_done || 0) + ' of ' + D.fmt.plural(x.files_total || 0, 'file') : 'the database')); });
          progress('');
          if (r.quiet_mode_lifted) { rlog('Quiet mode lifted with it.', 'dim'); if (opts.local && D.quietOff) D.quietOff(); }
          if (r.undone && r.undone.length) rlog('Undid ' + D.fmt.plural(r.undone.length, 'later move') + ' first.', 'dim');
          if (r.errors && r.errors.length) {
            r.errors.slice(0, 5).forEach(function (e) { rlog('  ' + e, 'err'); });
            throw new Error('Rollback reported ' + D.fmt.plural(r.errors.length, 'error') + '. It can be run again from the CLI.');
          }
          rlog('Rolled back.', 'ok');
          (r.warnings || []).forEach(function (w) { rlog(w, 'dim'); });
          setTitle('Rolled', 'back.');
          live.className = 'dsk-live ok'; capsText.textContent = KIND + ' undone';
          lineState();
          var dbOnly = pick.files === false || r.files === false;
          sub.textContent = dbOnly ? 'The database of ' + D.host(opts.targetHome) + ' is back to how it was before the move. Its files stay as they are.' : D.host(opts.targetHome) + ' is back to how it was before the move.';
          headK.textContent = 'Undone.';
          setHead(dbOnly ? ['The database went back to how it was. The files the move placed stay on ', { b: D.host(opts.targetHome) }, '.'] : ['The crates went back to ', { b: srcLabel }, '. Nothing from the move is left on ', { b: D.host(opts.targetHome) }, '.'], 'Rolled back', 'ok');
          cancelAnimationFrame(boat.raf);
          boat.t = null; boat.cargo = 0; boat.dock = 'left'; boat.arrived = false; drawScene();
          stopEls.forEach(function (se, i) { setStop(i, 'todo'); se.small.textContent = '\u00a0'; });
          strip.style.setProperty('--p', 0);
          var needLogin = opts.local && await D.rollbackNeedsLogin(r, [{ id: rollbackId, label: snapLabel, deployed: 1 }]);
          D.mount(outcome, h('div', { class: 'dsk-stack', style: { marginTop: '16px' } },
            D.note('ok', [h('strong', null, 'Rolled back. '), dbOnly ? 'The database of ' + D.host(opts.targetHome) + ' is back to how it was before; its files stay as they are.' : D.host(opts.targetHome) + ' is back to how it was before.']),
            needLogin ? D.note('warn', [h('strong', null, 'Log in again. '), 'User accounts went back with the database, so your current login no longer applies here.'], 'key') : null,
            h('div', { class: 'dsk-row' },
              needLogin ? h('a', { class: 'dsk-btn primary', href: D.config.loginUrl }, 'Log in again') : null,
              needLogin ? null : h('button', { class: 'dsk-btn', type: 'button', onclick: opts.back }, D.icon(opts.icon || 'ship'), opts.againLabel || 'Start another move'))));
        } catch (e) {
          progress('');
          live.className = prevLive;
          capsText.textContent = prevCaps;
          lineState();
          if (opts.local && D.quietLifted(e)) { rlog('Quiet mode lifted with it.', 'dim'); D.quietOff(); }
          rlog('ERROR: ' + (D.laterPointsText(e) || D.errorText(e)), 'err');
          D.toast('Rollback failed: ' + (D.laterPointsText(e) || D.errorText(e)), 'error');
        }
      });
    }
  };

  /** Whether a failed rollback (an error answer) still lifted quiet mode on its way: a chain's earlier step can. */
  D.quietLifted = function (e) {
    var d = e && e.data && typeof e.data === 'object' ? e.data : null;
    return !!(D.quietOff && d && (d.quiet_mode_lifted || (d.data && d.data.quiet_mode_lifted)));
  };

  function hasFiles(p) { return !!(p.files || p.deployed); }
  /** The restore points made after p that rolling back to it undoes first, newest first (matches Import::later_points()). */
  function laterThan(points, p, files) {
    return points.filter(function (q) {
      return q.id !== p.id && q.created_at > p.created_at && ((!q.rolled_back_at && !q.rolled_back_legacy) || (files && hasFiles(q) && !q.files_spent));
    }).sort(function (a, b) { return b.created_at - a.created_at; });
  }
  /** The steps a rollback to p runs that still hold their files: the choice of Database only matters for any of them. */
  function unspentFiles(points, p) { return [p].concat(laterThan(points, p, true)).some(function (q) { return hasFiles(q) && !q.files_spent && !q.creates_site; }); }

  function missingPreview(e) { return !!(e && e.status === 404 && !(e.data && e.data.code === 'no_rollback')); }
  function pointsFromPreview(pv, list) {
    var listed = {};
    (Array.isArray(list) ? list : []).forEach(function (q) { if (q && q.id) listed[q.id] = q; });
    return pv.steps.map(function (st) {
      var q = listed[st.id] || {};
      return { id: st.id, label: st.label, created_at: st.created_at, files: (st.spent || st.removes || st.puts_back) ? 1 : 0, files_spent: !!st.spent, creates_site: !!st.creates_site, rolled_back_at: q.rolled_back_at || 0, rolled_back_legacy: !!q.rolled_back_legacy };
    });
  }

  /**
   * Asks before a rollback to restore point p, saying what it changes: the
   * later moves it undoes first, the files it removes and puts back, and,
   * when a step still holds its files, a choice of Database only. points is
   * the restore points list. Without it (a move's own Undo or Roll back,
   * maybe on another site), the steps come from the site's preview, which
   * can be passed in already fetched. Resolves { files } to go ahead, or
   * null. A point that's gone rejects with the site's 404.
   */
  D.rollbackConfirm = async function (api, p, points, preview) {
    var where = p.home ? D.host(p.home) : 'this site';
    var words = { title: p.title || 'Roll back?', confirmLabel: p.confirmLabel || 'Roll back', after: p.after || '' };
    var pending = preview !== undefined ? Promise.resolve(preview) : api.post('restore-points/preview', { import_id: p.id });
    if (!points) {
      var pv = null;
      try { pv = await pending; } catch (e) {
        if (!missingPreview(e)) throw e;
      }
      if (!pv || !Array.isArray(pv.steps)) {
        // An older Disembark without the preview: the plain question.
        var plain = await D.confirm(h('div', { class: 'dsk-stack', style: { gap: '10px' } },
          h('p', null, 'Put ' + where + ' back to the restore point taken before this move?'),
          h('p', { class: 'muted' }, 'Any move made there since is undone first, newest first. Anything changed since is lost.' + (words.after ? ' ' + words.after : ''))),
          { title: words.title, confirmLabel: words.confirmLabel, danger: true });
        return plain ? { files: true } : null;
      }
      // The preview lists the steps for Database and files, which include
      // later moves whose database was already rolled back (their files are
      // still out). With Database only those steps don't run, so take each
      // step's rolled-back state from the restore points list when it loads.
      var list = [];
      // (A push's destination answers POST only: there, the preview alone.)
      if (typeof api.get === 'function') try { list = await api.get('restore-points') || []; } catch (e) { /* an older site: the preview alone */ }
      points = pointsFromPreview(pv, list);
      p = points.filter(function (q) { return q.id === p.id; })[0] || p;
      pending = Promise.resolve(pv);
    }
    var choice = unspentFiles(points, p), files = true;
    var laterBox = h('div'), sumBox = h('div'), preview = null;
    // What it changes, from the site (read only): files each move placed
    // that a rollback removes, the ones it puts back, and the totals.
    pending.then(function (r) { preview = r || false; showLater(); }).catch(function () { preview = false; showLater(); });
    function size(b) { return (preview && preview.partial ? 'at least ' : '') + D.fmt.size(b || 0); }
    function stepOf(q) { return preview && (preview.steps || []).find(function (st) { return st.id === q.id; }); }
    function stepText(q) {
      var st = stepOf(q);
      if (!st) return '';
      // A subsite or tenant a move created goes whole, files and all, with Database only too.
      if (st.creates_site && !st.spent) return 'removes the subsite or tenant it created, files and all';
      if (!files || !st.files) return files && st.spent && (st.removes || st.puts_back || hasFiles(q)) ? 'database only (its files were put back before)' : 'database only';
      var parts = [];
      if (st.removes) parts.push('removes ' + D.fmt.plural(st.removes, 'file') + ' (' + size(st.removes_bytes) + ')');
      if (st.puts_back) parts.push('puts back ' + D.fmt.plural(st.puts_back, 'file'));
      return parts.length ? parts.join(', ') : 'database only';
    }
    function summary() {
      if (preview === null) return D.note('', [h('span', { class: 'dsk-spin' }), ' Working out what changes…']);
      if (!preview) return null;
      var db = preview.database || {}, lines = [];
      var steps = preview.steps || [], moves = steps.filter(function (st) { return st.files && (st.removes || st.puts_back); }).length > 1 ? 'the moves' : 'the move';
      var created = steps.filter(function (st) { return st.creates_site && !st.spent; }).length;
      // A point taken before a move that made a new subsite or tenant saves
      // no tables: the made site's go with it.
      var own = steps.filter(function (st) { return st.id === p.id; })[0];
      if (own && own.creates_site && !own.spent && !db.tables) lines.push(h('div', null, h('strong', null, 'Database: '), 'the tables of the subsite or tenant this move made go with it.'));
      else lines.push(h('div', null, h('strong', null, 'Database: '), db.partial ? 'the ' + D.fmt.plural(db.tables, 'table') + ' the change touched ' + (db.tables === 1 ? 'goes' : 'go') + ' back.' : 'back to ' + D.fmt.date(db.created_at) + ' (' + D.fmt.plural(db.tables, 'table') + ').'));
      if (files) {
        if (preview.removes) lines.push(h('div', null, h('strong', null, 'Removes: '), D.fmt.plural(preview.removes, 'file') + ' (' + size(preview.removes_bytes) + ') ' + moves + ' placed' + (preview.uploads ? ', ' + D.fmt.num(preview.uploads) + ' of them in uploads (' + size(preview.uploads_bytes) + ')' : '') + '.'));
        if (preview.puts_back) lines.push(h('div', null, h('strong', null, 'Puts back: '), D.fmt.plural(preview.puts_back, 'file') + ' ' + moves + ' replaced or removed.'));
        if (!preview.removes && !preview.puts_back) lines.push(h('div', null, h('strong', null, 'Files: '), 'none to change.'));
      } else {
        // The files a created site's step placed go with that site; only
        // the other steps' placed files stay.
        var staying = created ? steps.some(function (st) { return st.removes && !(st.creates_site && !st.spent); }) : !!preview.removes;
        lines.push(h('div', null, h('strong', null, 'Files: '), 'stay as they are' + (created ? ', except the ' + (created > 1 ? created + ' subsites or tenants' : 'subsite or tenant') + ' below' + (staying ? ', including files ' + moves + ' placed' : '')
          : staying ? ', including the ' + D.fmt.plural(preview.removes, 'file') + ' (' + size(preview.removes_bytes) + ') ' + moves + ' placed' : '') + '.'));
        if (created) lines.push(h('div', null, h('strong', null, 'Removes: '), (created > 1 ? 'the ' + created + ' subsites or tenants these moves created, each' : 'the subsite or tenant a move created,') + ' with its tables, folders and files. A site a move created always goes whole.'));
      }
      (preview.notes || []).forEach(function (n) { lines.push(h('div', null, h('strong', null, 'Users: '), n)); });
      if (preview.maintenance) lines.push(h('div', null, h('strong', null, 'While it runs: '), 'visitors see WordPress\u2019s \u201cBriefly unavailable for scheduled maintenance\u201d page, so nothing loads the site half put back.'));
      return D.note('', lines);
    }
    function showLater() {
      var later = laterThan(points, p, files);
      D.mount(sumBox, summary());
      D.mount(laterBox, later.length ? h('div', { class: 'dsk-stack', style: { gap: '4px' } },
        h('p', null, 'First it undoes ', h('strong', null, D.fmt.plural(later.length, 'later move')), ', newest first:'),
        h('ul', { style: { margin: '0 0 0 18px', padding: 0 } }, later.slice(0, 8).map(function (q) {
          var t = stepText(q);
          return h('li', { style: { marginBottom: '4px' } }, (q.label || 'Restore point') + ' ', h('span', { class: 'muted' }, '(' + D.fmt.date(q.created_at) + ')'),
            t ? h('small', { class: 'muted', style: { display: 'block' } }, t) : null);
        }), later.length > 8 ? h('li', { class: 'muted' }, 'and ' + D.fmt.plural(later.length - 8, 'more')) : null)) : null);
    }
    var opt = function (value, title, text) {
      var input = h('input', { type: 'radio', name: 'dsk-rb-files', value: value, style: { marginTop: '3px' } });
      input.checked = (value === 'all') === files;
      input.addEventListener('change', function () { files = value === 'all'; showLater(); });
      return h('label', { style: { display: 'flex', gap: '10px', alignItems: 'flex-start', padding: '9px 0', borderTop: '1px solid var(--line)', cursor: 'pointer' } }, input,
        h('span', null, h('strong', null, title), h('small', { class: 'muted', style: { display: 'block', marginTop: '2px' } }, text)));
    };
    showLater();
    var ok = await D.confirm(h('div', { class: 'dsk-stack', style: { gap: '10px' } },
      h('p', null, 'Put ' + where + ' back to how it was on ', h('strong', null, D.fmt.date(p.created_at)), '?'),
      choice && p.creates_site ? h('p', { class: 'muted' }, 'The subsite or tenant this move created goes completely either way: its tables, folders and files.') : null,
      choice ? h('div', null,
        opt('all', 'Database and files', 'The database goes back to the snapshot, the files the move replaced come back and the ones it added go.'),
        opt('db', 'Database only', 'The files stay as they are now, and the restore point keeps its saved files for a later rollback. An older database with newer plugin files usually works, though a plugin may ask to update its database.'))
        : h('p', { class: 'muted' }, p.creates_site ? 'The subsite or tenant this move created goes completely: its tables, folders and files.'
          : hasFiles(p) && p.files_spent ? 'Only the database goes back: an earlier rollback already put this point’s files back (a rollback moves them into place, so it can only happen once).'
          : 'The database is replaced with the snapshot.'),
      sumBox,
      laterBox,
      h('p', { class: 'muted' }, 'Anything changed since is lost.' + (words.after ? ' ' + words.after : ''))),
      { title: words.title, confirmLabel: words.confirmLabel, danger: true });
    return ok ? { files: files } : null;
  };

  /**
   * A rollback asked for outside the restore points list (a move's Undo,
   * Roll back on a stopped move, Roll back and discard): fetches the
   * preview with btn spinning, then asks with D.rollbackConfirm. Resolves
   * { files } or null (also while btn is already busy).
   */
  D.askRollback = async function (api, id, home, btn, words) {
    var busy = true, points = null;
    var pv = await D.busy(btn, async function () {
      busy = false;
      var r = null;
      try { r = await api.post('restore-points/preview', { import_id: id }); }
      catch (e) {
        if (missingPreview(e)) return null; // an older Disembark: the plain question
        throw e;
      }
      if (r && Array.isArray(r.steps)) {
        var list = [];
        // (A push's destination answers POST only: there, the preview alone.)
        if (typeof api.get === 'function') try { list = await api.get('restore-points') || []; } catch (e) { /* an older site: the preview alone */ }
        points = pointsFromPreview(r, list);
      }
      return r;
    });
    if (busy) return null;
    var p = Object.assign({ id: id, home: home }, words || {});
    if (points) p = Object.assign({}, points.filter(function (q) { return q.id === id; })[0] || {}, p);
    return D.rollbackConfirm(api, p, points, pv || null);
  };
  /**
   * Whether this browser has to log in again after rollback r, which undid
   * the points steps (plus r.undone). User accounts go back with a whole-site
   * move's database: a move is a point labelled as one, or one that placed
   * files (an unlabelled point from before 3.0, or a custom label), the same
   * test as wp disembark rollback. Even then, when the accounts that came
   * back are the ones this page was opened with (a move that stopped before
   * its database went in, or one this login predates), the login still
   * holds: WordPress is asked before sending anyone to the login page.
   * points is the restore points list, for labels and placed files.
   */
  D.rollbackNeedsLogin = async function (r, steps, points) {
    if (!r || r.logins_kept) return false;
    points = points || [];
    var moves = (steps || []).concat(r.undone || []).some(function (q) {
      var full = points.filter(function (x) { return x.id === q.id; })[0] || q;
      return /^Before (restore|pull|push|catch-up)/i.test(q.label || full.label || '') || (full.deployed || 0) > 0;
    });
    if (!moves) return false;
    try {
      var url = String(D.config.homeUrl || '').replace(/\/+$/, '') + '/?rest_route=/wp/v2/users/me&_fields=id';
      var res = await fetch(url, { headers: { 'X-WP-Nonce': D.config.nonce }, credentials: 'same-origin', cache: 'no-store' });
      var me = res.ok && /json/i.test(res.headers.get('content-type') || '') ? await res.json() : null;
      return !(me && me.id);
    } catch (e) { return true; }
  };
  /** What a rollback refused because later restore points exist (later_points_exist) says to do instead. */
  D.laterPointsText = function (e) {
    return e && e.data && e.data.code === 'later_points_exist'
      ? 'A move made after this one has its own restore point, so this one can’t go back on its own. Roll back from Backups → Restore points, which undoes the later moves first.' : '';
  };

  /**
   * The saved rollback points as a timeline of buoys, each with Roll back
   * and Delete. Used by Backups. Returns a card; card.reload() refreshes it,
   * and o.onCount(n, bytes) hears how many there are.
   */
  var BUOYS = ['#c8553d', '#d9a441', '#2b8a7e', '#3f78a8'];
  /** Under a restore point from before 3.0 that holds other sites' tables (left_alone). */
  D.OLD_POINT_NOTE = 'Made before 3.0: only this site’s tables are restored.';
  D.restorePoints = function (o) {
    o = o || {};
    var body = h('div'), count = h('span', { class: 'dsk-tag r', hidden: true });
    var showAll = false;
    var card = h('section', { class: 'dsk-card dsk-sec' },
      h('div', { class: 'dsk-sec-h' }, h('div', { class: 'spotmini', html: D.H.spot('points') }),
        h('div', null, h('h3', null, 'Restore points'), h('p', null, 'One is saved before every pull, restore and search and replace. Roll back puts this site back as it was then: the database, and the files the moves since changed.')),
        h('span', { class: 'r' }, count)),
      body);
    var points = [];
    // One rollback or delete at a time: while one runs, every other row's
    // buttons wait (the list behind it is about to change).
    var running = false;
    function lock(on) {
      running = on;
      Array.prototype.forEach.call(body.querySelectorAll('.acts button'), function (b) { if (!b.classList.contains('is-busy')) b.disabled = on; });
    }
    function item(p, i) {
      return h('li', null,
        h('span', { class: 'by', html: D.H.buoyIcon(BUOYS[i % BUOYS.length]) }),
        h('div', null,
          h('b', null, p.label || 'Restore point'),
          i === 0 ? h('span', { class: 'dsk-tag ok', style: { marginLeft: '8px' } }, 'latest') : null,
          p.rolled_back_at ? h('span', { class: 'dsk-tag', style: { marginLeft: '8px' } }, (hasFiles(p) && !p.files_spent ? 'database rolled back ' : 'rolled back ') + D.fmt.ago(p.rolled_back_at))
            : p.rolled_back_legacy ? h('span', { class: 'dsk-tag', style: { marginLeft: '8px' }, title: 'An earlier version of Disembark rolled this back without saying when.' }, 'rolled back') : null,
          hasFiles(p) && p.files_spent ? h('span', { class: 'dsk-tag', style: { marginLeft: '8px' }, title: 'A rollback moved this point’s saved files back into place, so it can put back the database only.' }, 'files put back') : null,
          h('div', { class: 'when' }, D.fmt.date(p.created_at) + ' · ' + D.fmt.size(p.size) + ' · ' + (!hasFiles(p) ? 'database only' : p.files_spent ? 'database (its files were put back)' : 'files and database')),
          p.left_alone ? h('div', { class: 'when' }, D.OLD_POINT_NOTE) : null),
        h('div', { class: 'acts' },
          h('button', { class: 'dsk-btn sm', type: 'button', disabled: running, onclick: function (e) { rollback(p, e.currentTarget); } }, D.icon('restore'), 'Roll back'),
          h('button', { class: 'dsk-btn ghost sm', type: 'button', disabled: running, onclick: function (e) { remove(p, e.currentTarget); } }, 'Delete')));
    }
    async function load() {
      // The list stays on screen while it refreshes (after a rollback or a
      // delete); only the first load shows a spinner.
      if (!body.querySelector('.dsk-tl')) D.mount(body, h('div', { class: 'dsk-tl-empty' }, h('span', { class: 'dsk-spin' }), ' Loading…'));
      var fresh;
      try { fresh = await D.api.get('restore-points'); }
      catch (e) { D.mount(body, h('div', { class: 'dsk-tl-empty' }, D.note('error', 'Could not list restore points: ' + D.errorText(e)))); return; }
      points = Array.isArray(fresh) ? fresh : [];
      var bytes = points.reduce(function (n, p) { return n + (p.size || 0); }, 0);
      count.hidden = !points.length;
      count.textContent = points.length + ' kept · ' + D.fmt.size(bytes);
      if (o.onCount) o.onCount(points.length, bytes, points[0]);
      if (!points.length) { D.mount(body, h('div', { class: 'dsk-tl-empty' }, 'None yet. One is saved automatically before anything changes this site.')); return; }
      var limit = showAll ? points.length : 5;
      var list = h('ol', { class: 'dsk-tl' }, points.slice(0, limit).map(item));
      var more = points.length > limit ? h('div', { class: 'dsk-tl-more' }, h('button', { class: 'dsk-btn ghost sm', type: 'button', onclick: function () {
        // The rest join the list below the ones showing; nothing reloads.
        showAll = true;
        var first = list.children.length;
        points.slice(first).forEach(function (p, j) { list.appendChild(item(p, first + j)); });
        more.remove();
        if (list.children[first]) list.children[first].querySelector('button').focus({ preventScroll: true });
      } }, 'Show all ' + points.length)) : null;
      D.mount(body, list, more);
    }
    async function rollback(p, btn) {
      if (running) return;
      var pick = await D.rollbackConfirm(D.api, p, points);
      if (!pick || running) return;
      var files = pick.files;
      var label = btn.lastChild, was = label && label.nodeType === 3 ? label.textContent : null;
      lock(true);
      await D.busy(btn, async function () {
        try {
          var r = await D.paced(D.api, 'import/rollback', { import_id: p.id, files: files, later: true }, function (x) {
            if (was === null) return;
            label.textContent = x.steps ? 'Undoing ' + x.step + ' of ' + x.steps + '…' : (x.phase === 'files' ? 'Files ' + D.fmt.num(x.files_done || 0) + ' of ' + D.fmt.num(x.files_total || 0) : 'Database…');
          });
          if (r.quiet_mode_lifted && D.quietOff) D.quietOff();
          if (r.errors && r.errors.length) throw new Error(D.fmt.plural(r.errors.length, 'error') + ': ' + r.errors.slice(0, 2).join(' · '));
          D.toast('Rolled back to ' + D.fmt.date(p.created_at) + (r.files === false ? ' (database only)' : '') + '.' + (r.warnings && r.warnings.length ? ' ' + r.warnings.join(' ') : ''));
          if (await D.rollbackNeedsLogin(r, [p], points)) { setTimeout(function () { location.href = D.config.loginUrl; }, 2500); return; }
          if (D.store.state.database.length) D.loadDatabase(true).catch(function () {});
          load();
        } catch (e) {
          if (D.quietLifted(e)) D.quietOff();
          D.toast('Rollback failed: ' + D.errorText(e), 'error'); load();
        }
        finally { if (was !== null) label.textContent = was; lock(false); }
      });
    }
    async function remove(p, btn) {
      if (running) return;
      var ok = await D.confirm('Delete the restore point from ' + D.fmt.date(p.created_at) + '? You won’t be able to roll back to it.', { title: 'Delete restore point?', confirmLabel: 'Delete', danger: true });
      if (!ok || running) return;
      lock(true);
      await D.busy(btn, async function () {
        try { await D.api.post('restore-points/delete', { import_id: p.id }); D.toast('Restore point deleted.'); load(); D.loadStatus(); }
        catch (e) { D.toast('Could not delete it: ' + D.errorText(e), 'error'); }
        finally { lock(false); }
      });
    }
    card.reload = load;
    load();
    return card;
  };

  /** What to do after a move into this site (Restore and Pull), in the arrival's left card. */
  D.arrivedView = function (box, ctx, undoLabel) {
    var again = ctx.back ? h('button', { class: 'dsk-btn', type: 'button', onclick: ctx.back }, D.icon(ctx.icon || 'ship'), ctx.againLabel || 'Start another move') : null;
    if (ctx.target) {
      var tg = ctx.target, net = tg.kind === 'multisite', own = D.address(tg.home) !== D.address(D.config.homeUrl);
      D.mount(box,
        h('h3', null, D.icon('checkCircle'), (net ? 'Subsite ' : 'Tenant ') + tg.id + ' is ready'),
        h('p', null, D.host(tg.home) + ' now holds the copied site. The rest of ' + D.host(D.config.homeUrl) + ' is as it was, and you’re still logged in.'),
        h('p', { style: { marginTop: '8px' } }, net ? 'Its users joined the network: they log in with their own passwords, and an account that was already here keeps its own.'
          : 'It has its own users: log in to it with the source site’s credentials' + (own ? '.' : ', from Tools → WP Freighter.')),
        h('div', { class: 'row' },
          own && D.safeHref(tg.home) ? h('a', { class: 'dsk-btn primary', href: D.safeHref(tg.home), target: '_blank', rel: 'noopener' }, 'Open ' + D.host(tg.home)) : null,
          h('button', { class: 'dsk-btn', type: 'button', onclick: function (e) { ctx.rollback(e.currentTarget); } }, D.icon('restore'), undoLabel),
          again),
        ctx.undoDetails());
      return;
    }
    if (ctx.keptUsers) {
      D.mount(box,
        h('h3', null, D.icon('checkCircle'), 'The network kept its users'),
        h('p', null, D.host(D.config.homeUrl) + ' now holds the copied site, and you’re still logged in. The network’s users, super admins and every subsite’s members are as they were.'),
        h('p', { style: { marginTop: '8px' } }, 'The incoming site’s users joined the network: they log in with their own passwords, and an account that was already here keeps its own.'),
        h('div', { class: 'row' },
          h('button', { class: 'dsk-btn', type: 'button', onclick: function (e) { ctx.rollback(e.currentTarget); } }, D.icon('restore'), undoLabel),
          again),
        ctx.undoDetails());
      return;
    }
    D.mount(box,
      h('h3', null, D.icon('key'), 'Log in again'),
      h('p', null, 'User accounts came over with the copy, so your current login no longer applies here. ', h('strong', { style: { color: 'var(--ink)' } }, 'Log in with the source site’s credentials.')),
      h('div', { class: 'row' },
        h('a', { class: 'dsk-btn primary', href: ctx.loginUrl || D.config.loginUrl }, 'Log in again'),
        h('button', { class: 'dsk-btn', type: 'button', onclick: function (e) { ctx.rollback(e.currentTarget); } }, D.icon('restore'), undoLabel)),
      ctx.undoDetails());
  };
})();
