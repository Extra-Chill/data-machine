/* ============================================================
   HERON & OAK — Main JavaScript
   Mobile nav, scroll reveals, filter chips, accordion, forms
   ============================================================ */

(function () {
  'use strict';

  /* ── Mobile Navigation ───────────────────────────────────── */
  const header  = document.getElementById('site-header');
  const toggle  = document.getElementById('navToggle');
  const navList = document.getElementById('navLinks');

  if (toggle && navList) {
    toggle.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = navList.classList.toggle('open');
      toggle.classList.toggle('open', isOpen);
      toggle.setAttribute('aria-expanded', isOpen);
    });

    navList.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        navList.classList.remove('open');
        toggle.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });

    document.addEventListener('click', (e) => {
      if (header && !header.contains(e.target)) {
        navList.classList.remove('open');
        toggle.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* ── Header scroll shadow ────────────────────────────────── */
  if (header) {
    const onScroll = () => {
      header.classList.toggle('scrolled', window.scrollY > 16);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ── Active nav link ─────────────────────────────────────── */
  const currentPath = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav__link').forEach(link => {
    const href = link.getAttribute('href');
    if (href === currentPath || (currentPath === '' && href === 'index.html')) {
      link.classList.add('active');
    }
  });

  /* ── Scroll reveal ───────────────────────────────────────── */
  const revealEls = document.querySelectorAll('.reveal, .reveal-stagger');

  if (revealEls.length > 0 && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          io.unobserve(entry.target);
        }
      });
    }, {
      rootMargin: '0px 0px -60px 0px',
      threshold: 0.08
    });
    revealEls.forEach(el => io.observe(el));
  }

  /* ── Filter chips (listings page — visual toggle only) ──── */
  document.querySelectorAll('.filter-bar').forEach(bar => {
    bar.querySelectorAll('.chip').forEach(chip => {
      chip.addEventListener('click', () => {
        // Toggle active state within its group (label-anchored)
        const group = chip.dataset.group;
        if (group) {
          bar.querySelectorAll(`.chip[data-group="${group}"]`).forEach(c => c.classList.remove('active'));
        }
        chip.classList.toggle('active');
      });
    });
  });

  /* ── FAQ accordion ───────────────────────────────────────── */
  document.querySelectorAll('.faq__q').forEach(btn => {
    btn.addEventListener('click', () => {
      const item = btn.closest('.faq__item');
      const wasOpen = item.classList.contains('open');
      item.parentElement.querySelectorAll('.faq__item').forEach(i => i.classList.remove('open'));
      if (!wasOpen) item.classList.add('open');
    });
  });

  /* ── Form handling (any .js-form) ────────────────────────── */
  document.querySelectorAll('.js-form').forEach(form => {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const btn = form.querySelector('[type="submit"]');
      const notice = form.querySelector('.form-notice');

      let valid = true;
      form.querySelectorAll('[required]').forEach(field => {
        if (!field.value.trim()) {
          valid = false;
          field.style.borderColor = 'var(--brass)';
          field.addEventListener('input', () => { field.style.borderColor = ''; }, { once: true });
        }
      });
      if (!valid) return;

      const originalText = btn.textContent;
      btn.textContent = 'Sending…';
      btn.disabled = true;

      setTimeout(() => {
        btn.textContent = originalText;
        btn.disabled = false;
        form.reset();
        if (notice) {
          notice.style.display = 'block';
          notice.classList.add('form-notice--success');
          notice.textContent = 'Message received. A broker will be in touch within one business day.';
          notice.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      }, 1100);
    });
  });

  /* ── Smooth anchor scroll ────────────────────────────────── */
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', (e) => {
      const id = a.getAttribute('href').slice(1);
      if (!id) return;
      const target = document.getElementById(id);
      if (target) {
        e.preventDefault();
        const offset = (header ? header.offsetHeight : 76) + 16;
        const top = target.getBoundingClientRect().top + window.scrollY - offset;
        window.scrollTo({ top, behavior: 'smooth' });
      }
    });
  });

})();
