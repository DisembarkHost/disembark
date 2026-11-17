/**
 * Restore: bring a backup into this site, from another live Disembark site or
 * from a backup zip. A rollback point is saved before anything changes.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h, S = D.store.state;

  D.section({ id: 'restore', title: 'Restore', icon: 'restore', mount: mount });

  /** Parses "disembark connect <url> <token>" (or "<url> <token>"). */
  function parseConnect(text) {
    var parts = String(text || '').trim().split(/\s+/).filter(Boolean);
    if (parts.length >= 2 && parts[0].toLowerCase() === 'disembark' && parts[1].toLowerCase() === 'connect') parts.splice(0, 2);
    if (parts.length !== 2) return null;
    var url = parts[0], token = parts[1];
    if (!/^https?:\/\//i.test(url)) { if (url.indexOf('.') === -1) return null; url = 'https://' + url; }
    if (!/^[A-Za-z0-9]{20,}$/.test(token)) return null;
    return { url: url.replace(/\/+$/, ''), token: token };
  }
  D.parseConnect = parseConnect;

  var STEPS = [
    { key: 'pre', label: 'Checking this site' },
    { key: 'stage', label: 'Bringing the backup over' },
    { key: 'read', label: 'Reading the backup' },
    { key: 'snap', label: 'Saving a rollback point' },
    { key: 'files', label: 'Putting files in place' },
    { key: 'db', label: 'Importing the database' },
    { key: 'urls', label: 'Rewriting URLs' },
    { key: 'fin', label: 'Finishing up' }
  ];

  function mount(root) {
    var st = { mode: 'site', file: null, site: '', token: '', sourceUrl: '', running: false };
    var view = h('div');
    D.mount(root,
      h('div', { class: 'dsk-head' }, h('div', null, h('h1', null, 'Restore'),
        h('p', null, 'Bring a site into this one: pull it straight from another live site running Disembark, or upload a backup zip. A rollback point is saved first, so you can always go back.'))),
      view);
    renderForm();

    /* ------------------------------------------------------------ Form */
    function renderForm() {
      var startBtn = h('button', { class: 'dsk-btn primary lg', disabled: true, onclick: confirmStart }, D.icon('restore'), 'Restore into this site');
      function sync() { startBtn.disabled = st.mode === 'site' ? !(st.site && st.token) : !st.file; }

      var siteUrl = h('input', { class: 'dsk-input mono', placeholder: 'https://old-site.com', value: st.site, spellcheck: 'false', autocomplete: 'off', autocapitalize: 'none' });
      var siteToken = h('input', { class: 'dsk-input mono', placeholder: 'Connection token', value: st.token, spellcheck: 'false', autocomplete: 'off', autocapitalize: 'none' });
      [siteUrl, siteToken].forEach(function (input) {
        input.addEventListener('input', function () { st.site = siteUrl.value.trim(); st.token = siteToken.value.trim(); sync(); });
        input.addEventListener('paste', function (e) {
          var parsed = parseConnect((e.clipboardData || window.clipboardData).getData('text'));
          if (!parsed) return;
          e.preventDefault();
          siteUrl.value = st.site = parsed.url; siteToken.value = st.token = parsed.token; sync();
          D.toast('Filled in from the connect line.');
        });
      });
      var sitePane = h('div', null,
        h('div', { class: 'dsk-field' }, h('label', null, 'Source site'), siteUrl),
        h('div', { class: 'dsk-field' }, h('label', null, 'Connection token'), siteToken,
          h('span', { class: 'hint' }, 'On the source site, open Tools → Disembark → Settings and copy the connect line. Paste it into either field to fill both.')));

      var fileName = h('strong', null, st.file ? st.file.name : 'Choose a backup zip');
      var fileMeta = h('div', { class: 'muted', style: { marginTop: '4px' } }, st.file ? D.fmt.size(st.file.size) : 'or drop it here · made by “disembark backup”');
      var fileInput = h('input', { type: 'file', accept: '.zip', hidden: true, onchange: function () { pick(fileInput.files[0]); } });
      function pick(f) {
        if (!f) return;
        st.file = f; fileName.textContent = f.name; fileMeta.textContent = D.fmt.size(f.size); sync();
      }
      var drop = h('div', { class: 'dsk-drop', tabindex: '0', role: 'button', onclick: function () { fileInput.click(); }, onkeydown: function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } } },
        D.icon('zip'), h('div', { style: { marginTop: '6px' } }, fileName), fileMeta, fileInput);
      drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('over'); });
      drop.addEventListener('dragleave', function () { drop.classList.remove('over'); });
      drop.addEventListener('drop', function (e) { e.preventDefault(); drop.classList.remove('over'); pick(e.dataTransfer.files[0]); });
      var srcUrl = h('input', { class: 'dsk-input mono', placeholder: 'Recorded in the backup', value: st.sourceUrl, spellcheck: 'false', oninput: function () { st.sourceUrl = srcUrl.value.trim(); } });
      var filePane = h('div', null, drop,
        h('div', { class: 'dsk-field', style: { marginTop: '14px' } }, h('label', null, 'Source URL to rewrite (optional)'), srcUrl,
          h('span', { class: 'hint' }, 'Defaults to the address recorded in the backup. It’s rewritten to ' + D.config.homeUrl + '.')));

      var panes = h('div', null, st.mode === 'site' ? sitePane : filePane);
      var seg = D.seg([{ value: 'site', label: 'From a live site', icon: 'globe' }, { value: 'file', label: 'From a backup file', icon: 'zip' }], st.mode, function (v) {
        st.mode = v; D.mount(panes, v === 'site' ? sitePane : filePane); sync();
      });
      sync();
      D.mount(view, h('div', { class: 'dsk-grid two', style: { alignItems: 'start' } },
        h('div', { class: 'dsk-card dsk-card-pad dsk-stack' }, seg, panes, h('div', null, startBtn)),
        h('div', { class: 'dsk-stack' },
          D.note('warn', [h('strong', null, 'This replaces this site.'), ' Its files and database are overwritten with the backup. User accounts come from the backup too, so afterwards you log in with the source site’s credentials.']),
          D.note('', ['A rollback point is saved before anything changes. If something goes wrong, or you change your mind, ', h('strong', null, 'Roll back'), ' puts this site back exactly as it was.'], 'anchor'),
          D.note('', ['Prefer the terminal? ', h('code', null, 'disembark restore ' + D.config.homeUrl + ' <folder>'), ' does the same from a backup folder.'], 'terminal'))));
    }

    async function confirmStart() {
      var what = st.mode === 'site' ? D.host(st.site) : st.file.name;
      var ok = await D.confirm(h('div', { class: 'dsk-stack', style: { gap: '10px' } },
        h('p', null, 'Replace ', h('strong', null, D.host(D.config.homeUrl)), ' with ', h('strong', null, what), '?'),
        h('p', { class: 'muted' }, 'A rollback point is saved first. You’ll need to log in again afterwards.')), { title: 'Restore this site?', confirmLabel: 'Restore', danger: true });
      if (ok) run();
    }

    /* ------------------------------------------------------------- Run */
    function run() {
      var importId = D.randomHex(24), rollbackId = '', log = h('div', { class: 'dsk-log', hidden: true });
      var stepEls = {}, current = null, line = h('div', { class: 'muted', style: { minHeight: '20px', marginTop: '10px', fontFamily: 'var(--mono)', fontSize: '12.5px' } });
      var steps = h('div', { class: 'dsk-steps' }, STEPS.map(function (s, i) {
        var meta = h('span', { class: 'meta' });
        var el = h('div', { class: 'dsk-step' }, h('span', { class: 'mark' }, String(i + 1)), s.label, meta);
        stepEls[s.key] = { el: el, meta: meta };
        return el;
      }));
      var outcome = h('div');
      var title = h('h2', { style: { fontSize: '28px', flex: 1 } }, 'Restoring');
      var detailsBtn = h('button', { class: 'dsk-btn ghost sm', onclick: function () { log.hidden = !log.hidden; detailsBtn.lastChild.textContent = log.hidden ? 'Show details' : 'Hide details'; } }, D.icon('terminal'), h('span', null, 'Show details'));
      D.mount(view, h('div', { class: 'dsk-grid two', style: { alignItems: 'start' } },
        h('div', { class: 'dsk-card dsk-card-pad' },
          h('div', { class: 'dsk-row', style: { marginBottom: '16px' } }, title, detailsBtn),
          steps, line, h('div', { style: { marginTop: '12px' } }, log)),
        outcome));

      function rlog(msg, cls) { log.appendChild(h('div', { class: cls || '' }, msg)); log.scrollTop = log.scrollHeight; }
      function step(key, meta) {
        if (current) { stepEls[current].el.classList.remove('active'); stepEls[current].el.classList.add('done'); }
        current = key;
        stepEls[key].el.classList.add('active');
        if (meta) stepEls[key].meta.textContent = meta;
      }
      function skip(key, why) { stepEls[key].el.classList.add('done'); stepEls[key].meta.textContent = why; }
      function progress(text) { line.textContent = text || ''; }
      var t = function (body) { return Object.assign({ import_id: importId }, body || {}); };

      async function stageFromSite() {
        rlog('Connecting to ' + st.site + '…');
        var c = await D.api.post('import/pull/connect', t({ source_url: st.site, source_token: st.token }));
        rlog('Connected. Source: ' + c.source_home + ' (' + c.table_count + ' tables).');
        var scan = function (s, chunk) { return D.api.post('import/pull/scan', t({ step: s, chunk: chunk || 0 })); };
        await scan('initiate');
        for (var i = 0; i < 100000; i++) {
          var s = await scan('scan');
          if (s.scanned_dirs && s.total_dirs) progress('Scanning source folders… ' + D.fmt.num(s.scanned_dirs) + ' of ' + D.fmt.num(s.total_dirs));
          if (s.status === 'scan_complete') break;
        }
        var ch = await scan('chunkify');
        for (var c2 = 1; c2 <= (ch.total_chunks || 0); c2++) await scan('process_chunk', c2);
        var fin = await scan('finalize');
        rlog(D.fmt.plural(fin.total_files || 0, 'file') + ' to pull.');
        var done = false, fetched = 0;
        while (!done) {
          var r = await D.api.post('import/pull/fetch-files', t({ offset: fetched, count: 1000 }));
          fetched = r.fetched; done = r.done;
          progress('Pulling files… ' + D.fmt.num(fetched) + ' of ' + D.fmt.num(r.total));
          stepEls.stage.meta.textContent = D.fmt.num(fetched) + ' / ' + D.fmt.num(r.total);
        }
        rlog('Pulled ' + D.fmt.plural(fetched, 'file') + '.');
        progress('Pulling the database…');
        var db = await D.api.post('import/pull/fetch-database', t());
        rlog('Database pulled (' + (db.tables || 0) + ' tables).');
        return String(c.source_home || '').replace(/\/$/, '');
      }
      async function upload(file, chunkBytes) {
        var size = chunkBytes > 0 ? chunkBytes : 20 * 1024 * 1024;
        var total = Math.max(1, Math.ceil(file.size / size));
        if (total === 1) {
          var fd = new FormData(); fd.append('import_id', importId); fd.append('file', file, 'restore.zip');
          progress('Uploading ' + D.fmt.size(file.size) + '…');
          await D.api.upload('import/upload-zip', fd);
          return;
        }
        for (var i = 0; i < total; i++) {
          var part = new FormData();
          part.append('import_id', importId); part.append('file_path', 'restore.zip');
          part.append('chunk_index', i); part.append('total_chunks', total);
          part.append('file', file.slice(i * size, Math.min(file.size, (i + 1) * size)), 'restore.zip');
          await D.api.upload('import/upload-chunk', part);
          progress('Uploading… part ' + (i + 1) + ' of ' + total);
          stepEls.stage.meta.textContent = Math.round(((i + 1) / total) * 100) + '%';
        }
        progress('Unpacking the backup…');
        await D.api.post('import/extract-zip', t({ file_path: 'restore.zip' }));
      }

      (async function () {
        try {
          step('pre');
          var pre = await D.api.post('import/preflight', t());
          var destPrefix = pre.db_prefix || '', destHome = String(pre.home_url || D.config.homeUrl).replace(/\/$/, '');
          rlog('This site: ' + destHome + ' (prefix ' + destPrefix + ').', 'dim');

          step('stage');
          var siteHome = '';
          if (st.mode === 'site') siteHome = await stageFromSite();
          else { rlog('Uploading ' + st.file.name + '…'); await upload(st.file, pre.upload_chunk_bytes); }

          step('read'); progress('');
          var info = await D.api.post('import/staged-info', t());
          var srcPrefix = (info.source && info.source.db_prefix) || '';
          var srcHome = String(st.sourceUrl || siteHome || (info.source && info.source.home_url) || '').replace(/\/$/, '');
          rlog(D.fmt.plural(info.files || 0, 'file') + ' staged' + (info.sql_file ? ', database found.' : ', no database.'));
          stepEls.read.meta.textContent = D.fmt.plural(info.files || 0, 'file');

          step('snap');
          await D.api.post('import/snapshot', t());
          rollbackId = importId;
          rlog('Rollback point saved (id ' + importId + ').', 'ok');
          showRollbackOnly();

          step('files');
          var dep = await D.api.post('import/deploy-files', t({ files: ['*'] }));
          rlog('Placed ' + D.fmt.plural(dep.deployed || 0, 'file') + '.');
          stepEls.files.meta.textContent = D.fmt.num(dep.deployed || 0);
          if (dep.errors && dep.errors.length) {
            dep.errors.slice(0, 5).forEach(function (e) { rlog('  ' + e, 'err'); });
            throw new Error(D.fmt.plural(dep.errors.length, 'file') + ' could not be put in place. Stopped before the database import.');
          }

          if (info.sql_file) {
            step('db');
            progress('This can take a while on large databases…');
            var sq = await D.api.post('import/import-staged-sql', t({ sql_file: info.sql_file, old_prefix: srcPrefix, new_prefix: destPrefix }));
            rlog(D.fmt.num(sq.statements_executed || 0) + ' statements executed.');
            stepEls.db.meta.textContent = D.fmt.num(sq.statements_executed || 0) + ' statements';
            if (sq.errors && sq.errors.length) {
              sq.errors.slice(0, 5).forEach(function (e) { rlog('  ' + e, 'err'); });
              throw new Error('The database import reported ' + D.fmt.plural(sq.errors.length, 'error') + '. Stopped before rewriting URLs.');
            }
            if (srcPrefix && destPrefix && srcPrefix !== destPrefix) {
              var rp = await D.api.post('import/remap-prefix', t({ old_prefix: srcPrefix, new_prefix: destPrefix }));
              rlog('Table prefix remapped ' + srcPrefix + ' → ' + destPrefix + ' (' + (rp.keys_changed || 0) + ' keys).');
            }
            step('urls'); progress('');
            if (srcHome && destHome && srcHome !== destHome) {
              rlog('Rewriting ' + srcHome + ' → ' + destHome + '…');
              var sr = await D.api.post('import/search-replace', t({ from: srcHome, to: destHome }));
              rlog(D.fmt.num(sr.cells_changed || 0) + ' cells updated.');
              stepEls.urls.meta.textContent = D.fmt.num(sr.cells_changed || 0) + ' cells';
            } else skip('urls', 'same address');
          } else { skip('db', 'none in backup'); skip('urls', 'not needed'); }

          step('fin');
          await D.api.post('import/finalize', t());
          stepEls.fin.el.classList.remove('active'); stepEls.fin.el.classList.add('done');
          progress('');
          rlog('Restore complete.', 'ok');
          title.textContent = 'Restored';
          showDone();
        } catch (e) {
          var msg = D.errorText(e);
          rlog('ERROR: ' + msg, 'err');
          title.textContent = 'Stopped';
          progress('');
          if (current) { stepEls[current].el.classList.remove('active'); stepEls[current].el.classList.add('failed'); stepEls[current].meta.textContent = 'stopped'; }
          showError(msg);
        }
      })();

      function rollbackButton(label, kind) {
        return h('button', { class: 'dsk-btn ' + kind, onclick: function (e) { rollback(e.currentTarget); } }, D.icon('restore'), label);
      }
      function cliUndo() { return D.cmd('disembark restore ' + D.config.homeUrl + ' --rollback=' + rollbackId); }
      function showRollbackOnly() {
        D.mount(outcome, h('div', { class: 'dsk-card dsk-card-pad dsk-stack', style: { gap: '12px' } },
          h('h3', null, 'Rollback point saved'),
          h('p', { class: 'muted' }, 'Whatever happens next, this puts the site back as it was:'), cliUndo()));
      }
      function showError(msg) {
        D.mount(outcome, h('div', { class: 'dsk-stack' },
          D.note('error', [h('strong', null, 'The restore stopped. '), msg]),
          rollbackId ? h('div', { class: 'dsk-card dsk-card-pad dsk-stack', style: { gap: '12px' } },
            h('h3', null, 'This site may be partly restored'),
            h('p', { class: 'muted' }, 'Roll back to the snapshot taken before the restore started.'),
            h('div', null, rollbackButton('Roll back', 'primary')), cliUndo()) : h('div', null, h('button', { class: 'dsk-btn', onclick: renderForm }, 'Back to the form'))));
      }
      function showDone() {
        D.mount(outcome, h('div', { class: 'dsk-card dsk-card-pad dsk-stack', style: { gap: '14px' } },
          h('div', { class: 'dsk-row' }, D.icon('checkCircle'), h('h2', { style: { fontSize: '30px' } }, 'Safely ashore.')),
          h('p', null, D.host(D.config.homeUrl) + ' now holds the restored site.'),
          D.note('warn', ['User accounts came from the backup, so your current login no longer applies. ', h('strong', null, 'Log in with the source site’s credentials.')]),
          h('div', { class: 'dsk-row' },
            h('a', { class: 'dsk-btn primary', href: D.config.loginUrl }, 'Log in again'),
            rollbackButton('Undo restore', '')),
          h('div', null, h('div', { class: 'caps', style: { marginBottom: '6px' } }, 'Undo from the CLI'), cliUndo())));
      }
      async function rollback(btn) {
        await D.busy(btn, async function () {
          rlog('Rolling back to the snapshot…');
          try {
            var r = await D.api.post('import/rollback', { import_id: rollbackId });
            if (r.errors && r.errors.length) {
              r.errors.slice(0, 5).forEach(function (e) { rlog('  ' + e, 'err'); });
              throw new Error('Rollback reported ' + D.fmt.plural(r.errors.length, 'error') + '. It can be run again from the CLI.');
            }
            rlog('Rolled back.', 'ok');
            // The snapshot brought back this site's own users, so the login
            // that started the restore is valid again.
            D.mount(outcome, h('div', { class: 'dsk-stack' },
              D.note('ok', [h('strong', null, 'Rolled back. '), 'This site is back to how it was before the restore.']),
              h('div', null, h('button', { class: 'dsk-btn', onclick: renderForm }, D.icon('restore'), 'Start another restore'))));
          } catch (e) {
            rlog('ERROR: ' + D.errorText(e), 'err');
            D.toast('Rollback failed: ' + D.errorText(e), 'error');
          }
        });
      }
    }
    void S;
  }
})();
