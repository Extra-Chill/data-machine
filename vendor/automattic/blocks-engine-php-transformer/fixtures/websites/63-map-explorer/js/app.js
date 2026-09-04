/* =========================================================
   VAELORA ATLAS — App Controller
   Wires MapEngine + MapRenderer to the UI: layer toggles,
   tooltip, detail panel, search, route planner, minimap,
   scale readout, and localStorage persistence.
   ========================================================= */
(function () {
  'use strict';

  const D = window.VAELORA;
  const STORE = 'vaelora.state.v1';

  const defaults = {
    theme: 'dark',
    layers: { graticule: true, regions: true, water: true, roads: true, transit: true, poi: true, labels: true },
    view: null,
    routeFrom: null, routeTo: null
  };

  function load() {
    try { return deepMerge(structuredCloneSafe(defaults), JSON.parse(localStorage.getItem(STORE) || '{}')); }
    catch (e) { return structuredCloneSafe(defaults); }
  }
  function save() { try { localStorage.setItem(STORE, JSON.stringify(state)); } catch (e) {} }
  function structuredCloneSafe(o) { return JSON.parse(JSON.stringify(o)); }
  function deepMerge(a, b) { for (const k in b) { if (b[k] && typeof b[k] === 'object' && !Array.isArray(b[k])) a[k] = deepMerge(a[k] || {}, b[k]); else a[k] = b[k]; } return a; }

  const state = load();

  /* ---- theme (shared with header on every page) ---- */
  function applyTheme(t) {
    document.body.setAttribute('data-theme', t);
    state.theme = t; save();
    const lbl = document.querySelector('#themeToggle .tlabel');
    if (lbl) lbl.textContent = t === 'dark' ? 'Dark' : 'Light';
  }
  applyTheme(state.theme);
  const themeBtn = document.getElementById('themeToggle');
  if (themeBtn) themeBtn.addEventListener('click', () =>
    applyTheme(document.body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'));

  // mobile nav
  const menuToggle = document.getElementById('menuToggle');
  if (menuToggle) menuToggle.addEventListener('click', () => document.body.classList.toggle('nav-open'));
  const scrim = document.querySelector('.nav-scrim');
  if (scrim) scrim.addEventListener('click', () => document.body.classList.remove('nav-open'));
  document.querySelectorAll('.side-link').forEach(l => {
    const file = location.pathname.split('/').pop() || 'index.html';
    if (l.getAttribute('href') === file) l.classList.add('active');
    l.addEventListener('click', () => document.body.classList.remove('nav-open'));
  });

  // The map only runs on pages that have the svg.
  const svg = document.getElementById('mapSvg');
  if (!svg) return;

  /* =========================================================
     BUILD MAP
     ========================================================= */
  const renderer = window.MapRenderer(svg);
  const engine = window.MapEngine(svg, { minZ: 0.85, maxZ: 14, pad: 60 });
  renderer.bindEngine(engine);

  // restore layer visibility
  Object.keys(state.layers).forEach(id => renderer.setLayer(id, state.layers[id]));
  // restore view (if any) else fit
  if (state.view) engine.setView(state.view, true);
  else engine.reset(false);

  /* ---- layer toggles UI ---- */
  document.querySelectorAll('.layer-toggle').forEach(cb => {
    const id = cb.dataset.layer;
    cb.checked = !!state.layers[id];
    cb.addEventListener('change', () => {
      state.layers[id] = cb.checked;
      renderer.setLayer(id, cb.checked);
      save();
    });
  });

  /* =========================================================
     ZOOM / SCALE / COORD READOUT + MINIMAP
     ========================================================= */
  const zoomVal = document.getElementById('zoomVal');
  const scaleBar = document.getElementById('scaleBar');
  const scaleLabel = document.getElementById('scaleLabel');
  const coordReadout = document.getElementById('coordReadout');

  // minimap
  const mini = document.getElementById('miniSvg');
  let miniRect = null;
  if (mini) buildMinimap();

  function buildMinimap() {
    const W = D.WORLD;
    mini.setAttribute('viewBox', `${W.x} ${W.y} ${W.w} ${W.h}`);
    const NS = 'http://www.w3.org/2000/svg';
    D.regions.forEach(r => {
      const p = document.createElementNS(NS, 'path');
      p.setAttribute('d', 'M' + r.poly.map(pt => pt.join(',')).join(' L') + ' Z');
      p.setAttribute('fill', r.color); p.setAttribute('class', 'mini-region');
      mini.appendChild(p);
    });
    miniRect = document.createElementNS(NS, 'rect');
    miniRect.setAttribute('class', 'mini-viewport');
    mini.appendChild(miniRect);
    // click/drag minimap to recenter
    function recenter(evt) {
      const r = mini.getBoundingClientRect();
      const wx = W.x + ((evt.clientX - r.left) / r.width) * W.w;
      const wy = W.y + ((evt.clientY - r.top) / r.height) * W.h;
      const v = engine.getView();
      engine.setView({ x: wx - v.w / 2, y: wy - v.h / 2, w: v.w, h: v.h });
    }
    let mDrag = false;
    mini.addEventListener('pointerdown', e => { mDrag = true; mini.setPointerCapture(e.pointerId); recenter(e); });
    mini.addEventListener('pointermove', e => { if (mDrag) recenter(e); });
    mini.addEventListener('pointerup', () => mDrag = false);
  }

  function fmtCoord(wx, wy) {
    // map world x->longitude-ish, y->latitude-ish over a fictional grid
    // Vaelora is centered near 14.2°N, 48.6°W (invented).
    const lon = -52 + (wx / D.WORLD.w) * 8;     // -52 .. -44
    const lat = 16.5 - (wy / D.WORLD.h) * 5;     // 16.5 .. 11.5
    const ns = lat >= 0 ? 'N' : 'S', ew = lon >= 0 ? 'E' : 'W';
    return `${Math.abs(lat).toFixed(3)}°${ns}  ${Math.abs(lon).toFixed(3)}°${ew}`;
  }

  function onView() {
    const v = engine.getView();
    const z = engine.zoomLevel();
    renderer.update(z);
    if (zoomVal) zoomVal.textContent = z.toFixed(2) + '×';

    // scale bar: choose a "nice" distance that fits ~110px on screen
    if (scaleBar && scaleLabel) {
      const rect = svg.getBoundingClientRect();
      const kmPerPx = (v.w / rect.width) * D.KM_PER_UNIT;
      const targetPx = 110;
      let km = kmPerPx * targetPx;
      const nice = niceNumber(km);
      const px = nice / kmPerPx;
      scaleBar.style.width = px.toFixed(1) + 'px';
      scaleLabel.textContent = nice < 1 ? (nice * 1000).toFixed(0) + ' m' : nice + ' km';
    }

    // minimap viewport rect
    if (miniRect) {
      miniRect.setAttribute('x', v.x); miniRect.setAttribute('y', v.y);
      miniRect.setAttribute('width', v.w); miniRect.setAttribute('height', v.h);
    }
    // persist (throttled)
    state.view = v; scheduleSave();
  }
  function niceNumber(x) {
    const pow = Math.pow(10, Math.floor(Math.log10(x)));
    const f = x / pow;
    const n = f < 1.5 ? 1 : f < 3.5 ? 2 : f < 7.5 ? 5 : 10;
    return n * pow;
  }
  let saveTimer = null;
  function scheduleSave() { clearTimeout(saveTimer); saveTimer = setTimeout(save, 300); }

  engine.on('view', onView);
  onView();

  /* =========================================================
     HOVER TOOLTIP + HIGHLIGHT
     ========================================================= */
  const tip = document.getElementById('mapTip');
  let hoverNode = null;

  function tolWorld() {
    // 14px pick radius -> world units at current zoom
    const v = engine.getView();
    const rect = svg.getBoundingClientRect();
    return 14 * (v.w / rect.width);
  }

  engine.on('hover', wpt => {
    const hitf = renderer.pick(wpt, tolWorld());
    if (hoverNode && (!hitf || hitf.node !== hoverNode)) {
      hoverNode.classList.remove('is-hover'); hoverNode = null;
    }
    if (!hitf) { tip.classList.remove('show'); svg.classList.remove('over-feature'); return; }
    if (hitf.node !== hoverNode) { hoverNode = hitf.node; hoverNode.classList.add('is-hover'); }
    svg.classList.add('over-feature');
    const sp = engine.worldToScreen(wpt.x, wpt.y);
    showTip(hitf, sp.x, sp.y);
  });
  engine.on('hoverout', () => {
    tip.classList.remove('show');
    if (hoverNode) { hoverNode.classList.remove('is-hover'); hoverNode = null; }
  });

  function showTip(hitf, sx, sy) {
    let title = '', sub = '';
    if (hitf.kind === 'region') { title = hitf.ref.name; sub = hitf.ref.area; }
    else if (hitf.kind === 'poi') { title = hitf.ref.name; sub = D.poiCats[hitf.ref.cat].label + ' · ' + hitf.ref.stat; }
    else { title = hitf.ref.name; sub = D.linesForStation(hitf.id).map(l => l.name).join(' · '); }
    tip.innerHTML = `<b>${esc(title)}</b><span>${esc(sub)}</span>`;
    tip.classList.add('show');
    const tw = tip.offsetWidth, th = tip.offsetHeight;
    let left = sx + 16, top = sy - th - 10;
    if (left + tw > window.innerWidth - 8) left = sx - tw - 16;
    if (top < 8) top = sy + 18;
    tip.style.left = left + 'px'; tip.style.top = top + 'px';
  }

  /* =========================================================
     CLICK / TAP -> DETAIL PANEL
     ========================================================= */
  let selectedNode = null;
  engine.on('tap', wpt => {
    if (routeMode) { handleRouteClick(wpt); return; }
    const hitf = renderer.pick(wpt, tolWorld());
    if (hitf) selectFeature(hitf.kind, hitf.id);
    else closePanel();
  });

  const panel = document.getElementById('detailPanel');
  const panelBody = document.getElementById('panelBody');
  document.getElementById('panelClose').addEventListener('click', closePanel);

  function selectFeature(kind, id, opts) {
    opts = opts || {};
    const node = renderer.nodeFor(kind, id);
    if (selectedNode && selectedNode !== node) selectedNode.classList.remove('is-selected');
    if (node) { node.classList.add('is-selected'); selectedNode = node; }

    let ref, body;
    if (kind === 'region') {
      ref = D.regionById(id);
      body = regionPanel(ref);
      if (opts.fly !== false) engine.flyToRect(D.bbox(ref.poly), { margin: 0.25 });
    } else if (kind === 'poi') {
      ref = D.poiById(id);
      body = poiPanel(ref);
      if (opts.fly !== false) engine.flyToPoint(ref.x, ref.y, 7);
    } else {
      ref = D.stationById(id);
      body = stationPanel(ref);
      if (opts.fly !== false) engine.flyToPoint(ref.x, ref.y, 9);
    }
    panelBody.innerHTML = body;
    panel.classList.add('open');
    wireRouteButtons();
  }

  function closePanel() {
    panel.classList.remove('open');
    if (selectedNode) { selectedNode.classList.remove('is-selected'); selectedNode = null; }
  }

  function regionPanel(r) {
    return `
      <span class="dp-kicker" style="color:${r.color}">Region · ${esc(r.island)} Island</span>
      <h2 class="dp-title">${esc(r.name)}</h2>
      <p class="dp-sub">${esc(r.area)}</p>
      <p class="dp-blurb">${esc(r.blurb)}</p>
      <div class="dp-stats">
        <div><span class="k">Capital</span><span class="v">${esc(r.capital)}</span></div>
        <div><span class="k">Population</span><span class="v">${r.pop.toLocaleString()}</span></div>
        <div><span class="k">Island</span><span class="v">${esc(r.island)}</span></div>
      </div>`;
  }
  function poiPanel(p) {
    const cat = D.poiCats[p.cat];
    const region = D.regionById(p.region);
    return `
      <span class="dp-kicker" style="color:${cat.color}">${esc(cat.label)}</span>
      <h2 class="dp-title">${esc(p.name)}</h2>
      <p class="dp-sub">${esc(p.stat)}</p>
      <p class="dp-blurb">${esc(p.blurb)}</p>
      <div class="dp-stats">
        <div><span class="k">Region</span><span class="v">${esc(region ? region.name : '—')}</span></div>
        <div><span class="k">Category</span><span class="v">${esc(cat.label)}</span></div>
        <div><span class="k">Coords</span><span class="v mono">${fmtCoord(p.x, p.y)}</span></div>
      </div>`;
  }
  function stationPanel(s) {
    const lines = D.linesForStation(s.id);
    const region = regionContaining(s.x, s.y);
    const chips = lines.map(l => `<span class="line-chip" style="--lc:${l.color}">${esc(l.name)}</span>`).join('');
    return `
      <span class="dp-kicker">Tramway station</span>
      <h2 class="dp-title">${esc(s.name)}</h2>
      <p class="dp-sub">${lines.length > 1 ? 'Interchange · ' : ''}${lines.length} line${lines.length > 1 ? 's' : ''}</p>
      <div class="line-chips">${chips}</div>
      <div class="dp-stats">
        <div><span class="k">Region</span><span class="v">${esc(region ? region.name : '—')}</span></div>
        <div><span class="k">Coords</span><span class="v mono">${fmtCoord(s.x, s.y)}</span></div>
      </div>
      <div class="route-actions">
        <button class="btn-route" data-route="from" data-id="${s.id}">Route from here</button>
        <button class="btn-route" data-route="to" data-id="${s.id}">Route to here</button>
      </div>`;
  }
  function regionContaining(x, y) {
    return D.regions.find(r => pointInPoly(x, y, r.poly));
  }
  function pointInPoly(px, py, poly) {
    let inside = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
      const xi = poly[i][0], yi = poly[i][1], xj = poly[j][0], yj = poly[j][1];
      if (((yi > py) !== (yj > py)) && (px < (xj - xi) * (py - yi) / (yj - yi) + xi)) inside = !inside;
    }
    return inside;
  }

  /* =========================================================
     SEARCH / FILTER LIST  (flies to selected place)
     ========================================================= */
  const searchInput = document.getElementById('placeSearch');
  const resultList = document.getElementById('resultList');
  const filterChips = document.querySelectorAll('.filter-chip');
  const allPlaces = D.allPlaces();
  let activeFilter = 'all';

  filterChips.forEach(c => c.addEventListener('click', () => {
    filterChips.forEach(x => x.classList.remove('active'));
    c.classList.add('active'); activeFilter = c.dataset.filter; renderResults();
  }));
  if (searchInput) searchInput.addEventListener('input', renderResults);

  function renderResults() {
    if (!resultList) return;
    const q = (searchInput ? searchInput.value : '').trim().toLowerCase();
    const items = allPlaces.filter(p => {
      const fok = activeFilter === 'all'
        || (activeFilter === 'region' && p.kind === 'region')
        || (activeFilter === 'poi' && p.kind === 'poi')
        || (activeFilter === 'station' && p.kind === 'station');
      const qok = !q || p.name.toLowerCase().includes(q) || (p.sub || '').toLowerCase().includes(q) || p.type.toLowerCase().includes(q);
      return fok && qok;
    });
    resultList.innerHTML = items.map(p => `
      <li>
        <button class="result" data-kind="${p.kind}" data-id="${p.id}">
          <span class="result-icon ${p.kind}"></span>
          <span class="result-text">
            <span class="result-name">${esc(p.name)}</span>
            <span class="result-sub">${esc(p.type)}${p.sub ? ' · ' + esc(p.sub) : ''}</span>
          </span>
        </button>
      </li>`).join('') || '<li class="result-empty">No places match.</li>';
    resultList.querySelectorAll('.result').forEach(b => {
      b.addEventListener('click', () => {
        selectFeature(b.dataset.kind, b.dataset.id);
        if (window.matchMedia('(max-width: 860px)').matches) document.body.classList.remove('panel-list-open');
      });
    });
  }
  renderResults();

  /* =========================================================
     ROUTE PLANNER (tram path between two stations)
     ========================================================= */
  let routeMode = false;
  const routeBtn = document.getElementById('routeBtn');
  const routeInfo = document.getElementById('routeInfo');

  if (routeBtn) routeBtn.addEventListener('click', () => {
    routeMode = !routeMode;
    routeBtn.classList.toggle('active', routeMode);
    svg.classList.toggle('route-mode', routeMode);
    if (routeMode) setRouteInfo('Click a start station, then an end station.');
    else clearRoute();
  });

  let routeFrom = null, routeTo = null;
  function handleRouteClick(wpt) {
    const hitf = renderer.pick(wpt, tolWorld());
    if (!hitf || hitf.kind !== 'station') { setRouteInfo('Pick a tram station.'); return; }
    if (!routeFrom) { routeFrom = hitf.id; markRouteEnds(); setRouteInfo('Start: ' + D.stationById(routeFrom).name + ' · pick destination.'); }
    else if (!routeTo && hitf.id !== routeFrom) { routeTo = hitf.id; markRouteEnds(); computeRoute(); }
    else { routeFrom = hitf.id; routeTo = null; markRouteEnds(); setRouteInfo('Start: ' + D.stationById(routeFrom).name + ' · pick destination.'); }
  }
  function markRouteEnds() {
    document.querySelectorAll('.station.route-end').forEach(n => n.classList.remove('route-end'));
    [routeFrom, routeTo].forEach(id => { if (id) { const n = renderer.nodeFor('station', id); if (n) n.classList.add('route-end'); } });
  }

  // Build adjacency graph from line definitions (consecutive stops connected).
  function buildGraph() {
    const adj = {};
    D.stations.forEach(s => adj[s.id] = []);
    D.lines.forEach(line => {
      for (let i = 0; i < line.stops.length - 1; i++) {
        const a = line.stops[i], b = line.stops[i + 1];
        const sa = D.stationById(a), sb = D.stationById(b);
        const w = Math.hypot(sa.x - sb.x, sa.y - sb.y);
        adj[a].push({ to: b, w, line }); adj[b].push({ to: a, w, line });
      }
    });
    return adj;
  }
  const GRAPH = buildGraph();

  function computeRoute() {
    // Dijkstra over the tram graph.
    const dist = {}, prev = {}, prevLine = {};
    D.stations.forEach(s => dist[s.id] = Infinity);
    dist[routeFrom] = 0;
    const pq = new Set(D.stations.map(s => s.id));
    while (pq.size) {
      let u = null, best = Infinity;
      pq.forEach(id => { if (dist[id] < best) { best = dist[id]; u = id; } });
      if (u === null) break;
      pq.delete(u);
      if (u === routeTo) break;
      (GRAPH[u] || []).forEach(e => {
        const nd = dist[u] + e.w;
        if (nd < dist[e.to]) { dist[e.to] = nd; prev[e.to] = u; prevLine[e.to] = e.line; }
      });
    }
    if (dist[routeTo] === Infinity) { setRouteInfo('No tram path between those stations.'); return; }
    // reconstruct
    const seq = []; let cur = routeTo;
    while (cur !== undefined) { seq.unshift(cur); if (cur === routeFrom) break; cur = prev[cur]; }
    drawRoute(seq, prevLine);
  }

  function drawRoute(seq, prevLine) {
    const pts = seq.map(id => { const s = D.stationById(id); return [s.x, s.y]; });
    renderer.routeOverlay.setAttribute('d', renderer.smoothD(pts));
    renderer.routeOverlay.classList.add('active');
    // distance + line changes
    let dist = 0, changes = 0, lastLine = null;
    const lineSeq = [];
    for (let i = 1; i < seq.length; i++) {
      const a = D.stationById(seq[i - 1]), b = D.stationById(seq[i]);
      dist += Math.hypot(a.x - b.x, a.y - b.y);
      const ln = prevLine[seq[i]];
      if (ln && ln !== lastLine) { lineSeq.push(ln); if (lastLine) changes++; lastLine = ln; }
    }
    const km = (dist * D.KM_PER_UNIT).toFixed(1);
    const lineNames = lineSeq.map(l => `<span class="line-chip" style="--lc:${l.color}">${esc(l.name)}</span>`).join('');
    setRouteInfo(
      `<b>${esc(D.stationById(routeFrom).name)} → ${esc(D.stationById(routeTo).name)}</b>` +
      `<div class="route-meta"><span>${seq.length} stops</span><span>${km} km</span><span>${changes} change${changes === 1 ? '' : 's'}</span></div>` +
      `<div class="line-chips">${lineNames}</div>` +
      `<button class="btn-clear-route">Clear route</button>`
    );
    const cb = routeInfo.querySelector('.btn-clear-route');
    if (cb) cb.addEventListener('click', clearRoute);
    // fit the route
    engine.flyToRect(D.bbox(pts), { margin: 0.5 });
  }

  function setRouteInfo(html) { if (routeInfo) { routeInfo.innerHTML = html; routeInfo.classList.add('show'); } }
  function clearRoute() {
    routeFrom = routeTo = null; markRouteEnds();
    renderer.routeOverlay.setAttribute('d', ''); renderer.routeOverlay.classList.remove('active');
    if (routeInfo) { routeInfo.classList.remove('show'); routeInfo.innerHTML = ''; }
    routeMode = false; if (routeBtn) routeBtn.classList.remove('active'); svg.classList.remove('route-mode');
  }

  function wireRouteButtons() {
    panelBody.querySelectorAll('.btn-route').forEach(b => {
      b.addEventListener('click', () => {
        routeMode = true; if (routeBtn) { routeBtn.classList.add('active'); }
        svg.classList.add('route-mode');
        if (b.dataset.route === 'from') { routeFrom = b.dataset.id; routeTo = null; markRouteEnds(); setRouteInfo('Start: ' + D.stationById(routeFrom).name + ' · pick destination.'); }
        else {
          routeTo = b.dataset.id;
          if (!routeFrom) { markRouteEnds(); setRouteInfo('Destination set · pick a start station.'); }
          else { markRouteEnds(); computeRoute(); }
        }
      });
    });
  }

  /* =========================================================
     CONTROLS: zoom buttons, reset, keyboard
     ========================================================= */
  document.getElementById('zoomIn').addEventListener('click', () => zoomCenter(1.6));
  document.getElementById('zoomOut').addEventListener('click', () => zoomCenter(1 / 1.6));
  document.getElementById('resetBtn').addEventListener('click', () => { engine.reset(true); });

  function zoomCenter(factor) {
    const v = engine.getView();
    engine.zoomAt({ x: v.x + v.w / 2, y: v.y + v.h / 2 }, factor);
  }

  // make svg keyboard-focusable & support arrow pan / +- zoom
  svg.setAttribute('tabindex', '0');
  svg.addEventListener('keydown', e => {
    const step = 0.12;
    switch (e.key) {
      case 'ArrowLeft':  engine.nudge(-step, 0); e.preventDefault(); break;
      case 'ArrowRight': engine.nudge(step, 0); e.preventDefault(); break;
      case 'ArrowUp':    engine.nudge(0, -step); e.preventDefault(); break;
      case 'ArrowDown':  engine.nudge(0, step); e.preventDefault(); break;
      case '+': case '=': zoomCenter(1.5); e.preventDefault(); break;
      case '-': case '_': zoomCenter(1 / 1.5); e.preventDefault(); break;
      case '0': engine.reset(true); e.preventDefault(); break;
      case 'Escape': closePanel(); break;
    }
  });

  // keyboard activation of features (enter/space on focused region/poi/station)
  svg.addEventListener('keydown', e => {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const t = e.target.closest('[data-id]');
    if (!t) return;
    e.preventDefault();
    const layer = t.closest('.layer');
    let kind = t.classList.contains('region') ? 'region'
      : t.classList.contains('poi') ? 'poi'
      : t.classList.contains('station') ? 'station' : null;
    if (kind) selectFeature(kind, t.getAttribute('data-id'));
  });

  // coordinate readout follows the pointer
  svg.addEventListener('pointermove', e => {
    if (!coordReadout) return;
    const w = engine.screenToWorld(e.clientX, e.clientY);
    if (w.x < 0 || w.y < 0 || w.x > D.WORLD.w || w.y > D.WORLD.h) { coordReadout.textContent = '— open sea —'; return; }
    coordReadout.textContent = fmtCoord(w.x, w.y);
  });

  // mobile: open the search/list drawer
  const listToggle = document.getElementById('listToggle');
  if (listToggle) listToggle.addEventListener('click', () => document.body.classList.toggle('panel-list-open'));

  /* ---- deep-link from gazetteer: ?focus=<kind>:<id> ---- */
  (function deepLink() {
    const params = new URLSearchParams(location.search);
    const f = params.get('focus');
    if (!f) return;
    const [kind, id] = f.split(':');
    if (kind && id) setTimeout(() => selectFeature(kind, id), 120);
  })();

  function esc(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
})();
