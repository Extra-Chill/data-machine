/* =========================================================
   TONEBOX — histogram.js
   Computes per-channel (R,G,B) and luma distributions from a
   pixel buffer and renders them onto a small canvas. The chart
   updates live whenever the edited image is recomputed.
   ========================================================= */
(function (global) {
  'use strict';

  function compute(data) {
    const r = new Uint32Array(256);
    const g = new Uint32Array(256);
    const b = new Uint32Array(256);
    const l = new Uint32Array(256);
    for (let i = 0; i < data.length; i += 4) {
      const R = data[i], G = data[i + 1], B = data[i + 2];
      r[R]++; g[G]++; b[B]++;
      l[(0.299 * R + 0.587 * G + 0.114 * B) | 0]++;
    }
    return { r, g, b, l };
  }

  /* draw one channel as a filled area path */
  function drawChannel(ctx, hist, w, h, max, color, mode) {
    ctx.globalCompositeOperation = mode;
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.moveTo(0, h);
    for (let i = 0; i < 256; i++) {
      const x = (i / 255) * w;
      // log-ish scale keeps tall spikes from flattening the rest
      const v = Math.log(1 + hist[i]) / Math.log(1 + max);
      ctx.lineTo(x, h - v * h);
    }
    ctx.lineTo(w, h);
    ctx.closePath();
    ctx.fill();
  }

  function render(canvas, data, channel) {
    const ctx = canvas.getContext('2d');
    const w = canvas.width, h = canvas.height;
    ctx.clearRect(0, 0, w, h);
    if (!data) return;
    const hist = compute(data);

    // grid lines
    ctx.globalCompositeOperation = 'source-over';
    ctx.strokeStyle = 'rgba(255,255,255,0.06)';
    ctx.lineWidth = 1;
    for (let q = 1; q < 4; q++) {
      const x = (q / 4) * w;
      ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, h); ctx.stroke();
    }

    function maxOf(arr) {
      // ignore the absolute peak at 0/255 which is common & huge
      let m = 1;
      for (let i = 2; i < 254; i++) if (arr[i] > m) m = arr[i];
      return m;
    }

    if (channel === 'luma') {
      drawChannel(ctx, hist.l, w, h, maxOf(hist.l), 'rgba(226,232,240,0.85)', 'source-over');
    } else {
      const max = Math.max(maxOf(hist.r), maxOf(hist.g), maxOf(hist.b));
      // additive blending so overlaps go white-ish, like real RGB histograms
      drawChannel(ctx, hist.r, w, h, max, 'rgba(244,63,94,0.75)', 'screen');
      drawChannel(ctx, hist.g, w, h, max, 'rgba(34,197,94,0.75)', 'screen');
      drawChannel(ctx, hist.b, w, h, max, 'rgba(59,130,246,0.78)', 'screen');
    }
    ctx.globalCompositeOperation = 'source-over';
  }

  global.TBHistogram = { compute, render };
})(window);
