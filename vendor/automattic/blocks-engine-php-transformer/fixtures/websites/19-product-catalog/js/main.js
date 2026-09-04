/* ============================================================
   HOLLOW & GRAIN — Main JavaScript
   Mobile nav, scroll reveals, category filter, forms
   ============================================================ */

(function () {
  'use strict';

  /* ── Mobile Navigation ───────────────────────────────────── */
  const header  = document.getElementById('site-header');
  const toggle  = document.getElementById('navToggle');
  const navList = document.getElementById('navLinks');

  if (toggle && navList) {
    toggle.addEventListener('click', () => {
      const isOpen = navList.classList.toggle('open');
      toggle.classList.toggle('open', isOpen);
      toggle.setAttribute('aria-expanded', isOpen);
    });

    // Close on link click (mobile)
    navList.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        navList.classList.remove('open');
        toggle.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
      if (!header.contains(e.target)) {
        navList.classList.remove('open');
        toggle.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* ── Header scroll shadow ────────────────────────────────── */
  if (header) {
    const onScroll = () => {
      header.classList.toggle('scrolled', window.scrollY > 20);
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

  /* ── Scroll Reveal ───────────────────────────────────────── */
  const revealEls = document.querySelectorAll('.reveal, .reveal-stagger');

  if (revealEls.length > 0) {
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

  /* ── Category Filter ─────────────────────────────────────── */
  const filterBtns = document.querySelectorAll('.filter-btn');
  const productCards = document.querySelectorAll('.product-card[data-category]');

  function applyFilter(selected) {
    filterBtns.forEach(b => {
      b.classList.toggle('active', b.dataset.filter === selected);
    });
    productCards.forEach((card, i) => {
      const match = selected === 'all' || card.dataset.category === selected;
      if (match) {
        card.classList.remove('hidden');
        card.style.transitionDelay = (i * 50) + 'ms';
      } else {
        card.classList.add('hidden');
        card.style.transitionDelay = '0ms';
      }
    });
  }

  if (filterBtns.length > 0) {
    filterBtns.forEach(btn => {
      btn.addEventListener('click', () => applyFilter(btn.dataset.filter));
    });

    // Honor ?filter= param from category links on the homepage
    const params = new URLSearchParams(window.location.search);
    const urlFilter = params.get('filter');
    if (urlFilter && [...filterBtns].some(b => b.dataset.filter === urlFilter)) {
      applyFilter(urlFilter);
    }
  }

  /* ── Inquiry Form Handling ───────────────────────────────── */
  document.querySelectorAll('.js-inquiry-form').forEach(form => {
    form.addEventListener('submit', (e) => {
      e.preventDefault();

      const btn = form.querySelector('[type="submit"]');
      const notice = form.querySelector('.form-notice');

      // Basic required-field check
      let valid = true;
      form.querySelectorAll('[required]').forEach(field => {
        if (!field.value.trim()) {
          valid = false;
          field.style.borderColor = 'var(--c-oak-warm)';
          field.addEventListener('input', () => {
            field.style.borderColor = '';
          }, { once: true });
        }
      });

      if (!valid) return;

      // Simulate send
      btn.textContent = 'Sending…';
      btn.disabled = true;

      setTimeout(() => {
        btn.textContent = 'Send Inquiry';
        btn.disabled = false;
        form.reset();
        if (notice) {
          notice.style.display = 'block';
          notice.classList.add('form-notice--success');
          notice.textContent = 'Your inquiry was sent — we\'ll be in touch within 2 business days.';
          notice.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      }, 1200);
    });
  });

  /* ── Smooth anchor scroll ────────────────────────────────── */
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', (e) => {
      const id = a.getAttribute('href').slice(1);
      const target = document.getElementById(id);
      if (target) {
        e.preventDefault();
        const offset = (header ? header.offsetHeight : 72) + 16;
        const top = target.getBoundingClientRect().top + window.scrollY - offset;
        window.scrollTo({ top, behavior: 'smooth' });
      }
    });
  });

})();
