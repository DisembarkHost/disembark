/**
 * Settings: quiet mode, connect the CLI, manage the connection token, clean up
 * working files, and pick light or dark.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h, S = D.store.state;

  D.section({ id: 'settings', title: 'Settings', icon: 'settings', mount: mount, onShow: function () { D.loadStatus(); } });

  function mount(root) {
    var home = D.config.homeUrl;
    var connect = D.cmd('', { label: 'Connect', hint: 'Links the CLI on your machine (or another site) to this site.' });
    // A ready-to-paste prompt for an AI coding agent (Claude Code, Cursor,
    // Codex…): the connect line plus the instructions page it should read.
    var agent = D.cmd('', { plain: true });
    var tokenText = h('code', { class: 'mono', style: { fontSize: '13px', padding: '6px 10px', wordBreak: 'break-all' } });
    var shown = false;
    function syncToken() {
      connect.querySelector('pre').textContent = 'disembark connect ' + home + ' ' + S.token;
      agent.set('Move the WordPress site ' + home + ' to my new host with Disembark. Instructions: https://disembark.host/disembark.md\nSource connect line: disembark connect ' + home + ' ' + S.token);
      tokenText.textContent = shown ? S.token : S.token.slice(0, 4) + '•'.repeat(Math.max(8, S.token.length - 8)) + S.token.slice(-4);
    }
    D.store.on(['token'], syncToken);
    syncToken();

    var storage = h('div');
    function renderStorage() {
      var ls = S.lastScan;
      D.mount(storage,
        h('div', { class: 'dsk-grid two' },
          h('div', { class: 'dsk-stat' }, h('div', { class: 'caps' }, 'Working files'), h('div', { class: 'v' }, D.fmt.size(S.diskSize)), h('div', { class: 's' }, 'Scans, exports and zips in uploads/disembark')),
          h('div', { class: 'dsk-stat' }, h('div', { class: 'caps' }, 'Last scan'), h('div', { class: 'v' }, ls ? D.fmt.ago(ls.timestamp) : 'Never'), h('div', { class: 's' }, ls ? D.fmt.plural(ls.total_files, 'file') + ' · ' + D.fmt.size(ls.total_size) : 'Run one from Back up'))));
    }
    D.store.on(['diskSize', 'lastScan'], renderStorage);
    renderStorage();

    var withPoints = D.toggle('Also delete restore points', false, function () {});
    var themeSeg = D.seg([{ value: 'system', label: 'System' }, { value: 'light', label: 'Light', icon: 'sun' }, { value: 'dark', label: 'Dark', icon: 'moon' }], D.themePref(), function (v) { D.setTheme(v); });

    D.mount(root,
      h('div', { class: 'dsk-head' }, h('div', null, h('h1', null, 'Settings'), h('p', null, 'Connect the CLI, manage access, and tidy up.'))),
      h('div', { class: 'dsk-grid two', style: { alignItems: 'start' } },
        h('div', { class: 'dsk-stack' },
          h('div', { class: 'dsk-card' },
            h('div', { class: 'dsk-card-head' }, h('h3', null, D.icon('terminal'), 'Disembark CLI')),
            h('div', { class: 'dsk-card-pad dsk-stack', style: { gap: '16px' } },
              D.cmd('wget https://github.com/DisembarkHost/disembark-cli/releases/latest/download/disembark.phar\nchmod +x disembark.phar\nsudo mv disembark.phar /usr/local/bin/disembark', { label: 'Install', hint: 'Once per machine. Needs PHP.' }),
              connect,
              D.note('', ['Prefer WordPress accounts? Connect with an application password instead of the token: ', h('code', null, 'disembark connect ' + home + ' "user:app password"')]))),
          h('div', { class: 'dsk-card' },
            h('div', { class: 'dsk-card-head' }, h('h3', null, D.icon('sparkle'), 'Hand it to an AI agent')),
            h('div', { class: 'dsk-card-pad dsk-stack', style: { gap: '14px' } },
              h('p', { class: 'muted' }, 'Using Claude Code, Cursor or Codex with SSH to your new host? Paste this and the agent runs the move with WP-CLI: install, pull, check, clean up.'),
              agent,
              D.note('warn', ['The prompt includes this site’s token. Regenerate it below once the move is done.']))),
          h('div', { class: 'dsk-card' },
            h('div', { class: 'dsk-card-head' }, h('h3', null, D.icon('key'), 'Connection token')),
            h('div', { class: 'dsk-card-pad dsk-stack', style: { gap: '14px' } },
              h('p', { class: 'muted' }, 'Anyone with this token has full access to this site’s files and database. It’s also what another site needs to pull this one.'),
              h('div', { class: 'dsk-row' }, tokenText,
                h('button', { class: 'dsk-btn sm', onclick: function (e) { shown = !shown; e.currentTarget.lastChild.textContent = shown ? 'Hide' : 'Show'; syncToken(); } }, h('span', null, 'Show')),
                h('button', { class: 'dsk-btn sm', onclick: function () { D.copy(S.token); } }, D.icon('copy'), 'Copy')),
              h('div', null, h('button', { class: 'dsk-btn danger sm', onclick: regenerate }, D.icon('refresh'), 'Regenerate token'))))),
        h('div', { class: 'dsk-stack' },
          quietCard(),
          h('div', { class: 'dsk-card' },
            h('div', { class: 'dsk-card-head' }, h('h3', null, D.icon('trash'), 'Storage')),
            h('div', { class: 'dsk-card-pad dsk-stack', style: { gap: '14px' } },
              storage,
              h('p', { class: 'muted' }, 'Cleaning up removes scans, exports and folder zips. Restore points are kept unless you include them below.'),
              withPoints,
              h('div', null, h('button', { class: 'dsk-btn', onclick: cleanup }, D.icon('trash'), 'Clean up working files')))),
          h('div', { class: 'dsk-card' },
            h('div', { class: 'dsk-card-head' }, h('h3', null, D.icon('sun'), 'Appearance')),
            h('div', { class: 'dsk-card-pad' }, themeSeg)),
          h('div', { class: 'dsk-card dsk-card-pad dsk-row', style: { justifyContent: 'space-between' } },
            h('div', null, h('div', { style: { fontFamily: 'var(--serif)', fontSize: '24px' } }, 'Disembark ' + D.config.version), h('div', { class: 'muted' }, 'MIT licensed · free forever')),
            h('div', { class: 'dsk-row' },
              h('a', { class: 'dsk-btn sm', href: 'https://github.com/DisembarkHost/disembark/blob/main/changelog.md', target: '_blank', rel: 'noopener' }, 'Changelog'),
              h('a', { class: 'dsk-btn sm', href: 'https://github.com/DisembarkHost/disembark', target: '_blank', rel: 'noopener' }, 'GitHub'))))));

    /**
     * Quiet mode: what it's holding back, and the switch. A restore from
     * another address turns it on; it can be turned on by hand for any copy.
     */
    function quietCard() {
      var body = h('div', { class: 'dsk-card-pad dsk-stack', style: { gap: '14px' } });
      var badge = h('span');
      var card = h('div', { class: 'dsk-card' },
        h('div', { class: 'dsk-card-head' }, h('h3', null, D.icon('moon'), 'Quiet mode'), h('span', { class: 'grow' }), badge),
        body);
      var held = h('div');

      async function load() {
        var q;
        try { q = await D.api.get('quiet-mode'); }
        catch (e) { D.mount(body, D.note('error', 'Could not read quiet mode: ' + D.errorText(e))); return; }
        render(q);
      }
      function render(q) {
        D.mount(badge, q.on ? h('span', { class: 'dsk-badge ' + (q.active ? 'accent' : '') }, q.active ? 'on' : 'standing down') : h('span', { class: 'dsk-badge' }, 'off'));
        if (!q.on) {
          D.mount(body,
            h('p', { class: 'muted' }, 'For a staging or dev copy of a live site: holds every email, pauses WP-Cron and Action Scheduler, closes WooCommerce checkout and its webhooks, and blocks payment, marketing, shipping-label and email APIs, so the copy can\u2019t reach real customers. A restore from another site turns it on by itself.'),
            h('div', null, h('button', { class: 'dsk-btn', onclick: function (e) { toggle(e.currentTarget, 'on'); } }, D.icon('moon'), 'Turn on quiet mode')));
          return;
        }
        var parts = [];
        if (!q.active) {
          parts.push(D.note('', ['It was turned on for ', h('strong', null, D.host(q.home)), ' and this site is now ', h('strong', null, D.host(D.config.homeUrl)), ', so it\u2019s standing down: emails, jobs and checkout run normally. Turn it off to remove it.'], 'info'));
        } else {
          parts.push(h('p', null, D.host(D.config.homeUrl) + ' is ' + (q.source ? 'a copy of ' + D.host(q.source) : 'a copy') + (q.since ? ', quiet since ' + D.fmt.date(q.since) : '') + '. Emails are held, scheduled jobs paused, checkout closed, and payment, marketing and shipping APIs blocked.'));
        }
        parts.push(h('div', { class: 'dsk-grid two' },
          h('div', { class: 'dsk-stat' }, h('div', { class: 'caps' }, 'Emails held'), h('div', { class: 'v' }, D.fmt.num(q.held_total || 0)), h('div', { class: 's' }, 'Never sent; the newest 50 are kept')),
          h('div', { class: 'dsk-stat' }, h('div', { class: 'caps' }, 'API calls blocked'), h('div', { class: 'v' }, D.fmt.num((q.blocked || []).reduce(function (n, b) { return n + b.count; }, 0))),
            h('div', { class: 's' }, (q.blocked || []).length ? q.blocked.map(function (b) { return b.label; }).filter(function (v, i, a) { return a.indexOf(v) === i; }).join(', ') : 'None so far'))));
        if (q.held && q.held.length) {
          parts.push(h('ul', { class: 'dsk-list', style: { border: '1px solid var(--line)', borderRadius: '10px', maxHeight: '280px', overflow: 'auto' } }, q.held.map(function (m) {
            return h('li', { style: { cursor: 'pointer' }, tabindex: '0', role: 'button', onclick: function () { open(m); }, onkeydown: function (e) { if (e.key === 'Enter') open(m); } },
              h('div', { class: 'grow', style: { minWidth: 0 } },
                h('div', { style: { fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, m.subject || '(no subject)'),
                h('div', { class: 'muted', style: { fontSize: '12.5px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, 'To ' + m.to + ' \u00b7 ' + D.fmt.ago(m.time))));
          })));
        }
        parts.push(h('div', { class: 'dsk-row' },
          h('button', { class: 'dsk-btn' + (q.active ? ' danger' : ''), onclick: function (e) { toggle(e.currentTarget, 'off'); } }, D.icon('sun'), 'Turn off quiet mode'),
          h('button', { class: 'dsk-btn ghost sm', onclick: function (e) { D.busy(e.currentTarget, load); } }, D.icon('refresh'), 'Refresh')));
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
        if (isHtml) view.srcdoc = full.message;
        D.modal({ title: full.subject || '(no subject)', wide: true, body: h('div', { class: 'dsk-stack', style: { gap: '10px' } },
          h('div', { class: 'muted', style: { fontSize: '13px' } }, 'To ' + full.to + ' \u00b7 held ' + D.fmt.date(full.time)), view) });
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
      load();
      return card;
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
          D.toast('Working files cleaned up.');
        } catch (err) { D.toast('Cleanup failed: ' + D.errorText(err), 'error'); }
      });
    }
  }
})();
