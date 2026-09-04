/* =========================================================
   BOUNDLESS — board.js
   The canvas controller. Owns rendering, the camera (pan/zoom),
   pointer interaction, the active tool, selection, resize handles,
   marquee, connector docking, and the inline text editor.
   ========================================================= */
'use strict';

function createBoard(canvas, opts = {}) {
  const ctx = canvas.getContext('2d');
  const cam = Store.state.camera;
  let dpr = Math.max(1, window.devicePixelRatio || 1);

  const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- interaction state ---- */
  let tool = 'select';
  let selection = new Set();        // ids
  let hoverId = null;
  let drag = null;                  // active gesture descriptor
  let marquee = null;               // {x,y,w,h} screen-space rect (live)
  let spaceDown = false;
  let snapGuides = [];              // [{x}|{y}] world lines drawn while snapping

  const HANDLE = 9;                 // px (screen) for resize handles
  const SNAP = 6;                   // px (screen) snap threshold

  /* events out to the app shell */
  const out = {
    change: opts.onChange || (() => {}),
    select: opts.onSelect || (() => {}),
    tool:   opts.onTool   || (() => {}),
    camera: opts.onCamera || (() => {}),
  };

  /* =====================================================
     Camera / sizing
  ===================================================== */
  function resize() {
    dpr = Math.max(1, window.devicePixelRatio || 1);
    const r = canvas.getBoundingClientRect();
    canvas.width  = Math.round(r.width * dpr);
    canvas.height = Math.round(r.height * dpr);
    render();
  }
  function viewSize() {
    return { w: canvas.width / dpr, h: canvas.height / dpr };
  }
  function eventPos(e) {
    const r = canvas.getBoundingClientRect();
    const t = e.touches ? e.touches[0] : e;
    return { x: t.clientX - r.left, y: t.clientY - r.top };
  }
  const toWorld = p => Geo.screenToWorld(cam, p.x, p.y);

  /* =====================================================
     Connector resolution: compute docked endpoints from
     the shapes they attach to, so they "stay attached".
  ===================================================== */
  function resolveConnectors() {
    for (const s of Store.shapes()) {
      if (s.type !== 'connector') continue;
      const A = s.from ? Store.byId(s.from) : null;
      const B = s.to   ? Store.byId(s.to)   : null;
      const ca = A ? Geo.rectCenter(Shapes.bounds(A)) : { x: s.x1, y: s.y1 };
      const cb = B ? Geo.rectCenter(Shapes.bounds(B)) : { x: s.x2, y: s.y2 };
      s._a = A ? Geo.rectBorderPoint(Shapes.bounds(A), cb.x, cb.y) : ca;
      s._b = B ? Geo.rectBorderPoint(Shapes.bounds(B), ca.x, ca.y) : cb;
    }
  }

  /* =====================================================
     Rendering
  ===================================================== */
  function render() {
    resolveConnectors();
    const v = viewSize();
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, v.w, v.h);
    paintBackground(v);

    ctx.save();
    ctx.setTransform(dpr * cam.zoom, 0, 0, dpr * cam.zoom, dpr * cam.x, dpr * cam.y);

    for (const s of Store.shapes()) Shapes.draw(ctx, s, cam.zoom);

    ctx.restore();

    drawSelectionOverlay(v);
    if (marquee) drawMarquee();
    if (snapGuides.length) drawSnapGuides();
  }

  function paintBackground(v) {
    ctx.fillStyle = '#f7f8fb';
    ctx.fillRect(0, 0, v.w, v.h);
    // dotted grid that scales with zoom and stays subtle
    let base = 32;
    let step = base * cam.zoom;
    while (step < 16) step *= 4;       // collapse dots when far out
    while (step > 110) step /= 4;      // densify when zoomed in
    const ox = cam.x % step, oy = cam.y % step;
    const dot = Geo.clamp(1.1 * Math.sqrt(cam.zoom), 0.6, 2.2);
    ctx.fillStyle = 'rgba(15,23,42,0.16)';
    for (let x = ox; x < v.w; x += step) {
      for (let y = oy; y < v.h; y += step) {
        ctx.beginPath();
        ctx.arc(x, y, dot, 0, Math.PI * 2);
        ctx.fill();
      }
    }
  }

  function drawSelectionOverlay(v) {
    if (!selection.size && hoverId == null) return;
    ctx.save();
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.lineWidth = 1.5;

    // hover outline
    if (hoverId != null && !selection.has(hoverId) && tool === 'select') {
      const s = Store.byId(hoverId);
      if (s) outlineShape(s, 'rgba(59,130,246,0.55)', false);
    }
    // selection outlines
    for (const id of selection) {
      const s = Store.byId(id);
      if (s) outlineShape(s, '#3b82f6', false);
    }
    // group handles only when a single resizable shape (or single selection bbox)
    if (selection.size >= 1) {
      const rect = selectionBounds();
      if (rect) {
        const sr = worldRectToScreen(rect);
        ctx.strokeStyle = '#3b82f6';
        ctx.setLineDash(selection.size > 1 ? [5, 4] : []);
        ctx.strokeRect(sr.x, sr.y, sr.w, sr.h);
        ctx.setLineDash([]);
        if (canResizeSelection()) drawHandles(sr);
      }
    }
    ctx.restore();
  }

  function outlineShape(s, color) {
    const b = Shapes.bounds(s);
    const sr = worldRectToScreen(b);
    ctx.strokeStyle = color;
    ctx.setLineDash([]);
    if (s.type === 'ellipse') {
      ctx.beginPath();
      ctx.ellipse(sr.x + sr.w / 2, sr.y + sr.h / 2, sr.w / 2, sr.h / 2, 0, 0, Math.PI * 2);
      ctx.stroke();
    } else {
      ctx.strokeRect(sr.x - 1, sr.y - 1, sr.w + 2, sr.h + 2);
    }
  }

  function drawHandles(sr) {
    const pts = handlePoints(sr);
    ctx.fillStyle = '#ffffff';
    ctx.strokeStyle = '#3b82f6';
    ctx.lineWidth = 1.5;
    for (const k in pts) {
      const p = pts[k];
      ctx.beginPath();
      ctx.rect(p.x - HANDLE / 2, p.y - HANDLE / 2, HANDLE, HANDLE);
      ctx.fill(); ctx.stroke();
    }
  }
  function handlePoints(sr) {
    return {
      nw: { x: sr.x,           y: sr.y },
      n:  { x: sr.x + sr.w / 2,y: sr.y },
      ne: { x: sr.x + sr.w,    y: sr.y },
      e:  { x: sr.x + sr.w,    y: sr.y + sr.h / 2 },
      se: { x: sr.x + sr.w,    y: sr.y + sr.h },
      s:  { x: sr.x + sr.w / 2,y: sr.y + sr.h },
      sw: { x: sr.x,           y: sr.y + sr.h },
      w:  { x: sr.x,           y: sr.y + sr.h / 2 },
    };
  }

  function drawMarquee() {
    ctx.save();
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.fillStyle = 'rgba(59,130,246,0.10)';
    ctx.strokeStyle = 'rgba(59,130,246,0.8)';
    ctx.lineWidth = 1;
    const r = Geo.normRect(marquee.x, marquee.y, marquee.w, marquee.h);
    ctx.fillRect(r.x, r.y, r.w, r.h);
    ctx.strokeRect(r.x, r.y, r.w, r.h);
    ctx.restore();
  }

  function drawSnapGuides() {
    ctx.save();
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.strokeStyle = '#f43f5e';
    ctx.lineWidth = 1;
    ctx.setLineDash([4, 4]);
    const v = viewSize();
    for (const g of snapGuides) {
      ctx.beginPath();
      if (g.x != null) {
        const sx = g.x * cam.zoom + cam.x;
        ctx.moveTo(sx, 0); ctx.lineTo(sx, v.h);
      } else {
        const sy = g.y * cam.zoom + cam.y;
        ctx.moveTo(0, sy); ctx.lineTo(v.w, sy);
      }
      ctx.stroke();
    }
    ctx.restore();
  }

  function worldRectToScreen(r) {
    const a = Geo.worldToScreen(cam, r.x, r.y);
    return { x: a.x, y: a.y, w: r.w * cam.zoom, h: r.h * cam.zoom };
  }

  /* =====================================================
     Selection helpers
  ===================================================== */
  function selectionBounds() {
    const rs = [...selection].map(id => Store.byId(id)).filter(Boolean).map(Shapes.bounds);
    return Geo.unionRects(rs);
  }
  function canResizeSelection() {
    if (selection.size !== 1) return false;
    const s = Store.byId([...selection][0]);
    return s && Shapes.isResizable(s) && s.type !== 'text';
  }
  function setSelection(ids) {
    selection = new Set(ids);
    out.select(getSelection());
    render();
  }
  function getSelection() {
    return [...selection].map(id => Store.byId(id)).filter(Boolean);
  }

  /* topmost shape under a world point */
  function pick(wp) {
    const tol = 6 / cam.zoom;
    const arr = Store.shapes();
    for (let i = arr.length - 1; i >= 0; i--) {
      if (Shapes.hit(arr[i], wp.x, wp.y, tol)) return arr[i];
    }
    return null;
  }
  function handleAt(sp) {
    if (!canResizeSelection()) return null;
    const rect = selectionBounds();
    if (!rect) return null;
    const sr = worldRectToScreen(rect);
    const pts = handlePoints(sr);
    for (const k in pts) {
      if (Math.abs(sp.x - pts[k].x) <= HANDLE && Math.abs(sp.y - pts[k].y) <= HANDLE) return k;
    }
    return null;
  }

  /* =====================================================
     Tools
  ===================================================== */
  function setTool(t) {
    tool = t;
    out.tool(t);
    updateCursor();
    if (t !== 'select') closeEditor();
  }
  function getTool() { return tool; }

  function updateCursor() {
    if (spaceDown || drag?.kind === 'pan') { canvas.style.cursor = 'grabbing'; return; }
    const map = {
      select: 'default', pan: 'grab',
      rect: 'crosshair', ellipse: 'crosshair', sticky: 'crosshair',
      text: 'text', pen: 'crosshair', connector: 'crosshair',
    };
    canvas.style.cursor = map[tool] || 'default';
  }

  /* default props for newly created shapes */
  const DEFAULTS = {
    rect:    { fill: '#dbeafe', stroke: '#3b82f6', strokeW: 2, radius: 12 },
    ellipse: { fill: '#d1fae5', stroke: '#10b981', strokeW: 2 },
    sticky:  { fill: '#fff8b8', text: '' },
    text:    { text: 'Text', fontSize: 24, fill: '#0f172a', bold: true },
    pen:     { stroke: '#1f2733', strokeW: 3 },
  };

  /* =====================================================
     Pointer pipeline
  ===================================================== */
  function onPointerDown(e) {
    if (e.button === 1 || (e.button === 0 && spaceDown) || tool === 'pan') {
      e.preventDefault();
      drag = { kind: 'pan', sx: e.clientX, sy: e.clientY, cx: cam.x, cy: cam.y };
      updateCursor();
      return;
    }
    if (e.button !== 0 && e.pointerType !== 'touch') return;
    try { canvas.setPointerCapture?.(e.pointerId); } catch (_) { /* ignore */ }
    const sp = eventPos(e);
    const wp = toWorld(sp);

    if (tool === 'select') return downSelect(e, sp, wp);
    if (tool === 'pen')     return downPen(wp);
    if (tool === 'text')    return downText(wp);
    if (tool === 'connector') return downConnector(sp, wp);
    return downCreate(wp); // rect / ellipse / sticky
  }

  function downSelect(e, sp, wp) {
    // resize handle first
    const h = handleAt(sp);
    if (h) {
      const s = Store.byId([...selection][0]);
      Store.begin();
      drag = { kind: 'resize', handle: h, id: s.id, start: Shapes.bounds(s), wp };
      return;
    }
    const target = pick(wp);
    if (target) {
      if (e.shiftKey) {
        if (selection.has(target.id)) selection.delete(target.id);
        else selection.add(target.id);
        out.select(getSelection());
      } else if (!selection.has(target.id)) {
        selection = new Set([target.id]);
        out.select(getSelection());
      }
      // begin move of all selected
      Store.begin();
      drag = {
        kind: 'move', wp, moved: false,
        ids: [...selection],
        origins: new Map([...selection].map(id => [id, Store.clone(Store.byId(id))])),
      };
      render();
    } else {
      if (!e.shiftKey) selection.clear();
      marquee = { x: sp.x, y: sp.y, w: 0, h: 0, add: e.shiftKey, base: new Set(selection) };
      out.select(getSelection());
      render();
    }
  }

  function downCreate(wp) {
    Store.begin();
    const s = Shapes.make(tool, Object.assign({ x: wp.x, y: wp.y, w: 1, h: 1 }, DEFAULTS[tool]));
    Store.add(s);
    selection = new Set([s.id]);
    drag = { kind: 'create', id: s.id, start: { x: wp.x, y: wp.y } };
    render();
  }

  function downPen(wp) {
    Store.begin();
    const s = Shapes.make('pen', Object.assign({ points: [wp] }, DEFAULTS.pen));
    Store.add(s);
    drag = { kind: 'pen', id: s.id };
    render();
  }

  function downText(wp) {
    Store.begin();
    const s = Shapes.make('text', Object.assign({ x: wp.x, y: wp.y, w: 240, h: 34 }, DEFAULTS.text));
    s.text = '';
    Store.add(s);
    Store.commit('add-text');
    setTool('select');
    selection = new Set([s.id]);
    out.select(getSelection());
    openEditor(s);
  }

  let pendingConn = null;
  function downConnector(sp, wp) {
    const target = pick(wp);
    pendingConn = {
      from: target ? target.id : null,
      x1: wp.x, y1: wp.y,
    };
    drag = { kind: 'connector', wp };
    render();
  }

  function onPointerMove(e) {
    const sp = eventPos(e);
    const wp = toWorld(sp);

    if (!drag) {
      if (marquee) {
        marquee.w = sp.x - marquee.x;
        marquee.h = sp.y - marquee.y;
        const wr = screenRectToWorld(Geo.normRect(marquee.x, marquee.y, marquee.w, marquee.h));
        const hits = Store.shapes().filter(s => Geo.rectsIntersect(wr, Shapes.bounds(s))).map(s => s.id);
        selection = new Set(marquee.add ? [...marquee.base, ...hits] : hits);
        out.select(getSelection());
        render();
        return;
      }
      // hover feedback in select tool
      if (tool === 'select') {
        const h = handleAt(sp);
        if (h) { canvas.style.cursor = handleCursor(h); }
        else {
          const t = pick(wp);
          const id = t ? t.id : null;
          if (id !== hoverId) { hoverId = id; render(); }
          canvas.style.cursor = t ? 'move' : 'default';
        }
      }
      return;
    }

    if (drag.kind === 'pan') {
      cam.x = drag.cx + (e.clientX - drag.sx);
      cam.y = drag.cy + (e.clientY - drag.sy);
      out.camera(cam); render();
      return;
    }
    if (drag.kind === 'create') {
      const s = Store.byId(drag.id);
      s.w = wp.x - drag.start.x;
      s.h = wp.y - drag.start.y;
      render();
      return;
    }
    if (drag.kind === 'pen') {
      const s = Store.byId(drag.id);
      const last = s.points[s.points.length - 1];
      if (Geo.dist(last.x, last.y, wp.x, wp.y) * cam.zoom > 2) s.points.push(wp);
      render();
      return;
    }
    if (drag.kind === 'connector') {
      drag.wp = wp; render();
      return;
    }
    if (drag.kind === 'resize') {
      doResize(drag, wp);
      render();
      return;
    }
    if (drag.kind === 'move') {
      let dx = wp.x - drag.wp.x, dy = wp.y - drag.wp.y;
      if (Math.abs(dx) + Math.abs(dy) > 0.001) drag.moved = true;
      // reset to origins then apply delta (so snapping is stable)
      for (const id of drag.ids) {
        const o = drag.origins.get(id);
        const cur = Store.byId(id);
        Object.assign(cur, Store.clone(o));
      }
      const snapped = computeSnap(drag.ids, dx, dy, e.altKey);
      for (const id of drag.ids) Shapes.translate(Store.byId(id), snapped.dx, snapped.dy);
      snapGuides = snapped.guides;
      render();
      return;
    }
  }

  function onPointerUp(e) {
    if (drag?.kind === 'create') {
      const s = Store.byId(drag.id);
      // a click (no drag) makes a default-sized shape
      if (Math.abs(s.w) < 6 && Math.abs(s.h) < 6) {
        const d = { rect: [160, 90], ellipse: [150, 110], sticky: [190, 150] }[s.type] || [140, 90];
        s.x -= d[0] / 2; s.y -= d[1] / 2; s.w = d[0]; s.h = d[1];
      } else {
        const nr = Geo.normRect(s.x, s.y, s.w, s.h);
        Object.assign(s, nr);
      }
      Store.commit('create');
      out.select(getSelection());
      if (s.type === 'sticky') openEditor(s);
      if (!opts.lockTool) setTool('select');
    } else if (drag?.kind === 'pen') {
      Store.commit('draw');
      if (!opts.lockTool) setTool('select');
    } else if (drag?.kind === 'resize') {
      Store.commit('resize');
    } else if (drag?.kind === 'move') {
      if (drag.moved) Store.commit('move'); else Store.commit();
    } else if (drag?.kind === 'connector') {
      finishConnector(e);
    }
    if (marquee) { marquee = null; render(); }
    snapGuides = [];
    drag = null;
    updateCursor();
    render();
    out.change();
  }

  function finishConnector(e) {
    const sp = eventPos(e);
    const wp = toWorld(sp);
    const target = pick(wp);
    // require a real drag
    if (Geo.dist(pendingConn.x1, pendingConn.y1, wp.x, wp.y) * cam.zoom < 8 && !target) {
      pendingConn = null; if (!opts.lockTool) setTool('select'); return;
    }
    Store.begin();
    const c = Shapes.make('connector', {
      from: pendingConn.from,
      to: target ? target.id : null,
      x1: pendingConn.x1, y1: pendingConn.y1,
      x2: wp.x, y2: wp.y,
      stroke: '#475569', strokeW: 2.5, style: 'bezier', arrow: true,
    });
    Store.add(c);
    Store.commit('connect');
    pendingConn = null;
    selection = new Set([c.id]);
    out.select(getSelection());
    if (!opts.lockTool) setTool('select');
  }

  /* =====================================================
     Resize math (8 handles, keeps min size)
  ===================================================== */
  function doResize(d, wp) {
    const s = Store.byId(d.id);
    let { x, y, w, h } = d.start;
    let x2 = x + w, y2 = y + h;
    const k = d.handle;
    if (k.includes('w')) x = wp.x;
    if (k.includes('e')) x2 = wp.x;
    if (k.includes('n')) y = wp.y;
    if (k.includes('s')) y2 = wp.y;
    let nr = Geo.normRect(x, y, x2 - x, y2 - y);
    const min = 12;
    if (nr.w < min) nr.w = min;
    if (nr.h < min) nr.h = min;
    Shapes.setBounds(s, nr);
  }
  function handleCursor(h) {
    return { n: 'ns-resize', s: 'ns-resize', e: 'ew-resize', w: 'ew-resize',
             ne: 'nesw-resize', sw: 'nesw-resize', nw: 'nwse-resize', se: 'nwse-resize' }[h] || 'default';
  }

  /* =====================================================
     Snapping: align selection edges/centers to other shapes
  ===================================================== */
  function computeSnap(ids, dx, dy, disabled) {
    if (disabled) return { dx, dy, guides: [] };
    const idset = new Set(ids);
    const movingRects = ids.map(id => Shapes.bounds(Store.byId(id)));
    const bb = Geo.unionRects(movingRects);
    if (!bb) return { dx, dy, guides: [] };
    const moved = { x: bb.x + dx, y: bb.y + dy, w: bb.w, h: bb.h };

    const targets = Store.shapes().filter(s => !idset.has(s.id) && s.type !== 'connector' && s.type !== 'pen');
    const thresh = SNAP / cam.zoom;
    const guides = [];

    const vx = [moved.x, moved.x + moved.w / 2, moved.x + moved.w];
    let bestX = null;
    for (const t of targets) {
      const b = Shapes.bounds(t);
      const tx = [b.x, b.x + b.w / 2, b.x + b.w];
      for (const a of vx) for (const o of tx) {
        const diff = o - a;
        if (Math.abs(diff) < thresh && (bestX == null || Math.abs(diff) < Math.abs(bestX.diff))) {
          bestX = { diff, line: o };
        }
      }
    }
    if (bestX) { dx += bestX.diff; guides.push({ x: bestX.line }); }

    const vy = [moved.y, moved.y + moved.h / 2, moved.y + moved.h];
    let bestY = null;
    for (const t of targets) {
      const b = Shapes.bounds(t);
      const ty = [b.y, b.y + b.h / 2, b.y + b.h];
      for (const a of vy) for (const o of ty) {
        const diff = o - a;
        if (Math.abs(diff) < thresh && (bestY == null || Math.abs(diff) < Math.abs(bestY.diff))) {
          bestY = { diff, line: o };
        }
      }
    }
    if (bestY) { dy += bestY.diff; guides.push({ y: bestY.line }); }

    return { dx, dy, guides };
  }

  function screenRectToWorld(r) {
    const a = Geo.screenToWorld(cam, r.x, r.y);
    const b = Geo.screenToWorld(cam, r.x + r.w, r.y + r.h);
    return Geo.normRect(a.x, a.y, b.x - a.x, b.y - a.y);
  }

  /* =====================================================
     Wheel: zoom (default) / pan with shift / trackpad
  ===================================================== */
  function onWheel(e) {
    e.preventDefault();
    const sp = eventPos(e);
    if (e.ctrlKey) {
      // pinch-zoom on trackpads arrives as ctrl+wheel
      const factor = Math.exp(-e.deltaY * 0.01);
      Geo.zoomAt(cam, sp.x, sp.y, cam.zoom * factor);
    } else if (e.shiftKey) {
      cam.x -= e.deltaY; cam.y -= e.deltaX;
    } else if (Math.abs(e.deltaX) > 0 && Math.abs(e.deltaX) >= Math.abs(e.deltaY)) {
      cam.x -= e.deltaX; cam.y -= e.deltaY; // trackpad pan
    } else {
      const factor = Math.exp(-e.deltaY * 0.0016);
      Geo.zoomAt(cam, sp.x, sp.y, cam.zoom * factor);
    }
    out.camera(cam);
    render();
    Store.save();
  }

  /* =====================================================
     Touch: pinch-zoom + two-finger pan
  ===================================================== */
  let touchState = null;
  function onTouchStart(e) {
    if (e.touches.length === 2) {
      drag = null; marquee = null;
      const [a, b] = e.touches;
      touchState = pinchInfo(a, b);
    }
  }
  function onTouchMove(e) {
    if (e.touches.length === 2 && touchState) {
      e.preventDefault();
      const [a, b] = e.touches;
      const cur = pinchInfo(a, b);
      const factor = cur.dist / touchState.dist;
      Geo.zoomAt(cam, cur.cx, cur.cy, cam.zoom * factor);
      cam.x += cur.cx - touchState.cx;
      cam.y += cur.cy - touchState.cy;
      touchState = cur;
      out.camera(cam); render();
    }
  }
  function onTouchEnd(e) {
    if (e.touches.length < 2) touchState = null;
  }
  function pinchInfo(a, b) {
    const r = canvas.getBoundingClientRect();
    const ax = a.clientX - r.left, ay = a.clientY - r.top;
    const bx = b.clientX - r.left, by = b.clientY - r.top;
    return { dist: Math.hypot(bx - ax, by - ay), cx: (ax + bx) / 2, cy: (ay + by) / 2 };
  }

  /* =====================================================
     Inline text editor (overlaid <textarea>)
  ===================================================== */
  let editorEl = null, editingId = null;
  function openEditor(s) {
    closeEditor();
    editingId = s.id;
    const ta = document.createElement('textarea');
    ta.className = 'canvas-editor';
    ta.value = (s.type === 'sticky' ? s.text : s.text) || '';
    ta.spellcheck = false;
    document.body.appendChild(ta);
    editorEl = ta;
    positionEditor(s, ta);
    ta.focus();
    ta.select();
    const stop = () => commitEditor();
    ta.addEventListener('blur', stop);
    ta.addEventListener('input', () => {
      const cur = Store.byId(editingId);
      if (cur) { cur.text = ta.value; render(); }
    });
    ta.addEventListener('keydown', ev => {
      ev.stopPropagation();
      if (ev.key === 'Escape' || (ev.key === 'Enter' && (ev.metaKey || ev.ctrlKey))) {
        ev.preventDefault(); ta.blur();
      }
    });
  }
  function positionEditor(s, ta) {
    const b = Shapes.bounds(s);
    const sr = worldRectToScreen(b);
    const r = canvas.getBoundingClientRect();
    ta.style.left = (r.left + sr.x) + 'px';
    ta.style.top  = (r.top + sr.y) + 'px';
    ta.style.width  = Math.max(60, sr.w) + 'px';
    ta.style.height = Math.max(28, sr.h) + 'px';
    const fs = (s.type === 'sticky' ? (s.fontSize || 18) : (s.fontSize || 24)) * cam.zoom;
    ta.style.fontSize = fs + 'px';
    ta.style.padding = (s.type === 'sticky' ? 14 * cam.zoom : 0) + 'px';
    ta.style.color = s.type === 'sticky' ? (s.textColor || '#3a2f0b') : (s.fill || '#0f172a');
    ta.style.fontWeight = s.bold ? 700 : 500;
    ta.style.textAlign = s.type === 'text' ? 'left' : 'left';
  }
  function commitEditor() {
    if (!editorEl) return;
    const s = Store.byId(editingId);
    const val = editorEl.value;
    editorEl.remove(); editorEl = null;
    if (s) {
      if (s.type === 'text' && val.trim() === '') { Store.remove(s.id); selection.delete(s.id); }
      else { Store.transact(() => { s.text = val; }, 'text'); }
    }
    editingId = null;
    render(); out.change();
  }
  function closeEditor() {
    if (editorEl) { editorEl.remove(); editorEl = null; editingId = null; }
  }
  function isEditing() { return !!editorEl; }

  /* double-click to edit text-bearing shapes */
  function onDblClick(e) {
    const wp = toWorld(eventPos(e));
    const t = pick(wp);
    if (t && (t.type === 'sticky' || t.type === 'text' || t.type === 'rect' || t.type === 'ellipse')) {
      if (t.type === 'rect' || t.type === 'ellipse') {
        // edit label
        editLabel(t);
      } else {
        selection = new Set([t.id]); out.select(getSelection());
        openEditor(t);
      }
    }
  }
  function editLabel(s) {
    closeEditor();
    editingId = s.id;
    const ta = document.createElement('textarea');
    ta.className = 'canvas-editor label-editor';
    ta.value = s.label || '';
    document.body.appendChild(ta); editorEl = ta;
    const b = Shapes.bounds(s); const sr = worldRectToScreen(b);
    const r = canvas.getBoundingClientRect();
    ta.style.left = (r.left + sr.x) + 'px'; ta.style.top = (r.top + sr.y) + 'px';
    ta.style.width = sr.w + 'px'; ta.style.height = sr.h + 'px';
    ta.style.fontSize = ((s.labelSize || 17) * cam.zoom) + 'px';
    ta.style.textAlign = 'center'; ta.style.fontWeight = 600;
    ta.style.display = 'flex';
    ta.focus(); ta.select();
    ta.addEventListener('input', () => { const c = Store.byId(editingId); if (c) { c.label = ta.value; render(); } });
    ta.addEventListener('blur', () => {
      const c = Store.byId(editingId); const val = ta.value;
      ta.remove(); editorEl = null;
      if (c) Store.transact(() => { c.label = val; }, 'label');
      editingId = null; render(); out.change();
    });
    ta.addEventListener('keydown', ev => {
      ev.stopPropagation();
      if (ev.key === 'Escape' || (ev.key === 'Enter' && !ev.shiftKey)) { ev.preventDefault(); ta.blur(); }
    });
  }

  /* keep editor glued to the shape while panning/zooming */
  function syncEditor() {
    if (editorEl && editingId != null) {
      const s = Store.byId(editingId);
      if (s) (editorEl.classList.contains('label-editor')) ? editLabelReposition(s) : positionEditor(s, editorEl);
    }
  }
  function editLabelReposition(s) {
    const b = Shapes.bounds(s); const sr = worldRectToScreen(b);
    const r = canvas.getBoundingClientRect();
    editorEl.style.left = (r.left + sr.x) + 'px'; editorEl.style.top = (r.top + sr.y) + 'px';
    editorEl.style.width = sr.w + 'px'; editorEl.style.height = sr.h + 'px';
    editorEl.style.fontSize = ((s.labelSize || 17) * cam.zoom) + 'px';
  }

  /* =====================================================
     Public commands (used by toolbar / keyboard)
  ===================================================== */
  function deleteSelection() {
    if (!selection.size) return;
    Store.transact(() => { for (const id of selection) Store.remove(id); }, 'delete');
    selection.clear();
    out.select(getSelection());
    render(); out.change();
  }
  function duplicateSelection(offset = 24) {
    if (!selection.size) return;
    const idMap = new Map();
    const clones = [];
    Store.transact(() => {
      for (const id of selection) {
        const orig = Store.byId(id);
        if (orig.type === 'connector') continue;
        const c = Store.clone(orig);
        c.id = Shapes.uid(c.type);
        Shapes.translate(c, offset, offset);
        idMap.set(id, c.id);
        Store.add(c); clones.push(c.id);
      }
      // duplicate connectors among the duplicated set, rewiring endpoints
      for (const id of selection) {
        const orig = Store.byId(id);
        if (orig.type !== 'connector') continue;
        if ((orig.from && !idMap.has(orig.from)) || (orig.to && !idMap.has(orig.to))) continue;
        const c = Store.clone(orig); c.id = Shapes.uid('connector');
        if (c.from) c.from = idMap.get(orig.from);
        if (c.to)   c.to   = idMap.get(orig.to);
        Store.add(c); clones.push(c.id);
      }
    }, 'duplicate');
    setSelection(clones);
    out.change();
  }
  function copySelection() {
    const arr = getSelection().filter(s => s.type !== 'connector').map(Store.clone);
    // include connectors fully inside selection
    const idset = new Set(selection);
    for (const s of getSelection()) {
      if (s.type === 'connector' && (!s.from || idset.has(s.from)) && (!s.to || idset.has(s.to)))
        arr.push(Store.clone(s));
    }
    return arr;
  }
  function pasteShapes(arr, atCursor) {
    if (!arr || !arr.length) return;
    const idMap = new Map();
    const added = [];
    const c = lastCursorWorld || centerWorld();
    const bb = Geo.unionRects(arr.filter(s => s.type !== 'connector').map(Shapes.bounds));
    const ox = bb ? c.x - (bb.x + bb.w / 2) : 20;
    const oy = bb ? c.y - (bb.y + bb.h / 2) : 20;
    Store.transact(() => {
      for (const s of arr) {
        if (s.type === 'connector') continue;
        const n = Store.clone(s); const old = n.id; n.id = Shapes.uid(n.type);
        Shapes.translate(n, atCursor ? ox : 24, atCursor ? oy : 24);
        idMap.set(old, n.id); Store.add(n); added.push(n.id);
      }
      for (const s of arr) {
        if (s.type !== 'connector') continue;
        const n = Store.clone(s); n.id = Shapes.uid('connector');
        if (n.from) n.from = idMap.get(n.from) || null;
        if (n.to)   n.to   = idMap.get(n.to) || null;
        Store.add(n); added.push(n.id);
      }
    }, 'paste');
    setSelection(added);
    out.change();
  }

  function selectAll() {
    setSelection(Store.shapes().map(s => s.id));
  }
  function clearSelection() { setSelection([]); }

  function applyStyle(patch) {
    if (!selection.size) return;
    Store.transact(() => {
      for (const id of selection) Object.assign(Store.byId(id), patch);
    }, 'style');
    render(); out.change();
  }

  function zOrder(op) {
    if (!selection.size) return;
    Store.transact(() => {
      for (const id of selection) Store[op](id);
    }, 'z-order');
    render(); out.change();
  }

  function nudge(dx, dy) {
    if (!selection.size) return;
    Store.transact(() => {
      for (const id of selection) Shapes.translate(Store.byId(id), dx, dy);
    }, 'nudge');
    render(); out.change();
  }

  /* =====================================================
     Camera commands
  ===================================================== */
  function centerWorld() {
    const v = viewSize();
    return Geo.screenToWorld(cam, v.w / 2, v.h / 2);
  }
  let lastCursorWorld = null;

  function setZoom(z, anchor) {
    const v = viewSize();
    const a = anchor || { x: v.w / 2, y: v.h / 2 };
    Geo.zoomAt(cam, a.x, a.y, z);
    out.camera(cam); render(); Store.save();
  }
  function zoomBy(mult) { setZoom(cam.zoom * mult); }

  function zoomToFit(pad = 80) {
    const rs = Store.shapes().map(Shapes.bounds);
    const bb = Geo.unionRects(rs);
    const v = viewSize();
    if (!bb || bb.w === 0) { cam.x = v.w / 2; cam.y = v.h / 2; cam.zoom = 1; }
    else {
      const z = Geo.clamp(Math.min((v.w - pad * 2) / bb.w, (v.h - pad * 2) / bb.h), 0.05, 4);
      cam.zoom = z;
      cam.x = v.w / 2 - (bb.x + bb.w / 2) * z;
      cam.y = v.h / 2 - (bb.y + bb.h / 2) * z;
    }
    out.camera(cam); render(); Store.save();
  }
  function zoomToSelection() {
    const bb = selectionBounds();
    if (!bb) return zoomToFit();
    const v = viewSize(); const pad = 120;
    const z = Geo.clamp(Math.min((v.w - pad) / bb.w, (v.h - pad) / bb.h), 0.1, 3);
    cam.zoom = z;
    cam.x = v.w / 2 - (bb.x + bb.w / 2) * z;
    cam.y = v.h / 2 - (bb.y + bb.h / 2) * z;
    out.camera(cam); render(); Store.save();
  }
  function resetView() { cam.x = 0; cam.y = 0; cam.zoom = 1; out.camera(cam); render(); }

  /* =====================================================
     Connector preview overlay during creation
  ===================================================== */
  const realRender = render;
  function renderWithPreview() {
    realRender();
    if (drag?.kind === 'connector' && pendingConn) {
      ctx.save();
      ctx.setTransform(dpr * cam.zoom, 0, 0, dpr * cam.zoom, dpr * cam.x, dpr * cam.y);
      const from = pendingConn.from ? Store.byId(pendingConn.from) : null;
      const a = from ? Geo.rectBorderPoint(Shapes.bounds(from), drag.wp.x, drag.wp.y)
                     : { x: pendingConn.x1, y: pendingConn.y1 };
      ctx.strokeStyle = '#3b82f6'; ctx.lineWidth = 2.5 / cam.zoom;
      ctx.setLineDash([6 / cam.zoom, 5 / cam.zoom]);
      ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(drag.wp.x, drag.wp.y); ctx.stroke();
      ctx.setLineDash([]);
      const tgt = pick(drag.wp);
      ctx.restore();
      if (tgt) outlineWorldShape(tgt);
    }
  }
  function outlineWorldShape(s) {
    const b = Shapes.bounds(s); const sr = worldRectToScreen(b);
    ctx.save(); ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.strokeStyle = '#3b82f6'; ctx.lineWidth = 2; ctx.setLineDash([4,3]);
    ctx.strokeRect(sr.x - 2, sr.y - 2, sr.w + 4, sr.h + 4); ctx.restore();
  }
  render = renderWithPreview;

  /* =====================================================
     Wire DOM events
  ===================================================== */
  function trackCursor(e) { lastCursorWorld = toWorld(eventPos(e)); }

  canvas.addEventListener('pointerdown', onPointerDown);
  window.addEventListener('pointermove', e => { onPointerMove(e); });
  window.addEventListener('pointerup', onPointerUp);
  canvas.addEventListener('pointermove', trackCursor);
  canvas.addEventListener('wheel', onWheel, { passive: false });
  canvas.addEventListener('dblclick', onDblClick);
  canvas.addEventListener('touchstart', onTouchStart, { passive: false });
  canvas.addEventListener('touchmove', onTouchMove, { passive: false });
  canvas.addEventListener('touchend', onTouchEnd);
  // context menu disabled so right-drag could pan in future
  canvas.addEventListener('contextmenu', e => e.preventDefault());

  window.addEventListener('keydown', e => {
    if (e.code === 'Space' && !isEditing() && !spaceDown) {
      spaceDown = true; updateCursor(); e.preventDefault();
    }
  });
  window.addEventListener('keyup', e => {
    if (e.code === 'Space') { spaceDown = false; updateCursor(); }
  });

  const ro = new ResizeObserver(() => { resize(); syncEditor(); });
  ro.observe(canvas);

  // keep editor positioned during any camera change
  const origCam = out.camera;
  out.camera = (c) => { origCam(c); syncEditor(); };

  resize();

  /* =====================================================
     Public API
  ===================================================== */
  return {
    render, resize,
    setTool, getTool,
    getSelection, setSelection, clearSelection, selectAll,
    deleteSelection, duplicateSelection, copySelection, pasteShapes,
    applyStyle, zOrder, nudge,
    setZoom, zoomBy, zoomToFit, zoomToSelection, resetView,
    get camera() { return cam; },
    isEditing,
    closeEditor,
    centerWorld,
    selectionBounds,
  };
}

if (typeof window !== 'undefined') window.createBoard = createBoard;
