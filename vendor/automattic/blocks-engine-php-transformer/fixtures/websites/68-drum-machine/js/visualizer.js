/* =========================================================
   PULSEFORGE — Output Visualizer
   Reads the engine AnalyserNode and paints a mirrored
   spectrum + a soft waveform glow on a canvas. Heavy motion
   is calmed under prefers-reduced-motion.
   ========================================================= */

(function (global) {
  'use strict';

  function Visualizer(canvas, engine) {
    this.canvas = canvas;
    this.ctx2d = canvas.getContext('2d');
    this.engine = engine;
    this.raf = null;
    this.reduced = global.matchMedia &&
      global.matchMedia('(prefers-reduced-motion: reduce)').matches;
    this.freq = null; this.wave = null;
    this.peaks = null;
    this._resize();
    var self = this;
    global.addEventListener('resize', function () { self._resize(); });
    if (global.matchMedia) {
      global.matchMedia('(prefers-reduced-motion: reduce)')
        .addEventListener('change', function (e) { self.reduced = e.matches; });
    }
  }

  Visualizer.prototype._resize = function () {
    var dpr = Math.min(global.devicePixelRatio || 1, 2);
    var r = this.canvas.getBoundingClientRect();
    this.w = Math.max(320, r.width);
    this.h = Math.max(120, r.height || 180);
    this.canvas.width = this.w * dpr;
    this.canvas.height = this.h * dpr;
    this.ctx2d.setTransform(dpr, 0, 0, dpr, 0, 0);
  };

  Visualizer.prototype.start = function () {
    if (this.raf) return;
    var a = this.engine.analyser;
    if (!a) return;
    this.freq = new Uint8Array(a.frequencyBinCount);
    this.wave = new Uint8Array(a.fftSize);
    this.peaks = new Float32Array(64).fill(0);
    var self = this;
    (function loop() { self._draw(); self.raf = requestAnimationFrame(loop); })();
  };

  Visualizer.prototype.stop = function () {
    if (this.raf) { cancelAnimationFrame(this.raf); this.raf = null; }
  };

  Visualizer.prototype._draw = function () {
    var g = this.ctx2d, w = this.w, h = this.h, a = this.engine.analyser;
    if (!a) return;
    a.getByteFrequencyData(this.freq);
    a.getByteTimeDomainData(this.wave);

    g.clearRect(0, 0, w, h);
    // background gradient
    var bg = g.createLinearGradient(0, 0, 0, h);
    bg.addColorStop(0, 'rgba(20,14,30,0.0)');
    bg.addColorStop(1, 'rgba(255,92,124,0.05)');
    g.fillStyle = bg; g.fillRect(0, 0, w, h);

    var bars = 64;
    var bw = w / bars;
    var step = Math.floor(this.freq.length / bars);
    var mid = h * 0.62;
    for (var i = 0; i < bars; i++) {
      var v = this.freq[i * step] / 255;
      // peak hold
      if (v > this.peaks[i]) this.peaks[i] = v;
      else this.peaks[i] *= this.reduced ? 0.96 : 0.92;
      var bh = Math.pow(v, 1.4) * mid;
      var x = i * bw;
      var hue = 330 - (i / bars) * 200; // pink → cyan
      var grad = g.createLinearGradient(0, mid - bh, 0, mid);
      grad.addColorStop(0, 'hsla(' + hue + ',90%,68%,0.95)');
      grad.addColorStop(1, 'hsla(' + hue + ',90%,55%,0.25)');
      g.fillStyle = grad;
      g.fillRect(x + 1, mid - bh, bw - 2, bh);
      // mirrored reflection
      g.fillStyle = 'hsla(' + hue + ',90%,60%,0.10)';
      g.fillRect(x + 1, mid, bw - 2, bh * 0.5);
      // peak cap
      var py = mid - this.peaks[i] * mid;
      g.fillStyle = 'hsla(' + hue + ',95%,80%,0.9)';
      g.fillRect(x + 1, py, bw - 2, 2);
    }

    // waveform glow line
    if (!this.reduced) {
      g.beginPath();
      g.lineWidth = 2;
      g.strokeStyle = 'rgba(124,232,192,0.85)';
      g.shadowColor = 'rgba(124,232,192,0.7)';
      g.shadowBlur = 8;
      var len = this.wave.length, slice = w / len;
      for (var j = 0; j < len; j++) {
        var y = (this.wave[j] / 128 - 1) * (h * 0.18) + h * 0.16;
        var px = j * slice;
        if (j === 0) g.moveTo(px, y); else g.lineTo(px, y);
      }
      g.stroke();
      g.shadowBlur = 0;
    } else {
      // static centre line when reduced
      g.strokeStyle = 'rgba(124,232,192,0.4)';
      g.lineWidth = 1.5;
      g.beginPath(); g.moveTo(0, h * 0.16); g.lineTo(w, h * 0.16); g.stroke();
    }
  };

  global.PulseforgeVisualizer = Visualizer;

})(window);
