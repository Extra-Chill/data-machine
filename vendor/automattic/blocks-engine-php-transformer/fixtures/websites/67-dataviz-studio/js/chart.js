/* =========================================================
   PLOTWEAVER — Chart renderer (hand-written, no libraries)
   Renders bar / grouped / stacked / line / area / scatter /
   pie / donut / radar / heatmap / histogram from a "view model".
   Supports nice-number axes, gridlines, legend, hover tooltips,
   click-to-toggle series, and an entrance animation.

   Exposes window.PW.Chart  ->  new PW.Chart(canvas, tooltipEl)
   .render(model, opts)
   ========================================================= */
(function () {
  'use strict';
  const PW = (window.PW = window.PW || {});
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- palettes (also used by config + gallery) ---- */
  const PALETTES = {
    aurora:   ['#6ee7ff', '#a78bfa', '#fb7185', '#34d399', '#fbbf24', '#f472b6', '#60a5fa', '#5eead4'],
    sunset:   ['#fb7185', '#fb923c', '#fbbf24', '#facc15', '#a3e635', '#f472b6', '#f59e0b', '#ef4444'],
    ocean:    ['#0ea5e9', '#06b6d4', '#14b8a6', '#3b82f6', '#6366f1', '#8b5cf6', '#22d3ee', '#2dd4bf'],
    forest:   ['#34d399', '#10b981', '#84cc16', '#22c55e', '#a3e635', '#4ade80', '#65a30d', '#16a34a'],
    mono:     ['#94a3b8', '#cbd5e1', '#64748b', '#e2e8f0', '#475569', '#f1f5f9', '#334155', '#94a3b8'],
    candy:    ['#f472b6', '#c084fc', '#818cf8', '#60a5fa', '#22d3ee', '#34d399', '#a3e635', '#fbbf24'],
  };
  PW.PALETTES = PALETTES;

  /* ---- nice number helpers (axis ticks) ---- */
  function niceNum(range, round) {
    const exp = Math.floor(Math.log10(range));
    const f = range / Math.pow(10, exp);
    let nf;
    if (round) nf = f < 1.5 ? 1 : f < 3 ? 2 : f < 7 ? 5 : 10;
    else nf = f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10;
    return nf * Math.pow(10, exp);
  }
  // Produce ~tickCount "nice" tick values covering [min,max].
  function niceScale(min, max, tickCount) {
    if (min === max) { min -= 1; max += 1; }
    if (!isFinite(min) || !isFinite(max)) { min = 0; max = 1; }
    const range = niceNum(max - min, false);
    const step = niceNum(range / Math.max(1, (tickCount - 1)), true);
    const niceMin = Math.floor(min / step) * step;
    const niceMax = Math.ceil(max / step) * step;
    const ticks = [];
    for (let v = niceMin; v <= niceMax + step * 0.5; v += step) ticks.push(+v.toFixed(10));
    return { min: niceMin, max: niceMax, step, ticks };
  }

  /* ---- value formatters ---- */
  function makeFmt(kind) {
    switch (kind) {
      case 'currency': return v => '$' + compact(v);
      case 'percent':  return v => trim(v) + '%';
      case 'thousands':return v => Number(v).toLocaleString('en-US');
      case 'compact':  return v => compact(v);
      default:         return v => trim(v);
    }
  }
  function trim(v) {
    if (!isFinite(v)) return String(v);
    const r = Math.round(v * 100) / 100;
    return (Math.abs(r) >= 1000 ? r.toLocaleString('en-US') : String(r));
  }
  function compact(v) {
    const a = Math.abs(v);
    if (a >= 1e9) return (v / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
    if (a >= 1e6) return (v / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (a >= 1e3) return (v / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
    return trim(v);
  }

  /* ---- color utilities ---- */
  function hexToRgb(h) {
    h = h.replace('#', '');
    if (h.length === 3) h = h.split('').map(c => c + c).join('');
    return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
  }
  function rgba(hex, a) { const [r, g, b] = hexToRgb(hex); return `rgba(${r},${g},${b},${a})`; }
  function lerpColor(c1, c2, t) {
    const a = hexToRgb(c1), b = hexToRgb(c2);
    return `rgb(${a.map((v, i) => Math.round(v + (b[i] - v) * t)).join(',')})`;
  }
  function readCss(name, fallback) {
    const v = getComputedStyle(document.body).getPropertyValue(name).trim();
    return v || fallback;
  }

  /* ==================================================================
     CHART CLASS
     ================================================================== */
  function Chart(canvas, tooltipEl) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d');
    this.tooltip = tooltipEl || null;
    this.model = null;
    this.opts = null;
    this.hidden = {};        // series index -> true (toggled off)
    this.hitmap = [];        // {x,y,r?, rect?, label, rows[]} for hover
    this.legendHit = [];     // legend rectangles for click-toggle
    this.anim = 0;           // 0..1 entrance progress
    this._raf = null;
    this._bindEvents();
  }

  Chart.prototype._setup = function () {
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const r = this.canvas.getBoundingClientRect();
    const w = Math.max(10, r.width), h = Math.max(10, r.height);
    this.canvas.width = Math.round(w * dpr);
    this.canvas.height = Math.round(h * dpr);
    this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    this.W = w; this.H = h; this.dpr = dpr;
  };

  Chart.prototype.render = function (model, opts) {
    if (model) this.model = model;
    if (opts) this.opts = opts;
    if (!this.model) return;
    // reset toggles when series set changes
    if (this._lastSig !== sigOf(this.model)) { this.hidden = {}; this._lastSig = sigOf(this.model); }
    const animate = this.opts && this.opts.animate && !reduceMotion;
    cancelAnimationFrame(this._raf);
    if (!animate) { this.anim = 1; this._draw(); return; }
    const t0 = performance.now(), dur = 650;
    const step = (t) => {
      this.anim = Math.min(1, (t - t0) / dur);
      const e = 1 - Math.pow(1 - this.anim, 3); // easeOutCubic
      this._drawAnim = e;
      this._draw();
      if (this.anim < 1) this._raf = requestAnimationFrame(step);
    };
    this.anim = 0; this._raf = requestAnimationFrame(step);
  };

  function sigOf(m) {
    return m.type + '|' + (m.series ? m.series.map(s => s.name).join(',') : '') + '|' + (m.labels ? m.labels.length : 0);
  }

  Chart.prototype.redraw = function () { if (this.model) { this.anim = 1; this._drawAnim = 1; this._draw(); } };

  /* ---- master draw dispatcher ---- */
  Chart.prototype._draw = function () {
    this._setup();
    const ctx = this.ctx, m = this.model, o = this.opts;
    ctx.clearRect(0, 0, this.W, this.H);
    this.hitmap = []; this.legendHit = [];
    const ink = readCss('--ink', '#e9eefb');
    const inkSoft = readCss('--ink-faint', '#62718d');
    const gridc = readCss('--grid', 'rgba(140,170,220,.1)');
    this._theme = { ink, inkSoft, gridc };

    // title
    let top = 16;
    if (o.title) {
      ctx.fillStyle = ink; ctx.font = '600 16px var(--font-ui), Inter, sans-serif';
      ctx.textAlign = 'left'; ctx.textBaseline = 'top';
      ctx.fillText(o.title, 24, top);
      top += 26;
    }

    const palette = PALETTES[o.palette] || PALETTES.aurora;
    const colorFor = (i) => palette[i % palette.length];
    this._colorFor = colorFor;

    // legend layout (reserves space)
    const legend = this._planLegend(top, palette);

    const type = m.type;
    const area = this._plotArea(top, legend);
    this._lastArea = area;

    if (type === 'pie' || type === 'donut') this._drawPie(area, type === 'donut');
    else if (type === 'radar') this._drawRadar(area);
    else if (type === 'heatmap') this._drawHeatmap(area);
    else if (type === 'scatter') this._drawScatter(area);
    else if (type === 'histogram') this._drawHistogram(area);
    else this._drawCartesian(area, type); // bar / line / area

    this._drawLegend(legend, palette);
  };

  Chart.prototype._plotArea = function (top, legend) {
    const padL = 62, padR = 22, padB = 46;
    let t = top + 8, b = this.H - padB;
    if (legend.pos === 'top') t += legend.h;
    if (legend.pos === 'bottom') b -= legend.h;
    let l = padL, r = this.W - padR;
    if (legend.pos === 'right') r -= legend.w;
    if (legend.pos === 'left') l += legend.w;
    return { l, t, r, b, w: r - l, h: b - t };
  };

  /* ---- legend planning + drawing ---- */
  Chart.prototype._planLegend = function (top, palette) {
    const m = this.model, o = this.opts;
    const pos = o.legendPos || 'top';
    if (pos === 'none') return { pos: 'none', items: [], h: 0, w: 0 };
    let items = [];
    if (m.type === 'pie' || m.type === 'donut') {
      items = (m.labels || []).map((lab, i) => ({ name: lab, color: palette[i % palette.length], idx: i }));
    } else if (m.type === 'scatter') {
      items = (m.groups || []).map((g, i) => ({ name: g, color: palette[i % palette.length], idx: i }));
    } else if (m.type === 'histogram') {
      return { pos: 'none', items: [], h: 0, w: 0 };
    } else if (m.series) {
      items = m.series.map((s, i) => ({ name: s.name, color: palette[i % palette.length], idx: i }));
    }
    const h = (pos === 'top' || pos === 'bottom') ? 26 : items.length * 22 + 6;
    const w = (pos === 'left' || pos === 'right') ? 130 : 0;
    return { pos, items, h, w };
  };

  Chart.prototype._drawLegend = function (legend, palette) {
    if (legend.pos === 'none' || !legend.items.length) return;
    const ctx = this.ctx;
    ctx.font = '500 12px var(--font-ui), Inter, sans-serif';
    ctx.textBaseline = 'middle';
    const horizontal = (legend.pos === 'top' || legend.pos === 'bottom');
    const ink = this._theme.ink;
    if (horizontal) {
      // measure & center
      let total = 0;
      const widths = legend.items.map(it => { const wv = ctx.measureText(it.name).width + 26; total += wv; return wv; });
      let x = (this.W - total) / 2; if (x < 16) x = 16;
      const y = legend.pos === 'top' ? (this._lastArea ? this._lastArea.t - legend.h + 10 : 40) : this.H - 20;
      ctx.textAlign = 'left';
      legend.items.forEach((it, i) => {
        const off = this.hidden[it.idx];
        ctx.globalAlpha = off ? 0.35 : 1;
        ctx.fillStyle = it.color;
        roundRect(ctx, x, y - 6, 12, 12, 3); ctx.fill();
        ctx.fillStyle = ink;
        if (off) { ctx.save(); ctx.strokeStyle = ink; ctx.beginPath(); }
        ctx.fillText(it.name, x + 18, y);
        if (off) {
          const tw = ctx.measureText(it.name).width;
          ctx.beginPath(); ctx.moveTo(x + 18, y); ctx.lineTo(x + 18 + tw, y); ctx.strokeStyle = ink; ctx.lineWidth = 1; ctx.stroke(); ctx.restore();
        }
        this.legendHit.push({ x, y: y - 9, w: widths[i], h: 18, idx: it.idx });
        x += widths[i];
        ctx.globalAlpha = 1;
      });
    } else {
      const x = legend.pos === 'left' ? 22 : this.W - legend.w + 14;
      let y = (this._lastArea ? this._lastArea.t : 60) + 8;
      ctx.textAlign = 'left';
      legend.items.forEach((it) => {
        const off = this.hidden[it.idx];
        ctx.globalAlpha = off ? 0.35 : 1;
        ctx.fillStyle = it.color; roundRect(ctx, x, y - 6, 12, 12, 3); ctx.fill();
        ctx.fillStyle = ink; ctx.fillText(clip(ctx, it.name, legend.w - 24), x + 18, y);
        this.legendHit.push({ x: x - 2, y: y - 9, w: legend.w - 10, h: 18, idx: it.idx });
        y += 22; ctx.globalAlpha = 1;
      });
    }
    ctx.globalAlpha = 1;
  };

  /* ==================================================================
     CARTESIAN: bar (grouped/stacked), line, area
     ================================================================== */
  Chart.prototype._drawCartesian = function (a, type) {
    const ctx = this.ctx, m = this.model, o = this.opts;
    const labels = m.labels || [];
    const activeSeries = m.series.filter((s, i) => !this.hidden[i]);
    const stacked = o.stacked && (type === 'bar' || type === 'area');

    // compute value extent
    let min = 0, max = 0;
    if (stacked) {
      labels.forEach((_, li) => {
        let sumPos = 0, sumNeg = 0;
        m.series.forEach((s, si) => { if (this.hidden[si]) return; const v = s.values[li]; if (v >= 0) sumPos += v; else sumNeg += v; });
        max = Math.max(max, sumPos); min = Math.min(min, sumNeg);
      });
    } else {
      m.series.forEach((s, si) => { if (this.hidden[si]) return; s.values.forEach(v => { if (!isFinite(v)) return; max = Math.max(max, v); min = Math.min(min, v); }); });
    }
    if (max === min) max = min + 1;
    const sc = niceScale(min, max, 6);
    const yToPx = (v) => a.b - ((v - sc.min) / (sc.max - sc.min)) * a.h;
    const e = this._drawAnim != null ? this._drawAnim : 1;

    // gridlines + y axis
    this._yAxis(a, sc);
    // x axis baseline
    const zeroY = yToPx(Math.max(sc.min, Math.min(sc.max, 0)));

    // x positions
    const n = labels.length;
    const band = a.w / Math.max(1, n);
    const xCenter = (i) => a.l + band * (i + 0.5);

    // x labels
    this._xLabels(a, labels, band);

    if (type === 'bar') {
      const seriesCount = stacked ? 1 : Math.max(1, activeSeries.length);
      const groupW = band * 0.72;
      const barW = stacked ? groupW : groupW / seriesCount;
      labels.forEach((lab, li) => {
        let stackPos = 0, stackNeg = 0;
        let drawnIdx = 0;
        m.series.forEach((s, si) => {
          if (this.hidden[si]) return;
          const v = s.values[li]; if (!isFinite(v)) { drawnIdx++; return; }
          const col = this._colorFor(si);
          let x, yTop, yBot;
          if (stacked) {
            const base = v >= 0 ? stackPos : stackNeg;
            const top = base + v;
            x = xCenter(li) - barW / 2;
            yBot = yToPx(base); yTop = yToPx(top);
            if (v >= 0) stackPos = top; else stackNeg = top;
          } else {
            x = xCenter(li) - groupW / 2 + drawnIdx * barW;
            yBot = zeroY; yTop = yToPx(v);
          }
          let hTop = yTop, hBot = yBot;
          // animate height from baseline
          hTop = yBot + (yTop - yBot) * e;
          const rectY = Math.min(hTop, hBot), rectH = Math.abs(hBot - hTop);
          ctx.fillStyle = col;
          roundRect(ctx, x + (stacked ? 0 : 1), rectY, barW - (stacked ? 0 : 2), Math.max(0, rectH), Math.min(4, barW / 3));
          ctx.fill();
          this.hitmap.push({ rect: [x, Math.min(yTop, yBot), barW, Math.abs(yBot - yTop)], label: lab, rows: [{ name: s.name, value: v, color: col }] });
          drawnIdx++;
        });
      });
    } else {
      // line / area
      const areaMode = (type === 'area');
      // for stacked area we accumulate
      const stackTop = labels.map(() => 0);
      m.series.forEach((s, si) => {
        if (this.hidden[si]) return;
        const col = this._colorFor(si);
        const pts = [];
        labels.forEach((lab, li) => {
          let v = s.values[li];
          let plotV = v;
          if (stacked && areaMode) { plotV = stackTop[li] + (isFinite(v) ? v : 0); stackTop[li] = plotV; }
          pts.push({ x: xCenter(li), y: yToPx(isFinite(plotV) ? plotV : sc.min), v, baseV: stackTop[li] - (isFinite(v) ? v : 0) });
        });
        if (areaMode) {
          ctx.beginPath();
          pts.forEach((p, i) => { const y = a.b + (p.y - a.b) * e; i ? ctx.lineTo(p.x, y) : ctx.moveTo(p.x, y); });
          if (stacked) {
            for (let i = pts.length - 1; i >= 0; i--) { const by = yToPx(pts[i].baseV); ctx.lineTo(pts[i].x, a.b + (by - a.b) * e); }
          } else {
            ctx.lineTo(pts[pts.length - 1].x, zeroY); ctx.lineTo(pts[0].x, zeroY);
          }
          ctx.closePath();
          const grad = ctx.createLinearGradient(0, a.t, 0, a.b);
          grad.addColorStop(0, rgba(col, 0.42)); grad.addColorStop(1, rgba(col, 0.05));
          ctx.fillStyle = grad; ctx.fill();
        }
        // line
        ctx.beginPath();
        pts.forEach((p, i) => { const y = a.b + (p.y - a.b) * e; i ? ctx.lineTo(p.x, y) : ctx.moveTo(p.x, y); });
        ctx.strokeStyle = col; ctx.lineWidth = 2.4; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
        ctx.stroke();
        // points
        pts.forEach((p, li) => {
          if (!isFinite(p.v)) return;
          const y = a.b + (p.y - a.b) * e;
          ctx.beginPath(); ctx.arc(p.x, y, 3.2, 0, Math.PI * 2);
          ctx.fillStyle = readCss('--panel', '#11161f'); ctx.fill();
          ctx.lineWidth = 2; ctx.strokeStyle = col; ctx.stroke();
          this.hitmap.push({ x: p.x, y, r: 11, label: labels[li], rows: [{ name: s.name, value: p.v, color: col }] });
        });
      });
      // group hover by x for line/area (replace individual with column groups)
      this._groupHitByColumn(labels, xCenter, band);
    }
  };

  // For line/area: also register column-wide hit zones grouping all series.
  Chart.prototype._groupHitByColumn = function (labels, xCenter, band) {
    const a = this._lastArea, m = this.model;
    labels.forEach((lab, li) => {
      const rows = [];
      m.series.forEach((s, si) => { if (this.hidden[si]) return; const v = s.values[li]; if (isFinite(v)) rows.push({ name: s.name, value: v, color: this._colorFor(si) }); });
      if (rows.length) this.hitmap.push({ rect: [xCenter(li) - band / 2, a.t, band, a.h], label: lab, rows, soft: true });
    });
  };

  Chart.prototype._yAxis = function (a, sc) {
    const ctx = this.ctx, fmt = makeFmt(this.opts.valueFormat);
    ctx.font = '11px var(--font-mono), monospace'; ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
    sc.ticks.forEach(v => {
      const y = a.b - ((v - sc.min) / (sc.max - sc.min)) * a.h;
      if (this.opts.grid !== false) {
        ctx.strokeStyle = this._theme.gridc; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(a.l, y + 0.5); ctx.lineTo(a.r, y + 0.5); ctx.stroke();
      }
      ctx.fillStyle = this._theme.inkSoft; ctx.fillText(fmt(v), a.l - 9, y);
    });
    if (this.opts.yLabel) {
      ctx.save(); ctx.translate(16, (a.t + a.b) / 2); ctx.rotate(-Math.PI / 2);
      ctx.fillStyle = this._theme.inkSoft; ctx.font = '600 11px var(--font-ui)'; ctx.textAlign = 'center';
      ctx.fillText(this.opts.yLabel, 0, 0); ctx.restore();
    }
  };

  Chart.prototype._xLabels = function (a, labels, band) {
    const ctx = this.ctx;
    ctx.fillStyle = this._theme.inkSoft; ctx.font = '11px var(--font-ui), sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'top';
    const maxW = band - 6;
    const skip = Math.ceil((labels.length * 60) / Math.max(1, a.w)); // thin out if crowded
    labels.forEach((lab, i) => {
      if (i % skip !== 0) return;
      const x = a.l + band * (i + 0.5);
      ctx.fillText(clip(ctx, String(lab), maxW), x, a.b + 8);
    });
    if (this.opts.xLabel) {
      ctx.font = '600 11px var(--font-ui)'; ctx.fillStyle = this._theme.inkSoft;
      ctx.fillText(this.opts.xLabel, (a.l + a.r) / 2, a.b + 26);
    }
  };

  /* ==================================================================
     SCATTER
     ================================================================== */
  Chart.prototype._drawScatter = function (a) {
    const ctx = this.ctx, m = this.model, o = this.opts;
    // expects m.points = [{x,y,label,group}], m.groups = [names]
    const pts = m.points || [];
    let xmin = Infinity, xmax = -Infinity, ymin = Infinity, ymax = -Infinity;
    pts.forEach(p => { xmin = Math.min(xmin, p.x); xmax = Math.max(xmax, p.x); ymin = Math.min(ymin, p.y); ymax = Math.max(ymax, p.y); });
    if (!isFinite(xmin)) { xmin = 0; xmax = 1; ymin = 0; ymax = 1; }
    const sx = niceScale(xmin, xmax, 6), sy = niceScale(ymin, ymax, 6);
    const xToPx = v => a.l + ((v - sx.min) / (sx.max - sx.min)) * a.w;
    const yToPx = v => a.b - ((v - sy.min) / (sy.max - sy.min)) * a.h;
    const fmt = makeFmt(o.valueFormat);
    // grids
    ctx.font = '11px var(--font-mono)'; ctx.textBaseline = 'middle'; ctx.textAlign = 'right';
    sy.ticks.forEach(v => {
      const y = yToPx(v);
      if (o.grid !== false) { ctx.strokeStyle = this._theme.gridc; ctx.beginPath(); ctx.moveTo(a.l, y + .5); ctx.lineTo(a.r, y + .5); ctx.stroke(); }
      ctx.fillStyle = this._theme.inkSoft; ctx.fillText(fmt(v), a.l - 9, y);
    });
    ctx.textAlign = 'center'; ctx.textBaseline = 'top';
    sx.ticks.forEach(v => {
      const x = xToPx(v);
      if (o.grid !== false) { ctx.strokeStyle = this._theme.gridc; ctx.beginPath(); ctx.moveTo(x + .5, a.t); ctx.lineTo(x + .5, a.b); ctx.stroke(); }
      ctx.fillStyle = this._theme.inkSoft; ctx.fillText(fmt(v), x, a.b + 8);
    });
    if (o.xLabel) { ctx.font = '600 11px var(--font-ui)'; ctx.fillText(o.xLabel, (a.l + a.r) / 2, a.b + 26); }
    if (o.yLabel) { ctx.save(); ctx.translate(16, (a.t + a.b) / 2); ctx.rotate(-Math.PI / 2); ctx.font = '600 11px var(--font-ui)'; ctx.textAlign = 'center'; ctx.fillText(o.yLabel, 0, 0); ctx.restore(); }
    const e = this._drawAnim != null ? this._drawAnim : 1;
    pts.forEach(p => {
      const gi = p.group || 0;
      if (this.hidden[gi]) return;
      const col = this._colorFor(gi);
      const px = xToPx(p.x), py = yToPx(p.y);
      ctx.beginPath(); ctx.arc(px, py, 5 * e, 0, Math.PI * 2);
      ctx.fillStyle = rgba(col, 0.78); ctx.fill();
      ctx.lineWidth = 1.2; ctx.strokeStyle = col; ctx.stroke();
      this.hitmap.push({ x: px, y: py, r: 9, label: p.label || '', rows: [{ name: (m.groups && m.groups[gi]) || 'point', value: `(${trim(p.x)}, ${trim(p.y)})`, color: col, raw: true }] });
    });
  };

  /* ==================================================================
     PIE / DONUT
     ================================================================== */
  Chart.prototype._drawPie = function (a, donut) {
    const ctx = this.ctx, m = this.model, o = this.opts;
    const cx = (a.l + a.r) / 2, cy = (a.t + a.b) / 2;
    const R = Math.min(a.w, a.h) / 2 - 10;
    const rin = donut ? R * 0.56 : 0;
    const vals = m.values.map((v, i) => ({ v: Math.max(0, v || 0), label: m.labels[i], idx: i })).filter(d => !this.hidden[d.idx]);
    const total = vals.reduce((s, d) => s + d.v, 0) || 1;
    const e = this._drawAnim != null ? this._drawAnim : 1;
    let ang = -Math.PI / 2;
    const fmt = makeFmt(o.valueFormat);
    vals.forEach((d) => {
      const slice = (d.v / total) * Math.PI * 2 * e;
      const col = this._colorFor(d.idx);
      const a1 = ang + slice;
      ctx.beginPath();
      if (donut) {
        ctx.arc(cx, cy, R, ang, a1);
        ctx.arc(cx, cy, rin, a1, ang, true);
      } else {
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, R, ang, a1);
      }
      ctx.closePath(); ctx.fillStyle = col; ctx.fill();
      ctx.strokeStyle = readCss('--panel', '#11161f'); ctx.lineWidth = 2; ctx.stroke();
      // label
      const mid = ang + slice / 2;
      if (d.v / total > 0.04 && e > 0.9) {
        const lr = (R + rin) / 2 || R * 0.65;
        const lx = cx + Math.cos(mid) * (donut ? lr : R * 0.62);
        const ly = cy + Math.sin(mid) * (donut ? lr : R * 0.62);
        ctx.fillStyle = '#0b0e15'; ctx.font = '600 11px var(--font-ui)';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText(Math.round(d.v / total * 100) + '%', lx, ly);
      }
      this.hitmap.push({ wedge: { cx, cy, R, rin, a0: ang, a1: ang + slice }, label: d.label, rows: [{ name: d.label, value: d.v, color: col, pct: (d.v / total * 100) }] });
      ang += slice;
    });
    if (donut && e > 0.9) {
      ctx.fillStyle = this._theme.ink; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.font = '700 22px var(--font-ui)'; ctx.fillText(compact(total), cx, cy - 7);
      ctx.fillStyle = this._theme.inkSoft; ctx.font = '500 11px var(--font-ui)';
      ctx.fillText('total', cx, cy + 13);
    }
  };

  /* ==================================================================
     RADAR
     ================================================================== */
  Chart.prototype._drawRadar = function (a) {
    const ctx = this.ctx, m = this.model, o = this.opts;
    const axes = m.labels || [];            // spokes
    const N = axes.length; if (N < 3) { this._noData(a, 'Radar needs ≥3 axis rows'); return; }
    const cx = (a.l + a.r) / 2, cy = (a.t + a.b) / 2;
    const R = Math.min(a.w, a.h) / 2 - 24;
    let max = 0; m.series.forEach((s, si) => { if (this.hidden[si]) return; s.values.forEach(v => max = Math.max(max, v || 0)); });
    const sc = niceScale(0, max, 5); max = sc.max;
    const ringsN = sc.ticks.length - 1;
    const angle = i => -Math.PI / 2 + i / N * Math.PI * 2;
    const e = this._drawAnim != null ? this._drawAnim : 1;
    // rings
    ctx.strokeStyle = this._theme.gridc; ctx.lineWidth = 1;
    for (let r = 1; r <= ringsN; r++) {
      const rr = R * r / ringsN;
      ctx.beginPath();
      for (let i = 0; i <= N; i++) { const an = angle(i % N); const x = cx + Math.cos(an) * rr, y = cy + Math.sin(an) * rr; i ? ctx.lineTo(x, y) : ctx.moveTo(x, y); }
      ctx.stroke();
    }
    // spokes + labels
    ctx.fillStyle = this._theme.inkSoft; ctx.font = '11px var(--font-ui)';
    for (let i = 0; i < N; i++) {
      const an = angle(i); const x = cx + Math.cos(an) * R, y = cy + Math.sin(an) * R;
      ctx.strokeStyle = this._theme.gridc; ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(x, y); ctx.stroke();
      const lx = cx + Math.cos(an) * (R + 14), ly = cy + Math.sin(an) * (R + 14);
      ctx.textAlign = Math.abs(Math.cos(an)) < 0.3 ? 'center' : (Math.cos(an) > 0 ? 'left' : 'right');
      ctx.textBaseline = 'middle';
      ctx.fillText(clip(ctx, String(axes[i]), 90), lx, ly);
    }
    // series polygons
    m.series.forEach((s, si) => {
      if (this.hidden[si]) return;
      const col = this._colorFor(si);
      ctx.beginPath();
      for (let i = 0; i < N; i++) {
        const an = angle(i); const rr = R * ((s.values[i] || 0) / max) * e;
        const x = cx + Math.cos(an) * rr, y = cy + Math.sin(an) * rr;
        i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
        this.hitmap.push({ x, y, r: 9, label: axes[i], rows: [{ name: s.name, value: s.values[i], color: col }] });
      }
      ctx.closePath();
      ctx.fillStyle = rgba(col, 0.16); ctx.fill();
      ctx.strokeStyle = col; ctx.lineWidth = 2; ctx.stroke();
      for (let i = 0; i < N; i++) { const an = angle(i); const rr = R * ((s.values[i] || 0) / max) * e; ctx.beginPath(); ctx.arc(cx + Math.cos(an) * rr, cy + Math.sin(an) * rr, 3, 0, Math.PI * 2); ctx.fillStyle = col; ctx.fill(); }
    });
  };

  /* ==================================================================
     HEATMAP
     ================================================================== */
  Chart.prototype._drawHeatmap = function (a) {
    const ctx = this.ctx, m = this.model, o = this.opts;
    // m.rows = [{label, values:[...]}], m.cols = [names]
    const rows = m.rowsHM || [], cols = m.cols || [];
    if (!rows.length || !cols.length) { this._noData(a, 'Heatmap needs ≥2 numeric columns'); return; }
    let min = Infinity, max = -Infinity;
    rows.forEach(r => r.values.forEach(v => { if (isFinite(v)) { min = Math.min(min, v); max = Math.max(max, v); } }));
    if (!isFinite(min)) { min = 0; max = 1; }
    const labelW = 96, headH = 22;
    const gx = a.l + labelW, gy = a.t + headH;
    const cw = (a.r - gx) / cols.length, ch = (a.b - gy) / rows.length;
    const pal = PALETTES[o.palette] || PALETTES.aurora;
    const cLo = pal[0], cHi = pal[2] || pal[1];
    const fmt = makeFmt(o.valueFormat);
    const e = this._drawAnim != null ? this._drawAnim : 1;
    // column headers
    ctx.font = '10.5px var(--font-ui)'; ctx.fillStyle = this._theme.inkSoft; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    cols.forEach((c, ci) => ctx.fillText(clip(ctx, String(c), cw - 2), gx + cw * (ci + 0.5), a.t + headH / 2));
    // rows
    ctx.textAlign = 'right';
    rows.forEach((r, ri) => {
      ctx.fillStyle = this._theme.inkSoft; ctx.textAlign = 'right';
      ctx.fillText(clip(ctx, String(r.label), labelW - 8), gx - 8, gy + ch * (ri + 0.5));
      r.values.forEach((v, ci) => {
        const t = max === min ? 0.5 : (v - min) / (max - min);
        const x = gx + cw * ci, y = gy + ch * ri;
        ctx.globalAlpha = isFinite(v) ? e : 0;
        ctx.fillStyle = isFinite(v) ? lerpColor(cLo, cHi, t) : 'transparent';
        roundRect(ctx, x + 1.5, y + 1.5, cw - 3, ch - 3, 3); ctx.fill();
        ctx.globalAlpha = 1;
        if (isFinite(v) && cw > 30 && ch > 18) {
          ctx.fillStyle = t > 0.55 ? '#0b0e15' : this._theme.ink; ctx.font = '10px var(--font-mono)'; ctx.textAlign = 'center';
          ctx.fillText(compact(v), x + cw / 2, y + ch / 2);
        }
        this.hitmap.push({ rect: [x, y, cw, ch], label: r.label + ' · ' + cols[ci], rows: [{ name: cols[ci], value: v, color: lerpColor(cLo, cHi, t) }] });
      });
    });
  };

  /* ==================================================================
     HISTOGRAM (bins a single numeric column)
     ================================================================== */
  Chart.prototype._drawHistogram = function (a) {
    const ctx = this.ctx, m = this.model, o = this.opts;
    const vals = (m.values || []).filter(isFinite);
    if (vals.length < 2) { this._noData(a, 'Histogram needs a numeric value column'); return; }
    const vmin = Math.min(...vals), vmax = Math.max(...vals);
    const binCount = Math.min(12, Math.max(5, Math.ceil(Math.sqrt(vals.length))));
    const sc = niceScale(vmin, vmax, binCount + 1);
    const step = sc.step;
    const start = sc.min;
    const bins = [];
    for (let x = start; x < sc.max - 1e-9; x += step) bins.push({ lo: x, hi: x + step, count: 0 });
    vals.forEach(v => { let idx = Math.floor((v - start) / step); if (idx >= bins.length) idx = bins.length - 1; if (idx < 0) idx = 0; bins[idx].count++; });
    const maxCount = Math.max(...bins.map(b => b.count), 1);
    const ysc = niceScale(0, maxCount, 5);
    const yToPx = c => a.b - (c / ysc.max) * a.h;
    // y axis (counts)
    ctx.font = '11px var(--font-mono)'; ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
    ysc.ticks.forEach(v => { const y = yToPx(v); if (o.grid !== false) { ctx.strokeStyle = this._theme.gridc; ctx.beginPath(); ctx.moveTo(a.l, y + .5); ctx.lineTo(a.r, y + .5); ctx.stroke(); } ctx.fillStyle = this._theme.inkSoft; ctx.fillText(String(v), a.l - 9, y); });
    const bw = a.w / bins.length;
    const e = this._drawAnim != null ? this._drawAnim : 1;
    const col = this._colorFor(0);
    ctx.font = '10px var(--font-mono)'; ctx.textAlign = 'center'; ctx.textBaseline = 'top';
    bins.forEach((b, i) => {
      const x = a.l + bw * i; const yTop = yToPx(b.count);
      const hgt = (a.b - yTop) * e;
      ctx.fillStyle = rgba(col, 0.85);
      roundRect(ctx, x + 1, a.b - hgt, bw - 2, hgt, 3); ctx.fill();
      ctx.fillStyle = this._theme.inkSoft;
      if (i % Math.ceil(bins.length / 8) === 0) ctx.fillText(compact(b.lo), x, a.b + 8);
      this.hitmap.push({ rect: [x, yTop, bw, a.b - yTop], label: `${compact(b.lo)} – ${compact(b.hi)}`, rows: [{ name: 'count', value: b.count, color: col }] });
    });
    if (o.xLabel) { ctx.font = '600 11px var(--font-ui)'; ctx.fillStyle = this._theme.inkSoft; ctx.fillText(o.xLabel, (a.l + a.r) / 2, a.b + 24); }
  };

  Chart.prototype._noData = function (a, msg) {
    const ctx = this.ctx; ctx.fillStyle = this._theme.inkSoft; ctx.font = '13px var(--font-ui)';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(msg, (a.l + a.r) / 2, (a.t + a.b) / 2);
  };

  /* ==================================================================
     INTERACTION: hover tooltip + legend click toggle
     ================================================================== */
  Chart.prototype._bindEvents = function () {
    const cv = this.canvas;
    cv.addEventListener('mousemove', (ev) => this._onMove(ev));
    cv.addEventListener('mouseleave', () => this._hideTip());
    cv.addEventListener('click', (ev) => this._onClick(ev));
    cv.style.cursor = 'default';
  };

  Chart.prototype._localPt = function (ev) {
    const r = this.canvas.getBoundingClientRect();
    return { x: ev.clientX - r.left, y: ev.clientY - r.top, cx: ev.clientX, cy: ev.clientY };
  };

  Chart.prototype._hitTest = function (px, py) {
    // prefer precise hits (points/bars/wedges) before soft column zones
    let soft = null;
    for (const h of this.hitmap) {
      if (h.r != null && h.x != null) { if ((px - h.x) ** 2 + (py - h.y) ** 2 <= h.r * h.r) return h; }
      else if (h.wedge) {
        const w = h.wedge; const dx = px - w.cx, dy = py - w.cy; const d = Math.hypot(dx, dy);
        if (d <= w.R && d >= w.rin) { let ang = Math.atan2(dy, dx); let a0 = w.a0, a1 = w.a1;
          // normalize
          const norm = x => { while (x < -Math.PI / 2) x += Math.PI * 2; while (x > Math.PI * 1.5) x -= Math.PI * 2; return x; };
          ang = norm(ang); if (ang >= norm(a0) - 1e-6 && ang <= norm(a1) + 1e-6) return h; }
      } else if (h.rect) {
        const [x, y, w, hh] = h.rect;
        if (px >= x && px <= x + w && py >= y && py <= y + hh) { if (h.soft) { soft = soft || h; } else return h; }
      }
    }
    return soft;
  };

  Chart.prototype._onMove = function (ev) {
    const p = this._localPt(ev);
    // legend hover -> pointer
    const overLegend = this.legendHit.some(l => p.x >= l.x && p.x <= l.x + l.w && p.y >= l.y && p.y <= l.y + l.h);
    const hit = this._hitTest(p.x, p.y);
    this.canvas.style.cursor = overLegend ? 'pointer' : (hit ? 'crosshair' : 'default');
    if (!hit || !this.tooltip) { this._hideTip(); return; }
    const fmt = makeFmt(this.opts.valueFormat);
    let html = `<div class="tt-key">${esc(hit.label)}</div>`;
    hit.rows.forEach(r => {
      const val = r.raw ? r.value : (r.pct != null ? `${fmt(r.value)} (${r.pct.toFixed(1)}%)` : fmt(r.value));
      html += `<div class="tt-row"><i style="background:${r.color}"></i>${esc(r.name)}<b>${esc(String(val))}</b></div>`;
    });
    this.tooltip.innerHTML = html;
    this.tooltip.style.opacity = '1';
    const tw = this.tooltip.offsetWidth, th = this.tooltip.offsetHeight;
    let tx = p.cx + 14, ty = p.cy + 14;
    if (tx + tw > window.innerWidth - 10) tx = p.cx - tw - 14;
    if (ty + th > window.innerHeight - 10) ty = p.cy - th - 14;
    this.tooltip.style.left = tx + 'px'; this.tooltip.style.top = ty + 'px';
  };

  Chart.prototype._hideTip = function () { if (this.tooltip) this.tooltip.style.opacity = '0'; };

  Chart.prototype._onClick = function (ev) {
    const p = this._localPt(ev);
    for (const l of this.legendHit) {
      if (p.x >= l.x && p.x <= l.x + l.w && p.y >= l.y && p.y <= l.y + l.h) {
        this.hidden[l.idx] = !this.hidden[l.idx];
        this.redraw();
        return;
      }
    }
  };

  /* ---- export helpers ---- */
  Chart.prototype.toPNG = function () { return this.canvas.toDataURL('image/png'); };

  /* ==================================================================
     shared canvas helpers
     ================================================================== */
  function roundRect(ctx, x, y, w, h, r) {
    if (w < 0) { x += w; w = -w; } if (h < 0) { y += h; h = -h; }
    r = Math.max(0, Math.min(r, w / 2, h / 2));
    ctx.beginPath();
    ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }
  function clip(ctx, text, maxW) {
    if (ctx.measureText(text).width <= maxW) return text;
    let t = text;
    while (t.length > 1 && ctx.measureText(t + '…').width > maxW) t = t.slice(0, -1);
    return t + '…';
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

  PW.Chart = Chart;
  PW.fmtUtil = { makeFmt, niceScale, compact, trim, PALETTES };
})();
