/**
 * Pull: copy another live site running Disembark into this one. This site
 * downloads from the source server-to-server, so the browser only drives the
 * steps. The mirror of Push, for when you're logged in to the destination.
 *
 * Also lists moves that stopped before finishing (Resume / Discard), and
 * offers a catch-up pull when this site has pulled from the source before.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h;

  D.section({ id: 'pull', title: 'Pull', icon: 'download', mount: mount });

  function mount(root) {
    var st = { site: '', token: '', quiet: true, catchUp: true };
    var view = h('div'), unfinishedBox = h('div');
    var lastPulls = [];
    D.mount(root,
      h('div', { class: 'dsk-head' }, h('div', null, h('h1', null, 'Pull'),
        h('p', null, 'Copy another site running Disembark into this one: live to staging, or off an old host. This site downloads everything straight from the source and saves a rollback point first.'))),
      unfinishedBox, view);
    renderForm();
    loadUnfinished();
    D.api.get('import/last-pulls').then(function (list) { lastPulls = Array.isArray(list) ? list : []; if (view.sync) view.sync(); }).catch(function () {});

    function lastPullFor(site) {
      var a = D.address(site);
      return a ? lastPulls.find(function (p) { return D.address(p.source) === a; }) : null;
    }

    /* ------------------------------------------------ Unfinished moves */
    async function loadUnfinished() {
      var list = [];
      try { list = await D.api.get('import/unfinished'); } catch (e) { /* older Disembark */ }
      if (!Array.isArray(list) || !list.length) { D.mount(unfinishedBox); return; }
      D.mount(unfinishedBox, h('div', { class: 'dsk-stack', style: { marginBottom: '18px' } }, list.map(function (m) {
        var where = m.rollback_point
          ? 'It stopped after the rollback point was saved, so this site may be partly changed.'
          : m.database ? 'Files and database were copied; nothing on this site has changed yet.'
          : m.files_total ? 'It stopped at ' + D.fmt.num(m.files_fetched) + ' of ' + D.fmt.num(m.files_total) + ' files; nothing on this site has changed yet.'
          : 'It stopped while scanning the source; nothing on this site has changed yet.';
        var cli = m.move && m.move.client === 'cli';
        return D.note(m.rollback_point ? 'warn' : '', [
          h('strong', null, 'An unfinished ' + (m.move && m.move.kind === 'push' ? 'push' : 'pull') + ' from ' + D.host(m.source) + '. '),
          'Started ' + D.fmt.ago(m.started) + ', last active ' + D.fmt.ago(m.touched) + '. ' + where,
          h('div', { class: 'dsk-row', style: { marginTop: '10px' } },
            cli ? h('code', null, 'wp disembark pull --resume=' + m.id)
              : h('button', { class: 'dsk-btn primary sm', onclick: function (e) { resume(m, e.currentTarget); } }, D.icon('play'), 'Resume'),
            h('button', { class: 'dsk-btn ghost sm', onclick: function (e) { discard(m, e.currentTarget); } }, D.icon('trash'), m.rollback_point ? 'Roll back and discard' : 'Discard'))
        ], 'history');
      })));
    }

    async function resume(m, btn) {
      var data;
      try { data = await D.busy(btn, function () { return D.api.post('import/resume', { import_id: m.id }); }); }
      catch (e) { D.toast(D.errorText(e), 'error'); loadUnfinished(); return; }
      if (!data) return;
      D.mount(unfinishedBox);
      run({ site: m.source, token: '', quiet: !!(m.move && m.move.quiet), label: (m.move && m.move.label) || 'Before pull from ' + D.host(m.source),
        resume: { id: m.id, steps: data.steps || {}, started: m.started } });
    }

    async function discard(m, btn) {
      var ok = await D.confirm(m.rollback_point
        ? 'Roll ' + D.host(D.config.homeUrl) + ' back to how it was before this move, then delete what it copied and its rollback point?'
        : 'Delete what this move copied so far? Nothing on this site has changed.',
        { title: m.rollback_point ? 'Roll back and discard?' : 'Discard this move?', confirmLabel: m.rollback_point ? 'Roll back and discard' : 'Discard', danger: true });
      if (!ok) return;
      await D.busy(btn, async function () {
        try {
          var rolledBack = false;
          if (m.rollback_point) {
            var r = await D.api.post('import/rollback', { import_id: m.id });
            if (r.errors && r.errors.length) throw new Error('The rollback reported ' + D.fmt.plural(r.errors.length, 'error') + '. The rollback point is kept: try again from Restore → Restore points.');
            rolledBack = true;
          }
          await D.api.post('import/cancel', { import_id: m.id, action: 'cleanup', delete_restore_point: rolledBack });
          D.toast(m.rollback_point ? 'Rolled back and cleaned up.' : 'Discarded.');
          if (m.rollback_point) setTimeout(function () { location.href = D.config.loginUrl; }, 1200);
        } catch (e) { D.toast(D.errorText(e), 'error'); }
        loadUnfinished();
      });
    }

    /* ------------------------------------------------------------ Form */
    function renderForm() {
      var btn = h('button', { class: 'dsk-btn primary lg', disabled: true, onclick: start }, D.icon('download'), 'Pull into this site');
      var route = h('span', { class: 'dsk-badge accent' }, 'source');
      var catchBox = h('div');
      function sync() {
        btn.disabled = !(st.site && st.token);
        route.textContent = st.site ? D.host(st.site) : 'source';
        var last = lastPullFor(st.site);
        if (!last) { D.mount(catchBox); return; }
        D.mount(catchBox, h('div', { class: 'dsk-field' },
          D.toggle('Only copy what changed since the last pull', st.catchUp, function (v) { st.catchUp = v; }),
          h('span', { class: 'hint' }, 'This site pulled ' + D.host(last.source) + ' ' + D.fmt.ago(last.at) + ' (' + D.fmt.date(last.at) + '). Files that are new or changed since then come across, files removed there are removed here, and the database is copied again in full. Turn this off to copy every file.')));
      }
      view.sync = sync;
      var fields = D.connectFields(st, sync, {
        site: 'Source site', placeholder: 'https://live-site.com',
        hint: 'On the source, open Tools → Disembark → Settings and copy its connect line. Paste it into either field to fill both.'
      });
      sync();
      D.mount(view, h('div', { class: 'dsk-stack' }, h('div', { class: 'dsk-grid two', style: { alignItems: 'start' } },
        h('div', { class: 'dsk-card dsk-card-pad dsk-stack' },
          h('div', { class: 'dsk-row' }, route, D.icon('chevron'), h('span', { class: 'dsk-badge' }, D.host(D.config.homeUrl))),
          fields,
          catchBox,
          D.quietToggle(st, 'Keep this copy quiet', 'For a staging or dev copy of a live site: emails are held, scheduled jobs paused, checkout closed, and payment and marketing APIs blocked, so the copy can’t reach real customers. Only applies when the address changes, and stands down by itself if this site later moves to the real domain.'),
          h('div', null, btn)),
        h('div', { class: 'dsk-stack' },
          D.note('warn', [h('strong', null, 'This replaces this site.'), ' Its files and database are overwritten with the source. User accounts come over too, so afterwards you log in with the source site’s credentials.']),
          D.note('', ['Nothing changes on the source: it’s only read from. A rollback point is saved here before anything changes, so ', h('strong', null, 'Undo pull'), ' puts this site back exactly as it was.'], 'anchor'),
          D.note('', ['If the pull stops (a closed tab, a lost connection), it can be resumed from here. Prefer the terminal? ', h('code', null, 'wp disembark pull <connect line>'), ' does the same over SSH.'], 'terminal'))),
        D.restorePoints()));
    }

    async function start() {
      var src = D.host(st.site), here = D.host(D.config.homeUrl);
      if (D.address(st.site) === D.address(D.config.homeUrl)) {
        D.toast('That’s this site’s own address. Paste the source site’s connect line.', 'error');
        return;
      }
      var catchUp = st.catchUp && !!lastPullFor(st.site);
      var ok = await D.confirm(h('div', { class: 'dsk-stack', style: { gap: '10px' } },
        h('p', null, catchUp ? 'Catch ' + here + ' up with ' : 'Replace ' + here + ' with a copy of ', h('strong', null, src), '?'),
        h('p', { class: 'muted' }, (catchUp ? 'Only changed files come across; the database is replaced in full. ' : '') + (st.quiet ? 'Quiet mode goes on before anything arrives. ' : 'Quiet mode is off: this copy will send emails and run scheduled jobs. ') + 'A rollback point is saved first. You’ll need to log in again afterwards.')),
        { title: (catchUp ? 'Catch up from ' : 'Pull ') + src + '?', confirmLabel: catchUp ? 'Catch up' : 'Pull', danger: true });
      if (!ok) return;
      run({ site: st.site, token: st.token, quiet: st.quiet, catchUp: catchUp, label: (catchUp ? 'Before catch-up from ' : 'Before pull from ') + src });
    }

    function run(o) {
      D.mount(unfinishedBox);
      D.runMove(view, {
        api: D.api, local: true, mode: 'site', site: o.site, token: o.token, quiet: o.quiet, catchUp: o.catchUp, resume: o.resume,
        targetHome: D.config.homeUrl, label: o.label, title: o.resume ? 'Resuming' : 'Pulling', doneTitle: 'Pulled', againLabel: 'Start another pull', icon: 'download',
        back: function () { renderForm(); loadUnfinished(); },
        done: function (outcome, ctx) { D.arrivedView(outcome, ctx, 'Undo pull'); }
      });
    }
  }
})();
