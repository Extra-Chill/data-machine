/* SOLE SOCIETY — app.js
 * Rendering, SVG sneaker generator, catalog filter/sort, product modal,
 * mini-cart drawer, and full cart-page rendering. All page-guarded.
 */
(function () {
  'use strict';

  var SIZES = ['7', '7.5', '8', '8.5', '9', '9.5', '10', '10.5', '11', '11.5', '12', '12.5', '13'];

  function money(n) {
    return '$' + Number(n).toFixed(2);
  }
  function moneyShort(n) {
    return '$' + Number(n).toFixed(0);
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function byId(id) {
    var list = window.SOLE_PRODUCTS || [];
    for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i];
    return null;
  }

  /* ---------------------------------------------------------------------------
   * Inline SVG sneaker — a stylized side profile driven by a colorway palette.
   * Returns markup for a 400x240 viewBox; scales to any container.
   * ------------------------------------------------------------------------ */
  function sneakerSVG(colorway, opts) {
    opts = opts || {};
    var c = colorway || {};
    var upper = c.upper || '#222';
    var accent = c.accent || '#c6ff00';
    var sole = c.sole || '#eee';
    var midsole = c.midsole || upper;
    var lace = c.lace || accent;
    var detail = c.detail || '#111';
    var uid = 'g' + Math.random().toString(36).slice(2, 8);
    var title = opts.title ? '<title>' + esc(opts.title) + '</title>' : '';

    return (
      '<svg class="shoe-svg" viewBox="0 0 400 240" role="img" aria-hidden="' +
      (opts.title ? 'false' : 'true') +
      '" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">' +
      title +
      '<defs>' +
      '<linearGradient id="' + uid + 'u" x1="0" y1="0" x2="0" y2="1">' +
      '<stop offset="0" stop-color="' + upper + '"/>' +
      '<stop offset="1" stop-color="' + shade(upper, -18) + '"/>' +
      '</linearGradient>' +
      '<linearGradient id="' + uid + 's" x1="0" y1="0" x2="0" y2="1">' +
      '<stop offset="0" stop-color="' + sole + '"/>' +
      '<stop offset="1" stop-color="' + shade(sole, -22) + '"/>' +
      '</linearGradient>' +
      '</defs>' +
      /* sole */
      '<path d="M22 188 Q14 168 40 162 L360 150 Q392 150 388 176 L384 196 Q382 206 368 206 L52 206 Q26 206 22 188 Z" fill="url(#' + uid + 's)" stroke="' + detail + '" stroke-width="2"/>' +
      /* midsole stripe */
      '<path d="M30 178 L380 167" fill="none" stroke="' + accent + '" stroke-width="4" stroke-linecap="round" opacity="0.85"/>' +
      /* main upper body */
      '<path d="M44 162 Q44 120 92 110 L150 104 Q176 78 214 84 L250 110 Q300 116 340 126 Q372 132 360 152 L40 164 Q40 163 44 162 Z" fill="url(#' + uid + 'u)" stroke="' + detail + '" stroke-width="2.5"/>' +
      /* toe cap */
      '<path d="M44 162 Q44 130 86 120 L118 116 Q120 150 116 160 L46 163 Q44 163 44 162 Z" fill="' + shade(upper, -10) + '" stroke="' + detail + '" stroke-width="1.5" opacity="0.9"/>' +
      /* heel counter */
      '<path d="M312 130 Q352 132 358 150 L334 154 Q322 140 308 136 Z" fill="' + midsole + '" stroke="' + detail + '" stroke-width="1.5"/>' +
      /* accent swoosh-style side panel */
      '<path d="M150 156 Q210 120 300 130 Q250 150 168 158 Z" fill="' + accent + '" stroke="' + shade(accent, -25) + '" stroke-width="1.5"/>' +
      /* collar / ankle padding */
      '<path d="M214 84 Q236 86 250 110 L236 120 Q220 100 206 100 Z" fill="' + shade(upper, 14) + '" stroke="' + detail + '" stroke-width="1.5"/>' +
      /* tongue */
      '<path d="M150 104 L162 132 L150 138 L138 118 Z" fill="' + shade(upper, 18) + '" stroke="' + detail + '" stroke-width="1.5"/>' +
      /* laces */
      lacePath(150, 118, accent === lace ? lace : lace, 5) +
      '<g stroke="' + lace + '" stroke-width="3.5" stroke-linecap="round">' +
      '<line x1="158" y1="116" x2="186" y2="124"/>' +
      '<line x1="162" y1="126" x2="190" y2="134"/>' +
      '<line x1="168" y1="136" x2="196" y2="144"/>' +
      '</g>' +
      /* eyelets */
      '<g fill="' + detail + '">' +
      '<circle cx="186" cy="124" r="2.4"/><circle cx="190" cy="134" r="2.4"/><circle cx="196" cy="144" r="2.4"/>' +
      '<circle cx="158" cy="116" r="2.4"/><circle cx="162" cy="126" r="2.4"/><circle cx="168" cy="136" r="2.4"/>' +
      '</g>' +
      /* outsole tread ticks */
      treadTicks(detail) +
      '</svg>'
    );
  }

  function lacePath() {
    return '';
  }

  function treadTicks(color) {
    var s = '<g stroke="' + color + '" stroke-width="1.6" opacity="0.55">';
    for (var x = 60; x < 360; x += 26) {
      s += '<line x1="' + x + '" y1="198" x2="' + (x + 2) + '" y2="206"/>';
    }
    return s + '</g>';
  }

  /* Lighten/darken a hex color by a percent (-100..100). */
  function shade(hex, percent) {
    var h = String(hex).replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    var r = parseInt(h.substring(0, 2), 16);
    var g = parseInt(h.substring(2, 4), 16);
    var b = parseInt(h.substring(4, 6), 16);
    var t = percent < 0 ? 0 : 255;
    var p = Math.abs(percent) / 100;
    r = Math.round((t - r) * p) + r;
    g = Math.round((t - g) * p) + g;
    b = Math.round((t - b) * p) + b;
    return '#' + [r, g, b].map(function (v) {
      var s = Math.max(0, Math.min(255, v)).toString(16);
      return s.length === 1 ? '0' + s : s;
    }).join('');
  }

  /* ---------------------------------------------------------------------------
   * Product card markup
   * ------------------------------------------------------------------------ */
  function cardHTML(p) {
    return (
      '<article class="card" data-id="' + p.id + '">' +
      '<button class="card-media" data-open="' + p.id + '" aria-label="View ' + esc(p.name) + '">' +
      '<span class="card-tag">' + esc(p.category) + '</span>' +
      sneakerSVG(p.colorway) +
      '</button>' +
      '<div class="card-body">' +
      '<p class="card-maker">' + esc(p.maker) + '</p>' +
      '<h3 class="card-name"><button class="linkish" data-open="' + p.id + '">' + esc(p.name) + '</button></h3>' +
      '<div class="card-foot">' +
      '<span class="card-price">' + money(p.price) + '</span>' +
      '<button class="btn btn-sm btn-accent" data-quickadd="' + p.id + '">Add</button>' +
      '</div>' +
      '</div>' +
      '</article>'
    );
  }

  /* ---------------------------------------------------------------------------
   * Catalog (shop.html) — filter + sort + live render
   * ------------------------------------------------------------------------ */
  function initCatalog() {
    var grid = document.getElementById('catalog-grid');
    if (!grid) return;
    var all = (window.SOLE_PRODUCTS || []).slice();

    var catSel = document.getElementById('filter-category');
    var priceSel = document.getElementById('filter-price');
    var sortSel = document.getElementById('sort-by');
    var countEl = document.getElementById('result-count');

    /* Optionally seed category from ?category= */
    var qs = new URLSearchParams(window.location.search);
    var preCat = qs.get('category');
    if (preCat && catSel) {
      for (var i = 0; i < catSel.options.length; i++) {
        if (catSel.options[i].value.toLowerCase() === preCat.toLowerCase()) {
          catSel.value = catSel.options[i].value;
        }
      }
    }

    function priceMatch(p, band) {
      if (band === 'all' || !band) return true;
      if (band === 'under100') return p.price < 100;
      if (band === '100-175') return p.price >= 100 && p.price <= 175;
      if (band === 'over175') return p.price > 175;
      return true;
    }

    function apply() {
      var cat = catSel ? catSel.value : 'all';
      var band = priceSel ? priceSel.value : 'all';
      var sort = sortSel ? sortSel.value : 'newest';

      var rows = all.filter(function (p) {
        var catOk = cat === 'all' || p.category === cat;
        return catOk && priceMatch(p, band);
      });

      rows.sort(function (a, b) {
        switch (sort) {
          case 'price-asc': return a.price - b.price;
          case 'price-desc': return b.price - a.price;
          case 'name-asc': return a.name.localeCompare(b.name);
          case 'newest':
          default: return b.drop - a.drop;
        }
      });

      grid.innerHTML = rows.length
        ? rows.map(cardHTML).join('')
        : '<p class="empty-note">No kicks match those filters. Try widening your search.</p>';

      if (countEl) {
        countEl.textContent = rows.length + (rows.length === 1 ? ' pair' : ' pairs');
      }
    }

    [catSel, priceSel, sortSel].forEach(function (el) {
      if (el) el.addEventListener('change', apply);
    });

    apply();
  }

  /* ---------------------------------------------------------------------------
   * Featured rows (index.html)
   * ------------------------------------------------------------------------ */
  function initFeatured() {
    var dropsEl = document.getElementById('latest-drops');
    if (dropsEl) {
      var byDrop = (window.SOLE_PRODUCTS || []).slice().sort(function (a, b) {
        return b.drop - a.drop;
      }).slice(0, 4);
      dropsEl.innerHTML = byDrop.map(cardHTML).join('');
    }
  }

  /* ---------------------------------------------------------------------------
   * Product detail modal
   * ------------------------------------------------------------------------ */
  var modal = {
    el: null, product: null, size: null, color: null, qty: 1, lastFocus: null
  };

  function buildModalShell() {
    if (document.getElementById('product-modal')) return;
    var wrap = document.createElement('div');
    wrap.id = 'product-modal';
    wrap.className = 'modal';
    wrap.setAttribute('aria-hidden', 'true');
    wrap.innerHTML =
      '<div class="modal-backdrop" data-close-modal></div>' +
      '<div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="modal-title">' +
      '<button class="modal-close" data-close-modal aria-label="Close product details">&times;</button>' +
      '<div class="modal-grid">' +
      '<div class="modal-media" id="modal-media"></div>' +
      '<div class="modal-info">' +
      '<p class="modal-maker" id="modal-maker"></p>' +
      '<h2 class="modal-title" id="modal-title"></h2>' +
      '<p class="modal-price" id="modal-price"></p>' +
      '<p class="modal-desc" id="modal-desc"></p>' +
      '<div class="field"><span class="field-label">Color</span><div class="chip-row" id="modal-colors"></div></div>' +
      '<div class="field"><span class="field-label">Size <span class="req">US</span></span><div class="size-grid" id="modal-sizes"></div>' +
      '<p class="size-prompt" id="size-prompt" hidden>Pick your size to continue.</p></div>' +
      '<div class="field qty-field"><span class="field-label">Quantity</span>' +
      '<div class="stepper">' +
      '<button class="step-btn" id="modal-qminus" aria-label="Decrease quantity">&minus;</button>' +
      '<span class="step-val" id="modal-qty" aria-live="polite">1</span>' +
      '<button class="step-btn" id="modal-qplus" aria-label="Increase quantity">+</button>' +
      '</div></div>' +
      '<button class="btn btn-accent btn-block" id="modal-add">Add to cart</button>' +
      '</div></div></div>';
    document.body.appendChild(wrap);
    modal.el = wrap;

    wrap.addEventListener('click', function (e) {
      if (e.target.hasAttribute('data-close-modal')) closeModal();
    });
    document.getElementById('modal-qminus').addEventListener('click', function () { setQty(modal.qty - 1); });
    document.getElementById('modal-qplus').addEventListener('click', function () { setQty(modal.qty + 1); });
    document.getElementById('modal-add').addEventListener('click', addFromModal);
  }

  function setQty(n) {
    modal.qty = Math.max(1, Math.min(20, n));
    var el = document.getElementById('modal-qty');
    if (el) el.textContent = modal.qty;
  }

  function openModal(id) {
    var p = byId(id);
    if (!p) return;
    buildModalShell();
    modal.product = p;
    modal.size = null;
    modal.color = (p.colors && p.colors[0]) || '';
    modal.qty = 1;
    modal.lastFocus = document.activeElement;

    document.getElementById('modal-media').innerHTML = sneakerSVG(p.colorway, { title: p.name });
    document.getElementById('modal-maker').textContent = p.maker;
    document.getElementById('modal-title').textContent = p.name;
    document.getElementById('modal-price').textContent = money(p.price);
    document.getElementById('modal-desc').textContent = p.description;
    document.getElementById('modal-qty').textContent = '1';

    /* colors */
    var colorsEl = document.getElementById('modal-colors');
    colorsEl.innerHTML = (p.colors || []).map(function (col, i) {
      return '<button class="chip' + (i === 0 ? ' is-active' : '') + '" data-color="' + esc(col) + '">' + esc(col) + '</button>';
    }).join('');
    colorsEl.querySelectorAll('.chip').forEach(function (btn) {
      btn.addEventListener('click', function () {
        modal.color = btn.getAttribute('data-color');
        colorsEl.querySelectorAll('.chip').forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
      });
    });

    /* sizes */
    var sizesEl = document.getElementById('modal-sizes');
    sizesEl.innerHTML = SIZES.map(function (s) {
      return '<button class="size-btn" data-size="' + s + '">' + s + '</button>';
    }).join('');
    sizesEl.querySelectorAll('.size-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        modal.size = btn.getAttribute('data-size');
        sizesEl.querySelectorAll('.size-btn').forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        document.getElementById('size-prompt').hidden = true;
      });
    });
    document.getElementById('size-prompt').hidden = true;

    modal.el.setAttribute('aria-hidden', 'false');
    document.body.classList.add('no-scroll');
    var closeBtn = modal.el.querySelector('.modal-close');
    if (closeBtn) closeBtn.focus();
  }

  function closeModal() {
    if (!modal.el) return;
    modal.el.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('no-scroll');
    if (modal.lastFocus && modal.lastFocus.focus) modal.lastFocus.focus();
  }

  function addFromModal() {
    if (!modal.product) return;
    if (!modal.size) {
      var prompt = document.getElementById('size-prompt');
      if (prompt) prompt.hidden = false;
      var grid = document.getElementById('modal-sizes');
      if (grid) {
        grid.classList.remove('shake');
        void grid.offsetWidth; /* reflow to restart animation */
        grid.classList.add('shake');
      }
      return;
    }
    window.SoleCart.add(modal.product.id, modal.size, modal.color, modal.qty);
    closeModal();
    openDrawer();
    flashAdded();
  }

  /* ---------------------------------------------------------------------------
   * Mini-cart drawer
   * ------------------------------------------------------------------------ */
  function buildDrawerShell() {
    if (document.getElementById('cart-drawer')) return;
    var wrap = document.createElement('div');
    wrap.id = 'cart-drawer';
    wrap.className = 'drawer';
    wrap.setAttribute('aria-hidden', 'true');
    wrap.innerHTML =
      '<div class="drawer-backdrop" data-close-drawer></div>' +
      '<aside class="drawer-panel" role="dialog" aria-modal="true" aria-label="Shopping cart">' +
      '<header class="drawer-head">' +
      '<h2 class="drawer-title">Your Bag</h2>' +
      '<button class="drawer-close" data-close-drawer aria-label="Close cart">&times;</button>' +
      '</header>' +
      '<div class="drawer-items" id="drawer-items"></div>' +
      '<footer class="drawer-foot">' +
      '<div class="drawer-subtotal"><span>Subtotal</span><span id="drawer-subtotal">$0.00</span></div>' +
      '<a class="btn btn-block btn-outline" href="cart.html">View full cart</a>' +
      '<button class="btn btn-block btn-accent" id="drawer-checkout">Checkout</button>' +
      '</footer>' +
      '</aside>';
    document.body.appendChild(wrap);

    wrap.addEventListener('click', function (e) {
      if (e.target.hasAttribute('data-close-drawer')) closeDrawer();
    });
    document.getElementById('drawer-checkout').addEventListener('click', function () {
      alert('This is a demo store — checkout is not wired to a payment provider.');
    });
  }

  function renderDrawer() {
    var box = document.getElementById('drawer-items');
    if (!box) return;
    var items = window.SoleCart.items();
    if (!items.length) {
      box.innerHTML = '<p class="empty-note">Your bag is empty. Go cop something.</p>';
    } else {
      box.innerHTML = items.map(function (it) {
        var p = byId(it.id);
        var key = window.SoleCart.keyFor(it);
        return (
          '<div class="line">' +
          '<div class="line-media">' + (p ? sneakerSVG(p.colorway) : '') + '</div>' +
          '<div class="line-info">' +
          '<p class="line-name">' + esc(it.name) + '</p>' +
          '<p class="line-meta">Size ' + esc(it.size) + ' &middot; ' + esc(it.color) + '</p>' +
          '<div class="line-controls">' +
          '<div class="stepper stepper-sm">' +
          '<button class="step-btn" data-dec="' + key + '" aria-label="Decrease quantity">&minus;</button>' +
          '<span class="step-val">' + it.qty + '</span>' +
          '<button class="step-btn" data-inc="' + key + '" aria-label="Increase quantity">+</button>' +
          '</div>' +
          '<button class="line-remove" data-remove="' + key + '" aria-label="Remove ' + esc(it.name) + '">Remove</button>' +
          '</div>' +
          '</div>' +
          '<div class="line-price">' + money(it.price * it.qty) + '</div>' +
          '</div>'
        );
      }).join('');
    }
    var sub = document.getElementById('drawer-subtotal');
    if (sub) sub.textContent = money(window.SoleCart.subtotal());
  }

  function openDrawer() {
    buildDrawerShell();
    renderDrawer();
    var d = document.getElementById('cart-drawer');
    d.setAttribute('aria-hidden', 'false');
    document.body.classList.add('no-scroll');
    var c = d.querySelector('.drawer-close');
    if (c) c.focus();
  }
  function closeDrawer() {
    var d = document.getElementById('cart-drawer');
    if (!d) return;
    d.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('no-scroll');
  }

  function flashAdded() {
    var btns = document.querySelectorAll('[data-cart-toggle]');
    btns.forEach(function (b) {
      b.classList.remove('pulse');
      void b.offsetWidth;
      b.classList.add('pulse');
    });
  }

  /* ---------------------------------------------------------------------------
   * Full cart page (cart.html)
   * ------------------------------------------------------------------------ */
  function renderCartPage() {
    var root = document.getElementById('cart-page');
    if (!root) return;
    var items = window.SoleCart.items();
    var listEl = document.getElementById('cart-lines');
    var summaryEl = document.getElementById('cart-summary');
    var emptyEl = document.getElementById('cart-empty');

    if (!items.length) {
      if (listEl) listEl.innerHTML = '';
      if (summaryEl) summaryEl.hidden = true;
      if (emptyEl) emptyEl.hidden = false;
      return;
    }
    if (emptyEl) emptyEl.hidden = true;
    if (summaryEl) summaryEl.hidden = false;

    listEl.innerHTML = items.map(function (it) {
      var p = byId(it.id);
      var key = window.SoleCart.keyFor(it);
      return (
        '<div class="cart-row">' +
        '<div class="cart-row-media">' + (p ? sneakerSVG(p.colorway) : '') + '</div>' +
        '<div class="cart-row-main">' +
        '<p class="cart-row-maker">' + esc(it.maker) + '</p>' +
        '<h3 class="cart-row-name">' + esc(it.name) + '</h3>' +
        '<p class="cart-row-meta">Size US ' + esc(it.size) + ' &middot; ' + esc(it.color) + '</p>' +
        '<button class="line-remove" data-remove="' + key + '">Remove</button>' +
        '</div>' +
        '<div class="cart-row-qty">' +
        '<div class="stepper">' +
        '<button class="step-btn" data-dec="' + key + '" aria-label="Decrease quantity">&minus;</button>' +
        '<span class="step-val">' + it.qty + '</span>' +
        '<button class="step-btn" data-inc="' + key + '" aria-label="Increase quantity">+</button>' +
        '</div>' +
        '</div>' +
        '<div class="cart-row-price">' + money(it.price * it.qty) + '</div>' +
        '</div>'
      );
    }).join('');

    var sub = window.SoleCart.subtotal();
    var ship = sub >= 150 || sub === 0 ? 0 : 12;
    var tax = +(sub * 0.08).toFixed(2);
    var total = +(sub + ship + tax).toFixed(2);
    summaryEl.innerHTML =
      '<h2 class="summary-title">Order Summary</h2>' +
      '<div class="summary-row"><span>Subtotal</span><span>' + money(sub) + '</span></div>' +
      '<div class="summary-row"><span>Shipping</span><span>' + (ship === 0 ? 'FREE' : money(ship)) + '</span></div>' +
      '<div class="summary-row"><span>Estimated tax</span><span>' + money(tax) + '</span></div>' +
      '<div class="summary-row summary-total"><span>Total</span><span>' + money(total) + '</span></div>' +
      (sub < 150 ? '<p class="summary-note">Add ' + money(150 - sub) + ' more for free shipping.</p>' : '<p class="summary-note">You unlocked free shipping.</p>') +
      '<button class="btn btn-block btn-accent" id="cart-checkout">Checkout</button>' +
      '<button class="btn btn-block btn-ghost" id="cart-clear">Clear cart</button>';

    var co = document.getElementById('cart-checkout');
    if (co) co.addEventListener('click', function () {
      alert('This is a demo store — checkout is not wired to a payment provider.');
    });
    var cl = document.getElementById('cart-clear');
    if (cl) cl.addEventListener('click', function () {
      if (confirm('Remove all items from your cart?')) window.SoleCart.clear();
    });
  }

  /* ---------------------------------------------------------------------------
   * Global delegated events
   * ------------------------------------------------------------------------ */
  function initGlobalEvents() {
    document.addEventListener('click', function (e) {
      var t = e.target.closest('[data-open]');
      if (t) { openModal(t.getAttribute('data-open')); return; }

      var qa = e.target.closest('[data-quickadd]');
      if (qa) { openModal(qa.getAttribute('data-quickadd')); return; }

      var toggle = e.target.closest('[data-cart-toggle]');
      if (toggle) { e.preventDefault(); openDrawer(); return; }

      var dec = e.target.closest('[data-dec]');
      if (dec) { window.SoleCart.changeQty(dec.getAttribute('data-dec'), -1); return; }
      var inc = e.target.closest('[data-inc]');
      if (inc) { window.SoleCart.changeQty(inc.getAttribute('data-inc'), 1); return; }
      var rm = e.target.closest('[data-remove]');
      if (rm) { window.SoleCart.remove(rm.getAttribute('data-remove')); return; }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        var m = document.getElementById('product-modal');
        if (m && m.getAttribute('aria-hidden') === 'false') { closeModal(); return; }
        var d = document.getElementById('cart-drawer');
        if (d && d.getAttribute('aria-hidden') === 'false') { closeDrawer(); return; }
      }
    });

    /* Re-render live surfaces whenever the cart changes. */
    document.addEventListener('sole-cart:change', function () {
      renderDrawer();
      renderCartPage();
    });

    /* Mobile nav toggle */
    var navToggle = document.querySelector('[data-nav-toggle]');
    var nav = document.getElementById('site-nav');
    if (navToggle && nav) {
      navToggle.addEventListener('click', function () {
        var open = nav.classList.toggle('is-open');
        navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }

    /* Newsletter / signup forms */
    document.querySelectorAll('[data-signup]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var note = form.querySelector('[data-signup-note]');
        if (note) { note.hidden = false; }
        form.reset();
      });
    });

    /* Contact form */
    var contact = document.getElementById('contact-form');
    if (contact) {
      contact.addEventListener('submit', function (e) {
        e.preventDefault();
        var note = document.getElementById('contact-note');
        if (note) note.hidden = false;
        contact.reset();
      });
    }
  }

  /* ---------------------------------------------------------------------------
   * Boot
   * ------------------------------------------------------------------------ */
  document.addEventListener('DOMContentLoaded', function () {
    initGlobalEvents();
    initFeatured();
    initCatalog();
    renderCartPage();
    /* footer year */
    var y = document.querySelectorAll('[data-year]');
    for (var i = 0; i < y.length; i++) y[i].textContent = new Date().getFullYear();
  });
})();
