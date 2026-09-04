/* ============================================================
   MARA VALE — main.js
   ============================================================ */

(function () {
  'use strict';

  /* ── Mobile nav toggle ─────────────────────────────────── */
  const toggle    = document.querySelector('.nav-toggle');
  const mobileNav = document.querySelector('.nav-mobile');

  if (toggle && mobileNav) {
    toggle.addEventListener('click', () => {
      const isOpen = toggle.classList.toggle('open');
      mobileNav.classList.toggle('open', isOpen);
      document.body.style.overflow = isOpen ? 'hidden' : '';
    });

    mobileNav.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        toggle.classList.remove('open');
        mobileNav.classList.remove('open');
        document.body.style.overflow = '';
      });
    });
  }

  /* ── Active nav link ────────────────────────────────────── */
  const page = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-links a, .nav-mobile a').forEach(link => {
    if (link.getAttribute('href') === page) link.classList.add('active');
  });

  /* ── Scroll reveal (IntersectionObserver) ──────────────── */
  const observer = new IntersectionObserver(
    entries => entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      }
    }),
    { threshold: 0.08, rootMargin: '0px 0px -50px 0px' }
  );

  document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

  /* ── Newsletter form(s) ─────────────────────────────────── */
  document.querySelectorAll('.newsletter-form').forEach(form => {
    form.addEventListener('submit', e => {
      e.preventDefault();
      const input = form.querySelector('input[type="email"]');
      const btn   = form.querySelector('button');
      if (!input || !input.value.trim()) return;

      const original = btn.textContent;
      btn.textContent = 'You\'re on the list';
      btn.style.background = '#3d6b34';
      btn.style.borderColor = '#3d6b34';
      input.value = '';
      input.placeholder = 'See you at the show ✦';

      setTimeout(() => {
        btn.textContent = original;
        btn.style.background = '';
        btn.style.borderColor = '';
        input.placeholder = 'your@email.com';
      }, 5000);
    });
  });

  /* ── Merch quantity buttons ─────────────────────────────── */
  document.querySelectorAll('.qty-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const display = btn.parentElement.querySelector('.qty-display');
      if (!display) return;
      let val = parseInt(display.textContent, 10) || 1;
      if (btn.dataset.dir === 'up')   val = Math.min(val + 1, 10);
      if (btn.dataset.dir === 'down') val = Math.max(val - 1, 1);
      display.textContent = val;
    });
  });

  /* ── Add-to-cart feedback ───────────────────────────────── */
  document.querySelectorAll('.add-to-cart').forEach(btn => {
    btn.addEventListener('click', () => {
      const orig = btn.textContent;
      btn.textContent = 'Added';
      btn.style.background = 'var(--ember)';
      btn.style.color = 'var(--bone)';
      setTimeout(() => {
        btn.textContent = orig;
        btn.style.background = '';
        btn.style.color = '';
      }, 2500);
    });
  });

})();
