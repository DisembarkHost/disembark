/**
 * "What to bring": shown during a pull or push, after the source is scanned
 * and before anything is copied. Lists what the source holds, ticks the
 * suggested skips (old backups, caches, logs, Wordfence's firewall files),
 * and lets you leave out any folder or bring any table empty. Choices can be
 * saved as templates on this site (GET/POST /templates).
 *
 * D.whatToBring(box, contents, o) resolves with
 *   { skip_paths, skip_names, skip_rows }, or null when the move is cancelled.
 * contents is POST import/pull/contents (app/Skips.php analyze()).
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h;

  function check(state) { return h('span', { class: 'dsk-check' + (state ? ' ' + state : '') }); }
  function inside(path, parent) { return path === parent || path.indexOf(parent + '/') === 0; }
  function topmost(list) {
    var out = [];
    list.slice().sort().forEach(function (p) { if (!out.some(function (q) { return inside(p, q); })) out.push(p); });
    return out;
  }

  D.whatToBring = function (box, c, o) {
    o = o || {};
    return new Promise(function (resolve) {
      /* ------------------------------------------------------------ Data */
      var nodes = {}, kids = {};
      (c.folders || []).forEach(function (f) { nodes[f[0]] = { path: f[0], bytes: f[1], files: f[2], dir: true }; });
      (c.root_files || []).forEach(function (f) { nodes[f[0]] = { path: f[0], bytes: f[1], files: 1, dir: false }; });
      Object.keys(nodes).forEach(function (p) {
        var i = p.lastIndexOf('/'), parent = i === -1 ? '' : p.slice(0, i);
        (kids[parent] = kids[parent] || []).push(nodes[p]);
      });
      Object.keys(kids).forEach(function (k) { kids[k].sort(function (a, b) { return (b.dir - a.dir) || (b.bytes - a.bytes); }); });
      var sugg = c.suggestions || [], prefix = String(c.prefix || '').toLowerCase();
      var tableBytes = {};
      (c.tables || []).forEach(function (t) { tableBytes[t.name] = t.size; });

      /* ----------------------------------------------------------- State */
      var on = new Set(), paths = new Set(), rows = new Set(), keepRows = new Set(), open = new Set();
      var templates = [], start = 'suggested';

      function apply(kind) {
        start = kind;
        on.clear(); paths.clear(); rows.clear(); keepRows.clear();
        if (kind === 'suggested') sugg.forEach(function (s) { if (s.default) on.add(s.id); });
        var tpl = templates.find(function (t) { return 'tpl:' + t.name === kind; });
        if (tpl) {
          (tpl.suggestions || []).forEach(function (id) { if (sugg.some(function (s) { return s.id === id; })) on.add(id); });
          (tpl.paths || []).forEach(function (p) { if (nodes[p]) paths.add(p); });
          (tpl.rows || []).forEach(function (suffix) {
            suffix = String(suffix).toLowerCase();
            (c.tables || []).forEach(function (t) {
              var n = t.name.toLowerCase();
              if (n === prefix + suffix || new RegExp('^' + prefix.replace(/[^a-z0-9_]/g, '') + '\\d+_' + suffix.replace(/[^a-z0-9_]/g, '') + '$').test(n)) rows.add(t.name);
            });
          });
        }
      }

      function skipPaths() {
        var all = [];
        sugg.forEach(function (s) { if (on.has(s.id)) all = all.concat(s.paths); });
        paths.forEach(function (p) { all.push(p); });
        return topmost(all);
      }
      function skipNames() {
        var all = [];
        sugg.forEach(function (s) { if (on.has(s.id)) all = all.concat(s.names || []); });
        return Array.from(new Set(all));
      }
      function skipRows() {
        var all = new Set();
        sugg.forEach(function (s) { if (on.has(s.id)) (s.tables || []).forEach(function (t) { all.add(t); }); });
        rows.forEach(function (t) { all.add(t); });
        keepRows.forEach(function (t) { all.delete(t); });
        return Array.from(all);
      }
      /** What leaves this path behind: { by: suggestion | null, path } or null. */
      function cover(path) {
        var hit = null;
        sugg.forEach(function (s) { if (!hit && on.has(s.id)) s.paths.forEach(function (p) { if (!hit && inside(path, p)) hit = { by: s, path: p }; }); });
        if (!hit) paths.forEach(function (p) { if (!hit && inside(path, p)) hit = { by: null, path: p }; });
        return hit;
      }
      function totals() {
        var files = 0, bytes = 0, names = 0, nameBytes = 0, rowBytes = 0;
        skipPaths().forEach(function (p) { if (nodes[p]) { files += nodes[p].files; bytes += nodes[p].bytes; } });
        sugg.forEach(function (s) { if (on.has(s.id)) { names += s.name_files || 0; nameBytes += s.name_bytes || 0; } });
        var r = skipRows();
        r.forEach(function (t) { rowBytes += tableBytes[t] || 0; });
        return { files: files + names, bytes: bytes + nameBytes, rows: r.length, rowBytes: rowBytes };
      }

      /* --------------------------------------------------------------- UI */
      var startSel = h('select', { class: 'dsk-select', style: { width: 'auto', minWidth: '200px' }, onchange: function () { apply(startSel.value); render(); } });
      var delBtn = h('button', { class: 'dsk-btn ghost sm', type: 'button', onclick: deleteTemplate }, D.icon('trash'), 'Delete template');
      var suggBox = h('div', { class: 'dsk-bring-sugg' });
      var tree = h('div', { class: 'dsk-tree dsk-bring-scroll' });
      var tables = h('ul', { class: 'dsk-list dsk-bring-scroll' });
      var summary = h('div', { class: 'grow' });
      var goBtn = h('button', { class: 'dsk-btn primary', type: 'button', onclick: function () { finish({ skip_paths: skipPaths(), skip_names: skipNames(), skip_rows: skipRows() }); } }, D.icon('download'), 'Bring it over');

      function renderStart() {
        D.mount(startSel, [h('option', { value: 'suggested' }, 'Suggested skips'), h('option', { value: 'everything' }, 'Everything')]
          .concat(templates.map(function (t) { return h('option', { value: 'tpl:' + t.name }, 'Template: ' + t.name); })));
        startSel.value = start;
        delBtn.hidden = start.indexOf('tpl:') !== 0;
      }

      function sizeLine(s) {
        var bits = [];
        if (s.files) bits.push(D.fmt.plural(s.files, 'file') + ', ' + D.fmt.size(s.bytes));
        if (s.tables && s.tables.length) bits.push(D.fmt.plural(s.tables.length, 'table') + ' brought empty (' + D.fmt.size(s.table_bytes) + ')');
        return bits.join(' · ');
      }
      function renderSugg() {
        if (!sugg.length) { D.mount(suggBox, h('p', { class: 'muted' }, 'Nothing on the source matches the usual things to leave behind.')); return; }
        var groups = [];
        sugg.forEach(function (s) { if (groups.indexOf(s.group) === -1) groups.push(s.group); });
        D.mount(suggBox, groups.map(function (g) {
          return h('div', { class: 'dsk-bring-group' }, h('div', { class: 'caps' }, g), sugg.filter(function (s) { return s.group === g; }).map(function (s) {
            var ticked = on.has(s.id);
            var what = s.paths.concat(s.names || [], s.tables || []);
            return h('div', { class: 'dsk-bring-item' + (ticked ? '' : ' off'), role: 'checkbox', 'aria-checked': String(ticked), tabindex: '0',
              onclick: function () { if (ticked) on.delete(s.id); else on.add(s.id); render(); },
              onkeydown: function (e) { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); e.currentTarget.click(); } } },
              check(ticked ? 'on' : ''),
              h('div', { class: 'grow' },
                h('div', { class: 'dsk-row', style: { gap: '8px' } }, h('strong', null, s.label), h('span', { class: 'size' }, sizeLine(s))),
                h('div', { class: 'why' }, s.why),
                h('div', { class: 'what' }, what.slice(0, 6).join(', ') + (what.length > 6 ? ', …' : ''))));
          }));
        }));
      }

      function renderTree() {
        var out = [];
        (function walk(parent, depth) {
          (kids[parent] || []).forEach(function (n) {
            var cov = cover(n.path), partial = false;
            if (!cov && n.dir) {
              skipPaths().forEach(function (p) { if (p.indexOf(n.path + '/') === 0) partial = true; });
            }
            var name = n.path.slice(parent ? parent.length + 1 : 0), isOpen = open.has(n.path), hasKids = n.dir && kids[n.path];
            out.push(h('div', { class: 'dsk-tree-row' + (cov ? ' off' : '') + (isOpen ? ' open' : ''), style: { paddingLeft: (6 + depth * 18) + 'px' },
              onclick: function () { if (hasKids) { if (isOpen) open.delete(n.path); else open.add(n.path); renderTree(); } } },
              h('span', { class: 'tw' }, hasKids ? D.icon('chevron') : null),
              h('span', { title: cov ? 'Left behind' : 'Brought over', onclick: function (e) { e.stopPropagation(); toggleNode(n, cov); } }, check(cov ? '' : partial ? 'mixed' : 'on')),
              D.fileIcon({ dir: n.dir, name: name }),
              h('span', { class: 'nm', title: n.path }, name),
              h('span', { class: 'sz' }, D.fmt.size(n.bytes) + (n.dir ? ' · ' + D.fmt.num(n.files) : ''))));
            if (isOpen) walk(n.path, depth + 1);
          });
        })('', 0);
        D.mount(tree, out.length ? out : h('p', { class: 'muted', style: { padding: '10px 14px' } }, 'No files.'));
      }
      function toggleNode(n, cov) {
        if (!cov) {
          paths.forEach(function (p) { if (inside(p, n.path)) paths.delete(p); });
          paths.add(n.path);
        } else if (cov.path === n.path && !cov.by) {
          paths.delete(n.path);
        } else if (cov.path === n.path && cov.by && cov.by.paths.length > 1 && !(cov.by.names || []).length && !(cov.by.tables || []).length) {
          // A list of files and folders (the install's root files, archives
          // in the root): this one comes, the rest still stay behind.
          on.delete(cov.by.id);
          cov.by.paths.forEach(function (p) { if (p !== n.path) paths.add(p); });
          D.toast('Bringing ' + n.path + '. The rest of “' + cov.by.label + '” stays behind.');
        } else if (cov.path === n.path && cov.by) {
          on.delete(cov.by.id);
          D.toast('Unticked “' + cov.by.label + '”.');
        } else {
          D.toast('It’s inside ' + cov.path + ', which is left behind. Bring that folder first.', 'error');
          return;
        }
        render();
      }

      function renderTables() {
        var skip = new Set(skipRows());
        D.mount(tables, (c.tables || []).map(function (t) {
          var empty = skip.has(t.name);
          return h('li', { class: 'clickable' + (empty ? ' off' : ''), title: empty ? 'The table comes across empty' : 'Brought over with its rows',
            onclick: function () {
              if (empty) { if (rows.has(t.name)) rows.delete(t.name); else keepRows.add(t.name); }
              else { if (keepRows.has(t.name)) keepRows.delete(t.name); else rows.add(t.name); }
              render();
            } },
            check(empty ? '' : 'on'), h('span', { class: 'grow mono' }, t.name),
            empty ? h('span', { class: 'dsk-badge' }, 'empty') : null,
            h('span', { class: 'size' }, D.fmt.size(t.size) + ' · ' + D.fmt.plural(t.rows, 'row')));
        }));
      }

      function renderSummary() {
        var t = totals();
        var keepFiles = Math.max(0, (c.files || 0) - t.files), keepBytes = Math.max(0, (c.bytes || 0) - t.bytes);
        D.mount(summary,
          h('div', null, h('strong', null, 'Bringing ' + D.fmt.plural(keepFiles, 'file') + ' (' + D.fmt.size(keepBytes) + ')'), ' and ' + D.fmt.plural((c.tables || []).length, 'table') + '.'),
          h('div', { class: 'muted' }, t.files || t.rows
            ? 'Leaving behind ' + D.fmt.plural(t.files, 'file') + ' (' + D.fmt.size(t.bytes) + ')' + (t.rows ? ', and the rows of ' + D.fmt.plural(t.rows, 'table') + ' (' + D.fmt.size(t.rowBytes) + ')' : '') + '.'
            : 'Nothing left behind.'));
      }

      function render() { renderStart(); renderSugg(); renderTree(); renderTables(); renderSummary(); }

      async function saveTemplate(e) {
        var current = start.indexOf('tpl:') === 0 ? start.slice(4) : '';
        var name = await D.prompt({ title: 'Save as a template', label: 'Name', value: current || (o.quiet ? 'Staging copy' : ''), confirmLabel: 'Save' });
        if (!name || !String(name).trim()) return;
        var strip = function (t) { var l = t.toLowerCase(); return prefix && l.indexOf(prefix) === 0 ? t.slice(prefix.length) : t; };
        try {
          templates = await D.busy(e.currentTarget, function () {
            return D.api.post('templates', { action: 'save', name: String(name).trim(), suggestions: Array.from(on), paths: Array.from(paths), rows: Array.from(rows).map(strip) });
          }) || templates;
          start = 'tpl:' + String(name).trim();
          var exact = templates.find(function (t) { return t.name.toLowerCase() === String(name).trim().toLowerCase(); });
          if (exact) start = 'tpl:' + exact.name;
          renderStart();
          D.toast('Saved “' + String(name).trim() + '”. It’s offered on every move from this site.');
        } catch (err) { D.toast(D.errorText(err), 'error'); }
      }
      async function deleteTemplate(e) {
        var name = start.slice(4);
        if (!(await D.confirm('Delete the template “' + name + '”?', { title: 'Delete template', confirmLabel: 'Delete', danger: true }))) return;
        try {
          templates = await D.busy(e.currentTarget, function () { return D.api.post('templates', { action: 'delete', name: name }); }) || [];
          apply('suggested'); render();
        } catch (err) { D.toast(D.errorText(err), 'error'); }
      }

      function finish(choice) {
        box.cancelChoice = null;
        D.mount(box);
        resolve(choice);
      }
      box.cancelChoice = function () { finish(null); };

      apply('suggested');
      D.mount(box, h('div', { class: 'dsk-card dsk-bring dsk-decide dsk-fade' },
        h('div', { class: 'dsk-card-pad dsk-stack', style: { gap: '14px' } },
          h('div', { class: 'q' },
            h('span', { class: 'dsk-tag warn', style: { marginBottom: '10px' } }, D.icon('alert'), 'Waiting on you'),
            h('h2', { style: { fontSize: '32px', lineHeight: 1 } }, 'What to ', h('em', { class: 'hl' }, 'bring?')),
            h('p', { class: 'muted', style: { marginTop: '10px' } }, o.catchUp
              ? 'Catching up: ' + D.fmt.plural(c.files || 0, 'file') + ' (' + D.fmt.size(c.bytes || 0) + ') on ' + D.host(o.source || '') + ' aren\u2019t here from the last pull (new, changed, or left behind by it), and all ' + D.fmt.plural((c.tables || []).length, 'table') + ' come across again. The usual things to leave behind are ticked. Nothing has been copied yet.'
              : D.host(o.source || '') + ' holds ' + D.fmt.plural(c.files || 0, 'file') + ' (' + D.fmt.size(c.bytes || 0) + ') and ' + D.fmt.plural((c.tables || []).length, 'table') + '. The usual things to leave behind are ticked. Nothing has been copied yet.')),
          h('div', { class: 'dsk-row' }, h('label', { class: 'muted' }, 'Start from'), startSel,
            h('button', { class: 'dsk-btn ghost sm', type: 'button', onclick: saveTemplate }, D.icon('plus'), 'Save as template'), delBtn),
          h('div', null, h('h3', { style: { marginBottom: '8px' } }, 'Leave behind'), suggBox),
          h('div', { class: 'dsk-grid two' },
            h('div', null, h('h3', { style: { marginBottom: '4px' } }, 'Files'), h('p', { class: 'muted', style: { margin: '0 0 8px', fontSize: '12.5px' } }, 'Untick a folder to leave it behind.'), h('div', { class: 'dsk-bring-pane' }, tree)),
            h('div', null, h('h3', { style: { marginBottom: '4px' } }, 'Database'), h('p', { class: 'muted', style: { margin: '0 0 8px', fontSize: '12.5px' } }, 'Untick a table to bring it empty: its structure comes across, its rows stay behind.'), h('div', { class: 'dsk-bring-pane' }, tables)))),
        h('div', { class: 'dsk-card-foot dsk-bring-foot' }, summary,
          h('button', { class: 'dsk-btn ghost', type: 'button', onclick: function () { finish(null); } }, 'Cancel'),
          h('button', { class: 'dsk-btn', type: 'button', onclick: function () { finish({ skip_paths: [], skip_names: [], skip_rows: [] }); } }, 'Bring everything'),
          goBtn)));
      render();
      if (box.scrollIntoView) box.scrollIntoView({ behavior: 'smooth', block: 'start' });

      D.api.get('templates').then(function (list) {
        templates = Array.isArray(list) ? list : [];
        renderStart();
      }).catch(function () { /* older Disembark here: no templates */ });
    });
  };
})();
