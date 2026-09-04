/* ============================================================
   BEYOND THE BLOCK — demos.js
   The live, pokeable widgets. Each one is the argument made flesh:
   "go ahead, build THIS by stacking static blocks."

   Demos:
     1. physics  — gravity/collision particle toy you can fling
     2. synth    — Web Audio bleep keyboard (mouse + QWERTY)
     3. genart   — generative art that re-rolls a new seed
     4. typereact— type and watch the letters explode into particles
     5. magnet   — magnetic / cursor-reactive UI dots
     6. attractor— strange-attractor "data" visual with a draggable param
   Each registers via BTB.demo(name, fn) and auto-mounts onto
   any element carrying data-demo="name".
   ============================================================ */
(function () {
  'use strict';
  const B = window.BTB;
  const REDUCED = B.reduced;
  const TAU = B.TAU, clamp = B.clamp, lerp = B.lerp;

  const registry = {};
  B.demo = (name, fn) => { registry[name] = fn; };

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-demo]').forEach(host => {
      const fn = registry[host.dataset.demo];
      if (fn) { try { fn(host); } catch (e) { console.warn('demo failed', host.dataset.demo, e); } }
    });
  });

  /* ============================================================
     1. PHYSICS TOY — verlet balls with gravity + walls + drag-fling
     ============================================================ */
  B.demo('physics', function (host) {
    const canvas = host.querySelector('canvas');
    const ctx = canvas.getContext('2d');
    let W, H, dpr, balls = [], dragging = null, pointer = { x: 0, y: 0, px: 0, py: 0 };
    const palette = ['#36e6ff', '#8a6bff', '#ff3ea5', '#c6ff3a', '#ffc24b'];

    function reset() {
      const f = B.fitCanvas(canvas); W = f.w; H = f.h; dpr = f.dpr;
      if (!balls.length) spawn(REDUCED ? 9 : 22);
    }
    function spawn(n) {
      for (let i = 0; i < n; i++) {
        const r = 10 + Math.random() * 18;
        balls.push({
          x: r + Math.random() * (W - 2 * r), y: Math.random() * H * 0.5,
          px: 0, py: 0, r, col: palette[(Math.random() * palette.length) | 0]
        });
        const b = balls[balls.length - 1]; b.px = b.x - (Math.random() - 0.5) * 4; b.py = b.y;
      }
    }
    reset();
    new ResizeObserver(reset).observe(canvas);

    function pos(e) {
      const r = canvas.getBoundingClientRect();
      const t = e.touches ? e.touches[0] : e;
      return { x: t.clientX - r.left, y: t.clientY - r.top };
    }
    function down(e) {
      const p = pos(e); pointer.x = pointer.px = p.x; pointer.y = pointer.py = p.y;
      for (let i = balls.length - 1; i >= 0; i--) {
        const b = balls[i], dx = p.x - b.x, dy = p.y - b.y;
        if (dx * dx + dy * dy < b.r * b.r) { dragging = b; break; }
      }
      if (e.cancelable) e.preventDefault();
    }
    function move(e) { const p = pos(e); pointer.px = pointer.x; pointer.py = pointer.y; pointer.x = p.x; pointer.y = p.y; }
    function up() {
      if (dragging) { dragging.px = dragging.x - (pointer.x - pointer.px); dragging.py = dragging.y - (pointer.y - pointer.py); }
      dragging = null;
    }
    canvas.addEventListener('mousedown', down); canvas.addEventListener('touchstart', down, { passive: false });
    window.addEventListener('mousemove', move); window.addEventListener('touchmove', move, { passive: false });
    window.addEventListener('mouseup', up); window.addEventListener('touchend', up);

    const btn = host.querySelector('[data-act="shake"]');
    if (btn) btn.addEventListener('click', () => {
      balls.forEach(b => { b.px = b.x + (Math.random() - 0.5) * 40; b.py = b.y + (Math.random() - 0.5) * 40; });
    });
    const addBtn = host.querySelector('[data-act="add"]');
    if (addBtn) addBtn.addEventListener('click', () => spawn(6));

    const GRAV = 0.45, FRICT = 0.99, BOUNCE = 0.72;
    function step() {
      for (const b of balls) {
        if (b === dragging) { b.x = pointer.x; b.y = pointer.y; b.px = pointer.px; b.py = pointer.py; continue; }
        const vx = (b.x - b.px) * FRICT, vy = (b.y - b.py) * FRICT;
        b.px = b.x; b.py = b.y; b.x += vx; b.y += vy + GRAV;
        if (b.x < b.r) { b.x = b.r; b.px = b.x + vx * BOUNCE; }
        if (b.x > W - b.r) { b.x = W - b.r; b.px = b.x + vx * BOUNCE; }
        if (b.y > H - b.r) { b.y = H - b.r; b.py = b.y + vy * BOUNCE; b.px = b.x - vx * 0.6; }
        if (b.y < b.r) { b.y = b.r; b.py = b.y + vy * BOUNCE; }
      }
      /* pairwise collisions */
      for (let i = 0; i < balls.length; i++) {
        for (let j = i + 1; j < balls.length; j++) {
          const a = balls[i], c = balls[j];
          let dx = c.x - a.x, dy = c.y - a.y, d = Math.hypot(dx, dy), min = a.r + c.r;
          if (d > 0 && d < min) {
            const o = (min - d) / 2, nx = dx / d, ny = dy / d;
            if (a !== dragging) { a.x -= nx * o; a.y -= ny * o; }
            if (c !== dragging) { c.x += nx * o; c.y += ny * o; }
          }
        }
      }
    }
    function draw() {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, W, H);
      for (const b of balls) {
        const g = ctx.createRadialGradient(b.x - b.r * .3, b.y - b.r * .3, b.r * .1, b.x, b.y, b.r);
        g.addColorStop(0, '#fff'); g.addColorStop(.25, b.col); g.addColorStop(1, b.col);
        ctx.fillStyle = g; ctx.globalAlpha = .92;
        ctx.beginPath(); ctx.arc(b.x, b.y, b.r, 0, TAU); ctx.fill();
      }
      ctx.globalAlpha = 1;
    }
    if (REDUCED) { for (let k = 0; k < 120; k++) step(); draw(); return; }
    B.onScreenLoop(canvas, () => { step(); draw(); });
  });

  /* ============================================================
     2. SYNTH — Web Audio mini keyboard, mouse + keyboard
     ============================================================ */
  B.demo('synth', function (host) {
    let AC = null, master = null;
    const NOTES = [
      ['C', 261.63, 'a'], ['D', 293.66, 's'], ['E', 329.63, 'd'], ['F', 349.23, 'f'],
      ['G', 392.00, 'g'], ['A', 440.00, 'h'], ['B', 493.88, 'j'], ['C2', 523.25, 'k']
    ];
    const keysWrap = host.querySelector('.keys');
    const waveChips = host.querySelectorAll('[data-wave]');
    let wave = 'triangle';

    function ensure() {
      if (AC) return;
      AC = new (window.AudioContext || window.webkitAudioContext)();
      master = AC.createGain(); master.gain.value = 0.0001; master.connect(AC.destination);
      master.gain.linearRampToValueAtTime(0.5, AC.currentTime + 0.05);
    }
    function play(freq) {
      ensure();
      if (AC.state === 'suspended') AC.resume();
      const now = AC.currentTime;
      const osc = AC.createOscillator(), g = AC.createGain();
      osc.type = wave; osc.frequency.value = freq;
      /* tiny detuned second osc for thickness */
      const osc2 = AC.createOscillator(), g2 = AC.createGain();
      osc2.type = wave; osc2.frequency.value = freq * 1.005; g2.gain.value = 0.5;
      g.gain.setValueAtTime(0.0001, now);
      g.gain.exponentialRampToValueAtTime(0.6, now + 0.012);
      g.gain.exponentialRampToValueAtTime(0.0001, now + 0.9);
      osc.connect(g); osc2.connect(g2); g2.connect(g); g.connect(master);
      osc.start(now); osc2.start(now); osc.stop(now + 0.95); osc2.stop(now + 0.95);
    }

    keysWrap.innerHTML = NOTES.map(([n, f, k]) =>
      `<div class="key" data-freq="${f}" data-k="${k}" role="button" tabindex="0" aria-label="Play note ${n}"><span>${k.toUpperCase()}</span></div>`).join('');
    const keyEls = [...keysWrap.querySelectorAll('.key')];

    function hit(el) {
      play(parseFloat(el.dataset.freq));
      el.classList.add('down'); setTimeout(() => el.classList.remove('down'), 140);
    }
    keyEls.forEach(el => {
      el.addEventListener('mousedown', () => hit(el));
      el.addEventListener('touchstart', (e) => { e.preventDefault(); hit(el); }, { passive: false });
      el.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); hit(el); } });
    });
    window.addEventListener('keydown', (e) => {
      if (e.repeat || e.metaKey || e.ctrlKey) return;
      const el = keyEls.find(k => k.dataset.k === e.key.toLowerCase());
      if (el && document.activeElement.tagName !== 'INPUT') { hit(el); }
    });
    waveChips.forEach(c => c.addEventListener('click', () => {
      waveChips.forEach(x => x.classList.remove('active')); c.classList.add('active'); wave = c.dataset.wave;
    }));
    const arp = host.querySelector('[data-act="arp"]');
    if (arp) arp.addEventListener('click', () => {
      ensure();
      NOTES.forEach(([n, f], i) => setTimeout(() => {
        const el = keyEls[i]; hit(el);
      }, i * 110));
    });
  });

  /* ============================================================
     3. GENERATIVE ART — re-seedable circle-packed / orbit art
     ============================================================ */
  B.demo('genart', function (host) {
    const canvas = host.querySelector('canvas');
    const ctx = canvas.getContext('2d');
    let W, H, dpr, seed = (Math.random() * 1e9) | 0;
    const seedLabel = host.querySelector('[data-seed-label]');
    function reset() { const f = B.fitCanvas(canvas); W = f.w; H = f.h; dpr = f.dpr; render(); }
    function render() {
      const r = B.rng(seed);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      const g = ctx.createLinearGradient(0, 0, W, H);
      const baseHue = r() * 360;
      g.addColorStop(0, `hsl(${baseHue},45%,8%)`); g.addColorStop(1, `hsl(${(baseHue + 60) % 360},55%,6%)`);
      ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
      const cx = W / 2, cy = H / 2;
      const rings = 4 + ((r() * 5) | 0);
      for (let ring = 0; ring < rings; ring++) {
        const count = 6 + ((r() * 30) | 0);
        const rad = (ring + 1) / rings * Math.min(W, H) * 0.46;
        const hue = (baseHue + ring * 40 + r() * 40) % 360;
        const off = r() * TAU;
        for (let i = 0; i < count; i++) {
          const a = off + (i / count) * TAU;
          const wob = (r() - 0.5) * 24;
          const x = cx + Math.cos(a) * (rad + wob), y = cy + Math.sin(a) * (rad + wob);
          const size = 2 + r() * (10 - ring);
          ctx.beginPath();
          if (r() > 0.4) { ctx.arc(x, y, size, 0, TAU); }
          else { ctx.rect(x - size, y - size, size * 2, size * 2); }
          ctx.fillStyle = `hsla(${hue},85%,${55 + r() * 20}%,${0.5 + r() * 0.4})`;
          ctx.fill();
          if (r() > 0.75) {
            ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(x, y);
            ctx.strokeStyle = `hsla(${hue},80%,60%,0.10)`; ctx.lineWidth = 0.6; ctx.stroke();
          }
        }
      }
      if (seedLabel) seedLabel.textContent = '0x' + (seed >>> 0).toString(16).padStart(8, '0');
    }
    reset();
    new ResizeObserver(() => { const f = B.fitCanvas(canvas); W = f.w; H = f.h; dpr = f.dpr; render(); }).observe(canvas);
    const reroll = () => { seed = (Math.random() * 1e9) | 0; render(); };
    const btn = host.querySelector('[data-act="reroll"]');
    if (btn) btn.addEventListener('click', reroll);
    canvas.style.cursor = 'pointer';
    canvas.addEventListener('click', reroll);
  });

  /* ============================================================
     4. TYPE-REACT — type and letters burst into particles
     ============================================================ */
  B.demo('typereact', function (host) {
    const canvas = host.querySelector('canvas');
    const input = host.querySelector('input');
    const ctx = canvas.getContext('2d');
    let W, H, dpr, parts = [];
    const palette = ['#36e6ff', '#8a6bff', '#ff3ea5', '#c6ff3a', '#ffc24b', '#ffffff'];
    function reset() { const f = B.fitCanvas(canvas); W = f.w; H = f.h; dpr = f.dpr; }
    reset(); new ResizeObserver(reset).observe(canvas);

    function burst(ch) {
      const r = canvas.getBoundingClientRect();
      const ir = input.getBoundingClientRect();
      const x = clamp(ir.left - r.left + ir.width * 0.5, 30, W - 30);
      const y = H * 0.5;
      const n = ch === ' ' ? 6 : 16;
      for (let i = 0; i < n; i++) {
        const a = Math.random() * TAU, sp = 1 + Math.random() * 5;
        parts.push({
          x, y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp - 2,
          life: 1, ch: Math.random() > 0.6 ? ch : ['+', '·', '◆', '*'][i % 4],
          col: palette[(Math.random() * palette.length) | 0], size: 10 + Math.random() * 16, rot: Math.random() * TAU
        });
      }
      if (parts.length > 400) parts.splice(0, parts.length - 400);
    }
    input.addEventListener('input', (e) => {
      const v = e.target.value;
      const last = v[v.length - 1] || ' ';
      if (!REDUCED) burst(last);
    });
    input.addEventListener('keydown', (e) => { if (e.key === 'Backspace' && !REDUCED) burst('×'); });

    function frame(dt) {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, W, H);
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      for (let i = parts.length - 1; i >= 0; i--) {
        const p = parts[i];
        p.vy += 6 * dt; p.x += p.vx; p.y += p.vy; p.vx *= 0.99; p.life -= dt * 0.9; p.rot += 0.05;
        if (p.life <= 0) { parts.splice(i, 1); continue; }
        ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.rot);
        ctx.globalAlpha = clamp(p.life, 0, 1); ctx.fillStyle = p.col;
        ctx.font = `700 ${p.size}px 'JetBrains Mono', monospace`;
        ctx.fillText(p.ch, 0, 0); ctx.restore();
      }
      ctx.globalAlpha = 1;
    }
    if (REDUCED) return;
    B.onScreenLoop(canvas, (dt) => frame(dt));
  });

  /* ============================================================
     5. MAGNETIC DOTS — cursor-reactive lattice that repels/attracts
     ============================================================ */
  B.demo('magnet', function (host) {
    const canvas = host.querySelector('canvas');
    const ctx = canvas.getContext('2d');
    let W, H, dpr, pts = [], m = { x: -999, y: -999 }, mode = 'repel';
    function build() {
      const f = B.fitCanvas(canvas); W = f.w; H = f.h; dpr = f.dpr;
      pts = []; const gap = 26;
      for (let x = gap; x < W; x += gap)
        for (let y = gap; y < H; y += gap)
          pts.push({ ox: x, oy: y, x, y, vx: 0, vy: 0 });
    }
    build(); new ResizeObserver(build).observe(canvas);
    canvas.addEventListener('mousemove', e => { const r = canvas.getBoundingClientRect(); m.x = e.clientX - r.left; m.y = e.clientY - r.top; });
    canvas.addEventListener('mouseleave', () => { m.x = m.y = -999; });
    canvas.addEventListener('touchmove', e => { const r = canvas.getBoundingClientRect(); const t = e.touches[0]; m.x = t.clientX - r.left; m.y = t.clientY - r.top; if (e.cancelable) e.preventDefault(); }, { passive: false });
    host.querySelectorAll('[data-mode]').forEach(c => c.addEventListener('click', () => {
      host.querySelectorAll('[data-mode]').forEach(x => x.classList.remove('active'));
      c.classList.add('active'); mode = c.dataset.mode;
    }));
    function frame() {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, W, H);
      const R = 110, dir = mode === 'attract' ? -1 : 1;
      for (const p of pts) {
        const dx = p.x - m.x, dy = p.y - m.y, d = Math.hypot(dx, dy);
        if (d < R && d > 0.01) {
          const f = (1 - d / R) * 6 * dir;
          p.vx += (dx / d) * f; p.vy += (dy / d) * f;
        }
        p.vx += (p.ox - p.x) * 0.06; p.vy += (p.oy - p.y) * 0.06;
        p.vx *= 0.86; p.vy *= 0.86; p.x += p.vx; p.y += p.vy;
        const disp = Math.hypot(p.x - p.ox, p.y - p.oy);
        const hue = 190 + clamp(disp * 3, 0, 130);
        ctx.fillStyle = `hsl(${hue},90%,${55 + clamp(disp, 0, 25)}%)`;
        ctx.beginPath(); ctx.arc(p.x, p.y, 1.6 + clamp(disp * 0.05, 0, 3), 0, TAU); ctx.fill();
      }
    }
    if (REDUCED) { frame(); return; }
    B.onScreenLoop(canvas, frame);
  });

  /* ============================================================
     6. ATTRACTOR — Clifford strange attractor, draggable parameter
     "data art" that mutates continuously
     ============================================================ */
  B.demo('attractor', function (host) {
    const canvas = host.querySelector('canvas');
    const ctx = canvas.getContext('2d');
    let W, H, dpr;
    const slider = host.querySelector('input[type="range"]');
    const out = host.querySelector('[data-attr-val]');
    function reset() { const f = B.fitCanvas(canvas); W = f.w; H = f.h; dpr = f.dpr; }
    reset(); new ResizeObserver(reset).observe(canvas);

    function draw(t) {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.fillStyle = 'rgba(7,6,13,0.18)'; ctx.fillRect(0, 0, W, H);
      const k = slider ? parseFloat(slider.value) : 1.6;
      const a = -1.4 + Math.sin(t * 0.07) * 0.3, b = 1.6 + k, c = 1.0, d = 0.7 + Math.cos(t * 0.05) * 0.2;
      let x = 0.1, y = 0.1;
      const scale = Math.min(W, H) / 4.4, cx = W / 2, cy = H / 2;
      const N = REDUCED ? 6000 : 2200;
      for (let i = 0; i < N; i++) {
        const nx = Math.sin(a * y) + c * Math.cos(a * x);
        const ny = Math.sin(b * x) + d * Math.cos(b * y);
        x = nx; y = ny;
        const px = cx + x * scale, py = cy + y * scale;
        const hue = (180 + (x + 2) * 60 + t * 10) % 360;
        ctx.fillStyle = `hsla(${hue},90%,65%,0.5)`;
        ctx.fillRect(px, py, 1, 1);
      }
    }
    if (slider) slider.addEventListener('input', () => { if (out) out.textContent = parseFloat(slider.value).toFixed(2); });
    if (out && slider) out.textContent = parseFloat(slider.value).toFixed(2);
    if (REDUCED) { ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.fillStyle = '#07060d'; ctx.fillRect(0, 0, W, H); draw(0); return; }
    B.onScreenLoop(canvas, (dt, t) => draw(t));
  });

  /* ============================================================
     MINI PREVIEWS for the "what's possible" page (data-mini)
     small autonomous canvases, deterministic per seed
     ============================================================ */
  B.demo('mini', function (host) {
    const canvas = host.querySelector('canvas');
    const ctx = canvas.getContext('2d');
    const kind = host.dataset.mini || 'wave';
    const seed = parseInt(host.dataset.seed || '7', 10);
    const noise = B.makeNoise(seed);
    let W, H, dpr;
    function reset() { const f = B.fitCanvas(canvas); W = f.w; H = f.h; dpr = f.dpr; }
    reset(); new ResizeObserver(reset).observe(canvas);
    const palette = ['#36e6ff', '#8a6bff', '#ff3ea5', '#c6ff3a'];
    function draw(t) {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, W, H);
      if (kind === 'wave') {
        for (let l = 0; l < 5; l++) {
          ctx.beginPath();
          for (let x = 0; x <= W; x += 4) {
            const y = H / 2 + Math.sin(x * 0.02 + t + l) * 14 + noise(x * 0.01, l + t * 0.1) * 26 - 13;
            x === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
          }
          ctx.strokeStyle = palette[l % palette.length]; ctx.globalAlpha = 0.55; ctx.lineWidth = 1.4; ctx.stroke();
        }
      } else if (kind === 'orbit') {
        const cx = W / 2, cy = H / 2;
        for (let i = 0; i < 5; i++) {
          const a = t * (0.4 + i * 0.2) + i;
          const rad = 8 + i * 9;
          const x = cx + Math.cos(a) * rad, y = cy + Math.sin(a * 1.3) * rad;
          ctx.beginPath(); ctx.arc(x, y, 3 + i, 0, TAU);
          ctx.fillStyle = palette[i % palette.length]; ctx.globalAlpha = 0.7; ctx.fill();
        }
      } else if (kind === 'grid') {
        const g = 14;
        for (let x = 0; x < W; x += g) for (let y = 0; y < H; y += g) {
          const n = noise(x * 0.05 + t * 0.3, y * 0.05);
          ctx.fillStyle = palette[(n * palette.length) | 0]; ctx.globalAlpha = n;
          const s = n * g; ctx.fillRect(x + (g - s) / 2, y + (g - s) / 2, s, s);
        }
      } else { /* spiral */
        const cx = W / 2, cy = H / 2;
        ctx.beginPath();
        for (let i = 0; i < 220; i++) {
          const a = i * 0.3 + t, rad = i * 0.22;
          const x = cx + Math.cos(a) * rad, y = cy + Math.sin(a) * rad;
          i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
        }
        ctx.strokeStyle = palette[seed % palette.length]; ctx.globalAlpha = 0.8; ctx.lineWidth = 1.4; ctx.stroke();
      }
      ctx.globalAlpha = 1;
    }
    if (REDUCED) { draw(1.2); return; }
    B.onScreenLoop(canvas, (dt, t) => draw(t));
  });
})();
