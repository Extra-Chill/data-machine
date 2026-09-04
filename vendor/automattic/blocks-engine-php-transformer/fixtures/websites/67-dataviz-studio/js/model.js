/* =========================================================
   PLOTWEAVER — View-model builder
   Maps a Dataset + config into the structure the Chart renderer
   expects (labels/series, points, values, heatmap rows, etc.).
   Exposes window.PW.buildModel(dataset, config)
   ========================================================= */
(function () {
  'use strict';
  const PW = (window.PW = window.PW || {});
  const num = PW.Data.parseNumber;
  const parseDate = PW.Data.parseDate;

  function colIndex(ds, name) { return ds.columns.findIndex(c => c.name === name); }

  // sort row indices by x column (value or date or natural)
  function sortedRowOrder(ds, xi, mode) {
    const order = ds.rows.map((_, i) => i);
    if (mode === 'none' || xi < 0) return order;
    const type = ds.columns[xi] ? ds.columns[xi].type : 'str';
    const key = i => {
      const v = ds.rows[i][xi];
      if (type === 'num') return num(v);
      if (type === 'date') return parseDate(v);
      return String(v).toLowerCase();
    };
    order.sort((a, b) => { const ka = key(a), kb = key(b); return ka < kb ? -1 : ka > kb ? 1 : 0; });
    if (mode === 'desc') order.reverse();
    return order;
  }

  PW.buildModel = function (ds, cfg) {
    const type = cfg.type;
    if (!ds || !ds.columns.length) return { type, labels: [], series: [] };
    const numericCols = ds.columns.map((c, i) => i).filter(i => ds.columns[i].type === 'num');

    // ---------- SCATTER ----------
    if (type === 'scatter') {
      const xi = colIndex(ds, cfg.x) >= 0 ? colIndex(ds, cfg.x) : numericCols[0];
      const yi = colIndex(ds, cfg.series[0]) >= 0 ? colIndex(ds, cfg.series[0]) : numericCols[1];
      const gi = colIndex(ds, cfg.group); // optional grouping column
      const groups = [];
      const points = [];
      ds.rows.forEach((r, ri) => {
        const x = num(r[xi]), y = num(r[yi]);
        if (!isFinite(x) || !isFinite(y)) return;
        let g = 0;
        if (gi >= 0) { const gv = String(r[gi]); g = groups.indexOf(gv); if (g < 0) { g = groups.length; groups.push(gv); } }
        points.push({ x, y, group: g, label: `Row ${ri + 1}` });
      });
      return { type, points, groups: groups.length ? groups : [ds.columns[yi] ? ds.columns[yi].name : 'y'], xName: ds.columns[xi] && ds.columns[xi].name, yName: ds.columns[yi] && ds.columns[yi].name };
    }

    // ---------- HISTOGRAM ----------
    if (type === 'histogram') {
      const vi = colIndex(ds, cfg.series[0]) >= 0 ? colIndex(ds, cfg.series[0]) : numericCols[0];
      const values = ds.rows.map(r => num(r[vi])).filter(isFinite);
      return { type, values, labels: [ds.columns[vi] ? ds.columns[vi].name : 'value'] };
    }

    // ---------- PIE / DONUT ----------
    if (type === 'pie' || type === 'donut') {
      const xi = colIndex(ds, cfg.x);
      const vi = colIndex(ds, cfg.series[0]) >= 0 ? colIndex(ds, cfg.series[0]) : numericCols[0];
      const order = sortedRowOrder(ds, cfg.sort !== 'none' ? vi : xi, cfg.sort);
      const labels = [], values = [];
      order.forEach(ri => { labels.push(xi >= 0 ? String(ds.rows[ri][xi]) : 'Row ' + (ri + 1)); values.push(num(ds.rows[ri][vi]) || 0); });
      return { type, labels, values };
    }

    // ---------- HEATMAP ----------
    if (type === 'heatmap') {
      const xi = colIndex(ds, cfg.x);
      const cols = (cfg.series.length ? cfg.series : ds.columns.filter(c => c.type === 'num').map(c => c.name));
      const colIdx = cols.map(n => colIndex(ds, n)).filter(i => i >= 0);
      const order = sortedRowOrder(ds, xi, cfg.sort);
      const rowsHM = order.map(ri => ({
        label: xi >= 0 ? String(ds.rows[ri][xi]) : 'Row ' + (ri + 1),
        values: colIdx.map(ci => num(ds.rows[ri][ci])),
      }));
      return { type, rowsHM, cols: colIdx.map(ci => ds.columns[ci].name) };
    }

    // ---------- RADAR ----------
    if (type === 'radar') {
      // labels = x column (spokes/attributes), series = chosen numeric cols
      const xi = colIndex(ds, cfg.x);
      const sCols = (cfg.series.length ? cfg.series : ds.columns.filter(c => c.type === 'num').map(c => c.name));
      const labels = ds.rows.map((r, i) => xi >= 0 ? String(r[xi]) : 'Axis ' + (i + 1));
      const series = sCols.map(n => { const ci = colIndex(ds, n); return { name: n, values: ds.rows.map(r => num(r[ci]) || 0) }; });
      return { type, labels, series };
    }

    // ---------- CARTESIAN (bar/line/area) ----------
    const xi = colIndex(ds, cfg.x);
    const order = sortedRowOrder(ds, xi, cfg.sort);
    const labels = order.map(ri => xi >= 0 ? String(ds.rows[ri][xi]) : 'Row ' + (ri + 1));
    const sCols = (cfg.series && cfg.series.length) ? cfg.series : ds.columns.filter((c, i) => c.type === 'num' && i !== xi).map(c => c.name);
    const series = sCols.map(n => {
      const ci = colIndex(ds, n);
      return { name: n, values: order.map(ri => num(ds.rows[ri][ci])) };
    });
    return { type, labels, series };
  };

  /* ---------- Default config given a dataset ---------- */
  PW.defaultConfig = function (ds) {
    const cols = ds.columns;
    const firstStr = cols.find(c => c.type !== 'num');
    const numCols = cols.filter(c => c.type === 'num');
    return {
      type: 'bar',
      x: firstStr ? firstStr.name : (cols[0] ? cols[0].name : ''),
      series: numCols.slice(0, 4).map(c => c.name),
      group: '',
      palette: 'aurora',
      title: '',
      xLabel: '',
      yLabel: '',
      legendPos: 'top',
      stacked: false,
      grid: true,
      valueFormat: 'number',
      sort: 'none',
      animate: true,
    };
  };
})();
