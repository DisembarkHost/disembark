/**
 * Pull: copy another live site running Disembark into this one. This site
 * downloads from the source server-to-server, so the browser only drives the
 * steps. The Move tab (move.js) collects the connect line and calls
 * D.pull.run(); this file holds the parts that are Pull's own:
 *
 * - moves that stopped before finishing (Resume / Discard),
 * - the catch-up pull when this site has pulled from the source before.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h;

  var lastPulls = [];
  D.pull = {
    /** Loads which sources this site pulled from before (for catch-up). */
    loadLastPulls: function () {
      return D.api.get('import/last-pulls').then(function (list) { lastPulls = Array.isArray(list) ? list : []; return lastPulls; }).catch(function () { return lastPulls; });
    },
    /** The last pull from site, or null. */
    lastPullFor: function (site) {
      var a = D.address(site);
      return a ? lastPulls.find(function (p) { return D.address(p.source) === a; }) || null : null;
    },
    unfinished: unfinished,
    run: run
  };

  /**
   * Lists unfinished moves into box, each with Resume and Discard.
   * onResume(o) is called with the run options to carry one on.
   */
  async function unfinished(box, onResume) {
    var list = [];
    try { list = await D.api.get('import/unfinished'); } catch (e) { /* older Disembark */ }
    if (!Array.isArray(list) || !list.length) { D.mount(box); return; }
    D.mount(box, h('div', { class: 'dsk-unfinished' }, list.map(function (m) {
      var where = m.rollback_point
        ? 'It stopped after the rollback point was saved, so this site may be partly changed.'
        : m.database ? 'Files and database were copied; nothing on this site has changed yet.'
        : m.files_total ? 'It stopped at ' + D.fmt.num(m.files_fetched) + ' of ' + D.fmt.num(m.files_total) + ' files; nothing on this site has changed yet.'
        : 'It stopped while scanning the source; nothing on this site has changed yet.';
      var cli = m.move && m.move.client === 'cli';
      return D.note(m.rollback_point ? 'warn' : '', [
        h('strong', null, 'An unfinished ' + (m.move && m.move.kind === 'push' ? 'push' : 'pull') + ' from ' + D.host(m.source) + '. '),
        (m.started ? 'Started ' + D.fmt.ago(m.started) + ', last active ' : 'Last active ') + D.fmt.ago(m.touched) + '. ' + where,
        m.rollback_point && m.idle_days >= 7 ? ' It has waited ' + D.fmt.plural(m.idle_days, 'day') + ': it stays until you resume it, discard it or roll it back.' : '',
        m.rollback_point && m.left_alone ? ' ' + (D.OLD_POINT_NOTE || '') : '',
        h('div', { class: 'dsk-row', style: { marginTop: '10px' } },
          cli ? h('code', null, 'wp disembark pull --resume=' + m.id)
            : h('button', { class: 'dsk-btn primary sm', type: 'button', onclick: function (e) { resume(m, e.currentTarget); } }, D.icon('play'), 'Resume'),
          h('button', { class: 'dsk-btn ghost sm', type: 'button', onclick: function (e) { discard(m, e.currentTarget); } }, D.icon('trash'), m.rollback_point ? 'Roll back and discard' : 'Discard'))
      ], 'history');
    })));

    async function resume(m, btn) {
      var data;
      try { data = await D.busy(btn, function () { return D.api.post('import/resume', { import_id: m.id }); }); }
      catch (e) { D.toast(D.errorText(e), 'error'); unfinished(box, onResume); return; }
      if (!data) return;
      D.mount(box);
      onResume({ site: m.source, token: '', quiet: !!(m.move && m.move.quiet), label: (m.move && m.move.label) || 'Before pull from ' + D.host(m.source),
        resume: { id: m.id, steps: data.steps || {}, started: m.started, pace: data.pace || null } });
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
            var r = await D.paced(D.api, 'import/rollback', { import_id: m.id });
            if (r.errors && r.errors.length) throw new Error('The rollback reported ' + D.fmt.plural(r.errors.length, 'error') + '. The rollback point is kept: try again from Backups → Restore points.');
            rolledBack = true;
          }
          await D.api.post('import/cancel', { import_id: m.id, action: 'cleanup', delete_restore_point: rolledBack });
          D.toast(m.rollback_point ? 'Rolled back and cleaned up.' : 'Discarded.');
          if (m.rollback_point) setTimeout(function () { location.href = D.config.loginUrl; }, 1200);
        } catch (e) { D.toast(D.errorText(e), 'error'); }
        unfinished(box, onResume);
      });
    }
  }

  /**
   * Runs a pull into view. o: { site, token, quiet, catchUp, into, scene,
   * label, resume }. back() goes back to the Move form.
   */
  function run(view, o, back) {
    D.runMove(view, {
      api: D.api, local: true, mode: 'site', site: o.site, token: o.token, quiet: o.quiet, catchUp: o.catchUp, resume: o.resume, into: o.into,
      scene: o.scene, kind: 'pull',
      targetHome: D.config.homeUrl, label: o.label, title: o.resume ? 'Resuming' : 'Pulling', doneTitle: 'Pulled', againLabel: 'Start another move', icon: 'ship',
      back: back,
      done: function (outcome, ctx) { D.arrivedView(outcome, ctx, 'Undo pull'); }
    });
  }
})();
