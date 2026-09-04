/* ============================================================
   SKILLET & STEM — Shared JS
   Nav toggle, ingredient check, scroll reveals, scale, comments
============================================================ */

(function () {
  'use strict';

  /* ---- HEADER SCROLL STATE ---- */
  const header = document.querySelector('.site-header');
  if (header) {
    const onScroll = () => {
      header.classList.toggle('scrolled', window.scrollY > 16);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---- MOBILE NAV TOGGLE ---- */
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
      mobileNav.classList.contains('open') ? closeNav() : openNav();
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
    // also treat recipe-* pages as belonging to recipes
    if (currentPath.startsWith('recipe-') && href === 'recipes.html') {
      link.classList.add('active');
    }
  });

  /* ---- SCROLL REVEAL ---- */
  const revealEls = document.querySelectorAll('.reveal');
  if (revealEls.length) {
    const observer = new IntersectionObserver(
      entries => {
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

  /* ---- NEWSLETTER FORMS ---- */
  document.querySelectorAll('.newsletter-form').forEach(form => {
    form.addEventListener('submit', e => {
      e.preventDefault();
      const emailInput = form.querySelector('input[type="email"]');
      if (!emailInput || !emailInput.value) return;
      const success = form.closest('.newsletter-wrap')?.querySelector('.newsletter-success');
      form.style.display = 'none';
      if (success) {
        success.style.display = 'block';
        success.style.animation = 'fadeIn 0.5s ease both';
      }
    });
  });

  /* ---- INGREDIENT CHECKBOX TOGGLE ---- */
  document.querySelectorAll('.ingredient-list li').forEach(li => {
    const checkbox = li.querySelector('input[type="checkbox"]');
    if (!checkbox) return;
    li.addEventListener('click', e => {
      if (e.target.tagName === 'INPUT') return;
      checkbox.checked = !checkbox.checked;
      li.classList.toggle('checked', checkbox.checked);
    });
    checkbox.addEventListener('change', () => {
      li.classList.toggle('checked', checkbox.checked);
    });
  });

  /* ---- SCALE BUTTONS (visual only on ingredients section) ---- */
  document.querySelectorAll('.scale').forEach(group => {
    const btns = group.querySelectorAll('.scale-btn');
    btns.forEach(btn => {
      btn.addEventListener('click', () => {
        btns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
      });
    });
  });

  /* ---- COMMENT FORM RATING (mock) ---- */
  document.querySelectorAll('.rating-input').forEach(group => {
    const stars = group.querySelectorAll('span');
    stars.forEach((star, i) => {
      star.addEventListener('click', () => {
        stars.forEach((s, j) => {
          s.style.color = j <= i ? 'var(--gold)' : 'var(--rule)';
        });
      });
    });
  });

  /* ---- SMOOTH SCROLL anchor links ---- */
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', e => {
      const id = anchor.getAttribute('href');
      if (id === '#' || id.length < 2) return;
      const target = document.querySelector(id);
      if (target) {
        e.preventDefault();
        const offset = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--nav-height')) || 80;
        const top = target.getBoundingClientRect().top + window.scrollY - offset - 16;
        window.scrollTo({ top, behavior: 'smooth' });
      }
    });
  });

  /* ---- COMMENT REPLY toggle (mock) ---- */
  document.querySelectorAll('.comment-actions .reply-link').forEach(link => {
    link.addEventListener('click', e => {
      e.preventDefault();
      const original = link.textContent;
      link.textContent = original === 'Reply' ? 'Cancel' : 'Reply';
    });
  });

  /* ---- FILTER CHIPS (visual only on archive) ---- */
  document.querySelectorAll('.filter-row').forEach(row => {
    const chips = row.querySelectorAll('.chip');
    chips.forEach(chip => {
      chip.addEventListener('click', () => {
        chips.forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
      });
    });
  });

})();
