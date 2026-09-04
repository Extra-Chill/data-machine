/* =========================================================
   NOISEWRIGHT STUDIO — Generative engines
   Shared math + canvas systems used across the site.
   No dependencies. Plain ES.
   ========================================================= */
'use strict';

const NW = (function () {

  const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ── Deterministic PRNG (mulberry32) ──────────────────── */
  function rng(seed) {
    let a = seed >>> 0;
    return function () {
      a |= 0; a = (a + 0x6D2B79F5) | 0;
      let t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  /* ── Value-noise field (smooth, cheap, no libs) ───────── */
  function makeNoise(seed) {
    const r = rng(seed);
    const size = 256;
    const grad = new Float32Array(size);
    for (let i = 0; i < size; i++) grad[i] = r() * Math.PI * 2;
    const perm = new Uint8Array(512);
    const p = new Uint8Array(size);
    for (let i = 0; i < size; i++) p[i] = i;
    for (let i = size - 1; i > 0; i--) {
      const j = (r() * (i + 1)) | 0;
      [p[i], p[j]] = [p[j], p[i]];
    }
    for (let i = 0; i < 512; i++) perm[i] = p[i & 255];

    const fade = t => t * t * t * (t * (t * 6 - 15) + 10);
    const lerp = (a, b, t) => a + (b - a) * t;
    function gradAt(ix, iy) { return grad[perm[(ix + perm[iy & 255]) & 255]]; }

    // returns angle-like noise in [0,1)
    return function noise(x, y) {
      const x0 = Math.floor(x), y0 = Math.floor(y);
      const xf = x - x0, yf = y - y0;
      const u = fade(xf), v = fade(yf);
      const g00 = gradAt(x0, y0), g10 = gradAt(x0 + 1, y0);
      const g01 = gradAt(x0, y0 + 1), g11 = gradAt(x0 + 1, y0 + 1);
      const n0 = lerp(Math.sin(g00), Math.sin(g10), u);
      const n1 = lerp(Math.sin(g01), Math.sin(g11), u);
      return (lerp(n0, n1, v) + 1) / 2;
    };
  }

  /* ── HiDPI canvas sizing ──────────────────────────────── */
  function fitCanvas(canvas) {
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const rect = canvas.getBoundingClientRect();
    const w = Math.max(1, Math.round(rect.width));
    const h = Math.max(1, Math.round(rect.height));
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx, w, h, dpr };
  }

  /* ─────────────────────────────────────────────────────────
     HERO FLOW FIELD
     Particles advected through an evolving noise field.
     ───────────────────────────────────────────────────────── */
  function heroFlowField(canvas) {
    if (!canvas) return;
    let { ctx, w, h } = fitCanvas(canvas);
    const noise = makeNoise(20260624);
    const palette = ['#6cf2c8', '#8a7bff', '#ff6b8b', '#ffd166'];
    let particles = [];
    const SCALE = 0.0024;
    let z = 0;

    function spawnCount() {
      return Math.min(900, Math.max(220, Math.round((w * h) / 2600)));
    }
    function resetParticles() {
      particles = [];
      const n = spawnCount();
      for (let i = 0; i < n; i++) particles.push(newParticle());
    }
    function newParticle() {
      return {
        x: Math.random() * w,
        y: Math.random() * h,
        life: 0,
        max: 120 + Math.random() * 220,
        c: palette[(Math.random() * palette.length) | 0],
        spd: 0.6 + Math.random() * 1.2
      };
    }

    function step() {
      // gentle trail fade — gives motion blur ribbons
      ctx.fillStyle = 'rgba(10,10,15,0.055)';
      ctx.fillRect(0, 0, w, h);
      ctx.globalCompositeOperation = 'lighter';

      for (const p of particles) {
        const a = noise(p.x * SCALE, p.y * SCALE + z) * Math.PI * 4;
        const nx = p.x + Math.cos(a) * p.spd;
        const ny = p.y + Math.sin(a) * p.spd;
        ctx.strokeStyle = p.c;
        ctx.globalAlpha = 0.5;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        ctx.lineTo(nx, ny);
        ctx.stroke();
        p.x = nx; p.y = ny; p.life++;
        if (p.life > p.max || p.x < -10 || p.x > w + 10 || p.y < -10 || p.y > h + 10) {
          Object.assign(p, newParticle());
        }
      }
      ctx.globalAlpha = 1;
      ctx.globalCompositeOperation = 'source-over';
      z += 0.0016;
    }

    function paintBase() {
      ctx.fillStyle = '#0a0a0f';
      ctx.fillRect(0, 0, w, h);
    }

    function renderStaticFrame() {
      paintBase();
      // draw a few hundred short streaks for a still composition
      ctx.globalCompositeOperation = 'lighter';
      for (let i = 0; i < 4500; i++) {
        let x = Math.random() * w, y = Math.random() * h;
        const c = palette[(Math.random() * palette.length) | 0];
        ctx.strokeStyle = c;
        ctx.globalAlpha = 0.35;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, y);
        for (let s = 0; s < 14; s++) {
          const a = noise(x * SCALE, y * SCALE) * Math.PI * 4;
          x += Math.cos(a) * 1.2; y += Math.sin(a) * 1.2;
          ctx.lineTo(x, y);
        }
        ctx.stroke();
      }
      ctx.globalAlpha = 1;
      ctx.globalCompositeOperation = 'source-over';
    }

    let raf = null;
    function loop() { step(); raf = requestAnimationFrame(loop); }

    function start() {
      paintBase();
      resetParticles();
      if (REDUCED) { renderStaticFrame(); return; }
      cancelAnimationFrame(raf);
      loop();
    }

    let rt;
    window.addEventListener('resize', () => {
      clearTimeout(rt);
      rt = setTimeout(() => { ({ ctx, w, h } = fitCanvas(canvas)); start(); }, 180);
    });

    // pause when off-screen / tab hidden
    document.addEventListener('visibilitychange', () => {
      if (REDUCED) return;
      if (document.hidden) cancelAnimationFrame(raf);
      else loop();
    });

    start();
  }

  /* ─────────────────────────────────────────────────────────
     SMALL STILL "WORK" THUMBNAILS — regenerable from a seed.
     Several algorithm flavours keyed by `kind`.
     ───────────────────────────────────────────────────────── */
  const PALETTES = [
    ['#6cf2c8', '#0a0a0f', '#8a7bff'],
    ['#ff6b8b', '#0a0a0f', '#ffd166'],
    ['#8a7bff', '#0a0a0f', '#6cf2c8'],
    ['#ffd166', '#0a0a0f', '#ff6b8b']
  ];

  function renderThumb(canvas, kind, seed) {
    if (!canvas) return;
    const { ctx, w, h } = fitCanvas(canvas);
    const r = rng(seed);
    const pal = PALETTES[(r() * PALETTES.length) | 0];
    ctx.fillStyle = '#0b0b12';
    ctx.fillRect(0, 0, w, h);

    if (kind === 'field') {
      const noise = makeNoise(seed);
      ctx.globalCompositeOperation = 'lighter';
      const sc = 0.006 + r() * 0.004;
      for (let i = 0; i < 700; i++) {
        let x = r() * w, y = r() * h;
        ctx.strokeStyle = pal[i % 2 === 0 ? 0 : 2];
        ctx.globalAlpha = 0.4;
        ctx.lineWidth = 0.9;
        ctx.beginPath(); ctx.moveTo(x, y);
        for (let s = 0; s < 18; s++) {
          const a = noise(x * sc, y * sc) * Math.PI * 4;
          x += Math.cos(a) * 1.4; y += Math.sin(a) * 1.4;
          ctx.lineTo(x, y);
        }
        ctx.stroke();
      }
      ctx.globalCompositeOperation = 'source-over';

    } else if (kind === 'circles') {
      const n = 60 + (r() * 80 | 0);
      for (let i = 0; i < n; i++) {
        const x = r() * w, y = r() * h, rad = r() * w * 0.18 + 4;
        ctx.strokeStyle = pal[r() < 0.5 ? 0 : 2];
        ctx.globalAlpha = 0.25 + r() * 0.4;
        ctx.lineWidth = 0.7 + r() * 1.4;
        ctx.beginPath(); ctx.arc(x, y, rad, 0, Math.PI * 2); ctx.stroke();
      }
      ctx.globalAlpha = 1;

    } else if (kind === 'grid') {
      const cols = 8 + (r() * 8 | 0), rows = 6 + (r() * 6 | 0);
      const cw = w / cols, ch = h / rows;
      for (let gy = 0; gy < rows; gy++) {
        for (let gx = 0; gx < cols; gx++) {
          const cx = gx * cw + cw / 2, cy = gy * ch + ch / 2;
          ctx.strokeStyle = pal[(gx + gy) % 2 === 0 ? 0 : 2];
          ctx.globalAlpha = 0.6;
          ctx.lineWidth = 1.2;
          ctx.beginPath();
          if (r() < 0.5) { ctx.moveTo(cx - cw * 0.35, cy - ch * 0.35); ctx.lineTo(cx + cw * 0.35, cy + ch * 0.35); }
          else { ctx.moveTo(cx + cw * 0.35, cy - ch * 0.35); ctx.lineTo(cx - cw * 0.35, cy + ch * 0.35); }
          ctx.stroke();
        }
      }
      ctx.globalAlpha = 1;

    } else if (kind === 'orbits') {
      const cx = w / 2, cy = h / 2;
      const arms = 3 + (r() * 4 | 0);
      ctx.globalCompositeOperation = 'lighter';
      for (let a = 0; a < arms; a++) {
        const pts = 220;
        const phase = r() * Math.PI * 2;
        const k = 1 + r() * 4;
        ctx.strokeStyle = pal[a % 2 === 0 ? 0 : 2];
        ctx.globalAlpha = 0.5;
        ctx.lineWidth = 0.8;
        ctx.beginPath();
        for (let i = 0; i < pts; i++) {
          const t = (i / pts) * Math.PI * 2 * 3;
          const rad = (Math.min(w, h) * 0.42) * (0.3 + 0.7 * Math.abs(Math.sin(t * 0.5 + phase)));
          const x = cx + Math.cos(t * k + phase) * rad;
          const y = cy + Math.sin(t + phase) * rad * 0.7;
          i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
        }
        ctx.stroke();
      }
      ctx.globalCompositeOperation = 'source-over';
      ctx.globalAlpha = 1;

    } else if (kind === 'voronoi') {
      // cheap stipple-cell look
      const sites = [];
      const n = 14 + (r() * 14 | 0);
      for (let i = 0; i < n; i++) sites.push([r() * w, r() * h, pal[r() < 0.5 ? 0 : 2]]);
      const step = 6;
      for (let y = 0; y < h; y += step) {
        for (let x = 0; x < w; x += step) {
          let best = Infinity, col = pal[0];
          for (const s of sites) {
            const d = (s[0] - x) * (s[0] - x) + (s[1] - y) * (s[1] - y);
            if (d < best) { best = d; col = s[2]; }
          }
          ctx.fillStyle = col;
          ctx.globalAlpha = 0.06 + (Math.sqrt(best) / w) * 0.5;
          ctx.fillRect(x, y, step, step);
        }
      }
      ctx.globalAlpha = 1;
    }

    // signature corner ticks
    ctx.strokeStyle = 'rgba(236,235,245,0.25)';
    ctx.lineWidth = 1;
    const m = 10, t = 9;
    ctx.beginPath();
    ctx.moveTo(m, m + t); ctx.lineTo(m, m); ctx.lineTo(m + t, m);
    ctx.moveTo(w - m - t, h - m); ctx.lineTo(w - m, h - m); ctx.lineTo(w - m, h - m - t);
    ctx.stroke();
  }

  /* ─────────────────────────────────────────────────────────
     INTERACTIVE LAB — slider-driven recursive pattern.
     ───────────────────────────────────────────────────────── */
  function makeLab(canvas, getParams) {
    if (!canvas) return { render() {} };

    function render() {
      const { ctx, w, h } = fitCanvas(canvas);
      const { density, twist, scale, color, seed } = getParams();
      const r = rng(seed);
      const noise = makeNoise(seed);
      ctx.fillStyle = '#0b0b12';
      ctx.fillRect(0, 0, w, h);

      const cols = ['#6cf2c8', '#ff6b8b', '#8a7bff', '#ffd166'];
      const main = cols[color] || cols[0];
      const alt = cols[(color + 2) % cols.length];

      const cx = w / 2, cy = h / 2;
      const rings = density;
      ctx.globalCompositeOperation = 'lighter';
      for (let ring = 0; ring < rings; ring++) {
        const rr = (ring / rings) * Math.min(w, h) * 0.46 + 8;
        const seg = 60 + ring * 6;
        ctx.strokeStyle = ring % 2 === 0 ? main : alt;
        ctx.globalAlpha = 0.28;
        ctx.lineWidth = 0.8;
        ctx.beginPath();
        for (let i = 0; i <= seg; i++) {
          const t = (i / seg) * Math.PI * 2;
          const n = noise(Math.cos(t) * scale + ring * 0.2, Math.sin(t) * scale + ring * 0.2);
          const wob = (n - 0.5) * rr * 0.55;
          const ang = t + ring * twist * 0.12;
          const x = cx + Math.cos(ang) * (rr + wob);
          const y = cy + Math.sin(ang) * (rr + wob);
          i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
        }
        ctx.closePath();
        ctx.stroke();
      }
      ctx.globalCompositeOperation = 'source-over';
      ctx.globalAlpha = 1;
    }
    return { render };
  }

  /* ─────────────────────────────────────────────────────────
     FOOTER / CTA AMBIENT FIELD — slow drifting dots.
     Animates unless reduced-motion (then one static frame).
     ───────────────────────────────────────────────────────── */
  function ambientField(canvas, opts) {
    if (!canvas) return;
    opts = opts || {};
    let { ctx, w, h } = fitCanvas(canvas);
    const noise = makeNoise(opts.seed || 777);
    const color = opts.color || '#6cf2c8';
    let pts = [];
    let z = 0;

    function build() {
      pts = [];
      const n = Math.min(420, Math.max(80, Math.round((w * h) / 5200)));
      for (let i = 0; i < n; i++) pts.push({ x: Math.random() * w, y: Math.random() * h });
    }
    function frame() {
      ctx.clearRect(0, 0, w, h);
      ctx.fillStyle = color;
      for (const p of pts) {
        const a = noise(p.x * 0.004, p.y * 0.004 + z) * Math.PI * 4;
        p.x += Math.cos(a) * 0.4;
        p.y += Math.sin(a) * 0.4;
        if (p.x < 0) p.x = w; if (p.x > w) p.x = 0;
        if (p.y < 0) p.y = h; if (p.y > h) p.y = 0;
        ctx.globalAlpha = 0.5;
        ctx.fillRect(p.x, p.y, 1.4, 1.4);
      }
      ctx.globalAlpha = 1;
      z += 0.001;
    }
    let raf = null;
    function loop() { frame(); raf = requestAnimationFrame(loop); }
    function start() {
      build();
      if (REDUCED) { frame(); return; }
      cancelAnimationFrame(raf);
      loop();
    }
    let rt;
    window.addEventListener('resize', () => {
      clearTimeout(rt);
      rt = setTimeout(() => { ({ ctx, w, h } = fitCanvas(canvas)); start(); }, 180);
    });
    document.addEventListener('visibilitychange', () => {
      if (REDUCED) return;
      document.hidden ? cancelAnimationFrame(raf) : loop();
    });
    start();
  }

  return { REDUCED, rng, makeNoise, fitCanvas, heroFlowField, renderThumb, makeLab, ambientField };
})();
