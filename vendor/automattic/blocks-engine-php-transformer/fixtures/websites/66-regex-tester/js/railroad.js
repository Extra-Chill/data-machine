/* =========================================================
   RELAY — Railroad diagram
   Renders the parsed regex AST as an SVG railroad / syntax
   diagram. Each AST node knows how to measure itself
   (width/height + connection y) and how to draw itself at a
   given (x,y) offset. Sequences flow left-to-right on a line;
   alternations stack vertically and fan out; quantifiers draw
   a loop-back rail; groups draw a labelled dashed box.

   This is a real two-pass layout: measure() then draw().
   ========================================================= */

(function (global) {
  'use strict';

  var NS = 'http://www.w3.org/2000/svg';
  var H = 34;          // base lane height
  var VGAP = 16;       // vertical gap between alternation branches
  var HPAD = 14;       // horizontal padding inside boxes
  var ARC = 10;        // corner radius for rails
  var FONT = 13;

  function el(name, attrs, text) {
    var n = document.createElementNS(NS, name);
    for (var k in attrs) n.setAttribute(k, attrs[k]);
    if (text != null) n.textContent = text;
    return n;
  }

  // crude monospace-ish text width
  function textW(s) { return Math.max(8, s.length * 8.2) + HPAD * 2; }

  /* ---------- measure ---------- */
  function measure(node) {
    switch (node.type) {
      case 'sequence': return measureSeq(node);
      case 'alternation': return measureAlt(node);
      case 'quantifier': return measureQuant(node);
      case 'group': return measureGroup(node);
      default: return measureTerminal(node);
    }
  }

  function measureTerminal(node) {
    var label = terminalLabel(node);
    return { node: node, kind: 'terminal', label: label.text, cls: label.cls,
             w: textW(label.text), h: H, cy: H / 2 };
  }

  function measureSeq(node) {
    if (!node.items.length) {
      return { node: node, kind: 'skip', w: 30, h: H, cy: H / 2, children: [] };
    }
    var kids = node.items.map(measure);
    var w = 0, h = 0;
    kids.forEach(function (k) { w += k.w + 18; h = Math.max(h, k.h); });
    w -= 18;
    return { node: node, kind: 'seq', w: w, h: h, cy: h / 2, children: kids };
  }

  function measureAlt(node) {
    var kids = node.options.map(measure);
    var inner = 0, h = 0;
    kids.forEach(function (k) { inner = Math.max(inner, k.w); h += k.h + VGAP; });
    h -= VGAP;
    var w = inner + 56; // room for fan-out rails
    return { node: node, kind: 'alt', w: w, h: h, cy: kids[0].h / 2, children: kids, inner: inner };
  }

  function measureQuant(node) {
    var c = measure(node.child);
    // extra height below for the loop rail
    var h = c.h + 20;
    return { node: node, kind: 'quant', w: c.w + 28, h: h, cy: c.cy, child: c };
  }

  function measureGroup(node) {
    var c = measure(node.body);
    var labelH = 16;
    return { node: node, kind: 'group', w: c.w + HPAD * 2 + 8, h: c.h + labelH + 12,
             cy: labelH + 6 + c.cy, child: c };
  }

  function terminalLabel(node) {
    switch (node.type) {
      case 'literal': return { text: node.value === ' ' ? '␣' : node.raw, cls: 'rr-lit' };
      case 'any': return { text: 'any char', cls: 'rr-meta' };
      case 'anchor':
        return { text: { start: '^ start', end: 'end $', wordB: '\\b', nonWordB: '\\B' }[node.kind], cls: 'rr-anchor' };
      case 'escape':
        if (node.kind === 'class') return { text: '\\' + node.shorthand + ' ' + node.label, cls: 'rr-meta' };
        return { text: node.raw, cls: 'rr-meta' };
      case 'backref': return { text: node.byName ? '\\k<' + node.ref + '>' : '\\' + node.ref, cls: 'rr-ref' };
      case 'charclass': return { text: classLabel(node), cls: 'rr-class' };
      default: return { text: node.raw || '?', cls: 'rr-lit' };
    }
  }

  function classLabel(node) {
    var s = node.parts.map(function (p) {
      if (p.kind === 'range') return p.from + '-' + p.to;
      if (p.kind === 'class') return '\\' + p.shorthand;
      return p.value;
    }).join('');
    return '[' + (node.negated ? '^' : '') + s + ']';
  }

  /* ---------- draw ---------- */
  function draw(m, x, y, g) {
    switch (m.kind) {
      case 'seq': return drawSeq(m, x, y, g);
      case 'alt': return drawAlt(m, x, y, g);
      case 'quant': return drawQuant(m, x, y, g);
      case 'group': return drawGroup(m, x, y, g);
      case 'skip': return drawSkip(m, x, y, g);
      default: return drawTerminal(m, x, y, g);
    }
  }

  function line(g, x1, y1, x2, y2) {
    g.appendChild(el('line', { x1: x1, y1: y1, x2: x2, y2: y2, class: 'rr-rail' }));
  }

  function drawTerminal(m, x, y, g) {
    var cy = y + m.cy;
    g.appendChild(el('rect', { x: x, y: y, width: m.w, height: m.h, rx: 7, class: 'rr-box ' + m.cls }));
    g.appendChild(el('text', { x: x + m.w / 2, y: cy + FONT * 0.35, class: 'rr-text', 'text-anchor': 'middle' }, m.label));
  }

  function drawSkip(m, x, y, g) {
    line(g, x, y + m.cy, x + m.w, y + m.cy);
  }

  function drawSeq(m, x, y, g) {
    if (!m.children.length) { drawSkip(m, x, y, g); return; }
    var cx = x;
    var cy = y + m.cy;
    m.children.forEach(function (k, i) {
      var ky = cy - k.cy;
      if (i > 0) line(g, cx - 18, cy, cx, cy); // connector
      // align child centre to lane centre
      draw(k, cx, ky, g);
      // entry/exit stubs to lane centre
      if (k.cy !== m.cy) {
        // vertical correction handled by connector to box edge
      }
      cx += k.w + 18;
    });
  }

  function drawAlt(m, x, y, g) {
    var leftX = x;
    var rightX = x + m.w;
    var hubL = x + 24;
    var hubR = rightX - 24;
    var topCy = y + m.children[0].cy;

    // entry & exit stubs on main lane
    line(g, leftX, topCy, hubL, topCy);
    line(g, hubR, topCy, rightX, topCy);

    var cy = y;
    m.children.forEach(function (k) {
      var branchY = cy;
      var bcy = branchY + k.cy;
      var bx = hubL + ((m.inner - k.w) / 2) + 0; // centre the branch
      var branchStart = hubL + 4;
      var branchEnd = hubR - 4;
      // route rail from hub down to branch level then across
      railTo(g, hubL, topCy, branchStart, bcy);
      line(g, branchStart, bcy, bx, bcy);
      draw(k, bx, branchY, g);
      line(g, bx + k.w, bcy, branchEnd, bcy);
      railTo(g, branchEnd, bcy, hubR, topCy);
      cy += k.h + VGAP;
    });
  }

  // draw a rail with a vertical+horizontal step (rounded)
  function railTo(g, x1, y1, x2, y2) {
    if (Math.abs(y1 - y2) < 0.5) { line(g, x1, y1, x2, y2); return; }
    var d = 'M ' + x1 + ' ' + y1 +
            ' V ' + (y2 - (y2 > y1 ? ARC : -ARC)) +
            ' Q ' + x1 + ' ' + y2 + ' ' + (x1 + ARC) + ' ' + y2 +
            ' H ' + x2;
    g.appendChild(el('path', { d: d, class: 'rr-rail', fill: 'none' }));
  }

  function drawQuant(m, x, y, g) {
    var child = m.child;
    var childX = x + 14;
    var cy = y + m.cy;
    var childY = cy - child.cy;
    // entry / exit stubs
    line(g, x, cy, childX, cy);
    draw(child, childX, childY, g);
    var exitX = childX + child.w;
    line(g, exitX, cy, exitX + 14, cy);

    var loopY = y + m.h - 4;
    var info = quantInfo(m.node);

    if (info.canRepeat) {
      // loop-back rail under the child (right -> left)
      var d = 'M ' + exitX + ' ' + cy +
              ' Q ' + (exitX + ARC) + ' ' + cy + ' ' + (exitX + ARC) + ' ' + (cy + ARC) +
              ' V ' + (loopY - ARC) +
              ' Q ' + (exitX + ARC) + ' ' + loopY + ' ' + exitX + ' ' + loopY +
              ' H ' + childX +
              ' Q ' + (childX - ARC) + ' ' + loopY + ' ' + (childX - ARC) + ' ' + (loopY - ARC) +
              ' V ' + (cy + ARC) +
              ' Q ' + (childX - ARC) + ' ' + cy + ' ' + childX + ' ' + cy;
      g.appendChild(el('path', { d: d, class: 'rr-rail rr-loop', fill: 'none' }));
      // arrow on the loop
      g.appendChild(el('path', { d: 'M ' + (childX + 6) + ' ' + (loopY - 4) + ' L ' + childX + ' ' + loopY + ' L ' + (childX + 6) + ' ' + (loopY + 4), class: 'rr-arrow', fill: 'none' }));
    }
    if (info.canSkip) {
      // bypass rail over the child (skip path)
      var topY = childY - 8;
      var sd = 'M ' + x + ' ' + cy +
               ' V ' + (topY + ARC) +
               ' Q ' + x + ' ' + topY + ' ' + (x + ARC) + ' ' + topY +
               ' H ' + (exitX + 14 - ARC) +
               ' Q ' + (exitX + 14) + ' ' + topY + ' ' + (exitX + 14) + ' ' + (topY + ARC) +
               ' V ' + cy;
      g.appendChild(el('path', { d: sd, class: 'rr-rail rr-skip', fill: 'none' }));
    }
    // quantifier label badge
    g.appendChild(el('text', { x: exitX + 18, y: loopY + 3, class: 'rr-badge', 'text-anchor': 'start' }, info.label));
  }

  function quantInfo(node) {
    var lbl;
    if (node.min === 0 && node.max === Infinity) lbl = '✱';
    else if (node.min === 1 && node.max === Infinity) lbl = '+';
    else if (node.min === 0 && node.max === 1) lbl = '?';
    else if (node.max === Infinity) lbl = '{' + node.min + ',}';
    else if (node.min === node.max) lbl = '{' + node.min + '}';
    else lbl = '{' + node.min + ',' + node.max + '}';
    if (!node.greedy) lbl += ' lazy';
    return { canSkip: node.min === 0, canRepeat: node.max === Infinity || node.max > 1, label: lbl };
  }

  function drawGroup(m, x, y, g) {
    var labelH = 16;
    var bx = x + 4, by = y + labelH;
    var bw = m.w - 8, bh = m.h - labelH;
    g.appendChild(el('rect', { x: bx, y: by, width: bw, height: bh - 4, rx: 9, class: 'rr-group ' + groupCls(m.node) }));
    g.appendChild(el('text', { x: bx + 10, y: y + 12, class: 'rr-grouplabel' }, groupLabel(m.node)));

    var child = m.child;
    var cy = y + m.cy;
    var childY = cy - child.cy;
    // entry/exit lines through the group box
    line(g, x, cy, x + HPAD + 4, cy);
    draw(child, x + HPAD + 4, childY, g);
    line(g, x + HPAD + 4 + child.w, cy, x + m.w, cy);
  }

  function groupCls(node) {
    if (node.kind.indexOf('look') !== -1) return 'rr-look';
    if (node.kind === 'named') return 'rr-named';
    if (node.kind === 'noncapturing') return 'rr-noncap';
    return 'rr-cap';
  }

  function groupLabel(node) {
    switch (node.kind) {
      case 'capturing': return 'group #' + node.captureNum;
      case 'named': return 'group ‹' + node.name + '›';
      case 'noncapturing': return 'non-capturing';
      case 'lookahead': return 'lookahead (?=…)';
      case 'neglookahead': return 'neg. lookahead (?!…)';
      case 'lookbehind': return 'lookbehind (?<=…)';
      case 'neglookbehind': return 'neg. lookbehind (?<!…)';
      default: return 'group';
    }
  }

  /* ---------- public ---------- */
  function render(ast, container) {
    container.innerHTML = '';
    var m = measure(ast);
    var margin = 24;
    var startW = 26, endW = 26;
    var totalW = m.w + margin * 2 + startW + endW;
    var totalH = m.h + margin * 2;

    var svg = el('svg', {
      viewBox: '0 0 ' + totalW + ' ' + totalH,
      width: totalW, height: totalH, class: 'rr-svg',
      role: 'img', 'aria-label': 'Railroad diagram of the regular expression'
    });
    var g = el('g', {});
    svg.appendChild(g);

    var cy = margin + m.cy;
    // start terminal
    g.appendChild(el('circle', { cx: margin + 6, cy: cy, r: 6, class: 'rr-cap-node' }));
    line(g, margin + 12, cy, margin + startW, cy);

    draw(m, margin + startW, margin, g);

    var endX = margin + startW + m.w;
    line(g, endX, cy, endX + endW - 12, cy);
    g.appendChild(el('circle', { cx: endX + endW - 6, cy: cy, r: 6, class: 'rr-cap-node rr-end-node' }));

    container.appendChild(svg);
    return svg;
  }

  global.RelayRailroad = { render: render };

})(typeof window !== 'undefined' ? window : this);
