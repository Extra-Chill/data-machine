/* =========================================================
   AuroraOS 88 — window manager
   Draggable / resizable / focusable / min-max-close windows.
   Real z-index stacking, taskbar buttons, position persistence.
   ========================================================= */
'use strict';

AOS.WM = (function () {
  const layer = () => AOS.$('#window-layer');
  const taskbar = () => AOS.$('#task-buttons');
  const wins = new Map();        // id -> win object
  let zTop = 10;
  let active = null;
  let spawnOffset = 0;

  const ctlSVG = {
    min:  `<svg viewBox="0 0 12 12"><path d="M2 9 H10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>`,
    max:  `<svg viewBox="0 0 12 12"><rect x="2" y="2" width="8" height="8" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>`,
    rest: `<svg viewBox="0 0 12 12"><rect x="2" y="3.5" width="6.5" height="6.5" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M4.5 3.5 V1.5 H10.5 V7.5 H8.5" fill="none" stroke="currentColor" stroke-width="1.4"/></svg>`,
    close:`<svg viewBox="0 0 12 12"><path d="M3 3 L9 9 M9 3 L3 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>`
  };

  function bounds() {
    const d = AOS.$('#desktop');
    return { w: d.clientWidth, h: d.clientHeight - 46 /* taskbar */ };
  }

  function focus(id) {
    const win = wins.get(id);
    if (!win) return;
    if (win.minned) restore(id);
    if (active && active !== id) {
      const prev = wins.get(active);
      if (prev) { prev.node.classList.remove('active'); prev.task.classList.remove('active'); }
    }
    active = id;
    win.node.classList.add('active');
    win.task.classList.add('active');
    win.z = ++zTop;
    win.node.style.zIndex = win.z;
  }

  function persist(win) {
    if (win.maxed || win.minned) return;
    const all = AOS.store.get('winpos', {});
    all[win.app] = { x: win.x, y: win.y, w: win.w, h: win.h };
    AOS.store.set('winpos', all);
  }

  /* ── create a window ── */
  function open(opts) {
    // opts: { app, title, icon, width, height, render(body, api), single }
    if (opts.single !== false && wins.has(opts.app)) {
      focus(opts.app);
      return wins.get(opts.app);
    }
    const id = opts.app + (opts.single === false ? ':' + (++spawnOffset) : '');
    const b = bounds();
    const saved = AOS.store.get('winpos', {})[opts.app];

    const small = b.w < 680;
    let W = Math.min(opts.width || 460, b.w - (small ? 10 : 16));
    let H = Math.min(opts.height || 340, b.h - 16);
    let X, Y;
    if (small) {
      // phone/tablet: near-full-width, lightly cascaded down the screen
      W = b.w - 10;
      H = Math.min(opts.height || 340, b.h - 56);
      X = 5;
      Y = AOS.clamp(10 + (spawnOffset % 5) * 18, 6, Math.max(6, b.h - H - 6));
      spawnOffset++;
    } else if (saved && opts.single !== false) {
      W = AOS.clamp(saved.w, 240, b.w); H = AOS.clamp(saved.h, 160, b.h);
      X = AOS.clamp(saved.x, 0, b.w - 120); Y = AOS.clamp(saved.y, 0, b.h - 40);
    } else {
      X = Math.round((b.w - W) / 2) + ((spawnOffset % 6) * 26) - 60;
      Y = Math.round((b.h - H) / 2.4) + ((spawnOffset % 6) * 22) - 30;
      spawnOffset++;
      X = AOS.clamp(X, 6, Math.max(6, b.w - W - 6));
      Y = AOS.clamp(Y, 6, Math.max(6, b.h - H - 6));
    }

    const node = AOS.el('div', { class: 'window opening', role: 'dialog', 'aria-label': opts.title, tabindex: '-1' });
    node.innerHTML = `
      <div class="win-title">
        <span class="win-ico">${opts.icon || AOS.icons.doc}</span>
        <span class="win-name">${opts.title}</span>
        <span class="win-controls">
          <button class="win-ctl min" aria-label="Minimize" title="Minimize">${ctlSVG.min}</button>
          <button class="win-ctl max" aria-label="Maximize" title="Maximize">${ctlSVG.max}</button>
          <button class="win-ctl close" aria-label="Close" title="Close">${ctlSVG.close}</button>
        </span>
      </div>
      <div class="win-body"></div>
      <div class="win-resize rz-e"></div>
      <div class="win-resize rz-s"></div>
      <div class="win-resize rz-se"></div>`;
    node.style.cssText += `left:${X}px;top:${Y}px;width:${W}px;height:${H}px;`;
    layer().append(node);

    // taskbar button
    const task = AOS.el('button', { class: 'task-btn', title: opts.title },
      AOS.el('span', { class: 'win-ico', style: 'width:16px;height:16px;flex:none', html: opts.icon || AOS.icons.doc }),
      AOS.el('span', { text: opts.title }));
    taskbar().append(task);

    const win = {
      id, app: opts.app, node, task,
      x: X, y: Y, w: W, h: H, z: 0,
      maxed: false, minned: false, prev: null,
      title: opts.title, onClose: opts.onClose
    };
    wins.set(id, win);

    const body = AOS.$('.win-body', node);
    const api = { node, body, win, close: () => close(id), focus: () => focus(id), setTitle: (t) => { AOS.$('.win-name', node).textContent = t; task.lastChild.textContent = t; } };
    if (typeof opts.render === 'function') opts.render(body, api);

    // events
    node.addEventListener('pointerdown', () => focus(id), true);
    task.addEventListener('click', () => {
      if (win.minned) { restore(id); }
      else if (active === id) { minimize(id); }
      else focus(id);
    });
    AOS.$('.win-ctl.min', node).addEventListener('click', (e) => { e.stopPropagation(); minimize(id); });
    AOS.$('.win-ctl.max', node).addEventListener('click', (e) => { e.stopPropagation(); toggleMax(id); });
    AOS.$('.win-ctl.close', node).addEventListener('click', (e) => { e.stopPropagation(); close(id); });
    AOS.$('.win-title', node).addEventListener('dblclick', (e) => { if (!e.target.closest('.win-ctl')) toggleMax(id); });

    makeDraggable(win);
    makeResizable(win, 'rz-e', true, false);
    makeResizable(win, 'rz-s', false, true);
    makeResizable(win, 'rz-se', true, true);

    focus(id);
    node.addEventListener('animationend', () => node.classList.remove('opening'), { once: true });
    return api;
  }

  /* ── dragging ── */
  function makeDraggable(win) {
    const handle = AOS.$('.win-title', win.node);
    let sx, sy, ox, oy, dragging = false;
    handle.addEventListener('pointerdown', (e) => {
      if (e.target.closest('.win-ctl') || win.maxed) return;
      dragging = true;
      win.node.classList.add('dragging');
      sx = e.clientX; sy = e.clientY; ox = win.x; oy = win.y;
      handle.setPointerCapture(e.pointerId);
    });
    handle.addEventListener('pointermove', (e) => {
      if (!dragging) return;
      const b = bounds();
      win.x = AOS.clamp(ox + (e.clientX - sx), -win.w + 80, b.w - 60);
      win.y = AOS.clamp(oy + (e.clientY - sy), 0, b.h - 36);
      win.node.style.left = win.x + 'px';
      win.node.style.top = win.y + 'px';
    });
    const end = (e) => {
      if (!dragging) return;
      dragging = false;
      win.node.classList.remove('dragging');
      try { handle.releasePointerCapture(e.pointerId); } catch (_) {}
      persist(win);
    };
    handle.addEventListener('pointerup', end);
    handle.addEventListener('pointercancel', end);
  }

  /* ── resizing ── */
  function makeResizable(win, cls, ew, ns) {
    const grip = AOS.$('.' + cls, win.node);
    let sx, sy, ow, oh, resizing = false;
    grip.addEventListener('pointerdown', (e) => {
      if (win.maxed) return;
      e.stopPropagation();
      resizing = true; win.node.classList.add('resizing');
      sx = e.clientX; sy = e.clientY; ow = win.w; oh = win.h;
      grip.setPointerCapture(e.pointerId);
    });
    grip.addEventListener('pointermove', (e) => {
      if (!resizing) return;
      const b = bounds();
      if (ew) win.w = AOS.clamp(ow + (e.clientX - sx), 240, b.w - win.x);
      if (ns) win.h = AOS.clamp(oh + (e.clientY - sy), 160, b.h - win.y);
      win.node.style.width = win.w + 'px';
      win.node.style.height = win.h + 'px';
    });
    const end = (e) => {
      if (!resizing) return;
      resizing = false; win.node.classList.remove('resizing');
      try { grip.releasePointerCapture(e.pointerId); } catch (_) {}
      persist(win);
    };
    grip.addEventListener('pointerup', end);
    grip.addEventListener('pointercancel', end);
  }

  function minimize(id) {
    const win = wins.get(id);
    if (!win) return;
    win.minned = true;
    win.node.classList.add('minned');
    win.task.classList.add('min');
    win.task.classList.remove('active');
    win.node.classList.remove('active');
    if (active === id) active = null;
  }
  function restore(id) {
    const win = wins.get(id);
    if (!win) return;
    win.minned = false;
    win.node.classList.remove('minned');
    win.task.classList.remove('min');
    focus(id);
  }
  function toggleMax(id) {
    const win = wins.get(id);
    if (!win) return;
    const maxBtn = AOS.$('.win-ctl.max', win.node);
    if (!win.maxed) {
      win.prev = { x: win.x, y: win.y, w: win.w, h: win.h };
      const b = bounds();
      win.maxed = true; win.node.classList.add('maxed');
      Object.assign(win.node.style, { left: '0px', top: '0px', width: b.w + 'px', height: b.h + 'px' });
      maxBtn.innerHTML = ctlSVG.rest; maxBtn.title = 'Restore';
    } else {
      win.maxed = false; win.node.classList.remove('maxed');
      const p = win.prev || { x: 40, y: 40, w: 460, h: 340 };
      Object.assign(win, p);
      Object.assign(win.node.style, { left: p.x + 'px', top: p.y + 'px', width: p.w + 'px', height: p.h + 'px' });
      maxBtn.innerHTML = ctlSVG.max; maxBtn.title = 'Maximize';
    }
    focus(id);
  }
  function close(id) {
    const win = wins.get(id);
    if (!win) return;
    if (typeof win.onClose === 'function') { try { win.onClose(); } catch (_) {} }
    win.node.classList.add('closing');
    win.task.remove();
    wins.delete(id);
    if (active === id) active = null;
    win.node.addEventListener('animationend', () => win.node.remove(), { once: true });
    setTimeout(() => win.node.remove(), 240);
  }

  function get(app) { return wins.get(app); }
  function isOpen(app) { return wins.has(app); }
  function relayout() {
    const b = bounds();
    for (const win of wins.values()) {
      if (win.maxed) { Object.assign(win.node.style, { width: b.w + 'px', height: b.h + 'px' }); continue; }
      win.x = AOS.clamp(win.x, -win.w + 80, b.w - 60);
      win.y = AOS.clamp(win.y, 0, b.h - 36);
      win.node.style.left = win.x + 'px';
      win.node.style.top = win.y + 'px';
    }
  }
  window.addEventListener('resize', relayout);

  return { open, close, focus, minimize, isOpen, get };
})();
