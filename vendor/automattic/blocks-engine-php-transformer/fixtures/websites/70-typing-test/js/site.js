/* ============================================================
   Keystroke — shared chrome: theme application, mobile nav,
   footer year, and an optional Web Audio key-click.
   ============================================================ */
(function (global) {
  'use strict';

  var THEMES = ['midnight', 'paper', 'forest', 'sunset'];

  function applyTheme(name) {
    if (THEMES.indexOf(name) === -1) name = 'midnight';
    document.documentElement.setAttribute('data-theme', name);
  }

  // Lazy Web Audio click — created on demand, never auto-plays.
  var actx = null;
  function click(ok) {
    var s = global.KeystrokeStore.getSettings();
    if (!s.sound) return;
    if (global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    try {
      if (!actx) actx = new (global.AudioContext || global.webkitAudioContext)();
      if (actx.state === 'suspended') actx.resume();
      var o = actx.createOscillator(), g = actx.createGain();
      o.type = 'square';
      o.frequency.value = ok ? 880 : 220;
      g.gain.setValueAtTime(0.0001, actx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.05, actx.currentTime + 0.005);
      g.gain.exponentialRampToValueAtTime(0.0001, actx.currentTime + 0.06);
      o.connect(g); g.connect(actx.destination);
      o.start(); o.stop(actx.currentTime + 0.07);
    } catch (e) { /* no audio */ }
  }

  function initChrome() {
    var s = global.KeystrokeStore.getSettings();
    applyTheme(s.theme);
    // mobile nav
    var toggle = document.querySelector('.nav-toggle');
    var nav = document.querySelector('.site-nav');
    if (toggle && nav) {
      toggle.addEventListener('click', function () {
        var open = nav.classList.toggle('open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }
    var y = document.querySelector('[data-year]');
    if (y) y.textContent = new Date().getFullYear();
  }

  global.KeystrokeSite = { applyTheme: applyTheme, click: click, initChrome: initChrome, THEMES: THEMES };
  if (document.readyState !== 'loading') initChrome();
  else document.addEventListener('DOMContentLoaded', initChrome);
})(window);
