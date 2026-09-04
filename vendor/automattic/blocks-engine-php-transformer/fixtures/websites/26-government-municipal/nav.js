/* ============================================================
   TOWN OF MILLBROOK — Navigation & UI Scripts
   ============================================================ */

(function () {
  'use strict';

  /* ── Mobile Nav Toggle ── */
  const toggle = document.querySelector('.nav-toggle');
  const drawer = document.querySelector('.nav-drawer');

  if (toggle && drawer) {
    toggle.addEventListener('click', function () {
      const open = toggle.classList.toggle('open');
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

  /* ── Active Page Highlight ── */
  let currentFile = window.location.pathname.split('/').pop() || 'index.html';
  if (currentFile === '') currentFile = 'index.html';

  document.querySelectorAll('.site-nav a, .nav-drawer a').forEach(function (link) {
    const href = link.getAttribute('href');
    if (!href) return;
    const base = href.split('#')[0];
    if (base === currentFile) {
      link.classList.add('active');
      link.setAttribute('aria-current', 'page');
    }
  });

  /* ── Dismissible Alert Banners ── */
  document.querySelectorAll('.alert-dismiss').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const alert = btn.closest('.alert');
      if (alert) {
        alert.style.display = 'none';
      }
    });
  });

  /* ── Contact-Officials Form: lightweight client validation ── */
  const officialsForm = document.getElementById('officials-contact-form');
  if (officialsForm) {
    const messageField = officialsForm.querySelector('textarea[name="message"]');
    const counter = document.getElementById('message-counter');
    const MAX = 2000;

    if (messageField && counter) {
      const updateCount = function () {
        const used = messageField.value.length;
        counter.textContent = used + ' / ' + MAX + ' characters';
        if (used > MAX) {
          counter.style.color = 'var(--civic-red)';
        } else {
          counter.style.color = '';
        }
      };
      messageField.addEventListener('input', updateCount);
      updateCount();
    }

    officialsForm.addEventListener('submit', function (e) {
      e.preventDefault();

      const submitBtn = officialsForm.querySelector('[type="submit"]');
      const status = document.getElementById('form-status');
      const originalText = submitBtn.textContent;

      submitBtn.textContent = 'Submitting…';
      submitBtn.disabled = true;

      setTimeout(function () {
        if (status) {
          status.hidden = false;
          status.focus();
          status.scrollIntoView({ behavior: 'smooth', block: 'center' });
          officialsForm.reset();
        }
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
      }, 900);
    });
  }

  /* ── Filter chips (visual-only toggle on Notices page) ── */
  document.querySelectorAll('.filter-chips').forEach(function (group) {
    group.querySelectorAll('.chip').forEach(function (chip) {
      chip.addEventListener('click', function (e) {
        e.preventDefault();
        group.querySelectorAll('.chip').forEach(function (c) {
          c.classList.remove('active');
        });
        chip.classList.add('active');
      });
    });
  });

})();
