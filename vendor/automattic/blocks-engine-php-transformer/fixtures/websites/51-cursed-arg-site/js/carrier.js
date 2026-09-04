/* ============================================================
   carrier.js — gate for the hidden final page.
   Reads puzzle progress; shows locked or unlocked view.
   ============================================================ */
(function () {
  'use strict';
  var WLC = window.WLC = window.WLC || {};
  // local fallback if awareness.js load order ever changes
  if (!WLC.getProgress) {
    WLC.getProgress = function () {
      try { return JSON.parse(localStorage.getItem('wlc.progress.v1')) || {}; }
      catch (e) { return {}; }
    };
  }

  var p = WLC.getProgress();
  var solved = p.stage1 && p.stage2 && p.stage3;

  var locked = document.getElementById('locked-view');
  var unlocked = document.getElementById('carrier-view');
  if (solved) {
    if (unlocked) unlocked.hidden = false;
    if (locked) locked.hidden = true;
    document.title = 'CARRIER — chair five is yours';
  } else {
    if (locked) locked.hidden = false;
    if (unlocked) unlocked.hidden = true;
  }

  // live frequency flicker on the readout (subtle, gated)
  var reduce = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var freq = document.getElementById('live-freq');
  if (freq && !reduce) {
    setInterval(function () {
      if (Math.random() < 0.18) {
        var jitter = (14.770 + (Math.random() - 0.5) * 0.004).toFixed(3);
        freq.innerHTML = jitter + '<small> kHz</small>';
        setTimeout(function () {
          freq.innerHTML = '14.770<small> kHz</small>';
        }, 160);
      }
    }, 2200);
  }

  var reset = document.getElementById('reset-progress');
  if (reset) {
    reset.addEventListener('click', function (e) {
      e.preventDefault();
      try {
        localStorage.removeItem('wlc.progress.v1');
        localStorage.removeItem('wlc.seen');
      } catch (err) {}
      if (WLC.whisper) WLC.whisper('the watch is empty again. someone will come.');
      setTimeout(function () { window.location.href = 'index.html'; }, 1200);
    });
  }
})();
