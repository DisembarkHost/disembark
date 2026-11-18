/**
 * Pull: copy another live site running Disembark into this one. This site
 * downloads from the source server-to-server, so the browser only drives the
 * steps. The mirror of Push, for when you're logged in to the destination.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h;

  D.section({ id: 'pull', title: 'Pull', icon: 'download', mount: mount });

  function mount(root) {
    var st = { site: '', token: '', quiet: true };
    var view = h('div');
    D.mount(root,
      h('div', { class: 'dsk-head' }, h('div', null, h('h1', null, 'Pull'),
        h('p', null, 'Copy another site running Disembark into this one: live to staging, or off an old host. This site downloads everything straight from the source and saves a rollback point first.'))),
      view);
    renderForm();

    function renderForm() {
      var btn = h('button', { class: 'dsk-btn primary lg', disabled: true, onclick: start }, D.icon('download'), 'Pull into this site');
      var route = h('span', { class: 'dsk-badge accent' }, 'source');
      function sync() {
        btn.disabled = !(st.site && st.token);
        route.textContent = st.site ? D.host(st.site) : 'source';
      }
      var fields = D.connectFields(st, sync, {
        site: 'Source site', placeholder: 'https://live-site.com',
        hint: 'On the source, open Tools → Disembark → Settings and copy its connect line. Paste it into either field to fill both.'
      });
      sync();
      D.mount(view, h('div', { class: 'dsk-stack' }, h('div', { class: 'dsk-grid two', style: { alignItems: 'start' } },
        h('div', { class: 'dsk-card dsk-card-pad dsk-stack' },
          h('div', { class: 'dsk-row' }, route, D.icon('chevron'), h('span', { class: 'dsk-badge' }, D.host(D.config.homeUrl))),
          fields,
          D.quietToggle(st, 'Keep this copy quiet', 'For a staging or dev copy of a live site: emails are held, scheduled jobs paused, checkout closed, and payment and marketing APIs blocked, so the copy can’t reach real customers. Only applies when the address changes, and stands down by itself if this site later moves to the real domain.'),
          h('div', null, btn)),
        h('div', { class: 'dsk-stack' },
          D.note('warn', [h('strong', null, 'This replaces this site.'), ' Its files and database are overwritten with the source. User accounts come over too, so afterwards you log in with the source site’s credentials.']),
          D.note('', ['Nothing changes on the source: it’s only read from. A rollback point is saved here before anything changes, so ', h('strong', null, 'Undo pull'), ' puts this site back exactly as it was.'], 'anchor'),
          D.note('', ['This site downloads from the source, so the source has to be reachable from here (not a local-only address). Prefer the terminal? ', h('code', null, 'wp disembark pull <connect line>'), ' does the same over SSH.'], 'terminal'))),
        D.restorePoints()));
    }

    async function start() {
      var src = D.host(st.site), here = D.host(D.config.homeUrl);
      if (D.address(st.site) === D.address(D.config.homeUrl)) {
        D.toast('That’s this site’s own address. Paste the source site’s connect line.', 'error');
        return;
      }
      var ok = await D.confirm(h('div', { class: 'dsk-stack', style: { gap: '10px' } },
        h('p', null, 'Replace ', h('strong', null, here), ' with a copy of ', h('strong', null, src), '?'),
        h('p', { class: 'muted' }, (st.quiet ? 'Quiet mode goes on before anything arrives. ' : 'Quiet mode is off: this copy will send emails and run scheduled jobs. ') + 'A rollback point is saved first. You’ll need to log in again afterwards.')),
        { title: 'Pull ' + src + '?', confirmLabel: 'Pull', danger: true });
      if (!ok) return;
      D.runMove(view, {
        api: D.api, local: true, mode: 'site', site: st.site, token: st.token, quiet: st.quiet,
        targetHome: D.config.homeUrl, label: 'Before pull from ' + src, title: 'Pulling', doneTitle: 'Pulled', againLabel: 'Start another pull', icon: 'download',
        back: renderForm,
        done: function (outcome, ctx) { D.arrivedView(outcome, ctx, 'Undo pull'); }
      });
    }
  }
})();
