/* =========================================================
   FLUXNODE — app.js
   Boots the editor, wires the top bar / file menu, the add-node
   palette, the right-click context menu, zoom HUD, keyboard
   shortcuts, import/export, and the URL ?graph= deep links.
   ========================================================= */
'use strict';

(function () {
  const $ = sel => document.querySelector(sel);

  /* ---- boot editor ---- */
  Editor.init({
    stage: $('#stage'),
    grid: $('#grid'),
    world: $('#world'),
    wires: $('#wires'),
    minimap: $('#minimap'),
    name: $('#doc-name'),
    save: $('#save-status'),
    evalHud: $('#eval-hud'),
    evalErr: $('#eval-state'),
    evalCount: $('#eval-count'),
    evalSorted: $('#eval-sorted'),
  });

  /* ---- choose initial document ---- */
  const params = new URLSearchParams(location.search);
  const wanted = params.get('graph');
  if (wanted && Seeds.get(wanted)) {
    Editor.loadGraph(Seeds.get(wanted), Seeds.get(wanted).name);
    history.replaceState({}, '', 'index.html');
  } else if (Editor.loadFromStorage()) {
    Editor.applyCamera();
    Editor.evaluate();
    $('#doc-name').textContent = Editor.name;
  } else {
    Editor.loadGraph(Seeds.get('sunset'), 'Sunset Dunes');
  }

  /* ---- doc name editing ---- */
  const nameEl = $('#doc-name');
  nameEl.addEventListener('blur', () => Editor.setName(nameEl.textContent.trim() || 'Untitled flow'));
  nameEl.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); nameEl.blur(); } });

  /* ════════ FILE MENU ════════ */
  const fileBtn = $('#menu-btn'), fileMenu = $('#file-menu');
  fileBtn.addEventListener('click', e => { e.stopPropagation(); const open = fileMenu.classList.toggle('open'); fileBtn.setAttribute('aria-expanded', open); });
  document.addEventListener('click', () => { fileMenu.classList.remove('open'); fileBtn.setAttribute('aria-expanded', 'false'); });
  fileMenu.addEventListener('click', e => e.stopPropagation());

  $('#mi-new').onclick = () => { if (confirm('Start a new empty flow? Your current flow is autosaved in this browser.')) Editor.newGraph(); fileMenu.classList.remove('open'); };
  $('#mi-clear').onclick = () => { if (confirm('Clear all nodes?')) Editor.newGraph(); fileMenu.classList.remove('open'); };
  $('#mi-sunset').onclick = () => { Editor.loadGraph(Seeds.get('sunset'), 'Sunset Dunes'); fileMenu.classList.remove('open'); };

  $('#mi-export-json').onclick = () => {
    const blob = new Blob([JSON.stringify(Editor.getDoc(), null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.download = (Editor.name || 'fluxnode').replace(/\s+/g, '-').toLowerCase() + '.flux.json';
    a.href = URL.createObjectURL(blob); a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    fileMenu.classList.remove('open');
    Editor.toast('Exported graph JSON');
  };
  const importFile = $('#import-file');
  $('#mi-import-json').onclick = () => { importFile.click(); fileMenu.classList.remove('open'); };
  importFile.addEventListener('change', () => {
    const f = importFile.files[0]; if (!f) return;
    const reader = new FileReader();
    reader.onload = () => {
      try {
        const data = JSON.parse(reader.result);
        if (!Array.isArray(data.nodes)) throw new Error('bad');
        Editor.loadGraph(data, data.name || f.name.replace(/\.\w+$/, ''));
        Editor.toast('Imported ' + f.name);
      } catch (e) { Editor.toast('Could not read that file.'); }
      importFile.value = '';
    };
    reader.readAsText(f);
  });
  $('#mi-export-png').onclick = () => { Editor.exportPreview(1024); fileMenu.classList.remove('open'); };

  /* ════════ ADD-NODE PALETTE ════════ */
  const palette = $('#palette');
  let paletteWorld = { x: 0, y: 0 };

  function buildPalette() {
    palette.innerHTML = '';
    const search = document.createElement('input');
    search.className = 'search'; search.id = 'palette-search'; search.placeholder = 'Search nodes…';
    palette.appendChild(search);
    const list = document.createElement('div'); list.id = 'palette-list'; palette.appendChild(list);

    const groups = {};
    Object.values(NodeTypes).forEach(t => { (groups[t.group] = groups[t.group] || []).push(t); });
    const order = ['Sources', 'Operators', 'Numbers', 'Output'];

    function render(filter) {
      list.innerHTML = '';
      order.forEach(gName => {
        const items = (groups[gName] || []).filter(t => !filter || t.name.toLowerCase().includes(filter) || t.group.toLowerCase().includes(filter));
        if (!items.length) return;
        const h = document.createElement('div'); h.className = 'grp'; h.textContent = gName; list.appendChild(h);
        items.forEach(t => {
          const b = document.createElement('button'); b.className = 'item'; b.dataset.key = t.key;
          b.innerHTML = `<span class="swatch" style="background:${t.color}"></span><span>${t.name}</span><span class="desc">${portDesc(t)}</span>`;
          b.onclick = () => { Editor.addNode(t.key, paletteWorld.x - 100, paletteWorld.y - 20); closePalette(); };
          list.appendChild(b);
        });
      });
    }
    function portDesc(t) {
      const i = t.inputs.length, o = t.outputs.length;
      return `${i}→${o}`;
    }
    render('');
    search.addEventListener('input', () => render(search.value.trim().toLowerCase()));
    // keyboard nav: enter picks first
    search.addEventListener('keydown', e => {
      if (e.key === 'Enter') { const first = list.querySelector('.item'); if (first) first.click(); }
      if (e.key === 'Escape') closePalette();
    });
    return search;
  }

  function openPalette(screenX, screenY) {
    const rect = $('#stage').getBoundingClientRect();
    paletteWorld = Editor.screenToWorld(screenX - rect.left, screenY - rect.top);
    const search = buildPalette();
    palette.style.left = Math.min(screenX, window.innerWidth - 250) + 'px';
    palette.style.top = Math.min(screenY, window.innerHeight - 360) + 'px';
    palette.classList.add('open');
    setTimeout(() => search.focus(), 0);
  }
  function closePalette() { palette.classList.remove('open'); }

  $('#add-btn').addEventListener('click', e => {
    e.stopPropagation();
    const r = e.currentTarget.getBoundingClientRect();
    openPalette(r.left, r.bottom + 6);
  });

  /* ════════ CONTEXT MENU ════════ */
  const ctx = $('#ctx');
  function openCtx(x, y, onNode) {
    ctx.innerHTML = '';
    const item = (label, sc, fn, danger) => {
      const b = document.createElement('button');
      if (danger) b.className = 'danger';
      b.innerHTML = `<span>${label}</span>${sc ? `<span class="sc">${sc}</span>` : ''}`;
      b.onclick = () => { fn(); closeCtx(); };
      ctx.appendChild(b);
    };
    item('Add node…', 'Tab', () => openPalette(x, y));
    if (onNode || Editor.selected.size) {
      const hr = document.createElement('hr'); ctx.appendChild(hr);
      item('Duplicate', '⌘D', () => Editor.duplicateSelected());
      item('Delete', 'Del', () => Editor.deleteSelected(), true);
    }
    const hr2 = document.createElement('hr'); ctx.appendChild(hr2);
    item('Fit to view', '2', () => Editor.fitView());
    ctx.style.left = Math.min(x, window.innerWidth - 200) + 'px';
    ctx.style.top = Math.min(y, window.innerHeight - 220) + 'px';
    ctx.classList.add('open');
  }
  function closeCtx() { ctx.classList.remove('open'); }
  $('#stage').addEventListener('contextmenu', e => {
    e.preventDefault();
    const node = e.target.closest('.node');
    if (node && !Editor.selected.has(node.dataset.id)) { /* select under cursor */ }
    openCtx(e.clientX, e.clientY, !!node);
  });
  document.addEventListener('pointerdown', e => { if (!ctx.contains(e.target)) closeCtx(); if (!palette.contains(e.target) && e.target.id !== 'add-btn') closePalette(); });

  /* ════════ ZOOM HUD ════════ */
  $('#zoom-in').onclick = () => Editor.setZoom(Editor.cam.zoom * 1.2);
  $('#zoom-out').onclick = () => Editor.setZoom(Editor.cam.zoom / 1.2);
  $('#zoom-fit').onclick = () => Editor.fitView();
  $('#zoom-label').onclick = () => Editor.setZoom(1);
  Editor.on(() => { $('#zoom-label').textContent = Math.round(Editor.cam.zoom * 100) + '%'; });

  /* ════════ SHORTCUTS MODAL + HELP ════════ */
  const modal = $('#shortcuts');
  $('#help-btn').onclick = () => modal.classList.add('open');
  modal.addEventListener('click', e => { if (e.target === modal || e.target.dataset.close !== undefined) modal.classList.remove('open'); });

  /* ════════ KEYBOARD SHORTCUTS ════════ */
  window.addEventListener('keydown', e => {
    if (Editor.isTyping(e)) {
      if (e.key === 'Escape') e.target.blur();
      return;
    }
    const meta = e.metaKey || e.ctrlKey;

    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); Editor.deleteSelected(); }
    else if (meta && e.key.toLowerCase() === 'd') { e.preventDefault(); Editor.duplicateSelected(); }
    else if (meta && e.key.toLowerCase() === 'a') { e.preventDefault(); for (const n of Editor.graph.nodes.keys()) Editor.selected.add(n); document.querySelectorAll('.node').forEach(el => el.classList.add('sel')); }
    else if (e.key === 'Tab') { e.preventDefault(); const r = $('#stage').getBoundingClientRect(); openPalette(r.left + r.width / 2, r.top + r.height / 2); }
    else if (e.key === '2' || e.key.toLowerCase() === 'f') { Editor.fitView(); }
    else if (meta && e.key === '0') { e.preventDefault(); Editor.setZoom(1); }
    else if ((meta && (e.key === '=' || e.key === '+'))) { e.preventDefault(); Editor.setZoom(Editor.cam.zoom * 1.2); }
    else if (meta && e.key === '-') { e.preventDefault(); Editor.setZoom(Editor.cam.zoom / 1.2); }
    else if (e.key === '?') { modal.classList.toggle('open'); }
    else if (e.key === 'Escape') { closePalette(); closeCtx(); modal.classList.remove('open'); }
  });

  /* dismiss hint after first interaction */
  const hint = $('#hint');
  if (hint) { $('#stage').addEventListener('pointerdown', () => { hint.style.opacity = '0'; setTimeout(() => hint.remove(), 600); }, { once: true }); }
})();
