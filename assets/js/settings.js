/**
 * Settings: connect the CLI, manage the connection token, clean up working
 * files, and pick light or dark.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h, S = D.store.state;

  D.section({ id: 'settings', title: 'Settings', icon: 'settings', mount: mount, onShow: function () { D.loadStatus(); } });

  function mount(root) {
    var home = D.config.homeUrl;
    var connect = D.cmd('', { label: 'Connect', hint: 'Links the CLI on your machine (or another site) to this site.' });
    var tokenText = h('code', { class: 'mono', style: { fontSize: '13px', padding: '6px 10px', wordBreak: 'break-all' } });
    var shown = false;
    function syncToken() {
      connect.querySelector('pre').textContent = 'disembark connect ' + home + ' ' + S.token;
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
            h('div', { class: 'dsk-card-head' }, h('h3', null, D.icon('key'), 'Connection token')),
            h('div', { class: 'dsk-card-pad dsk-stack', style: { gap: '14px' } },
              h('p', { class: 'muted' }, 'Anyone with this token has full access to this site’s files and database. It’s also what another site needs to pull this one.'),
              h('div', { class: 'dsk-row' }, tokenText,
                h('button', { class: 'dsk-btn sm', onclick: function (e) { shown = !shown; e.currentTarget.lastChild.textContent = shown ? 'Hide' : 'Show'; syncToken(); } }, h('span', null, 'Show')),
                h('button', { class: 'dsk-btn sm', onclick: function () { D.copy(S.token); } }, D.icon('copy'), 'Copy')),
              h('div', null, h('button', { class: 'dsk-btn danger sm', onclick: regenerate }, D.icon('refresh'), 'Regenerate token'))))),
        h('div', { class: 'dsk-stack' },
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
