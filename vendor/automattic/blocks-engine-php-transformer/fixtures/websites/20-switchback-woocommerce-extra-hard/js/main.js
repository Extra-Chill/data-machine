/* ==========================================
   SWITCHBACK — Main JS
   ========================================== */

(function () {
  'use strict';

  /* ── Mobile Nav ─────────────────────────── */
  const toggle = document.querySelector('.nav-toggle');
  const mobileNav = document.querySelector('.mobile-nav');
  const body = document.body;

  if (toggle && mobileNav) {
    toggle.addEventListener('click', () => {
      const isOpen = toggle.classList.toggle('open');
      mobileNav.classList.toggle('open', isOpen);
      body.style.overflow = isOpen ? 'hidden' : '';
      toggle.setAttribute('aria-expanded', isOpen);
    });

    // Close on ESC
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && mobileNav.classList.contains('open')) {
        toggle.classList.remove('open');
        mobileNav.classList.remove('open');
        body.style.overflow = '';
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* ── Sticky header ──────────────────────── */
  const header = document.querySelector('.site-header');
  if (header) {
    const onScroll = () => {
      header.classList.toggle('scrolled', window.scrollY > 40);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* ── Guided Kit Selector ────────────────── */
  const selectorForm = document.querySelector('.selector-form');
  const selectorResult = document.querySelector('.selector-result');

  const KITS = {
    hot: {
      name: 'Hot Trail Starter Kit',
      price: '$89',
      why: 'Covers heat management, hydration, and paw protection — everything that matters when temps climb.',
      url: 'bundles.html#hot-trail-starter',
    },
    mud: {
      name: 'Muddy Ride Home Kit',
      price: '$72',
      why: 'Clean-up starts the moment you hit the trailhead. Mat, dry coat, waterproof leash, and waste bags.',
      url: 'bundles.html#muddy-ride-home',
    },
    camp: {
      name: 'Weekend Camp Dog Kit',
      price: '$145',
      why: 'Two nights out. Dog pack, sleep mat, first aid, paw wax, and cleanup gear — nothing left behind.',
      url: 'product-weekend-camp-kit.html',
    },
    night: {
      name: 'Night Visibility Kit',
      price: '$78',
      why: 'Three light sources and a reflective harness. Run, hike, or camp after dark with full confidence.',
      url: 'bundles.html#night-visibility',
    },
    lake: {
      name: 'Lake Weekend Kit',
      price: '$95',
      why: 'Water-friendly gear, fast-dry coat, floating leash, and hydration for a full day at the water.',
      url: 'bundles.html#lake-weekend',
    },
    senior: {
      name: 'Senior Dog Trail Kit',
      price: '$120',
      why: 'Joint support, cushioned boots, electrolytes, and hydration — for dogs who still want to go.',
      url: 'bundles.html#senior-trail',
    },
  };

  function recommendKit(outing, weather, dogSize, mobility) {
    if (mobility === 'senior') return KITS.senior;
    if (weather === 'night') return KITS.night;
    if (outing === 'lake') return KITS.lake;
    if (outing === 'camp') return KITS.camp;
    if (weather === 'mud') return KITS.mud;
    if (weather === 'hot') return KITS.hot;
    // Default: day hike
    return KITS.hot;
  }

  if (selectorForm && selectorResult) {
    const selects = selectorForm.querySelectorAll('select');
    const resultKit = selectorResult.querySelector('.selector-result__kit');
    const resultWhy = selectorResult.querySelector('.selector-result__why');
    const resultLink = selectorResult.querySelector('.selector-result__link');

    function updateResult() {
      const vals = Array.from(selects).map(s => s.value);
      if (vals.some(v => !v)) return;

      const [outing, weather, , mobility] = vals;
      const kit = recommendKit(outing, weather, null, mobility);

      if (resultKit) resultKit.textContent = kit.name;
      if (resultWhy) resultWhy.textContent = kit.why;
      if (resultLink) {
        resultLink.href = kit.url;
        resultLink.textContent = 'View Kit — ' + kit.price + ' →';
      }
      selectorResult.classList.add('visible');
    }

    selects.forEach(s => s.addEventListener('change', updateResult));

    const formBtn = selectorForm.querySelector('.selector-submit');
    if (formBtn) {
      formBtn.addEventListener('click', (e) => {
        e.preventDefault();
        updateResult();
      });
    }
  }

  /* ── Shop filters ───────────────────────── */
  const filterCheckboxes = document.querySelectorAll('.js-filter-check');
  const productCards = document.querySelectorAll('.js-product-card');

  if (filterCheckboxes.length && productCards.length) {
    filterCheckboxes.forEach(cb => {
      cb.addEventListener('change', applyFilters);
    });

    function applyFilters() {
      const activeFilters = {};
      filterCheckboxes.forEach(cb => {
        if (cb.checked) {
          const group = cb.dataset.group;
          if (!activeFilters[group]) activeFilters[group] = [];
          activeFilters[group].push(cb.value);
        }
      });

      productCards.forEach(card => {
        const cardData = card.dataset;
        let show = true;

        for (const [group, values] of Object.entries(activeFilters)) {
          const cardValue = cardData[group] || '';
          const cardValues = cardValue.split(',').map(v => v.trim());
          const matches = values.some(v => cardValues.includes(v));
          if (!matches) { show = false; break; }
        }

        card.style.display = show ? '' : 'none';
      });

      // Update count
      const countEl = document.querySelector('.shop-result-count');
      if (countEl) {
        const visible = document.querySelectorAll('.js-product-card:not([style*="none"])').length;
        countEl.textContent = visible + ' products';
      }
    }
  }

  /* ── Sort select ────────────────────────── */
  const sortSelect = document.querySelector('.js-sort-select');
  if (sortSelect && productCards.length) {
    sortSelect.addEventListener('change', () => {
      const val = sortSelect.value;
      const grid = document.querySelector('.js-product-grid');
      if (!grid) return;

      const cards = Array.from(grid.querySelectorAll('.js-product-card'));
      cards.sort((a, b) => {
        if (val === 'price-asc') {
          return parseFloat(a.dataset.price || 0) - parseFloat(b.dataset.price || 0);
        }
        if (val === 'price-desc') {
          return parseFloat(b.dataset.price || 0) - parseFloat(a.dataset.price || 0);
        }
        return 0;
      });
      cards.forEach(c => grid.appendChild(c));
    });
  }

  /* ── Product detail thumbnail switcher ──── */
  const thumbs = document.querySelectorAll('.product-detail__thumb');
  const mainImage = document.querySelector('.product-detail__main-image');

  if (thumbs.length && mainImage) {
    thumbs.forEach(thumb => {
      thumb.addEventListener('click', () => {
        thumbs.forEach(t => t.classList.remove('active'));
        thumb.classList.add('active');
        // In a real store, swap the main image src here
      });
    });
  }

  /* ── Collection tab switching ───────────── */
  const collectionTabs = document.querySelectorAll('.collection-tab');
  const collectionPanels = document.querySelectorAll('.collection-panel');

  if (collectionTabs.length) {
    collectionTabs.forEach(tab => {
      tab.addEventListener('click', () => {
        const target = tab.dataset.target;
        collectionTabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');

        collectionPanels.forEach(panel => {
          panel.hidden = panel.id !== target;
        });
      });
    });
  }

  /* ── Add to cart (cosmetic) ─────────────── */
  const addButtons = document.querySelectorAll('.product-card__add, .btn--add-cart');
  const cartCount = document.querySelector('.cart-count');
  let cartTotal = 0;

  addButtons.forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      cartTotal++;
      if (cartCount) {
        cartCount.textContent = cartTotal;
        cartCount.style.transform = 'scale(1.3)';
        setTimeout(() => { cartCount.style.transform = ''; }, 200);
      }

      // Brief visual feedback on button
      const orig = btn.innerHTML;
      btn.innerHTML = `<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 7l3.5 3.5L12 3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>`;
      btn.style.background = 'var(--amber)';
      setTimeout(() => {
        btn.innerHTML = orig;
        btn.style.background = '';
      }, 900);
    });
  });

  /* ── Smooth scroll for anchor links ─────── */
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', (e) => {
      const targetId = anchor.getAttribute('href').slice(1);
      const target = document.getElementById(targetId);
      if (target) {
        e.preventDefault();
        const offset = 80;
        const top = target.getBoundingClientRect().top + window.scrollY - offset;
        window.scrollTo({ top, behavior: 'smooth' });
      }
    });
  });

  /* ── Scroll-reveal (simple IntersectionObserver) ── */
  const revealEls = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && revealEls.length) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('revealed');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    revealEls.forEach(el => observer.observe(el));
  }

})();
