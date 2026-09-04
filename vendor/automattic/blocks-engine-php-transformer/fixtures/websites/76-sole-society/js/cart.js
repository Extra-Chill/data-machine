/* SOLE SOCIETY — cart module (shared across all pages)
 * Persists to localStorage under "sole_cart".
 * A line item is keyed by productId + size + color, so the SAME shoe in a
 * different size (or colorway) is a DISTINCT line item.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'sole_cart';

  function lookupProduct(id) {
    var list = window.SOLE_PRODUCTS || [];
    for (var i = 0; i < list.length; i++) {
      if (list[i].id === id) return list[i];
    }
    return null;
  }

  function read() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return [];
      var parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function write(items) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    } catch (e) {
      /* storage may be unavailable; fail quietly */
    }
    emitChange();
  }

  function emitChange() {
    document.dispatchEvent(new CustomEvent('sole-cart:change', { detail: { items: read() } }));
  }

  function lineKey(id, size, color) {
    return id + '::' + (size || '') + '::' + (color || '');
  }

  var Cart = {
    STORAGE_KEY: STORAGE_KEY,

    items: function () {
      return read();
    },

    /* Add a quantity of a product/size/color. Merges with matching line. */
    add: function (productId, size, color, qty) {
      qty = parseInt(qty, 10) || 1;
      if (qty < 1) qty = 1;
      var product = lookupProduct(productId);
      if (!product) return;
      var items = read();
      var key = lineKey(productId, size, color);
      var found = null;
      for (var i = 0; i < items.length; i++) {
        if (lineKey(items[i].id, items[i].size, items[i].color) === key) {
          found = items[i];
          break;
        }
      }
      if (found) {
        found.qty += qty;
      } else {
        items.push({
          id: productId,
          name: product.name,
          maker: product.maker,
          price: product.price,
          size: size || '',
          color: color || (product.colors && product.colors[0]) || '',
          qty: qty
        });
      }
      write(items);
    },

    /* Set absolute quantity for a line; removes line if qty <= 0. */
    setQty: function (key, qty) {
      qty = parseInt(qty, 10) || 0;
      var items = read();
      var next = [];
      for (var i = 0; i < items.length; i++) {
        var it = items[i];
        if (lineKey(it.id, it.size, it.color) === key) {
          if (qty > 0) {
            it.qty = qty;
            next.push(it);
          }
          /* qty <= 0 drops the line */
        } else {
          next.push(it);
        }
      }
      write(next);
    },

    changeQty: function (key, delta) {
      var items = read();
      for (var i = 0; i < items.length; i++) {
        if (lineKey(items[i].id, items[i].size, items[i].color) === key) {
          Cart.setQty(key, items[i].qty + delta);
          return;
        }
      }
    },

    remove: function (key) {
      var items = read();
      var next = [];
      for (var i = 0; i < items.length; i++) {
        if (lineKey(items[i].id, items[i].size, items[i].color) !== key) {
          next.push(items[i]);
        }
      }
      write(next);
    },

    clear: function () {
      write([]);
    },

    keyFor: function (item) {
      return lineKey(item.id, item.size, item.color);
    },

    count: function () {
      var items = read();
      var n = 0;
      for (var i = 0; i < items.length; i++) n += items[i].qty;
      return n;
    },

    subtotal: function () {
      var items = read();
      var t = 0;
      for (var i = 0; i < items.length; i++) t += items[i].price * items[i].qty;
      return t;
    }
  };

  window.SoleCart = Cart;

  /* Keep the header badge in sync everywhere. */
  function updateBadges() {
    var count = Cart.count();
    var badges = document.querySelectorAll('[data-cart-count]');
    for (var i = 0; i < badges.length; i++) {
      badges[i].textContent = count;
      badges[i].setAttribute('data-empty', count === 0 ? 'true' : 'false');
    }
  }

  document.addEventListener('sole-cart:change', updateBadges);
  document.addEventListener('DOMContentLoaded', updateBadges);

  /* Reflect cart edits made in another tab/page. */
  window.addEventListener('storage', function (e) {
    if (e.key === STORAGE_KEY) emitChange();
  });
})();
