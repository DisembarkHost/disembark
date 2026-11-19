/**
 * Push: send this site to another site running Disembark. The browser drives
 * the destination's import API directly (WordPress REST answers CORS), and the
 * destination pulls from this site server-to-server, so nothing passes through
 * the browser but instructions.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h, S = D.store.state;

  D.section({ id: 'push', title: 'Push', icon: 'upload', mount: mount });

  /**
   * A client for another site's Disembark API, authenticated with its token.
   * Probes pretty permalinks first, then ?rest_route= (plain permalinks).
   */
  D.remoteApi = async function (siteUrl, token) {
    var base = String(siteUrl).replace(/\/+$/, '');
    var host = D.host(base);
    var usePlain = false;
    async function call(root, endpoint, body) {
      if (usePlain) root = base + '/?rest_route=/disembark/v1/';
      var res;
      try {
        res = await fetch(root + endpoint, {
          method: 'POST', mode: 'cors', credentials: 'omit',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(Object.assign({ token: token }, body || {}))
        });
      } catch (e) {
        throw new D.api.Error('Couldn’t reach ' + host + ' from this browser. Its server may block cross-site requests, or one site is http and the other https. You can pull from the destination instead: on ' + host + ', open Tools → Disembark → Pull, and paste this site’s connect line.', 0);
      }
      var text = await res.text(), data = text;
      try { data = text === '' ? null : JSON.parse(text); } catch (e) { /* not JSON */ }
      // The destination took a database with plain permalinks mid-push, so
      // /wp-json/ now answers with a page: carry on through ?rest_route=.
      if (res.ok && root.indexOf('/wp-json/') !== -1 && typeof data === 'string' && /<html|<!doctype/i.test(data.slice(0, 500))) {
        usePlain = true;
        return call(root, endpoint, body);
      }
      if (!res.ok) throw new D.api.Error((data && data.message) || (host + ' responded ' + res.status + '.'), res.status, data);
      return data;
    }
    var roots = [base + '/wp-json/disembark/v1/', base + '/?rest_route=/disembark/v1/'], lastError = null;
    for (var i = 0; i < roots.length; i++) {
      try {
        var pre = await call(roots[i], 'import/preflight', { import_id: D.randomHex(24) });
        if (pre && typeof pre === 'object' && 'db_prefix' in pre) {
          var root = roots[i];
          return {
            root: root, preflight: pre,
            post: function (endpoint, body) { return call(root, endpoint, body); },
            upload: function () { throw new Error('Uploads are not used when pushing.'); }
          };
        }
      } catch (e) {
        lastError = e;
        if (e.status === 403) throw new D.api.Error(host + ' rejected the token. Copy the connect line again from its Tools → Disembark → Settings.', 403);
        // A site on plain permalinks answers /wp-json/ with its front page,
        // which carries no CORS headers, so the browser reports it as
        // unreachable: try the ?rest_route= form before giving up.
      }
    }
    throw lastError || new Error('Disembark doesn’t seem to be active on ' + host + '.');
  };

  function mount(root) {
    var st = { site: '', token: '', quiet: false };
    var view = h('div');
    D.mount(root,
      h('div', { class: 'dsk-head' }, h('div', null, h('h1', null, 'Push'),
        h('p', null, 'Send this site to another site running Disembark: staging to live, or onto a new host. The destination pulls everything straight from here and saves a rollback point first.'))),
      view);
    renderForm();

    function renderForm() {
      var btn = h('button', { class: 'dsk-btn primary lg', disabled: true, onclick: start }, D.icon('upload'), 'Push to the destination');
      function sync() { btn.disabled = !(st.site && st.token); }
      var fields = D.connectFields(st, sync, {
        site: 'Destination site', placeholder: 'https://new-site.com',
        hint: 'On the destination, open Tools → Disembark → Settings and copy its connect line. Paste it into either field to fill both.'
      });
      sync();
      D.mount(view, h('div', { class: 'dsk-grid two', style: { alignItems: 'start' } },
        h('div', { class: 'dsk-card dsk-card-pad dsk-stack' },
          h('div', { class: 'dsk-row' }, h('span', { class: 'dsk-badge accent' }, D.host(D.config.homeUrl)), D.icon('chevron'), h('span', { class: 'dsk-badge' }, st.site ? D.host(st.site) : 'destination')),
          fields, D.quietToggle(st, 'Keep the destination quiet', 'Turn this on when the destination is a staging or dev copy: its emails are held, scheduled jobs paused, checkout closed, and payment and marketing APIs blocked. Leave it off when pushing to a live site.'), h('div', null, btn)),
        h('div', { class: 'dsk-stack' },
          D.note('warn', [h('strong', null, 'This replaces the destination.'), ' Its files and database are overwritten with this site, including its user accounts: afterwards you log in there with this site’s credentials.']),
          D.note('', ['The destination saves a rollback point before anything changes, so the push can be undone from here, from its own dashboard, or with the CLI.'], 'anchor'),
          D.note('', ['The destination downloads from ', h('code', null, D.host(D.config.homeUrl)), ', so this site has to be reachable from it (not a local-only address).'], 'globe'),
          sharedNote())));
    }

    // One site of a network or WP Freighter install goes as a standalone
    // site. A Freighter tenant without its own domain answers only at the
    // main site's address, which takes the main site's token.
    function sharedNote() {
      var inst = D.config.install;
      if (!inst) return null;
      var kind = inst.kind === 'multisite' ? 'network' : 'WP Freighter install';
      if (inst.current === inst.main) return D.note('', ['This is the main site of a ' + kind + '. The destination asks whether to bring the whole ' + kind + ' or one of its sites.'], 'globe');
      if (inst.kind === 'freighter' && D.address(D.config.homeUrl) === D.address(inst.home)) return D.note('warn', ['This tenant has no domain of its own, so the destination can\u2019t reach it directly. Push it from the main site\u2019s Disembark instead and choose tenant ' + inst.current + '.'], 'alert');
      return D.note('', ['This site goes as a standalone site: its own tables, its users, its uploads, and the plugins and themes it uses. The rest of the ' + kind + ' stays here.'], 'globe');
    }

    async function start(e) {
      var btn = e.currentTarget, dest = D.host(st.site);
      var ok = await D.confirm(h('div', { class: 'dsk-stack', style: { gap: '10px' } },
        h('p', null, 'Replace ', h('strong', null, dest), ' with ', h('strong', null, D.host(D.config.homeUrl)), '?'),
        h('p', { class: 'muted' }, 'The destination saves a rollback point first.')), { title: 'Push this site?', confirmLabel: 'Push', danger: true });
      if (!ok) return;
      var api;
      try { api = await D.busy(btn, function () { return D.remoteApi(st.site, st.token); }); }
      catch (err) { D.toast(D.errorText(err), 'error'); return; }
      if (!api) return;
      D.runMove(view, {
        api: api, mode: 'site', site: D.config.homeUrl, token: S.token, quiet: st.quiet,
        targetHome: api.preflight.home_url || st.site, label: 'Before push from ' + D.host(D.config.homeUrl),
        title: 'Pushing', doneTitle: 'Pushed', againLabel: 'Start another push',
        back: renderForm,
        done: function (outcome, ctx) {
          var target = api.preflight.home_url || st.site;
          D.mount(outcome, h('div', { class: 'dsk-card dsk-card-pad dsk-stack', style: { gap: '14px' } },
            h('div', { class: 'dsk-row' }, D.icon('checkCircle'), h('h2', { style: { fontSize: '30px' } }, 'Delivered.')),
            h('p', null, D.host(target) + ' now holds a copy of this site.'),
            D.note('', ['Its user accounts are now this site’s, so log in there with your credentials from ', h('strong', null, D.host(D.config.homeUrl)), '.']),
            h('div', { class: 'dsk-row' },
              h('a', { class: 'dsk-btn primary', href: target, target: '_blank', rel: 'noopener' }, 'Open ' + D.host(target)),
              h('button', { class: 'dsk-btn', onclick: function (ev) { ctx.rollback(ev.currentTarget); } }, D.icon('restore'), 'Undo push')),
            h('div', null, h('div', { class: 'caps', style: { marginBottom: '6px' } }, 'Undo from the CLI'), ctx.cliUndo())));
        }
      });
    }
  }
})();
