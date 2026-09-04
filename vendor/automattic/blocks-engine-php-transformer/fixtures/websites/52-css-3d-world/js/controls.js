/* ============================================================================
   VANTAPOINT — controls, HUD, minimap, detail overlay, mode toggle
   ----------------------------------------------------------------------------
   Glue layer. Boots the scene + camera, wires every input method, and keeps
   the on-screen UI (minimap, room label, detail overlay) in sync. Handles the
   graceful fallback to a flat 2D list on reduced-motion / small screens.
   ========================================================================== */

(function () {
  'use strict';

  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));

  const sceneEl = $('#scene');
  const viewport = $('#viewport');
  const reducedMQ = window.matchMedia('(prefers-reduced-motion: reduce)');
  const smallMQ = window.matchMedia('(max-width: 760px), (pointer: coarse)');

  /* ---------------------------------------------------------------- build -- */
  const build = window.SCENE.build(sceneEl);
  window.CAMERA.init(sceneEl, build);

  /* ------------------------------------------------------------ minimap ---- */
  const miniRooms = $('#mini-rooms');
  const miniDot = $('#mini-dot');
  const roomLabel = $('#room-name');
  const roomEra = $('#room-era');
  const ROOM_SIZE = window.SCENE.ROOM_SIZE;

  // grid bounds for minimap layout
  const gx = window.VANTAPOINT.rooms.map(r => r.grid.x);
  const gz = window.VANTAPOINT.rooms.map(r => r.grid.z);
  const minX = Math.min(...gx), maxX = Math.max(...gx);
  const minZ = Math.min(...gz), maxZ = Math.max(...gz);
  const cols = maxX - minX + 1, rows = maxZ - minZ + 1;

  window.VANTAPOINT.rooms.forEach(r => {
    const b = document.createElement('button');
    b.className = 'mini-room';
    b.type = 'button';
    b.dataset.room = r.id;
    b.style.left = ((r.grid.x - minX) / cols * 100) + '%';
    b.style.top = ((r.grid.z - minZ) / rows * 100) + '%';
    b.style.width = (100 / cols) + '%';
    b.style.height = (100 / rows) + '%';
    b.style.setProperty('--accent', r.accent);
    b.innerHTML = '<span>' + r.name.replace('The ', '') + '</span>';
    b.setAttribute('aria-label', 'Go to ' + r.name);
    b.addEventListener('click', () => window.CAMERA.gotoRoom(r.id));
    miniRooms.appendChild(b);
  });

  function updateMini(state) {
    const r = state.room;
    roomLabel.textContent = r.name;
    roomEra.textContent = r.era + '  ·  ' + r.intro;
    $$('.mini-room').forEach(b => b.classList.toggle('active', b.dataset.room === r.id));
    // dot position within whole grid (world coords -> percent)
    const worldX = state.pos.x / ROOM_SIZE; // in room units
    const worldZ = state.pos.z / ROOM_SIZE;
    miniDot.style.left = ((worldX - minX + 0.5) / cols * 100) + '%';
    miniDot.style.top = ((worldZ - minZ + 0.5) / rows * 100) + '%';
    miniDot.style.transform = 'translate(-50%,-50%) rotate(' + (state.yaw * 180 / Math.PI) + 'deg)';
  }
  window.CAMERA.onMove(updateMini);

  /* ----------------------------------------------------- keyboard input ---- */
  const keys = {};
  function recomputeKeys() {
    const f = (keys['w'] || keys['arrowup'] ? 1 : 0) - (keys['s'] || keys['arrowdown'] ? 1 : 0);
    const s = (keys['d'] ? 1 : 0) - (keys['a'] ? 1 : 0);
    const t = (keys['arrowright'] || keys['e'] ? 1 : 0) - (keys['arrowleft'] || keys['q'] ? 1 : 0);
    window.CAMERA.setForward(f);
    window.CAMERA.setStrafe(s);
    window.CAMERA.setTurn(t);
  }
  window.addEventListener('keydown', (e) => {
    if (overlayOpen && e.key === 'Escape') { closeOverlay(); return; }
    const k = e.key.toLowerCase();
    if (['w','a','s','d','q','e','arrowup','arrowdown','arrowleft','arrowright'].includes(k)) {
      if (mode !== '3d') return;
      e.preventDefault();
      keys[k] = true; recomputeKeys();
    }
    if (k === 'r') window.CAMERA.reset();
    if (k === 'f') { e.preventDefault(); focusNearest(); }
  });
  window.addEventListener('keyup', (e) => {
    const k = e.key.toLowerCase();
    if (keys[k]) { keys[k] = false; recomputeKeys(); }
  });
  window.addEventListener('blur', () => { for (const k in keys) keys[k] = false; recomputeKeys(); });

  /* --------------------------------------------------- drag / touch look --- */
  let dragging = false, lastX = 0, lastY = 0, moved = 0;
  viewport.addEventListener('pointerdown', (e) => {
    // taps on HUD controls are theirs to handle; never start a look-drag there
    if (mode !== '3d' || e.target.closest('.hud')) return;
    dragging = true; moved = 0; lastX = e.clientX; lastY = e.clientY;
    viewport.setPointerCapture(e.pointerId);
    viewport.classList.add('grabbing');
  });
  viewport.addEventListener('pointermove', (e) => {
    if (!dragging) return;
    const dx = e.clientX - lastX, dy = e.clientY - lastY;
    lastX = e.clientX; lastY = e.clientY; moved += Math.abs(dx) + Math.abs(dy);
    window.CAMERA.look(-dx * 0.004, -dy * 0.003);
  });
  function endDrag(e) {
    if (!dragging) return;
    dragging = false; viewport.classList.remove('grabbing');
    try { viewport.releasePointerCapture(e.pointerId); } catch (_) {}
    // treat as click-to-focus if barely moved and hit a panel
    if (moved < 6) {
      const panel = document.elementFromPoint(e.clientX, e.clientY);
      const p = panel && panel.closest && panel.closest('.art-panel');
      if (p && p.dataset.exhibit) openExhibit(p.dataset.exhibit);
    }
  }
  viewport.addEventListener('pointerup', endDrag);
  viewport.addEventListener('pointercancel', endDrag);

  /* panels: keyboard + direct click ---------------------------------------- */
  sceneEl.addEventListener('keydown', (e) => {
    const p = e.target.closest && e.target.closest('.art-panel');
    if (p && p.dataset.exhibit && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault(); openExhibit(p.dataset.exhibit);
    }
  });

  /* ------------------------------------------------- on-screen D-pad -------- */
  function holdBtn(sel, downFn, upFn) {
    const el = $(sel); if (!el) return;
    const down = (e) => { e.preventDefault(); downFn(); };
    const up = (e) => { e.preventDefault(); upFn(); };
    el.addEventListener('pointerdown', down);
    el.addEventListener('pointerup', up);
    el.addEventListener('pointerleave', up);
    el.addEventListener('pointercancel', up);
  }
  holdBtn('#nav-fwd', () => window.CAMERA.setForward(1), () => window.CAMERA.setForward(0));
  holdBtn('#nav-back', () => window.CAMERA.setForward(-1), () => window.CAMERA.setForward(0));
  holdBtn('#nav-left', () => window.CAMERA.setTurn(-1), () => window.CAMERA.setTurn(0));
  holdBtn('#nav-right', () => window.CAMERA.setTurn(1), () => window.CAMERA.setTurn(0));
  $('#nav-reset').addEventListener('click', () => window.CAMERA.reset());
  $('#nav-focus').addEventListener('click', focusNearest);

  // For touch (no rAF loop running smoothly under thumb) also nudge a step
  if (smallMQ.matches) {
    $('#nav-fwd').addEventListener('click', () => window.CAMERA.nudge(1, 0));
    $('#nav-back').addEventListener('click', () => window.CAMERA.nudge(-1, 0));
  }

  /* ---------------------------------------- focus nearest faced exhibit ----- */
  function focusNearest() {
    const r = window.CAMERA.currentRoom();
    const { yaw } = window.CAMERA.state;
    // map yaw to the wall the camera most faces
    const norm = ((yaw % (Math.PI * 2)) + Math.PI * 2) % (Math.PI * 2);
    let side;
    if (norm < Math.PI / 4 || norm >= Math.PI * 7 / 4) side = 'north';
    else if (norm < Math.PI * 3 / 4) side = 'west';
    else if (norm < Math.PI * 5 / 4) side = 'south';
    else side = 'east';
    const wall = r.walls[side];
    window.CAMERA.gotoExhibit(r.id, side);
    if (wall && wall.type === 'art') setTimeout(() => openExhibit(wall.id), 700);
  }

  /* ------------------------------------------------ detail overlay (HUD) ---- */
  const overlay = $('#detail');
  let overlayOpen = false, lastFocus = null;

  function findExhibit(id) {
    for (const r of window.VANTAPOINT.rooms)
      for (const side of ['north','east','south','west'])
        if (r.walls[side] && r.walls[side].id === id)
          return { wall: r.walls[side], room: r, side };
    return null;
  }

  function openExhibit(id) {
    const found = findExhibit(id);
    if (!found || found.wall.type !== 'art') return;
    const w = found.wall;
    $('#detail-art').innerHTML = window.ARTWORKS.render(w.art);
    $('#detail-title').textContent = w.title;
    $('#detail-meta').textContent = w.year + '  ·  ' + w.medium + '  ·  ' + w.dims;
    $('#detail-room').textContent = found.room.name + '  —  ' + found.room.era;
    $('#detail-body').textContent = w.detail;
    $('#detail-caption').textContent = w.caption;
    overlay.style.setProperty('--accent', found.room.accent);
    overlay.hidden = false;
    requestAnimationFrame(() => overlay.classList.add('open'));
    overlayOpen = true;
    lastFocus = document.activeElement;
    $('#detail-close').focus();
    window.CAMERA.stop();
  }
  function closeOverlay() {
    overlay.classList.remove('open');
    overlayOpen = false;
    setTimeout(() => { overlay.hidden = true; }, 280);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
    if (mode === '3d' && !reducedMQ.matches) window.CAMERA.start();
  }
  $('#detail-close').addEventListener('click', closeOverlay);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) closeOverlay(); });
  $('#detail-tour').addEventListener('click', () => { closeOverlay(); tourNext(); });

  // expose for the flat list buttons
  window.VP_OPEN = openExhibit;

  /* --------------------------------------------- guided tour (next piece) --- */
  const tourSeq = [];
  window.VANTAPOINT.rooms.forEach(r => ['east','south','west'].forEach(side => {
    if (r.walls[side] && r.walls[side].type === 'art')
      tourSeq.push({ room: r.id, side, id: r.walls[side].id });
  }));
  let tourIdx = -1;
  function tourNext() {
    tourIdx = (tourIdx + 1) % tourSeq.length;
    const t = tourSeq[tourIdx];
    if (mode === '3d') {
      window.CAMERA.gotoExhibit(t.room, t.side);
      setTimeout(() => openExhibit(t.id), 900);
    } else {
      openExhibit(t.id);
      const card = $('[data-flat="' + t.id + '"]');
      if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }
  $('#start-tour').addEventListener('click', () => { tourIdx = -1; tourNext(); });

  /* ------------------------------------------------------- mode toggle ----- */
  let mode = '3d';
  const stage = $('#stage');
  const flat = $('#flat');
  const modeBtn = $('#mode-toggle');

  function buildFlat() {
    if (flat.dataset.built) return;
    let html = '';
    window.VANTAPOINT.rooms.forEach(r => {
      html += '<section class="flat-room"><header class="flat-room-head">' +
        '<h2>' + r.name + '</h2><p>' + r.era + ' · ' + r.intro + '</p></header><div class="flat-grid">';
      ['north','east','south','west'].forEach(side => {
        const w = r.walls[side]; if (!w) return;
        if (w.type === 'art') {
          html += '<article class="flat-card" data-flat="' + w.id + '" style="--accent:' + r.accent + '">' +
            '<div class="flat-thumb">' + window.ARTWORKS.render(w.art) + '</div>' +
            '<div class="flat-meta"><h3>' + w.title + '</h3>' +
            '<p class="flat-line">' + w.year + ' · ' + w.medium + '</p>' +
            '<p class="flat-cap">' + w.caption + '</p>' +
            '<button class="flat-btn" data-open="' + w.id + '">Read full label →</button></div></article>';
        } else {
          html += '<article class="flat-card flat-text"><div class="flat-meta"><h3>' + w.title +
            '</h3><p>' + w.body + '</p></div></article>';
        }
      });
      html += '</div></section>';
    });
    flat.innerHTML = html;
    flat.dataset.built = '1';
    flat.addEventListener('click', (e) => {
      const b = e.target.closest('[data-open]');
      if (b) openExhibit(b.dataset.open);
    });
  }

  function setMode(m) {
    mode = m;
    const is3d = m === '3d';
    stage.hidden = !is3d;
    flat.hidden = is3d;
    document.body.classList.toggle('mode-flat', !is3d);
    modeBtn.setAttribute('aria-pressed', String(!is3d));
    modeBtn.querySelector('.mode-label').textContent = is3d ? 'Switch to list view' : 'Enter the 3D space';
    if (is3d) { window.CAMERA.setEnabled(true); }
    else { buildFlat(); window.CAMERA.setEnabled(false); }
  }
  modeBtn.addEventListener('click', () => setMode(mode === '3d' ? 'flat' : '3d'));

  /* ------------------------------------------------- reduced / small init --- */
  function applyEnvironment() {
    const reduced = reducedMQ.matches;
    const small = smallMQ.matches;
    window.CAMERA.setReduced(reduced);
    if (reduced || small) {
      setMode('flat');
      $('#env-note').hidden = false;
      $('#env-note').textContent = reduced
        ? 'Reduced-motion is on, so the museum is shown as an accessible list. You can still enter the 3D space with the toggle above.'
        : 'On small / touch screens the museum opens as a readable list. Tap “Enter the 3D space” to fly through it.';
    } else {
      setMode('3d');
      window.CAMERA.start();
    }
  }
  reducedMQ.addEventListener('change', applyEnvironment);
  applyEnvironment();

  /* ----------------------------------------- entrance overlay dismiss ------ */
  const enter = $('#enter');
  if (enter) {
    $('#enter-go').addEventListener('click', () => {
      enter.classList.add('gone');
      setTimeout(() => enter.remove(), 600);
      if (mode === '3d' && !reducedMQ.matches) window.CAMERA.start();
      viewport.focus();
    });
    $('#enter-list').addEventListener('click', () => {
      setMode('flat');
      enter.classList.add('gone');
      setTimeout(() => enter.remove(), 600);
    });
  }

  /* ----------------------------------------------------- particle dust ----- */
  // a light floating-dust layer for depth; CSS animates, JS just seeds count
  const dust = $('#dust');
  if (dust && !reducedMQ.matches) {
    for (let i = 0; i < 40; i++) {
      const s = document.createElement('span');
      s.style.left = Math.random() * 100 + '%';
      s.style.top = Math.random() * 100 + '%';
      s.style.setProperty('--d', (6 + Math.random() * 10).toFixed(1) + 's');
      s.style.setProperty('--delay', (-Math.random() * 10).toFixed(1) + 's');
      s.style.setProperty('--sz', (1 + Math.random() * 2.4).toFixed(1) + 'px');
      dust.appendChild(s);
    }
  }

  /* footer year + shared header toggle ------------------------------------- */
  $$('[data-year]').forEach(el => el.textContent = new Date().getFullYear());
  const navToggle = $('.nav-toggle');
  if (navToggle) navToggle.addEventListener('click', () => {
    const nav = $('.site-nav');
    const open = nav.classList.toggle('open');
    navToggle.setAttribute('aria-expanded', String(open));
  });
})();
