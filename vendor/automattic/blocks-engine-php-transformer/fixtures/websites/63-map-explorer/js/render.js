/* =========================================================
   VAELORA ATLAS — Renderer
   Builds the SVG layer tree from VAELORA data, exposes layer
   show/hide, label decluttering by zoom, and hit-testing.
   ========================================================= */
(function () {
  'use strict';

  const SVGNS = 'http://www.w3.org/2000/svg';
  const D = window.VAELORA;

  function el(tag, attrs) {
    const n = document.createElementNS(SVGNS, tag);
    if (attrs) for (const k in attrs) n.setAttribute(k, attrs[k]);
    return n;
  }
  function polyD(pts, close) {
    let d = 'M' + pts.map(p => p[0] + ',' + p[1]).join(' L');
    if (close) d += ' Z';
    return d;
  }
  // smooth a polyline with a light Catmull-Rom -> bezier for rivers/roads
  function smoothD(pts) {
    if (pts.length < 3) return polyD(pts, false);
    let d = `M${pts[0][0]},${pts[0][1]}`;
    for (let i = 0; i < pts.length - 1; i++) {
      const p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
      const c1x = p1[0] + (p2[0] - p0[0]) / 6, c1y = p1[1] + (p2[1] - p0[1]) / 6;
      const c2x = p2[0] - (p3[0] - p1[0]) / 6, c2y = p2[1] - (p3[1] - p1[1]) / 6;
      d += ` C${c1x},${c1y} ${c2x},${c2y} ${p2[0]},${p2[1]}`;
    }
    return d;
  }

  function Renderer(svgEl) {
    // <defs> with a soft water-glow + halftone hill shading
    const defs = el('defs');
    const ocean = el('radialGradient', { id: 'oceanGrad', cx: '50%', cy: '42%', r: '75%' });
    ocean.appendChild(el('stop', { offset: '0%',  'stop-color': 'var(--sea-1)' }));
    ocean.appendChild(el('stop', { offset: '100%', 'stop-color': 'var(--sea-2)' }));
    defs.appendChild(ocean);
    const coastBlur = el('filter', { id: 'coastBlur', x: '-20%', y: '-20%', width: '140%', height: '140%' });
    coastBlur.appendChild(el('feGaussianBlur', { in: 'SourceGraphic', stdDeviation: '6' }));
    defs.appendChild(coastBlur);
    svgEl.appendChild(defs);

    // Ocean backdrop (covers a generous area around the world)
    const W = D.WORLD;
    svgEl.appendChild(el('rect', {
      x: W.x - 400, y: W.y - 400, width: W.w + 800, height: W.h + 800,
      fill: 'url(#oceanGrad)', class: 'ocean'
    }));

    // ── Layer groups (z-order matters) ──
    const layers = {};
    const layerOrder = ['coastglow', 'graticule', 'regions', 'water', 'roads', 'transit', 'poi', 'labels'];
    layerOrder.forEach(id => {
      const g = el('g', { 'data-layer': id, class: 'layer layer-' + id });
      layers[id] = g; svgEl.appendChild(g);
    });

    const hit = { regions: [], pois: [], stations: [] }; // hit-test registries
    const labelRegistry = []; // {el, x, y, min, max, w, h, priority}

    /* ---------- COAST GLOW (blurred halo behind land) ---------- */
    D.regions.forEach(r => {
      layers.coastglow.appendChild(el('path', {
        d: polyD(r.poly, true), class: 'coastglow', filter: 'url(#coastBlur)'
      }));
    });

    /* ---------- GRATICULE (lat/long grid) ---------- */
    for (let gx = 0; gx <= W.w; gx += 100) {
      layers.graticule.appendChild(el('line', { x1: gx, y1: 0, x2: gx, y2: W.h, class: 'grat' }));
    }
    for (let gy = 0; gy <= W.h; gy += 100) {
      layers.graticule.appendChild(el('line', { x1: 0, y1: gy, x2: W.w, y2: gy, class: 'grat' }));
    }

    /* ---------- REGIONS ---------- */
    D.regions.forEach(r => {
      const p = el('path', {
        d: polyD(r.poly, true), class: 'region', 'data-id': r.id,
        fill: r.color, tabindex: '0', role: 'button',
        'aria-label': r.name + ' region'
      });
      layers.regions.appendChild(p);
      hit.regions.push({ id: r.id, poly: r.poly, node: p, ref: r });
      // region label (centroid)
      const c = D.centroid(r.poly);
      addLabel(r.name, c[0], c[1], { cls: 'lbl-region', min: 0.85, max: 7, priority: 3 });
    });

    /* ---------- WATER (rivers, lakes) ---------- */
    D.lakes.forEach(l => {
      layers.water.appendChild(el('path', { d: polyD(l.poly, true), class: 'lake' }));
      const c = D.centroid(l.poly);
      addLabel(l.name, c[0], c[1], { cls: 'lbl-water', min: 2.6, max: 14, priority: 1 });
    });
    D.rivers.forEach(rv => {
      layers.water.appendChild(el('path', { d: smoothD(rv.path), class: 'river', 'data-id': rv.id }));
      // label near the river midpoint
      const mid = rv.path[Math.floor(rv.path.length / 2)];
      addLabel(rv.name, mid[0] + 6, mid[1], { cls: 'lbl-water', min: 3, max: 14, priority: 1, italic: true });
    });

    /* ---------- ROADS / TRAILS ---------- */
    D.roads.forEach(rd => {
      // casing under highways for a road look
      if (rd.cls === 'highway') {
        layers.roads.appendChild(el('path', { d: smoothD(rd.path), class: 'road-case' }));
      }
      layers.roads.appendChild(el('path', { d: smoothD(rd.path), class: 'road road-' + rd.cls, 'data-id': rd.id }));
      const mid = rd.path[Math.floor(rd.path.length / 2)];
      addLabel(rd.name, mid[0], mid[1] - 6, { cls: 'lbl-road', min: 4, max: 14, priority: 0 });
    });

    /* ---------- TRANSIT (lines then stations) ---------- */
    const transitLineLayer = el('g', { class: 'transit-lines' });
    const transitStationLayer = el('g', { class: 'transit-stations' });
    layers.transit.appendChild(transitLineLayer);
    layers.transit.appendChild(transitStationLayer);

    const lineNodes = {};
    D.lines.forEach(line => {
      const pts = line.stops.map(id => { const s = D.stationById(id); return [s.x, s.y]; });
      const path = el('path', {
        d: smoothD(pts), class: 'tram-line', 'data-id': line.id,
        stroke: line.color
      });
      transitLineLayer.appendChild(path);
      lineNodes[line.id] = path;
    });
    // route-highlight overlay (sits above lines)
    const routeOverlay = el('path', { class: 'route-overlay', d: '' });
    transitLineLayer.appendChild(routeOverlay);

    D.stations.forEach(s => {
      const lines = D.linesForStation(s.id);
      const isInterchange = lines.length > 1;
      const g = el('g', { class: 'station' + (isInterchange ? ' interchange' : ''), 'data-id': s.id, tabindex: '0', role: 'button', 'aria-label': s.name + ' tram station' });
      g.appendChild(el('circle', { cx: s.x, cy: s.y, r: isInterchange ? 6 : 4.4, class: 'station-dot', fill: isInterchange ? 'var(--ink)' : lines[0].color }));
      transitStationLayer.appendChild(g);
      hit.stations.push({ id: s.id, x: s.x, y: s.y, node: g, ref: s });
      addLabel(s.name, s.x + 8, s.y - 2, { cls: 'lbl-station', min: isInterchange ? 4 : 5.5, max: 14, priority: isInterchange ? 2 : 0 });
    });

    /* ---------- POI ---------- */
    D.pois.forEach(p => {
      const cat = D.poiCats[p.cat];
      const g = el('g', { class: 'poi poi-' + p.cat, 'data-id': p.id, transform: `translate(${p.x},${p.y})`, tabindex: '0', role: 'button', 'aria-label': p.name });
      g.appendChild(el('circle', { r: 8.5, class: 'poi-halo' }));
      g.appendChild(el('circle', { r: 6, class: 'poi-pin', fill: cat.color }));
      g.appendChild(poiGlyph(p.cat));
      layers.poi.appendChild(g);
      hit.pois.push({ id: p.id, x: p.x, y: p.y, node: g, ref: p });
      addLabel(p.name, p.x + 10, p.y + 3, { cls: 'lbl-poi', min: 3.4, max: 14, priority: 1 });
    });

    function poiGlyph(cat) {
      const g = el('g', { class: 'poi-glyph' });
      const mk = (tag, a) => g.appendChild(el(tag, a));
      switch (cat) {
        case 'peak':     mk('path', { d: 'M-3,2 L0,-3 L3,2 Z' }); break;
        case 'port':     mk('path', { d: 'M0,-3 V3 M-3,1 A3 3 0 0 0 3 1' }); mk('circle', { r: 1, cy: -3 }); break;
        case 'park':     mk('path', { d: 'M0,3 V-1 M-2.5,0 L0,-3 L2.5,0 Z' }); break;
        case 'civic':    mk('path', { d: 'M-3,2 H3 M-3,2 V-1 L0,-3 L3,-1 V2' }); break;
        default:         mk('circle', { r: 1.6 });
      }
      return g;
    }

    /* ---------- LABELS ---------- */
    function addLabel(text, x, y, o) {
      o = o || {};
      const t = el('text', {
        x, y, class: 'label ' + (o.cls || '') + (o.italic ? ' italic' : ''),
        'text-anchor': o.anchor || 'start'
      });
      t.textContent = text;
      layers.labels.appendChild(t);
      labelRegistry.push({ el: t, x, y, min: o.min || 0, max: o.max || 99,
        priority: o.priority || 0, text });
    }

    /* ---------- HIT-TESTING ---------- */
    function pointInPoly(px, py, poly) {
      let inside = false;
      for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const xi = poly[i][0], yi = poly[i][1], xj = poly[j][0], yj = poly[j][1];
        if (((yi > py) !== (yj > py)) && (px < (xj - xi) * (py - yi) / (yj - yi) + xi)) inside = !inside;
      }
      return inside;
    }

    function isVisible(layerId) { return layers[layerId].style.display !== 'none'; }

    // hit-test a world point. tolWorld = pick radius in world units.
    // Point features (POI/station) win over area features; the single
    // nearest point feature within tolerance is chosen. Hidden layers
    // are never picked.
    function pick(worldPt, tolWorld) {
      let best = null, bestDist = Infinity;
      if (isVisible('poi')) hit.pois.forEach(h => {
        const d = Math.hypot(h.x - worldPt.x, h.y - worldPt.y);
        if (d < tolWorld && d < bestDist) { best = { kind: 'poi', id: h.id, ref: h.ref, node: h.node }; bestDist = d; }
      });
      if (isVisible('transit')) hit.stations.forEach(h => {
        const d = Math.hypot(h.x - worldPt.x, h.y - worldPt.y);
        if (d < tolWorld && d < bestDist) { best = { kind: 'station', id: h.id, ref: h.ref, node: h.node }; bestDist = d; }
      });
      if (best) return best;
      // then regions (area features), only if that layer is shown
      if (isVisible('regions')) {
        for (const h of hit.regions) {
          if (pointInPoly(worldPt.x, worldPt.y, h.poly)) {
            return { kind: 'region', id: h.id, ref: h.ref, node: h.node };
          }
        }
      }
      return null;
    }

    function nodeFor(kind, id) {
      const map = kind === 'region' ? hit.regions : kind === 'poi' ? hit.pois : hit.stations;
      const h = map.find(x => x.id === id);
      return h ? h.node : null;
    }

    /* ---------- LAYER VISIBILITY ---------- */
    function setLayer(id, visible) {
      const g = layers[id];
      if (g) g.style.display = visible ? '' : 'none';
    }

    /* ---------- ZOOM-DEPENDENT STYLING (label declutter, scale) ---------- */
    function update(zoom) {
      // counter-scale point markers & label sizes so they don't balloon
      const k = Math.max(0.32, Math.min(1.25, 1 / Math.sqrt(zoom)));
      svgEl.style.setProperty('--zoom-k', k);

      // Greedy label declutter: higher priority + closer to view center wins.
      // We do screen-space overlap rejection using each label's screen box.
      const shown = [];
      // sort: priority desc, then min asc (region labels first)
      const sorted = labelRegistry.slice().sort((a, b) => b.priority - a.priority || a.min - b.min);
      for (const L of sorted) {
        if (zoom < L.min || zoom > L.max) { L.el.style.display = 'none'; continue; }
        const box = labelBox(L, k);
        let collide = false;
        for (const s of shown) { if (overlap(box, s)) { collide = true; break; } }
        if (collide) { L.el.style.display = 'none'; }
        else { L.el.style.display = ''; shown.push(box); }
      }
    }
    function labelBox(L, k) {
      // approx text box in screen px around its anchor
      const sp = engineRef ? engineRef.worldToScreen(L.x, L.y) : { x: L.x, y: L.y };
      const w = L.text.length * 6.4 + 6, h = 15;
      return { x: sp.x - 2, y: sp.y - h + 3, w, h, _L: L };
    }
    function overlap(a, b) {
      return !(a.x + a.w < b.x || b.x + b.w < a.x || a.y + a.h < b.y || b.y + b.h < a.y);
    }

    let engineRef = null;
    function bindEngine(engine) { engineRef = engine; }

    return {
      layers, setLayer, pick, nodeFor, update, bindEngine,
      smoothD, polyD,
      routeOverlay, lineNodes,
      addLabel
    };
  }

  window.MapRenderer = Renderer;
  window.MapRenderer._helpers = { polyD, smoothD };
})();
