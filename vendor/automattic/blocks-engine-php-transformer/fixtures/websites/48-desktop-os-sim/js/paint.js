/* =========================================================
   AuroraOS 88 — NeonPaint
   A small but working canvas paint app: brush, neon-glow
   brush, eraser, size, color swatches, clear, and save.
   Pointer-driven so it works with mouse + touch.
   ========================================================= */
'use strict';

AOS.Paint = (function () {

  const COLORS = ['#ff4fd6', '#00eaff', '#ffd166', '#8affc1', '#7a2bff', '#ffffff', '#ff6b8b', '#0b0220'];

  function mount(body, api) {
    const root = AOS.el('div', { class: 'paint' });
    const tools = AOS.el('div', { class: 'paint-tools' });
    const wrap = AOS.el('div', { class: 'paint-canvas-wrap' });
    const canvas = AOS.el('canvas', { id: 'paint-canvas' });
    wrap.append(canvas);
    root.append(tools, wrap);
    body.append(root);

    const ctx = canvas.getContext('2d');
    let color = COLORS[0];
    let size = 6;
    let tool = 'brush';   // brush | glow | erase
    let drawing = false;
    let last = null;
    let dpr = Math.min(window.devicePixelRatio || 1, 2);

    function resize() {
      // preserve drawing across resize
      const prev = (canvas.width && canvas.height) ? ctx.getImageData(0, 0, canvas.width, canvas.height) : null;
      const pw = canvas.width, ph = canvas.height;
      dpr = Math.min(window.devicePixelRatio || 1, 2);
      const w = wrap.clientWidth, h = wrap.clientHeight;
      canvas.width = Math.max(1, Math.round(w * dpr));
      canvas.height = Math.max(1, Math.round(h * dpr));
      ctx.lineCap = 'round'; ctx.lineJoin = 'round';
      if (prev && pw && ph) {
        const tmp = document.createElement('canvas'); tmp.width = pw; tmp.height = ph;
        tmp.getContext('2d').putImageData(prev, 0, 0);
        ctx.drawImage(tmp, 0, 0, pw, ph, 0, 0, canvas.width, canvas.height);
      }
    }

    function pos(e) {
      const r = canvas.getBoundingClientRect();
      return { x: (e.clientX - r.left) * (canvas.width / r.width), y: (e.clientY - r.top) * (canvas.height / r.height) };
    }
    function stroke(a, b) {
      ctx.globalCompositeOperation = tool === 'erase' ? 'destination-out' : 'source-over';
      ctx.strokeStyle = color;
      ctx.lineWidth = size * dpr;
      if (tool === 'glow') { ctx.shadowBlur = size * 2.4 * dpr; ctx.shadowColor = color; }
      else ctx.shadowBlur = 0;
      ctx.beginPath();
      ctx.moveTo(a.x, a.y);
      ctx.lineTo(b.x, b.y);
      ctx.stroke();
      ctx.shadowBlur = 0;
    }

    canvas.addEventListener('pointerdown', (e) => {
      drawing = true; last = pos(e); canvas.setPointerCapture(e.pointerId);
      stroke(last, { x: last.x + 0.01, y: last.y + 0.01 });
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!drawing) return;
      const p = pos(e); stroke(last, p); last = p;
    });
    const up = (e) => { drawing = false; try { canvas.releasePointerCapture(e.pointerId); } catch (_) {} };
    canvas.addEventListener('pointerup', up);
    canvas.addEventListener('pointercancel', up);

    /* ── toolbar ── */
    COLORS.forEach(c => {
      const sw = AOS.el('button', { class: 'paint-swatch' + (c === color ? ' on' : ''), style: 'background:' + c, 'aria-label': 'Color ' + c });
      sw.addEventListener('click', () => { color = c; tool = (tool === 'erase' ? 'brush' : tool); syncTools(); });
      sw.dataset.color = c;
      tools.append(sw);
    });
    tools.append(AOS.el('span', { class: 'paint-sep' }));

    const toolBtns = {};
    [['brush', '✎ Brush'], ['glow', '✨ Neon'], ['erase', '⌫ Erase']].forEach(([id, label]) => {
      const b = AOS.el('button', { class: 'paint-tool' + (id === tool ? ' on' : ''), text: label });
      b.addEventListener('click', () => { tool = id; syncTools(); });
      toolBtns[id] = b; tools.append(b);
    });

    tools.append(AOS.el('span', { class: 'paint-sep' }));
    const range = AOS.el('input', { type: 'range', class: 'paint-range', min: '1', max: '40', value: String(size), 'aria-label': 'Brush size' });
    range.addEventListener('input', () => { size = +range.value; });
    tools.append(AOS.el('span', { style: 'font-size:.72rem;color:var(--ink-soft)' }, 'Size'), range);

    tools.append(AOS.el('span', { class: 'paint-sep' }));
    const clearBtn = AOS.el('button', { class: 'paint-tool', text: '🗑 Clear' });
    clearBtn.addEventListener('click', () => ctx.clearRect(0, 0, canvas.width, canvas.height));
    const saveBtn = AOS.el('button', { class: 'paint-tool', text: '⤓ Save PNG' });
    saveBtn.addEventListener('click', () => {
      try {
        const a = document.createElement('a');
        a.download = 'auroraos-doodle.png';
        a.href = canvas.toDataURL('image/png');
        a.click();
      } catch (e) { /* file:// may block — ignore */ }
    });
    tools.append(clearBtn, saveBtn);

    function syncTools() {
      Object.entries(toolBtns).forEach(([id, b]) => b.classList.toggle('on', id === tool));
      tools.querySelectorAll('.paint-swatch').forEach(s => s.classList.toggle('on', s.dataset.color === color));
    }

    // size after the window has a measured box
    requestAnimationFrame(() => {
      resize();
      // welcome doodle
      ctx.font = `${28 * dpr}px 'VT323', monospace`;
      ctx.fillStyle = 'rgba(0,234,255,.45)';
      ctx.fillText('draw something →', 18 * dpr, 40 * dpr);
    });
    new ResizeObserver(() => resize()).observe(wrap);
  }

  return { mount };
})();
