/* ============================================================
   REMOTE ROOST — main.js
   Light progressive enhancement only. No frameworks.
   - Mobile nav toggle
   - Sticky-header shadow on scroll
   - Filter chip visual toggle (no real filtering)
   - Sort selector (no real sorting)
   - Save-job toggle (visual only)
   - Newsletter + post-a-job form submit handlers (display-only)
   ============================================================ */

(function () {
  'use strict';

  // ─── Mobile nav toggle ───────────────────────────────
  var toggle = document.querySelector('.nav-toggle');
  var mobileNav = document.querySelector('.mobile-nav');
  if (toggle && mobileNav) {
    toggle.addEventListener('click', function () {
      var open = mobileNav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // ─── Sticky header shadow on scroll ──────────────────
  var header = document.querySelector('.site-header');
  if (header) {
    var lastY = 0;
    window.addEventListener('scroll', function () {
      var y = window.scrollY;
      if (y > 4 && lastY <= 4) {
        header.style.boxShadow = '0 1px 0 rgba(12,56,56,0.05), 0 8px 22px rgba(12,56,56,0.07)';
      }
      if (y <= 4 && lastY > 4) {
        header.style.boxShadow = '';
      }
      lastY = y;
    }, { passive: true });
  }

  // ─── Filter chips (visual toggle only) ───────────────
  document.querySelectorAll('.filter-bar').forEach(function (bar) {
    bar.querySelectorAll('.filter-chip').forEach(function (chip) {
      chip.addEventListener('click', function (e) {
        e.preventDefault();
        bar.querySelectorAll('.filter-chip').forEach(function (c) {
          c.classList.remove('is-active');
          c.setAttribute('aria-pressed', 'false');
        });
        chip.classList.add('is-active');
        chip.setAttribute('aria-pressed', 'true');
      });
    });
  });

  // ─── Save-job toggle (visual only) ───────────────────
  document.querySelectorAll('[data-save-job]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var saved = btn.classList.toggle('is-saved');
      btn.textContent = saved ? 'Saved ✓' : 'Save job';
    });
  });

  // ─── Newsletter form (no-op) ─────────────────────────
  var newsForm = document.querySelector('[data-newsletter]');
  if (newsForm) {
    newsForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = newsForm.querySelector('[data-newsletter-msg]');
      if (msg) {
        msg.textContent = 'Thanks — check your inbox in about a minute.';
        msg.style.color = '#f4ecda';
      }
      newsForm.reset();
    });
  }

  // ─── Post-a-job form (no-op) ─────────────────────────
  var postForm = document.querySelector('[data-post-job]');
  if (postForm) {
    postForm.addEventListener('submit', function (e) {
      e.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      alert('Submission received — we review every post within one business day before it goes live.');
    });
  }

  // ─── Sort selector (no-op) ───────────────────────────
  var sortSelect = document.querySelector('[data-sort]');
  if (sortSelect) {
    sortSelect.addEventListener('change', function () {
      // No-op: real sorting would re-order .job-row nodes here.
    });
  }

})();
