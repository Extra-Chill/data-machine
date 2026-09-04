/* =========================================================
   BOUNDLESS — shapes.js
   The object model. Every board item is a plain serializable
   object: { id, type, ...fields }. This module knows how to
   compute a shape's bounding box, hit-test it, and draw it onto
   a 2D context already transformed into world space.
   ========================================================= */
'use strict';

const Shapes = (() => {

  let _seq = 0;
  function uid(prefix = 's') {
    _seq++;
    return prefix + '_' + Date.now().toString(36) + '_' + _seq.toString(36) +
           Math.random().toString(36).slice(2, 6);
  }

  /* ---- bounds: world-space {x,y,w,h} for any shape ---- */
  function bounds(s) {
    switch (s.type) {
      case 'rect':
      case 'ellipse':
      case 'sticky':
      case 'text':
        return Geo.normRect(s.x, s.y, s.w, s.h);
      case 'pen':
        return Geo.polylineBounds(s.points);
      case 'connector': {
        // resolved by board (needs sibling lookup); fall back to stored pts.
        // Coordinates may be undefined for docked connectors that haven't
        // been resolved yet — coerce to finite numbers so bounds never NaN.
        const a = s._a || { x: s.x1 ?? 0, y: s.y1 ?? 0 };
        const b = s._b || { x: s.x2 ?? 0, y: s.y2 ?? 0 };
        const ax = a.x ?? 0, ay = a.y ?? 0, bx = b.x ?? 0, by = b.y ?? 0;
        return Geo.normRect(
          Math.min(ax, bx), Math.min(ay, by),
          Math.abs(bx - ax), Math.abs(by - ay)
        );
      }
      default:
        return Geo.normRect(s.x || 0, s.y || 0, s.w || 0, s.h || 0);
    }
  }

  /* ---- hit test in world space; tol is in world units ---- */
  function hit(s, px, py, tol) {
    switch (s.type) {
      case 'rect':
      case 'sticky':
      case 'text':
        return Geo.rectContains(Geo.expandRect(bounds(s), tol), px, py);
      case 'ellipse':
        return Geo.ellipseContains(Geo.expandRect(bounds(s), tol), px, py);
      case 'pen':
        return Geo.pointToPolyline(px, py, s.points) <= tol + (s.strokeW || 2);
      case 'connector': {
        const a = s._a || { x: s.x1, y: s.y1 };
        const b = s._b || { x: s.x2, y: s.y2 };
        return Geo.pointToSegment(px, py, a.x, a.y, b.x, b.y) <= tol + 6;
      }
      default:
        return false;
    }
  }

  const RESIZABLE = new Set(['rect', 'ellipse', 'sticky', 'text', 'pen']);
  const isResizable = s => RESIZABLE.has(s.type);

  /* Move a shape by world delta. Connectors that are docked move with
     their endpoints automatically; free connectors move their points. */
  function translate(s, dx, dy) {
    switch (s.type) {
      case 'pen':
        s.points = s.points.map(p => ({ x: p.x + dx, y: p.y + dy }));
        break;
      case 'connector':
        if (!s.from) { s.x1 += dx; s.y1 += dy; }
        if (!s.to)   { s.x2 += dx; s.y2 += dy; }
        break;
      default:
        s.x += dx; s.y += dy;
    }
  }

  /* Resize a shape so its bounds match newRect (world space). */
  function setBounds(s, nr) {
    if (s.type === 'pen') {
      const old = Geo.polylineBounds(s.points);
      const sx = old.w === 0 ? 1 : nr.w / old.w;
      const sy = old.h === 0 ? 1 : nr.h / old.h;
      s.points = s.points.map(p => ({
        x: nr.x + (p.x - old.x) * sx,
        y: nr.y + (p.y - old.y) * sy,
      }));
      s.strokeW = (s.strokeW || 2);
    } else {
      s.x = nr.x; s.y = nr.y; s.w = nr.w; s.h = nr.h;
    }
  }

  /* =====================================================
     Drawing. ctx is in world space (already pan/zoomed).
     `zoom` is provided so we can keep line widths / fonts
     crisp regardless of scale.
  ===================================================== */
  function roundRectPath(ctx, x, y, w, h, r) {
    r = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y,     x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x,     y + h, r);
    ctx.arcTo(x,     y + h, x,     y,     r);
    ctx.arcTo(x,     y,     x + w, y,     r);
    ctx.closePath();
  }

  function wrapText(ctx, text, maxW) {
    const lines = [];
    for (const para of String(text).split('\n')) {
      if (para === '') { lines.push(''); continue; }
      let line = '';
      for (const word of para.split(' ')) {
        const test = line ? line + ' ' + word : word;
        if (ctx.measureText(test).width > maxW && line) {
          lines.push(line); line = word;
        } else { line = test; }
      }
      lines.push(line);
    }
    return lines;
  }

  function draw(ctx, s, zoom, opts = {}) {
    ctx.save();
    const stroke = s.stroke || '#1f2733';
    const fill   = s.fill   || '#ffffff';
    const sw     = (s.strokeW || 2);

    if (s.type === 'rect') {
      ctx.fillStyle = fill; ctx.strokeStyle = stroke; ctx.lineWidth = sw;
      roundRectPath(ctx, s.x, s.y, Math.abs(s.w), Math.abs(s.h), s.radius ?? 10);
      if (fill !== 'none') ctx.fill();
      if (sw > 0) ctx.stroke();
      drawLabel(ctx, s, { x: s.x, y: s.y, w: Math.abs(s.w), h: Math.abs(s.h) });

    } else if (s.type === 'ellipse') {
      ctx.fillStyle = fill; ctx.strokeStyle = stroke; ctx.lineWidth = sw;
      const rx = Math.abs(s.w) / 2, ry = Math.abs(s.h) / 2;
      ctx.beginPath();
      ctx.ellipse(s.x + rx, s.y + ry, rx, ry, 0, 0, Math.PI * 2);
      if (fill !== 'none') ctx.fill();
      if (sw > 0) ctx.stroke();
      drawLabel(ctx, s, { x: s.x, y: s.y, w: Math.abs(s.w), h: Math.abs(s.h) });

    } else if (s.type === 'sticky') {
      const w = Math.abs(s.w), h = Math.abs(s.h);
      // soft shadow
      ctx.save();
      ctx.shadowColor = 'rgba(15,23,42,0.20)';
      ctx.shadowBlur = 14 ; ctx.shadowOffsetY = 6;
      ctx.fillStyle = fill;
      roundRectPath(ctx, s.x, s.y, w, h, 4);
      ctx.fill();
      ctx.restore();
      // a folded-corner accent strip
      ctx.fillStyle = 'rgba(0,0,0,0.06)';
      ctx.fillRect(s.x, s.y, w, 6);
      drawStickyText(ctx, s, w, h);

    } else if (s.type === 'text') {
      drawFreeText(ctx, s);

    } else if (s.type === 'pen') {
      ctx.strokeStyle = s.stroke || '#1f2733';
      ctx.lineWidth = sw;
      ctx.lineJoin = 'round'; ctx.lineCap = 'round';
      const pts = s.points;
      if (pts.length === 1) {
        ctx.beginPath();
        ctx.arc(pts[0].x, pts[0].y, sw / 2, 0, Math.PI * 2);
        ctx.fillStyle = s.stroke; ctx.fill();
      } else {
        ctx.beginPath();
        ctx.moveTo(pts[0].x, pts[0].y);
        for (let i = 1; i < pts.length - 1; i++) {
          const mx = (pts[i].x + pts[i + 1].x) / 2;
          const my = (pts[i].y + pts[i + 1].y) / 2;
          ctx.quadraticCurveTo(pts[i].x, pts[i].y, mx, my);
        }
        ctx.lineTo(pts[pts.length - 1].x, pts[pts.length - 1].y);
        ctx.stroke();
      }

    } else if (s.type === 'connector') {
      drawConnector(ctx, s, zoom);
    }
    ctx.restore();
  }

  function drawLabel(ctx, s, r) {
    if (!s.label) return;
    ctx.save();
    ctx.fillStyle = s.labelColor || '#0f172a';
    const fs = s.labelSize || 17;
    ctx.font = `600 ${fs}px Inter, system-ui, sans-serif`;
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    const lines = wrapText(ctx, s.label, r.w - 18);
    const lh = fs * 1.25;
    const startY = r.y + r.h / 2 - (lines.length - 1) * lh / 2;
    lines.forEach((ln, i) => ctx.fillText(ln, r.x + r.w / 2, startY + i * lh));
    ctx.restore();
  }

  function drawStickyText(ctx, s, w, h) {
    if (!s.text) return;
    ctx.save();
    ctx.fillStyle = s.textColor || '#3a2f0b';
    const fs = s.fontSize || 18;
    ctx.font = `500 ${fs}px Inter, system-ui, sans-serif`;
    ctx.textAlign = 'left'; ctx.textBaseline = 'top';
    const pad = 14;
    const lines = wrapText(ctx, s.text, w - pad * 2);
    const lh = fs * 1.32;
    lines.forEach((ln, i) => ctx.fillText(ln, s.x + pad, s.y + pad + 6 + i * lh));
    ctx.restore();
  }

  function drawFreeText(ctx, s) {
    ctx.save();
    ctx.fillStyle = s.fill && s.fill !== 'none' ? s.fill : (s.textColor || '#0f172a');
    const fs = s.fontSize || 22;
    const weight = s.bold ? 700 : 500;
    ctx.font = `${weight} ${fs}px Inter, system-ui, sans-serif`;
    ctx.textAlign = 'left'; ctx.textBaseline = 'top';
    const lines = wrapText(ctx, s.text || '', Math.max(40, Math.abs(s.w)));
    const lh = fs * 1.3;
    lines.forEach((ln, i) => ctx.fillText(ln, s.x, s.y + i * lh));
    ctx.restore();
  }

  function drawConnector(ctx, s, zoom) {
    const a = s._a || { x: s.x1, y: s.y1 };
    const b = s._b || { x: s.x2, y: s.y2 };
    ctx.strokeStyle = s.stroke || '#475569';
    ctx.fillStyle   = s.stroke || '#475569';
    ctx.lineWidth = s.strokeW || 2.5;
    ctx.lineJoin = 'round'; ctx.lineCap = 'round';
    if (s.dashed) ctx.setLineDash([8, 7]);

    // gentle curve: control points pulled along the dominant axis
    const dx = b.x - a.x, dy = b.y - a.y;
    const horiz = Math.abs(dx) > Math.abs(dy);
    const k = Math.min(120, Math.max(40, Math.hypot(dx, dy) * 0.4));
    const c1 = horiz ? { x: a.x + Math.sign(dx) * k, y: a.y }
                     : { x: a.x, y: a.y + Math.sign(dy) * k };
    const c2 = horiz ? { x: b.x - Math.sign(dx) * k, y: b.y }
                     : { x: b.x, y: b.y - Math.sign(dy) * k };

    ctx.beginPath();
    ctx.moveTo(a.x, a.y);
    if (s.style === 'straight') ctx.lineTo(b.x, b.y);
    else ctx.bezierCurveTo(c1.x, c1.y, c2.x, c2.y, b.x, b.y);
    ctx.stroke();
    ctx.setLineDash([]);

    // arrowhead at b, aimed along the incoming tangent
    const tx = s.style === 'straight' ? a.x : c2.x;
    const ty = s.style === 'straight' ? a.y : c2.y;
    const ang = Math.atan2(b.y - ty, b.x - tx);
    const ah = 13;
    if (s.arrow !== false) {
      ctx.beginPath();
      ctx.moveTo(b.x, b.y);
      ctx.lineTo(b.x - ah * Math.cos(ang - 0.4), b.y - ah * Math.sin(ang - 0.4));
      ctx.lineTo(b.x - ah * Math.cos(ang + 0.4), b.y - ah * Math.sin(ang + 0.4));
      ctx.closePath();
      ctx.fill();
    }

    // optional mid-label
    if (s.label) {
      const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
      ctx.save();
      ctx.font = `600 13px Inter, system-ui, sans-serif`;
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      const tw = ctx.measureText(s.label).width + 12;
      ctx.fillStyle = '#ffffff';
      roundRectPath(ctx, mx - tw / 2, my - 11, tw, 22, 6);
      ctx.fill();
      ctx.strokeStyle = 'rgba(15,23,42,0.12)'; ctx.lineWidth = 1; ctx.stroke();
      ctx.fillStyle = '#334155';
      ctx.fillText(s.label, mx, my + 1);
      ctx.restore();
    }
  }

  /* factory helpers used by tools / templates */
  function make(type, props) {
    return Object.assign({ id: uid(type), type }, props);
  }

  return {
    uid, bounds, hit, draw, translate, setBounds,
    isResizable, make, wrapText, roundRectPath,
  };
})();

if (typeof window !== 'undefined') window.Shapes = Shapes;
