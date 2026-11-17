/**
 * Database: browse tables, page and sort rows, edit / add / delete rows,
 * inspect structure, and export one table or a batch as SQL.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h, S = D.store.state;

  D.section({ id: 'database', title: 'Database', icon: 'database', mount: mount, onShow: function () { if (!S.database.length) D.loadDatabase().catch(function (e) { D.toast('Could not read the database: ' + D.errorText(e), 'error'); }); } });

  function mount(root) {
    var st = { q: '', selected: [], anchor: null, tab: 'rows' };
    var listEl = h('div', { class: 'dsk-scroll-fill' });
    var detail = h('section');
    var allBox = h('span', { class: 'dsk-check', role: 'checkbox', tabindex: '0', 'aria-label': 'Select all visible tables', onclick: toggleAll });
    var countEl = h('span', { class: 'muted', style: { fontSize: '12.5px' } });
    var search = D.search('Search tables', function (v) { st.q = v; renderList(); });

    D.mount(root,
      h('div', { class: 'dsk-head' },
        h('div', null, h('h1', null, 'Database'), h('p', null, 'Browse and edit tables directly, or export them as SQL. Changes are written immediately.')),
        h('div', { class: 'dsk-head-tools' }, h('button', { class: 'dsk-btn', onclick: function (e) { D.busy(e.currentTarget, function () { return D.loadDatabase(true).catch(function (err) { D.toast(D.errorText(err), 'error'); }); }); } }, D.icon('refresh'), 'Refresh'))),
      h('div', { class: 'dsk-card dsk-split' },
        h('aside', null,
          h('div', { class: 'dsk-pad', style: { borderBottom: '1px solid var(--line)', display: 'grid', gap: '10px' } }, search,
            h('div', { class: 'dsk-row', style: { gap: '8px' } }, allBox, countEl)),
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
      countEl.textContent = S.database.length ? D.fmt.plural(rows.length, 'table') + (st.selected.length ? ' · ' + st.selected.length + ' selected' : '') : '';
      if (!S.database.length) { D.mount(listEl, h('div', { class: 'dsk-pad muted' }, h('span', { class: 'dsk-spin' }), ' Loading tables…')); return; }
      D.mount(listEl, h('ul', { class: 'dsk-list' }, rows.map(function (t) {
        var sel = st.selected.indexOf(t.table) !== -1;
        return h('li', { class: 'clickable' + (sel ? ' sel' : ''), tabindex: '0', onclick: function (e) { pick(t.table, e, rows); }, onkeydown: function (e) { if (e.key === 'Enter') pick(t.table, e, rows); } },
          h('span', { class: 'dsk-check' + (sel ? ' on' : ''), onclick: function (e) { e.stopPropagation(); pick(t.table, { metaKey: true, shiftKey: e.shiftKey }, rows); } }),
          h('span', { class: 'grow mono', style: { fontSize: '12.5px' } }, t.table),
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
    }
    function toggleAll() {
      var names = visible().map(function (t) { return t.table; });
      var all = names.every(function (n) { return st.selected.indexOf(n) !== -1; });
      st.selected = all ? st.selected.filter(function (n) { return names.indexOf(n) === -1; }) : Array.from(new Set(st.selected.concat(names)));
      renderList(); renderDetail();
    }
    function tableInfo(name) { return S.database.find(function (t) { return t.table === name; }) || { table: name, size: 0, row_count: 0 }; }

    /* ---------------------------------------------------------- Detail */
    function renderDetail() {
      if (!st.selected.length) {
        D.mount(detail, D.empty('Pick a table', S.database.length ? D.fmt.plural(S.database.length, 'table') + ' · ' + D.fmt.size(S.database.reduce(function (s, t) { return s + t.size; }, 0)) + '. Cmd- or Shift-click to select several for export.' : '', 'db'));
        return;
      }
      if (st.selected.length > 1) { batch(); return; }
      single(tableInfo(st.selected[0]));
    }

    function batch() {
      var tables = st.selected.map(tableInfo);
      var size = tables.reduce(function (s, t) { return s + t.size; }, 0), rows = tables.reduce(function (s, t) { return s + t.row_count; }, 0);
      var btn = h('button', { class: 'dsk-btn primary lg', onclick: function () { exportBatch(tables, btn); } }, D.icon('download'), 'Export ' + tables.length + ' tables');
      D.mount(detail, h('div', { class: 'dsk-empty' }, h('div', { style: { maxWidth: '420px' } },
        D.art('db'), h('h3', null, D.fmt.plural(tables.length, 'table') + ' selected'),
        h('div', { class: 'dsk-row', style: { justifyContent: 'center', margin: '10px 0 16px' } }, h('span', { class: 'dsk-badge' }, D.fmt.size(size)), h('span', { class: 'dsk-badge' }, D.fmt.num(rows) + ' rows')),
        h('p', { style: { marginBottom: '16px' } }, 'Downloads SQL dumps of the selected tables. Big tables are split into parts so no request times out.'), btn)));
    }

    function single(t) {
      var tabs = [{ id: 'rows', label: 'Rows' }, { id: 'structure', label: 'Structure' }, { id: 'export', label: 'Export' }];
      var body = h('div', { style: { flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' } });
      var tabBar = h('div', { class: 'dsk-tabs', role: 'tablist' });
      var schema = null;
      function loadSchema() {
        if (!schema) schema = D.api.get('database/schema', { table: t.table }).catch(function (e) { schema = null; throw e; });
        return schema;
      }
      function renderTabs() {
        D.mount(tabBar, tabs.map(function (x) {
          return h('button', { role: 'tab', 'aria-selected': String(st.tab === x.id), onclick: function () { st.tab = x.id; renderTabs(); renderTab(); } }, x.label);
        }));
      }
      function renderTab() {
        if (st.tab === 'rows') rowsTab(t, body, loadSchema);
        else if (st.tab === 'structure') structureTab(t, body, loadSchema);
        else exportTab(t, body);
      }
      D.mount(detail,
        h('div', { class: 'dsk-detail-head' }, h('div', { class: 'grow' }, h('h2', { class: 'mono', style: { fontFamily: 'var(--mono)', fontSize: '20px' } }, t.table),
          h('div', { class: 'path' }, D.fmt.num(t.row_count) + ' rows · ' + D.fmt.size(t.size)))),
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
          D.mount(tableWrap, D.empty('This table is empty', 'Add a row to get started.', 'db'));
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
      D.mount(body, h('div', { class: 'dsk-empty' }, h('div', { style: { maxWidth: '420px' } }, D.art('db'),
        h('h3', null, 'Export this table'), h('p', { style: { margin: '6px 0 16px' } }, D.fmt.num(t.row_count) + ' rows · ' + D.fmt.size(t.size) + '. The dump is generated on the server, then downloaded.'), btn)));
    }
  }

  /**
   * Exports tables as SQL the way the CLI does: small tables batched up to
   * 200 MB per dump, big tables split into parts of at most 200 MB / 1M rows.
   */
  async function exportBatch(tables, btn) {
    var MAX_SIZE = 200 * 1024 * 1024, MAX_ROWS = 1000000, queue = [], batch = [], batchSize = 0;
    tables.forEach(function (t) {
      if ((t.size > MAX_SIZE || t.row_count > MAX_ROWS) && t.row_count > 0) {
        if (batch.length) { queue.push({ tables: batch }); batch = []; batchSize = 0; }
        var parts = Math.max(Math.ceil(t.size / MAX_SIZE), Math.ceil(t.row_count / MAX_ROWS));
        for (var i = 1; i <= parts; i++) queue.push({ table: t.table, part: i, parts: parts, rows: Math.ceil(t.row_count / parts) });
      } else {
        if (batchSize + t.size > MAX_SIZE && batch.length) { queue.push({ tables: batch }); batch = []; batchSize = 0; }
        batch.push(t.table); batchSize += t.size;
      }
    });
    if (batch.length) queue.push({ tables: batch });
    var token = S.backupToken || D.randomHex(12), label = btn.lastChild, original = label.textContent;
    btn.disabled = true;
    try {
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
        D.download(url, name);
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
