/**
 * Browse: this site's files and database tables in one tab, switched with
 * a segmented control. The Files and Database sections (files.js,
 * database.js) register as panes and mount the first time they're shown.
 * Each pane puts its own tools (search, rescan, search and replace) in the
 * bar beside the switch: D.browseTools(id) is that pane's slot.
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h;

  D.section({ id: 'browse', title: 'Browse', icon: 'folder', tab: 3, mount: mount, onRoute: route, onShow: function () { if (active && active.onShow) active.onShow(); } });

  var PANES = [{ id: 'files', label: 'Files', icon: 'folder' }, { id: 'database', label: 'Database', icon: 'database' }];
  var mode = 'files', boxes = {}, mounted = {}, active = null, segBox = null, spotBox = null, ready = false;
  var tools = {};
  PANES.forEach(function (p) { tools[p.id] = h('div', { class: 'dsk-bw-tools', hidden: true }); });
  D.browseTools = function (id) { return tools[id]; };

  function def(id) { return D.sections.filter(function (s) { return s.id === id; })[0]; }
  function route(asked) { if (asked === 'files' || asked === 'database') { mode = asked; if (ready) select(); } }

  function mount(root) {
    spotBox = h('div', { html: D.H.spot('files') });
    segBox = h('div');
    PANES.forEach(function (p) { boxes[p.id] = h('div', { hidden: true }); });
    D.mount(root,
      h('div', { class: 'dsk-browse' },
        D.pageHead({ caps: 'Browse', icon: 'folder', title: ['Everything aboard, ', 'crate by crate.'],
          text: 'Look through this site’s files and database tables, see what takes the space, and fix old addresses with search and replace.', side: spotBox }),
        h('div', { class: 'dsk-bw-top' }, segBox, PANES.map(function (p) { return tools[p.id]; })),
        PANES.map(function (p) { return boxes[p.id]; })));
    ready = true;
    select();
  }

  function select() {
    // Made once, so the button pressed keeps focus; a route change only updates it.
    if (!segBox.seg) { segBox.seg = D.seg(PANES.map(function (p) { return { value: p.id, label: p.label, icon: p.icon }; }), mode, function (v) { mode = v; select(); }); D.mount(segBox, segBox.seg); }
    else segBox.seg.set(mode);
    spotBox.innerHTML = D.H.spot(mode === 'files' ? 'files' : 'db');
    PANES.forEach(function (p) { boxes[p.id].hidden = tools[p.id].hidden = p.id !== mode; });
    var d = def(mode);
    active = d;
    if (d && !mounted[mode]) { mounted[mode] = true; d.mount(boxes[mode]); }
    if (d && d.onShow) d.onShow();
  }
})();
