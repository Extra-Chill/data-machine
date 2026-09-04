/* Inkwell & Quill — cart + localStorage (shared across every page)
 * Exposes window.InkwellCart with a small, framework-free API and a
 * change-notification system so app.js can re-render badges/drawers/pages.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'inkwell_cart';
  var listeners = [];
  var items = load();

  function load() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) { return []; }
      var parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed.filter(isValidLine) : [];
    } catch (e) {
      return [];
    }
  }

  function isValidLine(line) {
    return line && typeof line.id === 'string' &&
      typeof line.formatId === 'string' &&
      typeof line.qty === 'number' && line.qty > 0;
  }

  function persist() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    } catch (e) { /* storage may be unavailable; degrade silently */ }
  }

  function lineKey(id, formatId) {
    return id + '::' + formatId;
  }

  function find(id, formatId) {
    for (var i = 0; i < items.length; i++) {
      if (items[i].id === id && items[i].formatId === formatId) { return items[i]; }
    }
    return null;
  }

  function notify() {
    persist();
    for (var i = 0; i < listeners.length; i++) {
      try { listeners[i](publicSnapshot()); } catch (e) { /* keep going */ }
    }
  }

  // Enrich raw lines with live product data + computed prices for consumers.
  function publicSnapshot() {
    var data = window.INKWELL_DATA;
    var lines = items.map(function (line) {
      var product = data ? data.getProduct(line.id) : null;
      var unit = product ? data.priceFor(product, line.formatId) : 0;
      return {
        key: lineKey(line.id, line.formatId),
        id: line.id,
        formatId: line.formatId,
        formatLabel: data ? data.formatLabel(line.formatId) : line.formatId,
        qty: line.qty,
        title: product ? product.title : line.id,
        author: product ? product.author : '',
        genre: product ? product.genre : '',
        product: product,
        unitPrice: unit,
        lineTotal: unit * line.qty
      };
    });
    return {
      lines: lines,
      count: count(),
      subtotal: lines.reduce(function (sum, l) { return sum + l.lineTotal; }, 0)
    };
  }

  function count() {
    return items.reduce(function (sum, l) { return sum + l.qty; }, 0);
  }

  function add(id, formatId, qty) {
    qty = Math.max(1, parseInt(qty, 10) || 1);
    var existing = find(id, formatId);
    if (existing) {
      existing.qty += qty;
    } else {
      items.push({ id: id, formatId: formatId, qty: qty });
    }
    notify();
  }

  function setQty(id, formatId, qty) {
    qty = parseInt(qty, 10) || 0;
    var existing = find(id, formatId);
    if (!existing) { return; }
    if (qty <= 0) {
      remove(id, formatId);
      return;
    }
    existing.qty = qty;
    notify();
  }

  function increment(id, formatId, delta) {
    var existing = find(id, formatId);
    if (!existing) { return; }
    setQty(id, formatId, existing.qty + (delta || 1));
  }

  function remove(id, formatId) {
    items = items.filter(function (l) {
      return !(l.id === id && l.formatId === formatId);
    });
    notify();
  }

  function clear() {
    items = [];
    notify();
  }

  function subscribe(fn) {
    if (typeof fn === 'function') {
      listeners.push(fn);
      fn(publicSnapshot()); // emit current state immediately
    }
  }

  // Keep multiple open tabs/pages in sync.
  window.addEventListener('storage', function (e) {
    if (e.key === STORAGE_KEY) {
      items = load();
      for (var i = 0; i < listeners.length; i++) {
        try { listeners[i](publicSnapshot()); } catch (err) { /* noop */ }
      }
    }
  });

  window.InkwellCart = {
    add: add,
    setQty: setQty,
    increment: increment,
    remove: remove,
    clear: clear,
    count: count,
    snapshot: publicSnapshot,
    subscribe: subscribe
  };
})();
