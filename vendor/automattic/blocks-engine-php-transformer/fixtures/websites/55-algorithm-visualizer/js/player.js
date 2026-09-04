/* =========================================================
   STEPWISE — the step engine (StepPlayer)

   A reusable, algorithm-agnostic transport. An algorithm runs
   to completion up front and emits an array of discrete STEPS.
   The player then "replays" those steps: it can play, pause,
   step forward/back, scrub to any index, and change speed.

   Because the whole trace is recorded as data, stepping
   backward is just decrementing an index — no need to re-run
   or invert the algorithm. The visualizer supplies:

     opts.steps   : Array  — the recorded trace
     opts.render  : fn(index) — draw the state AT step `index`
     opts.onState : fn(state) — called on every transport change

   This same class drives sorting, pathfinding, and the BST
   visualizer. It owns nothing about WHAT is being animated.
   ========================================================= */
(function () {
  'use strict';

  var REDUCED = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function StepPlayer(opts) {
    this.steps = opts.steps || [];
    this.render = opts.render || function () {};
    this.onState = opts.onState || function () {};
    this.index = 0;                 // current step index (0..steps.length-1)
    this.playing = false;
    this.speed = opts.speed || 6;   // steps per second
    this._acc = 0;                  // time accumulator (ms)
    this._raf = null;
    this._last = 0;
    this.reducedMotion = REDUCED;
  }

  StepPlayer.prototype.load = function (steps) {
    this.pause();
    this.steps = steps || [];
    this.index = 0;
    this.draw();
    this._emit();
  };

  StepPlayer.prototype.setSpeed = function (s) {
    this.speed = Math.max(1, Math.min(60, s));
  };

  StepPlayer.prototype.length = function () { return this.steps.length; };

  StepPlayer.prototype.atEnd = function () {
    return this.index >= this.steps.length - 1;
  };

  StepPlayer.prototype.draw = function () {
    this.render(this.index);
  };

  StepPlayer.prototype._emit = function () {
    this.onState({
      index: this.index,
      total: this.steps.length,
      playing: this.playing,
      atEnd: this.atEnd(),
      atStart: this.index <= 0,
      step: this.steps[this.index] || null
    });
  };

  /* ── transport ─────────────────────────────────────────── */

  StepPlayer.prototype.play = function () {
    if (this.playing || this.steps.length === 0) return;
    if (this.atEnd()) { this.index = 0; this.draw(); } // replay from start
    this.playing = true;
    this._last = performance.now();
    this._acc = 0;
    this._emit();
    var self = this;
    var tick = function (now) {
      if (!self.playing) return;
      var dt = now - self._last;
      self._last = now;
      // guard against tab-switch jumps
      if (dt > 250) dt = 1000 / self.speed;
      self._acc += dt;
      var interval = 1000 / self.speed;
      var advanced = false;
      while (self._acc >= interval && !self.atEnd()) {
        self._acc -= interval;
        self.index++;
        advanced = true;
      }
      if (advanced) self.draw();
      if (self.atEnd()) {
        self.playing = false;
        self.draw();
        self._emit();
        return;
      }
      self._emit();
      self._raf = requestAnimationFrame(tick);
    };
    this._raf = requestAnimationFrame(tick);
  };

  StepPlayer.prototype.pause = function () {
    if (!this.playing) return;
    this.playing = false;
    if (this._raf) cancelAnimationFrame(this._raf);
    this._raf = null;
    this._emit();
  };

  StepPlayer.prototype.toggle = function () {
    if (this.playing) this.pause(); else this.play();
  };

  StepPlayer.prototype.stepForward = function () {
    this.pause();
    if (this.index < this.steps.length - 1) {
      this.index++;
      this.draw();
      this._emit();
    }
  };

  StepPlayer.prototype.stepBack = function () {
    this.pause();
    if (this.index > 0) {
      this.index--;
      this.draw();
      this._emit();
    }
  };

  StepPlayer.prototype.seek = function (i) {
    this.pause();
    this.index = Math.max(0, Math.min(this.steps.length - 1, i | 0));
    this.draw();
    this._emit();
  };

  StepPlayer.prototype.toStart = function () { this.seek(0); };
  StepPlayer.prototype.toEnd = function () { this.seek(this.steps.length - 1); };

  StepPlayer.prototype.destroy = function () {
    this.pause();
    this.steps = [];
  };

  window.StepPlayer = StepPlayer;

  /* =========================================================
     Transport UI binder — wires a StepPlayer to a standard
     set of DOM controls (the markup is identical on every
     visualizer page). Keeps keyboard handling in one place.
     ========================================================= */
  function bindTransport(player, root) {
    root = root || document;
    var $ = function (sel) { return root.querySelector(sel); };

    var playBtn = $('[data-transport="play"]');
    var backBtn = $('[data-transport="back"]');
    var fwdBtn  = $('[data-transport="forward"]');
    var startBtn = $('[data-transport="start"]');
    var endBtn  = $('[data-transport="end"]');
    var scrub   = $('[data-transport="scrub"]');
    var counter = $('[data-transport="counter"]');
    var statusPill = $('[data-transport="status"]');
    var speedInput = $('[data-transport="speed"]');
    var speedVal = $('[data-transport="speed-val"]');

    var iconPlay = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>';
    var iconPause = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>';
    var iconReplay = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v5h-5"/></svg>';

    function syncSpeedLabel() {
      if (speedVal) speedVal.textContent = player.speed + '×';
    }

    // initialise speed from store
    if (window.StepwiseStore) {
      player.setSpeed(window.StepwiseStore.get('speed'));
    }
    if (speedInput) { speedInput.value = player.speed; }
    syncSpeedLabel();

    if (playBtn) playBtn.addEventListener('click', function () { player.toggle(); });
    if (backBtn) backBtn.addEventListener('click', function () { player.stepBack(); });
    if (fwdBtn)  fwdBtn.addEventListener('click', function () { player.stepForward(); });
    if (startBtn) startBtn.addEventListener('click', function () { player.toStart(); });
    if (endBtn)  endBtn.addEventListener('click', function () { player.toEnd(); });

    if (scrub) {
      scrub.addEventListener('input', function () {
        player.seek(parseInt(scrub.value, 10));
      });
    }

    if (speedInput) {
      speedInput.addEventListener('input', function () {
        var s = parseInt(speedInput.value, 10);
        player.setSpeed(s);
        if (window.StepwiseStore) window.StepwiseStore.set('speed', s);
        syncSpeedLabel();
      });
    }

    // keyboard: space=play/pause, ←/→ step, home/end jump
    document.addEventListener('keydown', function (e) {
      var tag = (e.target && e.target.tagName) || '';
      if (tag === 'INPUT' && e.target.type !== 'range') return;
      if (tag === 'SELECT' || tag === 'TEXTAREA') return;
      if (e.key === ' ') { e.preventDefault(); player.toggle(); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); player.stepForward(); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); player.stepBack(); }
      else if (e.key === 'Home') { e.preventDefault(); player.toStart(); }
      else if (e.key === 'End') { e.preventDefault(); player.toEnd(); }
    });

    // reflect transport state into the UI
    var origOnState = player.onState;
    player.onState = function (st) {
      if (scrub) {
        scrub.max = Math.max(0, st.total - 1);
        scrub.value = st.index;
        scrub.setAttribute('aria-valuenow', st.index);
        scrub.setAttribute('aria-valuemax', Math.max(0, st.total - 1));
      }
      if (counter) {
        counter.textContent = (st.total === 0)
          ? '0 / 0'
          : (st.index + 1) + ' / ' + st.total;
      }
      if (playBtn) {
        playBtn.innerHTML = st.playing ? iconPause : (st.atEnd ? iconReplay : iconPlay);
        playBtn.setAttribute('aria-label', st.playing ? 'Pause' : (st.atEnd ? 'Replay' : 'Play'));
      }
      if (backBtn) backBtn.disabled = st.atStart;
      if (startBtn) startBtn.disabled = st.atStart;
      if (fwdBtn) fwdBtn.disabled = st.atEnd;
      if (endBtn) endBtn.disabled = st.atEnd;
      if (statusPill) {
        statusPill.classList.toggle('running', st.playing);
        statusPill.classList.toggle('done', !st.playing && st.atEnd && st.total > 0);
        var label = statusPill.querySelector('.status-label');
        if (label) {
          label.textContent = st.playing ? 'Running'
            : (st.atEnd && st.total > 0 ? 'Complete'
            : (st.index === 0 ? 'Ready' : 'Paused'));
        }
      }
      origOnState(st);
    };

    // prime once
    player.onState({
      index: player.index, total: player.steps.length,
      playing: false, atEnd: player.atEnd(),
      atStart: player.index <= 0, step: player.steps[player.index] || null
    });

    return {
      refresh: function () {
        player.onState({
          index: player.index, total: player.steps.length,
          playing: player.playing, atEnd: player.atEnd(),
          atStart: player.index <= 0, step: player.steps[player.index] || null
        });
      }
    };
  }

  window.bindTransport = bindTransport;
})();
