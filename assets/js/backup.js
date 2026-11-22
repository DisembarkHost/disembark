/**
 * Backups: download a backup zip (scan the site, choose what to bring, then
 * zip it in the browser or run it with the CLI), the restore points saved
 * before every move, and earlier scans to pick up again.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h, S = D.store.state;

  // Back on the tab, the restore points list again: a move or a rollback
  // elsewhere in the dashboard may have changed it.
  var reloadPoints = null, shownOnce = false;
  D.section({ id: 'backups', title: 'Backups', icon: 'history', tab: 2, mount: mount, onShow: function () {
    if (!shownOnce) { shownOnce = true; return; } // mount just loaded it
    if (reloadPoints) reloadPoints();
  } });

  /** "Today, 2:31 PM", "Yesterday, 9:02 AM", "Nov 17, 11:44 AM", "Feb 16, 2025, 7:59 PM". */
  function when(ts) {
    if (!ts) return '';
    var d = new Date(ts * 1000), now = new Date();
    var time = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    var day = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime(), then = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    var diff = Math.round((day - then) / 86400000);
    if (diff === 0) return 'Today, ' + time;
    if (diff === 1) return 'Yesterday, ' + time;
    return d.toLocaleDateString([], d.getFullYear() === now.getFullYear() ? { month: 'short', day: 'numeric' } : { month: 'short', day: 'numeric', year: 'numeric' }) + ', ' + time;
  }

  // File count and size of an earlier scan, read from its manifest (each part
  // lists its own count and size). Cached per session.
  var scanInfo = {};
  function sessionInfo(s) {
    if (!scanInfo[s.token]) {
      scanInfo[s.token] = D.api.get('manifest', { backup_token: s.token }).then(function (m) {
        if (!Array.isArray(m)) return null;
        return m.reduce(function (a, p) { a.files += Number(p.count) || 0; a.size += Number(p.size) || 0; return a; }, { files: 0, size: 0 });
      }).catch(function () { return null; });
    }
    return scanInfo[s.token];
  }

  function mount(root) {
    var view = h('div'), picker = h('div'), scans = h('div');
    var points = D.restorePoints();
    reloadPoints = points.reload;
    D.mount(root,
      D.pageHead({ caps: 'Backups', icon: 'history', title: ['Every move, ', 'anchored.'],
        text: 'A restore point is saved before every pull, restore and search and replace. Download a full backup zip whenever you like.' }),
      picker, view, points, scans);

    var vs = D.scope();
    function fresh() { vs.dispose(); vs = D.scope(); }
    function render() {
      fresh();
      D.mount(picker, sitePicker());
      D.mount(view, S.scanned ? ready() : start());
    }
    D.store.on(['scanned', 'index', 'site'], render);
    D.store.on(['sessions', 'lastScan'], function () { if (!S.scanned && !busy) render(); renderScans(); });
    D.store.on(['backupToken', 'site'], renderScans);
    // While a scan runs (or an earlier one loads), the site it's for stays
    // put: the result would otherwise land under another site.
    var busy = false, siteSel = null;
    function setBusy(on) { busy = on; if (siteSel) siteSel.setDisabled(on); }
    render();
    renderScans();

    // A network or WP Freighter install: back up all of it or one site, as
    // a standalone site (restores into any single site). A zip of the whole
    // install is for keeping or a full server restore by hand: Move refuses
    // it (Import::whole_install_refusal()), so the label says so.
    function wholeNote() {
      var inst = D.config.install;
      if (!inst || S.site) return '';
      var kind = inst.kind === 'multisite' ? 'network' : 'WP Freighter install';
      return 'A zip of the whole ' + kind + ' is for keeping, or for restoring this whole server by hand (unzip it, import its database). Move brings one site at a time, so it won’t restore this zip: pick one site under Back up for a zip it restores into any WordPress site.';
    }
    function sitePicker() {
      var inst = D.config.install;
      if (!inst || !(inst.sites || []).length) return null;
      var kind = inst.kind === 'multisite' ? 'network' : 'WP Freighter install';
      var opts = [{ value: '', label: 'The whole ' + kind, sub: D.fmt.plural(inst.total || inst.sites.length, 'site') + ' · for a full server restore' }].concat(inst.sites.map(function (s) {
        return { value: s.id, label: s.name || D.host(s.url), match: D.host(s.url), sub: D.host(s.url) + ' · ' + (s.id === 'main' ? 'main site' : (inst.kind === 'multisite' ? 'site ' : 'tenant ') + s.id) };
      }));
      var sel = siteSel = D.combo(opts, S.site || '', function (v) { if (!busy) D.setSite(v); }, { label: 'What to back up', placeholder: 'Search sites by name or address', disabled: busy });
      return h('div', { class: 'dsk-card dsk-bk-scope' },
        h('span', { class: 'caps' }, 'Back up'), sel,
        h('span', { class: 'muted' }, S.site ? 'This site alone, as a standalone site: its tables, its users, its uploads, and the plugins and themes it uses. Restore it into any single WordPress site.' : 'Every site of the ' + kind + ', exactly as it is here. ' + wholeNote()));
    }

    /* ---------------------------------------------------- The two cards */
    function dlCard(body) {
      return h('section', { class: 'dsk-card dsk-bk-dl' }, h('div', { class: 'dsk-bk-art', html: D.H.spot('zip') }), h('div', { class: 'dsk-bk-dl-main' }, body));
    }
    function start() {
      return h('div', { class: 'dsk-two' },
        dlCard([
          h('h2', null, 'Download a ', h('em', { class: 'hl' }, 'backup zip')),
          h('p', null, 'Scan the site, choose what to bring (leave out caches, old backups, log tables), then download one zip of its files and database.'),
          h('button', { class: 'dsk-btn primary lg', type: 'button', onclick: function () { scan(); } }, D.icon('scan'), 'Scan this site'),
          S.lastScan ? h('div', { class: 'meta' }, 'Last scan ' + D.fmt.ago(S.lastScan.timestamp) + ' · ' + D.fmt.plural(S.lastScan.total_files, 'file') + ' · ' + D.fmt.size(S.lastScan.total_size)) : null]),
        cliCard(false));
    }

    /* ------------------------------------------------------------ Scan */
    async function scan() {
      var steps = [
        { key: 'db', label: 'Reading the database' },
        { key: 'scanning', label: 'Scanning folders' },
        { key: 'chunking', label: 'Writing the file list' },
        { key: 'loading', label: 'Loading the file list' }
      ];
      var stepEls = {}, bar = D.progress();
      var list = h('div', { class: 'dsk-steps' }, steps.map(function (s, i) {
        var meta = h('span', { class: 'meta' });
        var el = h('div', { class: 'dsk-step' }, h('span', { class: 'mark' }, String(i + 1)), s.label, meta);
        stepEls[s.key] = { el: el, meta: meta, i: i };
        return el;
      }));
      function at(key, meta, pct) {
        steps.forEach(function (s) {
          var st = stepEls[s.key];
          st.el.classList.toggle('active', s.key === key);
          st.el.classList.toggle('done', st.i < stepEls[key].i);
        });
        if (meta !== undefined) stepEls[key].meta.textContent = meta;
        bar.set(pct);
      }
      fresh();
      setBusy(true);
      D.mount(view, h('div', { class: 'dsk-two' },
        dlCard([h('h2', null, 'Scanning ', h('em', { class: 'hl' }, D.host(S.site ? D.siteName(S.site).split(' ')[0] : D.config.homeUrl))), h('div', { class: 'dsk-bk-steps' }, list, bar)]),
        cliCard(false)));
      try {
        at('db', '', null);
        var tables = await D.loadDatabase(true);
        stepEls.db.meta.textContent = D.fmt.plural(tables.length, 'table');
        await D.scanSite(function (p) {
          var pct = p.total ? (p.done / p.total) * 100 : null;
          var meta = p.phase === 'scanning' ? D.fmt.num(p.done) + ' of ' + D.fmt.num(p.total) + ' folders' : D.fmt.num(p.done) + ' of ' + D.fmt.num(p.total) + ' parts';
          at(p.phase, meta, pct);
        });
        setBusy(false);
        D.toast('Scan complete: ' + D.fmt.plural(S.files.length, 'file') + '.');
      } catch (e) {
        setBusy(false);
        D.toast(e.siteChanged ? D.errorText(e) : 'The scan did not finish: ' + D.errorText(e), 'error');
        render();
      }
    }

    async function resume(session) {
      fresh();
      setBusy(true);
      D.mount(view, h('div', { class: 'dsk-two' },
        dlCard([h('h2', null, 'Picking up ', h('em', { class: 'hl' }, 'a scan')), h('div', { class: 'dsk-row muted' }, h('span', { class: 'dsk-spin' }), 'Loading the scan from ' + when(session.timestamp) + '…')]),
        cliCard(false)));
      window.scrollTo({ top: 0, behavior: 'smooth' });
      try {
        await D.loadDatabase(true);
        await D.resumeSession(session);
        setBusy(false);
        D.toast('Scan loaded.');
      } catch (e) {
        setBusy(false);
        D.toast('Could not load that scan: ' + D.errorText(e), 'error');
        // The site says the scan covered another site (or is gone): the list again.
        if (e.data && e.data.code === 'session_site_mismatch') D.loadStatus();
        render();
      }
    }

    /* ----------------------------------------------------------- Ready */
    function ready() {
      var summary = h('p'), totals = h('span', { class: 'dsk-tag r' });
      function renderSummary() {
        var f = fileTotals(), db = dbTotals();
        var parts = [];
        if (S.includeFiles) parts.push(h('b', null, D.fmt.plural(f.files, 'file')), ' (' + D.fmt.size(f.size) + ')');
        if (S.includeFiles && S.includeDb) parts.push(' and ');
        if (S.includeDb) parts.push(h('b', null, D.fmt.plural(db.tables, 'table')), ' (' + D.fmt.size(db.size) + ')');
        if (!S.includeFiles && !S.includeDb) parts.push('Nothing is included yet. Turn on files, the database, or both below.');
        else parts.push((f.excluded || db.left) ? ', with ' + [f.excluded ? D.fmt.plural(f.excluded, 'file') : '', db.left ? D.fmt.plural(db.left, 'table') : ''].filter(Boolean).join(' and ') + ' left out.' : '. Leave out anything you don’t need below.');
        D.mount(summary, parts);
        totals.textContent = D.fmt.size((S.includeFiles ? f.size : 0) + (S.includeDb ? db.size : 0)) + ' in all';
      }
      vs.on(['excluded', 'included', 'includeDb', 'includeFiles'], renderSummary);
      renderSummary();
      return h('div', null,
        h('div', { class: 'dsk-two' },
          dlCard([
            h('h2', null, 'Download a ', h('em', { class: 'hl' }, 'backup zip')),
            summary,
            h('div', { class: 'dsk-row' },
              h('button', { class: 'dsk-btn primary lg', type: 'button', onclick: function () { browserBackup(); } }, D.icon('download'), 'Download backup zip'),
              h('button', { class: 'dsk-btn ghost', type: 'button', onclick: function () { scan(); } }, D.icon('refresh'), 'Rescan')),
            h('div', { class: 'meta' }, 'Scanned ' + (S.lastScan ? D.fmt.ago(S.lastScan.timestamp) : 'just now') + '. The zip is built on the server, then downloaded. No CLI needed.')]),
          cliCard(true)),
        h('section', { class: 'dsk-card dsk-sec' },
          h('div', { class: 'dsk-sec-h' }, h('div', null, h('h3', null, 'Choose what to bring'), h('p', null, 'Leave out caches, old backups and log tables. Your choices also apply to the terminal commands.')), totals),
          h('div', { class: 'dsk-bk-choose' }, dbPane(), filesPane())));
    }

    function fileTotals() {
      var out = { files: 0, size: 0, excluded: 0 };
      if (!S.excluded.size) { out.files = S.files.length; out.size = S.index ? S.index.root.size : 0; return out; }
      S.files.forEach(function (f) {
        if (D.isExcluded(f.name)) out.excluded++;
        else { out.files++; out.size += Number(f.size) || 0; }
      });
      return out;
    }
    function dbTotals() {
      var out = { tables: 0, size: 0, left: 0 };
      S.database.forEach(function (t) { if (S.included.has(t.table)) { out.tables++; out.size += t.size; } else out.left++; });
      return out;
    }

    /* Database picker */
    function dbPane() {
      var q = '', sortBy = 'name', anchor = null, list = h('ul', { class: 'dsk-list' });
      var body = h('div', { class: 'dsk-scroll' }, list);
      var stat = h('span', { class: 'muted' });
      function visible() {
        var rows = S.database.filter(function (t) { return !q || t.table.toLowerCase().indexOf(q) !== -1; });
        return rows.sort(function (a, b) { return sortBy === 'size' ? b.size - a.size : a.table.localeCompare(b.table); });
      }
      function setIncluded(names, on) {
        var inc = new Set(S.included);
        names.forEach(function (n) { if (on) inc.add(n); else inc.delete(n); });
        D.store.set({ included: inc });
      }
      function renderList() {
        var rows = visible(), db = dbTotals();
        stat.textContent = S.includeDb ? D.fmt.plural(db.tables, 'table') + ' · ' + D.fmt.size(db.size) : 'Skipped';
        D.mount(list, rows.length ? rows.map(function (t) {
          var on = S.included.has(t.table);
          return h('li', { class: on ? '' : 'off' },
            h('span', {
              class: 'dsk-check' + (on ? ' on' : ''), role: 'checkbox', tabindex: '0', 'aria-checked': String(on), 'aria-label': 'Include ' + t.table,
              title: 'Shift-click to apply to a range',
              onclick: function (e) {
                if (e.shiftKey && anchor) {
                  var names = rows.map(function (r) { return r.table; }), a = names.indexOf(anchor), b = names.indexOf(t.table);
                  if (a !== -1 && b !== -1) { setIncluded(names.slice(Math.min(a, b), Math.max(a, b) + 1), !on); anchor = t.table; return; }
                }
                anchor = t.table; setIncluded([t.table], !on);
              },
              onkeydown: function (e) { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); e.currentTarget.click(); } }
            }),
            h('span', { class: 'grow nm' }, t.table),
            h('span', { class: 'size' }, D.fmt.num(t.row_count) + ' rows'),
            h('span', { class: 'size sz' }, D.fmt.size(t.size)));
        }) : h('li', { class: 'muted' }, 'No tables match.'));
      }
      vs.on(['included', 'includeDb'], renderList);
      renderList();
      var sortBtn = h('button', { class: 'dsk-btn ghost sm', type: 'button', onclick: function () { sortBy = sortBy === 'name' ? 'size' : 'name'; sortBtn.lastChild.textContent = sortBy === 'name' ? 'A–Z' : 'Largest'; renderList(); } }, D.icon('settings'), h('span', null, 'A–Z'));
      var tools = h('div', { class: 'dsk-bk-tools' },
        h('div', { class: 'grow' }, D.search('Search tables', function (v) { q = v; renderList(); })),
        sortBtn,
        h('button', { class: 'dsk-btn ghost sm', type: 'button', onclick: function () { setIncluded(visible().map(function (t) { return t.table; }), true); } }, 'All'),
        h('button', { class: 'dsk-btn ghost sm', type: 'button', onclick: function () { setIncluded(visible().map(function (t) { return t.table; }), false); } }, 'None'));
      body.hidden = tools.hidden = !S.includeDb;
      return h('div', { class: 'dsk-bk-pane' },
        h('div', { class: 'dsk-bk-pane-h' }, h('span', { class: 'dsk-dot', style: { background: 'var(--db)' } }), h('h3', null, 'Database'), stat, h('span', { class: 'grow' }),
          D.toggle('Include', S.includeDb, function (v) { D.store.set({ includeDb: v }); body.hidden = tools.hidden = !v; })),
        tools, body);
    }

    /* File picker */
    function filesPane() {
      var tree = D.Tree({ mode: 'exclude', scope: vs });
      var body = h('div', { class: 'dsk-scroll', style: { padding: '6px 0' } }, tree.el);
      var stat = h('span', { class: 'muted' });
      var resetBtn = h('button', { class: 'dsk-btn ghost sm', type: 'button', onclick: function () { D.store.set({ excluded: new Set() }); } }, 'Reset');
      function sync() {
        var f = fileTotals();
        resetBtn.hidden = !S.excluded.size || !S.includeFiles;
        stat.textContent = S.includeFiles ? D.fmt.plural(f.files, 'file') + ' · ' + D.fmt.size(f.size) : 'Skipped';
      }
      vs.on(['excluded', 'includeFiles'], sync); sync();
      var hint = h('div', { class: 'dsk-bk-tools muted' },
        h('span', null, 'Click ', D.icon('checkCircle'), ' to leave something out. ', h('kbd', null, 'Shift'), '-click another to apply it to everything between.'));
      hint.querySelector('svg').style.cssText = 'width:14px;height:14px;vertical-align:-2px;color:var(--teal)';
      body.hidden = hint.hidden = !S.includeFiles;
      return h('div', { class: 'dsk-bk-pane' },
        h('div', { class: 'dsk-bk-pane-h' }, h('span', { class: 'dsk-dot', style: { background: 'var(--files)' } }), h('h3', null, 'Files'), stat, h('span', { class: 'grow' }), resetBtn,
          D.toggle('Include', S.includeFiles, function (v) { D.store.set({ includeFiles: v }); body.hidden = hint.hidden = !v; })),
        hint, body);
    }

    /* Back up in the browser */
    async function browserBackup() {
      if (!S.includeFiles && !S.includeDb) { D.toast('Include files, the database, or both.', 'error'); return; }
      var files = S.includeFiles ? S.files.filter(function (f) { return !D.isExcluded(f.name); }) : [];
      var tables = S.includeDb ? S.database.filter(function (t) { return S.included.has(t.table); }) : [];
      // A zip that says it has the database but holds none would pass for a full backup.
      if (S.includeDb && !tables.length) {
        D.toast(S.database.length ? 'No tables are picked. Pick some under Database, or turn off its Include.' : 'The database tables didn’t load, so the zip would have no database. Scan again, or turn off the database’s Include.', 'error');
        return;
      }
      if (S.includeFiles && !files.length) { D.toast('No files are picked. Bring some back under Files, or turn off its Include.', 'error'); return; }
      var MAX = 150 * 1024 * 1024, MAX_FILES = 1500, BIG = 40 * 1024 * 1024, batches = [], cur = [], curSize = 0;
      files.forEach(function (f) {
        var size = Number(f.size) || 0, big = size > BIG;
        if ((big || curSize + size > MAX || cur.length >= MAX_FILES) && cur.length) { batches.push({ files: cur, size: curSize }); cur = []; curSize = 0; }
        cur.push(f.name); curSize += size;
        if (big) { batches.push({ files: cur, size: curSize }); cur = []; curSize = 0; }
      });
      if (cur.length) batches.push({ files: cur, size: curSize });
      var dbJobs = D.planDbExport(tables);
      var total = batches.reduce(function (a, b) { return a + b.size; }, 0) + dbJobs.reduce(function (a, j) { return a + (j.size || 0); }, 0) || 1;
      var stamp = new Date().toISOString().replace(/[-:]/g, '').replace('T', '-').slice(0, 13);
      var archive = (S.site ? D.siteName(S.site).split(' ')[0] + '-' + (S.site === 'main' ? 'main' : 'site' + S.site) : D.host(D.config.homeUrl)).replace(/[^a-z0-9.-]/gi, '_') + '-' + stamp + '.zip';
      var token = S.backupToken, done = 0, cancelled = false, close = null;

      var bar = D.progress(), line = h('div', { class: 'muted mono', style: { fontSize: '12.5px', minHeight: '18px' } });
      D.modal({
        title: 'Building your backup', persistent: true,
        body: h('div', { class: 'dsk-stack', style: { gap: '12px' } },
          h('p', null, D.fmt.plural(files.length, 'file') + ' and ' + D.fmt.plural(tables.length, 'table') + ', about ' + D.fmt.size(total) + ' before compression.'),
          bar, line, h('p', { class: 'muted' }, 'Keep this tab open until it finishes.')),
        actions: [{ label: 'Cancel', value: 'cancel' }],
        onOpen: function (dlg, d) { close = d; }
      }).then(function (v) { if (v === 'cancel') cancelled = true; });

      function tick(size, text) { done += size || 0; bar.set((done / total) * 100); if (text) line.textContent = text; }
      async function step(body) { if (cancelled) throw new Error('cancelled'); return D.api.post('backup/archive', Object.assign({ backup_token: token, archive: archive }, S.site ? { site: S.site } : {}, body)); }
      try {
        for (var i = 0; i < batches.length; i++) {
          line.textContent = 'Adding files… batch ' + (i + 1) + ' of ' + batches.length;
          await step({ step: 'files', files: batches[i].files });
          tick(batches[i].size);
        }
        for (var j = 0; j < dbJobs.length; j++) {
          var job = dbJobs[j];
          line.textContent = job.tables ? 'Exporting the database… ' + (j + 1) + ' of ' + dbJobs.length : 'Exporting ' + job.table + ' (part ' + job.part + ' of ' + job.parts + ')…';
          await step(job.tables ? { step: 'database', tables: job.tables } : { step: 'database', table: job.table, part: job.part, rows_per_part: job.rows });
          tick(job.size);
        }
        line.textContent = 'Finishing the zip…';
        var fin = await step({ step: 'finish' });
        if (cancelled) throw new Error('cancelled');
        bar.set(100);
        if (close) close(null);
        await D.downloadSiteFile(fin.url, fin.file_name);
        var choice = await D.modal({
          title: 'Backup ready',
          body: h('div', { class: 'dsk-stack', style: { gap: '10px' } },
            h('p', null, h('strong', null, fin.file_name), ' (' + D.fmt.size(fin.size) + ') is downloading.'),
            wholeNote() ? h('p', { class: 'muted' }, wholeNote()) : null,
            h('p', { class: 'muted' }, 'Delete it from the server once the download finishes? It’s a full copy of the site in a public folder (with an unguessable name).')),
          actions: [{ label: 'Keep it for now', value: 'keep' }, { label: 'Delete from server', kind: 'primary', value: 'delete' }]
        });
        if (choice === 'delete') { await cleanupArchive(); D.toast('Backup zip deleted from the server.'); }
        D.loadStatus();
      } catch (e) {
        if (close) close(null);
        await cleanupArchive();
        if (!cancelled) D.toast('The backup did not finish: ' + D.errorText(e), 'error'); else D.toast('Backup cancelled.');
        // The site says this scan covered another site: it can't be used for this one, so scan again.
        if (e && e.data && e.data.code === 'session_site_mismatch') { D.setSite(S.site); D.loadStatus(); }
      }
      async function cleanupArchive() {
        var base = archive.replace(/\.zip$/, '');
        for (var n of [archive, base + '-database.db.txt']) {
          try { await D.api.post('cleanup-file', { backup_token: token, file_name: n }); } catch (x) { /* best effort */ }
        }
      }
    }

    /* "Or from your terminal": the backup command, plus the rest behind a disclosure. */
    function cliCard(withChoices) {
      var head = h('h3', null, D.icon('terminal'), 'Or from your terminal');
      if (S.site) {
        return h('section', { class: 'dsk-card dsk-bk-cli' }, head,
          h('p', null, 'The Disembark CLI backs up the whole install. For this one site, use the backup zip, or pull it straight into another site with ', h('code', null, 'wp disembark pull <connect line> --site=' + S.site), '.'));
      }
      var home = D.config.homeUrl;
      var main = D.cmd('');
      var blocks = {
        runner: D.cmd('', { label: 'Back up now', hint: 'Downloads the CLI if needed and runs the backup with your choices.' }),
        sync: D.cmd('', { label: 'Sync', hint: 'Create or refresh a local mirror.' }),
        ncdu: D.cmd('', { label: 'Disk usage', hint: 'Browse what takes up space (needs ncdu).' }),
        info: D.cmd('', { label: 'Info', hint: 'Connection, storage and earlier sessions.' })
      };
      function flags() {
        var f = '';
        var ex = D.minimalExclusions();
        if (ex.length) f += ' ' + ex.map(function (p) { return '-x "' + p + '"'; }).join(' ');
        var left = S.database.filter(function (t) { return !S.included.has(t.table); }).map(function (t) { return t.table; });
        if (left.length) f += ' --exclude-tables=' + left.join(',');
        if (!S.includeDb) f += ' --skip-db';
        if (!S.includeFiles) f += ' --skip-files';
        return f;
      }
      function update() {
        var f = withChoices ? flags() : '', sid = withChoices && S.backupToken ? ' --session-id=' + S.backupToken : '';
        main.set('disembark backup ' + home + f + sid);
        blocks.runner.querySelector('pre').textContent = 'curl -sL https://disembark.host/run | bash -s -- backup "' + home + '" --token="' + S.token + '"' + (withChoices && S.backupToken ? ' --session-id="' + S.backupToken + '"' : '') + f;
        blocks.sync.querySelector('pre').textContent = 'disembark sync ' + home + ' "' + D.host(home) + '"' + f + sid;
        blocks.ncdu.querySelector('pre').textContent = 'disembark ncdu ' + home + sid;
        blocks.info.querySelector('pre').textContent = 'disembark info ' + home;
      }
      vs.on(['excluded', 'included', 'includeDb', 'includeFiles', 'backupToken', 'token'], update);
      update();
      return h('section', { class: 'dsk-card dsk-bk-cli' }, head, main,
        h('p', null, 'Files and database stream straight to your machine. Connect the CLI once in ', h('a', { href: '#settings' }, 'Settings'), '. ', h('code', null, 'disembark sync'), ' keeps a local mirror up to date.'),
        h('details', { class: 'dsk-gtk' },
          h('summary', null, D.icon('terminal'), 'More commands', D.icon('chevron', 'chev')),
          h('div', { class: 'dsk-gtk-body dsk-stack', style: { gap: '14px' } },
            blocks.runner, blocks.sync, blocks.ncdu, blocks.info,
            withChoices && S.backupToken ? h('p', null, 'These reuse this scan (session ', h('code', null, S.backupToken), '), so the CLI skips scanning again.') : null)));
    }

    /* ------------------------------------------- Pick up an earlier scan */
    // On a network's or WP Freighter install's main site, only scans of the
    // site picked under Back up: another site's file list would download
    // with this site's name. A scan from before 3.0.1 doesn't say which
    // site it covered, so it's offered only on a single site. (A subsite's
    // own dashboard scans only itself.)
    function fits(s) {
      var inst = D.config.install;
      if (!inst || !(inst.sites || []).length) return true;
      if (!('site' in s)) return false;
      var a = String(s.site || ''), b = String(S.site || '');
      return (a === 'all' ? '' : a) === b;
    }
    function renderScans() {
      var sessions = (S.sessions || []).filter(fits).slice(0, 5);
      if (!sessions.length) { D.mount(scans); return; }
      D.mount(scans, h('section', { class: 'dsk-card dsk-sec' },
        h('div', { class: 'dsk-sec-h' }, h('div', null, h('h3', null, 'Pick up an earlier scan'), h('p', null, 'Skip the scan and download from one of these. Files may have changed since.'))),
        h('ul', { class: 'dsk-scans' }, sessions.map(function (s) {
          var meta = h('span', { class: 'm' }, '…');
          var loaded = S.scanned && S.backupToken === s.token;
          sessionInfo(s).then(function (i) { meta.textContent = i ? D.fmt.plural(i.files, 'file') + ' · ' + D.fmt.size(i.size) : D.fmt.ago(s.timestamp); });
          var go = function () { if (!busy) resume(s); };
          return h('li', { tabindex: '0', role: 'button', 'aria-label': 'Pick up the scan from ' + when(s.timestamp), onclick: go, onkeydown: function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } } },
            D.icon('scan'), h('span', { class: 'd' }, when(s.timestamp), loaded ? h('span', { class: 'dsk-tag ok' }, 'in use') : null,
              s.filtered ? h('span', { class: 'dsk-tag', title: 'Scanned with folders left out (the CLI’s -x), so its file list isn’t the whole site.' }, 'some folders left out') : null), meta, D.icon('chevron', 'chev'));
        }))));
    }
  }
})();
