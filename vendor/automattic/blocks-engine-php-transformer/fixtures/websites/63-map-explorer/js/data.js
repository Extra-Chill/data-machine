/* =========================================================
   VAELORA ATLAS — World Data
   A hand-authored fictional archipelago nation.
   All geometry is in "world units" on a 1000 x 700 canvas.
   1 world unit ≈ 0.12 km (so the map spans ~120 km E-W).
   No external data, no map tiles — every coordinate below
   was drawn by hand to form a coherent little country.
   ========================================================= */
(function () {
  'use strict';

  // World bounds (the full extent every feature lives inside)
  const WORLD = { x: 0, y: 0, w: 1000, h: 700 };

  // Scale: how many real kilometres one world unit represents.
  const KM_PER_UNIT = 0.12;

  /* ---------------------------------------------------------
     REGIONS (filled polygons) — provinces of Vaelora.
     Points are [x,y] pairs. Authored so coastlines interlock
     and the three main islands read clearly.
     --------------------------------------------------------- */
  const regions = [
    {
      id: 'sundermark', name: 'Sundermark', kind: 'region',
      island: 'Maelan', capital: 'Halethorn',
      pop: 412600, area: 'Northern uplands & terraced vineyards',
      blurb: 'The breadbasket province of the main island, Maelan. Sundermark’s terraced hills above the Aevish Sound have grown the pale "halefrost" grape for nine centuries; its capital Halethorn is the seat of the Vaeloran Assembly.',
      color: '#7fae6b',
      poly: [
        [180, 70], [300, 58], [400, 84], [452, 140], [430, 196],
        [470, 240], [438, 300], [360, 318], [300, 286], [250, 300],
        [196, 268], [168, 200], [150, 130]
      ]
    },
    {
      id: 'aevenport', name: 'Aevenport', kind: 'region',
      island: 'Maelan', capital: 'Aeven',
      pop: 884300, area: 'Harbour metropolis & financial quarter',
      blurb: 'Vaelora’s largest city-province wraps the deep natural harbour of Aeven Bay. A dense lattice of canals, the Tramway, and the glass spires of the Mercantile Row make it the beating heart of the republic.',
      color: '#c8923f',
      poly: [
        [438, 300], [470, 240], [560, 250], [612, 300], [628, 372],
        [590, 430], [512, 452], [452, 430], [420, 372], [400, 330],
        [360, 318]
      ]
    },
    {
      id: 'morrowfen', name: 'Morrowfen', kind: 'region',
      island: 'Maelan', capital: 'Quillet',
      pop: 198400, area: 'Coastal wetlands & reed marshes',
      blurb: 'A low, misty country of tidal channels and reed-cutters on Maelan’s south coast. The Morrowfen marshes shelter the rare silver heron and the floating market town of Quillet, built on pontoons.',
      color: '#5e9c8f',
      poly: [
        [452, 430], [590, 430], [628, 372], [690, 400], [712, 470],
        [660, 540], [560, 566], [470, 548], [420, 500], [420, 372]
      ]
    },
    {
      id: 'kelvarne', name: 'Kelvarne', kind: 'region',
      island: 'Maelan', capital: 'Brandruil',
      pop: 256100, area: 'Western forests & timber coast',
      blurb: 'The rugged, rain-soaked west of Maelan, cloaked in the ancient Kelvarne pinewoods. Logging camps and the granite-walled town of Brandruil cling to the steep Tarrow Coast.',
      color: '#4f8f6c',
      poly: [
        [150, 130], [168, 200], [196, 268], [250, 300], [300, 286],
        [300, 360], [340, 420], [300, 500], [220, 520], [150, 470],
        [110, 380], [96, 280], [110, 190]
      ]
    },
    {
      id: 'taleth', name: 'Taleth Isle', kind: 'region',
      island: 'Taleth', capital: 'Sythe',
      pop: 74200, area: 'Volcanic island & black-sand coast',
      blurb: 'A young volcanic island off the south-east, dominated by the smoking cone of Mount Vey. Its black-sand beaches and geothermal baths draw pilgrims to the cliff-town of Sythe.',
      color: '#9a6b5e',
      poly: [
        [760, 470], [840, 452], [902, 500], [920, 576], [876, 636],
        [792, 648], [732, 600], [726, 528]
      ]
    },
    {
      id: 'orrin', name: 'Orrin Holm', kind: 'region',
      island: 'Orrin', capital: 'Dunmere',
      pop: 39800, area: 'Windswept northern skerries',
      blurb: 'A scatter of low, heather-clad skerries in the cold northern reach. Orrin Holm’s lighthouse keepers and puffin colonies endure the gales that gave the nation its sailing reputation.',
      color: '#6e8aa8',
      poly: [
        [560, 64], [648, 52], [712, 90], [724, 150], [684, 196],
        [612, 200], [556, 160], [540, 104]
      ]
    }
  ];

  /* ---------------------------------------------------------
     COASTLINE GLOW — a single outer ring approximating the
     archipelago halo, used to paint shallow water.
     --------------------------------------------------------- */
  // (coast is derived from region polygons at render time)

  /* ---------------------------------------------------------
     RIVERS (polylines) — flow from uplands to the sea.
     --------------------------------------------------------- */
  const rivers = [
    {
      id: 'aevish', name: 'River Aevish', length: 41,
      path: [[300, 58], [322, 130], [360, 200], [400, 260], [452, 320],
             [500, 380], [540, 430]]
    },
    {
      id: 'tarrow', name: 'Tarrow Water', length: 28,
      path: [[196, 268], [220, 330], [240, 400], [230, 460], [200, 510]]
    },
    {
      id: 'quill', name: 'Quill Brook', length: 19,
      path: [[438, 300], [460, 360], [500, 420], [540, 470], [560, 540]]
    },
    {
      id: 'vey', name: 'Vey Run', length: 11,
      path: [[840, 540], [820, 570], [792, 600], [760, 618]]
    }
  ];

  // A few inland lakes (closed polygons rendered with the water layer)
  const lakes = [
    { id: 'mirrowmere', name: 'Mirrowmere', poly: [[330, 210], [372, 204], [392, 232], [378, 266], [338, 270], [318, 240]] },
    { id: 'taen', name: 'Lake Taen', poly: [[180, 360], [214, 352], [232, 378], [220, 410], [188, 414], [168, 388]] }
  ];

  /* ---------------------------------------------------------
     ROADS / TRAILS (polylines).
     class: 'highway' (national route) or 'trail' (footpath).
     --------------------------------------------------------- */
  const roads = [
    { id: 'r1', name: 'Route 1 — Coast Highway', cls: 'highway',
      path: [[210, 110], [300, 130], [380, 170], [452, 230], [512, 300],
             [560, 372], [540, 460], [470, 520]] },
    { id: 'r2', name: 'Route 2 — Vintners’ Road', cls: 'highway',
      path: [[300, 130], [330, 200], [360, 270], [400, 330], [452, 380]] },
    { id: 'r7', name: 'Route 7 — West Forest Run', cls: 'highway',
      path: [[210, 110], [150, 180], [130, 280], [160, 380], [230, 460]] },
    { id: 't3', name: 'Greywarden Trail', cls: 'trail',
      path: [[250, 300], [300, 330], [320, 380], [300, 430], [260, 470]] },
    { id: 't9', name: 'Heron Walk', cls: 'trail',
      path: [[512, 452], [540, 490], [560, 540], [540, 560]] }
  ];

  /* ---------------------------------------------------------
     TRANSIT — the Vaeloran Tramway. Lines have an ordered
     list of station ids; stations have coordinates. The
     routing tool walks these line definitions.
     --------------------------------------------------------- */
  const stations = [
    { id: 's-hale',   name: 'Halethorn Central', x: 312, y: 132 },
    { id: 's-vint',   name: 'Vintners’ Cross',   x: 372, y: 196 },
    { id: 's-mirr',   name: 'Mirrowmere',         x: 388, y: 248 },
    { id: 's-quay',   name: 'Aeven Quay',         x: 470, y: 300 },
    { id: 's-merc',   name: 'Mercantile Row',     x: 512, y: 340 },
    { id: 's-spire',  name: 'Spire Plaza',        x: 556, y: 360 },
    { id: 's-harb',   name: 'Harbour Gate',       x: 590, y: 408 },
    { id: 's-fen',    name: 'Fenmarket',          x: 540, y: 470 },
    { id: 's-quil',   name: 'Quillet Pontoon',    x: 560, y: 540 },
    { id: 's-bran',   name: 'Brandruil Yard',     x: 230, y: 440 },
    { id: 's-tarr',   name: 'Tarrow Halt',        x: 240, y: 400 },
    { id: 's-grey',   name: 'Greywarden',         x: 300, y: 360 },
    { id: 's-west',   name: 'West Junction',      x: 360, y: 330 },
    { id: 's-bay',    name: 'Bayside',            x: 452, y: 392 }
  ];

  const lines = [
    { id: 'l-amber', name: 'Amber Line', color: '#e0a02a',
      stops: ['s-hale', 's-vint', 's-mirr', 's-quay', 's-merc', 's-spire', 's-harb'] },
    { id: 'l-teal', name: 'Teal Line', color: '#1fb6a6',
      stops: ['s-quay', 's-bay', 's-fen', 's-quil'] },
    { id: 'l-violet', name: 'Violet Line', color: '#9b6fe0',
      stops: ['s-bran', 's-tarr', 's-grey', 's-west', 's-quay'] }
  ];

  /* ---------------------------------------------------------
     POINTS OF INTEREST.
     cat drives the icon + legend grouping.
     --------------------------------------------------------- */
  const pois = [
    { id: 'p-assembly', name: 'Hall of the Assembly', cat: 'civic', x: 318, y: 120, region: 'sundermark',
      blurb: 'The 11th-century basalt seat of Vaelora’s elected Assembly, crowned by the famous Verdigris Dome.', stat: 'Built 1144 · 240 seats' },
    { id: 'p-harbour', name: 'Aeven Grand Harbour', cat: 'port', x: 588, y: 408, region: 'aevenport',
      blurb: 'The deepest natural harbour in the northern sea; 1,100 vessels berth here at peak trade season.', stat: 'Depth 22 m · est. 9th c.' },
    { id: 'p-lighthouse', name: 'Dunmere Light', cat: 'landmark', x: 660, y: 120, region: 'orrin',
      blurb: 'A 48-metre cast-iron lighthouse warning ships off the Orrin skerries since 1871. Still keeper-tended.', stat: 'Range 31 km · 1 keeper' },
    { id: 'p-mountvey', name: 'Mount Vey', cat: 'peak', x: 830, y: 540, region: 'taleth',
      blurb: 'An active stratovolcano, 1,612 m. Last erupted 1903; today its flanks heat the Sythe geothermal baths.', stat: '1,612 m · active' },
    { id: 'p-vineyard', name: 'Halefrost Vineyards', cat: 'park', x: 360, y: 150, region: 'sundermark',
      blurb: 'Nine centuries of terraced vines producing Vaelora’s flagship pale wine. Open for tastings in autumn.', stat: '1,900 ha · since 1098' },
    { id: 'p-university', name: 'Aeven Maritime College', cat: 'civic', x: 540, y: 332, region: 'aevenport',
      blurb: 'The republic’s premier school of navigation, cartography, and naval engineering.', stat: 'Founded 1602 · 6,400 students' },
    { id: 'p-market', name: 'Quillet Floating Market', cat: 'civic', x: 558, y: 536, region: 'morrowfen',
      blurb: 'A market town built entirely on pontoons; vendors trade reed-crafts and eels from flat-bottomed skiffs.', stat: 'Pop. 4,200 · all-floating' },
    { id: 'p-heron', name: 'Silver Heron Reserve', cat: 'park', x: 640, y: 480, region: 'morrowfen',
      blurb: 'A protected tidal wetland and the last nesting ground of the silver heron. Boardwalks and hides for visitors.', stat: '9,800 ha · protected 1955' },
    { id: 'p-forest', name: 'Kelvarne Pinewood', cat: 'park', x: 180, y: 300, region: 'kelvarne',
      blurb: 'Old-growth pine forest, some trees over 600 years old. Crossed by the Greywarden Trail.', stat: 'Old-growth · 41,000 ha' },
    { id: 'p-fort', name: 'Brandruil Fort', cat: 'landmark', x: 232, y: 470, region: 'kelvarne',
      blurb: 'A granite coastal fortress built to guard the Tarrow Coast against raiders. Now a museum.', stat: 'Built 1488 · museum' },
    { id: 'p-baths', name: 'Sythe Geothermal Baths', cat: 'landmark', x: 800, y: 560, region: 'taleth',
      blurb: 'Volcanic hot springs terraced into the cliff above the black-sand bay. A place of healing since antiquity.', stat: '38–44 °C · year-round' },
    { id: 'p-spire', name: 'Mercantile Spire', cat: 'landmark', x: 552, y: 348, region: 'aevenport',
      blurb: 'The tallest building in Vaelora, a 188 m glass tower housing the merchant exchanges.', stat: '188 m · 44 floors' }
  ];

  // POI category metadata (icon glyph drawn in render.js)
  const poiCats = {
    civic:    { label: 'Civic & culture', color: '#5b9dff' },
    port:     { label: 'Ports',            color: '#34c4e0' },
    landmark: { label: 'Landmarks',        color: '#e0712a' },
    peak:     { label: 'Peaks',            color: '#b07bff' },
    park:     { label: 'Parks & reserves', color: '#41d68a' }
  };

  // Expose
  window.VAELORA = {
    WORLD, KM_PER_UNIT,
    regions, rivers, lakes, roads, stations, lines, pois, poiCats,
    stationById(id) { return stations.find(s => s.id === id); },
    regionById(id)  { return regions.find(r => r.id === id); },
    poiById(id)     { return pois.find(p => p.id === id); },
    lineById(id)    { return lines.find(l => l.id === id); },
    // Every searchable/gazetteer entry, unified.
    allPlaces() {
      const out = [];
      regions.forEach(r => out.push({
        id: r.id, name: r.name, type: 'Region', kind: 'region',
        x: centroid(r.poly)[0], y: centroid(r.poly)[1],
        sub: r.area, blurb: r.blurb, ref: r
      }));
      pois.forEach(p => out.push({
        id: p.id, name: p.name, type: poiCats[p.cat].label.replace(/s$/, ''), kind: 'poi',
        x: p.x, y: p.y, sub: p.stat, blurb: p.blurb, ref: p
      }));
      stations.forEach(s => out.push({
        id: s.id, name: s.name, type: 'Tram station', kind: 'station',
        x: s.x, y: s.y, sub: linesForStation(s.id).map(l => l.name).join(' · '),
        blurb: 'Tramway station served by ' + linesForStation(s.id).map(l => l.name).join(', ') + '.', ref: s
      }));
      return out;
    },
    centroid, linesForStation,
    // bounding box of any [x,y] point list
    bbox(points) {
      let minx = Infinity, miny = Infinity, maxx = -Infinity, maxy = -Infinity;
      points.forEach(([x, y]) => {
        if (x < minx) minx = x; if (y < miny) miny = y;
        if (x > maxx) maxx = x; if (y > maxy) maxy = y;
      });
      return { x: minx, y: miny, w: maxx - minx, h: maxy - miny };
    }
  };

  function centroid(poly) {
    let a = 0, cx = 0, cy = 0;
    for (let i = 0, n = poly.length; i < n; i++) {
      const [x0, y0] = poly[i], [x1, y1] = poly[(i + 1) % n];
      const cross = x0 * y1 - x1 * y0;
      a += cross; cx += (x0 + x1) * cross; cy += (y0 + y1) * cross;
    }
    a *= 0.5;
    if (Math.abs(a) < 1e-6) { // fallback: average
      const s = poly.reduce((m, p) => [m[0] + p[0], m[1] + p[1]], [0, 0]);
      return [s[0] / poly.length, s[1] / poly.length];
    }
    return [cx / (6 * a), cy / (6 * a)];
  }

  function linesForStation(id) {
    return lines.filter(l => l.stops.includes(id));
  }
})();
