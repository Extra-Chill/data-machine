/* =========================================================
   VOLTROVE — Canvas Visualizer
   Reads from the engine's AnalyserNode and draws:
     · an oscilloscope (time domain), and
     · a frequency-bar spectrum underneath.
   Honours prefers-reduced-motion (drops to a calm, low-rate
   render with no glow / trails).
   ========================================================= */

(function (global) {
  'use strict';

  function Visualizer(canvas, engine) {
    this.canvas = canvas;
    this.ctx2d = canvas.getContext('2d');
    this.engine = engine;
    this.raf = null;
    this.running = false;
    this.reduced = global.matchMedia &&
      global.matchMedia('(prefers-reduced-motion: reduce)').matches;
    this.timeData = null;
    this.freqData = null;
    this._frame = 0;
    this._resize();
    const self = this;
    global.addEventListener('resize', function () { self._resize(); });
  }

  Visualizer.prototype._resize = function () {
    const dpr = Math.min(global.devicePixelRatio || 1, 2);
    const rect = this.canvas.getBoundingClientRect();
    this.w = Math.max(1, Math.floor(rect.width));
    this.h = Math.max(1, Math.floor(rect.height));
    this.canvas.width = this.w * dpr;
    this.canvas.height = this.h * dpr;
    this.ctx2d.setTransform(dpr, 0, 0, dpr, 0, 0);
  };

  Visualizer.prototype.start = function () {
    if (this.running || !this.engine.ready || !this.engine.analyser) return;
    const a = this.engine.analyser;
    this.timeData = new Uint8Array(a.fftSize);
    this.freqData = new Uint8Array(a.frequencyBinCount);
    this.running = true;
    this._loop();
  };

  Visualizer.prototype.stop = function () {
    this.running = false;
    if (this.raf) cancelAnimationFrame(this.raf);
  };

  Visualizer.prototype._loop = function () {
    if (!this.running) return;
    const self = this;
    this.raf = requestAnimationFrame(function () { self._loop(); });
    // Throttle to ~20fps when reduced motion is requested
    this._frame++;
    if (this.reduced && this._frame % 3 !== 0) return;
    this._draw();
  };

  Visualizer.prototype._draw = function () {
    const a = this.engine.analyser;
    const g = this.ctx2d;
    const w = this.w, h = this.h;
    if (!a) return;

    a.getByteTimeDomainData(this.timeData);
    a.getByteFrequencyData(this.freqData);

    // Background — trail fade for motion, solid for reduced
    if (this.reduced) {
      g.fillStyle = '#0c0b12';
      g.fillRect(0, 0, w, h);
    } else {
      g.fillStyle = 'rgba(12, 11, 18, 0.28)';
      g.fillRect(0, 0, w, h);
    }

    // ── Frequency bars (bottom half) ──
    const bins = this.freqData.length;
    const usable = Math.floor(bins * 0.62); // skip the very top, mostly empty
    const barW = w / usable;
    for (let i = 0; i < usable; i++) {
      const v = this.freqData[i] / 255;
      const bh = v * h * 0.72;
      const x = i * barW;
      const hue = 168 + (i / usable) * 120; // mint → violet
      g.fillStyle = 'hsla(' + hue + ', 78%, ' + (40 + v * 28) + '%, 0.78)';
      g.fillRect(x, h - bh, Math.max(1, barW - 0.6), bh);
    }

    // ── Oscilloscope (overlaid) ──
    const buf = this.timeData;
    const n = buf.length;
    g.lineWidth = this.reduced ? 1.4 : 2;
    g.strokeStyle = this.reduced ? '#6cf2c8' : 'rgba(120, 245, 210, 0.95)';
    if (!this.reduced) {
      g.shadowColor = '#6cf2c8';
      g.shadowBlur = 8;
    } else {
      g.shadowBlur = 0;
    }
    g.beginPath();
    const slice = w / n;
    for (let i = 0; i < n; i++) {
      const y = (buf[i] / 128.0) * (h / 2);
      const x = i * slice;
      if (i === 0) g.moveTo(x, y); else g.lineTo(x, y);
    }
    g.stroke();
    g.shadowBlur = 0;

    // center line
    g.strokeStyle = 'rgba(255,255,255,0.06)';
    g.lineWidth = 1;
    g.beginPath();
    g.moveTo(0, h / 2); g.lineTo(w, h / 2);
    g.stroke();
  };

  // Static "idle" state to show before audio is enabled
  Visualizer.prototype.drawIdle = function () {
    const g = this.ctx2d, w = this.w, h = this.h;
    g.fillStyle = '#0c0b12';
    g.fillRect(0, 0, w, h);
    g.strokeStyle = 'rgba(108, 242, 200, 0.35)';
    g.lineWidth = 1.5;
    g.beginPath();
    for (let x = 0; x <= w; x++) {
      const y = h / 2 + Math.sin(x * 0.03) * (h * 0.12) * Math.sin(x * 0.006);
      if (x === 0) g.moveTo(x, y); else g.lineTo(x, y);
    }
    g.stroke();
    g.fillStyle = 'rgba(154, 153, 181, 0.55)';
    g.font = '12px "JetBrains Mono", monospace';
    g.textAlign = 'center';
    g.fillText('analyser idle — click to enable sound', w / 2, h - 14);
  };

  global.VoltroveVisualizer = Visualizer;

})(window);
