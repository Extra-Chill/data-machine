/* =========================================================
   BOUNDLESS — geometry.js
   Pure math helpers: the world<->screen camera transform,
   rectangles, points, hit-testing. No DOM, no state.
   ========================================================= */
'use strict';

const Geo = (() => {

  /* ---- numeric helpers ---- */
  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
  const lerp  = (a, b, t) => a + (b - a) * t;
  const round = (v, step) => Math.round(v / step) * step;
  const dist  = (ax, ay, bx, by) => Math.hypot(bx - ax, by - ay);

  /* ----------------------------------------------------------
     Camera: a 2D affine transform { x, y, zoom }.
     screen = world * zoom + offset
     world  = (screen - offset) / zoom
     The offset is stored as (x, y).
  ---------------------------------------------------------- */
  function worldToScreen(cam, wx, wy) {
    return { x: wx * cam.zoom + cam.x, y: wy * cam.zoom + cam.y };
  }
  function screenToWorld(cam, sx, sy) {
    return { x: (sx - cam.x) / cam.zoom, y: (sy - cam.y) / cam.zoom };
  }

  /* Zoom toward a fixed screen point so that the world point under
     the cursor stays under the cursor (zoom-to-cursor). */
  function zoomAt(cam, screenX, screenY, nextZoom) {
    nextZoom = clamp(nextZoom, 0.05, 12);
    const before = screenToWorld(cam, screenX, screenY);
    cam.zoom = nextZoom;
    const after = worldToScreen(cam, before.x, before.y);
    cam.x += screenX - after.x;
    cam.y += screenY - after.y;
    return cam;
  }

  /* ---- rectangles (world space, axis-aligned) ---- */
  function normRect(x, y, w, h) {
    if (w < 0) { x += w; w = -w; }
    if (h < 0) { y += h; h = -h; }
    return { x, y, w, h };
  }
  function rectContains(r, px, py) {
    return px >= r.x && px <= r.x + r.w && py >= r.y && py <= r.y + r.h;
  }
  function rectsIntersect(a, b) {
    return !(b.x > a.x + a.w || b.x + b.w < a.x ||
             b.y > a.y + a.h || b.y + b.h < a.y);
  }
  function rectContainsRect(outer, inner) {
    return inner.x >= outer.x && inner.y >= outer.y &&
           inner.x + inner.w <= outer.x + outer.w &&
           inner.y + inner.h <= outer.y + outer.h;
  }
  function unionRects(rects) {
    if (!rects.length) return null;
    let minX =  Infinity, minY =  Infinity;
    let maxX = -Infinity, maxY = -Infinity;
    for (const r of rects) {
      minX = Math.min(minX, r.x);
      minY = Math.min(minY, r.y);
      maxX = Math.max(maxX, r.x + r.w);
      maxY = Math.max(maxY, r.y + r.h);
    }
    return { x: minX, y: minY, w: maxX - minX, h: maxY - minY };
  }
  function expandRect(r, pad) {
    return { x: r.x - pad, y: r.y - pad, w: r.w + pad * 2, h: r.h + pad * 2 };
  }
  function rectCenter(r) {
    return { x: r.x + r.w / 2, y: r.y + r.h / 2 };
  }

  /* point-in-ellipse (inscribed in rect) */
  function ellipseContains(r, px, py) {
    const rx = r.w / 2, ry = r.h / 2;
    if (rx <= 0 || ry <= 0) return false;
    const dx = (px - (r.x + rx)) / rx;
    const dy = (py - (r.y + ry)) / ry;
    return dx * dx + dy * dy <= 1;
  }

  /* distance from point to segment (for selecting freehand / connectors) */
  function pointToSegment(px, py, ax, ay, bx, by) {
    const dx = bx - ax, dy = by - ay;
    const len2 = dx * dx + dy * dy;
    let t = len2 === 0 ? 0 : ((px - ax) * dx + (py - ay) * dy) / len2;
    t = clamp(t, 0, 1);
    const cx = ax + t * dx, cy = ay + t * dy;
    return Math.hypot(px - cx, py - cy);
  }
  function pointToPolyline(px, py, pts) {
    let min = Infinity;
    for (let i = 1; i < pts.length; i++) {
      min = Math.min(min, pointToSegment(px, py, pts[i - 1].x, pts[i - 1].y, pts[i].x, pts[i].y));
    }
    if (pts.length === 1) min = Math.hypot(px - pts[0].x, py - pts[0].y);
    return min;
  }
  function polylineBounds(pts) {
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (const p of pts) {
      minX = Math.min(minX, p.x); minY = Math.min(minY, p.y);
      maxX = Math.max(maxX, p.x); maxY = Math.max(maxY, p.y);
    }
    return normRect(minX, minY, maxX - minX, maxY - minY);
  }

  /* Find the point on a rectangle's border where a line from its
     center toward (tx,ty) exits. Used to dock connector endpoints. */
  function rectBorderPoint(r, tx, ty) {
    const cx = r.x + r.w / 2, cy = r.y + r.h / 2;
    let dx = tx - cx, dy = ty - cy;
    if (dx === 0 && dy === 0) return { x: cx, y: cy };
    const hw = r.w / 2, hh = r.h / 2;
    const scale = 1 / Math.max(Math.abs(dx) / hw, Math.abs(dy) / hh);
    return { x: cx + dx * scale, y: cy + dy * scale };
  }

  return {
    clamp, lerp, round, dist,
    worldToScreen, screenToWorld, zoomAt,
    normRect, rectContains, rectsIntersect, rectContainsRect,
    unionRects, expandRect, rectCenter, ellipseContains,
    pointToSegment, pointToPolyline, polylineBounds, rectBorderPoint,
  };
})();

if (typeof window !== 'undefined') window.Geo = Geo;
