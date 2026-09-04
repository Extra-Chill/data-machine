/* ============================================================
   NORTHLINE PLUMBING & HEATING — Navigation Script
   ============================================================ */

(function () {
  'use strict';

  /* ── Mobile Nav Toggle ── */
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

  /* ── Active Page Highlight ── */
  var currentFile = window.location.pathname.split('/').pop() || 'index.html';
  if (currentFile === '') currentFile = 'index.html';

  document.querySelectorAll('.site-nav a, .nav-drawer a').forEach(function (link) {
    var href = link.getAttribute('href');
    if (!href) return;
    var base = href.split('#')[0];
    if (base === currentFile || (currentFile === 'index.html' && base === 'index.html')) {
      link.classList.add('active');
    }
  });

  /* ── Scroll-Enhanced Header Shadow ── */
  var header = document.querySelector('.site-header');
  if (header) {
    window.addEventListener('scroll', function () {
      if (window.scrollY > 12) {
        header.style.boxShadow = '0 4px 32px rgba(0,0,0,.48)';
      } else {
        header.style.boxShadow = '0 2px 20px rgba(0,0,0,.38)';
      }
    }, { passive: true });
  }

  /* ── Simple Contact Form Handler ── */
  var contactForm = document.getElementById('contact-form');
  if (contactForm) {
    contactForm.addEventListener('submit', function (e) {
      e.preventDefault();

      var submitBtn = contactForm.querySelector('[type="submit"]');
      var originalText = submitBtn.textContent;

      submitBtn.textContent = 'Sending…';
      submitBtn.disabled = true;

      setTimeout(function () {
        var successMsg = document.getElementById('form-success');
        if (successMsg) {
          contactForm.style.display = 'none';
          successMsg.style.display = 'block';
        } else {
          submitBtn.textContent = 'Request Sent!';
          submitBtn.style.background = '#15803d';
          setTimeout(function () {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
            submitBtn.style.background = '';
            contactForm.reset();
          }, 4000);
        }
      }, 1200);
    });
  }

})();
