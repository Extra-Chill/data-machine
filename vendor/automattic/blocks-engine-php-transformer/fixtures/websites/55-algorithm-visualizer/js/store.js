/* =========================================================
   STEPWISE — shared preferences store
   Persists theme + preferred playback speed in localStorage,
   wires the theme toggle, and marks the active nav link.
   ========================================================= */
(function () {
  'use strict';

  var KEY = 'stepwise.prefs.v1';

  var defaults = {
    theme: 'dark',     // 'dark' | 'light'
    speed: 6           // steps per second baseline (1..30)
  };

  function read() {
    try {
      var raw = localStorage.getItem(KEY);
      if (!raw) return Object.assign({}, defaults);
      var obj = JSON.parse(raw);
      return Object.assign({}, defaults, obj);
    } catch (e) {
      return Object.assign({}, defaults);
    }
  }

  function write(prefs) {
    try { localStorage.setItem(KEY, JSON.stringify(prefs)); } catch (e) { /* ignore */ }
  }

  var prefs = read();

  var listeners = [];

  var Store = {
    get: function (k) { return prefs[k]; },
    set: function (k, v) {
      prefs[k] = v;
      write(prefs);
      listeners.forEach(function (fn) { fn(k, v); });
    },
    on: function (fn) { listeners.push(fn); },
    all: function () { return Object.assign({}, prefs); }
  };

  // Apply theme to <html> as early as possible
  function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');
  }
  applyTheme(prefs.theme);

  // Wire up theme toggle + active nav once DOM is ready
  function init() {
    var toggle = document.querySelector('.theme-toggle');
    if (toggle) {
      toggle.addEventListener('click', function () {
        var next = (Store.get('theme') === 'light') ? 'dark' : 'light';
        Store.set('theme', next);
        applyTheme(next);
      });
    }

    // Mark the active nav link by filename
    var here = location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.site-nav a').forEach(function (a) {
      var href = (a.getAttribute('href') || '').split('/').pop();
      if (href === here || (here === '' && href === 'index.html')) {
        a.classList.add('active');
        a.setAttribute('aria-current', 'page');
      }
    });

    // Update copyright year placeholders
    document.querySelectorAll('[data-year]').forEach(function (el) {
      el.textContent = new Date().getFullYear();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.StepwiseStore = Store;
})();
