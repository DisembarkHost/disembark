/**
 * Settings, one row each: the connect line and its token, a prompt for an AI
 * agent, the Disembark CLI, quiet mode, storage clean up, and light or dark.
 * The token is masked on screen until shown, but every Copy includes it.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h, S = D.store.state;
  var syncTheme = null;

  D.section({ id: 'settings', title: 'Settings', icon: 'settings', tab: 4, mount: mount, onShow: function () { D.loadStatus(); if (syncTheme) syncTheme(); } });

  function mount(root) {
    var home = D.config.homeUrl, shown = false;
    var connectLine = function () { return 'disembark connect ' + home + ' ' + S.token; };
    var agentText = function (tok) { return 'Move the WordPress site ' + home + ' to my new host with Disembark. Instructions: https://disembark.host/disembark.md\nSource connect line: disembark connect ' + home + ' ' + tok; };

    /** The token as shown on screen: the first and last four, or all of it. */
    function tokenEl() {
      var t = S.token || '';
      if (shown) return document.createTextNode(t);
      return h('span', null, t.slice(0, 4), h('span', { class: 'mask' }, '••••••••••••'), t.slice(-4));
    }
    /** A terminal line that shows the masked token but copies the real one. */
    function secretCmd(render, copyText, plain) {
      var pre = h('pre');
      var el = h('div', { class: 'dsk-cmd' + (plain ? ' plain' : '') }, pre,
        h('button', { type: 'button', class: 'dsk-btn', onclick: function () { D.copy(copyText()); } }, 'copy'));
      el.update = function () { D.mount(pre, render()); };
      return el;
    }

    var tokline = h('div', { class: 'dsk-tokline' });
    var agent = secretCmd(function () { return [agentText(''), tokenEl()]; }, function () { return agentText(S.token); }, true);
    var connectCmd = secretCmd(function () { return ['disembark connect ' + home + ' ', tokenEl()]; }, connectLine);
    var agentNote = h('span');
    var revealBtn = h('button', { class: 'dsk-btn sm', type: 'button', onclick: function () { shown = !shown; syncToken(); } }, h('span', null, 'Show token'));
    function syncToken() {
      D.mount(tokline, 'disembark connect ' + home + ' ', tokenEl());
      agent.update(); connectCmd.update();
      revealBtn.lastChild.textContent = shown ? 'Hide token' : 'Show token';
      revealBtn.setAttribute('aria-pressed', String(shown));
      agentNote.textContent = (shown ? 'The token is showing.' : 'The token is hidden here but included when you copy.') + ' Regenerate it once the move is done.';
    }
    D.store.on(['token'], syncToken);
    syncToken();

    /* Storage */
    var stats = h('div', { class: 'row' }), pts = { n: null, bytes: 0 };
    function renderStorage() {
      var ls = S.lastScan;
      D.mount(stats,
        stat('Working files', D.fmt.size(S.diskSize), 'Scans, exports and zips'),
        stat('Restore points', pts.n === null ? '…' : D.fmt.size(pts.bytes), pts.n === null ? '' : pts.n ? D.fmt.plural(pts.n, 'point') + ' kept' : 'None kept'),
        stat('Last scan', ls ? D.fmt.ago(ls.timestamp) : 'Never', ls ? D.fmt.plural(ls.total_files, 'file') + ' · ' + D.fmt.size(ls.total_size) : 'Run one from Backups'));
    }
    function stat(label, v, sub) { return h('div', { class: 'dsk-set-stat' }, h('div', { class: 'caps' }, label), h('div', { class: 'v' }, v), h('small', null, sub)); }
    async function loadPoints() {
      try { var list = await D.api.get('restore-points'); pts = { n: list.length, bytes: list.reduce(function (n, p) { return n + (p.size || 0); }, 0) }; }
      catch (e) { pts = { n: 0, bytes: 0 }; }
      renderStorage();
    }
    D.store.on(['diskSize', 'lastScan'], renderStorage);
    renderStorage();
    loadPoints();
    var withPoints = D.toggle('Also delete restore points', false, function () {});

    /* Appearance */
    var themeBox = h('div');
    syncTheme = function () {
      D.mount(themeBox, D.seg([{ value: 'system', label: 'System' }, { value: 'light', label: 'Light', icon: 'sun' }, { value: 'dark', label: 'Dark', icon: 'moon' }], D.themePref(), function (v) { D.setTheme(v); }));
    };
    syncTheme();
    // The header's sun/moon button changes the theme too: keep the switch in step.
    var appRoot = document.getElementById('disembark-app');
    if (appRoot && window.MutationObserver) new MutationObserver(function () { syncTheme(); }).observe(appRoot, { attributes: true, attributeFilter: ['data-theme'] });

    function row(icon, title, text, ctl, extra) {
      return h('section', { class: 'dsk-card dsk-sg' },
        h('div', { class: 'about' }, h('h3', null, D.icon(icon), title, extra || null), text ? h('p', null, text) : null),
        h('div', { class: 'ctl' }, ctl));
    }

    var quiet = quietRow();
    D.mount(root,
      D.pageHead({ caps: 'Settings', icon: 'settings', title: ['Keys, tools ', '& tidying.'], text: 'Connect the CLI, hand a move to an AI agent, and clean up working files.' }),
      h('div', { class: 'dsk-set' },
        row('key', 'Connect line', 'Paste it into Disembark on another site to pull from or push to this one. Anyone with it has full access to this site’s files and database.', [
          tokline,
          h('div', { class: 'row' },
            h('button', { class: 'dsk-btn sm', type: 'button', onclick: function () { D.copy(connectLine()); } }, D.icon('copy'), 'Copy'),
            h('button', { class: 'dsk-btn ghost sm', type: 'button', title: 'Copy the token alone', onclick: function () { D.copy(S.token); } }, 'Token only'),
            revealBtn,
            h('span', { class: 'grow' }),
            h('button', { class: 'dsk-btn danger sm', type: 'button', onclick: regenerate }, D.icon('refresh'), 'Regenerate'))]),
        row('sparkle', 'Hand it to an AI agent', 'Using Claude Code, Cursor or Codex with SSH to the new host? Paste this and the agent runs the move with WP-CLI: install, pull, check, clean up.', [
          agent,
          h('div', { class: 'dsk-set-note' }, D.icon('alert'), agentNote)]),
        row('terminal', 'Disembark CLI', 'Back up, sync and move from your own machine. Install once (needs PHP), then connect it to this site.', [
          D.cmd('wget https://github.com/DisembarkHost/disembark-cli/releases/latest/download/disembark.phar\nchmod +x disembark.phar && sudo mv disembark.phar /usr/local/bin/disembark'),
          connectCmd,
          h('p', { class: 'dsk-hint', style: { margin: 0 } }, 'Prefer WordPress accounts? Use an application password instead of the token: ', h('code', null, 'disembark connect ' + home + ' "user:app password"'))]),
        quiet.el,
        row('folder', 'Storage', 'Scans, exports and zips live in uploads/disembark. Restore points are kept unless you include them.', [
          stats,
          h('div', { class: 'row' }, withPoints, h('span', { class: 'grow' }), h('button', { class: 'dsk-btn sm', type: 'button', onclick: cleanup }, D.icon('trash'), 'Clean up working files'))]),
        row('sun', 'Appearance', 'Follows your system unless you choose.', themeBox),
        h('section', { class: 'dsk-card dsk-foot-about' },
          h('h3', null, 'Disembark ' + D.config.version),
          h('span', { class: 'muted' }, 'MIT licensed · free forever'),
          h('span', { class: 'r' },
            h('a', { class: 'dsk-btn sm', href: 'https://github.com/DisembarkHost/disembark/blob/main/changelog.md', target: '_blank', rel: 'noopener' }, 'Changelog'),
            h('a', { class: 'dsk-btn sm', href: 'https://github.com/DisembarkHost/disembark', target: '_blank', rel: 'noopener' }, 'GitHub')))));

    /**
     * Quiet mode: what it's holding back, and the switch. A restore from
     * another address turns it on; it can be turned on by hand for any copy.
     */
    function quietRow() {
      var tag = h('span'), body = h('div', { class: 'ctl' });
      var el = h('section', { class: 'dsk-card dsk-sg' },
        h('div', { class: 'about' }, h('h3', null, D.icon('moon'), 'Quiet mode', tag), h('p', null, 'For a staging or dev copy of a live site.')),
        body);
      function spotRow(text) {
        return h('div', { class: 'dsk-set-quiet' }, h('div', { class: 'qspot', html: D.H.spot('quiet') }), h('div', null, text));
      }
      async function load() {
        var q;
        try { q = await D.api.get('quiet-mode'); }
        catch (e) { D.mount(body, D.note('error', 'Could not read quiet mode: ' + D.errorText(e))); return; }
        render(q);
      }
      function render(q) {
        D.mount(tag, q.on ? h('span', { class: 'dsk-tag ' + (q.active ? 'ok' : 'warn') }, q.active ? 'on' : 'standing down') : h('span', { class: 'dsk-tag' }, 'off'));
        if (!q.on) {
          D.mount(body,
            spotRow(h('p', null, 'Holds every email, pauses WP-Cron and Action Scheduler, closes WooCommerce checkout and its webhooks, and blocks payment, marketing, shipping-label and email APIs, so the copy can’t reach real customers. A restore or pull from another site turns it on by itself.')),
            h('div', { class: 'row' }, h('button', { class: 'dsk-btn sm', type: 'button', onclick: function (e) { toggle(e.currentTarget, 'on'); } }, D.icon('moon'), 'Turn on quiet mode')));
          return;
        }
        var parts = [];
        if (!q.active) {
          parts.push(spotRow(D.note('', ['It was turned on for ', h('strong', null, D.host(q.home)), ' and this site is now ', h('strong', null, D.host(D.config.homeUrl)), ', so it’s standing down: emails, jobs and checkout run normally. Turn it off to remove it.'], 'info')));
        } else {
          parts.push(spotRow(h('p', null, D.host(D.config.homeUrl) + ' is ' + (q.source ? 'a copy of ' + D.host(q.source) : 'a copy') + (q.since ? ', quiet since ' + D.fmt.date(q.since) : '') + '. Emails are held, scheduled jobs paused, checkout closed, and payment, marketing and shipping APIs blocked.')));
        }
        var blocked = (q.blocked || []).reduce(function (n, b) { return n + b.count; }, 0);
        parts.push(h('div', { class: 'row' },
          stat('Emails held', D.fmt.num(q.held_total || 0), 'Never sent; the newest 50 are kept'),
          stat('API calls blocked', D.fmt.num(blocked), (q.blocked || []).length ? q.blocked.map(function (b) { return b.label; }).filter(function (v, i, a) { return a.indexOf(v) === i; }).join(', ') : 'None so far')));
        if (q.held && q.held.length) {
          parts.push(h('ul', { class: 'dsk-list dsk-set-held' }, q.held.map(function (m) {
            return h('li', { tabindex: '0', role: 'button', onclick: function () { open(m); }, onkeydown: function (e) { if (e.key === 'Enter') open(m); } },
              D.icon('bell'),
              h('div', { class: 'grow' },
                h('div', { class: 'sub' }, m.subject || '(no subject)'),
                h('div', { class: 'to' }, 'To ' + m.to + ' · ' + D.fmt.ago(m.time))));
          })));
        }
        parts.push(h('div', { class: 'row' },
          h('button', { class: 'dsk-btn sm' + (q.active ? ' danger' : ''), type: 'button', onclick: function (e) { toggle(e.currentTarget, 'off'); } }, D.icon('sun'), 'Turn off quiet mode'),
          h('button', { class: 'dsk-btn ghost sm', type: 'button', onclick: function (e) { D.busy(e.currentTarget, load); } }, D.icon('refresh'), 'Refresh')));
        D.mount(body, parts);
      }
      async function open(m) {
        var full;
        try { full = await D.api.get('quiet-mode', { message: m.index }); }
        catch (e) { D.toast(D.errorText(e), 'error'); return; }
        var isHtml = /<\/?(html|body|p|div|table|br)\b/i.test(full.message || '');
        var view = isHtml
          ? h('iframe', { sandbox: '', style: { width: '100%', height: '420px', border: '1px solid var(--line)', borderRadius: '8px', background: '#fff' } })
          : h('pre', { class: 'mono', style: { whiteSpace: 'pre-wrap', maxHeight: '420px', overflow: 'auto', fontSize: '12.5px' } }, full.message || '');
        // The sandbox stops scripts; the CSP stops remote images and other
        // loads (tracking pixels would tell the sender the email was opened).
        if (isHtml) view.srcdoc = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; font-src data:">' + full.message;
        D.modal({ title: full.subject || '(no subject)', wide: true, body: h('div', { class: 'dsk-stack', style: { gap: '10px' } },
          h('div', { class: 'muted', style: { fontSize: '13px' } }, 'To ' + full.to + ' · held ' + D.fmt.date(full.time)), view) });
      }
      async function toggle(btn, action) {
        if (action === 'off') {
          var ok = await D.confirm('Emails send again, scheduled jobs resume (overdue ones run right away), checkout opens and API calls go through. Held emails are discarded.', { title: 'Turn off quiet mode?', confirmLabel: 'Turn off', danger: true });
          if (!ok) return;
        }
        await D.busy(btn, async function () {
          try { render(await D.api.post('quiet-mode', { action: action })); load(); D.toast(action === 'on' ? 'Quiet mode is on.' : 'Quiet mode is off.'); }
          catch (e) { D.toast('Could not change quiet mode: ' + D.errorText(e), 'error'); }
        });
      }
      D.mount(body, h('div', { class: 'muted' }, h('span', { class: 'dsk-spin' })));
      load();
      return { el: el, load: load };
    }

    async function regenerate(e) {
      var btn = e.currentTarget;
      var ok = await D.confirm('Every CLI connection and saved command using the current token stops working, and sites that pull from this one need the new token.', { title: 'Regenerate the token?', confirmLabel: 'Regenerate', danger: true });
      if (!ok) return;
      await D.busy(btn, async function () {
        try {
          var r = await D.api.post('regenerate-token', {});
          D.store.set({ token: r.token });
          D.toast('New token generated.');
        } catch (err) { D.toast('Could not regenerate the token: ' + D.errorText(err), 'error'); }
      });
    }

    async function cleanup(e) {
      var btn = e.currentTarget, all = withPoints.input.checked;
      var ok = await D.confirm(all ? 'This removes every scan, export and zip, and every restore point. Restores already done can no longer be rolled back.' : 'This removes every scan, export and zip. Restore points stay.', { title: 'Clean up working files?', confirmLabel: 'Clean up', danger: all });
      if (!ok) return;
      await D.busy(btn, async function () {
        try {
          await D.api.get('cleanup', all ? { include_restore_points: 1 } : {});
          D.store.set({ scanned: false, files: [], index: null, excluded: new Set(), backupToken: '' });
          await D.loadStatus();
          loadPoints();
          D.toast('Working files cleaned up.');
        } catch (err) { D.toast('Cleanup failed: ' + D.errorText(err), 'error'); }
      });
    }
  }
})();
