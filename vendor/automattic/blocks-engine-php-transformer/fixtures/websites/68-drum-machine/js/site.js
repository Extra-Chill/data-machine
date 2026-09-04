/* =========================================================
   PULSEFORGE — shared site chrome
   Mobile nav toggle, footer year, and the "audition" buttons
   on patterns.html that play a one-shot voice or a short loop
   of a factory groove without leaving the page.
   ========================================================= */

(function (global) {
  'use strict';

  // mobile nav toggle
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.querySelector('.site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // footer year
  document.querySelectorAll('[data-year]').forEach(function (el) {
    el.textContent = new Date().getFullYear();
  });

  /* ── audition (patterns.html) ──────────────────────────── */
  var auditionBtns = document.querySelectorAll('[data-groove], [data-voice-demo]');
  if (auditionBtns.length && global.Pulseforge && global.PulseforgeKits) {
    var PF = global.Pulseforge;
    var K = global.PulseforgeKits;
    var engine = null;
    var loop = null;       // a tiny inline scheduler for groove preview
    var playingId = null;

    function ensureEngine() {
      if (!engine) {
        engine = new PF.DrumEngine();
        if (!engine.supported) return false;
        engine.init();
      }
      engine.resume();
      return true;
    }

    function stopAll() {
      if (loop) { clearInterval(loop.timer); loop = null; }
      document.querySelectorAll('.is-playing').forEach(function (b) { b.classList.remove('is-playing'); });
      playingId = null;
    }

    // ── one-shot voice demo (kit-aware via the 909 default) ──
    document.querySelectorAll('[data-voice-demo]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!ensureEngine()) { btn.textContent = 'No audio'; btn.disabled = true; return; }
        var id = btn.getAttribute('data-voice-demo');
        engine.trigger(id, engine.now() + 0.01, 1);
        btn.classList.add('is-playing');
        setTimeout(function () { btn.classList.remove('is-playing'); }, 250);
      });
    });

    // ── groove preview loop ──────────────────────────────────
    document.querySelectorAll('[data-groove]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!ensureEngine()) { btn.textContent = 'No audio'; btn.disabled = true; return; }
        var id = btn.getAttribute('data-groove');
        if (playingId === id) { stopAll(); return; }
        stopAll();

        var groove = K.grooveById(id);
        if (!groove) return;
        engine.loadKit(K.kitById(groove.kit));
        var grid = K.grid(groove.grid);
        var bpm = groove.bpm, swing = groove.swing || 0;
        var sps = (60 / bpm) / 4;

        // lookahead loop, same principle as the main sequencer
        var step = 0;
        var nextTime = engine.now() + 0.08;
        loop = { timer: null };
        loop.timer = setInterval(function () {
          while (nextTime < engine.now() + 0.1) {
            for (var vid in grid) {
              if (grid[vid].hits[step]) engine.trigger(vid, nextTime, grid[vid].vel[step]);
            }
            var dur = (step % 2 === 1) ? sps * (1 + swing) : sps * (1 - swing);
            nextTime += dur;
            step = (step + 1) % 16;
          }
        }, 25);

        btn.classList.add('is-playing');
        playingId = id;
      });
    });

    global.addEventListener('pagehide', stopAll);
  }

})(window);
