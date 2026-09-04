/* =========================================================
   BETON GALERIE — Main JavaScript
   ========================================================= */
'use strict';

/* ── Mobile Nav ─────────────────────────────────────────── */
function initMobileNav() {
  const toggle = document.querySelector('.nav-toggle');
  const nav    = document.querySelector('.site-nav');
  if (!toggle || !nav) return;

  const open  = () => { toggle.classList.add('active');    nav.classList.add('open');    toggle.setAttribute('aria-expanded', 'true');  };
  const close = () => { toggle.classList.remove('active'); nav.classList.remove('open'); toggle.setAttribute('aria-expanded', 'false'); };

  toggle.addEventListener('click', () => nav.classList.contains('open') ? close() : open());
  nav.querySelectorAll('a').forEach(a => a.addEventListener('click', close));
  document.addEventListener('click', e => {
    if (nav.classList.contains('open') && !nav.contains(e.target) && !toggle.contains(e.target)) close();
  });
}

/* ── Active Nav ─────────────────────────────────────────── */
function initActiveNav() {
  const path  = window.location.pathname;
  const pname = path.split('/').pop() || 'index.html';
  document.querySelectorAll('.site-nav a').forEach(a => {
    const href  = a.getAttribute('href') || '';
    const fname = href.split('/').pop();
    if (fname === pname) a.classList.add('active');
    if ((fname === 'index.html' || href === './' || href === '') &&
        (pname === '' || pname === 'index.html')) a.classList.add('active');
    // current-exhibition.html should also light "Current"
    if (pname === 'current-exhibition.html' && fname === 'current-exhibition.html') a.classList.add('active');
  });
}

/* ── Language toggle (visual only) ──────────────────────── */
function initLangToggle() {
  document.querySelectorAll('.lang-toggle button').forEach(btn => {
    btn.addEventListener('click', () => {
      const group = btn.closest('.lang-toggle');
      group.querySelectorAll('button').forEach(b => b.classList.remove('on'));
      btn.classList.add('on');
    });
  });
}

/* ── Filter chips (artists page) ───────────────────────── */
function initChips() {
  document.querySelectorAll('[data-chip-group]').forEach(group => {
    const chips = group.querySelectorAll('.chip');
    chips.forEach(chip => {
      chip.addEventListener('click', () => {
        chips.forEach(c => c.classList.remove('is-on'));
        chip.classList.add('is-on');
      });
    });
  });
}

/* ── Forms (mock submit) ────────────────────────────────── */
function initForms() {
  document.querySelectorAll('form.form').forEach(form => {
    form.addEventListener('submit', e => {
      e.preventDefault();
      const btn = form.querySelector('button[type="submit"]');
      if (!btn) return;
      const orig = btn.textContent;
      btn.textContent = 'Received — Danke';
      btn.disabled = true;
      setTimeout(() => { btn.textContent = orig; btn.disabled = false; form.reset(); }, 4500);
    });
  });

  const news = document.querySelector('.footer-newsletter form');
  if (news) {
    news.addEventListener('submit', e => {
      e.preventDefault();
      const btn = news.querySelector('button');
      if (!btn) return;
      const orig = btn.innerHTML;
      btn.textContent = 'Subscribed';
      setTimeout(() => { btn.innerHTML = orig; news.reset(); }, 3500);
    });
  }
}

/* ── Init ────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initMobileNav();
  initActiveNav();
  initLangToggle();
  initChips();
  initForms();
});
