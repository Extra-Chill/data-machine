/* ============================================================
   Keystroke — tiny hand-drawn canvas charts (no libraries).
   Line chart for per-second WPM, and a WPM-over-time history chart.
   ============================================================ */
(function (global) {
  'use strict';

  function css(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || '#888';
  }

  function fit(canvas) {
    var dpr = Math.min(global.devicePixelRatio || 1, 2);
    var rect = canvas.getBoundingClientRect();
    var w = Math.max(1, rect.width), h = Math.max(1, rect.height || 200);
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(h * dpr);
    var ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx: ctx, w: w, h: h };
  }

  function niceMax(v) {
    if (v <= 0) return 10;
    var pow = Math.pow(10, Math.floor(Math.log10(v)));
    var n = v / pow;
    var step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
    return step * pow;
  }

  // series: [{points:[{x,y}], color, label, fill}], xLabel/yLabel optional
  function lineChart(canvas, series, opts) {
    opts = opts || {};
    var f = fit(canvas), ctx = f.ctx, w = f.w, h = f.h;
    ctx.clearRect(0, 0, w, h);
    var padL = 40, padR = 12, padT = 14, padB = 26;
    var plotW = w - padL - padR, plotH = h - padT - padB;

    var maxX = 1, maxY = 1;
    series.forEach(function (s) {
      s.points.forEach(function (p) { if (p.x > maxX) maxX = p.x; if (p.y > maxY) maxY = p.y; });
    });
    maxY = niceMax(maxY * 1.1);
    var grid = css('--grid'), ink = css('--muted');

    // gridlines + y labels
    ctx.strokeStyle = grid; ctx.fillStyle = ink;
    ctx.lineWidth = 1; ctx.font = '11px ui-monospace, monospace'; ctx.textBaseline = 'middle';
    var ticks = 4;
    for (var i = 0; i <= ticks; i++) {
      var yv = maxY * i / ticks;
      var y = padT + plotH - (yv / maxY) * plotH;
      ctx.globalAlpha = 0.5;
      ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(w - padR, y); ctx.stroke();
      ctx.globalAlpha = 1;
      ctx.textAlign = 'right';
      ctx.fillText(String(Math.round(yv)), padL - 6, y);
    }
    // x label
    ctx.textAlign = 'center';
    if (opts.xLabel) ctx.fillText(opts.xLabel, padL + plotW / 2, h - 8);

    function X(x) { return padL + (maxX === 0 ? 0 : x / maxX) * plotW; }
    function Y(y) { return padT + plotH - (y / maxY) * plotH; }

    series.forEach(function (s) {
      if (!s.points.length) return;
      if (s.fill) {
        var g = ctx.createLinearGradient(0, padT, 0, padT + plotH);
        g.addColorStop(0, s.color + '55'); g.addColorStop(1, s.color + '00');
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.moveTo(X(s.points[0].x), padT + plotH);
        s.points.forEach(function (p) { ctx.lineTo(X(p.x), Y(p.y)); });
        ctx.lineTo(X(s.points[s.points.length - 1].x), padT + plotH);
        ctx.closePath(); ctx.fill();
      }
      ctx.strokeStyle = s.color; ctx.lineWidth = s.width || 2;
      ctx.lineJoin = 'round'; ctx.lineCap = 'round';
      ctx.beginPath();
      s.points.forEach(function (p, idx) {
        var px = X(p.x), py = Y(p.y);
        if (idx === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
      });
      ctx.stroke();
      if (s.dots) {
        ctx.fillStyle = s.color;
        s.points.forEach(function (p) {
          ctx.beginPath(); ctx.arc(X(p.x), Y(p.y), 2.5, 0, Math.PI * 2); ctx.fill();
        });
      }
    });
  }

  global.KeystrokeChart = { lineChart: lineChart };
})(window);
