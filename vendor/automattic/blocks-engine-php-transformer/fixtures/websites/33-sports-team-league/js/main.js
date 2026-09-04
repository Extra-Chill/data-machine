/* ============================================================
   Hudson Valley Hockey Conference — Main JavaScript
   Mobile nav, scroll header, leaderboard tabs, sortable headers
   mock, filter chips, scroll reveal, active nav.
   ============================================================ */

(function () {
  'use strict';

  // ── Header scroll state ─────────────────────────────────
  const header = document.querySelector('.site-header');
  if (header) {
    const onScroll = () => {
      header.classList.toggle('scrolled', window.scrollY > 30);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // ── Mobile nav toggle ───────────────────────────────────
  const navToggle = document.querySelector('.nav-toggle');
  const mobileNav = document.querySelector('.mobile-nav');

  if (navToggle && mobileNav) {
    navToggle.addEventListener('click', () => {
      const isOpen = navToggle.classList.toggle('open');
      mobileNav.classList.toggle('open', isOpen);
      document.body.style.overflow = isOpen ? 'hidden' : '';
      navToggle.setAttribute('aria-expanded', isOpen);
    });

    mobileNav.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        navToggle.classList.remove('open');
        mobileNav.classList.remove('open');
        document.body.style.overflow = '';
        navToggle.setAttribute('aria-expanded', 'false');
      });
    });

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && mobileNav.classList.contains('open')) {
        navToggle.classList.remove('open');
        mobileNav.classList.remove('open');
        document.body.style.overflow = '';
        navToggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // ── Scroll reveal ───────────────────────────────────────
  const reveals = document.querySelectorAll('.reveal');
  if (reveals.length && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.1, rootMargin: '0px 0px -40px 0px' }
    );
    reveals.forEach(el => observer.observe(el));
  }

  // ── Leaderboard tabs ────────────────────────────────────
  const lbTabs = document.querySelectorAll('.lb-tab');
  const lbGroups = document.querySelectorAll('.lb-group');

  if (lbTabs.length) {
    lbTabs.forEach(btn => {
      btn.addEventListener('click', () => {
        const target = btn.dataset.tab;
        lbTabs.forEach(b => {
          b.classList.toggle('active', b === btn);
          b.setAttribute('aria-selected', b === btn);
        });
        lbGroups.forEach(group => {
          group.style.display = group.id === target ? 'grid' : 'none';
        });
      });
    });
  }

  // ── Filter chips (visual toggle only) ───────────────────
  document.querySelectorAll('.chip-group').forEach(group => {
    const chips = group.querySelectorAll('.chip');
    chips.forEach(chip => {
      chip.addEventListener('click', () => {
        chips.forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
      });
    });
  });

  // ── Sortable header mock ───────────────────────────────
  // Cycles classes (none → asc → desc) on click, clears siblings.
  document.querySelectorAll('.data-table thead th.sortable').forEach(th => {
    th.addEventListener('click', () => {
      const row = th.parentNode;
      const wasAsc = th.classList.contains('sorted-asc');
      const wasDesc = th.classList.contains('sorted-desc');
      row.querySelectorAll('th').forEach(sib => {
        sib.classList.remove('sorted-asc', 'sorted-desc');
      });
      if (!wasAsc && !wasDesc) {
        th.classList.add('sorted-asc');
      } else if (wasAsc) {
        th.classList.add('sorted-desc');
      }
    });
  });

  // ── Active nav link ────────────────────────────────────
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-links a, .mobile-nav a').forEach(link => {
    const href = link.getAttribute('href');
    if (!href) return;
    if (
      href === currentPage ||
      (currentPage === '' && href === 'index.html') ||
      (currentPage === 'index.html' && href === 'index.html') ||
      (href === 'teams.html' && currentPage.startsWith('team-'))
    ) {
      link.classList.add('active');
    }
  });

})();
