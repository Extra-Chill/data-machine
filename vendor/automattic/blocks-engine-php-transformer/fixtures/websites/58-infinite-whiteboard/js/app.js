/* =========================================================
   BOUNDLESS — app.js
   The application shell for the editor page. Wires the toolbar,
   keyboard shortcuts, the properties inspector, the minimap, the
   file menu (new / clear / export PNG / export+import JSON), the
   zoom HUD, and the autosave/restore lifecycle.
   ========================================================= */
'use strict';

(function () {
  const canvas = document.getElementById('board');
  if (!canvas) return;

  /* ---- restore or seed ---- */
  const restored = Store.load();
  if (!restored || !Store.shapes().length) {
    Store.setDocument(Seed.defaultBoard(), { history: false });
  }
  // optional ?template= from templates.html
  const params = new URLSearchParams(location.search);
  const t = params.get('template');
  if (t && Seed.templates[t]) {
    Store.setDocument(Seed.templates[t].build(), { history: false });
    history.replaceState(null, '', 'index.html');
  }

  const clipboard = { data: null };

  const board = createBoard(canvas, {
    onChange: scheduleSave,
    onSelect: onSelectionChange,
    onTool:   reflectTool,
    onCamera: onCamera,
  });

  // if there was no saved camera (fresh seed) fit the board
  if (!restored) requestAnimationFrame(() => board.zoomToFit());

  /* =====================================================
     Toolbar
  ===================================================== */
  const tools = document.querySelectorAll('[data-tool]');
  tools.forEach(btn => btn.addEventListener('click', () => board.setTool(btn.dataset.tool)));
  function reflectTool(t) {
    tools.forEach(b => b.classList.toggle('is-active', b.dataset.tool === t));
  }
  reflectTool(board.getTool());

  /* action buttons */
  bind('act-undo',   () => { Store.undo(); board.clearSelection(); board.render(); });
  bind('act-redo',   () => { Store.redo(); board.render(); });
  bind('act-delete', () => board.deleteSelection());
  bind('act-dupe',   () => board.duplicateSelection());
  bind('act-front',  () => board.zOrder('bringToFront'));
  bind('act-back',   () => board.zOrder('sendToBack'));

  bind('zoom-in',  () => board.zoomBy(1.2));
  bind('zoom-out', () => board.zoomBy(1 / 1.2));
  bind('zoom-fit', () => board.zoomToFit());
  bind('zoom-reset', () => board.setZoom(1));

  function bind(id, fn) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', e => { e.preventDefault(); fn(); });
  }

  /* =====================================================
     File menu
  ===================================================== */
  const menuBtn = document.getElementById('menu-btn');
  const menu = document.getElementById('file-menu');
  if (menuBtn && menu) {
    menuBtn.addEventListener('click', e => {
      e.stopPropagation();
      const open = menu.classList.toggle('open');
      menuBtn.setAttribute('aria-expanded', open);
    });
    document.addEventListener('click', () => { menu.classList.remove('open'); menuBtn.setAttribute('aria-expanded', false); });
    menu.addEventListener('click', e => e.stopPropagation());
  }

  bind('mi-new', () => {
    if (confirm('Start a new blank board? Your current board is autosaved in this browser and will be replaced.')) {
      Store.setDocument({ shapes: [], name: 'Untitled board' });
      board.resetView(); board.clearSelection();
    }
    menu?.classList.remove('open');
  });
  bind('mi-roadmap', () => { Store.setDocument(Seed.defaultBoard()); board.zoomToFit(); menu?.classList.remove('open'); });
  bind('mi-clear', () => {
    if (board.getSelection().length && confirm('Delete selected objects?')) { board.deleteSelection(); }
    else if (confirm('Clear the entire board?')) { Store.setDocument({ shapes: [], name: Store.state.name }); board.clearSelection(); }
    menu?.classList.remove('open');
  });
  bind('mi-export-png', () => { exportPNG(); menu?.classList.remove('open'); });
  bind('mi-export-json', () => { exportJSONFile(); menu?.classList.remove('open'); });
  bind('mi-import-json', () => { document.getElementById('import-file').click(); menu?.classList.remove('open'); });

  const importInput = document.getElementById('import-file');
  if (importInput) importInput.addEventListener('change', e => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      try { Store.importJSON(reader.result); board.zoomToFit(); }
      catch (err) { alert('Could not import: ' + err.message); }
    };
    reader.readAsText(file);
    e.target.value = '';
  });

  /* board name (editable) */
  const nameEl = document.getElementById('board-name');
  if (nameEl) {
    nameEl.textContent = Store.state.name;
    nameEl.addEventListener('blur', () => Store.setName(nameEl.textContent.trim() || 'Untitled board'));
    nameEl.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); nameEl.blur(); } });
  }

  /* =====================================================
     Export PNG — render the board bounds to an offscreen canvas
  ===================================================== */
  function exportPNG() {
    const rs = Store.shapes().map(Shapes.bounds);
    const bb = Geo.unionRects(rs) || { x: -200, y: -200, w: 400, h: 400 };
    const pad = 60;
    const scale = 2;
    const off = document.createElement('canvas');
    off.width  = Math.ceil((bb.w + pad * 2) * scale);
    off.height = Math.ceil((bb.h + pad * 2) * scale);
    const c = off.getContext('2d');
    c.fillStyle = '#f7f8fb';
    c.fillRect(0, 0, off.width, off.height);
    c.setTransform(scale, 0, 0, scale, (pad - bb.x) * scale, (pad - bb.y) * scale);
    // resolve connectors against current geometry
    for (const s of Store.shapes()) {
      if (s.type === 'connector') {
        const A = s.from ? Store.byId(s.from) : null, B = s.to ? Store.byId(s.to) : null;
        const ca = A ? Geo.rectCenter(Shapes.bounds(A)) : { x: s.x1, y: s.y1 };
        const cb = B ? Geo.rectCenter(Shapes.bounds(B)) : { x: s.x2, y: s.y2 };
        s._a = A ? Geo.rectBorderPoint(Shapes.bounds(A), cb.x, cb.y) : ca;
        s._b = B ? Geo.rectBorderPoint(Shapes.bounds(B), ca.x, ca.y) : cb;
      }
    }
    for (const s of Store.shapes()) Shapes.draw(c, s, 1);
    off.toBlob(blob => downloadBlob(blob, slug(Store.state.name) + '.png'));
  }
  function exportJSONFile() {
    const blob = new Blob([Store.exportJSON()], { type: 'application/json' });
    downloadBlob(blob, slug(Store.state.name) + '.boundless.json');
  }
  function downloadBlob(blob, name) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
  const slug = s => (s || 'board').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

  /* =====================================================
     Properties inspector
  ===================================================== */
  const inspector = document.getElementById('inspector');
  const swatches = ['#ffffff','#dbeafe','#d1fae5','#fef3c7','#ffe4e6','#ede9fe','#fff8b8','#1f2733','none'];
  const strokeSwatches = ['#1f2733','#3b82f6','#10b981','#f59e0b','#f43f5e','#8b5cf6','#475569','none'];

  function onSelectionChange(sel) {
    updateZHud();
    if (!inspector) return;
    if (!sel.length) { inspector.classList.remove('open'); return; }
    inspector.classList.add('open');
    renderInspector(sel);
  }

  function renderInspector(sel) {
    const first = sel[0];
    const kinds = new Set(sel.map(s => s.type));
    const typeName = kinds.size === 1 ? prettyType(first.type) : `${sel.length} objects`;
    let html = `<div class="insp-head">${typeName}</div>`;

    const showFill = sel.some(s => ['rect','ellipse','sticky','text'].includes(s.type));
    const showStroke = sel.some(s => ['rect','ellipse','pen','connector'].includes(s.type));

    if (showFill) {
      html += `<div class="insp-row"><label>Fill</label><div class="swatches" data-prop="fill">`;
      html += swatches.map(c => swatch(c, first.fill === c)).join('');
      html += `</div></div>`;
    }
    if (showStroke) {
      html += `<div class="insp-row"><label>Stroke</label><div class="swatches" data-prop="stroke">`;
      html += strokeSwatches.map(c => swatch(c, first.stroke === c)).join('');
      html += `</div></div>`;
    }
    if (sel.some(s => ['rect','ellipse','pen','connector'].includes(s.type))) {
      html += `<div class="insp-row"><label>Weight</label>
        <input type="range" id="ins-weight" min="0" max="10" step="0.5" value="${first.strokeW ?? 2}"></div>`;
    }
    if (sel.some(s => s.type === 'connector')) {
      html += `<div class="insp-row"><label>Line</label>
        <div class="seg" id="ins-style">
          <button data-style="bezier" class="${first.style!=='straight'?'on':''}">Curved</button>
          <button data-style="straight" class="${first.style==='straight'?'on':''}">Straight</button>
        </div></div>`;
      html += `<div class="insp-row"><label>Dashed</label>
        <input type="checkbox" id="ins-dash" ${first.dashed?'checked':''}></div>`;
    }
    if (sel.some(s => ['text','sticky'].includes(s.type))) {
      html += `<div class="insp-row"><label>Font size</label>
        <input type="range" id="ins-font" min="10" max="64" step="1" value="${first.fontSize ?? 22}"></div>`;
    }

    html += `<div class="insp-actions">
      <button id="ins-front" title="Bring to front (])">Front</button>
      <button id="ins-back" title="Send to back ([)">Back</button>
      <button id="ins-dupe" title="Duplicate (Ctrl+D)">Duplicate</button>
      <button id="ins-del" class="danger" title="Delete (Del)">Delete</button>
    </div>`;

    inspector.innerHTML = html;

    inspector.querySelectorAll('.swatches').forEach(group => {
      const prop = group.dataset.prop;
      group.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
        board.applyStyle({ [prop]: b.dataset.color });
        renderInspector(board.getSelection());
      }));
    });
    on('ins-weight', 'input', e => board.applyStyle({ strokeW: +e.target.value }));
    on('ins-font', 'input', e => board.applyStyle({ fontSize: +e.target.value }));
    on('ins-dash', 'change', e => board.applyStyle({ dashed: e.target.checked }));
    inspector.querySelectorAll('#ins-style button').forEach(b =>
      b.addEventListener('click', () => { board.applyStyle({ style: b.dataset.style }); renderInspector(board.getSelection()); }));
    on('ins-front', 'click', () => board.zOrder('bringToFront'));
    on('ins-back', 'click', () => board.zOrder('sendToBack'));
    on('ins-dupe', 'click', () => board.duplicateSelection());
    on('ins-del', 'click', () => board.deleteSelection());

    function on(id, ev, fn) { const el = inspector.querySelector('#' + id); if (el) el.addEventListener(ev, fn); }
  }
  function swatch(c, active) {
    const bg = c === 'none'
      ? 'background:linear-gradient(135deg,#fff 45%,#f43f5e 46%,#f43f5e 54%,#fff 55%);'
      : `background:${c};`;
    return `<button class="sw ${active?'on':''}" data-color="${c}" style="${bg}" title="${c}"></button>`;
  }
  function prettyType(t) {
    return { rect:'Rectangle', ellipse:'Ellipse', sticky:'Sticky note', text:'Text', pen:'Drawing', connector:'Connector' }[t] || t;
  }

  /* =====================================================
     Zoom HUD + minimap
  ===================================================== */
  const zoomLabel = document.getElementById('zoom-label');
  const minimap = document.getElementById('minimap');
  const mmCtx = minimap ? minimap.getContext('2d') : null;

  function onCamera(cam) { updateZHud(); drawMinimap(); }
  function updateZHud() {
    if (zoomLabel) zoomLabel.textContent = Math.round(board.camera.zoom * 100) + '%';
    drawMinimap();
  }
  function updateZHudDeferred() { requestAnimationFrame(updateZHud); }

  function drawMinimap() {
    if (!mmCtx) return;
    const W = minimap.width, H = minimap.height;
    mmCtx.clearRect(0, 0, W, H);
    mmCtx.fillStyle = '#eef1f6'; mmCtx.fillRect(0, 0, W, H);
    const rs = Store.shapes().map(Shapes.bounds);
    const bb = Geo.unionRects(rs);
    if (!bb) return;
    const pad = 8;
    const ebb = Geo.expandRect(bb, Math.max(bb.w, bb.h) * 0.08 + 40);
    const s = Math.min((W - pad * 2) / ebb.w, (H - pad * 2) / ebb.h);
    const ox = pad + (W - pad * 2 - ebb.w * s) / 2 - ebb.x * s;
    const oy = pad + (H - pad * 2 - ebb.h * s) / 2 - ebb.y * s;
    for (const sh of Store.shapes()) {
      if (sh.type === 'connector' || sh.type === 'pen') continue;
      const b = Shapes.bounds(sh);
      mmCtx.fillStyle = sh.fill && sh.fill !== 'none' ? sh.fill : '#cbd5e1';
      mmCtx.globalAlpha = 0.95;
      mmCtx.fillRect(ox + b.x * s, oy + b.y * s, Math.max(1, b.w * s), Math.max(1, b.h * s));
    }
    mmCtx.globalAlpha = 1;
    // viewport rectangle
    const cam = board.camera;
    const r = canvas.getBoundingClientRect();
    const vw = r.width / cam.zoom, vh = r.height / cam.zoom;
    const vx = (-cam.x) / cam.zoom, vy = (-cam.y) / cam.zoom;
    mmCtx.strokeStyle = '#3b82f6'; mmCtx.lineWidth = 1.5;
    mmCtx.strokeRect(ox + vx * s, oy + vy * s, vw * s, vh * s);
    minimap._map = { ox, oy, s };
  }
  if (minimap) {
    minimap.addEventListener('pointerdown', e => {
      const m = minimap._map; if (!m) return;
      const r = minimap.getBoundingClientRect();
      const wx = (e.clientX - r.left - m.ox) / m.s;
      const wy = (e.clientY - r.top - m.oy) / m.s;
      const cam = board.camera; const cr = canvas.getBoundingClientRect();
      cam.x = cr.width / 2 - wx * cam.zoom;
      cam.y = cr.height / 2 - wy * cam.zoom;
      board.render(); updateZHud();
    });
  }

  /* =====================================================
     Save indicator
  ===================================================== */
  const saveDot = document.getElementById('save-status');
  let saveTimer = null;
  function scheduleSave() {
    if (saveDot) { saveDot.textContent = 'Saving…'; saveDot.classList.add('saving'); }
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      if (saveDot) { saveDot.textContent = 'All changes saved'; saveDot.classList.remove('saving'); }
    }, 600);
    drawMinimap();
  }
  Store.subscribe(() => { scheduleSave(); if (nameEl) nameEl.textContent = Store.state.name; updateZHudDeferred(); board.render(); });

  /* =====================================================
     Keyboard shortcuts
  ===================================================== */
  window.addEventListener('keydown', e => {
    if (board.isEditing()) return;
    const tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || e.target.isContentEditable) return;
    const mod = e.metaKey || e.ctrlKey;

    if (mod && e.key.toLowerCase() === 'z') {
      e.preventDefault();
      if (e.shiftKey) Store.redo(); else Store.undo();
      board.clearSelection(); board.render(); return;
    }
    if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); Store.redo(); board.render(); return; }
    if (mod && e.key.toLowerCase() === 'a') { e.preventDefault(); board.selectAll(); return; }
    if (mod && e.key.toLowerCase() === 'c') { clipboard.data = board.copySelection(); return; }
    if (mod && e.key.toLowerCase() === 'x') { clipboard.data = board.copySelection(); board.deleteSelection(); return; }
    if (mod && e.key.toLowerCase() === 'v') { e.preventDefault(); board.pasteShapes(clipboard.data, true); return; }
    if (mod && e.key.toLowerCase() === 'd') { e.preventDefault(); board.duplicateSelection(); return; }
    if (mod && e.key === '=') { e.preventDefault(); board.zoomBy(1.2); return; }
    if (mod && e.key === '-') { e.preventDefault(); board.zoomBy(1/1.2); return; }
    if (mod && e.key === '0') { e.preventDefault(); board.setZoom(1); return; }

    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); board.deleteSelection(); return; }
    if (e.key === 'Escape') { board.clearSelection(); board.setTool('select'); return; }
    if (e.key === ']') { board.zOrder('bringForward'); return; }
    if (e.key === '[') { board.zOrder('sendBackward'); return; }

    // arrow nudge
    const step = e.shiftKey ? 10 : 1;
    if (e.key === 'ArrowLeft')  { e.preventDefault(); board.nudge(-step, 0); return; }
    if (e.key === 'ArrowRight') { e.preventDefault(); board.nudge(step, 0); return; }
    if (e.key === 'ArrowUp')    { e.preventDefault(); board.nudge(0, -step); return; }
    if (e.key === 'ArrowDown')  { e.preventDefault(); board.nudge(0, step); return; }

    // tool hotkeys (single keys)
    const toolKeys = { v:'select', h:'pan', r:'rect', o:'ellipse', s:'sticky', t:'text', p:'pen', c:'connector', a:'connector' };
    if (!mod && toolKeys[e.key.toLowerCase()] && e.key.length === 1) {
      board.setTool(toolKeys[e.key.toLowerCase()]);
    }
    if (e.key === '1') board.setZoom(1);
    if (e.key === '2') board.zoomToFit();
  });

  /* shortcuts help dialog */
  const help = document.getElementById('shortcuts');
  bind('help-btn', () => help?.classList.add('open'));
  if (help) help.addEventListener('click', e => { if (e.target === help || e.target.dataset.close != null) help.classList.remove('open'); });

  /* initial HUD */
  updateZHud();
  onSelectionChange(board.getSelection());
  drawMinimap();
})();
