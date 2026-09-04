/* =========================================================
   FLUXNODE — editor.js
   The interactive node editor. Owns:
     • the camera (pan/zoom) and world↔screen transform
     • the scaling dotted grid (canvas)
     • node DOM rendering + inline controls
     • port hit-testing and wire creation (SVG beziers)
     • node dragging, click/box selection
     • the minimap
     • localStorage autosave + JSON import/export
     • triggering graph evaluation and pushing the result to previews
   The Editor exposes a small API consumed by app.js.
   ========================================================= */
'use strict';

const Editor = (() => {
  const KEY = 'fluxnode.graph.v1';

  /* ---- DOM refs ---- */
  let stage, gridCanvas, gctx, worldEl, wiresSvg, minimap, mctx;
  let nameEl, saveEl, evalHud, evalErr, evalCount, evalSorted;

  /* ---- state ---- */
  let graph = new Graph();
  let cam = { x: 0, y: 0, zoom: 1 };
  let docName = 'Untitled flow';
  const nodeEls = new Map();        // nodeId -> { el, ports:Map(portKey->{el,kind}), thumb }
  const selected = new Set();       // selected node ids
  let selectedEdge = null;
  let lastEval = null;
  let animT = 0, animating = false, rafId = 0;
  const listeners = new Set();

  function on(fn) { listeners.add(fn); }
  function emit() { listeners.forEach(fn => fn()); }

  /* ════════ camera / transform ════════ */
  function applyCamera() {
    worldEl.style.transform = `translate(${cam.x}px, ${cam.y}px) scale(${cam.zoom})`;
    drawGrid();
    layoutWires();
    drawMinimap();
    emit();
  }
  function screenToWorld(sx, sy) {
    return { x: (sx - cam.x) / cam.zoom, y: (sy - cam.y) / cam.zoom };
  }
  function worldToScreen(wx, wy) {
    return { x: wx * cam.zoom + cam.x, y: wy * cam.zoom + cam.y };
  }
  function setZoom(z, cx, cy) {
    z = FX.clamp(z, 0.2, 3);
    const rect = stage.getBoundingClientRect();
    cx = cx ?? rect.width / 2; cy = cy ?? rect.height / 2;
    const before = screenToWorld(cx, cy);
    cam.zoom = z;
    const after = screenToWorld(cx, cy);
    cam.x += (after.x - before.x) * cam.zoom;
    cam.y += (after.y - before.y) * cam.zoom;
    applyCamera();
  }

  /* ════════ grid ════════ */
  function drawGrid() {
    const w = stage.clientWidth, h = stage.clientHeight;
    if (gridCanvas.width !== w || gridCanvas.height !== h) { gridCanvas.width = w; gridCanvas.height = h; }
    gctx.clearRect(0, 0, w, h);
    const step = 28 * cam.zoom;
    if (step < 6) return;
    const ox = ((cam.x % step) + step) % step;
    const oy = ((cam.y % step) + step) % step;
    // minor dots
    gctx.fillStyle = 'rgba(255,255,255,0.045)';
    for (let x = ox; x < w; x += step) {
      for (let y = oy; y < h; y += step) {
        gctx.fillRect(x, y, 1.4, 1.4);
      }
    }
    // major lines every 4
    const major = step * 4;
    const mox = ((cam.x % major) + major) % major;
    const moy = ((cam.y % major) + major) % major;
    gctx.strokeStyle = 'rgba(255,255,255,0.04)';
    gctx.lineWidth = 1;
    gctx.beginPath();
    for (let x = mox; x < w; x += major) { gctx.moveTo(x + .5, 0); gctx.lineTo(x + .5, h); }
    for (let y = moy; y < h; y += major) { gctx.moveTo(0, y + .5); gctx.lineTo(w, y + .5); }
    gctx.stroke();
  }

  /* ════════ node rendering ════════ */
  const NODE_W = 200, OUT_W = 268;

  function nodeWidth(node) { return NodeTypes[node.type].kind === 'output' ? OUT_W : NODE_W; }

  function buildNode(node) {
    const T = NodeTypes[node.type];
    const el = document.createElement('div');
    el.className = 'node kind-' + T.key;
    if (T.kind === 'output') el.classList.add('kind-output');
    el.style.left = node.x + 'px';
    el.style.top = node.y + 'px';
    el.dataset.id = node.id;

    // head
    const head = document.createElement('div');
    head.className = 'node-head';
    head.style.background = `linear-gradient(180deg, ${hexA(T.color, .26)}, ${hexA(T.color, .10)})`;
    head.innerHTML =
      `<span class="dot" style="background:${T.color}"></span>` +
      `<span class="ttl">${T.name}</span>` +
      `<span class="badge">${node.id.replace(/_.*/, '')}</span>`;
    el.appendChild(head);

    const body = document.createElement('div');
    body.className = 'node-body';

    const portsMap = new Map();

    // input port rows
    const inWrap = document.createElement('div'); inWrap.className = 'ports';
    T.inputs.forEach(p => {
      const row = document.createElement('div'); row.className = 'port-row in';
      const dot = document.createElement('div');
      dot.className = `port in type-${p.type}`;
      dot.dataset.node = node.id; dot.dataset.port = p.key; dot.dataset.dir = 'in'; dot.dataset.ptype = p.type;
      dot.title = `${p.label} · ${p.type}`;
      const lbl = document.createElement('span'); lbl.className = 'port-label'; lbl.textContent = p.label;
      row.appendChild(dot); row.appendChild(lbl);
      inWrap.appendChild(row);
      portsMap.set('in:' + p.key, { el: dot, type: p.type });
    });
    if (T.inputs.length) body.appendChild(inWrap);

    // controls
    T.params.forEach(pm => body.appendChild(buildControl(node, pm)));

    // thumbnail for source/operator/output nodes (anything producing color)
    let thumb = null;
    if (T.outputs.some(o => o.type === PORT.COLOR) || T.kind === 'output') {
      thumb = document.createElement('canvas');
      thumb.className = 'node-thumb';
      thumb.width = T.kind === 'output' ? 256 : 120;
      thumb.height = T.kind === 'output' ? 192 : 64;
      body.appendChild(thumb);
    }

    // output port rows
    const outWrap = document.createElement('div'); outWrap.className = 'ports';
    T.outputs.forEach(p => {
      const row = document.createElement('div'); row.className = 'port-row out';
      const dot = document.createElement('div');
      dot.className = `port out type-${p.type}`;
      dot.dataset.node = node.id; dot.dataset.port = p.key; dot.dataset.dir = 'out'; dot.dataset.ptype = p.type;
      dot.title = `${p.label} · ${p.type}`;
      const lbl = document.createElement('span'); lbl.className = 'port-label'; lbl.textContent = p.label;
      row.appendChild(lbl); row.appendChild(dot);
      outWrap.appendChild(row);
      portsMap.set('out:' + p.key, { el: dot, type: p.type });
    });
    if (T.outputs.length) body.appendChild(outWrap);

    el.appendChild(body);
    worldEl.appendChild(el);
    nodeEls.set(node.id, { el, ports: portsMap, thumb });
    return el;
  }

  function buildControl(node, pm) {
    const wrap = document.createElement('div'); wrap.className = 'ctrl';
    const id = node.id + '_' + pm.key;

    if (pm.type === 'range' || pm.type === 'number') {
      const row = document.createElement('div'); row.className = 'ctrl-row';
      const lab = document.createElement('label'); lab.textContent = pm.label; lab.htmlFor = id;
      const val = document.createElement('span'); val.className = 'val';
      row.appendChild(lab); row.appendChild(val); wrap.appendChild(row);

      const input = document.createElement('input');
      input.type = pm.type === 'range' ? 'range' : 'number';
      input.id = id;
      if (pm.min !== undefined) input.min = pm.min;
      if (pm.max !== undefined) input.max = pm.max;
      if (pm.step !== undefined) input.step = pm.step;
      input.value = node.params[pm.key];
      const fmt = v => (pm.step && pm.step < 1 ? (+v).toFixed(2) : (+v).toString()) + (pm.unit || '');
      val.textContent = fmt(input.value);
      input.addEventListener('input', () => {
        node.params[pm.key] = parseFloat(input.value);
        val.textContent = fmt(input.value);
        scheduleEval(); saveSoon();
      });
      stopDrag(input);
      wrap.appendChild(input);

    } else if (pm.type === 'color') {
      const row = document.createElement('div'); row.className = 'ctrl-row';
      const lab = document.createElement('label'); lab.textContent = pm.label;
      const input = document.createElement('input'); input.type = 'color'; input.id = id;
      input.value = node.params[pm.key];
      input.addEventListener('input', () => { node.params[pm.key] = input.value; scheduleEval(); saveSoon(); });
      stopDrag(input);
      row.appendChild(lab); row.appendChild(input); wrap.appendChild(row);

    } else if (pm.type === 'select') {
      const row = document.createElement('div'); row.className = 'ctrl-row';
      const lab = document.createElement('label'); lab.textContent = pm.label;
      const sel = document.createElement('select'); sel.id = id;
      pm.options.forEach(o => { const op = document.createElement('option'); op.value = o; op.textContent = o; sel.appendChild(op); });
      sel.value = node.params[pm.key];
      sel.addEventListener('change', () => { node.params[pm.key] = sel.value; scheduleEval(); saveSoon(); });
      stopDrag(sel);
      row.appendChild(lab); row.appendChild(sel); wrap.appendChild(row);
    }
    return wrap;
  }

  // prevent control interactions from starting a node drag
  function stopDrag(el) {
    el.addEventListener('pointerdown', e => e.stopPropagation());
  }

  function hexA(hex, a) {
    const h = hex.replace('#', '');
    const r = parseInt(h.slice(0, 2), 16), g = parseInt(h.slice(2, 4), 16), b = parseInt(h.slice(4, 6), 16);
    return `rgba(${r},${g},${b},${a})`;
  }

  function rebuildAllNodes() {
    nodeEls.forEach(rec => rec.el.remove());
    nodeEls.clear();
    for (const node of graph.nodes.values()) buildNode(node);
    refreshSelectionClasses();
    layoutWires();
  }

  /* ════════ wires (SVG bezier) ════════ */
  // port centre in WORLD coords, derived from node pos + measured port offset
  function portWorldPos(nodeId, dir, portKey) {
    const rec = nodeEls.get(nodeId);
    const node = graph.nodes.get(nodeId);
    if (!rec || !node) return null;
    const p = rec.ports.get(dir + ':' + portKey);
    if (!p) return null;
    // offset of port centre relative to node element, in node-local (unscaled) px
    const nodeRect = rec.el.getBoundingClientRect();
    const portRect = p.el.getBoundingClientRect();
    const lx = (portRect.left + portRect.width / 2 - nodeRect.left) / cam.zoom;
    const ly = (portRect.top + portRect.height / 2 - nodeRect.top) / cam.zoom;
    return { x: node.x + lx, y: node.y + ly, type: p.type };
  }

  function bezier(p1, p2) {
    const dx = Math.max(40, Math.abs(p2.x - p1.x) * 0.5);
    return `M ${p1.x} ${p1.y} C ${p1.x + dx} ${p1.y}, ${p2.x - dx} ${p2.y}, ${p2.x} ${p2.y}`;
  }

  const typeColor = t => (t === PORT.NUMBER ? 'var(--t-number)' : 'var(--t-color)');

  let tempWire = null;
  function layoutWires() {
    // size svg to viewport, draw in world space by translating its group
    while (wiresSvg.firstChild) wiresSvg.removeChild(wiresSvg.firstChild);
    const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    g.setAttribute('transform', `translate(${cam.x},${cam.y}) scale(${cam.zoom})`);
    wiresSvg.appendChild(g);

    // mark connected ports
    nodeEls.forEach(rec => rec.ports.forEach(p => p.el.classList.remove('connected')));

    for (const e of graph.edges.values()) {
      const a = portWorldPos(e.from.node, 'out', e.from.port);
      const b = portWorldPos(e.to.node, 'in', e.to.port);
      if (!a || !b) continue;
      const col = typeColor(a.type);
      // fat invisible hit path
      const hit = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      hit.setAttribute('class', 'wire-hit'); hit.setAttribute('d', bezier(a, b));
      hit.dataset.edge = e.id;
      g.appendChild(hit);
      // visible wire
      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('class', 'wire' + (selectedEdge === e.id ? ' sel' : ''));
      path.setAttribute('d', bezier(a, b));
      path.setAttribute('stroke', col);
      path.dataset.edge = e.id;
      g.appendChild(path);
      // light up endpoints
      markConnected(e.from.node, 'out', e.from.port);
      markConnected(e.to.node, 'in', e.to.port);
    }

    if (tempWire) {
      const tp = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      tp.setAttribute('class', 'temp');
      tp.setAttribute('d', bezier(tempWire.from, tempWire.to));
      g.appendChild(tp);
    }
  }
  function markConnected(nodeId, dir, portKey) {
    const rec = nodeEls.get(nodeId);
    if (rec) { const p = rec.ports.get(dir + ':' + portKey); if (p) p.el.classList.add('connected'); }
  }

  /* ════════ evaluation + previews ════════ */
  let evalTimer = null;
  function scheduleEval() {
    if (evalTimer) return;
    evalTimer = requestAnimationFrame(() => { evalTimer = null; evaluate(); });
  }
  function evaluate() {
    lastEval = graph.evaluate();
    // per-node error styling
    nodeEls.forEach((rec, id) => {
      const node = graph.nodes.get(id);
      rec.el.classList.toggle('eval-error', !!(node && node._error));
    });
    renderThumbs();
    updateHud();
    maybeAnimate();
    emit();
  }

  function renderThumbs() {
    if (!lastEval) return;
    for (const [id, rec] of nodeEls) {
      if (!rec.thumb) continue;
      const node = graph.nodes.get(id);
      const T = NodeTypes[node.type];
      let sampler;
      if (T.kind === 'output') {
        sampler = lastEval.outputNode && lastEval.outputNode._sampler;
      } else {
        const out = lastEval.values.get(id);
        const portKey = T.outputs.find(o => o.type === PORT.COLOR)?.key;
        sampler = out && portKey && typeof out[portKey] === 'function' ? out[portKey] : null;
      }
      const ctx = rec.thumb.getContext('2d');
      if (sampler) renderSampler(ctx, sampler, rec.thumb.width, rec.thumb.height, animT);
      else { ctx.fillStyle = '#0d1117'; ctx.fillRect(0, 0, rec.thumb.width, rec.thumb.height); }
    }
  }

  function updateHud() {
    if (!evalHud) return;
    const err = lastEval && (lastEval.cyclic || lastEval.errors > 0);
    evalHud.classList.toggle('has-error', !!err);
    evalErr.textContent = lastEval
      ? (lastEval.cyclic ? 'cycle detected' : lastEval.errors ? lastEval.errors + ' node error' : 'live')
      : '—';
    evalCount.textContent = graph.nodes.size;
    evalSorted.textContent = lastEval ? lastEval.order.length : 0;
  }

  /* if any warp node has flow>0, run an animation loop for the preview */
  function anyAnimated() {
    for (const n of graph.nodes.values()) {
      if (n.type === 'warp' && n.params.flow > 0) return true;
      if (n.type === 'math' && n.params.op === 'sine') {
        // only animate sine if it feeds a warp flow... keep simple: animate if a warp is animated
      }
    }
    return false;
  }
  function maybeAnimate() {
    const want = anyAnimated() && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (want && !animating) { animating = true; loop(); }
    if (!want) { animating = false; cancelAnimationFrame(rafId); animT = 0; }
  }
  let lastFrame = 0;
  function loop(ts) {
    if (!animating) return;
    animT += 0.016;
    // re-render only the output + animated thumbs at ~30fps
    if (!ts || ts - lastFrame > 33) {
      lastFrame = ts || 0;
      renderThumbs();
    }
    rafId = requestAnimationFrame(loop);
  }

  /* ════════ selection ════════ */
  function refreshSelectionClasses() {
    nodeEls.forEach((rec, id) => rec.el.classList.toggle('sel', selected.has(id)));
  }
  function selectOnly(id) { selected.clear(); if (id) selected.add(id); selectedEdge = null; refreshSelectionClasses(); layoutWires(); emit(); }
  function toggleSelect(id) { if (selected.has(id)) selected.delete(id); else selected.add(id); selectedEdge = null; refreshSelectionClasses(); emit(); }
  function clearSelection() { selected.clear(); selectedEdge = null; refreshSelectionClasses(); layoutWires(); emit(); }

  /* ════════ node ops ════════ */
  function addNode(typeKey, worldX, worldY) {
    const node = graph.addNode(typeKey, Math.round(worldX), Math.round(worldY));
    buildNode(node);
    selectOnly(node.id);
    scheduleEval(); saveSoon();
    return node;
  }
  function deleteSelected() {
    if (selectedEdge) { graph.disconnect(selectedEdge); selectedEdge = null; layoutWires(); scheduleEval(); saveSoon(); return; }
    if (!selected.size) return;
    selected.forEach(id => { graph.removeNode(id); const rec = nodeEls.get(id); if (rec) rec.el.remove(); nodeEls.delete(id); });
    selected.clear();
    layoutWires(); scheduleEval(); saveSoon();
  }
  function duplicateSelected() {
    if (!selected.size) return;
    const idMap = new Map();
    const newIds = [];
    selected.forEach(id => {
      const src = graph.nodes.get(id);
      const n = graph.addNode(src.type, src.x + 36, src.y + 36, JSON.parse(JSON.stringify(src.params)));
      idMap.set(id, n.id); newIds.push(n.id); buildNode(n);
    });
    // copy internal edges
    for (const e of [...graph.edges.values()]) {
      if (idMap.has(e.from.node) && idMap.has(e.to.node)) {
        graph.connect({ node: idMap.get(e.from.node), port: e.from.port }, { node: idMap.get(e.to.node), port: e.to.port });
      }
    }
    selected.clear(); newIds.forEach(i => selected.add(i));
    refreshSelectionClasses(); layoutWires(); scheduleEval(); saveSoon();
  }

  /* ════════ document ops ════════ */
  function loadGraph(doc, name) {
    graph = Graph.fromJSON(doc);
    docName = name || doc.name || 'Untitled flow';
    if (nameEl) nameEl.textContent = docName;
    selected.clear(); selectedEdge = null;
    rebuildAllNodes();
    evaluate();
    fitView(true);
    saveSoon();
  }
  function newGraph() { loadGraph({ nodes: [], edges: [] }, 'Untitled flow'); }
  function getDoc() { return { app: 'Fluxnode', version: 1, name: docName, ...graph.toJSON(), camera: cam }; }
  function setName(n) { docName = n; saveSoon(); }

  /* ════════ persistence ════════ */
  let saveTimer = null;
  function saveSoon() {
    if (saveEl) { saveEl.textContent = 'Saving…'; saveEl.classList.add('saving'); }
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      try {
        localStorage.setItem(KEY, JSON.stringify({ name: docName, ...graph.toJSON(), camera: cam, savedAt: Date.now() }));
      } catch (e) { /* private mode / quota */ }
      if (saveEl) { saveEl.textContent = 'All changes saved'; saveEl.classList.remove('saving'); }
    }, 350);
  }
  function loadFromStorage() {
    try {
      const raw = localStorage.getItem(KEY);
      if (!raw) return false;
      const data = JSON.parse(raw);
      if (!data || !Array.isArray(data.nodes)) return false;
      graph = Graph.fromJSON(data);
      docName = data.name || 'Untitled flow';
      if (data.camera) cam = data.camera;
      return true;
    } catch (e) { return false; }
  }

  /* ════════ minimap ════════ */
  function graphBounds() {
    if (!graph.nodes.size) return { x: 0, y: 0, w: 1000, h: 700 };
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (const n of graph.nodes.values()) {
      const rec = nodeEls.get(n.id);
      const w = rec ? rec.el.offsetWidth : NODE_W;
      const h = rec ? rec.el.offsetHeight : 120;
      minX = Math.min(minX, n.x); minY = Math.min(minY, n.y);
      maxX = Math.max(maxX, n.x + w); maxY = Math.max(maxY, n.y + h);
    }
    return { x: minX, y: minY, w: maxX - minX, h: maxY - minY };
  }
  function drawMinimap() {
    if (!minimap) return;
    const W = minimap.width, H = minimap.height;
    mctx.clearRect(0, 0, W, H);
    mctx.fillStyle = '#0b0f15'; mctx.fillRect(0, 0, W, H);
    const b = graphBounds();
    const pad = 40;
    const scale = Math.min((W) / (b.w + pad * 2), (H) / (b.h + pad * 2));
    const offX = (W - b.w * scale) / 2 - b.x * scale;
    const offY = (H - b.h * scale) / 2 - b.y * scale;
    const tx = (wx) => wx * scale + offX, ty = (wy) => wy * scale + offY;
    // edges
    mctx.strokeStyle = 'rgba(124,92,255,.5)'; mctx.lineWidth = 1;
    for (const e of graph.edges.values()) {
      const a = graph.nodes.get(e.from.node), c = graph.nodes.get(e.to.node);
      if (!a || !c) continue;
      mctx.beginPath(); mctx.moveTo(tx(a.x + 100), ty(a.y + 30)); mctx.lineTo(tx(c.x), ty(c.y + 30)); mctx.stroke();
    }
    // nodes
    for (const n of graph.nodes.values()) {
      const rec = nodeEls.get(n.id);
      const w = rec ? rec.el.offsetWidth : NODE_W, h = rec ? rec.el.offsetHeight : 120;
      mctx.fillStyle = selected.has(n.id) ? '#7c5cff' : (NodeTypes[n.type].color);
      mctx.globalAlpha = selected.has(n.id) ? 1 : .8;
      mctx.fillRect(tx(n.x), ty(n.y), Math.max(2, w * scale), Math.max(2, h * scale));
      mctx.globalAlpha = 1;
    }
    // viewport rect
    const vw = screenToWorld(0, 0), ve = screenToWorld(stage.clientWidth, stage.clientHeight);
    mctx.strokeStyle = 'rgba(255,255,255,.7)'; mctx.lineWidth = 1;
    mctx.strokeRect(tx(vw.x), ty(vw.y), (ve.x - vw.x) * scale, (ve.y - vw.y) * scale);
    minimap._map = { scale, offX, offY };
  }

  /* ════════ fit / center ════════ */
  function fitView(instant) {
    const b = graphBounds();
    const pad = 80;
    const sw = stage.clientWidth, sh = stage.clientHeight;
    const z = FX.clamp(Math.min(sw / (b.w + pad * 2), sh / (b.h + pad * 2)), 0.2, 1.4);
    cam.zoom = z;
    cam.x = (sw - b.w * z) / 2 - b.x * z;
    cam.y = (sh - b.h * z) / 2 - b.y * z;
    applyCamera();
  }

  /* ════════ pointer interaction ════════ */
  let drag = null;  // current gesture

  function findPort(target) {
    if (target.classList && target.classList.contains('port')) return target;
    return null;
  }

  function init(refs) {
    stage = refs.stage; gridCanvas = refs.grid; worldEl = refs.world; wiresSvg = refs.wires;
    minimap = refs.minimap; nameEl = refs.name; saveEl = refs.save;
    evalHud = refs.evalHud; evalErr = refs.evalErr; evalCount = refs.evalCount; evalSorted = refs.evalSorted;
    gctx = gridCanvas.getContext('2d');
    mctx = minimap ? minimap.getContext('2d') : null;

    window.addEventListener('resize', () => applyCamera());

    /* ---- wheel zoom / pan ---- */
    stage.addEventListener('wheel', e => {
      e.preventDefault();
      const rect = stage.getBoundingClientRect();
      if (e.ctrlKey || e.metaKey) {
        const f = Math.exp(-e.deltaY * 0.01);
        setZoom(cam.zoom * f, e.clientX - rect.left, e.clientY - rect.top);
      } else {
        const factor = Math.exp(-e.deltaY * 0.0015);
        setZoom(cam.zoom * factor, e.clientX - rect.left, e.clientY - rect.top);
      }
    }, { passive: false });

    /* ---- pointer down ---- */
    stage.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);

    /* ---- wire click select / delete ---- */
    wiresSvg.addEventListener('pointerdown', e => {
      const eid = e.target.dataset && e.target.dataset.edge;
      if (eid) {
        e.stopPropagation();
        selectedEdge = eid; selected.clear(); refreshSelectionClasses(); layoutWires(); emit();
      }
    });

    /* ---- minimap click to jump ---- */
    if (minimap) {
      minimap.addEventListener('pointerdown', e => {
        const m = minimap._map; if (!m) return;
        const r = minimap.getBoundingClientRect();
        const wx = (e.clientX - r.left - m.offX) / m.scale;
        const wy = (e.clientY - r.top - m.offY) / m.scale;
        cam.x = stage.clientWidth / 2 - wx * cam.zoom;
        cam.y = stage.clientHeight / 2 - wy * cam.zoom;
        applyCamera();
      });
    }
  }

  function onPointerDown(e) {
    if (e.button === 1 || (e.button === 0 && spaceDown)) { // middle / space pan
      drag = { mode: 'pan', sx: e.clientX, sy: e.clientY, cx: cam.x, cy: cam.y };
      stage.classList.add('panning'); stage.setPointerCapture?.(e.pointerId);
      return;
    }
    if (e.button !== 0) return;
    const rect = stage.getBoundingClientRect();
    const port = findPort(e.target);

    if (port) { // start a wire
      e.preventDefault();
      const nodeId = port.dataset.node, portKey = port.dataset.port, dir = port.dataset.dir;
      if (dir === 'out') {
        const start = portWorldPos(nodeId, 'out', portKey);
        tempWire = { from: start, to: start, src: { node: nodeId, port: portKey, dir: 'out', type: port.dataset.ptype } };
      } else {
        // dragging from an input: if it has a wire, grab that wire's source; else start new from this input upstream-less (drop to connect)
        const existing = graph.edgeInto(nodeId, portKey);
        if (existing) {
          const src = existing.from;
          graph.disconnect(existing.id);
          const start = portWorldPos(src.node, 'out', src.port);
          tempWire = { from: start, to: start, src: { node: src.node, port: src.port, dir: 'out', type: port.dataset.ptype } };
          scheduleEval(); saveSoon();
        } else {
          const start = portWorldPos(nodeId, 'in', portKey);
          tempWire = { from: start, to: start, src: { node: nodeId, port: portKey, dir: 'in', type: port.dataset.ptype }, fromInput: true };
        }
      }
      drag = { mode: 'wire' };
      stage.classList.add('wiring');
      layoutWires();
      return;
    }

    const nodeEl = e.target.closest('.node');
    if (nodeEl) {
      const id = nodeEl.dataset.id;
      if (e.shiftKey) { toggleSelect(id); }
      else if (!selected.has(id)) { selectOnly(id); }
      // begin drag of all selected
      const start = {};
      selected.forEach(sid => { const n = graph.nodes.get(sid); start[sid] = { x: n.x, y: n.y }; });
      drag = { mode: 'node', sx: e.clientX, sy: e.clientY, start, moved: false };
      stage.setPointerCapture?.(e.pointerId);
      return;
    }

    // empty canvas: box select or pan-with-drag (we use box-select)
    if (!e.shiftKey) clearSelection();
    const w = screenToWorld(e.clientX - rect.left, e.clientY - rect.top);
    drag = { mode: 'marquee', sx: e.clientX - rect.left, sy: e.clientY - rect.top, wx: w.x, wy: w.y, additive: e.shiftKey };
    showMarquee(drag.sx, drag.sy, 0, 0);
  }

  function onPointerMove(e) {
    if (!drag) return;
    const rect = stage.getBoundingClientRect();

    if (drag.mode === 'pan') {
      cam.x = drag.cx + (e.clientX - drag.sx);
      cam.y = drag.cy + (e.clientY - drag.sy);
      applyCamera();
    } else if (drag.mode === 'node') {
      const dx = (e.clientX - drag.sx) / cam.zoom, dy = (e.clientY - drag.sy) / cam.zoom;
      if (Math.abs(dx) + Math.abs(dy) > 1) drag.moved = true;
      for (const sid of selected) {
        const n = graph.nodes.get(sid), rec = nodeEls.get(sid);
        n.x = Math.round(drag.start[sid].x + dx); n.y = Math.round(drag.start[sid].y + dy);
        rec.el.style.left = n.x + 'px'; rec.el.style.top = n.y + 'px';
      }
      layoutWires(); drawMinimap();
    } else if (drag.mode === 'wire') {
      const w = screenToWorld(e.clientX - rect.left, e.clientY - rect.top);
      tempWire.to = w;
      // snap preview to a hovered compatible port
      layoutWires();
    } else if (drag.mode === 'marquee') {
      const cx = e.clientX - rect.left, cy = e.clientY - rect.top;
      const x = Math.min(cx, drag.sx), y = Math.min(cy, drag.sy);
      const w = Math.abs(cx - drag.sx), h = Math.abs(cy - drag.sy);
      showMarquee(x, y, w, h);
      // live select
      const w0 = screenToWorld(x, y), w1 = screenToWorld(x + w, y + h);
      if (!drag.additive) selected.clear();
      for (const n of graph.nodes.values()) {
        const rec = nodeEls.get(n.id);
        const nw = rec.el.offsetWidth, nh = rec.el.offsetHeight;
        if (n.x < w1.x && n.x + nw > w0.x && n.y < w1.y && n.y + nh > w0.y) selected.add(n.id);
      }
      refreshSelectionClasses();
    }
  }

  function onPointerUp(e) {
    if (!drag) return;
    if (drag.mode === 'wire') {
      const port = findPort(e.target);
      if (port && tempWire) {
        const drop = { node: port.dataset.node, port: port.dataset.port, dir: port.dataset.dir };
        let from, to;
        if (tempWire.fromInput) {
          // started at an empty input, dropped on an output
          if (drop.dir === 'out') { from = drop; to = tempWire.src; }
        } else if (tempWire.src.dir === 'out') {
          if (drop.dir === 'in') { from = tempWire.src; to = drop; }
        }
        if (from && to) {
          const res = graph.connect({ node: from.node, port: from.port }, { node: to.node, port: to.port });
          if (!res.ok) toast(connectError(res.reason));
          else { scheduleEval(); saveSoon(); }
        }
      }
      tempWire = null;
      stage.classList.remove('wiring');
      layoutWires();
    } else if (drag.mode === 'node') {
      if (drag.moved) saveSoon();
    } else if (drag.mode === 'marquee') {
      hideMarquee();
      layoutWires(); emit();
    } else if (drag.mode === 'pan') {
      stage.classList.remove('panning');
    }
    drag = null;
  }

  function connectError(reason) {
    return ({ type: 'Type mismatch — can\'t wire color↔number.', cycle: 'Blocked: that would create a cycle.', self: 'Can\'t wire a node to itself.' })[reason] || 'Connection not allowed.';
  }

  /* ---- marquee element ---- */
  let marqueeEl = null;
  function showMarquee(x, y, w, h) {
    if (!marqueeEl) { marqueeEl = document.getElementById('marquee'); }
    if (!marqueeEl) return;
    marqueeEl.style.display = 'block';
    marqueeEl.style.left = x + 'px'; marqueeEl.style.top = y + 'px';
    marqueeEl.style.width = w + 'px'; marqueeEl.style.height = h + 'px';
  }
  function hideMarquee() { if (marqueeEl) marqueeEl.style.display = 'none'; }

  /* ---- space-to-pan tracking ---- */
  let spaceDown = false;
  window.addEventListener('keydown', e => { if (e.code === 'Space' && !isTyping(e)) { spaceDown = true; stage && stage.classList.add('panning'); } });
  window.addEventListener('keyup', e => { if (e.code === 'Space') { spaceDown = false; stage && !drag && stage.classList.remove('panning'); } });
  function isTyping(e) {
    const t = e.target;
    return t && (t.tagName === 'INPUT' || t.tagName === 'SELECT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
  }

  /* ---- toast ---- */
  let toastEl, toastTimer;
  function toast(msg) {
    toastEl = toastEl || document.getElementById('toast');
    if (!toastEl) return;
    toastEl.textContent = msg; toastEl.classList.add('show');
    clearTimeout(toastTimer); toastTimer = setTimeout(() => toastEl.classList.remove('show'), 2200);
  }

  /* ---- export the preview image ---- */
  function exportPreview(size = 1024) {
    if (!lastEval || !lastEval.output) { toast('No Output node connected.'); return; }
    const c = document.createElement('canvas'); c.width = size; c.height = size;
    renderSampler(c.getContext('2d'), lastEval.output, size, size, animT);
    const a = document.createElement('a');
    a.download = (docName || 'fluxnode').replace(/\s+/g, '-').toLowerCase() + '.png';
    a.href = c.toDataURL('image/png'); a.click();
  }

  return {
    init, loadGraph, newGraph, getDoc, setName,
    loadFromStorage, applyCamera, evaluate, fitView,
    addNode, deleteSelected, duplicateSelected,
    setZoom, screenToWorld, toast, exportPreview,
    on, isTyping,
    get cam() { return cam; }, get graph() { return graph; },
    get selected() { return selected; }, get lastEval() { return lastEval; },
    get name() { return docName; },
  };
})();

if (typeof window !== 'undefined') window.Editor = Editor;
