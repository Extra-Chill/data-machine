/* ================================================
   CIVIC SIGNAL — Main JavaScript
   ================================================ */

(function () {
  'use strict';

  /* === 1. Page Load === */
  document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.add('loaded');
    initNav();
    initScrollReveal();
    initResourceFilter();
    initChecklist();
    initTocHighlight();
    initHeroSearch();
    initPrintButton();
  });

  /* === 2. Navigation === */
  function initNav() {
    const toggle = document.querySelector('.nav-toggle');
    const drawer = document.querySelector('.nav-drawer');
    if (!toggle || !drawer) return;

    toggle.addEventListener('click', function () {
      const isOpen = drawer.classList.toggle('open');
      toggle.classList.toggle('open', isOpen);
      toggle.setAttribute('aria-expanded', isOpen);
      document.body.style.overflow = isOpen ? 'hidden' : '';
    });

    // Close drawer on nav link click
    drawer.querySelectorAll('.nav-link').forEach(link => {
      link.addEventListener('click', function () {
        drawer.classList.remove('open');
        toggle.classList.remove('open');
        toggle.setAttribute('aria-expanded', false);
        document.body.style.overflow = '';
      });
    });

    // Close on escape key
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && drawer.classList.contains('open')) {
        drawer.classList.remove('open');
        toggle.classList.remove('open');
        toggle.setAttribute('aria-expanded', false);
        document.body.style.overflow = '';
        toggle.focus();
      }
    });

    // Mark active nav link
    const currentPath = window.location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.nav-link').forEach(link => {
      const href = link.getAttribute('href');
      if (href === currentPath || (currentPath === '' && href === 'index.html')) {
        link.classList.add('active');
      }
    });
  }

  /* === 3. Scroll Reveal === */
  function initScrollReveal() {
    const reveals = document.querySelectorAll('.reveal');
    if (!reveals.length) return;

    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

    reveals.forEach(el => observer.observe(el));
  }

  /* === 4. Resource Filter & Search === */
  function initResourceFilter() {
    const grid = document.querySelector('[data-resource-grid]');
    if (!grid) return;

    const cards = Array.from(grid.querySelectorAll('[data-topic]'));
    const searchInput = document.querySelector('[data-resource-search]');
    const filterBtns = document.querySelectorAll('[data-filter]');
    const noResults = document.querySelector('.no-results');
    const countDisplay = document.querySelector('[data-result-count]');

    let activeFilter = 'all';
    let searchQuery = '';

    function applyFilters() {
      let visibleCount = 0;

      cards.forEach(function (card, i) {
        const topic = card.dataset.topic || '';
        const searchText = (card.dataset.search || card.textContent).toLowerCase();

        const matchesTopic = activeFilter === 'all' || topic === activeFilter;
        const matchesSearch = !searchQuery || searchText.includes(searchQuery);
        const visible = matchesTopic && matchesSearch;

        if (visible) {
          card.style.display = '';
          card.style.animationDelay = (visibleCount * 60) + 'ms';
          card.classList.remove('reveal');
          void card.offsetWidth; // trigger reflow
          card.classList.add('reveal', 'visible');
          visibleCount++;
        } else {
          card.style.display = 'none';
        }
      });

      if (noResults) {
        noResults.classList.toggle('visible', visibleCount === 0);
      }
      if (countDisplay) {
        countDisplay.textContent = visibleCount + ' resource' + (visibleCount !== 1 ? 's' : '');
      }
    }

    if (searchInput) {
      // Pre-populate from URL params
      const params = new URLSearchParams(window.location.search);
      const q = params.get('q');
      if (q) {
        searchInput.value = q;
        searchQuery = q.toLowerCase().trim();
      }

      searchInput.addEventListener('input', function () {
        searchQuery = this.value.toLowerCase().trim();
        applyFilters();
      });
    }

    filterBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        activeFilter = this.dataset.filter;
        filterBtns.forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        applyFilters();
      });
    });

    applyFilters();
  }

  /* === 5. Checklist Progress === */
  function initChecklist() {
    const checkboxes = document.querySelectorAll('.checklist-item input[type="checkbox"]');
    if (!checkboxes.length) return;

    const fill = document.querySelector('.progress-bar-fill');
    const countEl = document.querySelector('.progress-count');
    const pctEl = document.querySelector('.progress-pct');
    const STORAGE_KEY = document.body.dataset.checklistKey || 'civic-signal-checklist';

    // Restore from localStorage
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) {}

    checkboxes.forEach(function (cb) {
      if (saved[cb.id] === true) {
        cb.checked = true;
        const item = cb.closest('.checklist-item');
        if (item) item.classList.add('checked');
      }

      cb.addEventListener('change', function () {
        const item = this.closest('.checklist-item');
        if (item) item.classList.toggle('checked', this.checked);
        updateProgress();
      });
    });

    function updateProgress() {
      const total = checkboxes.length;
      const checked = document.querySelectorAll('.checklist-item input[type="checkbox"]:checked').length;
      const pct = total > 0 ? Math.round((checked / total) * 100) : 0;

      if (fill) {
        fill.style.width = pct + '%';
        fill.classList.toggle('complete', pct === 100);
      }
      if (countEl) countEl.textContent = checked + ' / ' + total;
      if (pctEl) pctEl.textContent = pct + '% complete';

      // Save to localStorage
      const state = {};
      checkboxes.forEach(function (cb) { state[cb.id] = cb.checked; });
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
    }

    updateProgress();
  }

  /* === 6. Table of Contents Active Section Highlight === */
  function initTocHighlight() {
    const toc = document.querySelector('.toc-list');
    if (!toc) return;

    const sections = document.querySelectorAll('[data-section]');
    if (!sections.length) return;

    const tocLinks = toc.querySelectorAll('a[href^="#"]');

    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          const id = entry.target.id;
          tocLinks.forEach(function (link) {
            link.classList.toggle('active', link.getAttribute('href') === '#' + id);
          });
        }
      });
    }, { rootMargin: '-20% 0px -60% 0px' });

    sections.forEach(el => observer.observe(el));
  }

  /* === 7. Hero Search Redirect === */
  function initHeroSearch() {
    const form = document.querySelector('.hero-search-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      const input = form.querySelector('.hero-search-input');
      const q = input ? input.value.trim() : '';
      if (q) {
        window.location.href = 'resources.html?q=' + encodeURIComponent(q);
      } else {
        window.location.href = 'resources.html';
      }
    });
  }

  /* === 8. Print Button === */
  function initPrintButton() {
    const btn = document.querySelector('[data-print]');
    if (!btn) return;
    btn.addEventListener('click', function () { window.print(); });
  }

  /* === 9. Smooth TOC scroll === */
  document.addEventListener('click', function (e) {
    const link = e.target.closest('a[href^="#"]');
    if (!link) return;
    const id = link.getAttribute('href').slice(1);
    const target = document.getElementById(id);
    if (!target) return;
    e.preventDefault();
    const offset = 80;
    const top = target.getBoundingClientRect().top + window.scrollY - offset;
    window.scrollTo({ top, behavior: 'smooth' });
  });

})();
