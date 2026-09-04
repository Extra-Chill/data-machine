/* ============================================================
   Keystroke — main test page controller.
   Wires settings → test generation → live typing → results.
   ============================================================ */
(function (global) {
  'use strict';

  var Store = global.KeystrokeStore;
  var Site = global.KeystrokeSite;

  var els = {};
  var settings, test, kb;
  var tickTimer = null, sampleTimer = null, rafId = null;
  var blurPaused = false;

  function $(sel) { return document.querySelector(sel); }
  function $all(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }

  function cacheEls() {
    els.words = $('#words');
    els.caret = $('#caret');
    els.field = $('#field');
    els.wpm = $('#stat-wpm');
    els.acc = $('#stat-acc');
    els.time = $('#stat-time');
    els.err = $('#stat-err');
    els.progress = $('#progress-fill');
    els.results = $('#results');
    els.live = $('#live-stats');
    els.testArea = $('#test-area');
    els.focusNote = $('#focus-note');
    els.kbMount = $('#keyboard');
    els.best = $('#best-badge');
  }

  /* ---------- option bar ---------- */
  function buildOptionBar() {
    // mode buttons
    $all('[data-mode]').forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-mode') === settings.mode);
      b.addEventListener('click', function () {
        settings.mode = b.getAttribute('data-mode');
        Store.saveSettings(settings);
        syncSubOptions();
        reset();
      });
    });
    // duration / wordcount sub-options
    $all('[data-duration]').forEach(function (b) {
      b.addEventListener('click', function () {
        settings.duration = parseInt(b.getAttribute('data-duration'), 10);
        Store.saveSettings(settings); syncSubOptions(); reset();
      });
    });
    $all('[data-wordcount]').forEach(function (b) {
      b.addEventListener('click', function () {
        settings.wordCount = parseInt(b.getAttribute('data-wordcount'), 10);
        Store.saveSettings(settings); syncSubOptions(); reset();
      });
    });
    // toggles
    var pt = $('#opt-punct'), nm = $('#opt-num');
    if (pt) { pt.checked = settings.punctuation; pt.addEventListener('change', function () { settings.punctuation = pt.checked; Store.saveSettings(settings); reset(); }); }
    if (nm) { nm.checked = settings.numbers; nm.addEventListener('change', function () { settings.numbers = nm.checked; Store.saveSettings(settings); reset(); }); }
    // theme + sound
    var th = $('#opt-theme');
    if (th) {
      th.value = settings.theme;
      th.addEventListener('change', function () { settings.theme = th.value; Store.saveSettings(settings); Site.applyTheme(th.value); refreshHeatmap(); });
    }
    var sd = $('#opt-sound');
    if (sd) { sd.checked = settings.sound; sd.addEventListener('change', function () { settings.sound = sd.checked; Store.saveSettings(settings); }); }
    syncSubOptions();
  }

  function syncSubOptions() {
    $all('[data-mode]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-mode') === settings.mode); });
    var durRow = $('#sub-duration'), wordRow = $('#sub-words');
    if (durRow) durRow.style.display = settings.mode === 'time' ? '' : 'none';
    if (wordRow) wordRow.style.display = settings.mode === 'words' ? '' : 'none';
    $all('[data-duration]').forEach(function (b) { b.classList.toggle('active', parseInt(b.getAttribute('data-duration'), 10) === settings.duration); });
    $all('[data-wordcount]').forEach(function (b) { b.classList.toggle('active', parseInt(b.getAttribute('data-wordcount'), 10) === settings.wordCount); });
  }

  /* ---------- rendering the target text ---------- */
  function renderTarget() {
    els.words.innerHTML = '';
    var frag = document.createDocumentFragment();
    for (var i = 0; i < test.target.length; i++) {
      var span = document.createElement('span');
      span.className = 'ch';
      var c = test.target[i];
      span.textContent = c;
      if (c === ' ') span.classList.add('space');
      frag.appendChild(span);
    }
    els.words.appendChild(frag);
    els.chSpans = els.words.querySelectorAll('.ch');
    paintFrom(0);
    moveCaret();
  }

  function paintFrom(start) {
    for (var i = start; i < els.chSpans.length; i++) {
      var span = els.chSpans[i];
      span.className = 'ch' + (test.target[i] === ' ' ? ' space' : '');
      if (i < test.caret) {
        span.classList.add(test.typed[i] === test.target[i] ? 'good' : 'bad');
        if (test.typed[i] !== test.target[i] && test.target[i] === ' ') span.classList.add('bad-space');
      }
    }
  }

  function moveCaret() {
    var idx = test.caret;
    var target = els.chSpans[idx] || els.chSpans[els.chSpans.length - 1];
    if (!target) return;
    var areaRect = els.words.getBoundingClientRect();
    var r = target.getBoundingClientRect();
    var atEnd = idx >= els.chSpans.length;
    var x = (atEnd ? r.right : r.left) - areaRect.left;
    var y = r.top - areaRect.top;
    els.caret.style.transform = 'translate(' + x + 'px,' + y + 'px)';
    els.caret.style.height = r.height + 'px';
    // keep current line in view
    var lineTop = target.offsetTop;
    if (lineTop > els.words.clientHeight - r.height * 1.5) {
      els.words.scrollTop = lineTop - r.height;
    }
    if (kb) kb.setNext(test.target[idx]);
  }

  /* ---------- the loop ---------- */
  function startLoops() {
    stopLoops();
    sampleTimer = setInterval(function () { if (!blurPaused) test.sample(); }, 1000);
    function frame() {
      updateLiveStats();
      rafId = requestAnimationFrame(frame);
    }
    rafId = requestAnimationFrame(frame);
  }
  function stopLoops() {
    if (sampleTimer) clearInterval(sampleTimer);
    if (rafId) cancelAnimationFrame(rafId);
    sampleTimer = rafId = null;
  }

  function updateLiveStats() {
    var s = test.liveStats();
    els.wpm.textContent = s.wpm;
    els.acc.textContent = s.acc + '%';
    els.err.textContent = s.errors;
    if (settings.mode === 'time') {
      var remaining = Math.max(0, settings.duration - s.elapsed);
      els.time.textContent = Math.ceil(remaining);
      els.progress.style.width = (100 * Math.min(1, s.elapsed / settings.duration)) + '%';
      if (test.started && s.elapsed >= settings.duration) { test.finish(); finishUp(); }
    } else {
      els.time.textContent = Math.floor(s.elapsed);
      els.progress.style.width = (100 * (test.caret / test.target.length)) + '%';
    }
  }

  /* ---------- input handling ---------- */
  function onKeyDown(e) {
    if (!els.results.hidden) {
      // results screen: Tab+Enter or Enter to go next
      if (e.key === 'Enter') { e.preventDefault(); reset(); focusField(); }
      return;
    }
    if (e.key === 'Tab') {
      e.preventDefault();
      reset(); focusField();
      return;
    }
    if (e.key === 'Backspace') {
      e.preventDefault();
      if (test.backspace()) { paintFrom(test.caret); moveCaret(); }
      return;
    }
    if (e.key === 'Escape') { reset(); return; }
    if (e.key.length !== 1) return; // ignore arrows, shift, etc.
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    e.preventDefault();
    handleChar(e.key);
  }

  function handleChar(ch) {
    var wasStarted = test.started;
    var res = test.input(ch);
    if (!res) return;
    if (!wasStarted) startLoops();
    Store.recordKey(res.expected, !res.ok);
    if (kb) kb.flash(res.typed, res.ok);
    Site.click(res.ok);
    paintFrom(res.index);
    moveCaret();
    if (test.finished) finishUp();
  }

  /* ---------- finishing ---------- */
  function finishUp() {
    stopLoops();
    var r = test.result();
    var prevBest = Store.bestFor(r.signature);
    Store.addResult(r);
    showResults(r, prevBest);
    refreshHeatmap();
    refreshBestBadge();
  }

  function showResults(r, prevBest) {
    els.testArea.hidden = true;
    els.results.hidden = false;
    $('#r-wpm').textContent = r.wpm;
    $('#r-acc').textContent = r.acc + '%';
    $('#r-raw').textContent = r.raw;
    $('#r-cons').textContent = r.consistency + '%';
    $('#r-chars').textContent = r.chars.correct + '/' + r.chars.strokes;
    $('#r-time').textContent = r.duration + 's';
    $('#r-mode').textContent = r.signature;
    var pb = $('#r-best');
    if (r.wpm > prevBest && prevBest > 0) pb.textContent = 'New personal best! (was ' + prevBest + ' wpm)';
    else if (prevBest === 0) pb.textContent = 'First run for this mode — that’s your best so far.';
    else pb.textContent = 'Personal best for this mode: ' + prevBest + ' wpm';

    // per-second chart: wpm + raw
    var canvas = $('#r-chart');
    var pts = r.samples.map(function (s) { return { x: s.t, y: s.wpm }; });
    var raw = r.samples.map(function (s) { return { x: s.t, y: s.raw }; });
    if (pts.length === 0) pts = [{ x: 0, y: r.wpm }];
    global.KeystrokeChart.lineChart(canvas, [
      { points: raw, color: getCss('--muted'), width: 1.5 },
      { points: pts, color: getCss('--accent'), width: 2.5, fill: true, dots: pts.length < 30 }
    ], { xLabel: 'seconds' });
  }

  function getCss(n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim() || '#fff'; }

  /* ---------- lifecycle ---------- */
  function reset() {
    stopLoops();
    test = new global.TypingTest(settings);
    els.results.hidden = true;
    els.testArea.hidden = false;
    els.progress.style.width = '0%';
    els.wpm.textContent = '0'; els.acc.textContent = '100%'; els.err.textContent = '0';
    els.time.textContent = settings.mode === 'time' ? settings.duration : '0';
    renderTarget();
  }

  function focusField() { if (els.field) els.field.focus(); document.body.classList.remove('blurred'); blurPaused = false; if (els.focusNote) els.focusNote.hidden = true; }

  function refreshHeatmap() { if (kb) kb.applyHeatmap(Store.getKeyStats()); }
  function refreshBestBadge() {
    if (!els.best) return;
    var sig = settings.mode + ':' +
      (settings.mode === 'time' ? settings.duration + 's' : settings.mode === 'words' ? settings.wordCount + 'w' : 'quote') +
      (settings.punctuation ? '+p' : '') + (settings.numbers ? '+n' : '');
    var b = Store.bestFor(sig);
    els.best.textContent = b > 0 ? ('best ' + b + ' wpm') : 'no record yet';
  }

  function init() {
    cacheEls();
    settings = Store.getSettings();
    Site.applyTheme(settings.theme);
    kb = new global.Keyboard(els.kbMount);
    buildOptionBar();
    refreshHeatmap();
    refreshBestBadge();
    reset();

    // The whole test area is a focusable surface that captures typing.
    els.field.addEventListener('keydown', onKeyDown);
    els.testArea.addEventListener('click', focusField);
    $('#btn-restart').addEventListener('click', function () { reset(); focusField(); });
    $('#btn-next').addEventListener('click', function () { reset(); focusField(); });

    // focus management: pause + dim when the field loses focus mid-run
    els.field.addEventListener('blur', function () {
      if (test.started && !test.finished) {
        blurPaused = true;
        document.body.classList.add('blurred');
        if (els.focusNote) els.focusNote.hidden = false;
      }
    });
    els.field.addEventListener('focus', function () {
      document.body.classList.remove('blurred');
      blurPaused = false;
      if (els.focusNote) els.focusNote.hidden = true;
    });

    window.addEventListener('resize', function () { if (test && !test.finished) moveCaret(); });
    focusField();
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})(window);
