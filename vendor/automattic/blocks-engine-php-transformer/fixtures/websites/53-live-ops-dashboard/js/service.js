/* =========================================================
   HELIX GRID — Service drill-down page (service.html)
   Picks a service (from ?svc= or selector) and renders a
   focused live view: latency, error rate, throughput,
   per-service log, dependency bubble plot, request mix bars.
   ========================================================= */
(function () {
  'use strict';
  const E = window.HelixEngine, C = window.HelixCharts;
  if (!E || !document.getElementById('servicePage')) return;

  const params = new URLSearchParams(location.search);
  let currentId = params.get('svc') || E.SERVICES[1].id;
  if (!E.getService(currentId)) currentId = E.SERVICES[0].id;

  // populate selector
  const sel = document.getElementById('svcSelect');
  E.SERVICES.forEach(s => {
    const o = document.createElement('option'); o.value = s.id;
    o.textContent = s.name + '  ·  ' + E.REGIONS.find(r => r.id === s.region).name;
    sel.appendChild(o);
  });
  sel.value = currentId;
  sel.addEventListener('change', () => {
    currentId = sel.value;
    history.replaceState(null, '', '?svc=' + currentId);
    seed();
    refreshHero();
  });

  /* per-service local history (engine keeps latency hist; we add err/req) */
  const localHist = {};
  E.SERVICES.forEach(s => localHist[s.id] = { lat: [], err: [], req: [] });
  E.on('tick', () => {
    E.SERVICES.forEach(s => {
      const ss = E.getService(s.id), lh = localHist[s.id];
      lh.lat.push(ss.latency); lh.err.push(ss.errRate); lh.req.push(ss.reqs);
      ['lat', 'err', 'req'].forEach(k => { if (lh[k].length > 120) lh[k].shift(); });
    });
  });
  // pre-fill from engine's history
  function seed() {
    const ss = E.getService(currentId);
    localHist[currentId].lat = ss.history.slice(-120);
  }
  E.SERVICES.forEach(s => localHist[s.id].lat = E.getService(s.id).history.slice(-120));

  /* charts */
  const latChart = C.lineChart(document.getElementById('svcLat'), { decimals: 0, threshold: 250, fmt: v => C.fmt(v, 0) + 'ms' });
  const errChart = C.lineChart(document.getElementById('svcErr'), { decimals: 2, threshold: 1, min: 0 });
  const reqChart = C.lineChart(document.getElementById('svcReq'), { decimals: 0, fmt: v => C.fmt(v, 0) });
  const depScatter = C.scatter(document.getElementById('svcScatter'), { xlabel: 'requests/s →   (bubble = error rate)' });
  const mixBars = C.barChart(document.getElementById('svcBars'), {});

  function toSeries(arr) { return arr.map((v, i) => ({ t: i, v })); }

  function draw() {
    const lh = localHist[currentId];
    latChart.draw([{ data: toSeries(lh.lat), color: C.css('--accent-3'), fill: true, width: 2 }]);
    errChart.draw([{ data: toSeries(lh.err), color: C.css('--crit'), fill: true, width: 1.8 }]);
    reqChart.draw([{ data: toSeries(lh.req), color: C.css('--accent-2'), fill: true, width: 1.8 }]);

    // dependency scatter: all services positioned by reqs (x) vs latency (y)
    depScatter.draw(E.SERVICES.map(s => {
      const ss = E.getService(s.id);
      return {
        x: ss.reqs, y: ss.latency, r: 5 + Math.min(22, ss.errRate * 4),
        color: s.id === currentId ? C.css('--accent') : C.css('--accent-2'),
        label: s.name,
      };
    }));

    // request mix: HTTP status buckets for current service (synthetic)
    const ss = E.getService(currentId);
    const total = ss.reqs;
    const err = total * ss.errRate / 100;
    const ok2 = total * (0.78);
    mixBars.draw([
      { label: '2xx', value: ok2, color: C.css('--ok') },
      { label: '3xx', value: total * 0.08, color: C.css('--accent-2') },
      { label: '4xx', value: total * 0.10, color: C.css('--warn') },
      { label: '5xx', value: err, color: C.css('--crit') },
    ]);
  }

  /* hero stats */
  function refreshHero() {
    const svc = E.SERVICES.find(s => s.id === currentId);
    const ss = E.getService(currentId);
    const region = E.REGIONS.find(r => r.id === svc.region);
    document.getElementById('svcName').textContent = svc.name;
    document.getElementById('svcMeta').textContent =
      `${svc.tier.toUpperCase()} tier · ${region.name} · region ${svc.region.toUpperCase()} · v4.${(currentId.length + 8)}.2`;
    const state = document.getElementById('svcState');
    state.className = 'svc-state ' + (ss.status === 'healthy' ? 'healthy' : ss.status === 'degraded' ? 'degraded' : 'down');
    state.querySelector('.label').textContent = ss.status.toUpperCase();
  }

  function refreshStatTiles() {
    const ss = E.getService(currentId);
    set('stLat', ss.latency.toFixed(0));
    set('stErr', ss.errRate.toFixed(2));
    set('stReq', (ss.reqs).toFixed(0));
    set('stUp', (99.5 + (ss.status === 'healthy' ? 0.49 : ss.status === 'degraded' ? 0.2 : -1.2)).toFixed(3));
    const state = document.getElementById('svcState');
    state.className = 'svc-state ' + (ss.status === 'healthy' ? 'healthy' : ss.status === 'degraded' ? 'degraded' : 'down');
    state.querySelector('.label').textContent = ss.status.toUpperCase();
  }
  function set(id, v) { const e = document.getElementById(id); if (e) e.querySelector('.v').textContent = v; }

  /* per-service log */
  const logEl = document.getElementById('svcLog');
  function fmtTime(t) { const d = new Date(t); return [d.getUTCHours(), d.getUTCMinutes(), d.getUTCSeconds()].map(n => String(n).padStart(2, '0')).join(':'); }
  function maybeLog(e) {
    if (e.svc !== E.SERVICES.find(s => s.id === currentId).name) return;
    const row = document.createElement('div'); row.className = 'log-row'; row.dataset.sev = e.sev;
    row.innerHTML = `<span class="lt">${fmtTime(e.t)}</span><span class="lsev">${e.sev.toUpperCase()}</span><span class="lsvc">${e.svc}</span><span class="lmsg">${e.msg}</span>`;
    logEl.insertBefore(row, logEl.firstChild);
    while (logEl.children.length > 60) logEl.removeChild(logEl.lastChild);
  }
  E.on('log', maybeLog);

  E.on('tick', refreshStatTiles);
  let last = 0;
  E.on('frame', ({ now }) => { if (now - last < (C.reduceMotion ? 500 : 70)) return; last = now; draw(); });

  refreshHero(); refreshStatTiles(); seed(); draw();
  window.addEventListener('resize', draw);
})();
