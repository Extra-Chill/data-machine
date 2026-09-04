/* Fernwood Botanicals — UI controller.
 * Renders product grids, runs filter/sort, builds the detail modal, the
 * mini-cart drawer, the cart page, and wires up the header badge.
 * All DOM access is guarded so each page only runs what it has. */

(function () {
  'use strict';

  var Data = window.FernwoodData;
  var Cart = window.FernwoodCart;

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function productById(id) {
    return Data.products.filter(function (p) { return p.id === id; })[0];
  }

  function variantPrice(product, label) {
    var base = product.price;
    for (var i = 0; i < product.variants.length; i++) {
      if (product.variants[i].label === label) return base + product.variants[i].delta;
    }
    return base;
  }

  function lightClass(light) {
    var l = (light || '').toLowerCase();
    if (l.indexOf('low') !== -1) return 'low';
    if (l.indexOf('full') !== -1 || l.indexOf('direct') !== -1) return 'high';
    return 'medium';
  }

  /* ---------------------------------------------------------------------------
   * Header badge (every page)
   * ------------------------------------------------------------------------- */
  function refreshBadges() {
    var n = Cart.count();
    $all('[data-cart-count]').forEach(function (el) {
      el.textContent = n;
      el.classList.toggle('is-empty', n === 0);
    });
  }

  /* ---------------------------------------------------------------------------
   * Product card
   * ------------------------------------------------------------------------- */
  function cardHTML(p) {
    return (
      '<article class="card" data-id="' + p.id + '">' +
      '<button class="card-media" data-detail="' + p.id + '" aria-label="View details for ' + p.name + '">' +
      Data.renderArt(p) +
      '<span class="card-cat">' + p.category + '</span>' +
      '</button>' +
      '<div class="card-body">' +
      '<h3 class="card-title">' + p.name + '</h3>' +
      '<p class="card-botanical">' + p.botanical + '</p>' +
      '<div class="card-meta">' +
      (p.light !== '—' ? '<span class="chip light-' + lightClass(p.light) + '">&#9728; ' + p.light + '</span>' : '') +
      (p.water !== '—' ? '<span class="chip water">&#128167; ' + p.water + '</span>' : '') +
      '</div>' +
      '<div class="card-foot">' +
      '<span class="price">' + Cart.money(p.price) + '</span>' +
      '<button class="btn btn-add" data-add="' + p.id + '">Add to cart</button>' +
      '</div>' +
      '</div>' +
      '</article>'
    );
  }

  function renderGrid(target, list) {
    if (!target) return;
    if (!list.length) {
      target.innerHTML = '<p class="empty-grid">No plants match your filters. Try widening your search.</p>';
      return;
    }
    target.innerHTML = list.map(cardHTML).join('');
  }

  /* ---------------------------------------------------------------------------
   * Detail modal
   * ------------------------------------------------------------------------- */
  var modal, modalState = { id: null, variant: null, qty: 1 };

  function ensureModal() {
    if (modal) return modal;
    modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.setAttribute('hidden', '');
    modal.innerHTML =
      '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">' +
      '<button class="modal-close" data-close aria-label="Close details">&times;</button>' +
      '<div class="modal-grid">' +
      '<div class="modal-media" data-modal-media></div>' +
      '<div class="modal-info">' +
      '<span class="modal-cat" data-modal-cat></span>' +
      '<h2 id="modal-title" data-modal-title></h2>' +
      '<p class="modal-botanical" data-modal-botanical></p>' +
      '<p class="modal-desc" data-modal-desc></p>' +
      '<div class="modal-care" data-modal-care></div>' +
      '<div class="modal-variant">' +
      '<label for="variant-select" data-modal-variant-label>Size</label>' +
      '<select id="variant-select" data-modal-variant></select>' +
      '</div>' +
      '<div class="modal-buy">' +
      '<div class="stepper" role="group" aria-label="Quantity">' +
      '<button class="step" data-step="-1" aria-label="Decrease quantity">&minus;</button>' +
      '<span class="step-val" data-modal-qty aria-live="polite">1</span>' +
      '<button class="step" data-step="1" aria-label="Increase quantity">+</button>' +
      '</div>' +
      '<button class="btn btn-primary modal-addbtn" data-modal-add>Add to cart &middot; <span data-modal-price></span></button>' +
      '</div>' +
      '</div>' +
      '</div>' +
      '</div>';
    document.body.appendChild(modal);

    modal.addEventListener('click', function (e) {
      if (e.target === modal || e.target.hasAttribute('data-close')) closeModal();
      var step = e.target.closest('[data-step]');
      if (step) {
        modalState.qty = Math.max(1, modalState.qty + parseInt(step.getAttribute('data-step'), 10));
        $('[data-modal-qty]', modal).textContent = modalState.qty;
        updateModalPrice();
      }
      if (e.target.closest('[data-modal-add]')) {
        var p = productById(modalState.id);
        Cart.add(p, modalState.variant, variantPrice(p, modalState.variant), modalState.qty);
        closeModal();
        openDrawer();
      }
    });

    $('[data-modal-variant]', modal).addEventListener('change', function (e) {
      modalState.variant = e.target.value;
      updateModalPrice();
    });

    return modal;
  }

  function updateModalPrice() {
    var p = productById(modalState.id);
    var unit = variantPrice(p, modalState.variant);
    $('[data-modal-price]', modal).textContent = Cart.money(unit * modalState.qty);
  }

  function openModal(id) {
    ensureModal();
    var p = productById(id);
    if (!p) return;
    modalState = { id: id, variant: p.variants[0].label, qty: 1 };
    $('[data-modal-media]', modal).innerHTML = Data.renderArt(p);
    $('[data-modal-cat]', modal).textContent = p.category;
    $('[data-modal-title]', modal).textContent = p.name;
    $('[data-modal-botanical]', modal).textContent = p.botanical;
    $('[data-modal-desc]', modal).textContent = p.desc;

    var care = '';
    if (p.light !== '—') care += '<div class="care-item"><span class="care-ico">&#9728;</span><div><strong>Light</strong><span>' + p.light + '</span></div></div>';
    if (p.water !== '—') care += '<div class="care-item"><span class="care-ico">&#128167;</span><div><strong>Water</strong><span>' + p.water + '</span></div></div>';
    $('[data-modal-care]', modal).innerHTML = care;

    $('[data-modal-variant-label]', modal).textContent = p.variantType === 'color' ? 'Pot color' : 'Pot size';
    $('[data-modal-variant]', modal).innerHTML = p.variants.map(function (v) {
      var extra = v.delta ? ' (+' + Cart.money(v.delta).replace('$', '$') + ')' : '';
      return '<option value="' + v.label + '">' + v.label + extra + '</option>';
    }).join('');
    $('[data-modal-qty]', modal).textContent = '1';
    updateModalPrice();

    modal.removeAttribute('hidden');
    document.body.classList.add('no-scroll');
    var closeBtn = $('[data-close]', modal);
    if (closeBtn) closeBtn.focus();
  }

  function closeModal() {
    if (modal) modal.setAttribute('hidden', '');
    if (!drawerOpen()) document.body.classList.remove('no-scroll');
  }

  /* ---------------------------------------------------------------------------
   * Mini-cart drawer
   * ------------------------------------------------------------------------- */
  var drawer;

  function ensureDrawer() {
    if (drawer) return drawer;
    drawer = document.createElement('div');
    drawer.className = 'drawer-overlay';
    drawer.setAttribute('hidden', '');
    drawer.innerHTML =
      '<aside class="drawer" role="dialog" aria-modal="true" aria-label="Shopping cart">' +
      '<header class="drawer-head">' +
      '<h2>Your Cart</h2>' +
      '<button class="drawer-close" data-drawer-close aria-label="Close cart">&times;</button>' +
      '</header>' +
      '<div class="drawer-body" data-drawer-body></div>' +
      '<footer class="drawer-foot">' +
      '<div class="drawer-subtotal"><span>Subtotal</span><span data-drawer-subtotal>$0.00</span></div>' +
      '<a class="btn btn-outline" href="cart.html">View cart</a>' +
      '<button class="btn btn-primary" data-checkout>Checkout</button>' +
      '</footer>' +
      '</aside>';
    document.body.appendChild(drawer);

    drawer.addEventListener('click', function (e) {
      if (e.target === drawer || e.target.hasAttribute('data-drawer-close')) closeDrawer();
      var dec = e.target.closest('[data-d-dec]');
      var inc = e.target.closest('[data-d-inc]');
      var rem = e.target.closest('[data-d-remove]');
      if (dec) Cart.changeQty(dec.dataset.id, dec.dataset.variant, -1);
      if (inc) Cart.changeQty(inc.dataset.id, inc.dataset.variant, 1);
      if (rem) Cart.remove(rem.dataset.id, rem.dataset.variant);
      if (e.target.closest('[data-checkout]')) {
        alert('Checkout is a demo in this static shop. Thanks for browsing Fernwood Botanicals!');
      }
    });
    return drawer;
  }

  function drawerOpen() { return drawer && !drawer.hasAttribute('hidden'); }

  function renderDrawer() {
    if (!drawer) return;
    var body = $('[data-drawer-body]', drawer);
    var list = Cart.items();
    if (!list.length) {
      body.innerHTML = '<p class="drawer-empty">Your cart is empty.<br>Time to add some green.</p>';
    } else {
      body.innerHTML = list.map(function (it) {
        var p = productById(it.id);
        return (
          '<div class="drawer-line">' +
          '<div class="drawer-thumb">' + (p ? Data.renderArt(p) : '') + '</div>' +
          '<div class="drawer-line-info">' +
          '<strong>' + it.name + '</strong>' +
          (it.variant ? '<span class="drawer-variant">' + it.variant + '</span>' : '') +
          '<div class="stepper sm" role="group" aria-label="Quantity for ' + it.name + '">' +
          '<button class="step" data-d-dec data-id="' + it.id + '" data-variant="' + it.variant + '" aria-label="Decrease">&minus;</button>' +
          '<span class="step-val">' + it.qty + '</span>' +
          '<button class="step" data-d-inc data-id="' + it.id + '" data-variant="' + it.variant + '" aria-label="Increase">+</button>' +
          '</div>' +
          '</div>' +
          '<div class="drawer-line-end">' +
          '<span class="price">' + Cart.money(it.price * it.qty) + '</span>' +
          '<button class="link-remove" data-d-remove data-id="' + it.id + '" data-variant="' + it.variant + '" aria-label="Remove ' + it.name + '">Remove</button>' +
          '</div>' +
          '</div>'
        );
      }).join('');
    }
    $('[data-drawer-subtotal]', drawer).textContent = Cart.money(Cart.subtotal());
  }

  function openDrawer() {
    ensureDrawer();
    renderDrawer();
    drawer.removeAttribute('hidden');
    document.body.classList.add('no-scroll');
    var c = $('[data-drawer-close]', drawer);
    if (c) c.focus();
  }

  function closeDrawer() {
    if (drawer) drawer.setAttribute('hidden', '');
    if (!modal || modal.hasAttribute('hidden')) document.body.classList.remove('no-scroll');
  }

  /* ---------------------------------------------------------------------------
   * Global delegated clicks (add buttons, detail buttons, cart toggle)
   * ------------------------------------------------------------------------- */
  function wireGlobal() {
    document.addEventListener('click', function (e) {
      var add = e.target.closest('[data-add]');
      if (add) {
        var p = productById(add.getAttribute('data-add'));
        if (p) {
          Cart.add(p, p.variants[0].label, p.price, 1);
          openDrawer();
        }
        return;
      }
      var det = e.target.closest('[data-detail]');
      if (det) { openModal(det.getAttribute('data-detail')); return; }

      if (e.target.closest('[data-open-cart]')) { openDrawer(); }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (drawerOpen()) closeDrawer();
        else if (modal && !modal.hasAttribute('hidden')) closeModal();
      }
    });
  }

  /* ---------------------------------------------------------------------------
   * Mobile nav toggle
   * ------------------------------------------------------------------------- */
  function wireNav() {
    var toggle = $('[data-nav-toggle]');
    var nav = $('[data-nav]');
    if (!toggle || !nav) return;
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* ---------------------------------------------------------------------------
   * Shop page: filters + sort
   * ------------------------------------------------------------------------- */
  function initShop() {
    var grid = $('[data-shop-grid]');
    if (!grid) return;

    var state = { category: 'all', light: 'all', maxPrice: 100, sort: 'featured' };
    var countEl = $('[data-result-count]');
    var priceOut = $('[data-price-out]');

    // populate category chips
    var catWrap = $('[data-cat-filters]');
    if (catWrap) {
      // preselect from ?category=
      var params = new URLSearchParams(window.location.search);
      var pre = params.get('category');
      if (pre && Data.categories.indexOf(pre) !== -1) state.category = pre;
      catWrap.addEventListener('click', function (e) {
        var b = e.target.closest('[data-cat]');
        if (!b) return;
        state.category = b.getAttribute('data-cat');
        $all('[data-cat]', catWrap).forEach(function (x) { x.classList.toggle('active', x === b); });
        apply();
      });
      $all('[data-cat]', catWrap).forEach(function (x) {
        x.classList.toggle('active', x.getAttribute('data-cat') === state.category);
      });
    }

    var lightSel = $('[data-light-filter]');
    if (lightSel) lightSel.addEventListener('change', function () { state.light = lightSel.value; apply(); });

    var priceInput = $('[data-price-filter]');
    if (priceInput) {
      priceInput.addEventListener('input', function () {
        state.maxPrice = parseInt(priceInput.value, 10);
        if (priceOut) priceOut.textContent = '$' + state.maxPrice;
        apply();
      });
    }

    var sortSel = $('[data-sort]');
    if (sortSel) sortSel.addEventListener('change', function () { state.sort = sortSel.value; apply(); });

    function apply() {
      var list = Data.products.filter(function (p) {
        if (state.category !== 'all' && p.category !== state.category) return false;
        if (p.price > state.maxPrice) return false;
        if (state.light !== 'all') {
          if (p.light === '—') return false;
          if (lightClass(p.light) !== state.light) return false;
        }
        return true;
      });

      switch (state.sort) {
        case 'price-asc': list.sort(function (a, b) { return a.price - b.price; }); break;
        case 'price-desc': list.sort(function (a, b) { return b.price - a.price; }); break;
        case 'name': list.sort(function (a, b) { return a.name.localeCompare(b.name); }); break;
        case 'newest': list.sort(function (a, b) { return b.added - a.added; }); break;
        default: break; // featured = data order
      }

      renderGrid(grid, list);
      if (countEl) countEl.textContent = list.length + (list.length === 1 ? ' plant' : ' plants');
    }

    apply();
  }

  /* ---------------------------------------------------------------------------
   * Home page featured rows
   * ------------------------------------------------------------------------- */
  function initHome() {
    var featured = $('[data-featured-grid]');
    if (featured) {
      // "easy-care picks" = low/medium maintenance favorites
      var picks = ['snake', 'pothos', 'zz', 'echeveria'].map(productById).filter(Boolean);
      renderGrid(featured, picks);
    }
  }

  /* ---------------------------------------------------------------------------
   * Cart page
   * ------------------------------------------------------------------------- */
  function initCartPage() {
    var wrap = $('[data-cart-page]');
    if (!wrap) return;

    function render() {
      var list = Cart.items();
      var linesEl = $('[data-cart-lines]', wrap);
      var emptyEl = $('[data-cart-empty]', wrap);
      var summaryEl = $('[data-cart-summary]', wrap);

      if (!list.length) {
        if (linesEl) linesEl.innerHTML = '';
        if (emptyEl) emptyEl.hidden = false;
        if (summaryEl) summaryEl.hidden = true;
        return;
      }
      if (emptyEl) emptyEl.hidden = true;
      if (summaryEl) summaryEl.hidden = false;

      linesEl.innerHTML = list.map(function (it) {
        var p = productById(it.id);
        return (
          '<div class="cartrow">' +
          '<div class="cartrow-media">' + (p ? Data.renderArt(p) : '') + '</div>' +
          '<div class="cartrow-main">' +
          '<h3>' + it.name + '</h3>' +
          (it.variant ? '<p class="cartrow-variant">' + it.variant + '</p>' : '') +
          '<button class="link-remove" data-c-remove data-id="' + it.id + '" data-variant="' + it.variant + '">Remove</button>' +
          '</div>' +
          '<div class="cartrow-qty">' +
          '<div class="stepper" role="group" aria-label="Quantity for ' + it.name + '">' +
          '<button class="step" data-c-dec data-id="' + it.id + '" data-variant="' + it.variant + '" aria-label="Decrease">&minus;</button>' +
          '<span class="step-val">' + it.qty + '</span>' +
          '<button class="step" data-c-inc data-id="' + it.id + '" data-variant="' + it.variant + '" aria-label="Increase">+</button>' +
          '</div>' +
          '</div>' +
          '<div class="cartrow-price">' + Cart.money(it.price * it.qty) + '</div>' +
          '</div>'
        );
      }).join('');

      var sub = Cart.subtotal();
      var shipping = sub >= 75 || sub === 0 ? 0 : 8.5;
      $('[data-sum-subtotal]', wrap).textContent = Cart.money(sub);
      $('[data-sum-shipping]', wrap).textContent = shipping === 0 ? 'Free' : Cart.money(shipping);
      $('[data-sum-total]', wrap).textContent = Cart.money(sub + shipping);
    }

    wrap.addEventListener('click', function (e) {
      var dec = e.target.closest('[data-c-dec]');
      var inc = e.target.closest('[data-c-inc]');
      var rem = e.target.closest('[data-c-remove]');
      if (dec) Cart.changeQty(dec.dataset.id, dec.dataset.variant, -1);
      if (inc) Cart.changeQty(inc.dataset.id, inc.dataset.variant, 1);
      if (rem) Cart.remove(rem.dataset.id, rem.dataset.variant);
      if (e.target.closest('[data-c-checkout]')) {
        alert('Checkout is a demo in this static shop. Thanks for browsing Fernwood Botanicals!');
      }
    });

    Cart.subscribe(render);
    render();
  }

  /* ---------------------------------------------------------------------------
   * Contact form (demo)
   * ------------------------------------------------------------------------- */
  function initContact() {
    var form = $('[data-contact-form]');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var note = $('[data-form-note]', form);
      if (note) {
        note.hidden = false;
        note.textContent = 'Thanks for reaching out! A grower will reply within two business days.';
      }
      form.reset();
    });
  }

  /* ---------------------------------------------------------------------------
   * Newsletter (demo)
   * ------------------------------------------------------------------------- */
  function initNewsletter() {
    $all('[data-newsletter]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var note = $('[data-news-note]', form);
        if (note) { note.hidden = false; note.textContent = 'You’re on the list — welcome to the greenhouse!'; }
        form.reset();
      });
    });
  }

  /* ---------------------------------------------------------------------------
   * Boot
   * ------------------------------------------------------------------------- */
  document.addEventListener('DOMContentLoaded', function () {
    wireGlobal();
    wireNav();
    Cart.subscribe(function () { refreshBadges(); renderDrawer(); });
    refreshBadges();

    initHome();
    initShop();
    initCartPage();
    initContact();
    initNewsletter();

    // stamp footer year
    $all('[data-year]').forEach(function (el) { el.textContent = new Date().getFullYear(); });
  });
})();
