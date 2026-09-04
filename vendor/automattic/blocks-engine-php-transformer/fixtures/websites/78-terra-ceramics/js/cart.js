/* Terra & Form — cart state, shared across all pages.
 * Persists to localStorage under "terra_cart". Other scripts subscribe to
 * Cart.onChange() to keep the header badge, drawer, and cart page in sync.
 */
(function (window) {
  'use strict';

  var STORAGE_KEY = 'terra_cart';
  var listeners = [];

  // A line item is keyed by product id + chosen variant so different glazes
  // of the same piece stack separately.
  function lineKey(id, variant) {
    return id + '::' + (variant || '');
  }

  function read() {
    try {
      var raw = window.localStorage.getItem(STORAGE_KEY);
      if (!raw) return [];
      var parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function write(items) {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    } catch (e) {
      /* storage unavailable — keep working in-memory for this page */
    }
    emit();
  }

  function emit() {
    var snapshot = read();
    for (var i = 0; i < listeners.length; i++) {
      try { listeners[i](snapshot); } catch (e) { /* keep others alive */ }
    }
  }

  var Cart = {
    STORAGE_KEY: STORAGE_KEY,

    items: function () {
      return read();
    },

    count: function () {
      return read().reduce(function (sum, it) { return sum + it.qty; }, 0);
    },

    total: function () {
      return read().reduce(function (sum, it) { return sum + it.price * it.qty; }, 0);
    },

    add: function (product, variant, qty) {
      qty = Math.max(1, parseInt(qty, 10) || 1);
      var items = read();
      var key = lineKey(product.id, variant);
      var existing = null;
      for (var i = 0; i < items.length; i++) {
        if (items[i].key === key) { existing = items[i]; break; }
      }
      if (existing) {
        existing.qty += qty;
      } else {
        items.push({
          key: key,
          id: product.id,
          name: product.name,
          price: product.price,
          category: product.category,
          shape: product.shape,
          glaze: variant && window.GLAZES && window.GLAZES[variant] ? variant : product.glaze,
          variant: variant || '',
          qty: qty
        });
      }
      write(items);
    },

    setQty: function (key, qty) {
      qty = parseInt(qty, 10) || 0;
      var items = read();
      if (qty <= 0) {
        items = items.filter(function (it) { return it.key !== key; });
      } else {
        for (var i = 0; i < items.length; i++) {
          if (items[i].key === key) { items[i].qty = qty; break; }
        }
      }
      write(items);
    },

    increment: function (key, delta) {
      var items = read();
      for (var i = 0; i < items.length; i++) {
        if (items[i].key === key) {
          Cart.setQty(key, items[i].qty + delta);
          return;
        }
      }
    },

    remove: function (key) {
      write(read().filter(function (it) { return it.key !== key; }));
    },

    clear: function () {
      write([]);
    },

    onChange: function (fn) {
      if (typeof fn === 'function') {
        listeners.push(fn);
        fn(read()); // fire immediately with current state
      }
    }
  };

  // Sync across tabs/pages.
  window.addEventListener('storage', function (e) {
    if (e.key === STORAGE_KEY) emit();
  });

  window.Cart = Cart;
})(window);
