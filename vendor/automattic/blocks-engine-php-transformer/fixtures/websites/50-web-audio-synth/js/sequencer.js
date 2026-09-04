/* =========================================================
   VOLTROVE — Step Sequencer
   Sample-accurate scheduling using the Web Audio clock with a
   lookahead window (the classic "A Tale of Two Clocks" pattern).
   A short setInterval only *schedules ahead*; actual note timing
   is driven by ctx.currentTime, so it never drifts.
   ========================================================= */

(function (global) {
  'use strict';

  const LOOKAHEAD_MS = 25;        // how often the scheduler wakes up
  const SCHEDULE_AHEAD = 0.12;    // seconds of audio scheduled in advance
  const STEPS = 16;

  function Sequencer(engine) {
    this.engine = engine;
    this.bpm = 124;
    this.steps = STEPS;
    this.current = 0;             // step about to be scheduled
    this.nextNoteTime = 0;
    this.isPlaying = false;
    this.timer = null;
    this.grid = global.VoltrovePatches.emptyGrid();
    this.synthLine = new Array(STEPS).fill(null);
    this.swing = 0;               // 0..0.5 amount of shuffle
    this.onStep = null;           // callback(stepIndex, time) for UI highlight
    this._queued = [];            // {step, time} pending UI events
  }

  Sequencer.prototype._secondsPerStep = function () {
    // 16 steps = 4 beats (16th notes)
    return (60.0 / this.bpm) / 4;
  };

  Sequencer.prototype._advance = function () {
    const spb = this._secondsPerStep();
    // apply swing to odd 16ths
    let dur = spb;
    if (this.current % 2 === 1) dur = spb * (1 + this.swing);
    else dur = spb * (1 - this.swing);
    this.nextNoteTime += dur;
    this.current = (this.current + 1) % this.steps;
  };

  Sequencer.prototype._scheduleStep = function (step, time) {
    const e = this.engine;
    // drums
    const rows = global.VoltrovePatches.DRUM_ROWS;
    for (let i = 0; i < rows.length; i++) {
      const r = rows[i];
      if (this.grid[r] && this.grid[r][step]) {
        const accent = (step % 4 === 0);
        e.drum(r, time, accent);
      }
    }
    // synth line through the current patch
    const note = this.synthLine[step];
    if (note != null) {
      const dur = this._secondsPerStep() * 0.9;
      e.trigger(note, time, dur, 0.8);
    }
    // queue UI highlight
    this._queued.push({ step: step, time: time });
  };

  Sequencer.prototype._tick = function () {
    const e = this.engine;
    if (!e.ready) return;
    while (this.nextNoteTime < e.now() + SCHEDULE_AHEAD) {
      this._scheduleStep(this.current, this.nextNoteTime);
      this._advance();
    }
    // flush UI events whose time has arrived
    const now = e.now();
    while (this._queued.length && this._queued[0].time <= now) {
      const ev = this._queued.shift();
      if (this.onStep) this.onStep(ev.step, ev.time);
    }
  };

  Sequencer.prototype.start = function () {
    if (this.isPlaying || !this.engine.ready) return;
    this.isPlaying = true;
    this.current = 0;
    this.nextNoteTime = this.engine.now() + 0.06;
    this._queued = [];
    const self = this;
    this.timer = setInterval(function () { self._tick(); }, LOOKAHEAD_MS);
  };

  Sequencer.prototype.stop = function () {
    this.isPlaying = false;
    if (this.timer) { clearInterval(this.timer); this.timer = null; }
    this._queued = [];
    if (this.onStep) this.onStep(-1, 0); // clear highlight
  };

  Sequencer.prototype.toggle = function () {
    if (this.isPlaying) this.stop(); else this.start();
  };

  Sequencer.prototype.setBpm = function (bpm) {
    this.bpm = Math.max(40, Math.min(220, bpm));
  };

  Sequencer.prototype.setSwing = function (amount) {
    this.swing = Math.max(0, Math.min(0.5, amount));
  };

  Sequencer.prototype.toggleCell = function (rowOrSynth, step) {
    if (rowOrSynth === 'synth') {
      // handled by UI directly via setSynthNote
      return;
    }
    if (this.grid[rowOrSynth]) {
      this.grid[rowOrSynth][step] = !this.grid[rowOrSynth][step];
      return this.grid[rowOrSynth][step];
    }
  };

  Sequencer.prototype.setSynthNote = function (step, midi) {
    this.synthLine[step] = midi;
  };

  Sequencer.prototype.loadPattern = function (pattern) {
    const fresh = global.VoltrovePatches.emptyGrid();
    const rows = global.VoltrovePatches.DRUM_ROWS;
    rows.forEach(function (r) {
      fresh[r] = (pattern.grid[r] || new Array(STEPS).fill(false)).slice(0, STEPS);
      while (fresh[r].length < STEPS) fresh[r].push(false);
    });
    this.grid = fresh;
    this.synthLine = (pattern.synthLine || new Array(STEPS).fill(null)).slice(0, STEPS);
    while (this.synthLine.length < STEPS) this.synthLine.push(null);
    if (pattern.bpm) this.setBpm(pattern.bpm);
  };

  Sequencer.prototype.clear = function () {
    this.grid = global.VoltrovePatches.emptyGrid();
    this.synthLine = new Array(STEPS).fill(null);
  };

  global.VoltroveSequencer = Sequencer;

})(window);
