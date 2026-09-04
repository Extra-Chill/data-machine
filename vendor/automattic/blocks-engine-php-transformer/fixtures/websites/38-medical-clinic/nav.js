/* ============================================================
   WESTGATE FAMILY DERMATOLOGY — Navigation & UI Script
   ============================================================ */

(function () {
  'use strict';

  /* ── Mobile Nav Toggle ─────────────────────────────────── */
  const toggle = document.querySelector('.nav-toggle');
  const drawer = document.querySelector('.nav-drawer');

  if (toggle && drawer) {
    toggle.addEventListener('click', function () {
      var open = toggle.classList.toggle('open');
      drawer.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', String(open));
      document.body.style.overflow = open ? 'hidden' : '';
    });

    document.addEventListener('click', function (e) {
      if (!e.target.closest('.site-header') && drawer.classList.contains('open')) {
        closeMobileNav();
      }
    });

    drawer.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', closeMobileNav);
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && drawer.classList.contains('open')) {
        closeMobileNav();
        toggle.focus();
      }
    });
  }

  function closeMobileNav() {
    if (!toggle || !drawer) return;
    toggle.classList.remove('open');
    drawer.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }

  /* ── Active Page Highlight ─────────────────────────────── */
  var currentFile = window.location.pathname.split('/').pop() || 'index.html';
  if (currentFile === '') currentFile = 'index.html';

  document.querySelectorAll('.site-nav a, .nav-drawer a').forEach(function (link) {
    var href = link.getAttribute('href');
    if (!href) return;
    var base = href.split('#')[0];
    if (base === currentFile) {
      link.classList.add('active');
    }
  });

  /* ── Reason-for-visit character counter ────────────────── */
  var reason = document.getElementById('reason');
  var reasonCount = document.getElementById('reason-count');
  if (reason && reasonCount) {
    var max = parseInt(reason.getAttribute('maxlength'), 10) || 500;
    var update = function () {
      var used = reason.value.length;
      reasonCount.textContent = used + ' / ' + max + ' characters';
      if (used > max * 0.9) {
        reasonCount.style.color = '#b8523a';
      } else {
        reasonCount.style.color = '';
      }
    };
    reason.addEventListener('input', update);
    update();
  }

  /* ── Referrer conditional field (UI-only) ──────────────── */
  var referredRadios = document.querySelectorAll('input[name="referred"]');
  var referrerWrap = document.getElementById('referrer-wrap');
  if (referredRadios.length && referrerWrap) {
    referredRadios.forEach(function (r) {
      r.addEventListener('change', function () {
        if (r.value === 'yes' && r.checked) {
          referrerWrap.hidden = false;
        } else if (r.value === 'no' && r.checked) {
          referrerWrap.hidden = true;
        }
      });
    });
  }

  /* ── Appointment form submit (mock) ────────────────────── */
  var apptForm = document.getElementById('appt-form');
  if (apptForm) {
    apptForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var submitBtn = apptForm.querySelector('[type="submit"]');
      if (!submitBtn) return;
      var original = submitBtn.textContent;
      submitBtn.textContent = 'Sending request…';
      submitBtn.disabled = true;
      setTimeout(function () {
        submitBtn.textContent = 'Request received — we will call you within one business day';
        submitBtn.style.background = '#6a8a5a';
        submitBtn.style.borderColor = '#6a8a5a';
        setTimeout(function () {
          submitBtn.textContent = original;
          submitBtn.disabled = false;
          submitBtn.style.background = '';
          submitBtn.style.borderColor = '';
          apptForm.reset();
          if (reasonCount) reasonCount.textContent = '0 / 500 characters';
        }, 5000);
      }, 1200);
    });
  }

  /* ── Header shadow on scroll ───────────────────────────── */
  var header = document.querySelector('.site-header');
  if (header) {
    window.addEventListener('scroll', function () {
      if (window.scrollY > 8) {
        header.style.boxShadow = '0 4px 16px rgba(16,58,92,.10)';
      } else {
        header.style.boxShadow = '0 1px 2px rgba(16,58,92,.05), 0 2px 8px rgba(16,58,92,.04)';
      }
    }, { passive: true });
  }

})();
