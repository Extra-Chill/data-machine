/* =========================================================
   FRAGMENT FOUNDRY — Shared site behaviours
   Mobile nav toggle, header scroll state, current-year,
   localStorage helpers, and a reduced-motion query that
   every page reuses.
   ========================================================= */
(function () {
  'use strict';
  var FF = (window.FF = window.FF || {});

  FF.prefersReducedMotion = function () {
    return window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  };

  /* localStorage with a namespace + graceful failure */
  var NS = 'fragment-foundry:';
  FF.store = {
    get: function (key, fallback) {
      try {
        var v = localStorage.getItem(NS + key);
        return v === null ? fallback : JSON.parse(v);
      } catch (e) { return fallback; }
    },
    set: function (key, value) {
      try { localStorage.setItem(NS + key, JSON.stringify(value)); }
      catch (e) { /* private mode / quota — ignore */ }
    },
    remove: function (key) {
      try { localStorage.removeItem(NS + key); } catch (e) {}
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    // current year
    var yr = document.querySelectorAll('[data-year]');
    yr.forEach(function (el) { el.textContent = new Date().getFullYear(); });

    // header scroll state
    var header = document.querySelector('.site-header');
    if (header) {
      var onScroll = function () {
        header.classList.toggle('scrolled', window.scrollY > 24);
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
    }

    // mobile nav
    var toggle = document.querySelector('.nav-toggle');
    var nav = document.querySelector('.site-nav');
    if (toggle && nav) {
      toggle.addEventListener('click', function () {
        var open = nav.classList.toggle('open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      nav.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () {
          nav.classList.remove('open');
          toggle.setAttribute('aria-expanded', 'false');
        });
      });
    }

    // mark active nav link
    var path = location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.site-nav a').forEach(function (a) {
      var href = a.getAttribute('href');
      if (href === path) a.classList.add('active');
    });
  });

})();
