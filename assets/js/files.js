/**
 * Files: browse the site's files, preview and edit text, view images,
 * rename, and download single files or whole folders as a zip.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h, S = D.store.state;
  var IMG = /\.(png|jpe?g|gif|webp|bmp|ico|avif|svg)$/i;
  var MAX_PREVIEW = 512 * 1024;
  var LANG = { js: 'javascript', mjs: 'javascript', json: 'json', css: 'css', scss: 'css', php: 'php', html: 'markup', htm: 'markup', xml: 'markup', svg: 'markup',
    sql: 'sql', yml: 'yaml', yaml: 'yaml', sh: 'bash', ini: 'ini', htaccess: 'ini', conf: 'ini', md: 'markdown' };

  D.section({ id: 'files', title: 'Files', icon: 'folder', mount: mount });

  function mount(root) {
    var view = h('div');
    var vs = D.scope();
    var activeExplorer = null;
    var slot = D.browseTools ? D.browseTools('files') : h('div');
    var searchBox = h('div', { class: 'dsk-bw-search' });
    var rescanBtn = h('button', { class: 'dsk-btn', type: 'button', onclick: function () { scan(); } }, D.icon('refresh'), 'Rescan');
    D.mount(slot, searchBox, rescanBtn);
    D.mount(root, view);

    function render() {
      vs.dispose(); vs = D.scope();
      D.mount(searchBox);
      if (S.site) { rescanBtn.hidden = true; D.mount(view, D.oneSiteNote('Files')); return; }
      rescanBtn.hidden = !S.scanned;
      D.mount(view, S.scanned ? explorer() : h('div', { class: 'dsk-card dsk-bw-intro' },
        h('div', null,
          h('h2', null, 'No file list ', h('em', { class: 'hl' }, 'yet.')),
          h('p', null, 'Scan the site to browse its files: every folder with its size, so you can see what takes the space. It takes a moment on big sites.'),
          h('button', { class: 'dsk-btn primary', type: 'button', onclick: function () { scan(); } }, D.icon('scan'), 'Scan files'))));
    }
    D.store.on(['scanned', 'index', 'site'], render);
    render();

    async function scan() {
      var line = h('span', { class: 'muted mono' });
      rescanBtn.hidden = true; D.mount(searchBox);
      D.mount(view, h('div', { class: 'dsk-card dsk-card-pad' }, h('div', { class: 'dsk-row' }, h('span', { class: 'dsk-spin' }), h('strong', null, 'Scanning files'), line)));
      try {
        await D.scanSite(function (p) { line.textContent = p.phase === 'scanning' ? D.fmt.num(p.done) + ' of ' + D.fmt.num(p.total) + ' folders' : D.fmt.num(p.done) + ' of ' + D.fmt.num(p.total) + ' parts'; });
      } catch (e) { D.toast('The scan did not finish: ' + D.errorText(e), 'error'); render(); }
    }

    /* -------------------------------------------------------- Explorer */
    function explorer() {
      var detail = h('section');
      var results = h('div', { class: 'dsk-scroll-fill', hidden: true });
      var tree = D.Tree({ mode: 'browse', scope: vs, onSelect: show });
      var treeWrap = h('div', { class: 'dsk-scroll-fill', style: { padding: '6px 0' } }, tree.el);
      var count = h('div', { class: 'dsk-bw-aside-h' }, D.icon('folder'), h('b', null, 'All files'), h('span', { class: 'muted' }, D.fmt.size(S.index.root.size) + ' · ' + D.fmt.plural(S.files.length, 'file')));
      var search = D.search('Find a file or folder', function (q) {
        if (!q) { results.hidden = true; treeWrap.hidden = false; return; }
        var hits = [];
        for (var i = 0; i < S.files.length && hits.length < 300; i++) if (S.files[i].name.toLowerCase().indexOf(q) !== -1) hits.push(S.files[i]);
        D.mount(results, hits.length ? h('ul', { class: 'dsk-list' }, hits.map(function (f) {
          return h('li', { class: 'clickable', title: f.name, onclick: function () { tree.reveal(f.name); show(S.index.nodes.get(f.name)); search.input.value = ''; results.hidden = true; treeWrap.hidden = false; } },
            D.fileIcon({ name: f.name }), h('span', { class: 'grow mono', style: { fontSize: '12.5px' } }, f.name), h('span', { class: 'size' }, D.fmt.size(f.size)));
        }), hits.length === 300 ? h('li', { class: 'muted' }, 'Showing the first 300 matches.') : null) : h('div', { class: 'dsk-pad muted' }, 'No files match.'));
        results.hidden = false; treeWrap.hidden = true;
      });
      D.mount(searchBox, search);
      activeExplorer = { open: function (path) { tree.reveal(path); show(S.index.nodes.get(path)); } };
      showFolder(S.index.root);
      return h('div', { class: 'dsk-card dsk-split dsk-bw-split' },
        h('aside', null, count, results, treeWrap),
        detail);

      /* ---------------------------------------------------- Details */
      function show(node) {
        if (!node) return;
        if (node.dir) showFolder(node); else showFile(node);
        if (window.innerWidth <= 960 && !node.dir) detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      /** A path crumb (each folder opens), the name, size and the actions. */
      function crumb(node) {
        var parts = node.path ? node.path.split('/') : [], out = [], p = '';
        out.push(h('button', { type: 'button', class: 'seg', onclick: function () { showFolder(S.index.root); } }, D.icon('folder'), D.host(D.config.homeUrl)));
        parts.forEach(function (part, i) {
          p = i ? p + '/' + part : part;
          var path = p, last = i === parts.length - 1;
          out.push(h('span', { class: 'sep' }, '/'));
          out.push(last ? h('b', null, part) : h('button', { type: 'button', class: 'seg', onclick: function () { activeExplorer.open(path); } }, part));
        });
        return h('div', { class: 'dsk-crumb' }, out);
      }
      function head(node, actions) {
        return h('div', { class: 'dsk-bw-head' }, crumb(node),
          h('div', { class: 'dsk-detail-head' },
            h('div', { class: 'grow' }, h('h2', null, node.path ? node.name : 'Everything'), h('div', { class: 'path' }, D.fmt.size(node.size) + (node.dir ? ' · ' + D.fmt.plural(node.files, 'file') : ''))),
            h('div', { class: 'dsk-row' }, actions)));
      }
      function renameBtn(node) {
        return D.config.fileEdits && node.path ? h('button', { class: 'dsk-btn sm', type: 'button', onclick: function () { rename(node); } }, D.icon('rename'), 'Rename') : null;
      }

      function showFolder(node) {
        var largest = node.kids.slice().sort(function (a, b) { return b.size - a.size; }).slice(0, 40);
        var max = largest.length ? largest[0].size || 1 : 1;
        D.mount(detail,
          head(node, node.path ? [h('button', { class: 'dsk-btn sm', type: 'button', onclick: function (e) { downloadFolder(node, e.currentTarget); } }, D.icon('zip'), 'Download as zip'), renameBtn(node)] : []),
          h('div', { class: 'dsk-scroll-fill' },
            h('div', { class: 'dsk-bw-cap caps' }, node.kids.length > largest.length ? 'Largest ' + largest.length + ' of ' + D.fmt.num(node.kids.length) : 'Largest first'),
            h('table', { class: 'dsk-ftab' }, h('tbody', null, largest.map(function (k) {
              var open = function () { tree.reveal(k.path); show(k); };
              return h('tr', { tabindex: '0', onclick: open, onkeydown: function (e) { if (e.key === 'Enter') open(); } },
                h('td', null, h('span', { class: 'nm' }, D.fileIcon(k), k.name + (k.dir ? '/' : ''))),
                h('td', { class: 'bar' }, h('i', { style: { width: Math.max(1, (k.size / max) * 100) + '%', background: k.dir ? 'var(--sea)' : 'var(--files)' } })),
                h('td', { class: 'num' }, D.fmt.size(k.size)),
                h('td', { class: 'num d' }, k.dir ? D.fmt.plural(k.files, 'file') : ''));
            })))));
      }

      async function showFile(node) {
        var preview = h('div', { class: 'dsk-preview' }, h('div', { class: 'dsk-empty' }, h('span', { class: 'dsk-spin' })));
        var actions = h('div', { class: 'dsk-row' });
        D.mount(detail, head(node, actions), preview);
        var dl = h('button', { class: 'dsk-btn sm', type: 'button', onclick: function (e) { downloadFile(node, e.currentTarget); } }, D.icon('download'), 'Download');
        D.mount(actions, dl, renameBtn(node));

        if (IMG.test(node.name)) {
          try {
            var img = await D.api.blob('stream-file', { file: node.path });
            var url = URL.createObjectURL(img.blob);
            D.mount(preview, h('img', { src: url, alt: node.name }));
          } catch (e) { D.mount(preview, h('div', { class: 'dsk-pad' }, D.note('error', 'Could not load the image: ' + D.errorText(e)))); }
          return;
        }
        if (node.size > MAX_PREVIEW) {
          D.mount(preview, h('div', { class: 'dsk-pad' }, D.note('', 'This file is ' + D.fmt.size(node.size) + ', too large to preview here. Download it instead.')));
          return;
        }
        var text;
        try {
          var r = await D.api.blob('stream-file', { file: node.path });
          text = await r.blob.text();
        } catch (e) { D.mount(preview, h('div', { class: 'dsk-pad' }, D.note('error', 'Could not read the file: ' + D.errorText(e)))); return; }
        if (/\u0000/.test(text.slice(0, 2000))) {
          D.mount(preview, h('div', { class: 'dsk-pad' }, D.note('', 'This looks like a binary file, so there’s no preview. Download it instead.')));
          return;
        }
        renderCode(text);

        function renderCode(content) {
          var ext = (node.name.split('.').pop() || '').toLowerCase();
          var lang = LANG[ext] || (node.name === '.htaccess' ? 'ini' : null);
          var code = h('code');
          if (lang && window.Prism && Prism.languages[lang]) code.innerHTML = Prism.highlight(content, Prism.languages[lang], lang);
          else code.textContent = content;
          D.mount(preview, h('pre', null, code));
          D.mount(actions, dl,
            D.config.fileEdits ? h('button', { class: 'dsk-btn sm', type: 'button', onclick: edit }, D.icon('edit'), 'Edit') : null,
            renameBtn(node));
        }
        function edit() {
          var ta = h('textarea', { class: 'dsk-editor', spellcheck: 'false' });
          ta.value = text;
          ta.addEventListener('keydown', function (e) {
            if (e.key === 'Tab') { e.preventDefault(); var s = ta.selectionStart; ta.setRangeText('\t', s, ta.selectionEnd, 'end'); }
            if ((e.metaKey || e.ctrlKey) && e.key === 's') { e.preventDefault(); save.click(); }
          });
          var save = h('button', { class: 'dsk-btn primary sm', onclick: async function () {
            await D.busy(save, async function () {
              try {
                await D.api.post('file/save', { file: node.path, content: ta.value });
                text = ta.value;
                D.toast('Saved ' + node.name + '.');
                renderCode(text);
              } catch (e) { D.toast('Could not save: ' + D.errorText(e), 'error'); }
            });
          } }, D.icon('check'), 'Save');
          D.mount(preview, ta);
          D.mount(actions, h('span', { class: 'muted', style: { fontSize: '12.5px' } }, h('kbd', null, '⌘S'), ' to save'), h('button', { class: 'dsk-btn ghost sm', onclick: function () { renderCode(text); } }, 'Cancel'), save);
          ta.focus();
        }
      }

      async function downloadFile(node, btn) {
        await D.busy(btn, async function () {
          try {
            var r = await D.api.blob('stream-file', { file: node.path });
            D.downloadBlob(r.blob, node.name);
          } catch (e) { D.toast('Could not download ' + node.name + ': ' + D.errorText(e), 'error'); }
        });
      }

      /** Zips a folder on the server in batches (one archive), then downloads it. */
      async function downloadFolder(node, btn) {
        var prefix = node.path + '/';
        var files = S.files.filter(function (f) { return f.name.indexOf(prefix) === 0; });
        if (!files.length) { D.toast('That folder is empty.', 'error'); return; }
        var MAX = 100 * 1024 * 1024, MAX_FILES = 500, BIG = 20 * 1024 * 1024;
        var chunks = [], cur = [], curSize = 0;
        files.forEach(function (f) {
          var size = Number(f.size) || 0, big = size > BIG;
          if ((big || curSize + size > MAX || cur.length >= MAX_FILES) && cur.length) { chunks.push(cur); cur = []; curSize = 0; }
          cur.push({ name: f.name }); curSize += size;
          if (big) { chunks.push(cur); cur = []; curSize = 0; }
        });
        if (cur.length) chunks.push(cur);
        var archive = node.name.replace(/[^a-z0-9]/gi, '_').toLowerCase() + '-' + Math.floor(Date.now() / 1000) + '.zip';
        var token = '';
        var label = h('span', null, 'Zipping…');
        btn.replaceChildren(h('span', { class: 'dsk-spin' }), label); btn.disabled = true;
        try {
          token = await D.backupSession();
          var url = '';
          for (var i = 0; i < chunks.length; i++) {
            label.textContent = 'Zipping ' + (i + 1) + ' of ' + chunks.length;
            url = await D.api.post('zip-sync-files', { backup_token: token, files: chunks[i], archive_name: archive });
            if (url && url.error) throw new Error(url.message);
          }
          await D.downloadSiteFile(url, archive);
          var keep = await D.modal({
            title: 'Download started',
            body: h('div', { class: 'dsk-stack', style: { gap: '10px' } }, h('p', null, h('strong', null, node.name), ' was zipped on the server and is downloading.'),
              h('p', { class: 'muted' }, 'Delete the temporary zip from the server now? It holds a copy of these files in a public folder.')),
            actions: [{ label: 'Keep it', value: 'keep' }, { label: 'Delete from server', kind: 'primary', value: 'delete' }]
          });
          if (keep === 'delete') {
            try { await D.api.post('cleanup-file', { backup_token: token, file_name: archive }); D.toast('Temporary zip deleted.'); D.loadStatus(); }
            catch (e) { D.toast('Could not delete the zip: ' + D.errorText(e), 'error'); }
          }
        } catch (e) {
          D.toast('Could not zip ' + node.name + ': ' + D.errorText(e), 'error');
        } finally {
          btn.replaceChildren(D.icon('zip'), 'Download as zip'); btn.disabled = false;
        }
      }

      async function rename(node) {
        var name = await D.prompt({ title: 'Rename', label: 'New name for ' + node.path, value: node.name, confirmLabel: 'Rename' });
        if (!name || name === node.name) return;
        try {
          await D.api.post('file/rename', { old_path: node.path, new_name: name });
          var parent = node.path.slice(0, node.path.length - node.name.length);
          var next = parent + name;
          var files = S.files.map(function (f) {
            if (f.name === node.path) return { name: next, size: f.size };
            if (f.name.indexOf(node.path + '/') === 0) return { name: next + f.name.slice(node.path.length), size: f.size };
            return f;
          }).sort(function (a, b) { return a.name < b.name ? -1 : a.name > b.name ? 1 : 0; });
          // Setting the index re-renders the explorer synchronously; point the
          // new one at the renamed item.
          D.store.set({ files: files, index: D.buildIndex(files) });
          D.toast('Renamed to ' + name + '.');
          if (activeExplorer && S.index.nodes.get(next)) activeExplorer.open(next);
        } catch (e) { D.toast('Could not rename: ' + D.errorText(e), 'error'); }
      }
    }
  }
})();
