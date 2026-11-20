/**
 * Push: send this site to another site running Disembark. The browser drives
 * the destination's import API directly (WordPress REST answers CORS), and the
 * destination pulls from this site server-to-server, so nothing passes through
 * the browser but instructions.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h, S = D.store.state;

  /**
   * A client for another site's Disembark API, authenticated with its token.
   * Probes pretty permalinks first, then ?rest_route= (plain permalinks).
   */
  D.remoteApi = async function (siteUrl, token) {
    var base = String(siteUrl).replace(/\/+$/, '');
    var host = D.host(base);
    var usePlain = false;
    async function call(root, endpoint, body, o) {
      if (usePlain) root = base + '/?rest_route=/disembark/v1/';
      var res, timer = null, ctl = (o && o.timeout && window.AbortController) ? new AbortController() : null;
      if (ctl) timer = setTimeout(function () { ctl.abort(); }, o.timeout);
      try {
        res = await fetch(root + endpoint, {
          method: 'POST', mode: 'cors', credentials: 'omit',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(Object.assign({ token: token }, body || {})),
          signal: ctl ? ctl.signal : undefined
        });
      } catch (e) {
        if (ctl && ctl.signal.aborted) { var te = new D.api.Error('No answer from ' + host + ' in time.', 0); te.timeout = true; throw te; }
        throw new D.api.Error('Couldn’t reach ' + host + ' from this browser. Its server may block cross-site requests, or one site is http and the other https. You can pull from the destination instead: on ' + host + ', open Tools → Disembark → Pull, and paste this site’s connect line.', 0);
      } finally { if (timer) clearTimeout(timer); }
      var text = await res.text(), data = text;
      try { data = text === '' ? null : JSON.parse(text); } catch (e) { /* not JSON */ }
      // The destination took a database with plain permalinks mid-push, so
      // /wp-json/ now answers with a page: carry on through ?rest_route=.
      if (res.ok && root.indexOf('/wp-json/') !== -1 && typeof data === 'string' && /<html|<!doctype/i.test(data.slice(0, 500))) {
        usePlain = true;
        return call(root, endpoint, body, o);
      }
      if (!res.ok) {
        var fail = new D.api.Error((data && data.message) || (host + ' responded ' + res.status + '.'), res.status, data);
        fail.retryAfter = parseInt(res.headers.get('Retry-After') || '', 10) || 0;
        throw fail;
      }
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
            post: function (endpoint, body, o) { return call(root, endpoint, body, o); },
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

  /**
   * Pushes this site to the destination in st ({ site, token, quiet, scene }),
   * rendering into view. Resolves false when the destination can't be
   * reached (the form stays). back() goes back to the Move form.
   */
  D.pushRun = async function (view, st, btn, back) {
    var api;
    try { api = await D.busy(btn, function () { return D.remoteApi(st.site, st.token); }); }
    catch (err) { D.toast(D.errorText(err), 'error'); return false; }
    if (!api) return false;
    D.runMove(view, {
      api: api, mode: 'site', site: D.config.homeUrl, token: S.token, quiet: st.quiet, scene: st.scene, kind: 'push',
      targetHome: api.preflight.home_url || st.site, label: 'Before push from ' + D.host(D.config.homeUrl),
      title: 'Pushing to', doneTitle: 'Delivered', againLabel: 'Start another move', icon: 'ship',
      back: back,
      done: function (outcome, ctx) {
        var target = api.preflight.home_url || st.site, href = D.safeHref(target);
        // Into a new or replaced subsite or tenant there: that site, not the whole destination.
        if (ctx.target) {
          var tg = ctx.target, net = tg.kind === 'multisite', own = D.address(tg.home) !== D.address(target), open = own ? D.safeHref(tg.home) : '';
          D.mount(outcome,
            h('h3', null, D.icon('checkCircle'), (net ? 'Subsite ' : 'Tenant ') + tg.id + ' is ready'),
            h('p', null, D.host(tg.home) + ' now holds a copy of this site. The rest of ' + D.host(target) + ' is as it was.'),
            h('p', { style: { marginTop: '8px' } }, net ? 'This site\u2019s users joined the network there: they log in with their own passwords, and an account that was already there keeps its own.'
              : 'It has its own users: log in to it with your credentials from ' + D.host(D.config.homeUrl) + (own ? '.' : ', from Tools \u2192 WP Freighter there.')),
            h('div', { class: 'row' },
              open ? h('a', { class: 'dsk-btn primary', href: open, target: '_blank', rel: 'noopener' }, 'Open ' + D.host(tg.home)) : null,
              h('button', { class: 'dsk-btn', type: 'button', onclick: function (ev) { ctx.rollback(ev.currentTarget); } }, D.icon('restore'), 'Undo push')),
            ctx.undoDetails());
          return;
        }
        D.mount(outcome,
          h('h3', null, D.icon('key'), 'Log in there with this site’s details'),
          h('p', null, D.host(target) + ' now holds a copy of this site, user accounts included, so log in there with your credentials from ', h('strong', null, D.host(D.config.homeUrl)), '.'),
          h('div', { class: 'row' },
            href ? h('a', { class: 'dsk-btn primary', href: href, target: '_blank', rel: 'noopener' }, 'Open ' + D.host(target)) : null,
            h('button', { class: 'dsk-btn', type: 'button', onclick: function (ev) { ctx.rollback(ev.currentTarget); } }, D.icon('restore'), 'Undo push')),
          ctx.undoDetails());
      }
    });
    return true;
  };

  /**
   * One site of a network or WP Freighter install goes as a standalone site.
   * A Freighter tenant without its own domain answers only at the main
   * site's address, which takes the main site's token. Returns a line for
   * the Move form's notes, or ''.
   */
  D.pushScopeNote = function () {
    var inst = D.config.install;
    if (!inst) return '';
    var kind = inst.kind === 'multisite' ? 'network' : 'WP Freighter install';
    if (inst.current === inst.main) return 'This is the main site of a ' + kind + '. The destination asks whether to bring the whole ' + kind + ' or one of its sites.';
    if (inst.kind === 'freighter' && D.address(D.config.homeUrl) === D.address(inst.home)) return 'This tenant has no domain of its own, so the destination can’t reach it directly. Push it from the main site’s Disembark instead and choose tenant ' + inst.current + '.';
    return 'This site goes as a standalone site: its own tables, its users, its uploads, and the plugins and themes it uses. The rest of the ' + kind + ' stays here.';
  };
})();
