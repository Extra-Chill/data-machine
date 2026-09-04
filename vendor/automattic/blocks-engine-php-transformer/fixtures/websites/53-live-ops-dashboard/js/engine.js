/* =========================================================
   HELIX GRID — Data Engine
   A simulated real-time streaming source for a renewable
   energy-grid operations console. Drives every chart, KPI,
   gauge, status grid and log line in the app.

   Pure vanilla. Deterministic-ish noise + trends + spikes.
   Exposes window.HelixEngine (a tiny pub/sub event bus).
   ========================================================= */
(function () {
  'use strict';

  /* ---- small seeded RNG so reloads look continuous-ish ---- */
  let _seed = (Date.now() >>> 0) ^ 0x9e3779b9;
  function rnd() {
    _seed ^= _seed << 13; _seed ^= _seed >>> 17; _seed ^= _seed << 5;
    return ((_seed >>> 0) % 1e7) / 1e7;
  }
  function gauss() { // approx normal noise, mean 0
    return (rnd() + rnd() + rnd() + rnd() - 2) * 0.75;
  }
  function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
  function lerp(a, b, t) { return a + (b - a) * t; }

  /* ---- domain model: a renewable grid operator ---- */
  const REGIONS = [
    { id: 'pnw',  name: 'Pacific NW',   cap: 4200, base: 0.62, color: '#34e0c4' },
    { id: 'cal',  name: 'California',   cap: 6100, base: 0.71, color: '#5b9dff' },
    { id: 'tex',  name: 'ERCOT Texas',  cap: 5400, base: 0.58, color: '#b07bff' },
    { id: 'mw',   name: 'Midwest',      cap: 3800, base: 0.49, color: '#ffc04d' },
    { id: 'ne',   name: 'New England',  cap: 2600, base: 0.55, color: '#41d68a' },
    { id: 'se',   name: 'Southeast',    cap: 3100, base: 0.66, color: '#ff8a5b' },
  ];

  const SOURCES = [ // generation mix (stacked area)
    { id: 'solar', name: 'Solar',   color: '#ffc04d' },
    { id: 'wind',  name: 'Wind',    color: '#5b9dff' },
    { id: 'hydro', name: 'Hydro',   color: '#34e0c4' },
    { id: 'storage', name: 'Storage', color: '#b07bff' },
  ];

  const SERVICES = [
    { id: 'scada',     name: 'scada-gateway',   region: 'cal', tier: 'core' },
    { id: 'dispatch',  name: 'dispatch-engine', region: 'tex', tier: 'core' },
    { id: 'forecast',  name: 'forecast-ml',     region: 'pnw', tier: 'core' },
    { id: 'billing',   name: 'settlement-api',  region: 'mw',  tier: 'edge' },
    { id: 'telemetry', name: 'telemetry-bus',   region: 'ne',  tier: 'core' },
    { id: 'market',    name: 'market-feed',     region: 'se',  tier: 'edge' },
    { id: 'auth',      name: 'auth-broker',     region: 'cal', tier: 'edge' },
    { id: 'archive',   name: 'cold-archive',    region: 'mw',  tier: 'edge' },
  ];

  /* log message templates by severity */
  const LOG_BANK = {
    info: [
      s => `[${s}] healthy — ${(40 + rnd() * 60 | 0)}ms p50, ${(180 + rnd() * 220 | 0)} req/s`,
      s => `[${s}] config reload applied (rev ${('a' + (rnd() * 9000 + 1000 | 0))})`,
      () => `dispatch: re-balanced load across 6 control areas`,
      () => `forecast-ml: 4h horizon refreshed, MAPE ${(2 + rnd() * 3).toFixed(1)}%`,
      s => `[${s}] autoscaled ${rnd() > .5 ? 'up' : 'down'} to ${(4 + rnd() * 12 | 0)} replicas`,
      () => `market-feed: cleared price $${(28 + rnd() * 40).toFixed(2)}/MWh`,
      s => `[${s}] heartbeat ok — uptime ${(20 + rnd() * 300 | 0)}d`,
    ],
    ok: [
      s => `[${s}] recovered — error rate back below 0.1%`,
      () => `storage: bank charged to ${(70 + rnd() * 28 | 0)}% SoC`,
      s => `[${s}] failover completed, primary restored`,
      () => `grid frequency re-synced to 60.00 Hz`,
    ],
    warn: [
      s => `[${s}] elevated latency — p99 ${(600 + rnd() * 900 | 0)}ms`,
      s => `[${s}] retry budget at ${(20 + rnd() * 40 | 0)}%, backing off`,
      () => `forecast skew detected: wind over-predicted by ${(8 + rnd() * 14 | 0)}%`,
      s => `[${s}] queue depth ${(2000 + rnd() * 6000 | 0)} msgs, lagging`,
      () => `frequency drift +${(0.02 + rnd() * 0.06).toFixed(3)} Hz, trimming reserves`,
      s => `[${s}] cert expires in ${(3 + rnd() * 9 | 0)}d`,
    ],
    crit: [
      s => `[${s}] ERROR 5xx spike — ${(3 + rnd() * 9).toFixed(1)}% of requests failing`,
      s => `[${s}] connection pool exhausted (max ${(100 + rnd() * 200 | 0)})`,
      () => `INTERTIE TRIP: 230kV line PNW↔CAL opened, rerouting`,
      s => `[${s}] circuit breaker OPEN — shedding non-critical load`,
      () => `under-frequency event 59.${(82 + rnd() * 10 | 0)}Hz — reserves deployed`,
    ],
  };

  /* ---- engine state ---- */
  const MAXLEN = 240;           // ring buffer length for series
  const TICK_MS = 1000;         // base tick = 1s of sim time
  const state = {
    paused: false,
    speed: 1,                   // playback multiplier
    t: Date.now(),              // sim clock
    tick: 0,
    series: {},                 // metricId -> [{t, v}]
    regions: {},                // regionId -> { load, gen, freq, history }
    sources: {},                // sourceId -> current MW
    services: {},               // serviceId -> { latency, errRate, status, history }
    grid: { freq: 60.0, demand: 0, supply: 0, price: 34, co2: 41 },
    incidents: [],
    activeIncident: null,
    logBuffer: [],
  };

  // metrics that get scrolling time-series
  const METRICS = ['demand', 'supply', 'freq', 'price', 'latency', 'errors', 'co2', 'throughput'];
  METRICS.forEach(m => state.series[m] = []);

  REGIONS.forEach(r => {
    state.regions[r.id] = { load: r.cap * r.base, gen: r.cap * r.base * 1.02, freq: 60, stress: 0.3, history: [] };
  });
  SOURCES.forEach(s => state.sources[s.id] = 0);
  SERVICES.forEach(s => {
    state.services[s.id] = { latency: 50 + rnd() * 40, errRate: 0.05 + rnd() * 0.1, status: 'healthy', history: [], reqs: 200 + rnd() * 200 };
  });

  /* ---- tiny pub/sub ---- */
  const listeners = {};
  function on(evt, fn) { (listeners[evt] || (listeners[evt] = [])).push(fn); }
  function emit(evt, payload) { (listeners[evt] || []).forEach(fn => fn(payload)); }

  function push(arr, v) { arr.push(v); if (arr.length > MAXLEN) arr.shift(); }

  /* ---- incident lifecycle ---- */
  let incidentSeq = 4823;
  function startIncident(forced) {
    const svc = SERVICES[rnd() * SERVICES.length | 0];
    const region = REGIONS.find(r => r.id === svc.region);
    const sev = forced ? (rnd() > 0.35 ? 'crit' : 'warn')
                       : (rnd() > 0.7 ? 'crit' : 'warn');
    const kinds = [
      'Latency saturation', 'Error-rate spike', 'Intertie trip',
      'Frequency excursion', 'Capacity shortfall', 'Telemetry gap',
    ];
    const inc = {
      id: 'INC-' + (++incidentSeq),
      service: svc, region,
      severity: sev,
      kind: kinds[rnd() * kinds.length | 0],
      started: state.t,
      ttl: (forced ? 24 : 14) + rnd() * 16,  // ticks
      age: 0,
      status: 'active',
      peak: 0,
    };
    state.activeIncident = inc;
    state.incidents.unshift(inc);
    if (state.incidents.length > 60) state.incidents.pop();
    log(sev, svc.name, `${inc.kind} on ${svc.name} (${region.name}) — incident ${inc.id} OPEN`);
    emit('incident:start', inc);
  }
  function resolveIncident(inc) {
    inc.status = 'resolved';
    inc.resolved = state.t;
    log('ok', inc.service.name, `${inc.id} RESOLVED — ${inc.kind} on ${inc.service.name} cleared (${(inc.age)}s)`);
    if (state.activeIncident === inc) state.activeIncident = null;
    emit('incident:end', inc);
  }

  /* ---- log emission ---- */
  let logSeq = 0;
  function log(sev, svc, msg) {
    const entry = {
      id: ++logSeq, t: state.t, sev,
      svc: svc || SERVICES[rnd() * SERVICES.length | 0].name,
      msg,
    };
    state.logBuffer.push(entry);
    if (state.logBuffer.length > 500) state.logBuffer.shift();
    emit('log', entry);
    return entry;
  }
  function autoLog() {
    // pick severity weighted by current grid stress
    const inc = state.activeIncident;
    const stress = inc ? (inc.severity === 'crit' ? 0.55 : 0.3) : 0.08;
    const roll = rnd();
    let sev = 'info';
    if (roll < stress * 0.55) sev = 'crit';
    else if (roll < stress) sev = 'warn';
    else if (roll < stress + 0.12) sev = 'ok';
    const bank = LOG_BANK[sev];
    const svc = inc && sev !== 'info' ? inc.service.name : SERVICES[rnd() * SERVICES.length | 0].name;
    const tmpl = bank[rnd() * bank.length | 0];
    log(sev, svc, tmpl(svc));
  }

  /* =========================================================
     THE TICK — advances the whole simulation one step
     ========================================================= */
  function step() {
    state.tick++;
    state.t += TICK_MS;
    const tk = state.tick;

    // diurnal solar curve + slow demand wave
    const hour = (state.t / 3600000) % 24;
    const solarCurve = clamp(Math.sin((hour - 6) / 12 * Math.PI), 0, 1);
    const demandWave = 0.78 + 0.16 * Math.sin(tk / 90) + 0.05 * Math.sin(tk / 23);

    const inc = state.activeIncident;
    if (inc) {
      inc.age++;
      inc.peak = Math.max(inc.peak, inc.age);
      if (inc.age > inc.ttl) resolveIncident(inc);
    }
    const incStress = inc ? (inc.severity === 'crit' ? 1 : 0.5) : 0;

    // ----- regions -----
    let totalDemand = 0, totalSupply = 0;
    const srcAccum = { solar: 0, wind: 0, hydro: 0, storage: 0 };
    REGIONS.forEach(r => {
      const rs = state.regions[r.id];
      const target = r.cap * r.base * demandWave;
      rs.load = lerp(rs.load, target + gauss() * r.cap * 0.02, 0.18);
      // generation mix per region
      const solar = r.cap * 0.42 * solarCurve * (0.85 + rnd() * 0.3);
      const wind  = r.cap * (0.18 + 0.22 * (0.5 + 0.5 * Math.sin(tk / 47 + r.cap)));
      const hydro = r.cap * 0.16 * (0.8 + 0.4 * Math.sin(tk / 200));
      let storage = rs.load - (solar + wind + hydro); // storage fills gap
      storage = clamp(storage, -r.cap * 0.15, r.cap * 0.25);
      rs.gen = solar + wind + hydro + storage;
      srcAccum.solar += solar; srcAccum.wind += wind; srcAccum.hydro += hydro; srcAccum.storage += Math.max(0, storage);
      // stress: gap between load & gen + incident bleed if region matches
      const gap = Math.abs(rs.load - rs.gen) / r.cap;
      let stressTarget = clamp(gap * 4 + (inc && inc.region.id === r.id ? incStress * 0.7 : 0), 0, 1);
      rs.stress = lerp(rs.stress, stressTarget, 0.2);
      rs.freq = 60 + (rs.gen - rs.load) / r.cap * 0.4 + gauss() * 0.01 - incStress * 0.04 * (inc && inc.region.id === r.id ? 1 : 0);
      push(rs.history, rs.load);
      totalDemand += rs.load; totalSupply += rs.gen;
    });

    SOURCES.forEach(s => state.sources[s.id] = srcAccum[s.id]);

    // ----- grid aggregate -----
    state.grid.demand = totalDemand;
    state.grid.supply = totalSupply;
    state.grid.freq = lerp(state.grid.freq, 60 + (totalSupply - totalDemand) / 25000 + gauss() * 0.008 - incStress * 0.03, 0.3);
    state.grid.price = clamp(lerp(state.grid.price, 30 + (totalDemand / totalSupply) * 18 + incStress * 35 + gauss() * 2, 0.25), 8, 240);
    state.grid.co2 = clamp(lerp(state.grid.co2, 38 + (1 - solarCurve) * 90 + (srcAccum.storage < 0 ? 20 : 0) + gauss() * 4, 0.2), 12, 220);

    push(state.series.demand, { t: state.t, v: totalDemand });
    push(state.series.supply, { t: state.t, v: totalSupply });
    push(state.series.freq,   { t: state.t, v: state.grid.freq });
    push(state.series.price,  { t: state.t, v: state.grid.price });
    push(state.series.co2,    { t: state.t, v: state.grid.co2 });

    // ----- services -----
    let totalReq = 0, sumLat = 0, sumErr = 0;
    SERVICES.forEach(s => {
      const ss = state.services[s.id];
      const hit = inc && inc.service.id === s.id;
      const latTarget = (s.tier === 'core' ? 45 : 70)
        + (hit ? (inc.severity === 'crit' ? 900 : 380) * (0.5 + 0.5 * Math.sin(inc.age / 3)) : 0)
        + Math.max(0, gauss() * 18);
      ss.latency = lerp(ss.latency, latTarget, 0.3);
      const errTarget = 0.05 + (hit ? (inc.severity === 'crit' ? 7 : 2.5) : 0) + Math.max(0, gauss() * 0.15);
      ss.errRate = clamp(lerp(ss.errRate, errTarget, 0.3), 0, 18);
      ss.reqs = lerp(ss.reqs, 200 + rnd() * 260 * demandWave, 0.15);
      ss.status = ss.errRate > 3 || ss.latency > 500 ? 'down'
        : ss.errRate > 1 || ss.latency > 250 ? 'degraded' : 'healthy';
      push(ss.history, ss.latency);
      totalReq += ss.reqs; sumLat += ss.latency; sumErr += ss.errRate;
    });
    push(state.series.latency, { t: state.t, v: sumLat / SERVICES.length });
    push(state.series.errors,  { t: state.t, v: sumErr / SERVICES.length });
    push(state.series.throughput, { t: state.t, v: totalReq });

    // ----- random spontaneous incidents -----
    if (!state.activeIncident && rnd() < 0.012) startIncident(false);

    // ----- logs (a few per tick) -----
    const n = 1 + (rnd() * 2 | 0) + (inc ? 1 : 0);
    for (let i = 0; i < n; i++) autoLog();

    emit('tick', state);
  }

  /* =========================================================
     SCHEDULER — speed-aware, respects pause & reduced motion
     ========================================================= */
  let acc = 0, last = performance.now(), raf = null;
  function loop(now) {
    raf = requestAnimationFrame(loop);
    const dt = now - last; last = now;
    if (state.paused) { acc = 0; return; }
    acc += dt * state.speed;
    let guard = 0;
    while (acc >= TICK_MS && guard < 8) { step(); acc -= TICK_MS; guard++; }
    emit('frame', { now, state });   // for smooth canvas redraw between ticks
  }

  /* ---- bootstrap history so charts open full ---- */
  function prime(n) {
    const realPaused = state.paused; state.paused = false;
    state.t -= n * TICK_MS;
    for (let i = 0; i < n; i++) step();
    state.paused = realPaused;
  }

  /* ---- public API ---- */
  const API = {
    REGIONS, SOURCES, SERVICES,
    state,
    on, emit,
    start() { if (!raf) { last = performance.now(); raf = requestAnimationFrame(loop); } },
    stop() { if (raf) cancelAnimationFrame(raf); raf = null; },
    pause(v) { state.paused = v == null ? !state.paused : !!v; emit('pause', state.paused); },
    isPaused() { return state.paused; },
    setSpeed(v) { state.speed = clamp(v, 0.25, 8); emit('speed', state.speed); },
    getSpeed() { return state.speed; },
    triggerIncident() {
      if (state.activeIncident) { // escalate existing
        state.activeIncident.severity = 'crit';
        state.activeIncident.ttl += 10;
        log('crit', state.activeIncident.service.name,
          `${state.activeIncident.id} ESCALATED to SEV-1 by operator`);
        emit('incident:start', state.activeIncident);
      } else startIncident(true);
    },
    series(id) { return state.series[id] || []; },
    getService(id) { return state.services[id]; },
    log,
    prime,
  };

  prime(MAXLEN); // fill buffers
  window.HelixEngine = API;
})();
