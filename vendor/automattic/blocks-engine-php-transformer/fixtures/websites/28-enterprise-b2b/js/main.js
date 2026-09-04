/* ============================================================
   LATTICE & SPIRE — main.js
   Light progressive enhancement. Mega-nav is in HTML always
   (CSS handles hover/focus visibility); this JS adds:
   - mobile nav toggle
   - mobile-nav accordion behavior (uses native <details>)
   - utility-bar language menu placeholder (no-op)
   - basic ROI input "savings" formatter (display-only)
   ============================================================ */

(function () {
  'use strict';

  // ─── Mobile nav toggle ─────────────────────────────────
  var toggle = document.querySelector('.nav-toggle');
  var mobileNav = document.querySelector('.mobile-nav');
  if (toggle && mobileNav) {
    toggle.addEventListener('click', function () {
      var open = mobileNav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // ─── Sticky header shadow ──────────────────────────────
  var header = document.querySelector('.site-header');
  if (header) {
    var lastY = 0;
    window.addEventListener('scroll', function () {
      var y = window.scrollY;
      if (y > 4 && lastY <= 4) header.style.boxShadow = '0 1px 0 rgba(12,39,71,0.05), 0 8px 24px rgba(12,39,71,0.06)';
      if (y <= 4 && lastY > 4)  header.style.boxShadow = '';
      lastY = y;
    }, { passive: true });
  }

  // ─── ROI calculator (display-only mock) ────────────────
  var roiForm = document.querySelector('[data-roi]');
  if (roiForm) {
    var inputs = roiForm.querySelectorAll('input[type="number"]');
    var output = roiForm.querySelector('[data-roi-output]');
    var compute = function () {
      var suppliers = parseFloat(roiForm.querySelector('[name="suppliers"]').value) || 0;
      var parts     = parseFloat(roiForm.querySelector('[name="parts"]').value) || 0;
      var spend     = parseFloat(roiForm.querySelector('[name="spend"]').value) || 0;
      // Pure mock formula — illustrative only
      var savings = (spend * 0.062) + (suppliers * 1280) + (parts * 12);
      if (output) {
        output.textContent = '$' + savings.toLocaleString('en-US', { maximumFractionDigits: 0 });
      }
    };
    inputs.forEach(function (i) { i.addEventListener('input', compute); });
  }

  // ─── Filter chips (visual toggle only) ─────────────────
  document.querySelectorAll('.filter-group').forEach(function (group) {
    group.querySelectorAll('.chip').forEach(function (chip) {
      chip.addEventListener('click', function (e) {
        e.preventDefault();
        group.querySelectorAll('.chip').forEach(function (c) { c.classList.remove('is-active'); });
        chip.classList.add('is-active');
      });
    });
  });

})();
