/* ============================================================
   awareness.js — the site "notices" the visitor.
   Reactive effects driven by cursor / idle / clock / scroll / time.
   All strobing gated under prefers-reduced-motion.
   ============================================================ */
(function () {
  'use strict';

  var reduce = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ----- shared progress store -----
  window.WLC = window.WLC || {};
  var KEY = 'wlc.progress.v1';
  WLC.getProgress = function () {
    try { return JSON.parse(localStorage.getItem(KEY)) || {}; }
    catch (e) { return {}; }
  };
  WLC.setProgress = function (obj) {
    try { localStorage.setItem(KEY, JSON.stringify(obj)); } catch (e) {}
  };
  WLC.mark = function (k) {
    var p = WLC.getProgress(); p[k] = true; WLC.setProgress(p);
    document.dispatchEvent(new CustomEvent('wlc:progress', { detail: p }));
  };

  // ----- live year / decay timestamp -----
  var now = new Date();
  document.querySelectorAll('[data-year]').forEach(function (el) {
    el.textContent = now.getFullYear();
  });

  // ----- whisper toast: short, eerie, addressed to "you" -----
  var whisper = document.getElementById('whisper');
  var firstVisit = !localStorage.getItem('wlc.seen');
  localStorage.setItem('wlc.seen', '1');

  var lines;
  if (firstVisit) {
    lines = [
      'carrier detected. someone is listening on this end too.',
      'you found us. we have been broadcasting for a long time.'
    ];
  } else {
    lines = [
      'you came back. we hoped you would.',
      'the frequency remembers you.',
      'we counted the days since you last tuned in.'
    ];
  }

  function sayWhisper(text) {
    if (!whisper) return;
    whisper.textContent = text;
    whisper.classList.add('show');
    clearTimeout(whisper._t);
    whisper._t = setTimeout(function () {
      whisper.classList.remove('show');
    }, 5200);
  }

  // initial greeting after a beat
  setTimeout(function () { sayWhisper(lines[0]); }, 3500);

  // ----- idle awareness: if you stop moving, it speaks -----
  var idleTimer = null;
  var idleMsgs = [
    'you have gone very quiet.',
    'still there? the signal holds.',
    'we can wait. we have nothing but time.',
    'are you reading, or are you being read?'
  ];
  var idleIdx = 0;
  function resetIdle() {
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(function () {
      sayWhisper(idleMsgs[idleIdx % idleMsgs.length]);
      idleIdx++;
      resetIdle();
    }, 32000);
  }
  ['mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function (ev) {
    window.addEventListener(ev, resetIdle, { passive: true });
  });
  resetIdle();

  // ----- clock-of-the-witching-hour message -----
  if (now.getHours() >= 0 && now.getHours() < 4) {
    setTimeout(function () {
      sayWhisper('it is late where you are. good. fewer ears between us.');
    }, 12000);
  }

  // ----- glitch intensifies with time on page (gated) -----
  if (!reduce) {
    // brief glitch bursts, never sustained strobe
    setInterval(function () {
      // probability rises slowly with dwell time
      var mins = (Date.now() - now.getTime()) / 60000;
      var chance = Math.min(0.12 + mins * 0.03, 0.4);
      if (Math.random() < chance) {
        document.body.classList.add('glitch-on');
        setTimeout(function () {
          document.body.classList.remove('glitch-on');
        }, 220 + Math.random() * 260);
      }
    }, 4500);
  }

  // ----- cursor proximity: a phrase appears in the title bar -----
  var baseTitle = document.title;
  var awayTitle = '[ are you still tuned in? ]';
  document.addEventListener('visibilitychange', function () {
    document.title = document.hidden ? awayTitle : baseTitle;
  });

  // ----- console breadcrumb (puzzle step 1) -----
  var css = 'color:#9fd6a8;font-family:monospace;font-size:12px;';
  console.log('%c WESTRIDGE LONGWAVE COOPERATIVE // operator console ', 'background:#0b110d;color:#d8b25a;padding:4px 8px;');
  console.log('%c> carrier wave nominal. listener present.', css);
  console.log('%c> if you are reading this, you are already part of the broadcast.', css);
  console.log('%c> FIELD LOG fragments are kept at /archive.html — five of them survived.', css);
  console.log('%c> one log is "corrupted." it is not. read it through a 13-place shift.', css);
  console.log('%c> when you know the operator\'s name, open the TERMINAL ( press ~ , or type the word OPEN ).', css);
  console.log('%c> -- W.L.C., still on frequency --', 'color:#b4533b;font-family:monospace;');

  // expose whisper for other modules
  WLC.whisper = sayWhisper;
})();
