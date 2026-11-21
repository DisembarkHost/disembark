/**
 * Harbor illustrations: the two-shore move scene and the small spot
 * pictures, drawn as inline SVG strings. Colours come from the .s-* classes
 * and the --water/--land/... tokens in admin.css, so they follow the theme.
 * D.H.harbor(opts) draws a scene; D.H.spot(kind) a 160x110 spot picture.
 */
(function () {
  'use strict';
  var K = window.Disembark.H = {};
  var f = function (n) { return Math.round(n * 10) / 10; };
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* ---------------------------------------------------------- pieces */
  K.tree = function (x, y, s) { s = s || 1; return '<g transform="translate(' + x + ' ' + y + ') scale(' + s + ')"><circle r="11" class="s-tree-2" cx="1.5" cy="2"/><circle r="10" class="s-tree"/><circle r="4" cx="-3" cy="-3" fill="rgba(255,255,255,.18)"/></g>'; };
  K.crate = function (x, y, fill) { return '<g transform="translate(' + x + ' ' + y + ')"><rect width="14" height="11" rx="2" fill="' + esc(fill) + '"/><path d="M2 5.5h10" stroke="rgba(255,255,255,.45)" stroke-width="1.4"/></g>'; };
  K.COLORS = ['#c8553d', '#2b8a7e', '#d9a441', '#3f78a8'];
  K.cargo = function (x, y, n) {
    n = n == null ? 4 : n; var out = '';
    for (var i = 0; i < n; i++) out += K.crate(x + (i % 2) * 16, y + Math.floor(i / 2) * 13, K.COLORS[i % 4]);
    return out;
  };
  K.warehouse = function (x, y, w, h, hot, tag) {
    var base = hot ? '#c8553d' : 'var(--roof-old)', top = hot ? '#b0472f' : 'var(--roof-old-2)';
    return '<rect x="' + (x + 3) + '" y="' + (y + 4) + '" width="' + w + '" height="' + h + '" rx="4" fill="rgba(0,0,0,.1)"/>' +
      '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="4" fill="' + base + '"/>' +
      '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + (h / 2) + '" rx="4" fill="' + top + '"/><rect x="' + x + '" y="' + (y + h / 2 - 4) + '" width="' + w + '" height="4" fill="' + top + '"/>' +
      '<path d="M' + x + ' ' + (y + h / 2) + 'h' + w + '" stroke="rgba(255,255,255,.3)" stroke-width="1.5"/>' +
      (tag ? '<text x="' + (x + w / 2) + '" y="' + (y + h / 2 - 6) + '" text-anchor="middle" class="s-tag">' + esc(tag) + '</text>' : '');
  };
  K.container = function (x, y, fill, tag) {
    return '<rect x="' + (x + 2) + '" y="' + (y + 3) + '" width="44" height="24" rx="3" fill="rgba(0,0,0,.1)"/><rect x="' + x + '" y="' + y + '" width="44" height="24" rx="3" fill="' + esc(fill) + '"/>' +
      '<path d="M' + (x + 11) + ' ' + (y + 4) + 'v16M' + (x + 22) + ' ' + (y + 4) + 'v16M' + (x + 33) + ' ' + (y + 4) + 'v16" stroke="rgba(0,0,0,.14)" stroke-width="1.5"/>' +
      (tag ? '<text x="' + (x + 22) + '" y="' + (y + 15.5) + '" text-anchor="middle" class="s-tag">' + esc(tag) + '</text>' : '');
  };
  K.check = function (x, y, r) { r = r || 12; var s = r / 12; return '<g transform="translate(' + x + ' ' + y + ') scale(' + s + ')"><circle r="12" fill="#2b8a7e" stroke="#fff" stroke-width="2.5"/><path d="M-5 .5l3.5 3.5 7-7.5" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></g>'; };
  K.sign = function (cx, y, text) {
    text = String(text || '');
    var w = Math.max(80, text.length * 7 + 26);
    text = esc(text);
    return '<g transform="translate(' + cx + ' ' + y + ')"><rect x="' + (-w / 2) + '" y="-11" width="' + w + '" height="22" rx="11" class="s-sign"/><text y="4" text-anchor="middle" class="s-label">' + text + '</text></g>';
  };
  K.buoy = function (x, y, fill) { return '<g transform="translate(' + x + ' ' + y + ')"><circle r="9" fill="rgba(255,255,255,.35)"/><circle r="6.5" fill="' + esc(fill || '#c8553d') + '" stroke="#fff" stroke-width="2"/><path d="M-6 0h12" stroke="#fff" stroke-width="2"/></g>'; };
  K.pier = function (x, y, len, dir) {
    // a wooden jetty from (x,y) toward +x (dir 1) or -x (dir -1), 14 tall
    var x0 = dir > 0 ? x : x - len, out = '<rect x="' + x0 + '" y="' + (y - 7) + '" width="' + len + '" height="14" class="s-wood"/>';
    for (var p = 8; p < len; p += 8) out += '<path d="M' + (x0 + p) + ' ' + (y - 7) + 'v14" class="s-plank"/>';
    var end = dir > 0 ? x + len - 3 : x - len + 3;
    out += '<circle cx="' + end + '" cy="' + (y - 8) + '" r="2.6" class="s-post"/><circle cx="' + end + '" cy="' + (y + 8) + '" r="2.6" class="s-post"/>';
    return out;
  };
  /* Top-down boat pointing along +x, centered at 0,0; n crates on deck. */
  K.boat = function (x, y, deg, n, s) {
    s = s || 1;
    var deck = '';
    for (var i = 0; i < (n == null ? 4 : n); i++) deck += '<rect x="' + (-12 + Math.floor(i / 2) * 11) + '" y="' + (i % 2 ? 0.5 : -8.5) + '" width="9.5" height="8" rx="1.6" fill="' + K.COLORS[i % 4] + '"/>';
    return '<g transform="translate(' + f(x) + ' ' + f(y) + ') rotate(' + f(deg) + ') scale(' + s + ')"><g class="s-bob">' +
      '<path d="M-48 -4q-8 -6 -16 -3M-48 4q-8 6 -16 3" class="s-wake"/>' +
      '<path d="M-30 -12H14Q32 -12 38 0Q32 12 14 12H-30Q-34 12 -34 8V-8Q-34 -12 -30 -12Z" class="s-hull"/>' +
      '<path d="M-28 -9H13Q27 -9 32 0Q27 9 13 9H-28Q-31 9 -31 6V-6Q-31 -9 -28 -9Z" class="s-deck"/>' +
      '<rect x="-29" y="-6.5" width="13" height="13" rx="2.5" class="s-cabin"/><rect x="-25" y="-4" width="5" height="8" rx="1.5" fill="var(--sea)" opacity=".6"/>' +
      deck + '</g></g>';
  };
  K.lighthouse = function (x, y, lit, s) {
    s = s || 1;
    return '<g transform="translate(' + x + ' ' + y + ') scale(' + s + ')">' +
      (lit ? '<path d="M0 -48 L-90 -70 L-90 -26Z" class="s-beam"/><path d="M0 -48 L90 -70 L90 -26Z" class="s-beam"/>' : '') +
      '<path d="M-11 0 L-7 -42 H7 L11 0Z" fill="#f4efe6"/><path d="M-10 -12 H10 L9.3 -20 H-9.3Z M-8.4 -30 H8.4 L7.7 -37 H-7.7Z" fill="#c8553d"/>' +
      '<rect x="-8" y="-53" width="16" height="11" rx="2" fill="var(--hull)"/><rect x="-5" y="-51" width="10" height="7" rx="1.5" class="' + (lit ? 's-light' : 's-cabin') + '"/>' +
      '<path d="M-10 -53 L0 -61 L10 -53Z" fill="var(--hull)"/><rect x="-16" y="-2" width="32" height="5" rx="2" fill="var(--roof-old-2)"/></g>';
  };
  K.waves = function (pts) {
    return '<g class="s-waves">' + pts.map(function (p) { return '<path class="s-wave" d="M' + p[0] + ' ' + p[1] + 'q5 -5 10 0t10 0"/>'; }).join('') + '</g>';
  };

  /* ---------------------------------------------------------- installs */
  // kind: site | network | freighter | zip | blank. cx = centre x, top = y of the building area.
  K.install = function (kind, cx, top, o) {
    o = o || {}; var out = '';
    if (kind === 'site') {
      out += K.warehouse(cx - 56, top + 20, 112, 78, o.hot !== false);
      if (o.arrived) out += K.check(cx + 50, top + 24);
    } else if (kind === 'network') {
      out += K.warehouse(cx - 52, top, 104, 50, false, 'MAIN SITE');
      [cx - 78, cx - 22, cx + 34].forEach(function (x, i) {
        var pick = o.pick === i;
        if (o.newSlot && i === 2) {
          out += o.arrived ? K.warehouse(x, top + 74, 46, 38, true) + K.check(x + 42, top + 76, 10) : '<rect x="' + x + '" y="' + (top + 74) + '" width="46" height="38" rx="4" class="s-slot"/>';
        } else out += K.warehouse(x, top + 74, 46, 38, pick);
      });
    } else if (kind === 'freighter') {
      out += '<path d="M' + (cx + 74) + ' ' + (top + 118) + ' V' + (top - 2) + ' M' + (cx + 74) + ' ' + (top + 2) + ' H' + (cx - 30) + ' M' + (cx - 20) + ' ' + (top + 2) + ' v14" stroke="var(--post)" stroke-width="4" fill="none" stroke-linecap="round"/>';
      var cols = [cx - 80, cx - 32, cx + 16], rows = [top + 92, top + 64, top + 36], n = 0;
      rows.forEach(function (y, r) {
        cols.forEach(function (x, c) {
          n++;
          if (r === 2 && c === 2) {
            if (o.newSlot) out += o.arrived ? K.container(x, y, '#c8553d') + K.check(x + 42, y, 10) : '<rect x="' + x + '" y="' + y + '" width="44" height="24" rx="3" class="s-slot"/>';
            return;
          }
          var hot = o.pick === n;
          out += K.container(x, y, hot ? '#c8553d' : (n % 2 ? 'var(--roof-old)' : 'var(--roof-old-2)'), r === 0 && c === 0 ? 'MAIN' : '');
        });
      });
    } else if (kind === 'zip') {
      // a sealed crate on a pallet: a backup zip
      out += '<rect x="' + (cx - 46) + '" y="' + (top + 92) + '" width="92" height="8" rx="2" class="s-wood"/>' +
        '<rect x="' + (cx - 40) + '" y="' + (top + 34) + '" width="80" height="58" rx="5" fill="#d9a441"/><path d="M' + (cx - 40) + ' ' + (top + 63) + 'h80M' + cx + ' ' + (top + 34) + 'v58" stroke="rgba(0,0,0,.18)" stroke-width="3"/>' +
        '<rect x="' + (cx - 16) + '" y="' + (top + 52) + '" width="32" height="22" rx="3" fill="#fbf7ef"/><text x="' + cx + '" y="' + (top + 67) + '" text-anchor="middle" style="font:600 9px var(--mono);fill:#7a5a3a">.zip</text>';
    }
    return out;
  };

  /* ---------------------------------------------------------- the harbor scene
     opts: { w:1000, h:300, left:{kind,label,pick,newSlot,arrived}, right:{...},
             t: boat position 0..1 (null = moored left), you:'left'|'right', youText,
             night: bool, compact: bool } */
  K.harbor = function (o) {
    var W = o.w || 1000, H = o.h || 300, mid = H * 0.62, L = o.left || {}, R = o.right || {};
    var lx = W * 0.3, rx = W * 0.7;
    var out = '<svg viewBox="0 0 ' + W + ' ' + H + '" class="harbor" role="img" aria-label="' + esc(o.alt || 'Harbor scene') + '">';
    out += '<rect width="' + W + '" height="' + H + '" class="s-water"/>';
    if (o.night) out += '<rect width="' + W + '" height="' + H + '" fill="var(--night)" opacity=".55"/><circle cx="' + (W * .5) + '" cy="46" r="26" fill="#f4efe6" opacity=".08"/><circle cx="' + (W * .5) + '" cy="46" r="14" fill="#f4efe6" opacity=".92"/>';
    var shoreL = 'M0 0H' + (lx - 10) + 'C' + (lx + 12) + ' ' + (H * .2) + ' ' + (lx - 16) + ' ' + (H * .42) + ' ' + lx + ' ' + (H * .6) + 'S' + (lx - 14) + ' ' + (H * .86) + ' ' + (lx + 6) + ' ' + H + 'H0Z';
    var shoreR = 'M' + W + ' 0H' + (rx + 10) + 'C' + (rx - 12) + ' ' + (H * .2) + ' ' + (rx + 16) + ' ' + (H * .42) + ' ' + rx + ' ' + (H * .6) + 'S' + (rx + 14) + ' ' + (H * .86) + ' ' + (rx - 6) + ' ' + H + 'H' + W + 'Z';
    [shoreL, shoreR].forEach(function (d) {
      out += '<path d="' + d + '" class="s-shallow" stroke="var(--shallow)" stroke-width="26" stroke-linejoin="round"/>';
      out += '<path d="' + d + '" class="s-sand" stroke="var(--sand)" stroke-width="8" stroke-linejoin="round"/>';
      out += '<path d="' + d + '" class="s-land"/>';
    });
    out += K.waves([[W * .42, H * .2], [W * .55, H * .3], [W * .47, H * .82], [W * .6, H * .7], [W * .38, H * .5]]);
    // trees
    [[.04, .12], [.25, .1], [.05, .88], [.23, .9], [.96, .12], [.76, .1], [.95, .88], [.78, .9]].forEach(function (p) { out += K.tree(W * p[0], H * p[1], o.compact ? .8 : 1); });
    // piers
    var pl = lx + 4, pr = rx - 4, plen = W * .07;
    out += K.pier(pl - 6, mid, plen, 1) + K.pier(pr + 6, mid, plen, -1);
    var a = [pl + plen + 4, mid], d = [pr - plen - 4, mid];
    var b = [W * .45, mid - H * .38], c = [W * .55, mid - H * .38];
    var path = 'M' + a.join(' ') + 'C' + b.join(' ') + ' ' + c.join(' ') + ' ' + d.join(' ');
    out += '<path d="' + path + '" class="s-route"/>';
    var t = o.t;
    if (t != null && t > 0) out += '<path d="' + path + '" class="s-route-done" pathLength="1" stroke-dasharray="' + t + ' 1"/>';
    // installs
    var top = o.compact ? H * .14 : H * .16;
    out += K.install(L.kind || 'site', W * .15, top, L) + K.install(R.kind || 'site', W * .85, top, R);
    if (L.label) out += K.sign(W * .15, H - 26, L.label);
    if (R.label) out += K.sign(W * .85, H - 26, R.label);
    if (o.you) {
      var ycx = o.you === 'left' ? W * .15 : W * .85, txt = String(o.youText || 'You are here');
      var tw = txt.length * 6.6 + 24;
      out += '<g transform="translate(' + ycx + ' ' + (top - 16) + ')"><rect x="' + (-tw / 2) + '" y="-12" width="' + tw + '" height="24" rx="12" fill="var(--accent)"/><text y="4.5" text-anchor="middle" style="font:600 11.5px var(--sans);fill:var(--accent-ink)">' + esc(txt) + '</text></g>';
    }
    if (o.buoys) o.buoys.forEach(function (bp) { out += K.buoy(W * bp[0], H * bp[1]); });
    // boat
    var bt = t == null ? 0 : t, p = K.bez([a, b, c, d], bt), s = K.bezSlope([a, b, c, d], bt);
    var deg = Math.atan2(s[1], s[0]) * 180 / Math.PI;
    if (t == null || t <= 0) { p = [a[0] + 30, mid + 26]; deg = 0; }
    if (t >= 1) { p = [d[0] - 30, mid + 26]; deg = 0; }
    out += '<g class="boat-slot">' + K.boat(p[0], p[1], deg, o.cargo, o.compact ? .85 : 1) + '</g>';
    out += '</svg>';
    return out;
  };
  K.bez = function (P, t) { var u = 1 - t; return [0, 1].map(function (i) { return u * u * u * P[0][i] + 3 * u * u * t * P[1][i] + 3 * u * t * t * P[2][i] + t * t * t * P[3][i]; }); };
  K.bezSlope = function (P, t) { var u = 1 - t; return [0, 1].map(function (i) { return 3 * u * u * (P[1][i] - P[0][i]) + 6 * u * t * (P[2][i] - P[1][i]) + 3 * t * t * (P[3][i] - P[2][i]); }); };

  /* Animate a harbor in el: the boat loops between from..to (0..1). */
  K.sail = function (el, opts, from, to, ms) {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) { el.innerHTML = K.harbor(Object.assign({}, opts, { t: to })); return; }
    var start = null;
    function frame(now) {
      if (start == null) start = now;
      var k = ((now - start) % ms) / ms, e = k < .5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;
      el.innerHTML = K.harbor(Object.assign({}, opts, { t: from + (to - from) * e }));
      requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  };

  /* ---------------------------------------------------------- spot illustrations (160x110) */
  K.spot = function (kind) {
    var o = '<svg viewBox="0 0 160 110" class="spot" aria-hidden="true"><rect width="160" height="110" rx="16" class="s-water"/>';
    if (kind === 'quiet') {
      o += '<rect width="160" height="110" rx="16" fill="var(--night)" opacity=".6"/><circle cx="128" cy="24" r="15" fill="#f4efe6" opacity=".08"/><circle cx="128" cy="24" r="8" fill="#f4efe6"/>' +
        '<path d="M0 82 Q40 70 80 80 T160 78 V110 H0Z" class="s-land"/>' + K.lighthouse(52, 84, true, .9) + K.boat(112, 92, 0, 2, .6);
    } else if (kind === 'files') {
      o += '<path d="M0 0 H160 V44 Q120 52 80 46 T0 48Z" class="s-land"/>' + K.warehouse(18, 8, 70, 34, true) + '<rect x="44" y="26" width="18" height="16" rx="2" fill="var(--post)"/>' +
        K.cargo(98, 14) + K.cargo(128, 20, 2) + K.pier(60, 60, 26, 1) + K.boat(110, 84, -8, 4, .75) + K.waves([[20, 86], [70, 96]]);
    } else if (kind === 'db') {
      var barrel = function (x, y, c) { return '<g transform="translate(' + x + ' ' + y + ')"><rect x="-9" y="-12" width="18" height="24" rx="5" fill="' + c + '"/><path d="M-9 -4h18M-9 4h18" stroke="rgba(0,0,0,.18)" stroke-width="1.6"/><ellipse cy="-12" rx="9" ry="3" fill="rgba(255,255,255,.3)"/></g>'; };
      o += '<path d="M0 0 H160 V56 Q120 64 80 58 T0 60Z" class="s-land"/>' + barrel(30, 36, '#2b8a7e') + barrel(52, 36, '#3f78a8') + barrel(74, 36, '#2b8a7e') + barrel(41, 17, '#3f78a8') + barrel(63, 17, '#2b8a7e') +
        K.warehouse(100, 16, 46, 30, false) + K.pier(84, 66, 22, 1) + K.boat(116, 88, 6, 0, .7) + '<g transform="translate(116 88) rotate(6) scale(.7)">' + barrel(-2, 0, '#3f78a8').replace('translate(-2 0)', 'translate(-2 0) scale(.55)') + '</g>' + K.waves([[20, 90], [60, 98]]);
    } else if (kind === 'done') {
      o += '<path d="M90 0 H160 V110 H96 Q82 80 94 55 T90 0Z" class="s-land"/>' + K.warehouse(112, 26, 38, 30, true) + K.check(146, 28, 9) + K.pier(98, 70, 20, -1) + K.boat(56, 84, 0, 4, .75) +
        '<path d="M40 70 v-22" stroke="var(--hull)" stroke-width="2"/><path d="M40 48 l14 5 -14 5z" fill="#c8553d"/>' + K.waves([[14, 30], [40, 22]]);
    } else if (kind === 'points') {
      o += K.waves([[20, 30], [110, 22], [70, 92]]) + '<path d="M14 64 C50 40 100 88 146 58" class="s-route"/>' + K.buoy(30, 56) + K.buoy(64, 58, '#d9a441') + K.buoy(98, 70, '#2b8a7e') + K.buoy(134, 62);
    } else if (kind === 'zip') {
      o += '<path d="M0 0 H160 V58 Q120 66 80 60 T0 62Z" class="s-land"/>' + K.install('zip', 80, -18) + K.waves([[24, 86], [100, 96]]);
    } else {
      o += '<path d="M0 0 H160 V36 Q120 44 80 38 T0 40Z" class="s-land"/>' + K.pier(50, 52, 20, 1) + K.boat(92, 74, 0, 4, .8) + K.waves([[20, 90], [120, 96]]);
    }
    return o + '</svg>';
  };


  /**
   * The move scene: a harbor sized for the card (wide on desktop, taller on
   * a phone), FROM/TO captions over the host signs, an arrow at the
   * destination end of the route, and crates waiting on a pier.
   * cfg: { harbor: { left, right, you, t, cargo, ... }, arrow, dock: 'left' | 'right' }
   */
  var phone = window.matchMedia ? window.matchMedia('(max-width: 600px)') : { matches: false };
  K.phone = phone;
  K.scene = function (cfg) {
    var compact = phone.matches, W = compact ? 520 : 1000, H = compact ? 380 : 250;
    var o = Object.assign({ w: W, h: H, compact: compact }, cfg.harbor);
    var shorten = function (side, isHere) { return Object.assign({}, side, { label: compact && isHere ? 'this site' : side.label }); };
    o.left = shorten(o.left, o.you === 'left');
    o.right = shorten(o.right, o.you === 'right');
    var svg = K.harbor(o);
    // Drop the bottom trees that would peek out from behind the host signs.
    [[W * .15, o.left.label], [W * .85, o.right.label]].forEach(function (sg) {
      var half = Math.max(80, String(sg[1] || '').length * 7 + 26) / 2 + 14;
      svg = svg.replace(/<g transform="translate\(([\d.]+) ([\d.]+)\) scale\([\d.]+\)"><circle r="11" class="s-tree-2"[^]*?<\/g>/g, function (m, x, y) {
        return (+y > H * .8 && Math.abs(+x - sg[0]) < half) ? '' : m;
      });
    });
    var lx = W * .3, rx = W * .7, mid = H * .62, plen = W * .07, pl = lx + 4, pr = rx - 4;
    var a = [pl + plen + 4, mid], d = [pr - plen - 4, mid], b = [W * .45, mid - H * .38], c = [W * .55, mid - H * .38];
    var ex = '<text x="' + W * .15 + '" y="' + (H - 45) + '" text-anchor="middle" style="font:600 9.5px var(--mono);letter-spacing:.16em;fill:var(--ink-3)">FROM</text>' +
      '<text x="' + W * .85 + '" y="' + (H - 45) + '" text-anchor="middle" style="font:600 9.5px var(--mono);letter-spacing:.16em;fill:var(--ink-3)">TO</text>';
    if (cfg.arrow) {
      var sl = K.bezSlope([a, b, c, d], 1), deg = Math.atan2(sl[1], sl[0]) * 180 / Math.PI;
      ex += '<g transform="translate(' + d[0] + ' ' + d[1] + ') rotate(' + deg.toFixed(1) + ')"><path d="M-9 -6 L0 0 L-9 6" fill="none" stroke="var(--accent)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" opacity=".8"/></g>';
    }
    var crates = function (x0, y) { var out = ''; for (var i = 0; i < 4; i++) out += K.crate(x0 + i * 16, y - 5.5, K.COLORS[i]); return out; };
    if (cfg.dock === 'left') ex += crates(pl, mid) + K.cargo(lx - 64, mid - 34, 4);
    if (cfg.dock === 'right') ex += crates(pr - plen + 12, mid) + K.cargo(rx + 34, mid - 34, 4);
    return svg.replace('</svg>', ex + '</svg>');
  };

  /** A small round buoy, for restore points. */
  K.buoyIcon = function (fill) { return '<svg viewBox="-12 -12 24 24" aria-hidden="true">' + K.buoy(0, 0, fill) + '</svg>'; };
})();
