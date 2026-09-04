/* =========================================================
   VOLTROVE — shared site chrome (nav toggle, year, audio
   preview on the patches gallery page).
   ========================================================= */

(function () {
  'use strict';

  // mobile nav toggle
  const toggle = document.querySelector('.nav-toggle');
  const nav = document.querySelector('.site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      const open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // footer year
  document.querySelectorAll('[data-year]').forEach(function (el) {
    el.textContent = new Date().getFullYear();
  });

  /* ── Patch gallery audition (patches.html) ──────────────
     Each .audition button plays a short auto-played phrase of a
     factory patch so visitors can hear it without leaving the page. */
  const auditionBtns = document.querySelectorAll('[data-audition]');
  if (auditionBtns.length && window.Voltrove && window.VoltrovePatches) {
    const V = window.Voltrove;
    const VP = window.VoltrovePatches;
    let engine = null;
    let playingId = null;
    let stopTimer = null;

    function getPatch(id) {
      return VP.FACTORY_PATCHES.filter(function (p) { return p.id === id; })[0];
    }

    function stopAll() {
      if (engine) engine.panic();
      if (playingId) {
        const prev = document.querySelector('[data-audition="' + playingId + '"]');
        if (prev) prev.classList.remove('playing');
      }
      playingId = null;
      clearTimeout(stopTimer);
    }

    auditionBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        const id = btn.getAttribute('data-audition');
        if (!engine) { engine = new V.SynthEngine(); if (!engine.supported) { btn.textContent = 'No audio'; btn.disabled = true; return; } engine.init(); }
        engine.resume();

        if (playingId === id) { stopAll(); btn.classList.remove('playing'); return; }
        stopAll();

        const fp = getPatch(id);
        if (!fp) return;
        engine.loadPatch(fp.patch);

        // a short phrase — arpeggio that suits most patches
        const t0 = engine.now() + 0.05;
        const phrase = [60, 63, 67, 70, 72, 70, 67, 63];
        const step = 0.22;
        phrase.forEach(function (m, i) {
          engine.trigger(m, t0 + i * step, step * 1.6, 0.8);
        });

        playingId = id;
        btn.classList.add('playing');
        stopTimer = setTimeout(function () {
          btn.classList.remove('playing');
          playingId = null;
        }, (phrase.length * step + 1.5) * 1000);
      });
    });
  }

  /* ── About page: tiny signal-chain visual is pure CSS/SVG ── */
})();
