/* ============================================================
   Keystroke — typing engine.
   Flat character-stream model: the target is a single string;
   the typist types it character-by-character (spaces included).
   A wrong key still advances so it can be corrected; backspace
   steps back. All stats derive from this model.

   WPM  = (correctChars / 5) / minutes        (net)
   raw  = (allTypedChars / 5) / minutes
   acc  = correctKeystrokes / totalKeystrokes
   ============================================================ */
(function (global) {
  'use strict';

  var W = global.KeystrokeWords;

  function pick(arr, rng) { return arr[Math.floor(rng() * arr.length)]; }

  // Small seeded PRNG so a generated test is stable until restarted.
  function mulberry32(a) {
    return function () {
      a |= 0; a = a + 0x6D2B79F5 | 0;
      var t = Math.imul(a ^ a >>> 15, 1 | a);
      t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
      return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
  }

  function makeWord(rng, opts) {
    var w = pick(W.WORDS, rng);
    if (opts.numbers && rng() < 0.12) {
      w = String(Math.floor(rng() * 1000));
    }
    if (opts.punctuation && rng() < 0.18) {
      if (rng() < 0.5) w = w + pick(W.MID, rng);
      else w = w + pick(W.SENTENCE_END, rng);
    }
    if (opts.punctuation && rng() < 0.08) {
      w = w.charAt(0).toUpperCase() + w.slice(1);
    }
    return w;
  }

  // Build the target string for a test.
  function buildTarget(settings, seed) {
    var rng = mulberry32(seed);
    if (settings.mode === 'quote') {
      var q = W.QUOTES[Math.floor(rng() * W.QUOTES.length)];
      return { text: q.text, meta: q.source, wordTarget: null };
    }
    var count = settings.mode === 'words' ? settings.wordCount : 120; // time mode gets a long buffer
    var parts = [];
    for (var i = 0; i < count; i++) parts.push(makeWord(rng, settings));
    return { text: parts.join(' '), meta: null, wordTarget: settings.mode === 'words' ? count : null };
  }

  function TypingTest(settings) {
    this.settings = settings;
    this.seed = (Date.now() ^ Math.floor(Math.random() * 1e9)) >>> 0;
    var built = buildTarget(settings, this.seed);
    this.target = built.text;
    this.meta = built.meta;
    this.typed = [];          // array of typed chars (parallel to target index)
    this.caret = 0;           // next index to type
    this.started = false;
    this.finished = false;
    this.startTime = 0;
    this.endTime = 0;
    this.keystrokes = 0;      // every printable key (not backspace)
    this.errorStrokes = 0;    // printable keys that did not match target at that moment
    this.samples = [];        // { t, wpm, raw, errors }
    this.signature = settings.mode + ':' +
      (settings.mode === 'time' ? settings.duration + 's' :
       settings.mode === 'words' ? settings.wordCount + 'w' : 'quote') +
      (settings.punctuation ? '+p' : '') + (settings.numbers ? '+n' : '');
  }

  TypingTest.prototype.minutes = function (now) {
    var t = (this.finished ? this.endTime : (now || Date.now())) - this.startTime;
    return Math.max(t, 1) / 60000;
  };

  // Count correct chars among typed positions.
  TypingTest.prototype.correctChars = function () {
    var n = 0;
    for (var i = 0; i < this.typed.length; i++) {
      if (this.typed[i] === this.target[i]) n++;
    }
    return n;
  };

  TypingTest.prototype.liveStats = function (now) {
    var mins = this.minutes(now);
    var correct = this.correctChars();
    var wpm = (correct / 5) / mins;
    var raw = (this.caret / 5) / mins;
    var acc = this.keystrokes ? (this.keystrokes - this.errorStrokes) / this.keystrokes : 1;
    var errorsNow = 0;
    for (var i = 0; i < this.typed.length; i++) if (this.typed[i] !== this.target[i]) errorsNow++;
    return {
      wpm: Math.max(0, Math.round(wpm)),
      raw: Math.max(0, Math.round(raw)),
      acc: Math.round(acc * 100),
      errors: errorsNow,
      elapsed: this.started ? (this.finished ? this.endTime - this.startTime : (now || Date.now()) - this.startTime) / 1000 : 0
    };
  };

  // Returns 'correct' | 'wrong' | null (no-op) describing what happened, plus the typed char.
  TypingTest.prototype.input = function (ch) {
    if (this.finished) return null;
    if (this.caret >= this.target.length) return null;
    if (!this.started) { this.started = true; this.startTime = Date.now(); }
    var expected = this.target[this.caret];
    var ok = ch === expected;
    this.typed[this.caret] = ch;
    this.caret++;
    this.keystrokes++;
    if (!ok) this.errorStrokes++;
    var result = { ok: ok, expected: expected, typed: ch, index: this.caret - 1 };
    // Words/quote mode finish: typed the whole target.
    if (this.caret >= this.target.length) {
      if (this.settings.mode !== 'time') this.finish();
    }
    return result;
  };

  TypingTest.prototype.backspace = function () {
    if (this.finished || this.caret === 0) return false;
    this.caret--;
    this.typed[this.caret] = undefined;
    this.typed.length = this.caret;
    return true;
  };

  TypingTest.prototype.sample = function (now) {
    if (!this.started || this.finished) return;
    var s = this.liveStats(now);
    this.samples.push({ t: Math.round(s.elapsed), wpm: s.wpm, raw: s.raw, errors: s.errors });
  };

  TypingTest.prototype.finish = function () {
    if (this.finished) return;
    this.finished = true;
    this.endTime = Date.now();
  };

  // Consistency: how steady the per-second wpm was (100% = no variance).
  TypingTest.prototype.consistency = function () {
    var xs = this.samples.map(function (s) { return s.wpm; }).filter(function (v) { return v > 0; });
    if (xs.length < 2) return 100;
    var mean = xs.reduce(function (a, b) { return a + b; }, 0) / xs.length;
    if (mean === 0) return 0;
    var variance = xs.reduce(function (a, b) { return a + (b - mean) * (b - mean); }, 0) / xs.length;
    var cv = Math.sqrt(variance) / mean;
    return Math.max(0, Math.round((1 - cv) * 100));
  };

  TypingTest.prototype.result = function () {
    var s = this.liveStats(this.endTime);
    return {
      wpm: s.wpm, raw: s.raw, acc: s.acc, errors: s.errors,
      consistency: this.consistency(),
      chars: { correct: this.correctChars(), typed: this.caret, total: this.target.length, strokes: this.keystrokes },
      duration: Math.round((this.endTime - this.startTime) / 1000),
      samples: this.samples.slice(),
      signature: this.signature,
      mode: this.settings.mode,
      date: this.endTime
    };
  };

  global.TypingTest = TypingTest;
})(window);
