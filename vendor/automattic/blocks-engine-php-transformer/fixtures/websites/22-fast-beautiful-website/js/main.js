/* =========================================================
   MOTH & MERIDIAN — Main JavaScript
   ========================================================= */
'use strict';

/* ── Custom Cursor ──────────────────────────────────────── */
function initCursor() {
  if (window.innerWidth <= 768) return;

  const cursor = document.createElement('div');
  cursor.className = 'cursor';
  const ring = document.createElement('div');
  ring.className = 'cursor-ring';
  document.body.append(cursor, ring);

  let cx = window.innerWidth / 2;
  let cy = window.innerHeight / 2;
  let rx = cx, ry = cy;

  document.addEventListener('mousemove', e => {
    cx = e.clientX;
    cy = e.clientY;
    cursor.style.left = cx + 'px';
    cursor.style.top  = cy + 'px';
  });

  (function lerp() {
    rx += (cx - rx) * 0.1;
    ry += (cy - ry) * 0.1;
    ring.style.left = rx + 'px';
    ring.style.top  = ry + 'px';
    requestAnimationFrame(lerp);
  })();

  document.querySelectorAll('a, button, .fragrance-card, .journal-card, .fragrance-full-card, .stockist-card, .founder-card').forEach(el => {
    el.addEventListener('mouseenter', () => document.body.classList.add('cursor-hover'));
    el.addEventListener('mouseleave', () => document.body.classList.remove('cursor-hover'));
  });
}

/* ── Sticky Header ──────────────────────────────────────── */
function initHeader() {
  const header = document.querySelector('.site-header');
  if (!header) return;
  const onScroll = () => header.classList.toggle('scrolled', window.scrollY > 55);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
}

/* ── Mobile Nav ─────────────────────────────────────────── */
function initMobileNav() {
  const toggle = document.querySelector('.nav-toggle');
  const nav    = document.querySelector('.site-nav');
  if (!toggle || !nav) return;

  const open  = () => { toggle.classList.add('active');    nav.classList.add('open');    document.body.style.overflow = 'hidden'; };
  const close = () => { toggle.classList.remove('active'); nav.classList.remove('open'); document.body.style.overflow = ''; };

  toggle.addEventListener('click', () => nav.classList.contains('open') ? close() : open());
  nav.querySelectorAll('a').forEach(a => a.addEventListener('click', close));
  document.addEventListener('click', e => {
    if (nav.classList.contains('open') && !nav.contains(e.target) && !toggle.contains(e.target)) close();
  });
}

/* ── Scroll Reveal ──────────────────────────────────────── */
function initReveal() {
  const els = document.querySelectorAll('.reveal');
  if (!els.length) return;

  const obs = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.classList.add('visible');
        obs.unobserve(e.target);
      }
    });
  }, { threshold: 0.08, rootMargin: '0px 0px -44px 0px' });

  els.forEach(el => obs.observe(el));
}

/* ── Active Nav ─────────────────────────────────────────── */
function initActiveNav() {
  const path = window.location.pathname;
  document.querySelectorAll('.site-nav a').forEach(a => {
    const href = a.getAttribute('href') || '';
    const fname = href.split('/').pop();
    const pname = path.split('/').pop() || 'index.html';
    if (fname === pname) a.classList.add('active');
    if ((fname === 'index.html' || href === './' || href === '') && (pname === '' || pname === 'index.html')) a.classList.add('active');
  });
}

/* ── Contact Form ───────────────────────────────────────── */
function initContactForm() {
  const form = document.querySelector('.contact-form');
  if (!form) return;
  form.addEventListener('submit', e => {
    e.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    const orig = btn.textContent;
    btn.textContent = 'Message received — thank you';
    btn.disabled = true;
    setTimeout(() => { btn.textContent = orig; btn.disabled = false; form.reset(); }, 4500);
  });
}

/* ── Staggered Number Counter (for stats) ───────────────── */
function initCounters() {
  const counters = document.querySelectorAll('[data-count]');
  if (!counters.length) return;
  const obs = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      const el  = e.target;
      const end = parseInt(el.dataset.count, 10);
      const dur = 1600;
      const step = end / (dur / 16);
      let cur = 0;
      const tick = () => {
        cur = Math.min(cur + step, end);
        el.textContent = Math.floor(cur);
        if (cur < end) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
      obs.unobserve(el);
    });
  }, { threshold: 0.5 });
  counters.forEach(el => obs.observe(el));
}

/* ── Collection Filter (collection page) ───────────────── */
function initFilter() {
  const btns = document.querySelectorAll('.filter-btn');
  const cards = document.querySelectorAll('.fragrance-full-card');
  if (!btns.length) return;

  btns.forEach(btn => {
    btn.addEventListener('click', () => {
      btns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const family = btn.dataset.filter;
      cards.forEach(card => {
        if (family === 'all' || card.dataset.family === family) {
          card.style.display = '';
          card.style.opacity = '1';
        } else {
          card.style.opacity = '0';
          setTimeout(() => { card.style.display = card.style.opacity === '0' ? 'none' : ''; }, 300);
        }
      });
    });
  });
}

/* ── Init ────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initCursor();
  initHeader();
  initMobileNav();
  initReveal();
  initActiveNav();
  initContactForm();
  initCounters();
  initFilter();
});
