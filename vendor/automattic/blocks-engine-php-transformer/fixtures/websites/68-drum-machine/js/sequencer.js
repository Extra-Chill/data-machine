/* =========================================================
   PULSEFORGE — Step Sequencer + Song Arranger
   Sample-accurate scheduling with a lookahead window (the
   classic "A Tale of Two Clocks" pattern). A short setInterval
   only *schedules ahead*; note timing is anchored to
   ctx.currentTime, so it never drifts.

   Data model:
     pattern = { grid, bpm, swing, steps }   (grid from kits.js)
     patterns[0..N]  — slots A,B,C,D…
     song = [ {pattern: idx, repeats: n}, … ]  (chain mode)
   ========================================================= */

(function (global) {
  'use strict';

  var LOOKAHEAD_MS = 25;
  var SCHEDULE_AHEAD = 0.10;
  var K = global.PulseforgeKits;
  var VOICES = global.Pulseforge.VOICES;

  function newPattern(steps) {
    return { grid: K.emptyGrid(), bpm: 120, swing: 0, steps: steps || 16 };
  }

  function Sequencer(engine) {
    this.engine = engine;
    this.patterns = [];
    for (var i = 0; i < 4; i++) this.patterns.push(newPattern(16)); // A B C D
    this.current = 0;           // active pattern index
    this.steps = 16;
    this.bpm = 120;
    this.swing = 0;
    this.metronome = false;

    this.step = 0;              // step about to be scheduled
    this.nextNoteTime = 0;
    this.isPlaying = false;
    this.timer = null;
    this._queued = [];

    // song / chain mode
    this.songMode = false;
    this.song = [];             // [{pattern, repeats}]
    this.songIndex = 0;
    this.songRepeat = 0;

    this.onStep = null;         // (stepIndex, patternIndex) for UI
    this.onPatternChange = null;
  }

  Sequencer.prototype.pattern = function () { return this.patterns[this.current]; };

  Sequencer.prototype._sps = function () { return (60.0 / this.bpm) / 4; }; // 16th note

  Sequencer.prototype._advance = function () {
    var spb = this._sps();
    var dur = (this.step % 2 === 1) ? spb * (1 + this.swing) : spb * (1 - this.swing);
    this.nextNoteTime += dur;
    this.step++;
    if (this.step >= this.steps) {
      this.step = 0;
      if (this.songMode && this.song.length) this._advanceSong();
    }
  };

  Sequencer.prototype._advanceSong = function () {
    this.songRepeat++;
    var slot = this.song[this.songIndex];
    if (slot && this.songRepeat >= slot.repeats) {
      this.songRepeat = 0;
      this.songIndex = (this.songIndex + 1) % this.song.length;
    }
    var next = this.song[this.songIndex];
    if (next) this._setActive(next.pattern, true);
  };

  Sequencer.prototype._setActive = function (idx, fromSong) {
    if (idx === this.current) return;
    this.current = idx;
    var p = this.patterns[idx];
    this.steps = p.steps;
    this.bpm = p.bpm;
    this.swing = p.swing;
    if (this.onPatternChange) this.onPatternChange(idx, fromSong);
  };

  Sequencer.prototype._scheduleStep = function (step, time) {
    var e = this.engine, g = this.pattern().grid;
    for (var i = 0; i < VOICES.length; i++) {
      var id = VOICES[i].id, lane = g[id];
      if (lane && lane.hits[step]) {
        e.trigger(id, time, Math.min(1.3, lane.vel[step]));
      }
    }
    if (this.metronome) {
      // synth click via cowbell-ish blip on the engine context
      this._metro(time, step % this.steps === 0);
    }
    this._queued.push({ step: step, pat: this.current, time: time });
  };

  Sequencer.prototype._metro = function (time, downbeat) {
    var c = this.engine.ctx;
    var o = c.createOscillator(); o.type = 'square';
    o.frequency.value = downbeat ? 1600 : 1000;
    var g = c.createGain();
    g.gain.setValueAtTime(0.0001, time);
    g.gain.linearRampToValueAtTime(downbeat ? 0.18 : 0.1, time + 0.001);
    g.gain.exponentialRampToValueAtTime(0.0001, time + 0.03);
    o.connect(g); g.connect(this.engine.master);
    o.start(time); o.stop(time + 0.04);
  };

  Sequencer.prototype._tick = function () {
    var e = this.engine;
    if (!e.ready) return;
    while (this.nextNoteTime < e.now() + SCHEDULE_AHEAD) {
      this._scheduleStep(this.step, this.nextNoteTime);
      this._advance();
    }
    var now = e.now();
    while (this._queued.length && this._queued[0].time <= now) {
      var ev = this._queued.shift();
      if (this.onStep) this.onStep(ev.step, ev.pat);
    }
  };

  Sequencer.prototype.start = function () {
    if (this.isPlaying || !this.engine.ready) return;
    this.isPlaying = true;
    this.step = 0;
    if (this.songMode && this.song.length) {
      this.songIndex = 0; this.songRepeat = 0;
      this._setActive(this.song[0].pattern, true);
    }
    this.nextNoteTime = this.engine.now() + 0.06;
    this._queued = [];
    var self = this;
    this.timer = setInterval(function () { self._tick(); }, LOOKAHEAD_MS);
  };

  Sequencer.prototype.stop = function () {
    this.isPlaying = false;
    if (this.timer) { clearInterval(this.timer); this.timer = null; }
    this._queued = [];
    if (this.onStep) this.onStep(-1, this.current);
  };

  Sequencer.prototype.toggle = function () { if (this.isPlaying) this.stop(); else this.start(); };

  Sequencer.prototype.setBpm = function (bpm) {
    this.bpm = Math.max(40, Math.min(240, Math.round(bpm)));
    this.pattern().bpm = this.bpm;
  };
  Sequencer.prototype.setSwing = function (a) {
    this.swing = Math.max(0, Math.min(0.6, a));
    this.pattern().swing = this.swing;
  };
  Sequencer.prototype.setSteps = function (n) {
    n = Math.max(4, Math.min(16, n));
    this.steps = n; this.pattern().steps = n;
    if (this.step >= n) this.step = 0;
  };

  /* ── cell editing ───────────────────────────────────────── */
  Sequencer.prototype.toggleCell = function (id, step) {
    var lane = this.pattern().grid[id];
    if (!lane) return false;
    lane.hits[step] = !lane.hits[step];
    if (lane.hits[step] && lane.vel[step] == null) lane.vel[step] = 1;
    return lane.hits[step];
  };
  Sequencer.prototype.setVel = function (id, step, v) {
    var lane = this.pattern().grid[id];
    if (lane) lane.vel[step] = Math.max(0.1, Math.min(1.3, v));
  };
  Sequencer.prototype.cycleVel = function (id, step) {
    var lane = this.pattern().grid[id];
    if (!lane || !lane.hits[step]) return;
    // normal 1.0 → accent 1.2 → ghost 0.55 → normal
    var v = lane.vel[step];
    if (v >= 1.15) lane.vel[step] = 0.55;
    else if (v <= 0.6) lane.vel[step] = 1.0;
    else lane.vel[step] = 1.2;
    return lane.vel[step];
  };

  /* ── pattern operations ─────────────────────────────────── */
  Sequencer.prototype.selectPattern = function (idx) {
    if (idx < 0 || idx >= this.patterns.length) return;
    this.current = idx;
    var p = this.patterns[idx];
    this.steps = p.steps; this.bpm = p.bpm; this.swing = p.swing;
  };
  Sequencer.prototype.clearPattern = function () {
    this.pattern().grid = K.emptyGrid();
  };
  Sequencer.prototype.copyTo = function (destIdx) {
    var src = this.pattern();
    this.patterns[destIdx] = JSON.parse(JSON.stringify(src));
  };
  Sequencer.prototype.randomize = function (density) {
    density = density == null ? 0.28 : density;
    var g = K.emptyGrid();
    var n = this.steps;
    // weighted: kick on strong beats, hats dense, snare backbeat-ish
    function maybe(prob) { return Math.random() < prob; }
    for (var s = 0; s < n; s++) {
      var strong = (s % 4 === 0);
      if (maybe(strong ? 0.7 : density * 0.6)) { g.kick.hits[s] = true; }
      if ((s % 8 === 4) && maybe(0.85)) { g.snare.hits[s] = true; }
      else if (maybe(density * 0.4)) { g.snare.hits[s] = true; g.snare.vel[s] = 0.55; }
      if (maybe(0.55)) { g.closed.hits[s] = true; g.closed.vel[s] = maybe(0.4) ? 0.55 : 1; }
      if (s % 4 === 2 && maybe(0.4)) { g.open.hits[s] = true; }
      if (maybe(density * 0.25)) { var lane = maybe(0.5) ? 'lowtom' : 'clap'; g[lane].hits[s] = true; }
    }
    this.pattern().grid = g;
  };
  Sequencer.prototype.shiftPattern = function (dir) {
    // rotate every lane left/right by one step
    var g = this.pattern().grid, n = this.steps;
    for (var id in g) {
      var lane = g[id];
      var hits = lane.hits.slice(0, n), vel = lane.vel.slice(0, n);
      if (dir > 0) { hits.unshift(hits.pop()); vel.unshift(vel.pop()); }
      else { hits.push(hits.shift()); vel.push(vel.shift()); }
      for (var i = 0; i < n; i++) { lane.hits[i] = hits[i]; lane.vel[i] = vel[i]; }
    }
  };

  Sequencer.prototype.loadGroove = function (groove) {
    var p = this.pattern();
    p.grid = K.grid(groove.grid);
    p.steps = 16; this.steps = 16;
    if (groove.bpm) { p.bpm = groove.bpm; this.bpm = groove.bpm; }
    if (groove.swing != null) { p.swing = groove.swing; this.swing = groove.swing; }
  };

  /* ── song / chain mode ──────────────────────────────────── */
  Sequencer.prototype.setSongMode = function (on) { this.songMode = on; };
  Sequencer.prototype.songAdd = function (patternIdx, repeats) {
    this.song.push({ pattern: patternIdx, repeats: repeats || 1 });
  };
  Sequencer.prototype.songRemove = function (i) { this.song.splice(i, 1); };
  Sequencer.prototype.songClear = function () { this.song = []; };

  /* ── serialization (export/import) ──────────────────────── */
  Sequencer.prototype.toJSON = function (kitId) {
    return {
      app: 'pulseforge', version: 1,
      kit: kitId || null,
      currentPattern: this.current,
      patterns: this.patterns,
      song: this.song,
      songMode: this.songMode
    };
  };
  Sequencer.prototype.fromJSON = function (data) {
    if (!data || !data.patterns) return false;
    this.patterns = data.patterns.map(function (p) {
      // ensure all voices present
      var g = K.emptyGrid();
      for (var id in g) if (p.grid[id]) g[id] = p.grid[id];
      return { grid: g, bpm: p.bpm || 120, swing: p.swing || 0, steps: p.steps || 16 };
    });
    while (this.patterns.length < 4) this.patterns.push(newPattern(16));
    this.song = data.song || [];
    this.songMode = !!data.songMode;
    this.selectPattern(data.currentPattern || 0);
    return true;
  };

  Sequencer.prototype.newPattern = newPattern;
  global.PulseforgeSequencer = Sequencer;

})(window);
