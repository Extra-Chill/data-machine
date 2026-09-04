/* =========================================================
   BRIDGES OF LIGHT FOUNDATION — main.js
   Trilingual site (en / ar / ja). Minimal client-side JS:
   - sticky header subtle shadow
   - mobile nav toggle
   - newsletter / contact form mock submit
   - language-aware document.dir sync (defensive)
   ========================================================= */
'use strict';

/* -- Sync dir attribute from html lang (defensive) -------- */
function initDirSync() {
  const root = document.documentElement;
  const lang = (root.getAttribute('lang') || 'en').toLowerCase();
  // Only set dir if it isn't already explicitly authored.
  if (!root.hasAttribute('dir')) {
    root.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
  }
}

/* -- Sticky header subtle shadow on scroll --------------- */
function initHeader() {
  const header = document.querySelector('.site-header');
  if (!header) return;
  const onScroll = () => {
    header.classList.toggle('scrolled', window.scrollY > 24);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
}

/* -- Mobile nav toggle ----------------------------------- */
function initMobileNav() {
  const toggle = document.querySelector('.nav-toggle');
  const nav = document.querySelector('.primary-nav');
  if (!toggle || !nav) return;

  toggle.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
}

/* -- Newsletter mock submit ------------------------------ */
function initNewsletter() {
  const forms = document.querySelectorAll('form.newsletter-form');
  forms.forEach(form => {
    form.addEventListener('submit', e => {
      e.preventDefault();
      const btn = form.querySelector('button[type="submit"]');
      if (!btn) return;
      const original = btn.textContent;
      btn.disabled = true;
      btn.textContent = btn.dataset.success || 'Thank you';
      setTimeout(() => {
        btn.disabled = false;
        btn.textContent = original;
        form.reset();
      }, 3800);
    });
  });
}

/* -- Contact form mock submit --------------------------- */
function initContact() {
  const form = document.querySelector('form.contact-form');
  if (!form) return;
  form.addEventListener('submit', e => {
    e.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    if (!btn) return;
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Sent — we will respond within 3 business days';
    setTimeout(() => {
      btn.disabled = false;
      btn.textContent = original;
      form.reset();
    }, 5000);
  });
}

/* -- Smooth scroll for in-page anchors -------------------- */
function initSmoothScroll() {
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
      const id = a.getAttribute('href').slice(1);
      if (!id) return;
      const target = document.getElementById(id);
      if (!target) return;
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initDirSync();
  initHeader();
  initMobileNav();
  initNewsletter();
  initContact();
  initSmoothScroll();
});
