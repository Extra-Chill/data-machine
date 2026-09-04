/* Terra & Form — UI logic: vessel SVGs, catalog render/filter/sort,
 * product modal, mini-cart drawer, and full cart page.
 * Everything is guarded for per-page DOM and wired on DOMContentLoaded.
 */
(function (window, document) {
  'use strict';

  /* ---------- utilities ---------- */

  function money(n) {
    return '$' + Number(n).toFixed(2);
  }

  function glazeFill(name) {
    return (window.GLAZES && window.GLAZES[name]) || '#d6c3a5';
  }

  // Slightly darker tone for shading/pooling, derived from the glaze hex.
  function shade(hex, amt) {
    var h = hex.replace('#', '');
    var r = parseInt(h.substring(0, 2), 16);
    var g = parseInt(h.substring(2, 4), 16);
    var b = parseInt(h.substring(4, 6), 16);
    r = Math.max(0, Math.min(255, Math.round(r + amt)));
    g = Math.max(0, Math.min(255, Math.round(g + amt)));
    b = Math.max(0, Math.min(255, Math.round(b + amt)));
    function p(v) { return ('0' + v.toString(16)).slice(-2); }
    return '#' + p(r) + p(g) + p(b);
  }

  function byId(id) { return document.getElementById(id); }

  function findProduct(id) {
    var list = window.PRODUCTS || [];
    for (var i = 0; i < list.length; i++) {
      if (list[i].id === id) return list[i];
    }
    return null;
  }

  /* ---------- vessel SVG generator ----------
   * Returns an <svg> string. Each `shape` draws a distinct silhouette so the
   * grid reads like a gallery. `glaze` picks the fill; shade() adds pooling.
   */
  function renderVessel(shape, glazeName) {
    var fill = glazeFill(glazeName);
    var deep = shade(fill, -28);
    var light = shade(fill, 22);
    var line = '#2c2c29';
    var s = '';
    var shadow = '<ellipse cx="100" cy="178" rx="58" ry="9" fill="rgba(44,44,41,0.10)"/>';

    switch (shape) {
      case 'plate':
        s = shadow +
          '<ellipse cx="100" cy="120" rx="78" ry="26" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<ellipse cx="100" cy="116" rx="78" ry="24" fill="' + light + '"/>' +
          '<ellipse cx="100" cy="116" rx="52" ry="15" fill="' + deep + '" opacity="0.55"/>' +
          '<ellipse cx="100" cy="114" rx="52" ry="14" fill="' + fill + '"/>';
        break;
      case 'platter':
        s = shadow +
          '<ellipse cx="100" cy="124" rx="86" ry="30" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<ellipse cx="100" cy="119" rx="86" ry="27" fill="' + light + '"/>' +
          '<ellipse cx="100" cy="120" rx="58" ry="17" fill="' + deep + '" opacity="0.5"/>';
        break;
      case 'bowl':
        s = shadow +
          '<path d="M40 92 Q100 96 160 92 Q150 156 100 158 Q50 156 40 92 Z" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<ellipse cx="100" cy="92" rx="60" ry="16" fill="' + deep + '" opacity="0.55"/>' +
          '<ellipse cx="100" cy="92" rx="60" ry="14" fill="' + light + '"/>';
        break;
      case 'tumbler':
        s = shadow +
          '<path d="M64 64 Q60 110 70 158 L130 158 Q140 110 136 64 Q100 56 64 64 Z" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<ellipse cx="100" cy="64" rx="36" ry="9" fill="' + deep + '" opacity="0.55"/>' +
          '<ellipse cx="100" cy="64" rx="36" ry="8" fill="' + light + '"/>' +
          '<path d="M74 100 Q100 104 126 100" fill="none" stroke="' + deep + '" stroke-width="1" opacity="0.4"/>';
        break;
      case 'mug':
        s = shadow +
          '<path d="M62 60 L62 152 Q62 160 70 160 L122 160 Q130 160 130 152 L130 60 Z" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<path d="M130 78 Q166 80 166 108 Q166 136 130 134" fill="none" stroke="' + line + '" stroke-width="7" stroke-linecap="round"/>' +
          '<ellipse cx="96" cy="60" rx="34" ry="9" fill="' + deep + '" opacity="0.55"/>' +
          '<ellipse cx="96" cy="60" rx="34" ry="8" fill="' + light + '"/>';
        break;
      case 'espresso':
        s = shadow +
          '<ellipse cx="100" cy="150" rx="50" ry="12" fill="' + light + '" stroke="' + line + '" stroke-width="1.1"/>' +
          '<path d="M76 96 L80 138 Q80 144 88 144 L112 144 Q120 144 120 138 L124 96 Z" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<path d="M124 104 Q150 106 150 122 Q150 136 124 134" fill="none" stroke="' + line + '" stroke-width="5" stroke-linecap="round"/>' +
          '<ellipse cx="100" cy="96" rx="24" ry="6" fill="' + deep + '" opacity="0.55"/>';
        break;
      case 'budvase':
        s = shadow +
          '<path d="M86 50 L84 78 Q66 96 66 124 Q66 160 100 162 Q134 160 134 124 Q134 96 116 78 L114 50 Z" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<ellipse cx="100" cy="50" rx="14" ry="5" fill="' + deep + '" opacity="0.6"/>' +
          '<path d="M70 132 Q100 138 130 132" fill="none" stroke="' + light + '" stroke-width="2" opacity="0.6"/>';
        break;
      case 'pedestal':
        s = shadow +
          '<path d="M70 44 L66 70 Q44 92 44 124 Q44 158 100 160 Q156 158 156 124 Q156 92 134 70 L130 44 Z" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<rect x="92" y="160" width="16" height="10" fill="' + deep + '"/>' +
          '<path d="M78 172 L122 172 L132 184 L68 184 Z" fill="' + light + '" stroke="' + line + '" stroke-width="1.1"/>' +
          '<ellipse cx="100" cy="44" rx="30" ry="8" fill="' + deep + '" opacity="0.55"/>' +
          '<ellipse cx="100" cy="44" rx="30" ry="7" fill="' + light + '"/>';
        break;
      case 'ikebana':
        s = shadow +
          '<path d="M44 110 Q100 96 156 110 Q150 150 100 152 Q50 150 44 110 Z" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<ellipse cx="100" cy="110" rx="56" ry="14" fill="' + deep + '" opacity="0.6"/>' +
          '<ellipse cx="100" cy="110" rx="56" ry="12" fill="' + shade(fill, -10) + '"/>';
        break;
      case 'nesting':
        s = shadow +
          '<path d="M52 118 Q100 122 148 118 Q140 158 100 160 Q60 158 52 118 Z" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<path d="M64 96 Q100 100 136 96 Q130 124 100 126 Q70 124 64 96 Z" fill="' + light + '" stroke="' + line + '" stroke-width="1.1"/>' +
          '<path d="M76 76 Q100 80 124 76 Q120 96 100 98 Q80 96 76 76 Z" fill="' + shade(fill, 10) + '" stroke="' + line + '" stroke-width="1"/>';
        break;
      case 'dish':
        s = shadow +
          '<ellipse cx="100" cy="128" rx="64" ry="20" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<ellipse cx="100" cy="124" rx="64" ry="18" fill="' + light + '"/>' +
          '<ellipse cx="100" cy="124" rx="40" ry="10" fill="' + deep + '" opacity="0.5"/>' +
          '<circle cx="100" cy="124" r="3" fill="' + line + '"/>';
        break;
      case 'sculpture':
        s = shadow +
          '<path d="M74 52 L62 110 Q58 150 100 158 Q142 150 138 110 L120 52 Q100 44 74 52 Z" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<path d="M100 50 L100 158" stroke="' + deep + '" stroke-width="1" opacity="0.4"/>' +
          '<path d="M74 52 L80 156" stroke="' + light + '" stroke-width="1.5" opacity="0.6"/>' +
          '<path d="M120 52 L122 156" stroke="' + deep + '" stroke-width="1.5" opacity="0.45"/>';
        break;
      case 'teapot':
        s = shadow +
          '<path d="M52 110 Q52 70 100 70 Q148 70 148 110 Q148 150 100 152 Q52 150 52 110 Z" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>' +
          '<path d="M148 100 Q176 96 182 72" fill="none" stroke="' + line + '" stroke-width="7" stroke-linecap="round"/>' +
          '<path d="M52 96 Q26 92 24 116" fill="none" stroke="' + line + '" stroke-width="6" stroke-linecap="round"/>' +
          '<path d="M82 70 Q100 62 118 70 L112 58 Q100 54 88 58 Z" fill="' + light + '" stroke="' + line + '" stroke-width="1.1"/>' +
          '<circle cx="100" cy="56" r="4" fill="' + deep + '"/>';
        break;
      default:
        s = shadow +
          '<path d="M64 64 Q60 110 70 158 L130 158 Q140 110 136 64 Q100 56 64 64 Z" fill="' + fill + '" stroke="' + line + '" stroke-width="1.2"/>';
    }

    return '<svg viewBox="0 0 200 200" role="img" aria-hidden="true" preserveAspectRatio="xMidYMid meet">' + s + '</svg>';
  }
  window.renderVessel = renderVessel;

  /* ---------- header / badge / mobile nav ---------- */

  function initHeader() {
    var badges = document.querySelectorAll('[data-cart-count]');
    if (badges.length && window.Cart) {
      window.Cart.onChange(function () {
        var c = window.Cart.count();
        for (var i = 0; i < badges.length; i++) {
          badges[i].textContent = c;
          badges[i].setAttribute('data-empty', c === 0 ? 'true' : 'false');
        }
      });
    }

    var toggle = byId('navToggle');
    var nav = byId('siteNav');
    if (toggle && nav) {
      toggle.addEventListener('click', function () {
        var open = nav.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }

    var cartBtn = byId('cartButton');
    if (cartBtn) {
      cartBtn.addEventListener('click', function () { openDrawer(); });
    }
  }

  /* ---------- product card ---------- */

  function cardHTML(p) {
    return '' +
      '<article class="card" data-id="' + p.id + '">' +
        '<button class="card__media" data-open="' + p.id + '" aria-label="View ' + p.name + '">' +
          '<span class="card__vessel">' + renderVessel(p.shape, p.glaze) + '</span>' +
          (p.featured ? '<span class="card__tag">New</span>' : '') +
        '</button>' +
        '<div class="card__body">' +
          '<p class="card__cat">' + p.category + '</p>' +
          '<h3 class="card__name"><button class="linklike" data-open="' + p.id + '">' + p.name + '</button></h3>' +
          '<p class="card__price">' + money(p.price) + '</p>' +
          '<div class="card__actions">' +
            '<button class="btn btn--quiet" data-open="' + p.id + '">Details</button>' +
            '<button class="btn btn--solid" data-add="' + p.id + '">Add to cart</button>' +
          '</div>' +
        '</div>' +
      '</article>';
  }

  function bindGrid(container) {
    container.addEventListener('click', function (e) {
      var openEl = e.target.closest('[data-open]');
      if (openEl) { openModal(openEl.getAttribute('data-open')); return; }
      var addEl = e.target.closest('[data-add]');
      if (addEl) {
        var p = findProduct(addEl.getAttribute('data-add'));
        if (p && window.Cart) {
          var defVariant = p.variants ? p.variants.options[0] : '';
          window.Cart.add(p, defVariant, 1);
          openDrawer();
        }
      }
    });
  }

  /* ---------- home: featured row + collections ---------- */

  function initHome() {
    var feat = byId('featuredGrid');
    if (feat) {
      var featured = (window.PRODUCTS || []).filter(function (p) { return p.featured; }).slice(0, 4);
      feat.innerHTML = featured.map(cardHTML).join('');
      bindGrid(feat);
    }
  }

  /* ---------- shop: filter + sort ---------- */

  function initShop() {
    var grid = byId('shopGrid');
    if (!grid) return;

    var catSel = byId('filterCategory');
    var priceSel = byId('filterPrice');
    var sortSel = byId('sortBy');
    var countEl = byId('resultCount');

    bindGrid(grid);

    function priceMatch(p, token) {
      if (token === 'all') return true;
      if (token === 'u30') return p.price < 30;
      if (token === '30-60') return p.price >= 30 && p.price <= 60;
      if (token === '60-90') return p.price > 60 && p.price <= 90;
      if (token === 'o90') return p.price > 90;
      return true;
    }

    function apply() {
      var cat = catSel ? catSel.value : 'all';
      var priceTok = priceSel ? priceSel.value : 'all';
      var sort = sortSel ? sortSel.value : 'newest';

      var list = (window.PRODUCTS || []).filter(function (p) {
        return (cat === 'all' || p.category === cat) && priceMatch(p, priceTok);
      });

      list.sort(function (a, b) {
        switch (sort) {
          case 'price-asc':  return a.price - b.price;
          case 'price-desc': return b.price - a.price;
          case 'name':       return a.name.localeCompare(b.name);
          case 'newest':
          default:           return b.added - a.added;
        }
      });

      grid.innerHTML = list.length
        ? list.map(cardHTML).join('')
        : '<p class="empty">No pieces match these filters. Try widening your search.</p>';

      if (countEl) {
        countEl.textContent = list.length + (list.length === 1 ? ' piece' : ' pieces');
      }
    }

    [catSel, priceSel, sortSel].forEach(function (el) {
      if (el) el.addEventListener('change', apply);
    });

    // Optional category deep-link via ?category=Vases
    var params = new URLSearchParams(window.location.search);
    var qc = params.get('category');
    if (qc && catSel) {
      for (var i = 0; i < catSel.options.length; i++) {
        if (catSel.options[i].value === qc) { catSel.value = qc; break; }
      }
    }

    apply();
  }

  /* ---------- product modal ---------- */

  var modalState = { id: null, variant: null, qty: 1, lastFocus: null };

  function ensureModal() {
    var modal = byId('productModal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'productModal';
    modal.className = 'modal';
    modal.setAttribute('hidden', '');
    modal.innerHTML =
      '<div class="modal__backdrop" data-close-modal></div>' +
      '<div class="modal__panel" role="dialog" aria-modal="true" aria-labelledby="modalName">' +
        '<button class="modal__close" data-close-modal aria-label="Close details">&times;</button>' +
        '<div class="modal__grid">' +
          '<div class="modal__media" id="modalMedia"></div>' +
          '<div class="modal__info">' +
            '<p class="modal__cat" id="modalCat"></p>' +
            '<h2 class="modal__name" id="modalName"></h2>' +
            '<p class="modal__price" id="modalPrice"></p>' +
            '<p class="modal__desc" id="modalDesc"></p>' +
            '<dl class="modal__meta"><dt>Dimensions</dt><dd id="modalDims"></dd></dl>' +
            '<div class="modal__variant" id="modalVariantWrap">' +
              '<label class="field__label" id="modalVariantLabel" for="modalVariant"></label>' +
              '<div class="swatches" id="modalSwatches"></div>' +
            '</div>' +
            '<div class="modal__buy">' +
              '<div class="stepper" role="group" aria-label="Quantity">' +
                '<button class="stepper__btn" data-qty="-1" aria-label="Decrease quantity">&minus;</button>' +
                '<span class="stepper__val" id="modalQty" aria-live="polite">1</span>' +
                '<button class="stepper__btn" data-qty="1" aria-label="Increase quantity">+</button>' +
              '</div>' +
              '<button class="btn btn--solid btn--lg" id="modalAdd">Add to cart</button>' +
            '</div>' +
            '<p class="modal__note">Each piece is thrown by hand and made to order — slight variations in form and glaze are part of the work.</p>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);

    modal.addEventListener('click', function (e) {
      if (e.target.closest('[data-close-modal]')) closeModal();
      var q = e.target.closest('[data-qty]');
      if (q) {
        modalState.qty = Math.max(1, modalState.qty + parseInt(q.getAttribute('data-qty'), 10));
        byId('modalQty').textContent = modalState.qty;
      }
      var sw = e.target.closest('[data-swatch]');
      if (sw) {
        modalState.variant = sw.getAttribute('data-swatch');
        var all = modal.querySelectorAll('[data-swatch]');
        for (var i = 0; i < all.length; i++) {
          all[i].setAttribute('aria-pressed', all[i] === sw ? 'true' : 'false');
        }
        var p = findProduct(modalState.id);
        if (p) byId('modalMedia').innerHTML = renderVessel(p.shape, GLAZES[modalState.variant] ? modalState.variant : p.glaze);
      }
    });

    byId('modalAdd').addEventListener('click', function () {
      var p = findProduct(modalState.id);
      if (p && window.Cart) {
        window.Cart.add(p, modalState.variant || '', modalState.qty);
        closeModal();
        openDrawer();
      }
    });

    return modal;
  }

  function openModal(id) {
    var p = findProduct(id);
    if (!p) return;
    var modal = ensureModal();
    modalState.id = id;
    modalState.qty = 1;
    modalState.variant = p.variants ? p.variants.options[0] : '';
    modalState.lastFocus = document.activeElement;

    byId('modalCat').textContent = p.category;
    byId('modalName').textContent = p.name;
    byId('modalPrice').textContent = money(p.price);
    byId('modalDesc').textContent = p.description;
    byId('modalDims').textContent = p.dimensions;
    byId('modalQty').textContent = '1';
    byId('modalMedia').innerHTML = renderVessel(p.shape, p.glaze);

    var wrap = byId('modalVariantWrap');
    if (p.variants) {
      wrap.removeAttribute('hidden');
      byId('modalVariantLabel').textContent = p.variants.label;
      var isGlaze = p.variants.label === 'Glaze';
      byId('modalSwatches').innerHTML = p.variants.options.map(function (opt, idx) {
        var pressed = idx === 0 ? 'true' : 'false';
        if (isGlaze) {
          return '<button class="swatch" data-swatch="' + opt + '" aria-pressed="' + pressed + '" title="' + opt + '">' +
            '<span class="swatch__dot" style="background:' + glazeFill(opt) + '"></span>' +
            '<span class="swatch__name">' + opt + '</span></button>';
        }
        return '<button class="swatch swatch--text" data-swatch="' + opt + '" aria-pressed="' + pressed + '">' + opt + '</button>';
      }).join('');
    } else {
      wrap.setAttribute('hidden', '');
    }

    modal.removeAttribute('hidden');
    document.body.classList.add('no-scroll');
    requestAnimationFrame(function () { modal.classList.add('is-open'); });
    var closeBtn = modal.querySelector('.modal__close');
    if (closeBtn) closeBtn.focus();
  }

  function closeModal() {
    var modal = byId('productModal');
    if (!modal || modal.hasAttribute('hidden')) return;
    modal.classList.remove('is-open');
    document.body.classList.remove('no-scroll');
    window.setTimeout(function () { modal.setAttribute('hidden', ''); }, 200);
    if (modalState.lastFocus && modalState.lastFocus.focus) modalState.lastFocus.focus();
  }

  /* ---------- mini-cart drawer ---------- */

  function ensureDrawer() {
    var drawer = byId('cartDrawer');
    if (drawer) return drawer;
    drawer = document.createElement('div');
    drawer.id = 'cartDrawer';
    drawer.className = 'drawer';
    drawer.setAttribute('hidden', '');
    drawer.innerHTML =
      '<div class="drawer__backdrop" data-close-drawer></div>' +
      '<aside class="drawer__panel" role="dialog" aria-modal="true" aria-label="Shopping cart">' +
        '<header class="drawer__head">' +
          '<h2 class="drawer__title">Your cart</h2>' +
          '<button class="drawer__close" data-close-drawer aria-label="Close cart">&times;</button>' +
        '</header>' +
        '<div class="drawer__body" id="drawerBody"></div>' +
        '<footer class="drawer__foot" id="drawerFoot"></footer>' +
      '</aside>';
    document.body.appendChild(drawer);

    drawer.addEventListener('click', function (e) {
      if (e.target.closest('[data-close-drawer]')) { closeDrawer(); return; }
      var inc = e.target.closest('[data-inc]');
      if (inc) { window.Cart.increment(inc.getAttribute('data-inc'), parseInt(inc.getAttribute('data-step'), 10)); return; }
      var rm = e.target.closest('[data-remove]');
      if (rm) { window.Cart.remove(rm.getAttribute('data-remove')); return; }
    });

    if (window.Cart) {
      window.Cart.onChange(function () {
        if (!drawer.hasAttribute('hidden')) renderDrawer();
      });
    }
    return drawer;
  }

  function lineRowHTML(it, opts) {
    opts = opts || {};
    var variant = it.variant ? '<span class="line__variant">' + it.variant + '</span>' : '';
    return '' +
      '<div class="line">' +
        '<span class="line__media">' + renderVessel(it.shape, it.glaze) + '</span>' +
        '<div class="line__main">' +
          '<p class="line__name">' + it.name + '</p>' +
          variant +
          '<p class="line__price">' + money(it.price) + '</p>' +
        '</div>' +
        '<div class="line__controls">' +
          '<div class="stepper stepper--sm" role="group" aria-label="Quantity for ' + it.name + '">' +
            '<button class="stepper__btn" data-inc="' + it.key + '" data-step="-1" aria-label="Decrease quantity">&minus;</button>' +
            '<span class="stepper__val">' + it.qty + '</span>' +
            '<button class="stepper__btn" data-inc="' + it.key + '" data-step="1" aria-label="Increase quantity">+</button>' +
          '</div>' +
          '<button class="line__remove" data-remove="' + it.key + '" aria-label="Remove ' + it.name + '">Remove</button>' +
        '</div>' +
        '<p class="line__subtotal">' + money(it.price * it.qty) + '</p>' +
      '</div>';
  }

  function renderDrawer() {
    var body = byId('drawerBody');
    var foot = byId('drawerFoot');
    if (!body || !foot) return;
    var items = window.Cart.items();
    if (!items.length) {
      body.innerHTML = '<div class="drawer__empty"><p>Your cart is empty.</p><p class="muted">Pieces you add will appear here.</p></div>';
      foot.innerHTML = '<a class="btn btn--solid btn--block" href="shop.html">Browse the shop</a>';
      return;
    }
    body.innerHTML = items.map(function (it) { return lineRowHTML(it); }).join('');
    foot.innerHTML =
      '<div class="drawer__total"><span>Subtotal</span><span>' + money(window.Cart.total()) + '</span></div>' +
      '<p class="drawer__ship muted">Shipping &amp; made-to-order lead times calculated at checkout.</p>' +
      '<a class="btn btn--solid btn--block" href="cart.html">View cart</a>';
  }

  function openDrawer() {
    var drawer = ensureDrawer();
    renderDrawer();
    drawer.removeAttribute('hidden');
    document.body.classList.add('no-scroll');
    requestAnimationFrame(function () { drawer.classList.add('is-open'); });
    var closeBtn = drawer.querySelector('.drawer__close');
    if (closeBtn) closeBtn.focus();
  }

  function closeDrawer() {
    var drawer = byId('cartDrawer');
    if (!drawer || drawer.hasAttribute('hidden')) return;
    drawer.classList.remove('is-open');
    document.body.classList.remove('no-scroll');
    window.setTimeout(function () { drawer.setAttribute('hidden', ''); }, 200);
  }

  /* ---------- full cart page ---------- */

  function initCartPage() {
    var root = byId('cartPage');
    if (!root) return;

    root.addEventListener('click', function (e) {
      var inc = e.target.closest('[data-inc]');
      if (inc) { window.Cart.increment(inc.getAttribute('data-inc'), parseInt(inc.getAttribute('data-step'), 10)); return; }
      var rm = e.target.closest('[data-remove]');
      if (rm) { window.Cart.remove(rm.getAttribute('data-remove')); return; }
      if (e.target.closest('[data-clear]')) { window.Cart.clear(); return; }
    });

    function render() {
      var items = window.Cart.items();
      if (!items.length) {
        root.innerHTML =
          '<div class="cartpage__empty">' +
            '<h1 class="section__title">Your cart is empty</h1>' +
            '<p class="muted">Nothing here yet — find a piece for your table.</p>' +
            '<a class="btn btn--solid" href="shop.html">Browse the shop</a>' +
          '</div>';
        return;
      }
      var lines = items.map(function (it) { return lineRowHTML(it); }).join('');
      root.innerHTML =
        '<div class="cartpage__grid">' +
          '<div class="cartpage__lines">' +
            '<div class="cartpage__lines-head"><h1 class="section__title">Your cart</h1>' +
              '<button class="linklike" data-clear>Clear cart</button></div>' +
            lines +
          '</div>' +
          '<aside class="cartpage__summary">' +
            '<h2 class="summary__title">Order summary</h2>' +
            '<div class="summary__row"><span>Subtotal</span><span>' + money(window.Cart.total()) + '</span></div>' +
            '<div class="summary__row muted"><span>Shipping</span><span>Calculated at checkout</span></div>' +
            '<div class="summary__row summary__row--total"><span>Total</span><span>' + money(window.Cart.total()) + '</span></div>' +
            '<button class="btn btn--solid btn--block" id="checkoutBtn">Proceed to checkout</button>' +
            '<a class="btn btn--quiet btn--block" href="shop.html">Continue shopping</a>' +
            '<p class="muted summary__note">Made-to-order pieces ship in 3–5 weeks. We will confirm your lead time by email.</p>' +
          '</aside>' +
        '</div>';
      var co = byId('checkoutBtn');
      if (co) co.addEventListener('click', function () {
        co.textContent = 'Thank you — this is a demo';
        co.disabled = true;
      });
    }

    window.Cart.onChange(render);
  }

  /* ---------- contact form (demo) ---------- */

  function initContact() {
    var form = byId('contactForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var status = byId('contactStatus');
      if (status) {
        status.textContent = 'Thank you — your message has been noted. This is a demo form, so nothing was sent.';
        status.classList.add('is-visible');
      }
      form.reset();
    });
  }

  /* ---------- global key handling ---------- */

  function initKeys() {
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeModal();
        closeDrawer();
      }
    });
  }

  /* ---------- footer year ---------- */

  function initYear() {
    var els = document.querySelectorAll('[data-year]');
    var y = new Date().getFullYear();
    for (var i = 0; i < els.length; i++) els[i].textContent = y;
  }

  /* ---------- boot ---------- */

  document.addEventListener('DOMContentLoaded', function () {
    initHeader();
    initHome();
    initShop();
    initCartPage();
    initContact();
    initKeys();
    initYear();
    ensureDrawer(); // pre-build so badge-driven open is instant
  });

})(window, document);
