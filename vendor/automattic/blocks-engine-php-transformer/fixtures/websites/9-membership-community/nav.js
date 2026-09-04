/* ============================================================
   COMMON TABLE — Shared JS
   Mobile nav · Scroll reveal · Active link · Form handling
   ============================================================ */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    // ── Mobile nav toggle ────────────────────────────────────
    var hamburger = document.querySelector('.hamburger');
    var navMenu   = document.querySelector('.nav-menu');

    if (hamburger && navMenu) {
      hamburger.addEventListener('click', function () {
        var isOpen = navMenu.classList.toggle('open');
        hamburger.classList.toggle('active', isOpen);
        hamburger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        document.body.style.overflow = isOpen ? 'hidden' : '';
      });

      // Close when a nav link is clicked
      navMenu.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
          navMenu.classList.remove('open');
          hamburger.classList.remove('active');
          hamburger.setAttribute('aria-expanded', 'false');
          document.body.style.overflow = '';
        });
      });

      // Close on outside click
      document.addEventListener('click', function (e) {
        if (!e.target.closest('.site-nav') && navMenu.classList.contains('open')) {
          navMenu.classList.remove('open');
          hamburger.classList.remove('active');
          hamburger.setAttribute('aria-expanded', 'false');
          document.body.style.overflow = '';
        }
      });
    }

    // ── Nav shadow on scroll ─────────────────────────────────
    var siteNav = document.querySelector('.site-nav');
    if (siteNav) {
      function updateNav() {
        siteNav.classList.toggle('scrolled', window.scrollY > 40);
      }
      window.addEventListener('scroll', updateNav, { passive: true });
      updateNav();
    }

    // ── Active nav link ───────────────────────────────────────
    var page = window.location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.nav-link').forEach(function (link) {
      var href = link.getAttribute('href');
      if (href === page || (page === '' && href === 'index.html')) {
        link.classList.add('active');
        link.setAttribute('aria-current', 'page');
      }
    });

    // ── Scroll reveal via IntersectionObserver ─────────────────
    var reveals = document.querySelectorAll('.reveal');
    if (reveals.length && 'IntersectionObserver' in window) {
      var revealObs = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            var delay = parseInt(entry.target.dataset.delay || '0', 10);
            setTimeout(function () {
              entry.target.classList.add('is-visible');
            }, delay);
            revealObs.unobserve(entry.target);
          }
        });
      }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

      reveals.forEach(function (el) { revealObs.observe(el); });
    } else {
      // Fallback: show everything
      reveals.forEach(function (el) { el.classList.add('is-visible'); });
    }

    // ── Auto-stagger children with data-stagger ────────────────
    document.querySelectorAll('[data-stagger]').forEach(function (parent) {
      var step = parseInt(parent.dataset.stagger || '100', 10);
      Array.from(parent.children).forEach(function (child, i) {
        child.classList.add('reveal');
        child.dataset.delay = String(i * step);
      });
      // Observe newly marked children
      if ('IntersectionObserver' in window) {
        var staggerObs = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              var d = parseInt(entry.target.dataset.delay || '0', 10);
              setTimeout(function () { entry.target.classList.add('is-visible'); }, d);
              staggerObs.unobserve(entry.target);
            }
          });
        }, { threshold: 0.08, rootMargin: '0px 0px -30px 0px' });
        parent.querySelectorAll('.reveal').forEach(function (el) { staggerObs.observe(el); });
      } else {
        parent.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('is-visible'); });
      }
    });

    // ── Form submission ────────────────────────────────────────
    document.querySelectorAll('form[data-ct-form]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('[type="submit"]');
        if (!btn) return;
        var orig = btn.textContent;
        btn.textContent = 'Sent — thank you!';
        btn.disabled = true;
        btn.style.backgroundColor = 'var(--sage)';
        var successMsg = form.querySelector('.form-success');
        if (successMsg) { successMsg.classList.add('show'); }
        setTimeout(function () {
          btn.textContent = orig;
          btn.disabled = false;
          btn.style.backgroundColor = '';
          form.reset();
          if (successMsg) { successMsg.classList.remove('show'); }
        }, 4000);
      });
    });

    // ── Smooth scroll for hash links ───────────────────────────
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
      anchor.addEventListener('click', function (e) {
        var target = document.querySelector(this.getAttribute('href'));
        if (target) {
          e.preventDefault();
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    });

  });
}());
