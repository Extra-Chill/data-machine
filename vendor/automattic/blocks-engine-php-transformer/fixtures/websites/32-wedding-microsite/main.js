/* ============================================================
   MAYA + DEVON — Shared JS
   Mobile nav, scroll reveals, countdown timer, active link
============================================================ */

(function () {
  'use strict';

  /* ---- WEDDING DATE ---- */
  const WEDDING_DATE = new Date('2026-09-19T14:00:00-04:00');

  /* ---- HEADER SCROLL STATE ---- */
  const header = document.querySelector('.site-header');
  if (header) {
    const onScroll = () => {
      header.classList.toggle('scrolled', window.scrollY > 20);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---- MOBILE NAV ---- */
  const toggle = document.querySelector('.nav-toggle');
  const mobileNav = document.querySelector('.mobile-nav');
  const overlay = document.querySelector('.mobile-nav-overlay');

  if (toggle && mobileNav) {
    const openNav = () => {
      mobileNav.classList.add('open');
      toggle.classList.add('open');
      toggle.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
    };

    const closeNav = () => {
      mobileNav.classList.remove('open');
      toggle.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
    };

    toggle.addEventListener('click', () => {
      const isOpen = mobileNav.classList.contains('open');
      isOpen ? closeNav() : openNav();
    });

    if (overlay) overlay.addEventListener('click', closeNav);

    document.querySelectorAll('.mobile-nav-links a').forEach(link => {
      link.addEventListener('click', closeNav);
    });

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && mobileNav.classList.contains('open')) closeNav();
    });
  }

  /* ---- ACTIVE NAV LINK ---- */
  const currentPath = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.main-nav a, .mobile-nav-links a').forEach(link => {
    const href = link.getAttribute('href');
    if (href === currentPath || (currentPath === '' && href === 'index.html')) {
      link.classList.add('active');
    }
  });

  /* ---- COUNTDOWN TIMER ---- */
  const countdownEl = document.querySelector('[data-countdown-days]');
  if (countdownEl) {
    const updateCountdown = () => {
      const now = new Date();
      const diffMs = WEDDING_DATE - now;
      const days = Math.max(0, Math.ceil(diffMs / (1000 * 60 * 60 * 24)));
      countdownEl.textContent = days;

      const labelEl = document.querySelector('[data-countdown-label]');
      if (labelEl) {
        if (days === 0) labelEl.textContent = 'It’s today!';
        else if (days === 1) labelEl.textContent = 'day to go';
        else labelEl.textContent = 'days to go';
      }
    };
    updateCountdown();
    // Update every hour
    setInterval(updateCountdown, 60 * 60 * 1000);
  }

  /* ---- SCROLL REVEAL ---- */
  const revealEls = document.querySelectorAll('.reveal');
  if (revealEls.length && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
    );
    revealEls.forEach(el => observer.observe(el));
  }

  /* ---- RSVP FORM SUBMIT (demo) ---- */
  const rsvpForm = document.querySelector('.rsvp-form');
  if (rsvpForm) {
    rsvpForm.addEventListener('submit', e => {
      e.preventDefault();
      const success = document.querySelector('.rsvp-success');
      rsvpForm.style.display = 'none';
      if (success) {
        success.hidden = false;
        success.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  }

  /* ---- SMOOTH SCROLL for anchor links ---- */
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', e => {
      const href = anchor.getAttribute('href');
      if (href === '#') return;
      const target = document.querySelector(href);
      if (target) {
        e.preventDefault();
        const offset = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--nav-height')) || 78;
        const top = target.getBoundingClientRect().top + window.scrollY - offset - 16;
        window.scrollTo({ top, behavior: 'smooth' });
      }
    });
  });

})();
