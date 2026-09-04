/* =========================================================
   AETHELON — shared site behaviour
   Loader, sticky header, mobile + active nav, scroll progress,
   IntersectionObserver reveals + char splits, count-ups,
   magnetic buttons, and the live canvas starfield.
   Runs on every page. Honours prefers-reduced-motion.
   ========================================================= */
'use strict';

const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
if (REDUCED) document.documentElement.classList.add('reduced');

/* ── small math helpers shared by other modules ─────────── */
window.AETH = {
  clamp: (v, a, b) => Math.min(b, Math.max(a, v)),
  lerp: (a, b, t) => a + (b - a) * t,
  sub: (p, s, e) => Math.min(1, Math.max(0, (p - s) / (e - s))),
  reduced: REDUCED
};

/* =========================================================
   LIVE STARFIELD  (canvas, requestAnimationFrame)
   Twinkling + slowly drifting stars over a soft nebula.
   ========================================================= */
function initStarfield() {
  const canvas = document.querySelector('.starfield');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  let w, h, dpr, stars = [];

  function makeStars() {
    const count = Math.round((w * h) / 6500);
    stars = [];
    for (let i = 0; i < count; i++) {
      stars.push({
        x: Math.random() * w,
        y: Math.random() * h,
        z: Math.random(),                 // depth → size + drift speed
        r: Math.random() * 1.4 + 0.3,
        tw: Math.random() * Math.PI * 2,  // twinkle phase
        tws: Math.random() * 0.04 + 0.008,
        hue: Math.random() < 0.18 ? 'ice' : (Math.random() < 0.12 ? 'warm' : 'white')
      });
    }
  }

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    w = window.innerWidth;
    h = window.innerHeight;
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    makeStars();
  }

  function drawNebula() {
    // soft nebular clouds painted with radial gradients
    const clouds = [
      { x: 0.28, y: 0.32, r: 0.5, c: 'rgba(111,123,255,0.10)' },
      { x: 0.74, y: 0.66, r: 0.55, c: 'rgba(176,107,255,0.09)' },
      { x: 0.55, y: 0.18, r: 0.4, c: 'rgba(127,231,255,0.07)' }
    ];
    clouds.forEach(cl => {
      const g = ctx.createRadialGradient(cl.x * w, cl.y * h, 0, cl.x * w, cl.y * h, cl.r * Math.max(w, h));
      g.addColorStop(0, cl.c);
      g.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = g;
      ctx.fillRect(0, 0, w, h);
    });
  }

  const palette = { white: '255,255,255', ice: '127,231,255', warm: '255,212,121' };

  function frame() {
    ctx.clearRect(0, 0, w, h);
    drawNebula();
    for (const s of stars) {
      s.tw += s.tws;
      const a = 0.4 + Math.abs(Math.sin(s.tw)) * 0.6;
      // gentle drift; far stars (low z) move slower for depth
      s.x += (0.06 + s.z * 0.18) * 0.4;
      if (s.x > w + 2) s.x = -2;
      const size = s.r * (0.6 + s.z);
      ctx.beginPath();
      ctx.arc(s.x, s.y, size, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(${palette[s.hue]},${a})`;
      ctx.fill();
      // occasional glow for the brightest near stars
      if (s.z > 0.85 && s.r > 1.1) {
        ctx.beginPath();
        ctx.arc(s.x, s.y, size * 3, 0, Math.PI * 2);
        ctx.fillStyle = `rgba(${palette[s.hue]},${a * 0.08})`;
        ctx.fill();
      }
    }
    raf = requestAnimationFrame(frame);
  }

  let raf;
  resize();
  window.addEventListener('resize', () => { cancelAnimationFrame(raf); resize(); if (REDUCED) { ctx.clearRect(0,0,w,h); drawNebula(); paintStatic(); } else frame(); });

  function paintStatic() {
    for (const s of stars) {
      const size = s.r * (0.6 + s.z);
      ctx.beginPath();
      ctx.arc(s.x, s.y, size, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(${palette[s.hue]},${0.4 + s.z * 0.4})`;
      ctx.fill();
    }
  }

  if (REDUCED) {
    ctx.clearRect(0, 0, w, h);
    drawNebula();
    paintStatic();
    return;
  }
  frame();
}

/* ── Intro loader ───────────────────────────────────────── */
function initLoader() {
  const loader = document.querySelector('.loader');
  if (!loader) return;

  loader.querySelectorAll('.loader-mark span').forEach((s, i) => {
    s.style.transition = 'transform .6s cubic-bezier(.22,.61,.36,1)';
    s.style.transitionDelay = (0.06 * i) + 's';
    requestAnimationFrame(() => { s.style.transform = 'translateY(0)'; });
  });

  if (REDUCED) { loader.classList.add('done'); document.body.style.overflow = ''; return; }

  document.body.style.overflow = 'hidden';
  const bar = loader.querySelector('.loader-bar > i');
  const pct = loader.querySelector('.loader-pct');
  let p = 0;
  const tick = () => {
    p += Math.max(0.7, (100 - p) * 0.07);
    if (p >= 100) p = 100;
    if (bar) bar.style.width = p + '%';
    if (pct) pct.textContent = 'T-MINUS ' + String(Math.floor(100 - p)).padStart(3, '0');
    if (p < 100) {
      requestAnimationFrame(tick);
    } else {
      if (pct) pct.textContent = 'LIFTOFF';
      setTimeout(() => {
        loader.classList.add('done');
        document.body.style.overflow = '';
        document.dispatchEvent(new CustomEvent('aeth:loaded'));
      }, 420);
    }
  };
  requestAnimationFrame(tick);
}

/* ── Sticky header ──────────────────────────────────────── */
function initHeader() {
  const header = document.querySelector('.site-header');
  if (!header) return;
  const onScroll = () => header.classList.toggle('scrolled', window.scrollY > 40);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
}

/* ── Mobile nav ─────────────────────────────────────────── */
function initMobileNav() {
  const toggle = document.querySelector('.nav-toggle');
  const nav = document.querySelector('.site-nav');
  if (!toggle || !nav) return;
  const close = () => { toggle.classList.remove('active'); nav.classList.remove('open'); toggle.setAttribute('aria-expanded', 'false'); document.body.style.overflow = ''; };
  const open = () => { toggle.classList.add('active'); nav.classList.add('open'); toggle.setAttribute('aria-expanded', 'true'); document.body.style.overflow = 'hidden'; };
  toggle.addEventListener('click', () => nav.classList.contains('open') ? close() : open());
  nav.querySelectorAll('a').forEach(a => a.addEventListener('click', close));
}

/* ── Active nav (path-based, file:// safe) ──────────────── */
function initActiveNav() {
  const page = (location.pathname.split('/').pop() || 'index.html');
  document.querySelectorAll('.site-nav a').forEach(a => {
    const href = (a.getAttribute('href') || '').split('/').pop();
    if (href === page || ((page === '' || page === 'index.html') && href === 'index.html')) {
      a.classList.add('active');
    }
  });
}

/* ── Top scroll progress bar ────────────────────────────── */
function initScrollProgress() {
  const bar = document.querySelector('.scroll-progress');
  if (!bar) return;
  let ticking = false;
  const update = () => {
    const h = document.documentElement;
    const max = h.scrollHeight - h.clientHeight;
    bar.style.width = (max > 0 ? (h.scrollTop / max) * 100 : 0) + '%';
    ticking = false;
  };
  window.addEventListener('scroll', () => { if (!ticking) { requestAnimationFrame(update); ticking = true; } }, { passive: true });
  update();
}

/* ── Reveals + char splits ──────────────────────────────── */
function initReveal() {
  document.querySelectorAll('.split[data-split]').forEach(el => {
    const text = el.textContent;
    el.textContent = '';
    [...text].forEach((ch, i) => {
      const span = document.createElement('span');
      span.className = 'char';
      span.textContent = ch === ' ' ? ' ' : ch;
      span.style.transitionDelay = (i * 0.02) + 's';
      el.appendChild(span);
    });
  });

  const els = document.querySelectorAll('.reveal, .split');
  if (!els.length) return;
  if (REDUCED) { els.forEach(e => e.classList.add('visible')); return; }

  const obs = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
  }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
  els.forEach(el => obs.observe(el));
}

/* ── Count-ups ──────────────────────────────────────────── */
function initCounters() {
  const counters = document.querySelectorAll('[data-count]');
  if (!counters.length) return;

  const run = (el) => {
    const end = parseFloat(el.dataset.count);
    const dec = parseInt(el.dataset.decimals || '0', 10);
    const dur = 1800;
    const start = performance.now();
    const ease = t => 1 - Math.pow(1 - t, 3);
    const frame = (now) => {
      const t = Math.min((now - start) / dur, 1);
      const val = end * ease(t);
      el.textContent = val.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
      if (t < 1) requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
  };

  if (REDUCED) {
    counters.forEach(el => {
      const dec = parseInt(el.dataset.decimals || '0', 10);
      el.textContent = parseFloat(el.dataset.count).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    });
    return;
  }
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { run(e.target); obs.unobserve(e.target); } });
  }, { threshold: 0.6 });
  counters.forEach(el => obs.observe(el));
}

/* ── Magnetic buttons ───────────────────────────────────── */
function initMagnetic() {
  if (REDUCED) return;
  document.querySelectorAll('.magnetic').forEach(el => {
    const strength = parseFloat(el.dataset.strength || '0.3');
    el.addEventListener('mousemove', (e) => {
      const r = el.getBoundingClientRect();
      const x = (e.clientX - (r.left + r.width / 2)) * strength;
      const y = (e.clientY - (r.top + r.height / 2)) * strength;
      el.style.transform = `translate(${x}px, ${y}px)`;
    });
    el.addEventListener('mouseleave', () => { el.style.transform = 'translate(0,0)'; });
  });
}

/* ── Mini bar chart (data.html) ─────────────────────────── */
function initBarChart() {
  const chart = document.querySelector('.chart');
  if (!chart) return;
  const fills = chart.querySelectorAll('.fill');
  const paint = () => fills.forEach(f => { f.style.height = (f.dataset.h || 0) + '%'; });
  if (REDUCED) { paint(); return; }
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { paint(); obs.disconnect(); } });
  }, { threshold: 0.4 });
  obs.observe(chart);
}

document.addEventListener('DOMContentLoaded', () => {
  initStarfield();
  initLoader();
  initHeader();
  initMobileNav();
  initActiveNav();
  initScrollProgress();
  initReveal();
  initCounters();
  initMagnetic();
  initBarChart();
});
