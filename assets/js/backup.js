/**
 * Back up: scan the site, choose what to include, and get the CLI commands
 * that run the backup with those choices.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h, S = D.store.state;

  D.section({ id: 'backup', title: 'Back up', icon: 'backup', mount: mount });

  var HERO_ART = '<svg viewBox="0 0 280 170" aria-hidden="true"><rect class="dsk-art-water" width="280" height="170" rx="18"/>' +
    '<rect class="dsk-art-land" width="280" height="44"/><rect class="dsk-art-wood" x="118" y="44" width="26" height="78" rx="3"/>' +
    '<path class="dsk-art-wave" fill="none" stroke-width="2" stroke-linecap="round" opacity=".55" d="M24 92q5-5 10 0t10 0M210 140q5-5 10 0t10 0M40 146q5-5 10 0t10 0M222 72q5-5 10 0t10 0"/>' +
    '<g class="dsk-boat"><path class="dsk-art-hull" d="M182 40c13 10 17 22 17 36v44c0 6-4 9-9 9h-16c-5 0-9-3-9-9V76c0-14 4-26 17-36z"/>' +
    '<path class="dsk-art-deck" d="M182 48c9 7 12 17 12 28v42c0 3-2 5-5 5h-14c-3 0-5-2-5-5V76c0-11 3-21 12-28z"/>' +
    '<rect x="172" y="62" width="9" height="9" rx="1.5" fill="#c8553d"/><rect x="183" y="62" width="9" height="9" rx="1.5" fill="#2b8a7e"/>' +
    '<rect x="172" y="73" width="9" height="9" rx="1.5" fill="#2b8a7e"/><rect x="183" y="73" width="9" height="9" rx="1.5" fill="#d9a441"/>' +
    '<rect x="173" y="96" width="18" height="16" rx="3" fill="#fff"/></g>' +
    '<rect x="96" y="14" width="70" height="22" rx="3" fill="#c8553d" opacity=".85"/></svg>';

  function mount(root) {
    var view = h('div');
    D.mount(root,
      h('div', { class: 'dsk-head' },
        h('div', null, h('h1', null, 'Back up'), h('p', null, 'Scan the site, choose what to bring, then run the backup with the Disembark CLI. Files and database stream straight to you.')),
        h('div', { class: 'dsk-head-tools' }, h('button', { class: 'dsk-btn', id: 'dsk-rescan', hidden: true, onclick: function (e) { scan(e.currentTarget); } }, D.icon('refresh'), 'Rescan'))),
      view);

    var vs = D.scope();
    function fresh() { vs.dispose(); vs = D.scope(); }
    function render() {
      fresh();
      root.querySelector('#dsk-rescan').hidden = !S.scanned;
      D.mount(view, S.scanned ? ready() : start());
    }
    D.store.on(['scanned', 'index'], render);
    D.store.on(['sessions', 'lastScan'], function () { if (!S.scanned) render(); });
    render();

    /* ----------------------------------------------------------- Start */
    function start() {
      var sessions = (S.sessions || []).slice(0, 5);
      return h('div', { class: 'dsk-stack' },
        h('div', { class: 'dsk-card dsk-hero' },
          h('div', null,
            h('h2', null, 'Ready when ', h('em', null, 'you are.')),
            h('p', null, 'A scan maps every file and table so you can leave out what you don’t need (caches, old backups, log tables) before anything is copied.'),
            h('div', { class: 'dsk-row' },
              h('button', { class: 'dsk-btn primary lg', onclick: function (e) { scan(e.currentTarget); } }, D.icon('scan'), 'Scan this site'),
              S.lastScan ? h('span', { class: 'muted' }, 'Last scan ' + D.fmt.ago(S.lastScan.timestamp) + ' · ' + D.fmt.plural(S.lastScan.total_files, 'file') + ' · ' + D.fmt.size(S.lastScan.total_size)) : null)),
          D.svg(HERO_ART, 'art')),
        sessions.length ? h('div', { class: 'dsk-card' },
          h('div', { class: 'dsk-card-head' }, h('h3', null, D.icon('history'), 'Pick up an earlier scan'), h('span', { class: 'grow' }), h('span', { class: 'muted' }, 'Files may have changed since.')),
          h('ul', { class: 'dsk-list' }, sessions.map(function (s) {
            return h('li', { class: 'clickable', tabindex: '0', onclick: function () { resume(s); }, onkeydown: function (e) { if (e.key === 'Enter') resume(s); } },
              D.icon('history'), h('span', { class: 'grow' }, D.fmt.date(s.timestamp)), h('span', { class: 'size' }, D.fmt.ago(s.timestamp) + ' · ' + s.token.slice(0, 8)), D.icon('chevron'));
          }))) : null,
        cliCard(false));
    }

    /* ------------------------------------------------------------ Scan */
    async function scan(btn) {
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
      D.mount(view, h('div', { class: 'dsk-card dsk-card-pad', style: { maxWidth: '640px' } },
        h('h2', { style: { fontSize: '28px', marginBottom: '16px' } }, 'Scanning ', D.host(D.config.homeUrl)), list, h('div', { style: { marginTop: '18px' } }, bar)));
      root.querySelector('#dsk-rescan').hidden = true;
      try {
        at('db', '', null);
        var tables = await D.loadDatabase(true);
        stepEls.db.meta.textContent = D.fmt.plural(tables.length, 'table');
        await D.scanSite(function (p) {
          var pct = p.total ? (p.done / p.total) * 100 : null;
          var meta = p.phase === 'scanning' ? D.fmt.num(p.done) + ' of ' + D.fmt.num(p.total) + ' folders' : D.fmt.num(p.done) + ' of ' + D.fmt.num(p.total) + ' parts';
          at(p.phase, meta, pct);
        });
        D.toast('Scan complete: ' + D.fmt.plural(S.files.length, 'file') + '.');
      } catch (e) {
        D.toast('The scan did not finish: ' + D.errorText(e), 'error');
        render();
      }
      void btn;
    }

    async function resume(session) {
      fresh();
      D.mount(view, h('div', { class: 'dsk-card dsk-card-pad', style: { maxWidth: '640px' } }, h('div', { class: 'dsk-row' }, h('span', { class: 'dsk-spin' }), 'Loading the scan from ' + D.fmt.date(session.timestamp) + '…')));
      try {
        await D.loadDatabase(true);
        await D.resumeSession(session);
        D.toast('Scan loaded.');
      } catch (e) {
        D.toast('Could not load that scan: ' + D.errorText(e), 'error');
        render();
      }
    }

    /* ----------------------------------------------------------- Ready */
    function ready() {
      var summary = h('div', { class: 'dsk-grid three' });
      function renderSummary() {
        var fileStats = fileTotals(), db = dbTotals();
        D.mount(summary,
          stat('Files', S.includeFiles ? D.fmt.size(fileStats.size) : 'Skipped', S.includeFiles ? D.fmt.plural(fileStats.files, 'file') + (fileStats.excluded ? ' · ' + D.fmt.num(fileStats.excluded) + ' left out' : '') : 'Not included', 'var(--files)'),
          stat('Database', S.includeDb ? D.fmt.size(db.size) : 'Skipped', S.includeDb ? D.fmt.plural(db.tables, 'table') + (db.left ? ' · ' + D.fmt.num(db.left) + ' left out' : '') : 'Not included', 'var(--db)'),
          stat('Total', D.fmt.size((S.includeFiles ? fileStats.size : 0) + (S.includeDb ? db.size : 0)), 'Scanned ' + (S.lastScan ? D.fmt.ago(S.lastScan.timestamp) : 'just now'), 'var(--sea)'));
      }
      vs.on(['excluded', 'included', 'includeDb', 'includeFiles'], renderSummary);
      renderSummary();
      return h('div', { class: 'dsk-stack' }, summary, h('div', { class: 'dsk-grid two' }, dbCard(), filesCard()), browserCard(), cliCard(true));
    }

    function stat(label, value, sub, color) {
      return h('div', { class: 'dsk-stat' }, h('div', { class: 'caps', style: { display: 'flex', gap: '8px', alignItems: 'center' } }, h('span', { class: 'dsk-dot', style: { background: color } }), label), h('div', { class: 'v' }, value), h('div', { class: 's' }, sub));
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
    function dbCard() {
      var q = '', sortBy = 'name', anchor = null, list = h('ul', { class: 'dsk-list' });
      var body = h('div', { class: 'dsk-scroll' }, list);
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
        var rows = visible();
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
            h('span', { class: 'grow mono', style: { fontSize: '13px' } }, t.table),
            h('span', { class: 'size' }, D.fmt.num(t.row_count) + ' rows'),
            h('span', { class: 'size', style: { minWidth: '64px', textAlign: 'right' } }, D.fmt.size(t.size)));
        }) : h('li', { class: 'muted' }, 'No tables match.'));
      }
      vs.on(['included'], renderList);
      renderList();
      var sortBtn = h('button', { class: 'dsk-btn ghost sm', onclick: function () { sortBy = sortBy === 'name' ? 'size' : 'name'; sortBtn.lastChild.textContent = sortBy === 'name' ? 'A–Z' : 'Largest'; renderList(); } }, D.icon('settings'), h('span', null, 'A–Z'));
      var card = h('div', { class: 'dsk-card' },
        h('div', { class: 'dsk-card-head' }, h('h3', null, D.icon('database'), 'Database'), h('span', { class: 'grow' }), D.toggle('Include', S.includeDb, function (v) { D.store.set({ includeDb: v }); body.hidden = !v; tools.hidden = !v; })),
        null, body);
      var tools = h('div', { class: 'dsk-row', style: { padding: '10px 14px', borderBottom: '1px solid var(--line)' } },
        h('div', { style: { flex: 1, minWidth: '160px' } }, D.search('Search tables', function (v) { q = v; renderList(); })),
        sortBtn,
        h('button', { class: 'dsk-btn ghost sm', onclick: function () { setIncluded(visible().map(function (t) { return t.table; }), true); } }, 'All'),
        h('button', { class: 'dsk-btn ghost sm', onclick: function () { setIncluded(visible().map(function (t) { return t.table; }), false); } }, 'None'));
      card.insertBefore(tools, body);
      body.hidden = tools.hidden = !S.includeDb;
      return card;
    }

    /* File picker */
    function filesCard() {
      var tree = D.Tree({ mode: 'exclude', scope: vs });
      var body = h('div', { class: 'dsk-scroll', style: { padding: '6px 0' } }, tree.el);
      var resetBtn = h('button', { class: 'dsk-btn ghost sm', onclick: function () { D.store.set({ excluded: new Set() }); } }, 'Reset');
      function syncReset() { resetBtn.hidden = !S.excluded.size; }
      vs.on(['excluded'], syncReset); syncReset();
      var hint = h('div', { class: 'muted', style: { padding: '10px 18px', borderBottom: '1px solid var(--line)', fontSize: '12.5px' } },
        'Click ', D.icon('checkCircle'), ' to leave something out. ', h('kbd', null, 'Shift'), '-click another to apply it to everything between.');
      hint.querySelector('svg').style.cssText = 'width:14px;height:14px;vertical-align:-2px;color:var(--teal)';
      var card = h('div', { class: 'dsk-card' },
        h('div', { class: 'dsk-card-head' }, h('h3', null, D.icon('folder'), 'Files'), h('span', { class: 'grow' }), resetBtn, D.toggle('Include', S.includeFiles, function (v) { D.store.set({ includeFiles: v }); body.hidden = hint.hidden = !v; })),
        hint, body);
      body.hidden = hint.hidden = !S.includeFiles;
      return card;
    }

    /* Back up in the browser */
    function browserCard() {
      var btn = h('button', { class: 'dsk-btn primary', onclick: function () { browserBackup(); } }, D.icon('download'), 'Download a backup zip');
      return h('div', { class: 'dsk-card dsk-card-pad dsk-row', style: { gap: '18px', alignItems: 'center' } },
        h('div', { style: { flex: 1, minWidth: '260px' } },
          h('h3', { style: { display: 'flex', gap: '9px', alignItems: 'center', marginBottom: '4px' } }, D.icon('zip'), 'Back up in the browser'),
          h('p', { class: 'muted' }, 'Builds a zip of your choices on the server, then downloads it. No CLI needed. Restores from Restore → From a backup file, or with the CLI. For very large sites the CLI is faster and never stores a copy on the server.')),
        btn);
    }

    async function browserBackup() {
      if (!S.includeFiles && !S.includeDb) { D.toast('Include files, the database, or both.', 'error'); return; }
      var files = S.includeFiles ? S.files.filter(function (f) { return !D.isExcluded(f.name); }) : [];
      var tables = S.includeDb ? S.database.filter(function (t) { return S.included.has(t.table); }) : [];
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
      var archive = D.host(D.config.homeUrl).replace(/[^a-z0-9.-]/gi, '_') + '-' + stamp + '.zip';
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
      async function step(body) { if (cancelled) throw new Error('cancelled'); return D.api.post('backup/archive', Object.assign({ backup_token: token, archive: archive }, body)); }
      try {
        for (var i = 0; i < batches.length; i++) {
          line.textContent = 'Adding files\u2026 batch ' + (i + 1) + ' of ' + batches.length;
          await step({ step: 'files', files: batches[i].files });
          tick(batches[i].size);
        }
        for (var j = 0; j < dbJobs.length; j++) {
          var job = dbJobs[j];
          line.textContent = job.tables ? 'Exporting the database\u2026 ' + (j + 1) + ' of ' + dbJobs.length : 'Exporting ' + job.table + ' (part ' + job.part + ' of ' + job.parts + ')\u2026';
          await step(job.tables ? { step: 'database', tables: job.tables } : { step: 'database', table: job.table, part: job.part, rows_per_part: job.rows });
          tick(job.size);
        }
        line.textContent = 'Finishing the zip\u2026';
        var fin = await step({ step: 'finish' });
        bar.set(100);
        if (close) close(null);
        D.download(fin.url, fin.file_name);
        var choice = await D.modal({
          title: 'Backup ready',
          body: h('div', { class: 'dsk-stack', style: { gap: '10px' } },
            h('p', null, h('strong', null, fin.file_name), ' (' + D.fmt.size(fin.size) + ') is downloading.'),
            h('p', { class: 'muted' }, 'Delete it from the server once the download finishes? It\u2019s a full copy of the site in a public folder (with an unguessable name).')),
          actions: [{ label: 'Keep it for now', value: 'keep' }, { label: 'Delete from server', kind: 'primary', value: 'delete' }]
        });
        if (choice === 'delete') { await cleanupArchive(); D.toast('Backup zip deleted from the server.'); }
        D.loadStatus();
      } catch (e) {
        if (close) close(null);
        await cleanupArchive();
        if (!cancelled) D.toast('The backup did not finish: ' + D.errorText(e), 'error'); else D.toast('Backup cancelled.');
      }
      async function cleanupArchive() {
        var base = archive.replace(/\.zip$/, '');
        for (var n of [archive, base + '-database.db.txt']) {
          try { await D.api.post('cleanup-file', { backup_token: token, file_name: n }); } catch (x) { /* best effort */ }
        }
      }
    }

    /* CLI commands */
    function cliCard(withChoices) {
      var home = D.config.homeUrl;
      var blocks = {
        runner: D.cmd('', { label: 'Back up now', hint: 'Downloads the CLI if needed and runs the backup with your choices.' }),
        backup: D.cmd('', { label: 'Backup', hint: 'A zip with files and database.' }),
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
        var f = withChoices ? flags() : '', sid = S.backupToken ? ' --session-id=' + S.backupToken : '';
        var folder = D.host(home);
        blocks.runner.querySelector('pre').textContent = 'curl -sL https://disembark.host/run | bash -s -- backup "' + home + '" --token="' + S.token + '"' + (S.backupToken ? ' --session-id="' + S.backupToken + '"' : '') + f;
        blocks.backup.querySelector('pre').textContent = 'disembark backup ' + home + f + sid;
        blocks.sync.querySelector('pre').textContent = 'disembark sync ' + home + ' "' + folder + '"' + f + sid;
        blocks.ncdu.querySelector('pre').textContent = 'disembark ncdu ' + home + sid;
        blocks.info.querySelector('pre').textContent = 'disembark info ' + home;
      }
      vs.on(['excluded', 'included', 'includeDb', 'includeFiles', 'backupToken', 'token'], update);
      update();
      return h('div', { class: 'dsk-card' },
        h('div', { class: 'dsk-card-head' }, h('h3', null, D.icon('terminal'), withChoices ? 'Run the backup' : 'Back up from your terminal'), h('span', { class: 'grow' }),
          h('a', { href: '#settings', class: 'dsk-btn ghost sm' }, D.icon('link'), 'Connect the CLI')),
        h('div', { class: 'dsk-card-pad dsk-stack', style: { gap: '16px' } },
          withChoices ? blocks.runner : null,
          blocks.backup, blocks.sync,
          withChoices ? h('div', { class: 'dsk-grid two' }, blocks.ncdu, blocks.info) : blocks.info,
          withChoices && S.backupToken ? D.note('', ['These commands reuse this scan (session ', h('code', null, S.backupToken), '), so the CLI skips scanning again.']) : null));
    }
  }
})();
