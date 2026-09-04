/* =========================================================
   VOLTROVE — Filament One · Audio Engine
   A small, dependency-free Web Audio synthesizer engine.

   Signal chain per voice:
     osc(A) ─┐
             ├─► voiceGain (ADSR) ─► filter (Biquad) ─► voiceBus
     osc(B) ─┘
   Global:
     voiceBus ─► drive ─► delay(fb) ─┐
                                     ├─► master ─► analyser ─► destination
     voiceBus ─────────── (dry) ─────┘
   ========================================================= */

(function (global) {
  'use strict';

  /* ── Music helpers ──────────────────────────────────── */
  // MIDI note number → frequency (A4 = 69 = 440 Hz)
  function mtof(midi) {
    return 440 * Math.pow(2, (midi - 69) / 12);
  }

  const NOTE_NAMES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
  function noteName(midi) {
    return NOTE_NAMES[midi % 12] + (Math.floor(midi / 12) - 1);
  }

  /* ── Default patch ──────────────────────────────────── */
  const DEFAULT_PATCH = {
    name: 'Init Filament',
    oscA: 'sawtooth',
    oscB: 'square',
    mixB: 0.35,        // level of second oscillator 0..1
    detune: 8,         // cents spread between the two oscs
    octB: -12,         // semitone offset for osc B
    cutoff: 2400,      // Hz
    resonance: 4,      // Q
    filterEnv: 1800,   // how far the envelope pushes the cutoff (Hz)
    attack: 0.01,      // seconds
    decay: 0.18,
    sustain: 0.55,     // 0..1
    release: 0.32,
    drive: 0.12,       // waveshaper amount 0..1
    delayTime: 0.27,   // seconds
    delayFeedback: 0.32,
    delayMix: 0.25,    // wet 0..1
    glide: 0.0,        // portamento seconds (mono feel when > 0)
    volume: 0.7        // master 0..1
  };

  /* ── Engine ─────────────────────────────────────────── */
  function SynthEngine() {
    this.ctx = null;
    this.ready = false;
    this.supported = (typeof (global.AudioContext || global.webkitAudioContext) === 'function');
    this.patch = Object.assign({}, DEFAULT_PATCH);
    this.voices = {};        // id → voice
    this.analyser = null;
    this._driveCurveCache = {};
  }

  SynthEngine.prototype.init = function () {
    if (this.ready || !this.supported) return this.ready;
    const Ctx = global.AudioContext || global.webkitAudioContext;
    this.ctx = new Ctx();

    const c = this.ctx;

    // Master chain
    this.master = c.createGain();
    this.master.gain.value = this.patch.volume;

    this.analyser = c.createAnalyser();
    this.analyser.fftSize = 2048;
    this.analyser.smoothingTimeConstant = 0.78;

    // gentle limiter so chords / panic don't clip horribly
    this.limiter = c.createDynamicsCompressor();
    this.limiter.threshold.value = -6;
    this.limiter.knee.value = 8;
    this.limiter.ratio.value = 12;
    this.limiter.attack.value = 0.003;
    this.limiter.release.value = 0.18;

    // Drive (waveshaper) sits on the wet+dry sum
    this.drive = c.createWaveShaper();
    this.drive.oversample = '2x';
    this._applyDrive(this.patch.drive);

    // Delay send/return
    this.voiceBus = c.createGain();        // all voices land here
    this.dry = c.createGain();
    this.wet = c.createGain();
    this.delay = c.createDelay(2.0);
    this.feedback = c.createGain();
    this.delayFilter = c.createBiquadFilter(); // tame the echoes
    this.delayFilter.type = 'lowpass';
    this.delayFilter.frequency.value = 3200;

    this.delay.delayTime.value = this.patch.delayTime;
    this.feedback.gain.value = this.patch.delayFeedback;
    this.wet.gain.value = this.patch.delayMix;
    this.dry.gain.value = 1;

    // wiring
    this.voiceBus.connect(this.dry);
    this.voiceBus.connect(this.delay);
    this.delay.connect(this.delayFilter);
    this.delayFilter.connect(this.feedback);
    this.feedback.connect(this.delay);   // feedback loop
    this.delayFilter.connect(this.wet);

    this.dry.connect(this.drive);
    this.wet.connect(this.drive);
    this.drive.connect(this.master);
    this.master.connect(this.limiter);
    this.limiter.connect(this.analyser);
    this.analyser.connect(c.destination);

    this.ready = true;
    return true;
  };

  // Resume context (must be called from a user gesture on most browsers)
  SynthEngine.prototype.resume = function () {
    if (!this.ready) this.init();
    if (this.ctx && this.ctx.state === 'suspended') {
      return this.ctx.resume();
    }
    return Promise.resolve();
  };

  SynthEngine.prototype.now = function () {
    return this.ctx ? this.ctx.currentTime : 0;
  };

  /* ── Waveshaper curve for soft drive ────────────────── */
  SynthEngine.prototype._applyDrive = function (amount) {
    const k = Math.max(0.0001, amount) * 100;
    const key = Math.round(k);
    let curve = this._driveCurveCache[key];
    if (!curve) {
      const n = 2048;
      curve = new Float32Array(n);
      for (let i = 0; i < n; i++) {
        const x = (i * 2) / n - 1;
        curve[i] = ((3 + k) * x * 20 * Math.PI / 180) / (Math.PI + k * Math.abs(x));
      }
      this._driveCurveCache[key] = curve;
    }
    this.drive.curve = curve;
  };

  /* ── Live parameter setters ─────────────────────────── */
  SynthEngine.prototype.set = function (key, value) {
    this.patch[key] = value;
    if (!this.ready) return;
    const t = this.now();
    switch (key) {
      case 'volume': this.master.gain.setTargetAtTime(value, t, 0.01); break;
      case 'delayTime': this.delay.delayTime.setTargetAtTime(value, t, 0.05); break;
      case 'delayFeedback': this.feedback.gain.setTargetAtTime(value, t, 0.02); break;
      case 'delayMix': this.wet.gain.setTargetAtTime(value, t, 0.02); break;
      case 'drive': this._applyDrive(value); break;
      case 'cutoff':
      case 'resonance':
        // apply to currently-held voices so the sweep is audible
        for (const id in this.voices) {
          const v = this.voices[id];
          if (key === 'cutoff') v.filter.frequency.setTargetAtTime(value, t, 0.02);
          else v.filter.Q.setTargetAtTime(value, t, 0.02);
        }
        break;
      case 'detune':
        for (const id in this.voices) {
          const v = this.voices[id];
          v.oscA.detune.setTargetAtTime(value, t, 0.02);
          v.oscB.detune.setTargetAtTime(this.patch.octB * 100 - value, t, 0.02);
        }
        break;
      default: break;
    }
  };

  SynthEngine.prototype.loadPatch = function (patch) {
    this.patch = Object.assign({}, DEFAULT_PATCH, patch);
    if (!this.ready) return;
    const t = this.now();
    this.master.gain.setTargetAtTime(this.patch.volume, t, 0.01);
    this.delay.delayTime.setTargetAtTime(this.patch.delayTime, t, 0.05);
    this.feedback.gain.setTargetAtTime(this.patch.delayFeedback, t, 0.02);
    this.wet.gain.setTargetAtTime(this.patch.delayMix, t, 0.02);
    this._applyDrive(this.patch.drive);
  };

  /* ── Note on / off (polyphonic) ─────────────────────── */
  // velocity 0..1
  SynthEngine.prototype.noteOn = function (midi, velocity, when) {
    if (!this.ready) return;
    velocity = (velocity == null) ? 0.85 : velocity;
    const c = this.ctx;
    const t = (when != null) ? when : this.now();
    const p = this.patch;
    const id = midi + ':' + t.toFixed(4);

    // If this midi is already held (re-trigger), release the old one quickly
    if (this.voices['held_' + midi]) {
      this._releaseVoice(this.voices['held_' + midi], t, 0.02);
    }

    const freq = mtof(midi);

    const oscA = c.createOscillator();
    oscA.type = p.oscA;
    oscA.frequency.setValueAtTime(freq, t);
    oscA.detune.setValueAtTime(p.detune, t);

    const oscB = c.createOscillator();
    oscB.type = p.oscB;
    // octB is an offset in semitones; transpose osc B's frequency.
    oscB.frequency.setValueAtTime(mtof(midi) * Math.pow(2, p.octB / 12), t);
    oscB.detune.setValueAtTime(-p.detune, t);

    const mixB = c.createGain();
    mixB.gain.value = p.mixB;

    const filter = c.createBiquadFilter();
    filter.type = 'lowpass';
    filter.Q.setValueAtTime(p.resonance, t);

    const vca = c.createGain();
    vca.gain.setValueAtTime(0.0001, t);

    // routing
    oscA.connect(vca);
    oscB.connect(mixB);
    mixB.connect(vca);
    vca.connect(filter);
    filter.connect(this.voiceBus);

    // Amp ADSR
    const peak = Math.max(0.02, velocity) * 0.32;
    const a = Math.max(0.001, p.attack);
    const d = Math.max(0.001, p.decay);
    const s = Math.max(0.0001, p.sustain) * peak;
    vca.gain.cancelScheduledValues(t);
    vca.gain.setValueAtTime(0.0001, t);
    vca.gain.linearRampToValueAtTime(peak, t + a);
    vca.gain.setTargetAtTime(s, t + a, d / 3 + 0.0001);

    // Filter ADSR — cutoff swept up by filterEnv then settles
    const base = p.cutoff;
    const top = Math.min(18000, base + p.filterEnv);
    filter.frequency.cancelScheduledValues(t);
    filter.frequency.setValueAtTime(base, t);
    filter.frequency.linearRampToValueAtTime(top, t + a);
    filter.frequency.setTargetAtTime(base, t + a, (d + 0.05) / 3);

    oscA.start(t);
    oscB.start(t);

    const voice = { id, midi, oscA, oscB, mixB, filter, vca, peak, startedAt: t, dead: false };
    this.voices[id] = voice;
    this.voices['held_' + midi] = voice; // last voice for this note (for noteOff lookup)
    return voice;
  };

  SynthEngine.prototype._releaseVoice = function (voice, t, releaseTime) {
    if (!voice || voice.dead) return;
    voice.dead = true;
    const r = Math.max(0.005, releaseTime);
    voice.vca.gain.cancelScheduledValues(t);
    // hold current value then ramp to silence
    const cur = voice.vca.gain.value;
    voice.vca.gain.setValueAtTime(Math.max(0.0001, cur), t);
    voice.vca.gain.setTargetAtTime(0.0001, t, r / 4);
    const stopAt = t + r + 0.05;
    try { voice.oscA.stop(stopAt); voice.oscB.stop(stopAt); } catch (e) {}
    const self = this;
    voice.oscA.onended = function () {
      try {
        voice.oscA.disconnect(); voice.oscB.disconnect();
        voice.mixB.disconnect(); voice.filter.disconnect(); voice.vca.disconnect();
      } catch (e) {}
      delete self.voices[voice.id];
      if (self.voices['held_' + voice.midi] === voice) {
        delete self.voices['held_' + voice.midi];
      }
    };
  };

  SynthEngine.prototype.noteOff = function (midi, when) {
    if (!this.ready) return;
    const t = (when != null) ? when : this.now();
    const voice = this.voices['held_' + midi];
    if (voice) this._releaseVoice(voice, t, this.patch.release);
  };

  // Fire-and-forget note for the sequencer (its own envelope length)
  SynthEngine.prototype.trigger = function (midi, when, dur, velocity) {
    const v = this.noteOn(midi, velocity, when);
    if (v) this._releaseVoice(v, when + dur, this.patch.release);
  };

  SynthEngine.prototype.panic = function () {
    if (!this.ready) return;
    const t = this.now();
    for (const id in this.voices) {
      if (id.indexOf('held_') === 0) continue;
      const v = this.voices[id];
      try {
        v.vca.gain.cancelScheduledValues(t);
        v.vca.gain.setValueAtTime(0.0001, t);
        v.oscA.stop(t + 0.02);
        v.oscB.stop(t + 0.02);
      } catch (e) {}
    }
    this.voices = {};
  };

  /* ── Percussion synthesis for the sequencer ─────────── */
  // Simple drum voices built from oscillators + noise, scheduled on the clock.
  SynthEngine.prototype.drum = function (type, when, accent) {
    if (!this.ready) return;
    const c = this.ctx;
    const t = when;
    const g = c.createGain();
    g.connect(this.voiceBus);
    const lvl = accent ? 1 : 0.7;

    if (type === 'kick') {
      const o = c.createOscillator();
      o.type = 'sine';
      o.frequency.setValueAtTime(150, t);
      o.frequency.exponentialRampToValueAtTime(45, t + 0.12);
      g.gain.setValueAtTime(0.9 * lvl, t);
      g.gain.exponentialRampToValueAtTime(0.001, t + 0.32);
      o.connect(g); o.start(t); o.stop(t + 0.35);
    } else if (type === 'snare') {
      const noise = this._noiseBurst(0.2);
      const nf = c.createBiquadFilter();
      nf.type = 'highpass'; nf.frequency.value = 1400;
      noise.connect(nf); nf.connect(g);
      const body = c.createOscillator();
      body.type = 'triangle'; body.frequency.setValueAtTime(190, t);
      const bg = c.createGain(); bg.gain.setValueAtTime(0.35 * lvl, t);
      bg.gain.exponentialRampToValueAtTime(0.001, t + 0.13);
      body.connect(bg); bg.connect(this.voiceBus);
      g.gain.setValueAtTime(0.6 * lvl, t);
      g.gain.exponentialRampToValueAtTime(0.001, t + 0.18);
      noise.start(t); noise.stop(t + 0.2);
      body.start(t); body.stop(t + 0.14);
    } else if (type === 'hat') {
      const noise = this._noiseBurst(0.06);
      const nf = c.createBiquadFilter();
      nf.type = 'highpass'; nf.frequency.value = 7000;
      noise.connect(nf); nf.connect(g);
      g.gain.setValueAtTime(0.4 * lvl, t);
      g.gain.exponentialRampToValueAtTime(0.001, t + (accent ? 0.12 : 0.05));
      noise.start(t); noise.stop(t + 0.13);
    } else if (type === 'clap') {
      const noise = this._noiseBurst(0.22);
      const nf = c.createBiquadFilter();
      nf.type = 'bandpass'; nf.frequency.value = 1500; nf.Q.value = 1.2;
      noise.connect(nf); nf.connect(g);
      g.gain.setValueAtTime(0.5 * lvl, t);
      g.gain.exponentialRampToValueAtTime(0.001, t + 0.2);
      noise.start(t); noise.stop(t + 0.24);
    }
  };

  SynthEngine.prototype._noiseBurst = function (dur) {
    const c = this.ctx;
    const len = Math.ceil(c.sampleRate * dur);
    const buf = c.createBuffer(1, len, c.sampleRate);
    const data = buf.getChannelData(0);
    for (let i = 0; i < len; i++) data[i] = Math.random() * 2 - 1;
    const src = c.createBufferSource();
    src.buffer = buf;
    return src;
  };

  /* ── exports ────────────────────────────────────────── */
  global.Voltrove = {
    SynthEngine: SynthEngine,
    DEFAULT_PATCH: DEFAULT_PATCH,
    mtof: mtof,
    noteName: noteName,
    NOTE_NAMES: NOTE_NAMES
  };

})(window);
