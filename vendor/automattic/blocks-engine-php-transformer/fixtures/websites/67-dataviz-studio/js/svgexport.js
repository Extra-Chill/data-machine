/* =========================================================
   PLOTWEAVER — Standalone SVG export
   Re-renders the current model to a self-contained <svg> string
   for the most common chart types (bar/line/area/pie/donut/scatter).
   Falls back to embedding the canvas PNG for radar/heatmap/histogram
   so EVERY chart type can still be exported as a valid SVG file.
   Exposes window.PW.toSVG(model, opts, chartInstance) -> string
   ========================================================= */
(function () {
  'use strict';
  const PW = (window.PW = window.PW || {});
  const { niceScale, makeFmt, compact } = PW.fmtUtil;
  const PAL = PW.PALETTES;

  const W = 880, H = 550;

  function esc(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  function color(opts, i) { const p = PAL[opts.palette] || PAL.aurora; return p[i % p.length]; }

  function frame(inner, bg, ink) {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" font-family="Inter, system-ui, sans-serif">` +
      `<rect width="${W}" height="${H}" fill="${bg}"/>${inner}</svg>`;
  }

  function header(opts, ink, soft) {
    let s = '', y = 20;
    if (opts.title) { s += `<text x="24" y="${y + 14}" font-size="17" font-weight="600" fill="${ink}">${esc(opts.title)}</text>`; }
    return s;
  }

  function legend(opts, items, ink) {
    if (opts.legendPos === 'none' || !items.length) return '';
    let x = 24, y = opts.title ? 50 : 26, s = '';
    items.forEach(it => {
      s += `<rect x="${x}" y="${y - 9}" width="12" height="12" rx="3" fill="${it.color}"/>` +
        `<text x="${x + 18}" y="${y}" font-size="12" fill="${ink}" dominant-baseline="middle">${esc(it.name)}</text>`;
      x += 26 + it.name.length * 7;
    });
    return s;
  }

  function cartesian(model, opts, theme, type) {
    const { ink, soft, grid } = theme;
    const labels = model.labels || [];
    const series = model.series || [];
    const stacked = opts.stacked && (type === 'bar' || type === 'area');
    let min = 0, max = 0;
    if (stacked) labels.forEach((_, li) => { let sp = 0, sn = 0; series.forEach(s => { const v = s.values[li]; v >= 0 ? sp += v : sn += v; }); max = Math.max(max, sp); min = Math.min(min, sn); });
    else series.forEach(s => s.values.forEach(v => { if (isFinite(v)) { max = Math.max(max, v); min = Math.min(min, v); } }));
    if (max === min) max = min + 1;
    const sc = niceScale(min, max, 6);
    const top = opts.title ? 70 : 46, padL = 62, padR = 22, padB = 46;
    const A = { l: padL, t: top, r: W - padR, b: H - padB };
    A.w = A.r - A.l; A.h = A.b - A.t;
    const yPx = v => A.b - ((v - sc.min) / (sc.max - sc.min)) * A.h;
    const fmt = makeFmt(opts.valueFormat);
    let s = '';
    // grid + y ticks
    sc.ticks.forEach(v => {
      const y = yPx(v);
      if (opts.grid !== false) s += `<line x1="${A.l}" y1="${y}" x2="${A.r}" y2="${y}" stroke="${grid}"/>`;
      s += `<text x="${A.l - 9}" y="${y}" font-size="11" fill="${soft}" text-anchor="end" dominant-baseline="middle">${esc(fmt(v))}</text>`;
    });
    const band = A.w / Math.max(1, labels.length);
    const xc = i => A.l + band * (i + 0.5);
    const skip = Math.ceil((labels.length * 60) / Math.max(1, A.w));
    labels.forEach((lab, i) => { if (i % skip) return; s += `<text x="${xc(i)}" y="${A.b + 18}" font-size="11" fill="${soft}" text-anchor="middle">${esc(String(lab))}</text>`; });

    if (type === 'bar') {
      const groupW = band * 0.72, barW = stacked ? groupW : groupW / Math.max(1, series.length);
      labels.forEach((lab, li) => {
        let sp = 0, sn = 0;
        series.forEach((se, si) => {
          const v = se.values[li]; if (!isFinite(v)) return;
          let x, y0, y1;
          if (stacked) { const base = v >= 0 ? sp : sn; const tp = base + v; x = xc(li) - barW / 2; y0 = yPx(base); y1 = yPx(tp); v >= 0 ? sp = tp : sn = tp; }
          else { x = xc(li) - groupW / 2 + si * barW; y0 = yPx(Math.max(sc.min, 0)); y1 = yPx(v); }
          s += `<rect x="${(x + (stacked ? 0 : 1)).toFixed(1)}" y="${Math.min(y0, y1).toFixed(1)}" width="${(barW - (stacked ? 0 : 2)).toFixed(1)}" height="${Math.abs(y0 - y1).toFixed(1)}" rx="3" fill="${color(opts, si)}"/>`;
        });
      });
    } else {
      const areaMode = type === 'area';
      const stackTop = labels.map(() => 0);
      series.forEach((se, si) => {
        const pts = labels.map((lab, li) => { let v = se.values[li]; let pv = v; if (stacked && areaMode) { pv = stackTop[li] + (isFinite(v) ? v : 0); stackTop[li] = pv; } return [xc(li), yPx(isFinite(pv) ? pv : sc.min)]; });
        const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
        if (areaMode) {
          const base = stacked ? labels.map((l, li) => [xc(li), yPx(stackTop[li] - (isFinite(se.values[li]) ? se.values[li] : 0))]).reverse() : [[pts[pts.length - 1][0], yPx(Math.max(sc.min, 0))], [pts[0][0], yPx(Math.max(sc.min, 0))]];
          const fill = line + ' ' + base.map(p => 'L' + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ') + ' Z';
          s += `<path d="${fill}" fill="${color(opts, si)}" fill-opacity="0.22"/>`;
        }
        s += `<path d="${line}" fill="none" stroke="${color(opts, si)}" stroke-width="2.4" stroke-linejoin="round"/>`;
        pts.forEach(p => { s += `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="3.2" fill="${theme.panel}" stroke="${color(opts, si)}" stroke-width="2"/>`; });
      });
    }
    return s;
  }

  function pie(model, opts, theme, donut) {
    const cx = W / 2, cy = (opts.title ? 70 : 46) + (H - (opts.title ? 70 : 46)) / 2 - 10;
    const R = Math.min(W, H - 120) / 2 - 20, rin = donut ? R * 0.56 : 0;
    const vals = model.values.map((v, i) => ({ v: Math.max(0, v || 0), i })).filter(d => d.v > 0);
    const total = vals.reduce((a, d) => a + d.v, 0) || 1;
    let ang = -Math.PI / 2, s = '';
    vals.forEach(d => {
      const slice = d.v / total * Math.PI * 2; const a1 = ang + slice;
      const x0 = cx + Math.cos(ang) * R, y0 = cy + Math.sin(ang) * R;
      const x1 = cx + Math.cos(a1) * R, y1 = cy + Math.sin(a1) * R;
      const large = slice > Math.PI ? 1 : 0;
      let path;
      if (donut) {
        const ix0 = cx + Math.cos(a1) * rin, iy0 = cy + Math.sin(a1) * rin;
        const ix1 = cx + Math.cos(ang) * rin, iy1 = cy + Math.sin(ang) * rin;
        path = `M${x0} ${y0} A${R} ${R} 0 ${large} 1 ${x1} ${y1} L${ix0} ${iy0} A${rin} ${rin} 0 ${large} 0 ${ix1} ${iy1} Z`;
      } else {
        path = `M${cx} ${cy} L${x0} ${y0} A${R} ${R} 0 ${large} 1 ${x1} ${y1} Z`;
      }
      s += `<path d="${path}" fill="${color(opts, d.i)}" stroke="${theme.panel}" stroke-width="2"/>`;
      ang = a1;
    });
    return s;
  }

  function scatter(model, opts, theme) {
    const pts = model.points || [];
    let xmin = Infinity, xmax = -Infinity, ymin = Infinity, ymax = -Infinity;
    pts.forEach(p => { xmin = Math.min(xmin, p.x); xmax = Math.max(xmax, p.x); ymin = Math.min(ymin, p.y); ymax = Math.max(ymax, p.y); });
    const sx = niceScale(xmin, xmax, 6), sy = niceScale(ymin, ymax, 6);
    const top = opts.title ? 70 : 46;
    const A = { l: 62, t: top, r: W - 22, b: H - 46 }; A.w = A.r - A.l; A.h = A.b - A.t;
    const xp = v => A.l + (v - sx.min) / (sx.max - sx.min) * A.w;
    const yp = v => A.b - (v - sy.min) / (sy.max - sy.min) * A.h;
    let s = '';
    sy.ticks.forEach(v => { const y = yp(v); s += `<line x1="${A.l}" y1="${y}" x2="${A.r}" y2="${y}" stroke="${theme.grid}"/><text x="${A.l - 9}" y="${y}" font-size="11" fill="${theme.soft}" text-anchor="end" dominant-baseline="middle">${PW.fmtUtil.trim(v)}</text>`; });
    sx.ticks.forEach(v => { const x = xp(v); s += `<text x="${x}" y="${A.b + 18}" font-size="11" fill="${theme.soft}" text-anchor="middle">${PW.fmtUtil.trim(v)}</text>`; });
    pts.forEach(p => { s += `<circle cx="${xp(p.x).toFixed(1)}" cy="${yp(p.y).toFixed(1)}" r="5" fill="${color(opts, p.group || 0)}" fill-opacity="0.78" stroke="${color(opts, p.group || 0)}"/>`; });
    return s;
  }

  PW.toSVG = function (model, opts, chartInstance) {
    const cs = getComputedStyle(document.body);
    const get = (n, f) => cs.getPropertyValue(n).trim() || f;
    const theme = { bg: get('--panel', '#11161f'), ink: get('--ink', '#e9eefb'), soft: get('--ink-faint', '#62718d'), grid: get('--grid', 'rgba(140,170,220,.1)'), panel: get('--panel', '#11161f') };
    const t = model.type;
    let inner = header(opts, theme.ink, theme.soft);
    let legendItems = [];
    if (t === 'pie' || t === 'donut') legendItems = (model.labels || []).map((l, i) => ({ name: l, color: color(opts, i) }));
    else if (model.series) legendItems = model.series.map((sr, i) => ({ name: sr.name, color: color(opts, i) }));

    if (t === 'bar' || t === 'line' || t === 'area') inner += cartesian(model, opts, theme, t);
    else if (t === 'pie' || t === 'donut') inner += pie(model, opts, theme, t === 'donut');
    else if (t === 'scatter') inner += scatter(model, opts, theme);
    else if (chartInstance) {
      // fall back to embedding the canvas raster for complex types
      const png = chartInstance.toPNG();
      inner += `<image x="0" y="${opts.title ? 36 : 14}" width="${W}" height="${H - (opts.title ? 36 : 14)}" preserveAspectRatio="xMidYMid meet" href="${png}"/>`;
      return frame(inner, theme.bg, theme.ink);
    }
    inner += legend(opts, legendItems, theme.ink);
    return frame(inner, theme.bg, theme.ink);
  };
})();
