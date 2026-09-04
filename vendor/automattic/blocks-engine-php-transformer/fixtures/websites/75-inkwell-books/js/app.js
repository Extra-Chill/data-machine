/* Inkwell & Quill — UI behavior (render / filter / sort / modal / drawer / cart page)
 * Vanilla JS, no dependencies. Every page-specific feature guards for missing
 * DOM nodes, so this one file powers all five pages safely.
 */
(function () {
  'use strict';

  var DATA = window.INKWELL_DATA;
  var Cart = window.InkwellCart;

  /* ---------- small helpers ---------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function money(n) { return '$' + (Math.round(n * 100) / 100).toFixed(2); }
  function esc(str) {
    return String(str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function hashHue(str) {
    var h = 0;
    for (var i = 0; i < str.length; i++) { h = (h * 31 + str.charCodeAt(i)) % 360; }
    return h;
  }

  /* ---------- inline SVG book cover generator ---------- */
  // Produces a distinct cover per book using genre theme + coverStyle + title hash.
  function buildCoverSVG(product, opts) {
    opts = opts || {};
    var theme = (window.INKWELL_GENRE_THEMES && window.INKWELL_GENRE_THEMES[product.genre]) ||
      { bg: '#1f2d4a', panel: '#27406b', ink: '#f4ecd8', accent: '#9b2226' };
    var uid = product.id.replace(/[^a-z0-9]/gi, '');
    var hue = hashHue(product.title);
    var initials = product.title.replace(/[^A-Za-z ]/g, '').split(/\s+/)
      .slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
    var w = 300, h = 440;

    var motif = '';
    switch (product.coverStyle) {
      case 'stripe':
        motif =
          '<rect x="40" y="120" width="220" height="6" fill="' + theme.accent + '"/>' +
          '<rect x="40" y="300" width="220" height="6" fill="' + theme.accent + '"/>';
        break;
      case 'arch':
        motif =
          '<path d="M60 360 V200 a90 90 0 0 1 180 0 V360 Z" fill="none" stroke="' + theme.accent + '" stroke-width="4" opacity="0.85"/>';
        break;
      case 'grid':
        motif =
          '<g stroke="' + theme.accent + '" stroke-width="1.5" opacity="0.5">' +
          '<line x1="40" y1="170" x2="260" y2="170"/><line x1="40" y1="220" x2="260" y2="220"/>' +
          '<line x1="40" y1="270" x2="260" y2="270"/><line x1="110" y1="150" x2="110" y2="300"/>' +
          '<line x1="190" y1="150" x2="190" y2="300"/></g>';
        break;
      case 'banner':
        motif =
          '<path d="M150 110 l70 40 v120 l-70 -34 -70 34 V150 Z" fill="' + theme.accent + '" opacity="0.85"/>';
        break;
      case 'noir':
        motif =
          '<circle cx="150" cy="210" r="78" fill="none" stroke="' + theme.accent + '" stroke-width="3"/>' +
          '<circle cx="150" cy="210" r="48" fill="' + theme.accent + '" opacity="0.18"/>' +
          '<line x1="150" y1="210" x2="206" y2="166" stroke="' + theme.accent + '" stroke-width="3"/>';
        break;
      case 'orbit':
        motif =
          '<g fill="none" stroke="' + theme.accent + '" stroke-width="2.5" opacity="0.9">' +
          '<ellipse cx="150" cy="210" rx="100" ry="42"/>' +
          '<ellipse cx="150" cy="210" rx="60" ry="92" transform="rotate(35 150 210)"/></g>' +
          '<circle cx="150" cy="210" r="16" fill="' + theme.accent + '"/>';
        break;
      case 'minimal':
        motif =
          '<line x1="90" y1="250" x2="210" y2="250" stroke="' + theme.accent + '" stroke-width="2"/>';
        break;
      case 'playful':
        motif =
          '<circle cx="95" cy="180" r="26" fill="' + theme.accent + '"/>' +
          '<circle cx="160" cy="230" r="18" fill="hsl(' + hue + ',70%,60%)"/>' +
          '<circle cx="215" cy="170" r="22" fill="' + theme.ink + '" opacity="0.85"/>';
        break;
      default:
        motif = '';
    }

    return '' +
      '<svg class="cover-svg" viewBox="0 0 ' + w + ' ' + h + '" role="img" ' +
      'aria-label="Cover of ' + esc(product.title) + ' by ' + esc(product.author) + '" ' +
      'preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">' +
        '<defs>' +
          '<linearGradient id="bg' + uid + '" x1="0" y1="0" x2="1" y2="1">' +
            '<stop offset="0" stop-color="' + theme.bg + '"/>' +
            '<stop offset="1" stop-color="' + theme.panel + '"/>' +
          '</linearGradient>' +
        '</defs>' +
        '<rect width="' + w + '" height="' + h + '" rx="6" fill="url(#bg' + uid + ')"/>' +
        // spine + paper edge
        '<rect x="0" y="0" width="14" height="' + h + '" rx="6" fill="#00000033"/>' +
        '<rect x="14" y="0" width="3" height="' + h + '" fill="' + theme.accent + '" opacity="0.7"/>' +
        // gold hairline frame
        '<rect x="24" y="24" width="' + (w - 48) + '" height="' + (h - 48) + '" rx="3" ' +
          'fill="none" stroke="' + theme.accent + '" stroke-width="1" opacity="0.55"/>' +
        motif +
        // big initial watermark
        '<text x="150" y="240" text-anchor="middle" font-family="Georgia, serif" ' +
          'font-size="120" fill="' + theme.ink + '" opacity="0.07" font-weight="700">' + esc(initials) + '</text>' +
        // title
        wrapTitle(product.title, theme.ink) +
        // author
        '<text x="150" y="404" text-anchor="middle" font-family="Georgia, serif" ' +
          'font-size="15" fill="' + theme.ink + '" opacity="0.85" font-style="italic">' +
          esc(product.author) + '</text>' +
        // genre kicker
        '<text x="150" y="64" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" ' +
          'font-size="11" letter-spacing="3" fill="' + theme.accent + '">' +
          esc(product.genre.toUpperCase()) + '</text>' +
      '</svg>';
  }

  // crude word-wrap into up to 3 tspans for the cover title
  function wrapTitle(title, ink) {
    var words = title.split(' ');
    var lines = [];
    var current = '';
    var max = 14;
    words.forEach(function (word) {
      if ((current + ' ' + word).trim().length > max && current) {
        lines.push(current.trim());
        current = word;
      } else {
        current = (current + ' ' + word).trim();
      }
    });
    if (current) { lines.push(current); }
    lines = lines.slice(0, 3);
    var startY = 348 - (lines.length - 1) * 26;
    return '<g font-family="Georgia, serif" font-weight="700" font-size="26" fill="' + ink + '" text-anchor="middle">' +
      lines.map(function (line, i) {
        return '<text x="150" y="' + (startY + i * 30) + '">' + esc(line) + '</text>';
      }).join('') + '</g>';
  }

  /* ---------- header badge (every page) ---------- */
  function initBadge() {
    var badges = $all('[data-cart-count]');
    if (!badges.length) { return; }
    Cart.subscribe(function (snap) {
      badges.forEach(function (b) {
        b.textContent = snap.count;
        b.classList.toggle('is-empty', snap.count === 0);
        b.setAttribute('aria-label', snap.count + ' items in cart');
      });
    });
  }

  /* ---------- mobile nav toggle ---------- */
  function initNavToggle() {
    var toggle = $('[data-nav-toggle]');
    var nav = $('[data-nav]');
    if (!toggle || !nav) { return; }
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* ---------- product card markup ---------- */
  function cardHTML(product) {
    var badge = product.bestseller ? '<span class="card-flag card-flag--best">Bestseller</span>' :
      (product.staffPick ? '<span class="card-flag card-flag--pick">Staff Pick</span>' : '');
    return '' +
      '<article class="book-card" data-id="' + esc(product.id) + '">' +
        '<button class="book-card__cover" data-open="' + esc(product.id) + '" ' +
          'aria-label="View details for ' + esc(product.title) + '">' +
          badge + buildCoverSVG(product) +
        '</button>' +
        '<div class="book-card__body">' +
          '<p class="book-card__genre">' + esc(product.genre) + '</p>' +
          '<h3 class="book-card__title">' +
            '<button class="linkish" data-open="' + esc(product.id) + '">' + esc(product.title) + '</button>' +
          '</h3>' +
          '<p class="book-card__author">' + esc(product.author) + '</p>' +
          '<div class="book-card__foot">' +
            '<span class="book-card__price">' + money(product.price) + '</span>' +
            '<button class="btn btn--small" data-add="' + esc(product.id) + '">Add to Cart</button>' +
          '</div>' +
        '</div>' +
      '</article>';
  }

  function renderInto(node, list) {
    if (!node) { return; }
    if (!list.length) {
      node.innerHTML = '<p class="empty-note">No books match these filters. Try widening your search.</p>';
      return;
    }
    node.innerHTML = list.map(cardHTML).join('');
  }

  /* ---------- home page rows ---------- */
  function initHomeRows() {
    var staff = $('[data-row="staff"]');
    var arrivals = $('[data-row="arrivals"]');
    var best = $('[data-row="bestsellers"]');
    if (!staff && !arrivals && !best) { return; }
    var all = DATA.products;

    if (staff) {
      renderInto(staff, all.filter(function (p) { return p.staffPick; }).slice(0, 4));
    }
    if (arrivals) {
      renderInto(arrivals, all.slice().sort(function (a, b) {
        return new Date(b.added) - new Date(a.added);
      }).slice(0, 4));
    }
    if (best) {
      renderInto(best, all.filter(function (p) { return p.bestseller; }).slice(0, 4));
    }
  }

  /* ---------- shop page filter + sort ---------- */
  function initShop() {
    var grid = $('[data-shop-grid]');
    if (!grid) { return; }
    var genreSel = $('[data-filter-genre]');
    var priceSel = $('[data-filter-price]');
    var sortSel = $('[data-sort]');
    var countOut = $('[data-result-count]');

    // populate genre options
    if (genreSel) {
      var genres = DATA.products.map(function (p) { return p.genre; })
        .filter(function (g, i, arr) { return arr.indexOf(g) === i; }).sort();
      genres.forEach(function (g) {
        var opt = document.createElement('option');
        opt.value = g; opt.textContent = g;
        genreSel.appendChild(opt);
      });
    }

    // honor ?genre= and ?sort= from query string (links from home)
    var qs = new URLSearchParams(window.location.search);
    if (genreSel && qs.get('genre')) { genreSel.value = qs.get('genre'); }
    if (sortSel && qs.get('sort')) {
      var wanted = qs.get('sort');
      if ($all('option', sortSel).some(function (o) { return o.value === wanted; })) {
        sortSel.value = wanted;
      }
    }

    function priceBand(val, product) {
      if (val === 'all' || !val) { return true; }
      if (val === 'under20') { return product.price < 20; }
      if (val === '20to28') { return product.price >= 20 && product.price <= 28; }
      if (val === 'over28') { return product.price > 28; }
      return true;
    }

    function apply() {
      var g = genreSel ? genreSel.value : 'all';
      var pb = priceSel ? priceSel.value : 'all';
      var sort = sortSel ? sortSel.value : 'featured';

      var list = DATA.products.filter(function (p) {
        return (g === 'all' || !g || p.genre === g) && priceBand(pb, p);
      });

      list.sort(function (a, b) {
        switch (sort) {
          case 'price-asc': return a.price - b.price;
          case 'price-desc': return b.price - a.price;
          case 'title-asc': return a.title.localeCompare(b.title);
          case 'newest': return new Date(b.added) - new Date(a.added);
          default:
            // featured: staff picks then bestsellers then title
            var sa = (a.staffPick ? 0 : 1) - 0, sb = (b.staffPick ? 0 : 1) - 0;
            if (sa !== sb) { return sa - sb; }
            var ba = a.bestseller ? 0 : 1, bb = b.bestseller ? 0 : 1;
            if (ba !== bb) { return ba - bb; }
            return a.title.localeCompare(b.title);
        }
      });

      renderInto(grid, list);
      if (countOut) {
        countOut.textContent = list.length + (list.length === 1 ? ' book' : ' books');
      }
    }

    [genreSel, priceSel, sortSel].forEach(function (el) {
      if (el) { el.addEventListener('change', apply); }
    });
    apply();
  }

  /* ---------- product detail modal ---------- */
  var modal = {
    root: null, lastFocus: null, currentId: null, qty: 1, formatId: null
  };

  function buildModalShell() {
    if (modal.root) { return modal.root; }
    var el = document.createElement('div');
    el.className = 'modal';
    el.setAttribute('hidden', '');
    el.innerHTML =
      '<div class="modal__overlay" data-close></div>' +
      '<div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="modalTitle">' +
        '<button class="modal__close" data-close aria-label="Close details">&times;</button>' +
        '<div class="modal__grid">' +
          '<div class="modal__cover" data-modal-cover></div>' +
          '<div class="modal__info">' +
            '<p class="modal__genre" data-modal-genre></p>' +
            '<h2 class="modal__title" id="modalTitle" data-modal-title></h2>' +
            '<p class="modal__author" data-modal-author></p>' +
            '<p class="modal__desc" data-modal-desc></p>' +
            '<div class="modal__controls">' +
              '<label class="field">' +
                '<span class="field__label">Format</span>' +
                '<select data-modal-format></select>' +
              '</label>' +
              '<div class="field">' +
                '<span class="field__label">Quantity</span>' +
                '<div class="stepper" role="group" aria-label="Quantity">' +
                  '<button class="stepper__btn" data-qty-dec aria-label="Decrease quantity">&minus;</button>' +
                  '<span class="stepper__val" data-qty-val aria-live="polite">1</span>' +
                  '<button class="stepper__btn" data-qty-inc aria-label="Increase quantity">+</button>' +
                '</div>' +
              '</div>' +
            '</div>' +
            '<div class="modal__buy">' +
              '<span class="modal__price" data-modal-price></span>' +
              '<button class="btn btn--primary" data-modal-add>Add to Cart</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(el);
    modal.root = el;

    el.addEventListener('click', function (e) {
      if (e.target.hasAttribute('data-close')) { closeModal(); }
    });
    $('[data-qty-dec]', el).addEventListener('click', function () { setModalQty(modal.qty - 1); });
    $('[data-qty-inc]', el).addEventListener('click', function () { setModalQty(modal.qty + 1); });
    $('[data-modal-format]', el).addEventListener('change', function (e) {
      modal.formatId = e.target.value;
      updateModalPrice();
    });
    $('[data-modal-add]', el).addEventListener('click', function () {
      Cart.add(modal.currentId, modal.formatId, modal.qty);
      closeModal();
      openDrawer();
    });
    return el;
  }

  function setModalQty(n) {
    modal.qty = Math.max(1, n);
    var out = $('[data-qty-val]', modal.root);
    if (out) { out.textContent = modal.qty; }
    updateModalPrice();
  }

  function updateModalPrice() {
    var product = DATA.getProduct(modal.currentId);
    if (!product) { return; }
    var unit = DATA.priceFor(product, modal.formatId);
    var out = $('[data-modal-price]', modal.root);
    if (out) { out.textContent = money(unit * modal.qty); }
  }

  function openModal(id) {
    var product = DATA.getProduct(id);
    if (!product) { return; }
    buildModalShell();
    modal.currentId = id;
    modal.qty = 1;
    modal.formatId = DATA.formats[0].id;
    modal.lastFocus = document.activeElement;

    $('[data-modal-cover]', modal.root).innerHTML = buildCoverSVG(product);
    $('[data-modal-genre]', modal.root).textContent = product.genre;
    $('[data-modal-title]', modal.root).textContent = product.title;
    $('[data-modal-author]', modal.root).textContent = 'by ' + product.author;
    $('[data-modal-desc]', modal.root).textContent = product.description;

    var fmtSel = $('[data-modal-format]', modal.root);
    fmtSel.innerHTML = DATA.formats.map(function (f) {
      return '<option value="' + f.id + '">' + esc(f.label) + ' — ' +
        money(DATA.priceFor(product, f.id)) + '</option>';
    }).join('');
    fmtSel.value = modal.formatId;

    setModalQty(1);
    modal.root.removeAttribute('hidden');
    document.body.classList.add('no-scroll');
    var closeBtn = $('.modal__close', modal.root);
    if (closeBtn) { closeBtn.focus(); }
  }

  function closeModal() {
    if (!modal.root || modal.root.hasAttribute('hidden')) { return; }
    modal.root.setAttribute('hidden', '');
    document.body.classList.remove('no-scroll');
    if (modal.lastFocus && modal.lastFocus.focus) { modal.lastFocus.focus(); }
  }

  /* ---------- mini-cart drawer ---------- */
  var drawer = { root: null, lastFocus: null };

  function buildDrawerShell() {
    if (drawer.root) { return drawer.root; }
    var el = document.createElement('div');
    el.className = 'drawer';
    el.setAttribute('hidden', '');
    el.innerHTML =
      '<div class="drawer__overlay" data-close></div>' +
      '<aside class="drawer__panel" role="dialog" aria-modal="true" aria-label="Shopping cart">' +
        '<header class="drawer__head">' +
          '<h2 class="drawer__title">Your Cart</h2>' +
          '<button class="drawer__close" data-close aria-label="Close cart">&times;</button>' +
        '</header>' +
        '<div class="drawer__body" data-drawer-lines></div>' +
        '<footer class="drawer__foot">' +
          '<div class="drawer__subtotal"><span>Subtotal</span><strong data-drawer-subtotal>$0.00</strong></div>' +
          '<a class="btn btn--outline" href="cart.html">View Cart</a>' +
          '<button class="btn btn--primary" data-checkout>Checkout</button>' +
        '</footer>' +
      '</aside>';
    document.body.appendChild(el);
    drawer.root = el;

    el.addEventListener('click', function (e) {
      if (e.target.hasAttribute('data-close')) { closeDrawer(); }
    });
    var checkout = $('[data-checkout]', el);
    if (checkout) {
      checkout.addEventListener('click', function () {
        var snap = Cart.snapshot();
        if (!snap.count) { return; }
        alert('Thank you! This is a demo store — your order of ' + snap.count +
          ' book(s) totaling ' + money(snap.subtotal) + ' would be placed here.');
        Cart.clear();
        closeDrawer();
      });
    }

    // delegate qty/remove inside drawer
    $('[data-drawer-lines]', el).addEventListener('click', function (e) {
      var btn = e.target.closest('[data-line-action]');
      if (!btn) { return; }
      var id = btn.getAttribute('data-id');
      var fmt = btn.getAttribute('data-format');
      var action = btn.getAttribute('data-line-action');
      if (action === 'inc') { Cart.increment(id, fmt, 1); }
      else if (action === 'dec') { Cart.increment(id, fmt, -1); }
      else if (action === 'remove') { Cart.remove(id, fmt); }
    });
    return el;
  }

  function lineRowHTML(line, compact) {
    var product = line.product;
    var coverMini = product ?
      '<div class="line__cover">' + buildCoverSVG(product) + '</div>' : '';
    return '' +
      '<div class="line' + (compact ? ' line--compact' : '') + '">' +
        coverMini +
        '<div class="line__main">' +
          '<p class="line__title">' + esc(line.title) + '</p>' +
          '<p class="line__meta">' + esc(line.author) + ' &middot; ' + esc(line.formatLabel) + '</p>' +
          '<p class="line__unit">' + money(line.unitPrice) + ' each</p>' +
          '<div class="line__controls">' +
            '<div class="stepper stepper--sm" role="group" aria-label="Quantity for ' + esc(line.title) + '">' +
              '<button class="stepper__btn" data-line-action="dec" data-id="' + esc(line.id) + '" data-format="' + esc(line.formatId) + '" aria-label="Decrease quantity">&minus;</button>' +
              '<span class="stepper__val">' + line.qty + '</span>' +
              '<button class="stepper__btn" data-line-action="inc" data-id="' + esc(line.id) + '" data-format="' + esc(line.formatId) + '" aria-label="Increase quantity">+</button>' +
            '</div>' +
            '<button class="line__remove" data-line-action="remove" data-id="' + esc(line.id) + '" data-format="' + esc(line.formatId) + '" aria-label="Remove ' + esc(line.title) + '">Remove</button>' +
          '</div>' +
        '</div>' +
        '<div class="line__total">' + money(line.lineTotal) + '</div>' +
      '</div>';
  }

  function renderDrawer(snap) {
    if (!drawer.root) { return; }
    var body = $('[data-drawer-lines]', drawer.root);
    var sub = $('[data-drawer-subtotal]', drawer.root);
    if (!snap.lines.length) {
      body.innerHTML = '<p class="empty-note">Your cart is empty. Find your next great read in the shop.</p>';
    } else {
      body.innerHTML = snap.lines.map(function (l) { return lineRowHTML(l, true); }).join('');
    }
    if (sub) { sub.textContent = money(snap.subtotal); }
  }

  function openDrawer() {
    buildDrawerShell();
    renderDrawer(Cart.snapshot());
    drawer.lastFocus = document.activeElement;
    drawer.root.removeAttribute('hidden');
    document.body.classList.add('no-scroll');
    var closeBtn = $('.drawer__close', drawer.root);
    if (closeBtn) { closeBtn.focus(); }
  }

  function closeDrawer() {
    if (!drawer.root || drawer.root.hasAttribute('hidden')) { return; }
    drawer.root.setAttribute('hidden', '');
    document.body.classList.remove('no-scroll');
    if (drawer.lastFocus && drawer.lastFocus.focus) { drawer.lastFocus.focus(); }
  }

  function initDrawer() {
    // keep drawer contents fresh whenever cart changes
    Cart.subscribe(function (snap) {
      if (drawer.root && !drawer.root.hasAttribute('hidden')) { renderDrawer(snap); }
    });
    var trigger = $('[data-open-cart]');
    if (trigger) {
      trigger.addEventListener('click', function (e) {
        e.preventDefault();
        openDrawer();
      });
    }
  }

  /* ---------- full cart page ---------- */
  function initCartPage() {
    var wrap = $('[data-cart-page]');
    if (!wrap) { return; }
    var linesNode = $('[data-cart-lines]', wrap);
    var subNode = $('[data-cart-subtotal]', wrap);
    var totalNode = $('[data-cart-total]', wrap);
    var summary = $('[data-cart-summary]', wrap);
    var emptyNode = $('[data-cart-empty]', wrap);
    var SHIP = 4.95;

    function render(snap) {
      if (!snap.lines.length) {
        if (linesNode) { linesNode.innerHTML = ''; }
        if (summary) { summary.hidden = true; }
        if (emptyNode) { emptyNode.hidden = false; }
        return;
      }
      if (emptyNode) { emptyNode.hidden = true; }
      if (summary) { summary.hidden = false; }
      if (linesNode) {
        linesNode.innerHTML = snap.lines.map(function (l) { return lineRowHTML(l, false); }).join('');
      }
      if (subNode) { subNode.textContent = money(snap.subtotal); }
      if (totalNode) { totalNode.textContent = money(snap.subtotal + SHIP); }
    }

    if (linesNode) {
      linesNode.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-line-action]');
        if (!btn) { return; }
        var id = btn.getAttribute('data-id');
        var fmt = btn.getAttribute('data-format');
        var action = btn.getAttribute('data-line-action');
        if (action === 'inc') { Cart.increment(id, fmt, 1); }
        else if (action === 'dec') { Cart.increment(id, fmt, -1); }
        else if (action === 'remove') { Cart.remove(id, fmt); }
      });
    }

    var checkoutBtn = $('[data-cart-checkout]', wrap);
    if (checkoutBtn) {
      checkoutBtn.addEventListener('click', function () {
        var snap = Cart.snapshot();
        if (!snap.count) { return; }
        alert('Thank you! This is a demo store — your order of ' + snap.count +
          ' book(s) totaling ' + money(snap.subtotal + SHIP) + ' would be placed here.');
        Cart.clear();
      });
    }

    Cart.subscribe(render);
  }

  /* ---------- contact form (demo, no backend) ---------- */
  function initContactForm() {
    var form = $('[data-contact-form]');
    if (!form) { return; }
    var note = $('[data-form-note]', form);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!form.checkValidity()) { form.reportValidity(); return; }
      if (note) {
        note.hidden = false;
        note.textContent = 'Thank you — your message has been received. We’ll reply within two business days.';
      }
      form.reset();
    });
  }

  /* ---------- newsletter (demo) ---------- */
  function initNewsletter() {
    $all('[data-newsletter]').forEach(function (form) {
      var note = $('[data-newsletter-note]', form);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!form.checkValidity()) { form.reportValidity(); return; }
        if (note) {
          note.hidden = false;
          note.textContent = 'You’re on the list! Watch your inbox for our monthly reading dispatch.';
        }
        form.reset();
      });
    });
  }

  /* ---------- global click + keyboard wiring ---------- */
  function initGlobalEvents() {
    document.addEventListener('click', function (e) {
      var openBtn = e.target.closest('[data-open]');
      if (openBtn) { e.preventDefault(); openModal(openBtn.getAttribute('data-open')); return; }
      var addBtn = e.target.closest('[data-add]');
      if (addBtn) {
        e.preventDefault();
        var product = DATA.getProduct(addBtn.getAttribute('data-add'));
        if (product) {
          Cart.add(product.id, DATA.formats[0].id, 1);
          openDrawer();
        }
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeModal();
        closeDrawer();
      }
    });
  }

  /* ---------- boot ---------- */
  document.addEventListener('DOMContentLoaded', function () {
    if (!DATA || !Cart) { return; }
    initBadge();
    initNavToggle();
    initDrawer();
    initGlobalEvents();
    initHomeRows();
    initShop();
    initCartPage();
    initContactForm();
    initNewsletter();

    // stamp current year in footers
    $all('[data-year]').forEach(function (n) { n.textContent = new Date().getFullYear(); });
  });
})();
