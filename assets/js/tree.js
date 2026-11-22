/**
 * File tree. Renders only open folders from the shared index, so a site with
 * hundreds of thousands of files stays responsive.
 *   mode 'exclude': each row has an include/exclude toggle (shift-click a
 *                   second toggle to apply it to the whole range).
 *   mode 'browse':  clicking a row selects it (onSelect).
 */
(function () {
  'use strict';
  var D = window.Disembark, h = D.h;
  var PAGE = 400;
  var CODE_EXT = /\.(php|js|mjs|ts|css|scss|json|html?|xml|svg|txt|md|ya?ml|ini|sql|sh|htaccess|log|conf|twig|vue|jsx|tsx)$/i;
  var IMG_EXT = /\.(png|jpe?g|gif|webp|bmp|ico|avif)$/i;

  D.fileIcon = function (node) {
    if (node.dir) return D.icon('folder', 'ic folder');
    if (IMG_EXT.test(node.name)) return D.icon('image', 'ic');
    if (CODE_EXT.test(node.name)) return D.icon('code', 'ic');
    if (/\.zip$/i.test(node.name)) return D.icon('zip', 'ic');
    return D.icon('file', 'ic');
  };

  D.Tree = function (opts) {
    var el = h('div', { class: 'dsk-tree', role: 'tree' });
    var open = new Set(), shown = new Map(), anchor = null, selected = opts.selected || null, order = [];

    function index() { return D.store.state.index; }

    function toggleOpen(node) {
      if (open.has(node.path)) open.delete(node.path); else open.add(node.path);
      render();
    }
    function setExcluded(paths, exclude) {
      var ex = new Set(D.store.state.excluded);
      paths.forEach(function (p) { if (exclude) ex.add(p); else ex.delete(p); });
      D.store.set({ excluded: ex });
    }
    function onToggle(node, e) {
      e.stopPropagation();
      var exclude = !D.store.state.excluded.has(node.path);
      if (e.shiftKey && anchor && anchor !== node.path) {
        var a = order.indexOf(anchor), b = order.indexOf(node.path);
        if (a !== -1 && b !== -1) {
          setExcluded(order.slice(Math.min(a, b), Math.max(a, b) + 1), exclude);
          anchor = node.path;
          return;
        }
      }
      anchor = node.path;
      setExcluded([node.path], exclude);
    }
    function activate(node) {
      if (opts.mode === 'browse') {
        selected = node.path;
        if (opts.onSelect) opts.onSelect(node);
        if (node.dir && !open.has(node.path)) open.add(node.path);
        render();
      } else if (node.dir) toggleOpen(node);
    }

    function row(node, depth) {
      var isOpen = node.dir && open.has(node.path);
      var excludedSelf = D.store.state.excluded.has(node.path);
      var off = opts.mode === 'exclude' && D.isExcluded(node.path);
      var inherited = off && !excludedSelf;
      var r = h('div', {
        class: ['dsk-tree-row', isOpen && 'open', off && 'off', selected === node.path && 'sel', opts.mode === 'exclude' && anchor === node.path && 'anchor'],
        role: 'treeitem', tabindex: '0', 'data-path': node.path, 'aria-expanded': node.dir ? String(isOpen) : null, 'aria-selected': opts.mode === 'browse' ? String(selected === node.path) : null,
        style: { paddingLeft: (6 + depth * 16) + 'px' }, title: node.path,
        onclick: function () { activate(node); },
        onkeydown: function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(node); }
          else if (e.key === 'ArrowRight' && node.dir && !isOpen) { e.preventDefault(); toggleOpen(node); }
          else if (e.key === 'ArrowLeft' && node.dir && isOpen) { e.preventDefault(); toggleOpen(node); }
          else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var sib = e.key === 'ArrowDown' ? r.nextElementSibling : r.previousElementSibling;
            if (sib && sib.classList.contains('dsk-tree-row')) sib.focus();
          }
        }
      },
        h('span', { class: 'tw', onclick: function (e) { if (node.dir) { e.stopPropagation(); toggleOpen(node); } } }, node.dir ? D.icon('chevron') : null),
        D.fileIcon(node),
        h('span', { class: 'nm' }, node.name),
        h('span', { class: 'sz' }, node.dir ? D.fmt.size(node.size) + ' · ' + D.fmt.num(node.files) : D.fmt.size(node.size)),
        opts.mode === 'exclude' ? h('button', {
          type: 'button', class: 'tog', disabled: inherited,
          title: inherited ? 'Excluded with its folder' : (off ? 'Excluded: click to include (shift-click for a range)' : 'Included: click to exclude (shift-click for a range)'),
          'aria-label': (off ? 'Include ' : 'Exclude ') + node.path,
          onclick: function (e) { onToggle(node, e); }
        }, D.icon(off ? 'xCircle' : 'checkCircle')) : null
      );
      return r;
    }

    function walk(nodes, depth, out) {
      var limit = shown.get(nodes) || PAGE;
      nodes.slice(0, limit).forEach(function (n) {
        out.push(row(n, depth));
        order.push(n.path);
        if (n.dir && open.has(n.path)) walk(n.kids, depth + 1, out);
      });
      if (nodes.length > limit) {
        out.push(h('div', { class: 'dsk-tree-more', style: { paddingLeft: (28 + depth * 16) + 'px' } },
          D.fmt.num(nodes.length - limit) + ' more · ',
          h('a', { href: '#', onclick: function (e) { e.preventDefault(); shown.set(nodes, limit + PAGE * 5); render(); } }, 'show more')));
      }
    }
    function render() {
      var idx = index();
      order = [];
      if (!idx) { D.mount(el); return; }
      var rows = [];
      walk(idx.root.kids, 0, rows);
      // The rows are made anew, so the one with focus (or its toggle) gets
      // it back: keyboard users stay where they were.
      var act = document.activeElement, fRow = act && el.contains(act) ? act.closest('.dsk-tree-row') : null;
      var fPath = fRow ? fRow.getAttribute('data-path') : null, fTog = !!(act && act.classList && act.classList.contains('tog'));
      var scrollParent = el.parentElement, top = scrollParent ? scrollParent.scrollTop : 0;
      D.mount(el, rows);
      if (scrollParent) scrollParent.scrollTop = top;
      if (fPath !== null) {
        var back = Array.prototype.find.call(el.querySelectorAll('.dsk-tree-row'), function (x) { return x.getAttribute('data-path') === fPath; });
        if (back) ((fTog && back.querySelector('.tog:not([disabled])')) || back).focus({ preventScroll: true });
      }
    }

    var scope = opts.scope || D.scope();
    scope.on(['index'], function () { open.clear(); shown.clear(); selected = null; anchor = null; render(); });
    if (opts.mode === 'exclude') scope.on(['excluded'], render);
    render();

    return {
      el: el,
      render: render,
      /** Opens the folders leading to path and selects it. */
      reveal: function (path) {
        var parts = path.split('/'), p = '';
        for (var i = 0; i < parts.length - 1; i++) { p = i ? p + '/' + parts[i] : parts[i]; open.add(p); }
        selected = path;
        render();
        var target = el.querySelector('.dsk-tree-row.sel');
        if (target) target.scrollIntoView({ block: 'nearest' });
      }
    };
  };
})();
