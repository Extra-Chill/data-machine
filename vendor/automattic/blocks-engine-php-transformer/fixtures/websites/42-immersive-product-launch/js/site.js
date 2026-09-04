/* =========================================================
   VOLTA — shared site behaviour
   Header, mobile nav, active nav, reveals, count-ups,
   magnetic buttons, scroll progress bar, intro loader.
   Runs on every page.
   ========================================================= */
'use strict';

const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
if (REDUCED) document.documentElement.classList.add('reduced');

/* ── Intro loader ───────────────────────────────────────── */
function initLoader() {
  const loader = document.querySelector('.loader');
  if (!loader) return;

  // Reveal the wordmark letters
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
    p += Math.max(0.6, (100 - p) * 0.06);
    if (p >= 100) p = 100;
    if (bar) bar.style.width = p + '%';
    if (pct) pct.textContent = String(Math.floor(p)).padStart(3, '0');
    if (p < 100) {
      requestAnimationFrame(tick);
    } else {
      setTimeout(() => {
        loader.classList.add('done');
        document.body.style.overflow = '';
        document.dispatchEvent(new CustomEvent('volta:loaded'));
      }, 380);
    }
  };
  requestAnimationFrame(tick);
}

/* ── Sticky header transform ────────────────────────────── */
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
    const pct = max > 0 ? (h.scrollTop / max) * 100 : 0;
    bar.style.width = pct + '%';
    ticking = false;
  };
  window.addEventListener('scroll', () => {
    if (!ticking) { requestAnimationFrame(update); ticking = true; }
  }, { passive: true });
  update();
}

/* ── IntersectionObserver reveals + char splits ─────────── */
function initReveal() {
  // split text into chars for headings flagged .split
  document.querySelectorAll('.split[data-split]').forEach(el => {
    const text = el.textContent;
    el.textContent = '';
    [...text].forEach((ch, i) => {
      const span = document.createElement('span');
      span.className = 'char';
      span.textContent = ch === ' ' ? ' ' : ch;
      span.style.transitionDelay = (i * 0.022) + 's';
      el.appendChild(span);
    });
  });

  const els = document.querySelectorAll('.reveal, .split');
  if (!els.length) return;
  if (REDUCED) { els.forEach(e => e.classList.add('visible')); return; }

  const obs = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
  els.forEach(el => obs.observe(el));
}

/* ── Animated count-ups ─────────────────────────────────── */
function initCounters() {
  const counters = document.querySelectorAll('[data-count]');
  if (!counters.length) return;

  const run = (el) => {
    const end = parseFloat(el.dataset.count);
    const dec = parseInt(el.dataset.decimals || '0', 10);
    const dur = 1700;
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

/* ── Magnetic buttons + parallax pull ───────────────────── */
function initMagnetic() {
  if (REDUCED) return;
  document.querySelectorAll('.magnetic').forEach(el => {
    const strength = parseFloat(el.dataset.strength || '0.35');
    el.addEventListener('mousemove', (e) => {
      const r = el.getBoundingClientRect();
      const x = (e.clientX - (r.left + r.width / 2)) * strength;
      const y = (e.clientY - (r.top + r.height / 2)) * strength;
      el.style.transform = `translate(${x}px, ${y}px)`;
    });
    el.addEventListener('mouseleave', () => { el.style.transform = 'translate(0,0)'; });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initLoader();
  initHeader();
  initMobileNav();
  initActiveNav();
  initScrollProgress();
  initReveal();
  initCounters();
  initMagnetic();
});
