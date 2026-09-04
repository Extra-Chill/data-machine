/* Lumen Coffee Roasters — UI: rendering, filter/sort, product modal, cart drawer */
(function (global) {
  'use strict';

  var doc = global.document;
  var L = global.LUMEN || {};
  var cart = L.cart;

  function $(sel, root) { return (root || doc).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); }
  function el(tag, cls, html) {
    var n = doc.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    return n;
  }
  function money(n) { return cart ? cart.money(n) : '$' + Number(n).toFixed(2); }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function selectionText(sel) {
    if (!sel) return '';
    return Object.keys(sel).map(function (k) { return k + ': ' + sel[k]; }).join(' · ');
  }

  /* ---------------------------------------------------------------- badge */
  function updateBadge() {
    var count = cart ? cart.count() : 0;
    $all('[data-cart-badge]').forEach(function (b) {
      b.textContent = count;
      b.hidden = count === 0;
    });
  }

  /* ---------------------------------------------------------- product card */
  function productCard(p) {
    var card = el('article', 'card');
    card.innerHTML =
      '<button class="card__media" type="button" data-detail="' + p.id + '" aria-label="View details for ' + escapeHtml(p.name) + '">' +
        L.svgFor(p) +
      '</button>' +
      '<div class="card__body">' +
        '<span class="card__cat">' + escapeHtml(categoryLabel(p.category)) + '</span>' +
        '<h3 class="card__title"><button type="button" class="linklike" data-detail="' + p.id + '">' + escapeHtml(p.name) + '</button></h3>' +
        '<p class="card__notes">' + escapeHtml(p.notes) + '</p>' +
        '<div class="card__meta">' +
          (p.roast !== 'N/A' ? '<span class="chip">' + escapeHtml(p.roast) + ' Roast</span>' : '<span class="chip">Brew Gear</span>') +
        '</div>' +
        '<div class="card__foot">' +
          '<span class="price">' + money(p.price) + '</span>' +
          '<div class="card__actions">' +
            '<button class="btn btn--ghost" type="button" data-detail="' + p.id + '">Options</button>' +
            '<button class="btn btn--solid" type="button" data-quickadd="' + p.id + '">Add</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    return card;
  }

  function categoryLabel(id) {
    var c = (L.CATEGORIES || []).filter(function (x) { return x.id === id; })[0];
    return c ? c.label : id;
  }

  /* --------------------------------------------------------------- modal */
  var modal, modalBody, lastFocused;
  var modalState = { product: null, selection: null, qty: 1 };

  function ensureModal() {
    modal = $('#product-modal');
    if (!modal) return false;
    modalBody = $('#product-modal-body', modal);
    modal.addEventListener('click', function (e) {
      if (e.target === modal || e.target.closest('[data-close-modal]')) closeModal();
    });
    // Single delegated handler — modalBody is reused across products.
    modalBody.addEventListener('click', onModalBodyClick);
    return true;
  }

  function renderModal() {
    var p = modalState.product;
    if (!p) return;
    var unit = L.priceFor(p, modalState.selection);
    var optsHtml = Object.keys(p.options || {}).map(function (group) {
      var choices = p.options[group];
      var btns = choices.map(function (c) {
        var label = (typeof c === 'object') ? c.label : c;
        var active = modalState.selection[group] === label ? ' is-active' : '';
        return '<button type="button" class="opt' + active + '" data-opt-group="' + escapeHtml(group) + '" data-opt-val="' + escapeHtml(label) + '">' + escapeHtml(label) + '</button>';
      }).join('');
      return '<div class="opt-group"><span class="opt-group__label">' + escapeHtml(group) + '</span><div class="opt-row">' + btns + '</div></div>';
    }).join('');

    modalBody.innerHTML =
      '<div class="detail">' +
        '<div class="detail__media">' + L.svgFor(p) + '</div>' +
        '<div class="detail__info">' +
          '<span class="card__cat">' + escapeHtml(categoryLabel(p.category)) + '</span>' +
          '<h2 id="product-modal-title" class="detail__title">' + escapeHtml(p.name) + '</h2>' +
          (p.roast !== 'N/A' ? '<p class="detail__roast">' + escapeHtml(p.roast) + ' Roast</p>' : '') +
          '<p class="detail__notes">' + escapeHtml(p.notes) + '</p>' +
          optsHtml +
          '<div class="detail__buy">' +
            '<div class="stepper" role="group" aria-label="Quantity">' +
              '<button type="button" class="stepper__btn" data-qty="-1" aria-label="Decrease quantity">&minus;</button>' +
              '<span class="stepper__val" data-qty-val aria-live="polite">' + modalState.qty + '</span>' +
              '<button type="button" class="stepper__btn" data-qty="1" aria-label="Increase quantity">+</button>' +
            '</div>' +
            '<button type="button" class="btn btn--solid btn--lg" data-add-detail>Add to Cart · <span data-detail-price>' + money(unit) + '</span></button>' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  function onModalBodyClick(e) {
    if (!modalState.product) return;
    var opt = e.target.closest('[data-opt-group]');
    if (opt) {
      modalState.selection[opt.getAttribute('data-opt-group')] = opt.getAttribute('data-opt-val');
      renderModal();
      return;
    }
    var step = e.target.closest('[data-qty]');
    if (step) {
      modalState.qty = Math.max(1, modalState.qty + parseInt(step.getAttribute('data-qty'), 10));
      renderModal();
      return;
    }
    if (e.target.closest('[data-add-detail]')) {
      cart.add(modalState.product, modalState.selection, modalState.qty);
      closeModal();
      openDrawer();
    }
  }

  function openModal(productId) {
    if (!modal) return;
    var p = L.productById(productId);
    if (!p) return;
    modalState.product = p;
    modalState.selection = L.defaultSelection(p);
    modalState.qty = 1;
    renderModal();

    lastFocused = doc.activeElement;
    modal.hidden = false;
    doc.body.classList.add('no-scroll');
    var closeBtn = $('[data-close-modal]', modal);
    if (closeBtn) closeBtn.focus();
  }

  function closeModal() {
    if (!modal || modal.hidden) return;
    modal.hidden = true;
    modalState.product = null;
    if (modalBody) modalBody.innerHTML = '';
    if (!drawerOpen()) doc.body.classList.remove('no-scroll');
    if (lastFocused && lastFocused.focus) lastFocused.focus();
  }

  /* --------------------------------------------------------------- drawer */
  var drawer, drawerScrim;

  function ensureDrawer() {
    drawer = $('#cart-drawer');
    drawerScrim = $('#drawer-scrim');
    if (!drawer) return false;
    if (drawerScrim) drawerScrim.addEventListener('click', closeDrawer);
    drawer.addEventListener('click', function (e) {
      if (e.target.closest('[data-close-drawer]')) closeDrawer();
    });
    $all('[data-open-drawer]').forEach(function (b) {
      b.addEventListener('click', function (e) { e.preventDefault(); openDrawer(); });
    });
    drawer.addEventListener('click', onLineAction);
    return true;
  }

  function drawerOpen() { return drawer && drawer.classList.contains('is-open'); }

  function openDrawer() {
    if (!drawer) return;
    renderDrawer();
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    if (drawerScrim) drawerScrim.hidden = false;
    doc.body.classList.add('no-scroll');
    var c = $('[data-close-drawer]', drawer);
    if (c) c.focus();
  }

  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    if (drawerScrim) drawerScrim.hidden = true;
    if (!modal || modal.hidden) doc.body.classList.remove('no-scroll');
  }

  function lineRow(line) {
    var p = L.productById(line.id);
    return '' +
      '<li class="line">' +
        '<div class="line__media">' + (p ? L.svgFor(p) : '') + '</div>' +
        '<div class="line__main">' +
          '<p class="line__name">' + escapeHtml(line.name) + '</p>' +
          (selectionText(line.selection) ? '<p class="line__opts">' + escapeHtml(selectionText(line.selection)) + '</p>' : '') +
          '<p class="line__price">' + money(line.price) + ' each</p>' +
          '<div class="stepper stepper--sm" role="group" aria-label="Quantity for ' + escapeHtml(line.name) + '">' +
            '<button type="button" class="stepper__btn" data-line-dec="' + line.key + '" aria-label="Decrease quantity">&minus;</button>' +
            '<span class="stepper__val">' + line.qty + '</span>' +
            '<button type="button" class="stepper__btn" data-line-inc="' + line.key + '" aria-label="Increase quantity">+</button>' +
          '</div>' +
        '</div>' +
        '<div class="line__end">' +
          '<span class="line__total">' + money(line.price * line.qty) + '</span>' +
          '<button type="button" class="line__remove" data-line-remove="' + line.key + '" aria-label="Remove ' + escapeHtml(line.name) + '">Remove</button>' +
        '</div>' +
      '</li>';
  }

  function renderDrawer() {
    if (!drawer) return;
    var items = cart.get();
    var listEl = $('[data-drawer-list]', drawer);
    var emptyEl = $('[data-drawer-empty]', drawer);
    var footEl = $('[data-drawer-foot]', drawer);
    var subEl = $('[data-drawer-subtotal]', drawer);
    if (items.length === 0) {
      if (listEl) listEl.innerHTML = '';
      if (emptyEl) emptyEl.hidden = false;
      if (footEl) footEl.hidden = true;
      return;
    }
    if (emptyEl) emptyEl.hidden = true;
    if (footEl) footEl.hidden = false;
    if (listEl) listEl.innerHTML = items.map(lineRow).join('');
    if (subEl) subEl.textContent = money(cart.subtotal());
  }

  function onLineAction(e) {
    var inc = e.target.closest('[data-line-inc]');
    var dec = e.target.closest('[data-line-dec]');
    var rem = e.target.closest('[data-line-remove]');
    if (inc) cart.changeQty(inc.getAttribute('data-line-inc'), 1);
    else if (dec) cart.changeQty(dec.getAttribute('data-line-dec'), -1);
    else if (rem) cart.remove(rem.getAttribute('data-line-remove'));
  }

  /* ------------------------------------------------------- shop catalog */
  function initShop() {
    var grid = $('#product-grid');
    if (!grid) return;

    var state = { category: 'all', maxPrice: null, sort: 'newest' };
    var catSel = $('#filter-category');
    var priceSel = $('#filter-price');
    var sortSel = $('#sort-by');
    var countEl = $('#result-count');

    function apply() {
      var list = (L.products || []).slice();
      if (state.category !== 'all') {
        list = list.filter(function (p) { return p.category === state.category; });
      }
      if (state.maxPrice != null) {
        list = list.filter(function (p) { return p.price <= state.maxPrice; });
      }
      switch (state.sort) {
        case 'price-asc': list.sort(function (a, b) { return a.price - b.price; }); break;
        case 'price-desc': list.sort(function (a, b) { return b.price - a.price; }); break;
        case 'name-asc': list.sort(function (a, b) { return a.name.localeCompare(b.name); }); break;
        default: list.sort(function (a, b) { return b.date - a.date; }); break;
      }
      grid.innerHTML = '';
      if (list.length === 0) {
        grid.appendChild(el('p', 'empty-note', 'No coffees match those filters. Try widening your search.'));
      } else {
        list.forEach(function (p) { grid.appendChild(productCard(p)); });
      }
      if (countEl) countEl.textContent = list.length + (list.length === 1 ? ' product' : ' products');
    }

    if (catSel) catSel.addEventListener('change', function () { state.category = catSel.value; apply(); });
    if (priceSel) priceSel.addEventListener('change', function () {
      state.maxPrice = priceSel.value === 'all' ? null : Number(priceSel.value);
      apply();
    });
    if (sortSel) sortSel.addEventListener('change', function () { state.sort = sortSel.value; apply(); });

    // Honor ?category= deep link from the home page.
    try {
      var q = new global.URLSearchParams(global.location.search).get('category');
      if (q && catSel) {
        var has = $all('option', catSel).some(function (o) { return o.value === q; });
        if (has) { catSel.value = q; state.category = q; }
      }
    } catch (e) { /* no-op */ }

    apply();
  }

  /* --------------------------------------------------- featured (home) */
  function initFeatured() {
    var row = $('#featured-grid');
    if (!row) return;
    var ids = ['ethiopia-yirgacheffe', 'lumen-house-blend', 'aurora-espresso', 'pour-over-dripper'];
    ids.forEach(function (id) {
      var p = L.productById(id);
      if (p) row.appendChild(productCard(p));
    });
  }

  /* ------------------------------------------------------- cart page */
  function initCartPage() {
    var wrap = $('#cart-page');
    if (!wrap) return;

    function render() {
      var items = cart.get();
      var listEl = $('[data-cartpage-list]', wrap);
      var emptyEl = $('[data-cartpage-empty]', wrap);
      var summaryEl = $('[data-cartpage-summary]', wrap);
      var subEl = $('[data-cartpage-subtotal]', wrap);
      var shipEl = $('[data-cartpage-shipping]', wrap);
      var totalEl = $('[data-cartpage-total]', wrap);

      if (items.length === 0) {
        if (listEl) listEl.innerHTML = '';
        if (emptyEl) emptyEl.hidden = false;
        if (summaryEl) summaryEl.hidden = true;
        return;
      }
      if (emptyEl) emptyEl.hidden = true;
      if (summaryEl) summaryEl.hidden = false;
      if (listEl) listEl.innerHTML = items.map(lineRow).join('');
      var sub = cart.subtotal();
      var ship = sub >= 45 || sub === 0 ? 0 : 6;
      if (subEl) subEl.textContent = money(sub);
      if (shipEl) shipEl.textContent = ship === 0 ? 'Free' : money(ship);
      if (totalEl) totalEl.textContent = money(sub + ship);
    }

    wrap.addEventListener('click', function (e) {
      onLineAction(e);
      if (e.target.closest('[data-cart-clear]')) cart.clear();
      if (e.target.closest('[data-cart-checkout]')) {
        e.preventDefault();
        global.alert('This is a demo storefront — checkout is not connected to a payment processor.');
      }
    });

    global.addEventListener(cart.EVENT, render);
    render();
  }

  /* ----------------------------------------------------- contact form */
  function initContactForm() {
    var form = $('#contact-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var status = $('#contact-status', form);
      if (status) {
        status.hidden = false;
        status.textContent = 'Thanks — your message has been received. We’ll be in touch within two business days.';
      }
      form.reset();
    });
  }

  function initNewsletter() {
    $all('[data-newsletter]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var status = form.querySelector('[data-newsletter-status]');
        if (status) {
          status.hidden = false;
          status.textContent = 'You’re on the list. Watch your inbox for fresh-crop releases.';
        }
        form.reset();
      });
    });
  }

  /* ------------------------------------------------ global delegation */
  function initGlobalClicks() {
    doc.addEventListener('click', function (e) {
      var detail = e.target.closest('[data-detail]');
      if (detail) { openModal(detail.getAttribute('data-detail')); return; }
      var quick = e.target.closest('[data-quickadd]');
      if (quick) {
        var p = L.productById(quick.getAttribute('data-quickadd'));
        if (p) { cart.add(p, L.defaultSelection(p), 1); openDrawer(); }
      }
    });
    doc.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { closeModal(); closeDrawer(); }
    });
  }

  /* ------------------------------------------------------------- boot */
  function boot() {
    if (!L.products || !cart) return; // products/cart scripts not loaded on this page
    updateBadge();
    ensureModal();
    ensureDrawer();
    initGlobalClicks();
    initFeatured();
    initShop();
    initCartPage();
    initContactForm();
    initNewsletter();

    global.addEventListener(cart.EVENT, function () {
      updateBadge();
      if (drawerOpen()) renderDrawer();
    });

    // mobile nav toggle
    var navToggle = $('#nav-toggle');
    var nav = $('#primary-nav');
    if (navToggle && nav) {
      navToggle.addEventListener('click', function () {
        var open = nav.classList.toggle('is-open');
        navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }

    // footer year
    $all('[data-year]').forEach(function (n) { n.textContent = new Date().getFullYear(); });
  }

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
