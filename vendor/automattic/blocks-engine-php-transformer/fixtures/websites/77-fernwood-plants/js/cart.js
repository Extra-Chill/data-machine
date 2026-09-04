/* Fernwood Botanicals — shared cart module.
 * Owns localStorage persistence (key "fernwood_cart"), totals, and the
 * header badge + mini-cart drawer. Shared across every page. */

(function (global) {
  'use strict';

  var STORAGE_KEY = 'fernwood_cart';
  var listeners = [];

  function load() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return [];
      var parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function save(items) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    } catch (e) { /* storage may be unavailable */ }
  }

  var items = load();

  function emit() {
    save(items);
    for (var i = 0; i < listeners.length; i++) {
      try { listeners[i](items); } catch (e) { /* keep others alive */ }
    }
  }

  function lineKey(id, variant) {
    return id + '::' + (variant || '');
  }

  function money(n) {
    return '$' + Number(n).toFixed(2);
  }

  var Cart = {
    money: money,

    items: function () { return items.slice(); },

    count: function () {
      return items.reduce(function (sum, it) { return sum + it.qty; }, 0);
    },

    subtotal: function () {
      return items.reduce(function (sum, it) { return sum + it.price * it.qty; }, 0);
    },

    add: function (product, variantLabel, unitPrice, qty) {
      qty = qty || 1;
      var key = lineKey(product.id, variantLabel);
      var existing = null;
      for (var i = 0; i < items.length; i++) {
        if (lineKey(items[i].id, items[i].variant) === key) { existing = items[i]; break; }
      }
      if (existing) {
        existing.qty += qty;
      } else {
        items.push({
          id: product.id,
          name: product.name,
          variant: variantLabel || '',
          price: unitPrice,
          qty: qty
        });
      }
      emit();
    },

    setQty: function (id, variant, qty) {
      var key = lineKey(id, variant);
      for (var i = 0; i < items.length; i++) {
        if (lineKey(items[i].id, items[i].variant) === key) {
          items[i].qty = Math.max(1, qty);
          break;
        }
      }
      emit();
    },

    changeQty: function (id, variant, delta) {
      var key = lineKey(id, variant);
      for (var i = 0; i < items.length; i++) {
        if (lineKey(items[i].id, items[i].variant) === key) {
          items[i].qty = Math.max(1, items[i].qty + delta);
          break;
        }
      }
      emit();
    },

    remove: function (id, variant) {
      var key = lineKey(id, variant);
      items = items.filter(function (it) { return lineKey(it.id, it.variant) !== key; });
      emit();
    },

    clear: function () {
      items = [];
      emit();
    },

    subscribe: function (fn) {
      listeners.push(fn);
      return fn;
    },

    /* Sync when another tab/page mutates localStorage. */
    _externalReload: function () {
      items = load();
      for (var i = 0; i < listeners.length; i++) {
        try { listeners[i](items); } catch (e) {}
      }
    }
  };

  global.addEventListener('storage', function (e) {
    if (e.key === STORAGE_KEY) Cart._externalReload();
  });

  global.FernwoodCart = Cart;
})(window);
