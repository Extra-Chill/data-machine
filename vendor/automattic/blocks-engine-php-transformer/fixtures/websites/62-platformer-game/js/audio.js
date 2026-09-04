/* audio.js — tiny Web Audio SFX synth for Lumen Leap.
   No audio files: every sound is generated on the fly with oscillators and a
   short envelope. Audio is created lazily on first user gesture (browsers
   block autoplay) and can be muted. A gentle ambient pad loops underneath. */
(function () {
  'use strict';

  let ctx = null;
  let master = null;
  let musicGain = null;
  let muted = (window.LumenSave && LumenSave.get('muted')) || false;
  let musicNodes = [];

  function ensure() {
    if (ctx) return ctx;
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    ctx = new AC();
    master = ctx.createGain();
    master.gain.value = muted ? 0 : 0.9;
    master.connect(ctx.destination);
    musicGain = ctx.createGain();
    musicGain.gain.value = 0.0;
    musicGain.connect(master);
    return ctx;
  }

  function resume() {
    ensure();
    if (ctx && ctx.state === 'suspended') ctx.resume();
  }

  /* Generic blip: freq sweep with an exponential decay. */
  function blip(freq, dur, type, vol, sweepTo) {
    if (muted) return;
    ensure();
    if (!ctx) return;
    const t = ctx.currentTime;
    const o = ctx.createOscillator();
    const g = ctx.createGain();
    o.type = type || 'square';
    o.frequency.setValueAtTime(freq, t);
    if (sweepTo) o.frequency.exponentialRampToValueAtTime(sweepTo, t + dur);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(vol || 0.25, t + 0.008);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(master);
    o.start(t); o.stop(t + dur + 0.02);
  }

  function noise(dur, vol) {
    if (muted) return;
    ensure();
    if (!ctx) return;
    const t = ctx.currentTime;
    const n = Math.floor(ctx.sampleRate * dur);
    const buf = ctx.createBuffer(1, n, ctx.sampleRate);
    const data = buf.getChannelData(0);
    for (let i = 0; i < n; i++) data[i] = (Math.random() * 2 - 1) * (1 - i / n);
    const src = ctx.createBufferSource();
    src.buffer = buf;
    const g = ctx.createGain();
    g.gain.value = vol || 0.2;
    const f = ctx.createBiquadFilter();
    f.type = 'highpass'; f.frequency.value = 800;
    src.connect(f); f.connect(g); g.connect(master);
    src.start(t);
  }

  const SFX = {
    jump()    { blip(420, 0.16, 'square', 0.18, 760); },
    coin()    { blip(880, 0.08, 'triangle', 0.22); setTimeout(() => blip(1320, 0.10, 'triangle', 0.20), 60); },
    gem()     { blip(660, 0.10, 'sine', 0.22); setTimeout(() => blip(990, 0.10, 'sine', 0.2), 70); setTimeout(() => blip(1480, 0.16, 'sine', 0.18), 150); },
    stomp()   { blip(220, 0.12, 'sawtooth', 0.22, 90); noise(0.10, 0.12); },
    hurt()    { blip(300, 0.28, 'sawtooth', 0.25, 70); },
    land()    { noise(0.05, 0.08); },
    checkpoint() { blip(523, 0.10, 'sine', 0.2); setTimeout(() => blip(784, 0.18, 'sine', 0.2), 90); },
    win()     {
      [523, 659, 784, 1047].forEach((f, i) => setTimeout(() => blip(f, 0.22, 'triangle', 0.22), i * 130));
    },
    lose()    {
      [440, 392, 330, 262].forEach((f, i) => setTimeout(() => blip(f, 0.26, 'sawtooth', 0.2, f * 0.85), i * 150));
    },
    ui()      { blip(560, 0.05, 'sine', 0.14); }
  };

  /* A slow, sparse ambient arpeggio so the world feels alive but calm. */
  function startMusic() {
    ensure();
    if (!ctx || musicNodes.length) return;
    musicGain.gain.setTargetAtTime(muted ? 0 : 0.12, ctx.currentTime, 1.2);
    const scale = [261.63, 329.63, 392.0, 440.0, 523.25, 659.25];
    let step = 0;
    const lfo = ctx.createOscillator();
    const lfoGain = ctx.createGain();
    lfo.frequency.value = 0.05; lfoGain.gain.value = 6;
    lfo.connect(lfoGain);
    lfo.start();
    musicNodes.push(lfo);
    const interval = setInterval(() => {
      if (!ctx || ctx.state !== 'running') return;
      const t = ctx.currentTime;
      const f = scale[(step * 2) % scale.length] / (step % 8 < 4 ? 1 : 2);
      const o = ctx.createOscillator();
      const g = ctx.createGain();
      o.type = 'sine';
      o.frequency.value = f;
      lfoGain.connect(o.frequency);
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(0.16, t + 0.4);
      g.gain.exponentialRampToValueAtTime(0.0001, t + 1.6);
      o.connect(g); g.connect(musicGain);
      o.start(t); o.stop(t + 1.8);
      step++;
    }, 620);
    musicNodes.push({ stop() { clearInterval(interval); } });
  }

  function stopMusic() {
    if (musicGain && ctx) musicGain.gain.setTargetAtTime(0, ctx.currentTime, 0.6);
  }

  const Audio = {
    sfx: SFX,
    resume,
    startMusic,
    stopMusic,
    isMuted() { return muted; },
    setMuted(m) {
      muted = m;
      if (window.LumenSave) LumenSave.set('muted', m);
      ensure();
      if (master) master.gain.setTargetAtTime(m ? 0 : 0.9, ctx.currentTime, 0.05);
      if (musicGain) musicGain.gain.setTargetAtTime(m ? 0 : 0.12, ctx.currentTime, 0.3);
    },
    toggleMuted() { this.setMuted(!muted); return muted; }
  };

  window.LumenAudio = Audio;
})();
