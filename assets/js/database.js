/**
 * Database: browse tables, page and sort rows, edit / add / delete rows,
 * inspect structure, and export one table or a batch as SQL.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h, S = D.store.state;

  D.section({ id: 'database', title: 'Database', icon: 'database', mount: guarded, onShow: function () { if (!S.site && !S.database.length) D.loadDatabase().catch(function (e) { D.toast('Could not read the database: ' + D.errorText(e), 'error'); }); } });

  // While Back up works on one site of a shared install, the tables loaded
  // are that site's export names: browsing or editing them here would be wrong.
  function guarded(root) {
    var box = h('div'), mounted = false;
    D.mount(root, box);
    function show() {
      if (S.site) { mounted = false; D.mount(box, D.oneSiteNote('Database')); return; }
      if (!mounted) { mounted = true; D.mount(box); mount(box); if (!S.database.length) D.loadDatabase().catch(function () {}); }
    }
    D.store.on(['site'], show);
    show();
  }

  function mount(root) {
    var st = { q: '', selected: [], anchor: null, tab: 'rows' };
    var listEl = h('div', { class: 'dsk-scroll-fill' });
    var detail = h('section');
    var allBox = h('span', { class: 'dsk-check', role: 'checkbox', tabindex: '0', 'aria-label': 'Select all visible tables', onclick: toggleAll });
    var countEl = h('span', { class: 'muted' });
    var search = D.search('Search tables', function (v) { st.q = v; renderList(); });

    var slot = D.browseTools ? D.browseTools('database') : h('div');
    D.mount(slot,
      h('div', { class: 'dsk-bw-search' }, search),
      h('button', { class: 'dsk-btn', type: 'button', onclick: function () { searchReplace(st.selected.slice()); } }, D.icon('search'), 'Search and replace'),
      h('button', { class: 'dsk-btn ghost icon', type: 'button', 'aria-label': 'Reload the table list', title: 'Reload the table list', onclick: function (e) { D.busy(e.currentTarget, function () { return D.loadDatabase(true).catch(function (err) { D.toast(D.errorText(err), 'error'); }); }); } }, D.icon('refresh')));
    D.mount(root,
      h('div', { class: 'dsk-card dsk-split dsk-db-split' },
        h('aside', null,
          h('div', { class: 'dsk-bw-aside-h' }, allBox, countEl),
          listEl),
        detail));

    D.store.on(['database'], function () {
      st.selected = st.selected.filter(function (n) { return S.database.some(function (t) { return t.table === n; }); });
      renderList(); renderDetail();
    });
    renderList(); renderDetail();

    function visible() {
      return S.database.filter(function (t) { return !st.q || t.table.toLowerCase().indexOf(st.q) !== -1; })
        .sort(function (a, b) { return a.table.localeCompare(b.table); });
    }
    function renderList() {
      var rows = visible();
      var selCount = rows.filter(function (t) { return st.selected.indexOf(t.table) !== -1; }).length;
      allBox.className = 'dsk-check' + (selCount && selCount === rows.length ? ' on' : selCount ? ' mixed' : '');
      countEl.textContent = S.database.length ? D.fmt.plural(rows.length, 'table') + ' · ' + D.fmt.size(rows.reduce(function (n, t) { return n + t.size; }, 0)) + (st.selected.length ? ' · ' + st.selected.length + ' selected' : '') : '';
      if (!S.database.length) { D.mount(listEl, h('div', { class: 'dsk-pad muted' }, h('span', { class: 'dsk-spin' }), ' Loading tables…')); return; }
      if (!rows.length) { D.mount(listEl, h('div', { class: 'dsk-pad muted' }, 'No tables match.')); return; }
      D.mount(listEl, h('ul', { class: 'dsk-list dsk-db-list' }, rows.map(function (t) {
        var sel = st.selected.indexOf(t.table) !== -1;
        return h('li', { class: 'clickable' + (sel ? ' sel' : ''), tabindex: '0', onclick: function (e) { pick(t.table, e, rows); }, onkeydown: function (e) { if (e.key === 'Enter') pick(t.table, e, rows); } },
          h('span', { class: 'dsk-check' + (sel ? ' on' : ''), title: 'Add to the selection', onclick: function (e) { e.stopPropagation(); pick(t.table, { metaKey: true, shiftKey: e.shiftKey }, rows); } }),
          h('span', { class: 'nm' }, t.table),
          h('span', { class: 'size' }, D.fmt.size(t.size)));
      })));
    }
    function pick(name, e, rows) {
      var names = rows.map(function (r) { return r.table; });
      if (e.shiftKey && st.anchor) {
        var a = names.indexOf(st.anchor), b = names.indexOf(name);
        names.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (n) { if (st.selected.indexOf(n) === -1) st.selected.push(n); });
      } else if (e.metaKey || e.ctrlKey) {
        var i = st.selected.indexOf(name);
        if (i === -1) st.selected.push(name); else st.selected.splice(i, 1);
        st.anchor = name;
      } else {
        st.selected = [name]; st.anchor = name;
      }
      renderList(); renderDetail();
      if (window.innerWidth <= 960 && !e.shiftKey && !e.metaKey && !e.ctrlKey) detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    function toggleAll() {
      var names = visible().map(function (t) { return t.table; });
      var all = names.every(function (n) { return st.selected.indexOf(n) !== -1; });
      st.selected = all ? st.selected.filter(function (n) { return names.indexOf(n) === -1; }) : Array.from(new Set(st.selected.concat(names)));
      renderList(); renderDetail();
    }
    /* ---------------------------------------------- Search and replace */
    function mark(text, needle) {
      var out = h('span'), i = 0, at;
      if (!needle) return out.appendChild(document.createTextNode(text)), out;
      while ((at = text.indexOf(needle, i)) !== -1) {
        out.appendChild(document.createTextNode(text.slice(i, at)));
        out.appendChild(h('mark', { style: { background: 'var(--accent-soft)', color: 'var(--accent)', borderRadius: '3px', padding: '0 2px' } }, needle));
        i = at + needle.length;
      }
      out.appendChild(document.createTextNode(text.slice(i)));
      return out;
    }
    function searchReplace(selected) {
      var from = h('input', { class: 'dsk-input mono', placeholder: 'https://old-address.com', spellcheck: 'false' });
      var to = h('input', { class: 'dsk-input mono', placeholder: 'https://new-address.com', spellcheck: 'false' });
      var onlySelected = selected.length ? D.toggle('Only the ' + D.fmt.plural(selected.length, 'selected table'), false, function () { result.replaceChildren(); }) : null;
      // This site's own tables by default: on a network or WP Freighter
      // install, the other sites' tables (and another WordPress sharing the
      // database) only when asked for.
      var everyTable = D.toggle('Every table in the database, not just this site’s', false, function () { result.replaceChildren(); });
      var result = h('div');
      var previewBtn = h('button', { class: 'dsk-btn primary', onclick: preview }, D.icon('search'), 'Preview changes');
      [from, to].forEach(function (i) { i.addEventListener('input', function () { result.replaceChildren(); }); i.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); preview(); } }); });
      function scope() { return onlySelected && onlySelected.input.checked ? selected : []; }
      function allTables() { return !scope().length && everyTable.input.checked ? 1 : 0; }
      D.modal({
        title: 'Search and replace', wide: true,
        body: h('div', { class: 'dsk-stack', style: { gap: '12px' } },
          h('p', null, 'Rewrites text across the database, safely inside serialized PHP values and JSON (the same engine a restore uses). Preview first; a restore point is saved before anything changes.'),
          h('div', { class: 'dsk-grid two', style: { gap: '12px' } },
            h('div', { class: 'dsk-field', style: { margin: 0 } }, h('label', null, 'Find'), from),
            h('div', { class: 'dsk-field', style: { margin: 0 } }, h('label', null, 'Replace with'), to)),
          onlySelected, everyTable, h('div', null, previewBtn), result),
        actions: [{ label: 'Close', value: null }]
      });
      async function preview() {
        if (!from.value) { from.focus(); return; }
        await D.busy(previewBtn, async function () {
          try {
            var r = await D.api.post('search-replace', { from: from.value, to: to.value, tables: scope(), all_tables: allTables(), dry_run: true });
            showPreview(r);
          } catch (e) { result.replaceChildren(D.note('error', D.errorText(e))); }
        });
      }
      function showPreview(r) {
        if (!r.cells_changed) { result.replaceChildren(D.note('', 'Nothing matches \u201c' + from.value + '\u201d' + (scope().length ? ' in the selected tables.' : allTables() ? ' anywhere in the database.' : ' in this site\u2019s tables.'))); return; }
        var go = h('button', { class: 'dsk-btn danger solid', onclick: function () { replace(r, go); } }, 'Replace ' + D.fmt.plural(r.cells_changed, 'cell'));
        result.replaceChildren(h('div', { class: 'dsk-stack', style: { gap: '12px' } },
          D.note('warn', [h('strong', null, D.fmt.plural(r.cells_changed, 'cell')), ' in ', D.fmt.plural(r.rows_changed, 'row'), ' across ', D.fmt.plural(Object.keys(r.by_table).length, 'table'), ' would change.']),
          h('div', { class: 'dsk-row', style: { gap: '6px' } }, Object.keys(r.by_table).slice(0, 12).map(function (t) { return h('span', { class: 'dsk-badge' }, t + ' · ' + D.fmt.num(r.by_table[t])); })),
          h('div', { class: 'dsk-table-wrap', style: { maxHeight: '260px', border: '1px solid var(--line)', borderRadius: '10px' } }, h('table', { class: 'dsk-table static' },
            h('thead', null, h('tr', null, h('th', null, 'Where'), h('th', null, 'Before'), h('th', null, 'After'))),
            h('tbody', null, (r.samples || []).map(function (x) {
              return h('tr', null,
                h('td', { style: { whiteSpace: 'normal', maxWidth: '180px' } }, x.table + '.' + x.column, h('div', { class: 'muted' }, x.key)),
                h('td', { style: { whiteSpace: 'normal', wordBreak: 'break-all', maxWidth: '300px' } }, mark(x.before, from.value)),
                h('td', { style: { whiteSpace: 'normal', wordBreak: 'break-all', maxWidth: '300px' } }, mark(x.after, to.value)));
            })))),
          (r.samples || []).length < r.cells_changed ? h('p', { class: 'muted' }, 'Showing the first ' + r.samples.length + ' changes.') : null,
          h('div', null, go)));
      }
      async function replace(r, btn) {
        var ok = await D.confirm(h('div', { class: 'dsk-stack', style: { gap: '10px' } },
          h('p', null, 'Replace ', h('strong', null, from.value), ' with ', h('strong', null, to.value || '(nothing)'), ' in ', D.fmt.plural(r.cells_changed, 'cell'), '?'),
          h('p', { class: 'muted' }, 'A restore point is saved first, so this can be undone.')), { title: 'Replace now?', confirmLabel: 'Replace', danger: true });
        if (!ok) return;
        await D.busy(btn, async function () {
          var id = D.randomHex(24), saved = false;
          try {
            // The tables the preview found, and only those: their restore
            // point, then the replace, each a piece at a time.
            var tables = Object.keys(r.by_table);
            await D.paced(D.api, 'import/snapshot', { import_id: id, label: 'Before search and replace: ' + from.value + ' → ' + (to.value || '(nothing)'), tables: tables });
            saved = true;
            var done = await D.paced(D.api, 'search-replace', { import_id: id, from: from.value, to: to.value, tables: tables });
            var undo = h('button', { class: 'dsk-btn', onclick: async function () {
              await D.busy(undo, async function () {
                try {
                  var rb = await D.paced(D.api, 'import/rollback', { import_id: id });
                  if (rb.errors && rb.errors.length) throw new Error(rb.errors[0]);
                  result.replaceChildren(D.note('ok', 'Undone. The database is back to how it was before the replace.'));
                  D.loadDatabase(true).catch(function () {});
                } catch (e) { D.toast('Undo failed: ' + D.errorText(e), 'error'); }
              });
            } }, D.icon('restore'), 'Undo');
            result.replaceChildren(h('div', { class: 'dsk-stack', style: { gap: '10px' } },
              D.note('ok', [h('strong', null, 'Replaced ' + D.fmt.plural(done.cells_changed, 'cell') + '.'), ' A restore point was saved first: undo it here, or later from ', pointsLink(), '.']),
              h('div', null, undo)));
            D.loadDatabase(true).catch(function () {});
          } catch (e) {
            result.replaceChildren(D.note('error', saved
              ? ['The replace stopped partway: ' + D.errorText(e) + ' Some cells may have changed. Roll back from ', pointsLink(), '.']
              : 'Nothing was replaced: ' + D.errorText(e)));
          }
        });
      }
    }

    function pointsLink() {
      return h('a', { href: '#backups', onclick: function (e) { var d = e.currentTarget.closest('dialog'); if (d) d.querySelector('header .dsk-btn').click(); } }, h('strong', null, 'Backups → Restore points'));
    }
    function tableInfo(name) { return S.database.find(function (t) { return t.table === name; }) || { table: name, size: 0, row_count: 0 }; }

    /* ---------------------------------------------------------- Detail */
    function renderDetail() {
      if (!st.selected.length) { overview(); return; }
      if (st.selected.length > 1) { batch(); return; }
      single(tableInfo(st.selected[0]));
    }

    /** Nothing picked: the largest tables, with what they hold. */
    function overview() {
      if (!S.database.length) { D.mount(detail, h('div', { class: 'dsk-empty' }, h('span', { class: 'dsk-spin' }))); return; }
      var total = S.database.reduce(function (s, t) { return s + t.size; }, 0);
      var top = S.database.slice().sort(function (a, b) { return b.size - a.size; }).slice(0, 25), max = top[0].size || 1;
      D.mount(detail,
        h('div', { class: 'dsk-bw-head' },
          h('div', { class: 'dsk-crumb' }, D.icon('database'), h('b', null, D.fmt.plural(S.database.length, 'table')), h('span', { class: 'r' }, D.fmt.size(total))),
          h('div', { class: 'dsk-detail-head' }, h('div', { class: 'grow' }, h('h2', null, 'Largest ', h('em', { class: 'hl' }, 'tables')),
            h('div', { class: 'path sans' }, 'Pick one to see its rows. Cmd- or Shift-click to select several for export.')))),
        h('div', { class: 'dsk-scroll-fill' },
          h('table', { class: 'dsk-ftab' }, h('tbody', null, top.map(function (t) {
            var open = function (e) { pick(t.table, e, visible()); };
            return h('tr', { tabindex: '0', onclick: open, onkeydown: function (e) { if (e.key === 'Enter') open(e); } },
              h('td', null, h('span', { class: 'nm' }, D.icon('database'), t.table)),
              h('td', { class: 'bar' }, h('i', { style: { width: Math.max(1, (t.size / max) * 100) + '%', background: 'var(--teal)' } })),
              h('td', { class: 'num' }, D.fmt.size(t.size)),
              h('td', { class: 'num d' }, D.fmt.num(t.row_count) + ' rows'));
          })))));
    }

    function batch() {
      var tables = st.selected.map(tableInfo);
      var size = tables.reduce(function (s, t) { return s + t.size; }, 0), rows = tables.reduce(function (s, t) { return s + t.row_count; }, 0);
      var btn = h('button', { class: 'dsk-btn primary lg', onclick: function () { exportBatch(tables, btn); } }, D.icon('download'), 'Export ' + tables.length + ' tables');
      D.mount(detail, h('div', { class: 'dsk-empty' }, h('div', { style: { maxWidth: '420px' } },
        h('div', { class: 'dsk-bw-spot', html: D.H.spot('db') }), h('h3', null, D.fmt.plural(tables.length, 'table') + ' selected'),
        h('div', { class: 'dsk-row', style: { justifyContent: 'center', margin: '10px 0 16px' } }, h('span', { class: 'dsk-badge' }, D.fmt.size(size)), h('span', { class: 'dsk-badge' }, D.fmt.num(rows) + ' rows')),
        h('p', { style: { marginBottom: '16px' } }, 'Downloads SQL dumps of the selected tables. Big tables are split into parts so no request times out.'), btn)));
    }

    function single(t) {
      var tabs = [{ id: 'rows', label: 'Rows' }, { id: 'structure', label: 'Structure' }, { id: 'export', label: 'Export' }];
      var body = h('div', { style: { flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' } });
      var tabBar = h('div', { class: 'dsk-dbtabs', role: 'tablist' });
      var schema = null;
      function loadSchema() {
        if (!schema) schema = D.api.get('database/schema', { table: t.table }).catch(function (e) { schema = null; throw e; });
        return schema;
      }
      function renderTabs() {
        D.mount(tabBar, tabs.map(function (x) {
          return h('button', { type: 'button', role: 'tab', 'aria-selected': String(st.tab === x.id), onclick: function () { st.tab = x.id; renderTabs(); renderTab(); } }, x.label);
        }));
      }
      function renderTab() {
        if (st.tab === 'rows') rowsTab(t, body, loadSchema);
        else if (st.tab === 'structure') structureTab(t, body, loadSchema);
        else exportTab(t, body);
      }
      D.mount(detail,
        h('div', { class: 'dsk-bw-head' },
          h('div', { class: 'dsk-crumb' }, h('button', { type: 'button', class: 'seg', onclick: function () { st.selected = []; renderList(); renderDetail(); } }, D.icon('database'), D.fmt.plural(S.database.length, 'table')), h('span', { class: 'sep' }, '/'), h('b', null, t.table)),
          h('div', { class: 'dsk-detail-head' }, h('div', { class: 'grow' }, h('h2', { class: 'dsk-db-name' }, t.table),
            h('div', { class: 'path' }, D.fmt.num(t.row_count) + ' rows · ' + D.fmt.size(t.size))))),
        tabBar, body);
      renderTabs(); renderTab();
    }

    /* Rows */
    function rowsTab(t, body, loadSchema) {
      var st2 = { page: 1, limit: 100, orderby: null, order: 'ASC', total: 0, rows: [], cols: [] };
      var tableWrap = h('div', { class: 'dsk-table-wrap', style: { flex: 1, minHeight: 0 } });
      var pager = h('div', { class: 'dsk-pager' });
      var toolbar = h('div', { class: 'dsk-row', style: { padding: '10px 14px' } },
        h('button', { class: 'dsk-btn sm', onclick: function () { editRow(null); } }, D.icon('plus'), 'Add row'),
        h('button', { class: 'dsk-btn ghost sm', onclick: function () { load(); } }, D.icon('refresh'), 'Reload'),
        h('span', { class: 'muted', style: { marginLeft: 'auto', fontSize: '12.5px' } }, 'Click a row to edit it.'));
      D.mount(body, toolbar, tableWrap, pager);
      load();

      async function load() {
        D.mount(tableWrap, h('div', { class: 'dsk-empty' }, h('span', { class: 'dsk-spin' })));
        try {
          var r = await D.api.get('database/rows', { table: t.table, page: st2.page, limit: st2.limit, orderby: st2.orderby, order: st2.order });
          st2.rows = r.rows || []; st2.total = Number(r.total) || 0;
          if (st2.rows.length) st2.cols = Object.keys(st2.rows[0]);
          else if (!st2.cols.length) { try { var sc = await loadSchema(); st2.cols = (sc.structure || []).map(function (c) { return c.Field; }); } catch (e) { /* ignore */ } }
          draw();
        } catch (e) { D.mount(tableWrap, h('div', { class: 'dsk-pad' }, D.note('error', 'Could not load rows: ' + D.errorText(e)))); }
      }
      function draw() {
        if (!st2.rows.length) {
          D.mount(tableWrap, h('div', { class: 'dsk-empty' }, h('div', null, h('h3', null, 'This table is empty'), h('p', null, 'Add a row to get started.'))));
        } else {
          D.mount(tableWrap, h('table', { class: 'dsk-table' },
            h('thead', null, h('tr', null, st2.cols.map(function (c) {
              return h('th', { class: 'sortable', onclick: function () {
                if (st2.orderby === c) st2.order = st2.order === 'ASC' ? 'DESC' : 'ASC'; else { st2.orderby = c; st2.order = 'ASC'; }
                st2.page = 1; load();
              } }, c, st2.orderby === c ? h('span', { class: 'arrow' }, st2.order === 'ASC' ? '↑' : '↓') : null);
            }))),
            h('tbody', null, st2.rows.map(function (row) {
              return h('tr', { tabindex: '0', onclick: function () { editRow(row); }, onkeydown: function (e) { if (e.key === 'Enter') editRow(row); } }, st2.cols.map(function (c) {
                var v = row[c];
                return v === null ? h('td', { class: 'null' }, 'NULL') : h('td', { title: String(v).slice(0, 500) }, String(v).slice(0, 200));
              }));
            }))));
        }
        var pages = Math.max(1, Math.ceil(st2.total / st2.limit));
        var size = h('select', { 'aria-label': 'Rows per page', onchange: function () { st2.limit = Number(size.value); st2.page = 1; load(); } },
          [50, 100, 500, 1000].map(function (n) { return h('option', { value: n, selected: n === st2.limit }, n + ' per page'); }));
        D.mount(pager,
          h('span', null, st2.total ? D.fmt.num((st2.page - 1) * st2.limit + 1) + '–' + D.fmt.num(Math.min(st2.total, st2.page * st2.limit)) + ' of ' + D.fmt.num(st2.total) : '0 rows'),
          h('span', { class: 'grow' }), size,
          h('button', { class: 'dsk-btn sm icon', 'aria-label': 'Previous page', disabled: st2.page <= 1, onclick: function () { st2.page--; load(); } }, h('span', { style: { transform: 'rotate(180deg)', display: 'grid' } }, D.icon('chevron'))),
          h('span', null, 'Page ' + st2.page + ' of ' + pages),
          h('button', { class: 'dsk-btn sm icon', 'aria-label': 'Next page', disabled: st2.page >= pages, onclick: function () { st2.page++; load(); } }, D.icon('chevron')));
      }

      async function editRow(row) {
        var sc;
        try { sc = await loadSchema(); } catch (e) { D.toast('Could not read the table structure: ' + D.errorText(e), 'error'); return; }
        var cols = (sc.structure || []);
        var creating = !row, fields = {};
        var form = h('div', { class: 'dsk-stack', style: { gap: '4px' } }, cols.map(function (c) {
          var type = String(c.Type || '').toLowerCase(), long = /text|blob|json/.test(type);
          var v = creating ? '' : row[c.Field];
          var input = long ? h('textarea', { class: 'dsk-textarea mono', rows: 4, spellcheck: 'false' }) : h('input', { class: 'dsk-input mono', spellcheck: 'false' });
          input.value = v === null || v === undefined ? '' : String(v);
          if (!creating && v === null) input.placeholder = 'NULL';
          if (creating && c.Extra && /auto_increment/i.test(c.Extra)) input.placeholder = 'automatic';
          fields[c.Field] = { input: input, original: v };
          return h('div', { class: 'dsk-field' },
            h('label', null, c.Field, c.Key === 'PRI' ? h('span', { class: 'dsk-badge accent', style: { marginLeft: '8px' } }, 'key') : null),
            input, h('span', { class: 'hint mono' }, c.Type + (c.Null === 'YES' ? ' · nullable' : '')));
        }));
        function where() {
          var keys = cols.filter(function (c) { return c.Key === 'PRI'; }), w = {};
          keys.forEach(function (k) { if (row[k.Field] !== undefined) w[k.Field] = row[k.Field]; });
          return Object.keys(w).length ? w : row;
        }
        var actions = [{ label: 'Cancel', value: null }, { label: creating ? 'Add row' : 'Save changes', kind: 'primary', onClick: async function () {
          var data = {};
          Object.keys(fields).forEach(function (k) {
            var f = fields[k], val = f.input.value;
            if (creating) { if (val !== '') data[k] = val; }
            else if (!(f.original === null && val === '') && String(f.original) !== val) data[k] = val;
          });
          if (!Object.keys(data).length) { if (creating) { D.toast('Fill in at least one field.', 'error'); return false; } return true; }
          try {
            if (creating) await D.api.post('database/row/create', { table: t.table, data: data });
            else await D.api.post('database/row', { table: t.table, data: data, where: where() });
            D.toast(creating ? 'Row added.' : 'Row saved.');
            load();
            return true;
          } catch (e) { D.toast(D.errorText(e), 'error'); return false; }
        } }];
        if (!creating) actions.unshift({ label: 'Delete row', kind: 'danger', left: true, onClick: async function () {
          var ok = await D.confirm('Delete this row from ' + t.table + '? This can’t be undone.', { title: 'Delete row?', confirmLabel: 'Delete', danger: true });
          if (!ok) return false;
          try { await D.api.post('database/row/delete', { table: t.table, where: where() }); D.toast('Row deleted.'); load(); return true; }
          catch (e) { D.toast(D.errorText(e), 'error'); return false; }
        } });
        D.modal({ title: creating ? 'Add a row' : 'Edit row', wide: true, body: form, actions: actions });
      }
    }

    /* Structure */
    async function structureTab(t, body, loadSchema) {
      D.mount(body, h('div', { class: 'dsk-empty' }, h('span', { class: 'dsk-spin' })));
      try {
        var sc = await loadSchema(), s = sc.status || {}, cols = sc.structure || [];
        var heads = cols.length ? Object.keys(cols[0]) : [];
        D.mount(body, h('div', { class: 'dsk-scroll-fill' },
          h('div', { class: 'dsk-pad dsk-grid three' },
            stat('Rows', D.fmt.num(s.Rows)), stat('Engine', s.Engine || '—'), stat('Collation', s.Collation || '—')),
          h('div', { class: 'dsk-table-wrap' }, h('table', { class: 'dsk-table static' },
            h('thead', null, h('tr', null, heads.map(function (k) { return h('th', null, k); }))),
            h('tbody', null, cols.map(function (c) { return h('tr', null, heads.map(function (k) { return c[k] === null ? h('td', { class: 'null' }, 'NULL') : h('td', null, String(c[k])); })); }))))));
      } catch (e) { D.mount(body, h('div', { class: 'dsk-pad' }, D.note('error', 'Could not read the structure: ' + D.errorText(e)))); }
      function stat(label, v) { return h('div', { class: 'dsk-stat' }, h('div', { class: 'caps' }, label), h('div', { class: 'v', style: { fontSize: '22px', wordBreak: 'break-all' } }, v)); }
    }

    /* Export */
    function exportTab(t, body) {
      var btn = h('button', { class: 'dsk-btn primary lg', onclick: function () { exportBatch([t], btn); } }, D.icon('download'), 'Download ' + t.table + '.sql');
      D.mount(body, h('div', { class: 'dsk-empty' }, h('div', { style: { maxWidth: '420px' } }, h('div', { class: 'dsk-bw-spot', html: D.H.spot('db') }),
        h('h3', null, 'Export this table'), h('p', { style: { margin: '6px 0 16px' } }, D.fmt.num(t.row_count) + ' rows · ' + D.fmt.size(t.size) + '. The dump is generated on the server, then downloaded.'), btn)));
    }
  }

  /**
   * Exports tables as SQL the way the CLI does: small tables batched up to
   * 200 MB per dump, big tables split into parts of at most 200 MB / 1M rows.
   */
  async function exportBatch(tables, btn) {
    var queue = D.planDbExport(tables);
    var token = '', label = btn.lastChild, original = label.textContent;
    btn.disabled = true;
    try {
      token = await D.backupSession();
      for (var q = 0; q < queue.length; q++) {
        var job = queue[q];
        label.textContent = queue.length > 1 ? 'Exporting ' + (q + 1) + ' of ' + queue.length + '…' : 'Exporting…';
        var url = job.tables
          ? (job.tables.length === 1 && tables.length === 1
            ? await D.api.post('export/database/' + job.tables[0], { backup_token: token })
            : await D.api.post('export-database-batch', { backup_token: token, tables: job.tables }))
          : await D.api.post('export/database/' + job.table, { backup_token: token, parts: job.part, rows_per_part: job.rows });
        if (!url || typeof url !== 'string') throw new Error('The server returned no download link.');
        var name = url.slice(url.lastIndexOf('/') + 1).replace(/\.(db|sql)\.txt$/, '.sql');
        await D.downloadSiteFile(url, name, { stream: true, deleteAfter: true });
        if (q < queue.length - 1) await D.sleep(1500); // browsers throttle rapid downloads
      }
      D.toast('Export ready: ' + D.fmt.plural(queue.length, 'download') + '.');
    } catch (e) {
      D.toast('Export failed: ' + D.errorText(e), 'error');
    } finally {
      btn.disabled = false; label.textContent = original;
    }
  }
})();
