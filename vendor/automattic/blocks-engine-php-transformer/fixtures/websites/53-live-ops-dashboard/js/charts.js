/* =========================================================
   HELIX GRID — Chart Library
   Hand-drawn canvas + SVG chart renderers. No libraries.
   Every chart is a small factory returning { draw(...) }.
   ========================================================= */
(function () {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function css(name) {
    return getComputedStyle(document.body).getPropertyValue(name).trim();
  }
  function setupHiDPI(canvas) {
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const r = canvas.getBoundingClientRect();
    const w = Math.max(1, r.width), h = Math.max(1, r.height);
    canvas.width = w * dpr; canvas.height = h * dpr;
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx, w, h };
  }
  function niceMax(v) {
    if (v <= 0) return 1;
    const p = Math.pow(10, Math.floor(Math.log10(v)));
    const n = v / p;
    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
  }
  function fmt(n, d) {
    if (Math.abs(n) >= 1000) return (n / 1000).toFixed(d ?? 1) + 'k';
    return n.toFixed(d ?? 0);
  }

  /* ------------------------------------------------------------------
     LINE CHART (scrolling multi-series time-series)
     ------------------------------------------------------------------ */
  function lineChart(canvas, opts) {
    opts = opts || {};
    return {
      draw(seriesList) {
        const { ctx, w, h } = setupHiDPI(canvas);
        ctx.clearRect(0, 0, w, h);
        const padL = 42, padR = 10, padT = 10, padB = 18;
        const cw = w - padL - padR, ch = h - padT - padB;

        // bounds
        let min = Infinity, max = -Infinity, len = 0;
        seriesList.forEach(s => {
          s.data.forEach(p => { if (p.v < min) min = p.v; if (p.v > max) max = p.v; });
          len = Math.max(len, s.data.length);
        });
        if (!isFinite(min)) return;
        if (opts.min != null) min = opts.min;
        if (opts.max != null) max = opts.max;
        const range = (max - min) || 1;
        const pad = range * 0.12; min -= pad; max += pad;

        // grid
        ctx.strokeStyle = css('--line-soft'); ctx.lineWidth = 1;
        ctx.fillStyle = css('--ink-faint');
        ctx.font = '10px ' + css('--font-mono');
        ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
        const rows = 4;
        for (let i = 0; i <= rows; i++) {
          const y = padT + ch * i / rows;
          ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(w - padR, y); ctx.stroke();
          const val = max - (max - min) * i / rows;
          ctx.fillText(opts.fmt ? opts.fmt(val) : fmt(val, opts.decimals), padL - 6, y);
        }
        // vertical time ticks
        ctx.textAlign = 'center'; ctx.textBaseline = 'top';
        for (let i = 0; i <= 4; i++) {
          const x = padL + cw * i / 4;
          const ago = Math.round((1 - i / 4) * (len - 1));
          ctx.fillText('-' + ago + 's', x, h - padB + 4);
        }

        const X = i => padL + cw * (i / (len - 1 || 1));
        const Y = v => padT + ch * (1 - (v - min) / (max - min));

        seriesList.forEach(s => {
          const data = s.data; if (data.length < 2) return;
          // fill under line (optional)
          if (s.fill) {
            const g = ctx.createLinearGradient(0, padT, 0, padT + ch);
            g.addColorStop(0, s.color + '44'); g.addColorStop(1, s.color + '00');
            ctx.beginPath();
            data.forEach((p, i) => i ? ctx.lineTo(X(i), Y(p.v)) : ctx.moveTo(X(i), Y(p.v)));
            ctx.lineTo(X(data.length - 1), padT + ch); ctx.lineTo(X(0), padT + ch); ctx.closePath();
            ctx.fillStyle = g; ctx.fill();
          }
          ctx.beginPath();
          data.forEach((p, i) => i ? ctx.lineTo(X(i), Y(p.v)) : ctx.moveTo(X(i), Y(p.v)));
          ctx.strokeStyle = s.color; ctx.lineWidth = s.width || 1.8;
          ctx.lineJoin = 'round'; ctx.shadowColor = s.color; ctx.shadowBlur = reduceMotion ? 0 : 6;
          ctx.stroke(); ctx.shadowBlur = 0;
          // leading dot
          const last = data[data.length - 1];
          ctx.beginPath(); ctx.arc(X(data.length - 1), Y(last.v), 2.6, 0, 7); ctx.fillStyle = s.color; ctx.fill();
        });

        // threshold line
        if (opts.threshold != null && opts.threshold <= max && opts.threshold >= min) {
          const y = Y(opts.threshold);
          ctx.setLineDash([4, 4]); ctx.strokeStyle = css('--crit'); ctx.globalAlpha = .6;
          ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(w - padR, y); ctx.stroke();
          ctx.setLineDash([]); ctx.globalAlpha = 1;
        }
      }
    };
  }

  /* ------------------------------------------------------------------
     STACKED AREA CHART (generation mix)
     ------------------------------------------------------------------ */
  function stackedArea(canvas, sources) {
    return {
      draw(histories) { // histories: { srcId: [v,...] }
        const { ctx, w, h } = setupHiDPI(canvas);
        ctx.clearRect(0, 0, w, h);
        const padL = 44, padR = 10, padT = 10, padB = 8;
        const cw = w - padL - padR, ch = h - padT - padB;
        const len = histories[sources[0].id]?.length || 0;
        if (len < 2) return;
        // total max
        let max = 0;
        for (let i = 0; i < len; i++) {
          let sum = 0; sources.forEach(s => sum += Math.max(0, histories[s.id][i] || 0));
          if (sum > max) max = sum;
        }
        max = niceMax(max);
        const X = i => padL + cw * (i / (len - 1));
        const Y = v => padT + ch * (1 - v / max);

        // gridlines
        ctx.strokeStyle = css('--line-soft'); ctx.fillStyle = css('--ink-faint');
        ctx.font = '10px ' + css('--font-mono'); ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
        for (let i = 0; i <= 3; i++) {
          const y = padT + ch * i / 3;
          ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(w - padR, y); ctx.stroke();
          ctx.fillText(fmt(max - max * i / 3) + 'MW', padL - 6, y);
        }

        const baseline = new Array(len).fill(0);
        sources.forEach(s => {
          ctx.beginPath();
          for (let i = 0; i < len; i++) {
            const v = baseline[i] + Math.max(0, histories[s.id][i] || 0);
            i ? ctx.lineTo(X(i), Y(v)) : ctx.moveTo(X(i), Y(v));
          }
          for (let i = len - 1; i >= 0; i--) ctx.lineTo(X(i), Y(baseline[i]));
          ctx.closePath();
          ctx.fillStyle = s.color + 'cc'; ctx.fill();
          ctx.strokeStyle = s.color; ctx.lineWidth = 1; ctx.stroke();
          for (let i = 0; i < len; i++) baseline[i] += Math.max(0, histories[s.id][i] || 0);
        });
      }
    };
  }

  /* ------------------------------------------------------------------
     SPARKLINE (tiny, for KPI tiles) — returns SVG path string
     ------------------------------------------------------------------ */
  function sparkPath(data, w, h) {
    if (!data.length) return '';
    let min = Infinity, max = -Infinity;
    data.forEach(v => { if (v < min) min = v; if (v > max) max = v; });
    const range = (max - min) || 1;
    return data.map((v, i) => {
      const x = (i / (data.length - 1 || 1)) * w;
      const y = h - ((v - min) / range) * (h - 2) - 1;
      return (i ? 'L' : 'M') + x.toFixed(1) + ' ' + y.toFixed(1);
    }).join(' ');
  }

  /* ------------------------------------------------------------------
     RADIAL GAUGE (canvas)
     ------------------------------------------------------------------ */
  function gauge(canvas, opts) {
    opts = opts || {};
    return {
      draw(value) {
        const { ctx, w, h } = setupHiDPI(canvas);
        ctx.clearRect(0, 0, w, h);
        const cx = w / 2, cy = h * 0.62, r = Math.min(w, h) * 0.42;
        const start = Math.PI * 0.75, end = Math.PI * 2.25; // 270deg
        const min = opts.min ?? 0, max = opts.max ?? 100;
        const t = Math.max(0, Math.min(1, (value - min) / (max - min)));
        // track
        ctx.lineCap = 'round'; ctx.lineWidth = Math.max(6, r * 0.16);
        ctx.strokeStyle = css('--line'); ctx.beginPath();
        ctx.arc(cx, cy, r, start, end); ctx.stroke();
        // colored arc
        const ang = start + (end - start) * t;
        const color = opts.colorFor ? opts.colorFor(value) : css('--accent');
        ctx.strokeStyle = color; ctx.shadowColor = color; ctx.shadowBlur = reduceMotion ? 0 : 10;
        ctx.beginPath(); ctx.arc(cx, cy, r, start, ang); ctx.stroke(); ctx.shadowBlur = 0;
        // needle
        ctx.strokeStyle = css('--ink'); ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(cx, cy);
        ctx.lineTo(cx + Math.cos(ang) * r * 0.82, cy + Math.sin(ang) * r * 0.82); ctx.stroke();
        ctx.fillStyle = css('--ink'); ctx.beginPath(); ctx.arc(cx, cy, 3.5, 0, 7); ctx.fill();
        // value text
        ctx.fillStyle = css('--ink'); ctx.textAlign = 'center';
        ctx.font = '700 ' + (r * 0.5).toFixed(0) + 'px ' + css('--font-ui');
        ctx.fillText((opts.format ? opts.format(value) : value.toFixed(opts.decimals ?? 0)), cx, cy + r * 0.05);
        ctx.fillStyle = css('--ink-faint'); ctx.font = '10px ' + css('--font-mono');
        ctx.fillText(opts.unit || '', cx, cy + r * 0.42);
      }
    };
  }

  /* ------------------------------------------------------------------
     BAR CHART (horizontal) — drawn via DOM in app.js, but we also
     provide a vertical canvas bar chart for the detail page.
     ------------------------------------------------------------------ */
  function barChart(canvas, opts) {
    opts = opts || {};
    return {
      draw(items) { // [{label, value, color}]
        const { ctx, w, h } = setupHiDPI(canvas);
        ctx.clearRect(0, 0, w, h);
        const padL = 8, padR = 8, padT = 8, padB = 22;
        const cw = w - padL - padR, ch = h - padT - padB;
        let max = niceMax(Math.max(...items.map(i => i.value), 1));
        const bw = cw / items.length;
        ctx.textAlign = 'center'; ctx.font = '10px ' + css('--font-mono');
        items.forEach((it, i) => {
          const bh = ch * (it.value / max);
          const x = padL + bw * i + bw * 0.18, bwid = bw * 0.64, y = padT + ch - bh;
          const g = ctx.createLinearGradient(0, y, 0, padT + ch);
          g.addColorStop(0, it.color); g.addColorStop(1, it.color + '55');
          ctx.fillStyle = g;
          const rr = 4;
          ctx.beginPath();
          ctx.moveTo(x, y + rr); ctx.arcTo(x, y, x + rr, y, rr);
          ctx.lineTo(x + bwid - rr, y); ctx.arcTo(x + bwid, y, x + bwid, y + rr, rr);
          ctx.lineTo(x + bwid, padT + ch); ctx.lineTo(x, padT + ch); ctx.closePath(); ctx.fill();
          ctx.fillStyle = css('--ink-faint');
          ctx.fillText(it.label, x + bwid / 2, h - 8);
          ctx.fillStyle = css('--ink');
          ctx.fillText(fmt(it.value, 0), x + bwid / 2, y - 4);
        });
      }
    };
  }

  /* ------------------------------------------------------------------
     SCATTER / latency-vs-error bubble (detail page extra)
     ------------------------------------------------------------------ */
  function scatter(canvas, opts) {
    opts = opts || {};
    return {
      draw(points) { // [{x,y,r,color,label}]
        const { ctx, w, h } = setupHiDPI(canvas);
        ctx.clearRect(0, 0, w, h);
        const padL = 36, padR = 12, padT = 12, padB = 24;
        const cw = w - padL - padR, ch = h - padT - padB;
        const maxX = niceMax(Math.max(...points.map(p => p.x), 1));
        const maxY = niceMax(Math.max(...points.map(p => p.y), 1));
        ctx.strokeStyle = css('--line-soft'); ctx.fillStyle = css('--ink-faint');
        ctx.font = '10px ' + css('--font-mono');
        ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
        for (let i = 0; i <= 3; i++) {
          const y = padT + ch * i / 3;
          ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(w - padR, y); ctx.stroke();
          ctx.fillText(fmt(maxY - maxY * i / 3, 1), padL - 5, y);
        }
        ctx.textAlign = 'center'; ctx.textBaseline = 'top';
        for (let i = 0; i <= 3; i++) {
          const x = padL + cw * i / 3;
          ctx.fillText(fmt(maxX * i / 3, 0), x, h - padB + 4);
        }
        points.forEach(p => {
          const x = padL + cw * (p.x / maxX), y = padT + ch * (1 - p.y / maxY);
          ctx.beginPath(); ctx.arc(x, y, p.r, 0, 7);
          ctx.fillStyle = p.color + '99'; ctx.fill();
          ctx.strokeStyle = p.color; ctx.lineWidth = 1.4; ctx.stroke();
        });
        // axis labels
        ctx.fillStyle = css('--ink-faint'); ctx.textAlign = 'center';
        ctx.fillText(opts.xlabel || '', padL + cw / 2, h - 11);
      }
    };
  }

  window.HelixCharts = {
    lineChart, stackedArea, gauge, barChart, scatter, sparkPath, css, fmt, reduceMotion,
  };
})();
