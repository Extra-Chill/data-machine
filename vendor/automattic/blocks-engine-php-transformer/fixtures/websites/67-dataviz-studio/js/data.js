/* =========================================================
   PLOTWEAVER — Data layer
   CSV/TSV parsing, type inference, built-in datasets.
   Exposes window.PW.Data
   ========================================================= */
(function () {
  'use strict';
  const PW = (window.PW = window.PW || {});

  /* ------------------------------------------------------------------
     CSV / TSV PARSER
     Handles quoted fields, embedded commas/newlines, escaped quotes,
     and auto-detects delimiter (comma / tab / semicolon).
     ------------------------------------------------------------------ */
  function detectDelimiter(text) {
    const firstLine = text.split(/\r?\n/, 1)[0] || '';
    const counts = { ',': 0, '\t': 0, ';': 0 };
    let inQ = false;
    for (const ch of firstLine) {
      if (ch === '"') inQ = !inQ;
      else if (!inQ && counts[ch] !== undefined) counts[ch]++;
    }
    let best = ',', bestN = -1;
    for (const d in counts) if (counts[d] > bestN) { bestN = counts[d]; best = d; }
    return best;
  }

  function parseDelimited(text, delim) {
    text = text.replace(/^﻿/, ''); // strip BOM
    delim = delim || detectDelimiter(text);
    const rows = [];
    let field = '', row = [], inQ = false, i = 0;
    const n = text.length;
    while (i < n) {
      const ch = text[i];
      if (inQ) {
        if (ch === '"') {
          if (text[i + 1] === '"') { field += '"'; i += 2; continue; }
          inQ = false; i++; continue;
        }
        field += ch; i++; continue;
      }
      if (ch === '"') { inQ = true; i++; continue; }
      if (ch === delim) { row.push(field); field = ''; i++; continue; }
      if (ch === '\n' || ch === '\r') {
        if (ch === '\r' && text[i + 1] === '\n') i++;
        row.push(field); field = '';
        if (row.length > 1 || row[0] !== '') rows.push(row);
        row = []; i++; continue;
      }
      field += ch; i++;
    }
    if (field !== '' || row.length) { row.push(field); rows.push(row); }
    return rows.filter(r => r.some(c => c.trim() !== ''));
  }

  /* ------------------------------------------------------------------
     TYPE INFERENCE
     ------------------------------------------------------------------ */
  const DATE_RE = /^\d{4}-\d{2}(-\d{2})?$|^\d{1,2}\/\d{1,2}\/\d{2,4}$/;
  const NUM_RE = /^-?[$£€]?\s?\d{1,3}(,\d{3})*(\.\d+)?%?$|^-?\d*\.?\d+([eE][-+]?\d+)?%?$/;

  function parseNumber(v) {
    if (typeof v === 'number') return v;
    if (v == null) return NaN;
    const s = String(v).trim().replace(/[$£€,%\s]/g, '');
    if (s === '') return NaN;
    const n = Number(s);
    return Number.isFinite(n) ? n : NaN;
  }

  function inferColumnType(values) {
    let nums = 0, dates = 0, nonEmpty = 0;
    for (const v of values) {
      const s = String(v == null ? '' : v).trim();
      if (s === '') continue;
      nonEmpty++;
      if (DATE_RE.test(s)) dates++;
      else if (NUM_RE.test(s)) nums++;
    }
    if (nonEmpty === 0) return 'str';
    if (dates / nonEmpty >= 0.7) return 'date';
    if (nums / nonEmpty >= 0.7) return 'num';
    return 'str';
  }

  // Parse a yyyy-mm[-dd] or m/d/y into a sortable timestamp.
  function parseDate(v) {
    const s = String(v).trim();
    let m = s.match(/^(\d{4})-(\d{2})(?:-(\d{2}))?$/);
    if (m) return Date.UTC(+m[1], +m[2] - 1, +(m[3] || 1));
    m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/);
    if (m) {
      let y = +m[3]; if (y < 100) y += 2000;
      return Date.UTC(y, +m[1] - 1, +m[2]);
    }
    const t = Date.parse(s);
    return Number.isNaN(t) ? 0 : t;
  }

  /* ------------------------------------------------------------------
     Build a Dataset object from raw rows.
     A dataset = { columns:[{name,type}], rows:[ [cell,...] ] }
     ------------------------------------------------------------------ */
  function fromRows(matrix, hasHeader) {
    if (!matrix.length) return { columns: [], rows: [] };
    const width = Math.max(...matrix.length ? matrix.map(r => r.length) : [0]);
    let header, body;
    if (hasHeader !== false) { header = matrix[0]; body = matrix.slice(1); }
    else { header = []; body = matrix; }
    const columns = [];
    for (let c = 0; c < width; c++) {
      const name = (header[c] != null && String(header[c]).trim()) || ('Column ' + (c + 1));
      const colVals = body.map(r => r[c]);
      columns.push({ name: name, type: inferColumnType(colVals) });
    }
    const rows = body.map(r => {
      const out = [];
      for (let c = 0; c < width; c++) out.push(r[c] != null ? r[c] : '');
      return out;
    });
    return { columns, rows };
  }

  function parseCSV(text, opts) {
    opts = opts || {};
    const matrix = parseDelimited(text, opts.delim);
    return fromRows(matrix, opts.header);
  }

  /* Serialize a dataset back to CSV (for export) */
  function toCSV(ds) {
    const esc = (v) => {
      const s = String(v == null ? '' : v);
      return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    };
    const lines = [ds.columns.map(c => esc(c.name)).join(',')];
    for (const r of ds.rows) lines.push(r.map(esc).join(','));
    return lines.join('\n');
  }

  PW.Data = {
    parseCSV, parseDelimited, fromRows, toCSV,
    inferColumnType, parseNumber, parseDate, detectDelimiter,
  };

  /* ================================================================
     BUILT-IN DATASETS — real-feeling, hand-crafted seed data
     ================================================================ */
  const DATASETS = {
    quarterly_revenue: {
      label: 'SaaS Revenue by Quarter',
      desc: 'ARR ($M) for three product lines across 8 quarters.',
      csv:
`Quarter,Core Platform,Analytics,Automation
2023 Q1,18.4,6.2,2.1
2023 Q2,20.1,7.8,3.0
2023 Q3,22.6,9.1,4.4
2023 Q4,25.3,11.0,6.2
2024 Q1,27.8,13.4,8.1
2024 Q2,30.9,16.2,10.5
2024 Q3,33.1,19.6,13.8
2024 Q4,36.7,23.9,17.4`,
    },

    global_temp: {
      label: 'Global Temperature Anomaly',
      desc: 'Land-ocean temperature anomaly (°C vs 1951–1980) by decade.',
      csv:
`Year,Anomaly,CO2 ppm
1900,-0.16,296
1910,-0.43,300
1920,-0.27,303
1930,-0.16,308
1940,0.04,310
1950,-0.07,311
1960,-0.03,317
1970,0.00,325
1980,0.27,339
1990,0.45,354
2000,0.61,369
2010,0.72,389
2020,1.02,414`,
    },

    fruit_market: {
      label: 'Farmers Market Sales',
      desc: 'Units sold and revenue by produce category — one Saturday.',
      csv:
`Category,Units,Revenue,Avg Price
Heirloom Tomatoes,142,497,3.50
Sweet Corn,310,186,0.60
Strawberries,98,490,5.00
Kale,76,152,2.00
Honey,54,648,12.00
Goat Cheese,41,369,9.00
Sourdough,87,435,5.00
Wildflowers,33,264,8.00`,
    },

    city_population: {
      label: 'Metro Population Growth',
      desc: 'Population (millions) of six metros, 1990–2020.',
      csv:
`City,1990,2000,2010,2020
Austin,0.85,1.25,1.72,2.28
Phoenix,2.24,3.25,4.19,4.85
Denver,1.85,2.18,2.54,2.96
Nashville,0.99,1.23,1.59,1.93
Raleigh,0.86,1.19,1.75,2.04
Boise,0.30,0.46,0.62,0.76`,
    },

    energy_mix: {
      label: 'Electricity Generation Mix',
      desc: 'Annual TWh by source for a regional grid, 2024.',
      csv:
`Source,TWh,Share %,CO2 g/kWh
Natural Gas,412,38,490
Nuclear,228,21,12
Wind,164,15,11
Hydro,109,10,24
Solar,98,9,48
Coal,54,5,820
Other,21,2,230`,
    },

    iris_sample: {
      label: 'Iris Flower Measurements',
      desc: 'Classic measurements (cm) — great for scatter plots.',
      csv:
`Sepal Length,Sepal Width,Petal Length,Species
5.1,3.5,1.4,setosa
4.9,3.0,1.4,setosa
5.4,3.9,1.7,setosa
4.6,3.4,1.4,setosa
7.0,3.2,4.7,versicolor
6.4,3.2,4.5,versicolor
6.9,3.1,4.9,versicolor
5.5,2.3,4.0,versicolor
6.3,3.3,6.0,virginica
7.1,3.0,5.9,virginica
6.5,3.0,5.8,virginica
7.6,3.0,6.6,virginica
4.8,3.4,1.9,setosa
5.7,2.8,4.5,versicolor
6.7,3.3,5.7,virginica`,
    },

    coffee_ratings: {
      label: 'Coffee Origin Cupping Scores',
      desc: 'Sensory scores (0–10) by origin — radar-ready.',
      csv:
`Attribute,Ethiopia,Colombia,Sumatra
Aroma,9.1,7.8,6.9
Acidity,8.7,7.2,4.1
Body,6.4,7.6,9.0
Sweetness,8.2,8.5,7.0
Balance,8.0,8.3,7.4
Aftertaste,8.4,7.7,6.6`,
    },

    rainfall_grid: {
      label: 'Monthly Rainfall by City',
      desc: 'Avg precipitation (mm) — designed for a heatmap.',
      csv:
`City,Jan,Feb,Mar,Apr,May,Jun,Jul,Aug,Sep,Oct,Nov,Dec
Seattle,142,98,93,71,53,40,18,28,41,89,150,140
Phoenix,24,22,28,7,4,1,24,28,21,17,18,23
Miami,41,56,67,73,140,234,166,224,191,143,82,52
Denver,12,13,33,43,55,46,49,42,30,25,22,15
Boston,87,84,103,93,84,87,82,83,93,98,99,99`,
    },
  };

  PW.DATASETS = DATASETS;
})();
