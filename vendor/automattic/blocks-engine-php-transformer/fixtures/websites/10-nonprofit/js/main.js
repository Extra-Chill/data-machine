/* ============================================================
   HARBOR STEPS — main.js
   Scroll header · reveal observer · counter animation ·
   budget bars · FAQ accordion · donation UI · nav toggle
   ============================================================ */

(function () {
  'use strict';

  /* ---- Sticky header ---- */
  const header = document.querySelector('.site-header');
  window.addEventListener('scroll', () => {
    header.classList.toggle('scrolled', window.scrollY > 60);
  }, { passive: true });

  /* ---- Mobile nav ---- */
  const navToggle = document.querySelector('.nav-toggle');
  if (navToggle) {
    navToggle.addEventListener('click', () => {
      header.classList.toggle('nav-open');
    });
    document.querySelectorAll('.nav-links a').forEach(a => {
      a.addEventListener('click', () => header.classList.remove('nav-open'));
    });
  }

  /* ---- Smooth anchor scroll (accounts for fixed header height) ---- */
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', function (e) {
      const target = document.querySelector(this.getAttribute('href'));
      if (!target) return;
      e.preventDefault();
      const offset = header.offsetHeight + 12;
      const y = target.getBoundingClientRect().top + window.scrollY - offset;
      window.scrollTo({ top: y, behavior: 'smooth' });
    });
  });

  /* ---- Counter animation ---- */
  function animateCounter(el) {
    const raw    = el.dataset.target;         // e.g. "47200" or "8.4"
    const isFloat = el.dataset.float === 'true';
    const prefix  = el.dataset.prefix || '';
    const suffix  = el.dataset.suffix || '';
    const target  = parseFloat(raw);
    const dur     = 2200;
    const start   = performance.now();

    function tick(now) {
      const p    = Math.min((now - start) / dur, 1);
      const ease = 1 - Math.pow(1 - p, 3);   // ease-out cubic
      const val  = target * ease;
      const disp = isFloat
        ? val.toFixed(1)
        : Math.round(val).toLocaleString();
      el.textContent = prefix + disp + suffix;
      if (p < 1) requestAnimationFrame(tick);
      else el.textContent = prefix + (isFloat ? target.toFixed(1) : target.toLocaleString()) + suffix;
    }
    requestAnimationFrame(tick);
  }

  /* ---- Intersection Observer for .reveal elements ---- */
  const revealObs = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.classList.add('revealed');
        revealObs.unobserve(e.target);
      }
    });
  }, { threshold: 0.12 });

  document.querySelectorAll('.reveal').forEach(el => revealObs.observe(el));

  /* ---- Counter observer ---- */
  const counterObs = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        const numEl = e.target.querySelector('.metric-number');
        if (numEl) {
          e.target.classList.add('revealed');
          animateCounter(numEl);
        }
        counterObs.unobserve(e.target);
      }
    });
  }, { threshold: 0.3 });

  document.querySelectorAll('.metric-card').forEach(c => counterObs.observe(c));

  /* ---- Budget bar observer ---- */
  const budgetObs = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.querySelectorAll('.budget-fill').forEach(bar => {
          // slight stagger per bar index
          const idx = [...bar.closest('.budget-bars').querySelectorAll('.budget-fill')].indexOf(bar);
          setTimeout(() => { bar.style.width = bar.dataset.w; }, idx * 120);
        });
        budgetObs.unobserve(e.target);
      }
    });
  }, { threshold: 0.25 });

  const budgetBars = document.querySelector('.budget-bars');
  if (budgetBars) budgetObs.observe(budgetBars);

  /* ---- FAQ accordion ---- */
  document.querySelectorAll('.faq-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const item   = btn.closest('.faq-item');
      const isOpen = item.classList.contains('open');
      document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('open'));
      if (!isOpen) item.classList.add('open');
    });
  });

  /* ---- Donation amount buttons ---- */
  document.querySelectorAll('.amount-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.amount-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const input = document.querySelector('.amount-custom-row input');
      if (input) input.value = btn.dataset.amount;
    });
  });

  /* Deactivate preset when user types a custom amount */
  const customInput = document.querySelector('.amount-custom-row input');
  if (customInput) {
    customInput.addEventListener('input', () => {
      document.querySelectorAll('.amount-btn').forEach(b => b.classList.remove('active'));
    });
  }

  /* Donate button feedback */
  const submitBtn = document.querySelector('.donate-submit');
  if (submitBtn) {
    submitBtn.addEventListener('click', () => {
      const amt = customInput ? customInput.value : '';
      if (!amt || isNaN(parseFloat(amt)) || parseFloat(amt) <= 0) {
        customInput && customInput.focus();
        return;
      }
      submitBtn.textContent = 'Thank you ✓';
      submitBtn.style.background = 'var(--teal)';
      setTimeout(() => {
        submitBtn.textContent = 'Donate Now →';
        submitBtn.style.background = '';
      }, 3000);
    });
  }

  /* ---- Wave parallax (subtle) ---- */
  const heroWaves = document.querySelector('.hero-waves');
  if (heroWaves) {
    window.addEventListener('scroll', () => {
      const y = window.scrollY;
      heroWaves.style.transform = `translateY(${y * 0.18}px)`;
    }, { passive: true });
  }

})();
