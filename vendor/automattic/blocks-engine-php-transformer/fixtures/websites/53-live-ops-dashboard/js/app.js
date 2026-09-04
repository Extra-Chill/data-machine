/* =========================================================
   HELIX GRID — Dashboard page wiring (index.html)
   Connects the engine to KPI tiles, charts, status grid,
   region bars, gauges, and the streaming log feed.
   ========================================================= */
(function () {
  'use strict';
  const E = window.HelixEngine;
  const C = window.HelixCharts;
  if (!E || !document.getElementById('dashboard')) return;
  const prefs = window.HelixShell ? window.HelixShell.prefs : { window: 60, region: 'all', logFilters: { crit: true, warn: true, info: true, ok: true } };

  /* ---- time-window filter (how many points to show) ---- */
  let windowSize = prefs.window || 60;
  function slice(arr) { return arr.slice(Math.max(0, arr.length - windowSize)); }

  /* ============== KPI TILES ============== */
  const kpiDefs = [
    { id: 'demand', label: 'Grid Demand', unit: 'GW', color: '--accent-2', series: 'demand', scale: 1 / 1000, dec: 2, invert: false },
    { id: 'freq',   label: 'Frequency',   unit: 'Hz', color: '--accent',   series: 'freq',   scale: 1, dec: 3, invert: false, center: 60 },
    { id: 'price',  label: 'Clearing $',  unit: '/MWh', color: '--warn',   series: 'price',  scale: 1, dec: 2, invert: true },
    { id: 'latency',label: 'API p50',     unit: 'ms', color: '--accent-3', series: 'latency',scale: 1, dec: 0, invert: true },
    { id: 'errors', label: 'Error Rate',  unit: '%',  color: '--crit',     series: 'errors', scale: 1, dec: 2, invert: true },
    { id: 'co2',    label: 'Grid CO₂',    unit: 'g/kWh', color: '--ok',    series: 'co2',    scale: 1, dec: 0, invert: true },
  ];
  const kpiGrid = document.getElementById('kpiGrid');
  const kpiEls = {};
  kpiDefs.forEach(k => {
    const el = document.createElement('div');
    el.className = 'kpi'; el.style.setProperty('--k-color', `var(${k.color})`);
    el.innerHTML = `
      <div class="kpi-head">
        <span class="kpi-label">${k.label}</span>
        <svg class="kpi-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 17l6-6 4 4 7-7"/><path d="M14 7h6v6"/></svg>
      </div>
      <div class="kpi-value tabnum"><span class="kv">–</span><span class="unit">${k.unit}</span></div>
      <div class="kpi-foot">
        <span class="kpi-delta flat"><span class="kd-arrow">→</span><span class="kd-val">0.0%</span></span>
        <svg class="kpi-spark" viewBox="0 0 84 26" preserveAspectRatio="none">
          <path class="sp-fill" fill="var(${k.color})" fill-opacity="0.12" d=""/>
          <path class="sp-line" fill="none" stroke="var(${k.color})" stroke-width="1.5" d=""/>
        </svg>
      </div>`;
    kpiGrid.appendChild(el);
    kpiEls[k.id] = el;
  });

  function updateKPIs() {
    kpiDefs.forEach(k => {
      const data = E.series(k.series);
      if (data.length < 2) return;
      const win = slice(data);
      const cur = data[data.length - 1].v * k.scale;
      const prev = win[0].v * k.scale;
      const el = kpiEls[k.id];
      el.querySelector('.kv').textContent = cur.toFixed(k.dec);
      // delta
      let delta = prev !== 0 ? (cur - prev) / Math.abs(prev) * 100 : 0;
      if (k.center != null) delta = (cur - k.center) / k.center * 100;
      const d = el.querySelector('.kpi-delta');
      const up = delta > 0.05, down = delta < -0.05;
      d.className = 'kpi-delta ' + (up ? 'up' : down ? 'down' : 'flat');
      // for "invert" metrics (lower is better) flip color meaning
      if (k.invert && (up || down)) d.className = 'kpi-delta ' + (up ? 'down' : 'up');
      d.querySelector('.kd-arrow').textContent = up ? '▲' : down ? '▼' : '→';
      d.querySelector('.kd-val').textContent = (delta >= 0 ? '+' : '') + delta.toFixed(2) + '%';
      // sparkline
      const vals = win.map(p => p.v);
      el.querySelector('.sp-line').setAttribute('d', C.sparkPath(vals, 84, 26));
      const fp = C.sparkPath(vals, 84, 26);
      el.querySelector('.sp-fill').setAttribute('d', fp ? fp + ' L84 26 L0 26 Z' : '');
    });
  }

  /* ============== CHARTS ============== */
  const demandChart = C.lineChart(document.getElementById('demandChart'), { decimals: 0, fmt: v => C.fmt(v / 1000, 1) + 'GW' });
  const freqChart = C.lineChart(document.getElementById('freqChart'), { min: 59.85, max: 60.15, decimals: 2, threshold: 59.95 });
  const latChart = C.lineChart(document.getElementById('latChart'), { decimals: 0, threshold: 250, fmt: v => C.fmt(v, 0) + 'ms' });
  const mixChart = C.stackedArea(document.getElementById('mixChart'), E.SOURCES);
  const freqGauge = C.gauge(document.getElementById('freqGauge'), {
    min: 59.5, max: 60.5, decimals: 2, unit: 'Hz · nominal 60.00',
    colorFor: v => Math.abs(v - 60) < 0.05 ? C.css('--ok') : Math.abs(v - 60) < 0.12 ? C.css('--warn') : C.css('--crit'),
  });
  const reserveGauge = C.gauge(document.getElementById('reserveGauge'), {
    min: 0, max: 100, decimals: 0, unit: '% spinning reserve',
    colorFor: v => v > 25 ? C.css('--ok') : v > 12 ? C.css('--warn') : C.css('--crit'),
  });
  const loadGauge = C.gauge(document.getElementById('loadGauge'), {
    min: 0, max: 100, decimals: 0, unit: '% of capacity',
    colorFor: v => v < 80 ? C.css('--ok') : v < 92 ? C.css('--warn') : C.css('--crit'),
  });

  // generation-mix history buffers
  const mixHist = {}; E.SOURCES.forEach(s => mixHist[s.id] = []);
  E.on('tick', () => {
    E.SOURCES.forEach(s => {
      mixHist[s.id].push(E.state.sources[s.id]);
      if (mixHist[s.id].length > 80) mixHist[s.id].shift();
    });
  });

  function drawCharts() {
    // region filter affects demand chart label only here; data is grid-wide
    demandChart.draw([
      { data: slice(E.series('demand')), color: C.css('--accent-2'), fill: true, width: 2 },
      { data: slice(E.series('supply')), color: C.css('--accent'), width: 1.6 },
    ]);
    freqChart.draw([{ data: slice(E.series('freq')), color: C.css('--accent'), fill: true, width: 1.8 }]);
    latChart.draw([
      { data: slice(E.series('latency')), color: C.css('--accent-3'), fill: true, width: 1.8 },
    ]);
    mixChart.draw(mixHist);
    // gauges
    freqGauge.draw(E.state.grid.freq);
    const totalCap = E.REGIONS.reduce((a, r) => a + r.cap, 0);
    const reservePct = Math.max(0, (E.state.grid.supply - E.state.grid.demand) / totalCap * 100 + 18);
    reserveGauge.draw(Math.min(100, reservePct));
    loadGauge.draw(Math.min(100, E.state.grid.demand / totalCap * 100));
  }

  /* ============== STATUS GRID (heatmap) ============== */
  // a grid of substations; color = stress level
  const STATIONS = [];
  E.REGIONS.forEach(r => { for (let i = 0; i < 24; i++) STATIONS.push({ region: r.id, name: r.name, idx: i }); });
  const sg = document.getElementById('statusGrid');
  const cells = STATIONS.map((st, i) => {
    const c = document.createElement('div');
    c.className = 'cell'; c.dataset.region = st.region;
    c.dataset.label = `${st.name} substation ${String(st.idx + 1).padStart(2, '0')}`;
    sg.appendChild(c); return c;
  });
  function heatColor(v) { // 0..1
    const stops = [[30, 92, 79], [65, 214, 138], [255, 192, 77], [255, 93, 108]];
    const seg = Math.min(2.999, v * 3); const i = seg | 0; const t = seg - i;
    const a = stops[i], b = stops[i + 1];
    const rr = a[0] + (b[0] - a[0]) * t | 0, gg = a[1] + (b[1] - a[1]) * t | 0, bb = a[2] + (b[2] - a[2]) * t | 0;
    return `rgb(${rr},${gg},${bb})`;
  }
  function updateStatusGrid() {
    const inc = E.state.activeIncident;
    STATIONS.forEach((st, i) => {
      const rs = E.state.regions[st.region];
      // per-station pseudo stress = region stress + per-cell wobble
      let v = rs.stress + Math.sin(E.state.tick / 10 + i * 1.7) * 0.12 + (Math.random() - 0.5) * 0.05;
      if (inc && inc.region.id === st.region && (i % 24) < 8) v += 0.35;
      v = Math.max(0, Math.min(1, v));
      cells[i].style.setProperty('--c-color', heatColor(v));
      cells[i].dataset.stress = (v * 100).toFixed(0);
    });
  }

  /* ============== REGION BARS ============== */
  const barsEl = document.getElementById('regionBars');
  const barEls = {};
  E.REGIONS.forEach(r => {
    const row = document.createElement('div'); row.className = 'bar-row';
    row.innerHTML = `<span class="bar-name">${r.name}</span>
      <div class="bar-track"><div class="bar-fill" style="background:linear-gradient(90deg, ${r.color}, ${r.color}88)"></div></div>
      <span class="bar-val">–</span>`;
    barsEl.appendChild(row);
    barEls[r.id] = { fill: row.querySelector('.bar-fill'), val: row.querySelector('.bar-val'), row };
  });
  function updateBars() {
    E.REGIONS.forEach(r => {
      const rs = E.state.regions[r.id];
      const pct = Math.min(100, rs.load / r.cap * 100);
      barEls[r.id].fill.style.width = pct + '%';
      barEls[r.id].val.textContent = (rs.load / 1000).toFixed(2) + 'GW';
      barEls[r.id].row.style.opacity = (prefs.region === 'all' || prefs.region === r.id) ? '1' : '0.32';
    });
  }

  /* ============== LIVE LOG FEED ============== */
  const logEl = document.getElementById('logFeed');
  const MAXROWS = 120;
  function severityVisible(sev) { return prefs.logFilters[sev]; }
  let searchTerm = '';
  function fmtTime(t) {
    const d = new Date(t);
    return [d.getUTCHours(), d.getUTCMinutes(), d.getUTCSeconds()].map(n => String(n).padStart(2, '0')).join(':');
  }
  function addLog(entry) {
    const row = document.createElement('div');
    row.className = 'log-row'; row.dataset.sev = entry.sev; row.dataset.svc = entry.svc;
    row.innerHTML = `<span class="lt">${fmtTime(entry.t)}</span>
      <span class="lsev">${entry.sev.toUpperCase()}</span>
      <span class="lsvc">${entry.svc}</span>
      <span class="lmsg" title="${entry.msg.replace(/"/g, '&quot;')}">${entry.msg}</span>`;
    applyLogFilter(row);
    logEl.insertBefore(row, logEl.firstChild);
    while (logEl.children.length > MAXROWS) logEl.removeChild(logEl.lastChild);
  }
  function applyLogFilter(row) {
    const ok = severityVisible(row.dataset.sev) &&
      (!searchTerm || (row.dataset.svc + ' ' + row.querySelector('.lmsg').textContent).toLowerCase().includes(searchTerm));
    row.classList.toggle('hidden', !ok);
  }
  function refilterLogs() { logEl.querySelectorAll('.log-row').forEach(applyLogFilter); }

  // seed log with recent buffer
  E.state.logBuffer.slice(-40).forEach(addLog);
  E.on('log', e => { if (!E.isPaused() || true) addLog(e); });

  // log filter chips
  document.querySelectorAll('#logChips .chip').forEach(chip => {
    const sev = chip.dataset.sev;
    chip.classList.toggle('active', prefs.logFilters[sev]);
    chip.addEventListener('click', () => {
      prefs.logFilters[sev] = !prefs.logFilters[sev];
      chip.classList.toggle('active', prefs.logFilters[sev]);
      window.HelixShell.savePrefs(prefs); refilterLogs();
    });
  });
  const search = document.getElementById('logSearch');
  if (search) search.addEventListener('input', () => { searchTerm = search.value.toLowerCase().trim(); refilterLogs(); });

  /* ============== CONTROL BAR (window + region) ============== */
  document.querySelectorAll('#windowSeg button').forEach(b => {
    b.classList.toggle('active', parseInt(b.dataset.win) === windowSize);
    b.addEventListener('click', () => {
      windowSize = parseInt(b.dataset.win);
      document.querySelectorAll('#windowSeg button').forEach(x => x.classList.toggle('active', x === b));
      prefs.window = windowSize; window.HelixShell.savePrefs(prefs);
    });
  });
  const regionSel = document.getElementById('regionSelect');
  if (regionSel) {
    regionSel.value = prefs.region;
    regionSel.addEventListener('change', () => {
      prefs.region = regionSel.value; window.HelixShell.savePrefs(prefs); updateBars(); updateStatusGrid();
    });
  }

  /* ============== status grid tooltip ============== */
  const tip = document.getElementById('tip');
  sg.addEventListener('mousemove', e => {
    const c = e.target.closest('.cell'); if (!c) { tip.classList.remove('show'); return; }
    tip.classList.add('show');
    tip.textContent = `${c.dataset.label} · stress ${c.dataset.stress}%`;
    tip.style.left = Math.min(window.innerWidth - 240, e.clientX + 12) + 'px';
    tip.style.top = (e.clientY + 14) + 'px';
  });
  sg.addEventListener('mouseleave', () => tip.classList.remove('show'));

  /* ============== incident flash on KPIs ============== */
  E.on('incident:start', () => {
    if (C.reduceMotion) return;
    ['errors', 'latency', 'price'].forEach(id => {
      const el = kpiEls[id]; if (el) { el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash'); }
    });
  });

  /* ============== MAIN RENDER LOOP ============== */
  // KPIs/bars/grid update on tick (1/sec sim); charts redraw every frame for smoothness
  E.on('tick', () => { updateKPIs(); updateBars(); updateStatusGrid(); });
  let lastDraw = 0;
  E.on('frame', ({ now }) => {
    if (now - lastDraw < (C.reduceMotion ? 500 : 60)) return;
    lastDraw = now; drawCharts();
  });

  // initial paint
  updateKPIs(); updateBars(); updateStatusGrid(); drawCharts();
  window.addEventListener('resize', drawCharts);
})();
