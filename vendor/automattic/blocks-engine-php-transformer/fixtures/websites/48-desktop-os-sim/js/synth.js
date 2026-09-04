/* =========================================================
   AuroraOS 88 — AuroraSynth
   A tiny step-sequencer "music player" built on the Web
   Audio API, with a live frequency-bar visualizer and a
   clickable keyboard. Three presets sketch NEON COASTLINE.
   ========================================================= */
'use strict';

AOS.Synth = (function () {
  let ac = null, master = null, analyser = null, data = null;
  let playing = false, step = 0, timer = 0, raf = 0;
  let vizCtx = null, vizCanvas = null, mounted = false;
  let curPreset = 0, keyEls = [], stepDisplay = null;

  // note frequencies (one octave + a couple extra)
  const NOTES = { 'C': 261.63, 'D': 293.66, 'E': 329.63, 'F': 349.23, 'G': 392.0, 'A': 440.0, 'B': 493.88, 'C2': 523.25 };
  const KEYS = ['C', 'D', 'E', 'F', 'G', 'A', 'B', 'C2'];

  // each preset: bpm + 16-step arrays for bass + lead (note name or null)
  const PRESETS = [
    {
      name: 'Headlight Country', sub: 'NEON COASTLINE — main bassline',
      bpm: 104,
      bass: ['C', null, 'C', null, 'G', null, 'A', null, 'F', null, 'F', null, 'G', null, 'G', null],
      lead: [null, 'E', null, 'G', null, 'B', null, 'C2', null, 'A', null, 'C2', null, 'B', null, 'G']
    },
    {
      name: 'Saltwater Arcade', sub: 'upbeat FM bells',
      bpm: 124,
      bass: ['A', null, 'A', 'E', null, 'A', null, 'E', 'F', null, 'F', 'C', null, 'F', null, 'G'],
      lead: ['C2', null, 'B', null, 'A', null, 'C2', null, 'A', null, 'G', null, 'A', null, 'B', null]
    },
    {
      name: 'Low Tide Transmissions', sub: 'slow ambient pads',
      bpm: 72,
      bass: ['C', null, null, null, 'G', null, null, null, 'A', null, null, null, 'F', null, null, null],
      lead: [null, null, 'E', null, null, null, 'B', null, null, null, 'C2', null, null, null, 'G', null]
    }
  ];

  function ensureAudio() {
    if (ac) return;
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return;
    ac = new AC();
    master = ac.createGain(); master.gain.value = 0.16;
    analyser = ac.createAnalyser(); analyser.fftSize = 128;
    data = new Uint8Array(analyser.frequencyBinCount);
    master.connect(analyser); analyser.connect(ac.destination);
  }

  function blip(freq, dur, type, gain) {
    if (!ac) return;
    const t = ac.currentTime;
    const osc = ac.createOscillator();
    const g = ac.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(freq, t);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(gain, t + 0.012);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    osc.connect(g); g.connect(master);
    osc.start(t); osc.stop(t + dur + 0.02);
  }

  function tick() {
    const p = PRESETS[curPreset];
    const b = p.bass[step], l = p.lead[step];
    if (b) blip(NOTES[b] / 2, 0.28, 'sawtooth', 0.5);
    if (l) blip(NOTES[l], 0.22, 'triangle', 0.32);
    // hat every other step
    if (step % 2 === 0) blip(8000 + Math.random() * 1200, 0.03, 'square', 0.05);

    // light the matching key
    keyEls.forEach(k => k.classList.remove('lit'));
    if (l && NOTES[l]) { const idx = KEYS.indexOf(l); if (keyEls[idx]) keyEls[idx].classList.add('lit'); }
    if (stepDisplay) stepDisplay.textContent = String(step + 1).padStart(2, '0');

    step = (step + 1) % 16;
    timer = setTimeout(tick, (60 / p.bpm / 4) * 1000);
  }

  function play() {
    ensureAudio();
    if (!ac) return;
    if (ac.state === 'suspended') ac.resume();
    if (playing) return;
    playing = true;
    updateTransport();
    tick();
    drawViz();
  }
  function pause() {
    playing = false;
    clearTimeout(timer);
    keyEls.forEach(k => k.classList.remove('lit'));
    updateTransport();
  }
  function stop() { pause(); cancelAnimationFrame(raf); }

  let playBtn = null;
  function updateTransport() {
    if (playBtn) playBtn.innerHTML = playing ? '⏸' : '▶';
  }

  function drawViz() {
    if (!vizCtx || !mounted) return;
    const c = vizCanvas, ctx = vizCtx;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const w = c.clientWidth, h = c.clientHeight;
    if (c.width !== w * dpr) { c.width = w * dpr; c.height = h * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0); }
    ctx.clearRect(0, 0, w, h);

    // baseline glow grid
    ctx.strokeStyle = 'rgba(0,234,255,0.08)';
    for (let y = h; y > 0; y -= 14) { ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(w, y); ctx.stroke(); }

    if (analyser && playing) analyser.getByteFrequencyData(data);
    const n = data ? data.length : 32;
    const bw = w / n;
    for (let i = 0; i < n; i++) {
      const v = (data && playing) ? data[i] / 255 : (0.12 + 0.1 * Math.sin(i * 0.6 + Date.now() / 600));
      const bh = Math.max(2, v * h * 0.92);
      const x = i * bw;
      const hue = 300 - (i / n) * 130;
      const g = ctx.createLinearGradient(0, h, 0, h - bh);
      g.addColorStop(0, `hsla(${hue},100%,60%,0.95)`);
      g.addColorStop(1, `hsla(${hue},100%,72%,0.4)`);
      ctx.fillStyle = g;
      ctx.fillRect(x + 1, h - bh, bw - 2, bh);
    }
    raf = requestAnimationFrame(drawViz);
  }

  function mount(body, api) {
    mounted = true;
    const root = AOS.el('div', { class: 'synth' });
    const viz = AOS.el('div', { class: 'synth-viz' });
    vizCanvas = AOS.el('canvas');
    const now = AOS.el('div', { class: 'synth-now' });
    viz.append(vizCanvas, now);

    const panel = AOS.el('div', { class: 'synth-panel' });

    // transport
    const transport = AOS.el('div', { class: 'synth-transport' });
    playBtn = AOS.el('button', { class: 'synth-btn', 'aria-label': 'Play / pause', html: '▶' });
    const stopBtn = AOS.el('button', { class: 'synth-btn sm', 'aria-label': 'Stop', html: '⏹' });
    const trackInfo = AOS.el('div', { class: 'synth-track' });
    stepDisplay = AOS.el('span', { text: '01' });
    const stepWrap = AOS.el('div', { class: 'synth-track', style: 'flex:none;text-align:right' }, 'step ', stepDisplay, '/16');
    transport.append(playBtn, stopBtn, trackInfo, stepWrap);

    // keyboard
    const keysWrap = AOS.el('div', { class: 'synth-keys' });
    keyEls = KEYS.map(k => {
      const key = AOS.el('button', { class: 'synth-key', 'aria-label': 'Play note ' + k }, k.replace('2', '↑'));
      key.addEventListener('pointerdown', () => { ensureAudio(); if (ac && ac.state === 'suspended') ac.resume(); blip(NOTES[k], 0.4, 'triangle', 0.34); key.classList.add('lit'); setTimeout(() => key.classList.remove('lit'), 160); if (!playing) { drawViz(); } });
      keysWrap.append(key);
      return key;
    });

    // presets
    const presetWrap = AOS.el('div', { class: 'synth-presets' });
    PRESETS.forEach((p, i) => {
      const b = AOS.el('button', { class: 'synth-preset' + (i === 0 ? ' on' : ''), text: p.name });
      b.addEventListener('click', () => {
        curPreset = i; step = 0;
        presetWrap.querySelectorAll('.synth-preset').forEach(x => x.classList.remove('on'));
        b.classList.add('on');
        updateNow();
      });
      presetWrap.append(b);
    });

    const hint = AOS.el('div', { class: 'synth-hint', text: 'Click keys to play · all sound is generated live with the Web Audio API · no audio files.' });

    panel.append(transport, keysWrap, presetWrap, hint);
    root.append(viz, panel);
    body.append(root);
    vizCtx = vizCanvas.getContext('2d');

    function updateNow() {
      const p = PRESETS[curPreset];
      now.innerHTML = `♪ now loaded: <b>${p.name}</b>`;
      trackInfo.innerHTML = `${p.name}<small>${p.sub} · ${p.bpm} BPM</small>`;
    }
    updateNow();

    playBtn.addEventListener('click', () => playing ? pause() : play());
    stopBtn.addEventListener('click', () => { stop(); step = 0; if (stepDisplay) stepDisplay.textContent = '01'; drawViz(); });

    drawViz();
    // when window closes, AOS.Synth.stop is wired via onClose in apps.js
    api.win.node.addEventListener('animationend', (e) => {
      if (e.animationName === 'winClose') { mounted = false; stop(); }
    });
  }

  return { mount, play, pause, stop };
})();
