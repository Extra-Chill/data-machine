/* =========================================================
   PULSEFORGE — RB-16 · Drum Synthesis Engine
   A dependency-free Web Audio rhythm machine. Every drum voice
   is synthesized from oscillators + filtered noise — no samples.

   Output chain:
     all voices ─► trackGain[i] ─► drumBus ─► drive(soft) ─►
                   master ─► limiter(comp) ─► analyser ─► destination
   ========================================================= */

(function (global) {
  'use strict';

  /* ── Voice catalogue ─────────────────────────────────────
     Each voice has a small set of params (tune/decay/level …)
     that genuinely reshape the synthesis below. Defaults here
     describe the "808"-ish kit; KITS (in kits.js) override them. */
  var VOICES = [
    { id: 'kick',    name: 'Kick',        color: '#ff5c7c' },
    { id: 'snare',   name: 'Snare',       color: '#ffc857' },
    { id: 'clap',    name: 'Clap',        color: '#ffa14a' },
    { id: 'rim',     name: 'Rimshot',     color: '#9be15d' },
    { id: 'closed',  name: 'Closed Hat',  color: '#5ad7ff' },
    { id: 'open',    name: 'Open Hat',    color: '#56c2ff' },
    { id: 'lowtom',  name: 'Low Tom',     color: '#c084fc' },
    { id: 'hitom',   name: 'High Tom',    color: '#d8a0ff' },
    { id: 'cowbell', name: 'Cowbell',     color: '#ffe066' },
    { id: 'cymbal',  name: 'Crash',       color: '#7ee8c0' }
  ];

  // Default per-voice parameter set. Values are 0..1 unless noted.
  function defaultParams() {
    return {
      kick:    { tune: 0.45, decay: 0.55, level: 0.95, tone: 0.40 }, // tone = click/punch
      snare:   { tune: 0.50, decay: 0.40, level: 0.80, tone: 0.55 }, // tone = noise vs body
      clap:    { tune: 0.50, decay: 0.45, level: 0.72, tone: 0.50 },
      rim:     { tune: 0.50, decay: 0.30, level: 0.62, tone: 0.50 },
      closed:  { tune: 0.55, decay: 0.22, level: 0.62, tone: 0.50 },
      open:    { tune: 0.55, decay: 0.55, level: 0.58, tone: 0.50 },
      lowtom:  { tune: 0.35, decay: 0.50, level: 0.72, tone: 0.50 },
      hitom:   { tune: 0.60, decay: 0.45, level: 0.72, tone: 0.50 },
      cowbell: { tune: 0.50, decay: 0.40, level: 0.55, tone: 0.50 },
      cymbal:  { tune: 0.50, decay: 0.70, level: 0.50, tone: 0.50 }
    };
  }

  /* map a 0..1 knob into a useful range */
  function lerp(a, b, t) { return a + (b - a) * t; }

  /* ── Engine ─────────────────────────────────────────────── */
  function DrumEngine() {
    this.ctx = null;
    this.ready = false;
    this.supported = (typeof (global.AudioContext || global.webkitAudioContext) === 'function');
    this.params = defaultParams();
    this.tracks = {};       // id → { gain, mute, solo, level }
    this.analyser = null;
    this._noiseBuf = null;
    this._driveCache = {};
  }

  DrumEngine.prototype.init = function () {
    if (this.ready || !this.supported) return this.ready;
    var Ctx = global.AudioContext || global.webkitAudioContext;
    this.ctx = new Ctx();
    var c = this.ctx;

    this.master = c.createGain();
    this.master.gain.value = 0.85;

    // soft drive on the sum so loud beats glue/clip gracefully
    this.drive = c.createWaveShaper();
    this.drive.oversample = '2x';
    this._applyDrive(0.12);

    // brick-wall-ish limiter
    this.limiter = c.createDynamicsCompressor();
    this.limiter.threshold.value = -3;
    this.limiter.knee.value = 6;
    this.limiter.ratio.value = 14;
    this.limiter.attack.value = 0.002;
    this.limiter.release.value = 0.12;

    this.analyser = c.createAnalyser();
    this.analyser.fftSize = 2048;
    this.analyser.smoothingTimeConstant = 0.72;

    this.drumBus = c.createGain();

    // per-track gains feed the bus
    var self = this;
    VOICES.forEach(function (v) {
      var g = c.createGain();
      g.gain.value = self.params[v.id].level;
      g.connect(self.drumBus);
      self.tracks[v.id] = { gain: g, mute: false, solo: false, level: self.params[v.id].level };
    });

    this.drumBus.connect(this.drive);
    this.drive.connect(this.master);
    this.master.connect(this.limiter);
    this.limiter.connect(this.analyser);
    this.analyser.connect(c.destination);

    // reusable pink-ish noise buffer (2s, looped via offset)
    this._buildNoise();

    this.ready = true;
    return true;
  };

  DrumEngine.prototype.resume = function () {
    if (!this.ready) this.init();
    if (this.ctx && this.ctx.state === 'suspended') return this.ctx.resume();
    return Promise.resolve();
  };

  DrumEngine.prototype.now = function () { return this.ctx ? this.ctx.currentTime : 0; };

  DrumEngine.prototype._buildNoise = function () {
    var c = this.ctx;
    var len = Math.floor(c.sampleRate * 2);
    var buf = c.createBuffer(1, len, c.sampleRate);
    var d = buf.getChannelData(0);
    // light pinkening for a less harsh hat
    var b0 = 0, b1 = 0, b2 = 0;
    for (var i = 0; i < len; i++) {
      var white = Math.random() * 2 - 1;
      b0 = 0.99765 * b0 + white * 0.0990460;
      b1 = 0.96300 * b1 + white * 0.2965164;
      b2 = 0.57000 * b2 + white * 1.0526913;
      d[i] = (b0 + b1 + b2 + white * 0.1848) * 0.32;
    }
    this._noiseBuf = buf;
  };

  DrumEngine.prototype._noise = function () {
    var src = this.ctx.createBufferSource();
    src.buffer = this._noiseBuf;
    src.loop = true;
    // random offset so successive hits aren't identical
    src.loopStart = 0; src.loopEnd = this._noiseBuf.duration;
    return src;
  };

  /* ── soft-clip waveshaper ───────────────────────────────── */
  DrumEngine.prototype._applyDrive = function (amount) {
    var k = Math.max(0.0001, amount) * 60;
    var key = Math.round(k);
    var curve = this._driveCache[key];
    if (!curve) {
      var n = 1024;
      curve = new Float32Array(n);
      for (var i = 0; i < n; i++) {
        var x = (i * 2) / n - 1;
        curve[i] = (1 + k) * x / (1 + k * Math.abs(x));
      }
      this._driveCache[key] = curve;
    }
    this.drive.curve = curve;
  };

  /* ── mixer ───────────────────────────────────────────────── */
  DrumEngine.prototype.setMaster = function (v) {
    if (this.ready) this.master.gain.setTargetAtTime(v, this.now(), 0.01);
  };
  DrumEngine.prototype.setDrive = function (v) { if (this.ready) this._applyDrive(v); };

  DrumEngine.prototype.setLevel = function (id, v) {
    if (!this.tracks[id]) return;
    this.tracks[id].level = v;
    this.params[id].level = v;
    this._refreshTrack(id);
  };
  DrumEngine.prototype.setMute = function (id, on) {
    if (!this.tracks[id]) return;
    this.tracks[id].mute = on; this._refreshAll();
  };
  DrumEngine.prototype.setSolo = function (id, on) {
    if (!this.tracks[id]) return;
    this.tracks[id].solo = on; this._refreshAll();
  };
  DrumEngine.prototype._anySolo = function () {
    for (var id in this.tracks) if (this.tracks[id].solo) return true;
    return false;
  };
  DrumEngine.prototype._refreshTrack = function (id) {
    if (!this.ready) return;
    var t = this.tracks[id];
    var soloMode = this._anySolo();
    var audible = soloMode ? t.solo : !t.mute;
    t.gain.gain.setTargetAtTime(audible ? t.level : 0.0001, this.now(), 0.008);
  };
  DrumEngine.prototype._refreshAll = function () {
    for (var id in this.tracks) this._refreshTrack(id);
  };

  /* ── per-voice param setter ─────────────────────────────── */
  DrumEngine.prototype.setParam = function (id, key, value) {
    if (!this.params[id]) return;
    this.params[id][key] = value;
    if (key === 'level') this.setLevel(id, value);
  };

  DrumEngine.prototype.loadKit = function (kit) {
    var dp = defaultParams();
    for (var id in dp) {
      this.params[id] = Object.assign({}, dp[id], (kit.params && kit.params[id]) || {});
      if (this.tracks[id]) {
        this.tracks[id].level = this.params[id].level;
        this._refreshTrack(id);
      }
    }
  };

  /* =========================================================
     DRUM SYNTHESIS — the heart of the machine
     trigger(id, when, velocity 0..1)
     ========================================================= */
  DrumEngine.prototype.trigger = function (id, when, velocity) {
    if (!this.ready) return;
    var c = this.ctx, t = when, p = this.params[id];
    if (!p) return;
    var vel = (velocity == null) ? 0.85 : velocity;
    var out = this.tracks[id] ? this.tracks[id].gain : this.drumBus;
    var fn = this['_v_' + id];
    if (fn) fn.call(this, c, t, p, vel, out);
  };

  // ── KICK: pitch-swept sine + transient click ──────────────
  DrumEngine.prototype._v_kick = function (c, t, p, vel, out) {
    var startF = lerp(120, 320, p.tune);
    var endF   = lerp(35, 60, p.tune);
    var dur    = lerp(0.16, 0.85, p.decay);

    var osc = c.createOscillator();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(startF, t);
    osc.frequency.exponentialRampToValueAtTime(endF, t + Math.min(0.18, dur));

    var g = c.createGain();
    g.gain.setValueAtTime(0.0001, t);
    g.gain.linearRampToValueAtTime(vel, t + 0.002);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    osc.connect(g); g.connect(out);
    osc.start(t); osc.stop(t + dur + 0.05);

    // click transient — short triangle blip, amount = tone
    var amt = p.tone;
    if (amt > 0.02) {
      var click = c.createOscillator();
      click.type = 'triangle';
      click.frequency.setValueAtTime(lerp(180, 1200, amt), t);
      var cg = c.createGain();
      cg.gain.setValueAtTime(0.6 * amt * vel, t);
      cg.gain.exponentialRampToValueAtTime(0.0001, t + 0.03);
      click.connect(cg); cg.connect(out);
      click.start(t); click.stop(t + 0.04);
    }
  };

  // ── SNARE: tuned triangle body + filtered noise ───────────
  DrumEngine.prototype._v_snare = function (c, t, p, vel, out) {
    var bodyF = lerp(140, 320, p.tune);
    var dur   = lerp(0.08, 0.35, p.decay);
    var noiseMix = lerp(0.25, 0.9, p.tone);

    // body
    var b1 = c.createOscillator(); b1.type = 'triangle'; b1.frequency.value = bodyF;
    var b2 = c.createOscillator(); b2.type = 'triangle'; b2.frequency.value = bodyF * 1.6;
    var bg = c.createGain();
    bg.gain.setValueAtTime((1 - noiseMix) * 0.9 * vel, t);
    bg.gain.exponentialRampToValueAtTime(0.0001, t + dur * 0.7);
    b1.connect(bg); b2.connect(bg); bg.connect(out);
    b1.start(t); b1.stop(t + dur); b2.start(t); b2.stop(t + dur);

    // noise
    var n = this._noise();
    var hp = c.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = lerp(900, 2200, p.tune);
    var bp = c.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 1800; bp.Q.value = 0.7;
    var ng = c.createGain();
    ng.gain.setValueAtTime(noiseMix * 0.9 * vel, t);
    ng.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    n.connect(hp); hp.connect(bp); bp.connect(ng); ng.connect(out);
    n.start(t); n.stop(t + dur + 0.02);
  };

  // ── CLAP: 3 quick noise bursts + a tail ───────────────────
  DrumEngine.prototype._v_clap = function (c, t, p, vel, out) {
    var dur = lerp(0.12, 0.4, p.decay);
    var bp = c.createBiquadFilter(); bp.type = 'bandpass';
    bp.frequency.value = lerp(900, 1800, p.tune); bp.Q.value = 1.1;
    var amp = c.createGain(); amp.gain.value = 0.0001;
    bp.connect(amp); amp.connect(out);

    var n = this._noise(); n.connect(bp);
    var offs = [0, 0.012, 0.024]; // the three slaps
    offs.forEach(function (o) {
      amp.gain.setValueAtTime(0.9 * vel, t + o);
      amp.gain.exponentialRampToValueAtTime(0.18 * vel, t + o + 0.009);
    });
    // diffuse tail
    amp.gain.setValueAtTime(0.7 * vel, t + 0.03);
    amp.gain.exponentialRampToValueAtTime(0.0001, t + 0.03 + dur);
    n.start(t); n.stop(t + dur + 0.06);
  };

  // ── RIMSHOT: two short detuned square pulses ──────────────
  DrumEngine.prototype._v_rim = function (c, t, p, vel, out) {
    var f = lerp(1400, 2400, p.tune);
    var dur = lerp(0.02, 0.09, p.decay);
    [f, f * 1.48].forEach(function (freq) {
      var o = c.createOscillator(); o.type = 'square'; o.frequency.value = freq;
      var g = c.createGain();
      g.gain.setValueAtTime(0.5 * vel, t);
      g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
      o.connect(g); g.connect(out); o.start(t); o.stop(t + dur + 0.01);
    });
  };

  // ── HI-HAT (metallic): six square oscs through a highpass ─
  DrumEngine.prototype._hat = function (c, t, p, vel, out, dur) {
    var ratios = [2, 3, 4.16, 5.43, 6.79, 8.21];
    var base = lerp(280, 520, p.tune);
    var bp = c.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = lerp(8000, 11000, p.tone); bp.Q.value = 0.8;
    var hp = c.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 6500;
    var amp = c.createGain();
    amp.gain.setValueAtTime(vel * 0.5, t);
    amp.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    bp.connect(hp); hp.connect(amp); amp.connect(out);
    var oscs = [];
    ratios.forEach(function (r) {
      var o = c.createOscillator(); o.type = 'square'; o.frequency.value = base * r;
      o.connect(bp); o.start(t); o.stop(t + dur + 0.02); oscs.push(o);
    });
    return amp;
  };
  DrumEngine.prototype._v_closed = function (c, t, p, vel, out) {
    this._hat(c, t, p, vel, out, lerp(0.025, 0.09, p.decay));
  };
  DrumEngine.prototype._v_open = function (c, t, p, vel, out) {
    this._hat(c, t, p, vel, out, lerp(0.2, 0.7, p.decay));
  };

  // ── TOMS: pitch-dropping sine with a touch of noise ───────
  DrumEngine.prototype._tom = function (c, t, p, vel, out, baseRange) {
    var startF = lerp(baseRange[0], baseRange[1], p.tune);
    var dur = lerp(0.15, 0.55, p.decay);
    var o = c.createOscillator(); o.type = 'sine';
    o.frequency.setValueAtTime(startF, t);
    o.frequency.exponentialRampToValueAtTime(startF * 0.6, t + dur);
    var g = c.createGain();
    g.gain.setValueAtTime(vel * 0.95, t);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(out); o.start(t); o.stop(t + dur + 0.04);
    // little noise attack
    var n = this._noise();
    var hp = c.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 400;
    var ng = c.createGain();
    ng.gain.setValueAtTime(0.18 * vel, t);
    ng.gain.exponentialRampToValueAtTime(0.0001, t + 0.05);
    n.connect(hp); hp.connect(ng); ng.connect(out); n.start(t); n.stop(t + 0.08);
  };
  DrumEngine.prototype._v_lowtom = function (c, t, p, vel, out) { this._tom(c, t, p, vel, out, [90, 150]); };
  DrumEngine.prototype._v_hitom  = function (c, t, p, vel, out) { this._tom(c, t, p, vel, out, [160, 260]); };

  // ── COWBELL: two detuned squares through a bandpass ───────
  DrumEngine.prototype._v_cowbell = function (c, t, p, vel, out) {
    var f = lerp(480, 680, p.tune);
    var dur = lerp(0.12, 0.4, p.decay);
    var bp = c.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 2640; bp.Q.value = 1.4;
    var amp = c.createGain();
    amp.gain.setValueAtTime(vel * 0.5, t);
    amp.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    bp.connect(amp); amp.connect(out);
    [f, f * 1.5].forEach(function (freq) {
      var o = c.createOscillator(); o.type = 'square'; o.frequency.value = freq;
      o.connect(bp); o.start(t); o.stop(t + dur + 0.02);
    });
  };

  // ── CRASH/CYMBAL: dense inharmonic squares, long decay ────
  DrumEngine.prototype._v_cymbal = function (c, t, p, vel, out) {
    var dur = lerp(0.4, 1.6, p.decay);
    var base = lerp(300, 460, p.tune);
    var ratios = [2, 3, 4.16, 5.43, 6.79, 8.21, 9.6, 11.1];
    var bp = c.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 9000; bp.Q.value = 0.5;
    var hp = c.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 5000;
    var amp = c.createGain();
    amp.gain.setValueAtTime(vel * 0.4, t + 0.002);
    amp.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    bp.connect(hp); hp.connect(amp); amp.connect(out);
    ratios.forEach(function (r) {
      var o = c.createOscillator(); o.type = 'square'; o.frequency.value = base * r;
      o.connect(bp); o.start(t); o.stop(t + dur + 0.05);
    });
  };

  /* ── exports ─────────────────────────────────────────────── */
  global.Pulseforge = {
    DrumEngine: DrumEngine,
    VOICES: VOICES,
    defaultParams: defaultParams
  };

})(window);
