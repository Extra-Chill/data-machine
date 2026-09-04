/* ============================================================
   BEYOND THE BLOCK — engine.js
   Shared chrome + reusable visual primitives.
   Everything here is hand-rolled vanilla JS. No libraries.
   ============================================================ */
(function () {
  'use strict';

  const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  window.BTB = window.BTB || {};
  window.BTB.reduced = REDUCED;

  /* ---------- tiny utils ---------- */
  const TAU = Math.PI * 2;
  const lerp = (a, b, t) => a + (b - a) * t;
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
  window.BTB.lerp = lerp;
  window.BTB.clamp = clamp;
  window.BTB.TAU = TAU;

  /* ---------- seeded RNG (mulberry32) ---------- */
  function rng(seed) {
    let a = seed >>> 0;
    return function () {
      a |= 0; a = (a + 0x6D2B79F5) | 0;
      let t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }
  window.BTB.rng = rng;

  /* ---------- value-noise field (smooth, deterministic) ---------- */
  function makeNoise(seed) {
    const r = rng(seed || 1337);
    const SIZE = 256;
    const grid = new Float32Array(SIZE * SIZE);
    for (let i = 0; i < grid.length; i++) grid[i] = r();
    const fade = t => t * t * t * (t * (t * 6 - 15) + 10);
    return function (x, y) {
      const xi = Math.floor(x) & (SIZE - 1);
      const yi = Math.floor(y) & (SIZE - 1);
      const xf = x - Math.floor(x);
      const yf = y - Math.floor(y);
      const x1 = (xi + 1) & (SIZE - 1);
      const y1 = (yi + 1) & (SIZE - 1);
      const tl = grid[yi * SIZE + xi];
      const tr = grid[yi * SIZE + x1];
      const bl = grid[y1 * SIZE + xi];
      const br = grid[y1 * SIZE + x1];
      const u = fade(xf), v = fade(yf);
      return lerp(lerp(tl, tr, u), lerp(bl, br, u), v);
    };
  }
  window.BTB.makeNoise = makeNoise;

  /* ---------- HiDPI canvas sizing ---------- */
  function fitCanvas(canvas) {
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const rect = canvas.getBoundingClientRect();
    const w = Math.max(1, Math.floor(rect.width * dpr));
    const h = Math.max(1, Math.floor(rect.height * dpr));
    if (canvas.width !== w || canvas.height !== h) {
      canvas.width = w; canvas.height = h;
    }
    return { dpr, w: rect.width, h: rect.height };
  }
  window.BTB.fitCanvas = fitCanvas;

  /* ---------- requestAnimationFrame loop helper ---------- */
  function loop(fn) {
    let raf, last = performance.now(), running = true;
    function frame(now) {
      if (!running) return;
      const dt = Math.min((now - last) / 1000, 0.05);
      last = now;
      fn(dt, now / 1000);
      raf = requestAnimationFrame(frame);
    }
    raf = requestAnimationFrame(frame);
    return {
      stop() { running = false; cancelAnimationFrame(raf); },
      start() { if (!running) { running = true; last = performance.now(); raf = requestAnimationFrame(frame); } }
    };
  }
  window.BTB.loop = loop;

  /* Only run a loop while its canvas is on-screen (saves battery) */
  function onScreenLoop(el, fn) {
    const ctrl = loop(fn);
    if (!('IntersectionObserver' in window)) return ctrl;
    const io = new IntersectionObserver((es) => {
      es.forEach(e => e.isIntersecting ? ctrl.start() : ctrl.stop());
    }, { threshold: 0.01 });
    io.observe(el);
    return ctrl;
  }
  window.BTB.onScreenLoop = onScreenLoop;

  /* ============================================================
     SITE CHROME — nav, year, scroll bar, reveals, cursor
     ============================================================ */
  document.addEventListener('DOMContentLoaded', function () {

    /* footer / inline year stamps */
    document.querySelectorAll('[data-year]').forEach(el => { el.textContent = new Date().getFullYear(); });

    /* mobile nav */
    const toggle = document.querySelector('.nav-toggle');
    const nav = document.querySelector('.site-nav');
    if (toggle && nav) {
      toggle.addEventListener('click', () => {
        const open = nav.classList.toggle('open');
        toggle.setAttribute('aria-expanded', String(open));
      });
      nav.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
        nav.classList.remove('open'); toggle.setAttribute('aria-expanded', 'false');
      }));
    }

    /* scroll progress bar */
    const bar = document.querySelector('.scroll-progress');
    if (bar) {
      let ticking = false;
      const update = () => {
        const h = document.documentElement;
        const max = h.scrollHeight - h.clientHeight;
        const p = max > 0 ? (h.scrollTop || window.scrollY) / max : 0;
        bar.style.width = (p * 100).toFixed(2) + '%';
        ticking = false;
      };
      window.addEventListener('scroll', () => {
        if (!ticking) { ticking = true; requestAnimationFrame(update); }
      }, { passive: true });
      update();
    }

    /* reveal on scroll */
    const reveals = document.querySelectorAll('.reveal, .kinetic');
    if ('IntersectionObserver' in window && !REDUCED) {
      const io = new IntersectionObserver((entries) => {
        entries.forEach(e => {
          if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
        });
      }, { threshold: 0.18, rootMargin: '0px 0px -8% 0px' });
      reveals.forEach(el => io.observe(el));
    } else {
      reveals.forEach(el => el.classList.add('in'));
    }

    /* split kinetic headlines into words */
    document.querySelectorAll('.kinetic[data-kinetic]').forEach(el => {
      const words = el.textContent.trim().split(/\s+/);
      el.innerHTML = words.map((w, i) =>
        `<span class="word" style="transition-delay:${i * 55}ms">${w}</span>`).join(' ');
    });

    /* magnetic custom cursor (desktop, pointer-fine) */
    if (matchMedia('(hover:hover) and (pointer:fine)').matches && !REDUCED) {
      const glow = document.createElement('div');
      glow.className = 'cursor-glow';
      document.body.appendChild(glow);
      let tx = innerWidth / 2, ty = innerHeight / 2, cx = tx, cy = ty;
      window.addEventListener('mousemove', (e) => { tx = e.clientX; ty = e.clientY; });
      const hot = 'a, button, .demo, input, .chip, .key';
      document.addEventListener('mouseover', (e) => {
        glow.classList.toggle('hot', !!e.target.closest(hot));
      });
      loop(() => {
        cx = lerp(cx, tx, 0.22); cy = lerp(cy, ty, 0.22);
        glow.style.transform = `translate(${cx}px,${cy}px) translate(-50%,-50%)`;
      });
    }
  });

  /* ============================================================
     HERO FIELD — flow-field particle background (canvas 2D)
     Cursor-reactive: particles bend toward the pointer.
     ============================================================ */
  window.BTB.heroField = function (canvas) {
    const ctx = canvas.getContext('2d');
    const noise = makeNoise(0x20260625);
    let W, H, dpr, particles = [], mouse = { x: -999, y: -999, active: false };
    const COUNT = REDUCED ? 0 : 260;

    function reset() {
      const f = fitCanvas(canvas); W = f.w; H = f.h; dpr = f.dpr;
      particles = [];
      const r = rng(7);
      for (let i = 0; i < COUNT; i++) {
        particles.push({ x: r() * W, y: r() * H, px: 0, py: 0, life: r() * 100, hue: r() });
      }
    }
    reset();
    window.addEventListener('resize', reset);
    canvas.parentElement.addEventListener('mousemove', e => {
      const r = canvas.getBoundingClientRect();
      mouse.x = e.clientX - r.left; mouse.y = e.clientY - r.top; mouse.active = true;
    });
    canvas.parentElement.addEventListener('mouseleave', () => mouse.active = false);

    /* paint a static frame for reduced-motion */
    if (REDUCED) {
      ctx.save(); ctx.scale(dpr, dpr);
      const g = ctx.createLinearGradient(0, 0, W, H);
      g.addColorStop(0, '#0c1030'); g.addColorStop(1, '#1a0c2e');
      ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
      ctx.strokeStyle = 'rgba(54,230,255,.25)';
      for (let i = 0; i < 40; i++) {
        ctx.beginPath();
        for (let x = 0; x <= W; x += 8) {
          const y = H * 0.5 + Math.sin(x * 0.01 + i) * (i * 3) + noise(x * 0.01, i) * 30 - 15;
          x === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
        }
        ctx.globalAlpha = 0.08; ctx.stroke();
      }
      ctx.restore();
      return;
    }

    const palette = ['#36e6ff', '#8a6bff', '#ff3ea5', '#c6ff3a'];
    ctx.scale(dpr, dpr);
    onScreenLoop(canvas, (dt, t) => {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.fillStyle = 'rgba(7,6,13,0.10)';
      ctx.fillRect(0, 0, W, H);
      const scale = 0.0022;
      for (const p of particles) {
        p.px = p.x; p.py = p.y;
        let ang = noise(p.x * scale, p.y * scale + t * 0.05) * TAU * 2.2;
        let vx = Math.cos(ang), vy = Math.sin(ang);
        if (mouse.active) {
          const dx = mouse.x - p.x, dy = mouse.y - p.y;
          const d2 = dx * dx + dy * dy;
          if (d2 < 26000) {
            const f = (1 - d2 / 26000) * 1.6;
            vx += (dx / Math.sqrt(d2 + 1)) * f;
            vy += (dy / Math.sqrt(d2 + 1)) * f;
          }
        }
        const sp = 1.1;
        p.x += vx * sp; p.y += vy * sp; p.life -= dt * 6;
        if (p.x < 0 || p.x > W || p.y < 0 || p.y > H || p.life < 0) {
          const r = Math.random();
          p.x = r * W; p.y = Math.random() * H; p.px = p.x; p.py = p.y; p.life = 60 + Math.random() * 60;
        }
        const col = palette[(p.hue * palette.length) | 0];
        ctx.strokeStyle = col; ctx.globalAlpha = 0.5; ctx.lineWidth = 1.1;
        ctx.beginPath(); ctx.moveTo(p.px, p.py); ctx.lineTo(p.x, p.y); ctx.stroke();
      }
      ctx.globalAlpha = 1;
    });
  };

  /* ============================================================
     CTA FIELD — soft drifting gradient mesh of dots
     ============================================================ */
  window.BTB.ctaField = function (canvas) {
    const ctx = canvas.getContext('2d');
    const noise = makeNoise(99);
    let W, H, dpr;
    function reset() { const f = fitCanvas(canvas); W = f.w; H = f.h; dpr = f.dpr; }
    reset(); window.addEventListener('resize', reset);
    const cols = 28, rows = 16;
    function draw(t) {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, W, H);
      for (let i = 0; i <= cols; i++) {
        for (let j = 0; j <= rows; j++) {
          const bx = (i / cols) * W, by = (j / rows) * H;
          const n = noise(i * 0.18 + t * 0.12, j * 0.18);
          const x = bx + Math.cos(n * TAU) * 16;
          const y = by + Math.sin(n * TAU) * 16;
          const r = 1 + n * 2.4;
          ctx.beginPath(); ctx.arc(x, y, r, 0, TAU);
          ctx.fillStyle = `hsla(${180 + n * 140}, 90%, 65%, ${0.18 + n * 0.3})`;
          ctx.fill();
        }
      }
    }
    if (REDUCED) { draw(0); return; }
    onScreenLoop(canvas, (dt, t) => draw(t));
  };

  /* boot the hero + cta on whichever page they exist */
  document.addEventListener('DOMContentLoaded', function () {
    const hero = document.getElementById('hero-canvas');
    if (hero) window.BTB.heroField(hero);
    const cta = document.getElementById('cta-canvas');
    if (cta) window.BTB.ctaField(cta);
  });
})();
