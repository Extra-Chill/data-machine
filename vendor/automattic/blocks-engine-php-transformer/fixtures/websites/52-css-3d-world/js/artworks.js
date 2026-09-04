/* ============================================================================
   VANTAPOINT — artwork renderers
   ----------------------------------------------------------------------------
   Each function returns an inline SVG string for one artwork. They are
   deterministic (a tiny seeded PRNG) so a piece looks the same on the wall and
   in the directory thumbnail. No external assets, no canvas required.
   ========================================================================== */

window.ARTWORKS = (function () {
  'use strict';

  /* Tiny seeded PRNG (mulberry32) ------------------------------------------- */
  function rng(seed) {
    let a = seed >>> 0;
    return function () {
      a |= 0; a = (a + 0x6D2B79F5) | 0;
      let t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }
  const F = (n) => Math.round(n * 100) / 100;

  function frame(inner, bg) {
    return '<svg viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" ' +
           'preserveAspectRatio="xMidYMid slice" role="img">' +
           '<rect width="400" height="400" fill="' + (bg || '#0a0c10') + '"/>' +
           inner + '</svg>';
  }

  /* --- Slow Weather No. 1 : grid of polarised, colour-shifting squares ----- */
  function auroraGrid() {
    const r = rng(1969), cells = 12, s = 400 / cells;
    let g = '';
    for (let y = 0; y < cells; y++) for (let x = 0; x < cells; x++) {
      const t = (x + y) / (cells * 2);
      const hue = 150 + t * 110 + r() * 40;
      const a = 0.25 + r() * 0.6;
      const rot = (r() - 0.5) * 30;
      g += '<rect x="' + F(x*s+2) + '" y="' + F(y*s+2) + '" width="' + F(s-4) +
           '" height="' + F(s-4) + '" fill="hsl(' + F(hue) + ' 70% 60% / ' + F(a) +
           ')" transform="rotate(' + F(rot) + ' ' + F(x*s+s/2) + ' ' + F(y*s+s/2) + ')"/>';
    }
    return frame('<defs><radialGradient id="aw1" cx="50%" cy="35%" r="75%">' +
      '<stop offset="0%" stop-color="#1a2b3a"/><stop offset="100%" stop-color="#06080c"/>' +
      '</radialGradient></defs><rect width="400" height="400" fill="url(#aw1)"/>' + g);
  }

  /* --- A Tide for a Room : rising blue horizon ---------------------------- */
  function tideClock() {
    const r = rng(1971);
    let wave = 'M0,250';
    for (let x = 0; x <= 400; x += 20) wave += ' L' + x + ',' + F(250 + Math.sin(x/40 + r()) * 8);
    wave += ' L400,400 L0,400 Z';
    let bub = '';
    for (let i = 0; i < 26; i++) bub += '<circle cx="' + F(r()*400) + '" cy="' + F(260 + r()*130) +
      '" r="' + F(1 + r()*3) + '" fill="#bfe9ff" opacity="' + F(0.2 + r()*0.4) + '"/>';
    return frame('<defs><linearGradient id="aw2" x1="0" y1="0" x2="0" y2="1">' +
      '<stop offset="0%" stop-color="#0b3a5c"/><stop offset="100%" stop-color="#0a6a9c"/>' +
      '</linearGradient></defs>' +
      '<path d="' + wave + '" fill="url(#aw2)"/>' +
      '<line x1="0" y1="250" x2="400" y2="250" stroke="#cdeeff" stroke-width="1.2" opacity="0.6"/>' +
      bub, '#0d1318');
  }

  /* --- Morning Index : fan of glass blades catching light ----------------- */
  function prismFan() {
    const blades = 40;
    let g = '';
    for (let i = 0; i < blades; i++) {
      const ang = -70 + (i / (blades - 1)) * 140;
      const hue = 30 + i * 4;
      g += '<g transform="rotate(' + F(ang) + ' 200 400)">' +
           '<rect x="197" y="40" width="6" height="360" fill="hsl(' + F(hue) +
           ' 90% 70% / 0.55)"/></g>';
    }
    return frame('<defs><radialGradient id="aw3" cx="50%" cy="100%" r="90%">' +
      '<stop offset="0%" stop-color="#3a2a10"/><stop offset="100%" stop-color="#08070a"/>' +
      '</radialGradient></defs><rect width="400" height="400" fill="url(#aw3)"/>' + g +
      '<circle cx="200" cy="400" r="14" fill="#ffe9b0"/>');
  }

  /* --- Condensation Portrait : frost bloom -------------------------------- */
  function frostBloom() {
    const r = rng(1976);
    let g = '';
    const cx = 200, cy = 200;
    for (let i = 0; i < 130; i++) {
      const a = r() * Math.PI * 2, len = 30 + r() * 150;
      const x2 = cx + Math.cos(a) * len, y2 = cy + Math.sin(a) * len;
      g += '<line x1="' + cx + '" y1="' + cy + '" x2="' + F(x2) + '" y2="' + F(y2) +
           '" stroke="#dff0ff" stroke-width="' + F(0.4 + r()*1) + '" opacity="' + F(0.15 + r()*0.4) + '"/>';
    }
    let flake = '';
    for (let i = 0; i < 40; i++) flake += '<circle cx="' + F(r()*400) + '" cy="' + F(r()*400) +
      '" r="' + F(r()*1.6) + '" fill="#fff" opacity="' + F(r()*0.5) + '"/>';
    return frame('<radialGradient id="aw4" cx="50%" cy="50%" r="60%">' +
      '<stop offset="0%" stop-color="#16242e"/><stop offset="100%" stop-color="#070b0e"/>' +
      '</radialGradient><rect width="400" height="400" fill="url(#aw4)"/>' + g + flake);
  }

  /* --- Lens of January : melting focus rings ----------------------------- */
  function iceLens() {
    let g = '';
    for (let i = 0; i < 9; i++) {
      const rad = 30 + i * 22;
      g += '<circle cx="200" cy="200" r="' + rad + '" fill="none" stroke="#bfe6ff" stroke-width="' +
           F(3 - i*0.25) + '" opacity="' + F(0.7 - i*0.06) + '"/>';
    }
    let drip = '';
    const r = rng(1979);
    for (let i = 0; i < 8; i++) { const x = F(r()*400);
      drip += '<path d="M' + x + ',300 q4,30 0,60 q-4,-30 0,-60" fill="#9fd4f5" opacity="0.4"/>'; }
    return frame('<radialGradient id="aw5" cx="50%" cy="45%" r="70%">' +
      '<stop offset="0%" stop-color="#1d3340"/><stop offset="100%" stop-color="#060a0d"/>' +
      '</radialGradient><rect width="400" height="400" fill="url(#aw5)"/>' +
      '<circle cx="200" cy="200" r="120" fill="#bfe6ff" opacity="0.08"/>' + g + drip);
  }

  /* --- Eleven Winters : accreting white snow-line ------------------------ */
  function snowGraph() {
    const r = rng(1981);
    let line = 'M0,360';
    for (let x = 0; x <= 400; x += 8) {
      const v = 360 - (Math.sin(x/30) * 0.5 + 0.5) * (60 + r()*120);
      line += ' L' + x + ',' + F(v);
    }
    line += ' L400,400 L0,400 Z';
    return frame('<rect width="400" height="400" fill="#1a1c20"/>' +
      '<path d="' + line + '" fill="#e8edf2" opacity="0.92"/>' +
      '<path d="' + line + '" fill="none" stroke="#fff" stroke-width="1"/>', '#1a1c20');
  }

  /* --- The Forty-Second Room : pendulum field + aligned bar --------------- */
  function pendulumField() {
    let g = '';
    for (let i = 0; i < 24; i++) {
      const x = 30 + (i / 23) * 340;
      const len = 90 + (i % 7) * 28;
      const sw = Math.sin(i * 1.3) * 30;
      g += '<line x1="' + F(x) + '" y1="0" x2="' + F(x+sw) + '" y2="' + F(len) +
           '" stroke="#caa9ff" stroke-width="1" opacity="0.6"/>' +
           '<circle cx="' + F(x+sw) + '" cy="' + F(len) + '" r="5" fill="#e0c6ff"/>';
    }
    return frame('<radialGradient id="aw7" cx="50%" cy="0%" r="100%">' +
      '<stop offset="0%" stop-color="#241a2e"/><stop offset="100%" stop-color="#08060c"/>' +
      '</radialGradient><rect width="400" height="400" fill="url(#aw7)"/>' + g +
      '<rect x="40" y="370" width="320" height="6" fill="#000" opacity="0.55"/>');
  }

  /* --- One Year of Evenings : fan of sunset arcs ------------------------- */
  function longExposure() {
    let g = '';
    for (let i = 0; i < 46; i++) {
      const t = i / 45;
      const peak = 320 - t * 200;
      const hue = 12 + t * 40;
      g += '<path d="M-20,400 Q200,' + F(peak) + ' 420,400" fill="none" stroke="hsl(' +
           F(hue) + ' 90% ' + F(45 + t*15) + '% / ' + F(0.2 + (1-Math.abs(t-0.5)*2)*0.4) +
           ')" stroke-width="1.4"/>';
    }
    return frame('<linearGradient id="aw8" x1="0" y1="0" x2="0" y2="1">' +
      '<stop offset="0%" stop-color="#1a1426"/><stop offset="60%" stop-color="#3a1810"/>' +
      '<stop offset="100%" stop-color="#0a0608"/></linearGradient>' +
      '<rect width="400" height="400" fill="url(#aw8)"/>' + g);
  }

  /* --- Reciprocal Light : two faces in a half mirror -------------------- */
  function mirrorMaze() {
    const head = (cx, fill, op) =>
      '<g opacity="' + op + '"><ellipse cx="' + cx + '" cy="180" rx="55" ry="70" fill="' + fill +
      '"/><ellipse cx="' + cx + '" cy="300" rx="80" ry="60" fill="' + fill + '"/></g>';
    return frame('<linearGradient id="aw9" x1="0" y1="0" x2="1" y2="0">' +
      '<stop offset="0%" stop-color="#1a1620"/><stop offset="50%" stop-color="#2a2436"/>' +
      '<stop offset="100%" stop-color="#1a1620"/></linearGradient>' +
      '<rect width="400" height="400" fill="url(#aw9)"/>' +
      head(140, '#e0a3ff', 0.5) + head(260, '#a3c8ff', 0.5) +
      '<line x1="200" y1="0" x2="200" y2="400" stroke="#fff" stroke-width="0.6" opacity="0.5"/>',
      '#1a1620');
  }

  /* --- A Ceiling of Fog : inverted clouds in a dome ---------------------- */
  function cameraObscura() {
    const r = rng(1999);
    let cl = '';
    for (let i = 0; i < 7; i++) {
      const cx = F(r()*400), cy = F(60 + r()*200), rr = F(40 + r()*70);
      cl += '<ellipse cx="' + cx + '" cy="' + cy + '" rx="' + rr + '" ry="' + F(rr*0.5) +
            '" fill="#8a98a6" opacity="' + F(0.25 + r()*0.3) + '"/>';
    }
    return frame('<defs><radialGradient id="awa" cx="50%" cy="50%" r="55%">' +
      '<stop offset="0%" stop-color="#6e7c8a"/><stop offset="80%" stop-color="#2a3038"/>' +
      '<stop offset="100%" stop-color="#0a0d10"/></radialGradient>' +
      '<clipPath id="dome"><circle cx="200" cy="200" r="190"/></clipPath></defs>' +
      '<rect width="400" height="400" fill="#06080a"/>' +
      '<g clip-path="url(#dome)"><rect width="400" height="400" fill="url(#awa)"/>' + cl +
      '<circle cx="120" cy="120" r="3" fill="#cdd6df"/><circle cx="300" cy="160" r="2.4" fill="#cdd6df"/></g>',
      '#06080a');
  }

  /* --- Index of Missing Stars : negative constellation ------------------- */
  function starPlot() {
    const r = rng(2006);
    let st = '';
    for (let i = 0; i < 110; i++) {
      const x = F(r()*400), y = F(r()*400), s = F(0.6 + r()*2.2);
      st += '<circle cx="' + x + '" cy="' + y + '" r="' + s + '" fill="#ffe9a8" opacity="' +
            F(0.4 + r()*0.6) + '"/>';
    }
    return frame('<rect width="400" height="400" fill="#05060a"/>' +
      '<rect width="400" height="400" fill="#0a0c14"/>' + st, '#05060a');
  }

  /* --- The Last Bulb : single dimming filament -------------------------- */
  function lastBulb() {
    return frame('<defs><radialGradient id="awb" cx="50%" cy="42%" r="40%">' +
      '<stop offset="0%" stop-color="#ffdf9e"/><stop offset="35%" stop-color="#b07e2a" stop-opacity="0.6"/>' +
      '<stop offset="100%" stop-color="#0a0806" stop-opacity="0"/></radialGradient></defs>' +
      '<rect width="400" height="400" fill="#0a0806"/>' +
      '<rect width="400" height="400" fill="url(#awb)"/>' +
      '<line x1="200" y1="0" x2="200" y2="120" stroke="#5a4a30" stroke-width="3"/>' +
      '<ellipse cx="200" cy="170" rx="46" ry="58" fill="none" stroke="#3a3020" stroke-width="2"/>' +
      '<path d="M186,150 q14,18 0,40 q14,-18 28,0 q-14,18 0,-40" fill="none" stroke="#ffe6a0" stroke-width="1.6"/>',
      '#0a0806');
  }

  const map = {
    auroraGrid, tideClock, prismFan, frostBloom, iceLens, snowGraph,
    pendulumField, longExposure, mirrorMaze, cameraObscura, starPlot, lastBulb
  };

  return {
    render(name) {
      const fn = map[name];
      return fn ? fn() : frame('<rect width="400" height="400" fill="#222"/>' +
        '<text x="200" y="200" fill="#888" text-anchor="middle" font-size="20">?</text>');
    }
  };
})();
