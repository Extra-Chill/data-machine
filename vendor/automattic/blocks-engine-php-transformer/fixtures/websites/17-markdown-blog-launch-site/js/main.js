/* =====================================================
   NORTHSTAR PANTRY — Main JavaScript
   ===================================================== */

(function () {
  'use strict';

  /* ================================================
     Mobile Navigation
  ================================================= */
  const navToggle  = document.querySelector('.nav-toggle');
  const mobileMenu = document.querySelector('.mobile-menu');

  if (navToggle && mobileMenu) {
    const openMenu = () => {
      navToggle.classList.add('is-open');
      mobileMenu.classList.add('is-open');
      document.body.style.overflow = 'hidden';
      navToggle.setAttribute('aria-expanded', 'true');
      // Trap focus inside mobile menu
      mobileMenu.querySelector('a')?.focus();
    };

    const closeMenu = () => {
      navToggle.classList.remove('is-open');
      mobileMenu.classList.remove('is-open');
      document.body.style.overflow = '';
      navToggle.setAttribute('aria-expanded', 'false');
    };

    navToggle.addEventListener('click', () => {
      navToggle.classList.contains('is-open') ? closeMenu() : openMenu();
    });

    // Close on link click
    mobileMenu.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', closeMenu);
    });

    // Close on Escape
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && mobileMenu.classList.contains('is-open')) closeMenu();
    });

    // Close on overlay click (if clicking outside the nav content)
    mobileMenu.addEventListener('click', e => {
      if (e.target === mobileMenu) closeMenu();
    });
  }

  /* ================================================
     Scroll Reveal
  ================================================= */
  const revealEls = document.querySelectorAll(
    '.reveal, .reveal-left, .reveal-right, .reveal-scale'
  );

  if (revealEls.length) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        const el    = entry.target;
        const delay = parseInt(el.dataset.delay || '0', 10);
        setTimeout(() => el.classList.add('is-visible'), delay);
        observer.unobserve(el);
      });
    }, { threshold: 0.1, rootMargin: '0px 0px -36px 0px' });

    revealEls.forEach(el => observer.observe(el));
  }

  /* ================================================
     Active Nav Link
  ================================================= */
  const currentFile = window.location.pathname.split('/').pop() || 'index.html';

  document.querySelectorAll('.nav-links a, .mobile-menu__links a').forEach(link => {
    const href = link.getAttribute('href');
    if (!href) return;
    const hrefFile = href.split('/').pop();
    if (hrefFile === currentFile ||
       (currentFile === '' && hrefFile === 'index.html')) {
      link.classList.add('active');
    }
  });

  /* ================================================
     Newsletter Forms
  ================================================= */
  document.querySelectorAll('.newsletter-form').forEach(form => {
    form.addEventListener('submit', e => {
      e.preventDefault();
      const input   = form.querySelector('input[type="email"]');
      const btn     = form.querySelector('button[type="submit"]');
      const success = form.nextElementSibling;

      if (!input?.value) return;

      const orig = btn.textContent;
      btn.textContent = 'Subscribed!';
      btn.disabled    = true;
      input.value     = '';

      if (success && success.classList.contains('newsletter-success')) {
        success.classList.add('show');
      }

      setTimeout(() => {
        btn.textContent = orig;
        btn.disabled    = false;
        if (success) success.classList.remove('show');
      }, 4000);
    });
  });

  /* ================================================
     Header shadow on scroll
  ================================================= */
  const header = document.querySelector('.site-header');
  if (header) {
    const updateHeader = () => {
      header.style.boxShadow = window.scrollY > 60
        ? '0 2px 24px rgba(44,31,18,0.10)'
        : 'none';
    };
    window.addEventListener('scroll', updateHeader, { passive: true });
    updateHeader();
  }

  /* ================================================
     Pricing toggle (monthly / annual)
  ================================================= */
  const pricingBtns   = document.querySelectorAll('[data-billing]');
  const annualPrices  = document.querySelectorAll('[data-price-annual]');
  const monthlyPrices = document.querySelectorAll('[data-price-monthly]');

  if (pricingBtns.length) {
    const setMode = mode => {
      pricingBtns.forEach(b => b.classList.toggle('active', b.dataset.billing === mode));
      annualPrices .forEach(el => el.style.display = mode === 'annual'  ? '' : 'none');
      monthlyPrices.forEach(el => el.style.display = mode === 'monthly' ? '' : 'none');
    };

    pricingBtns.forEach(btn => {
      btn.addEventListener('click', () => setMode(btn.dataset.billing));
    });

    // Default: annual
    setMode('annual');
  }

  /* ================================================
     Smooth FAQ accordion (if present)
  ================================================= */
  document.querySelectorAll('.faq-item').forEach(item => {
    const trigger = item.querySelector('.faq-trigger');
    const body    = item.querySelector('.faq-body');
    if (!trigger || !body) return;

    trigger.addEventListener('click', () => {
      const isOpen = item.classList.toggle('is-open');
      trigger.setAttribute('aria-expanded', isOpen);
      if (isOpen) {
        body.style.maxHeight = body.scrollHeight + 'px';
      } else {
        body.style.maxHeight = '0';
      }
    });
  });

})();
