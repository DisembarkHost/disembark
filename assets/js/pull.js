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
      if (!a) return null;
      // A site pulled out of a network or WP Freighter install is remembered
      // by its own address and by the install's connect line it came through.
      var mine = lastPulls.filter(function (p) { return D.address(p.source) === a; })
        .concat(lastPulls.filter(function (p) { return p.via && D.address(p.via) === a && D.address(p.source) !== a; }));
      // Only this site's newest pull (current) can catch up: a pull since
      // replaced the older ones' files, so the server brings everything
      // for them anyway. An older Disembark doesn't say: the first match.
      if (lastPulls.some(function (p) { return 'current' in p; })) return mine.filter(function (p) { return p.current; })[0] || null;
      return mine[0] || null;
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
    // A move still running (a CLI pull, another tab, a push driven from the
    // source) can't be resumed or discarded under it: its buttons wait, and
    // the list looks again in half a minute.
    if (list.some(function (m) { return m.active; })) {
      setTimeout(function () { if (box.isConnected) unfinished(box, onResume); }, 30000);
    }
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
        m.active ? h('div', { style: { marginTop: '8px' } }, h('strong', null, 'Still running: '), 'it was active ' + (m.active_ago >= 0 ? D.fmt.plural(Math.round(m.active_ago), 'second') + ' ago' : D.fmt.ago(m.touched)) + '. Resume and Discard wait until it stops.') : null,
        h('div', { class: 'dsk-row', style: { marginTop: '10px' } },
          cli ? h('code', null, 'wp disembark pull --resume=' + m.id)
            : h('button', { class: 'dsk-btn primary sm', type: 'button', disabled: !!m.active, onclick: function (e) { resume(m, e.currentTarget); } }, D.icon('play'), 'Resume'),
          h('button', { class: 'dsk-btn ghost sm', type: 'button', disabled: !!m.active, onclick: function (e) { discard(m, e.currentTarget); } }, D.icon('trash'), m.rollback_point ? 'Roll back and discard' : 'Discard'))
      ], 'history');
    })));

    async function resume(m, btn) {
      var data;
      try { data = await D.busy(btn, function () { return D.api.post('import/resume', { import_id: m.id }); }); }
      catch (e) { D.toast(D.errorText(e), 'error'); unfinished(box, onResume); return; }
      if (!data) return;
      D.mount(box);
      onResume({ site: m.source, token: '', quiet: !!(m.move && m.move.quiet), catchUp: !!m.catch_up, label: (m.move && m.move.label) || 'Before pull from ' + D.host(m.source),
        resume: { id: m.id, steps: data.steps || {}, started: m.started, pace: data.pace || null } });
    }

    async function discard(m, btn) {
      // With a restore point: the same question and rollback as Backups →
      // Restore points, so a move made since is undone first.
      var pick = null;
      if (m.rollback_point) {
        try { pick = await D.askRollback(D.api, m.id, D.config.homeUrl, btn, { title: 'Roll back and discard?', confirmLabel: 'Roll back and discard', after: 'Then what this move copied and its restore point are deleted.' }); }
        catch (e) { D.toast(D.errorText(e), 'error'); unfinished(box, onResume); return; }
        if (!pick) return;
      } else {
        var ok = await D.confirm('Delete what this move copied so far? Nothing on this site has changed.', { title: 'Discard this move?', confirmLabel: 'Discard', danger: true });
        if (!ok) return;
      }
      await D.busy(btn, async function () {
        try {
          var rolledBack = false, r = null;
          if (m.rollback_point) {
            r = await D.paced(D.api, 'import/rollback', { import_id: m.id, files: pick.files, later: true });
            if (r.quiet_mode_lifted && D.quietOff) D.quietOff();
            if (r.errors && r.errors.length) throw new Error('The rollback reported ' + D.fmt.plural(r.errors.length, 'error') + '. The rollback point is kept: try again from Backups → Restore points.');
            rolledBack = true;
          }
          await D.api.post('import/cancel', { import_id: m.id, action: 'cleanup', delete_restore_point: rolledBack });
          D.toast(m.rollback_point ? 'Rolled back and cleaned up.' : 'Discarded.');
          // User accounts went back with a whole-site move's database: log in again.
          if (r && !r.logins_kept) { setTimeout(function () { location.href = D.config.loginUrl; }, 1200); return; }
        } catch (e) {
          if (D.quietLifted(e)) D.quietOff();
          D.toast(D.laterPointsText(e) || D.errorText(e), 'error');
        }
        unfinished(box, onResume);
      });
    }
  }

  /**
   * Runs a pull into view. o: { site, token, quiet, catchUp, into, scene,
   * label, resume }. back() goes back to the Move form.
   */
  function run(view, o, back, onFinish) {
    D.runMove(view, {
      api: D.api, local: true, mode: 'site', site: o.site, token: o.token, quiet: o.quiet, catchUp: o.catchUp, lastSite: o.lastSite, resume: o.resume, into: o.into,
      scene: o.scene, kind: 'pull',
      targetHome: D.config.homeUrl, label: o.label, title: o.resume ? 'Resuming' : 'Pulling', doneTitle: 'Pulled', againLabel: 'Start another move', icon: 'ship',
      back: back, onFinish: onFinish,
      done: function (outcome, ctx) { D.arrivedView(outcome, ctx, 'Undo pull'); }
    });
  }
})();
