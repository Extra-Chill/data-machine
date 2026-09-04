/* Lumen Coffee Roasters — cart state, localStorage persistence, shared across pages */
(function (global) {
  'use strict';

  var KEY = 'lumen_cart';
  var EVENT = 'lumen:cart-updated';

  function read() {
    try {
      var raw = global.localStorage.getItem(KEY);
      var data = raw ? JSON.parse(raw) : [];
      return Array.isArray(data) ? data : [];
    } catch (e) {
      return [];
    }
  }

  function write(items) {
    try {
      global.localStorage.setItem(KEY, JSON.stringify(items));
    } catch (e) { /* storage unavailable — operate in-memory */ }
    notify();
  }

  function notify() {
    try {
      global.dispatchEvent(new CustomEvent(EVENT));
    } catch (e) {
      var ev = global.document.createEvent('Event');
      ev.initEvent(EVENT, true, true);
      global.dispatchEvent(ev);
    }
  }

  // A stable line key merges identical product + option selections.
  function lineKey(productId, selection) {
    var parts = [productId];
    if (selection) {
      Object.keys(selection).sort().forEach(function (k) {
        parts.push(k + ':' + selection[k]);
      });
    }
    return parts.join('|');
  }

  function getCart() { return read(); }

  function getCount() {
    return read().reduce(function (n, l) { return n + l.qty; }, 0);
  }

  function getSubtotal() {
    return read().reduce(function (s, l) { return s + l.price * l.qty; }, 0);
  }

  function add(product, selection, qty) {
    qty = Math.max(1, parseInt(qty, 10) || 1);
    var price = (global.LUMEN && global.LUMEN.priceFor)
      ? global.LUMEN.priceFor(product, selection)
      : product.price;
    var key = lineKey(product.id, selection);
    var items = read();
    var existing = items.filter(function (l) { return l.key === key; })[0];
    if (existing) {
      existing.qty += qty;
    } else {
      items.push({
        key: key,
        id: product.id,
        name: product.name,
        category: product.category,
        selection: selection || {},
        price: price,
        qty: qty
      });
    }
    write(items);
  }

  function setQty(key, qty) {
    qty = parseInt(qty, 10) || 0;
    var items = read();
    if (qty <= 0) {
      items = items.filter(function (l) { return l.key !== key; });
    } else {
      items.forEach(function (l) { if (l.key === key) l.qty = qty; });
    }
    write(items);
  }

  function changeQty(key, delta) {
    var items = read();
    var line = items.filter(function (l) { return l.key === key; })[0];
    if (line) setQty(key, line.qty + delta);
  }

  function remove(key) {
    write(read().filter(function (l) { return l.key !== key; }));
  }

  function clear() { write([]); }

  function money(n) {
    return '$' + Number(n).toFixed(2);
  }

  global.LUMEN = global.LUMEN || {};
  global.LUMEN.cart = {
    EVENT: EVENT,
    get: getCart,
    count: getCount,
    subtotal: getSubtotal,
    add: add,
    setQty: setQty,
    changeQty: changeQty,
    remove: remove,
    clear: clear,
    money: money
  };

  // Sync across browser tabs.
  global.addEventListener('storage', function (e) {
    if (e.key === KEY) notify();
  });
})(window);
