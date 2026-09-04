/* Fernwood Botanicals — product catalog data + inline SVG plant renderer.
 * Pure data + presentation helpers. No frameworks, no external assets. */

(function (global) {
  'use strict';

  /* ---------------------------------------------------------------------------
   * SVG art helpers
   * Each plant/product renders a varied inline SVG built from parameters so the
   * grid looks lush and no two cards are identical. Returns an SVG string.
   * ------------------------------------------------------------------------- */

  var POT_COLORS = {
    terracotta: { body: '#c97a54', shade: '#b15f3c', rim: '#dd9270' },
    clay: { body: '#d9b08c', shade: '#c4955f', rim: '#e7c6a6' },
    sage: { body: '#9caf88', shade: '#849a6d', rim: '#b3c4a2' },
    stone: { body: '#b9b3a7', shade: '#a39c8d', rim: '#cdc8bd' },
    forest: { body: '#3f5e3a', shade: '#314a2d', rim: '#56784f' },
    charcoal: { body: '#4a4f4d', shade: '#393d3b', rim: '#626966' }
  };

  function pot(potKey, cx, topY, w, h) {
    var c = POT_COLORS[potKey] || POT_COLORS.terracotta;
    var halfTop = w / 2;
    var halfBottom = w * 0.36;
    var rimH = h * 0.16;
    var lx = cx - halfTop, rx = cx + halfTop;
    var blx = cx - halfBottom, brx = cx + halfBottom;
    var bottomY = topY + h;
    return (
      '<path d="M' + lx + ' ' + topY + ' L' + rx + ' ' + topY +
      ' L' + brx + ' ' + bottomY + ' L' + blx + ' ' + bottomY + ' Z" fill="' + c.body + '"/>' +
      '<path d="M' + cx + ' ' + topY + ' L' + rx + ' ' + topY +
      ' L' + brx + ' ' + bottomY + ' L' + cx + ' ' + bottomY + ' Z" fill="' + c.shade + '" opacity="0.5"/>' +
      '<rect x="' + (lx - 3) + '" y="' + (topY - rimH) + '" width="' + (w + 6) + '" height="' + rimH +
      '" rx="' + (rimH / 2) + '" fill="' + c.rim + '"/>'
    );
  }

  function leaf(cx, cy, len, wide, angle, color, vein) {
    var t = 'rotate(' + angle + ' ' + cx + ' ' + cy + ')';
    var tipY = cy - len;
    var midY = cy - len * 0.5;
    var path =
      'M' + cx + ' ' + cy +
      ' C' + (cx - wide) + ' ' + midY + ' ' + (cx - wide * 0.4) + ' ' + tipY + ' ' + cx + ' ' + tipY +
      ' C' + (cx + wide * 0.4) + ' ' + tipY + ' ' + (cx + wide) + ' ' + midY + ' ' + cx + ' ' + cy + ' Z';
    var out = '<g transform="' + t + '">' +
      '<path d="' + path + '" fill="' + color + '"/>';
    if (vein) {
      out += '<path d="M' + cx + ' ' + cy + ' L' + cx + ' ' + tipY + '" stroke="' + vein +
        '" stroke-width="1.2" opacity="0.5" fill="none"/>';
    }
    out += '</g>';
    return out;
  }

  function frond(cx, cy, len, angle, color) {
    var t = 'rotate(' + angle + ' ' + cx + ' ' + cy + ')';
    var out = '<g transform="' + t + '">';
    out += '<path d="M' + cx + ' ' + cy + ' L' + cx + ' ' + (cy - len) +
      '" stroke="' + color + '" stroke-width="3" stroke-linecap="round"/>';
    var segs = 7;
    for (var i = 1; i <= segs; i++) {
      var fy = cy - (len * i) / (segs + 1);
      var fl = 14 * (1 - i / (segs + 2));
      out += '<path d="M' + cx + ' ' + fy + ' q' + (-fl) + ' ' + (-fl * 0.5) + ' ' + (-fl * 1.1) + ' ' + (fl * 0.2) +
        '" stroke="' + color + '" stroke-width="2.4" fill="none" stroke-linecap="round"/>';
      out += '<path d="M' + cx + ' ' + fy + ' q' + fl + ' ' + (-fl * 0.5) + ' ' + (fl * 1.1) + ' ' + (fl * 0.2) +
        '" stroke="' + color + '" stroke-width="2.4" fill="none" stroke-linecap="round"/>';
    }
    out += '</g>';
    return out;
  }

  function svgWrap(inner, label) {
    return (
      '<svg class="plant-svg" viewBox="0 0 200 200" role="img" aria-label="' +
      (label || 'plant illustration') +
      '" xmlns="http://www.w3.org/2000/svg">' +
      '<defs><radialGradient id="blobg" cx="50%" cy="40%" r="70%">' +
      '<stop offset="0%" stop-color="#eef3e6"/><stop offset="100%" stop-color="#dde7d0"/>' +
      '</radialGradient></defs>' +
      '<path d="M40 120 C20 80 60 30 110 36 C168 42 192 96 168 138 C150 170 70 178 40 120 Z" fill="url(#blobg)"/>' +
      inner +
      '</svg>'
    );
  }

  /* ---- Per-style plant builders ---- */

  function artUpright(g1, g2, potKey) {
    var s = '';
    s += leaf(100, 120, 78, 30, -22, g1, g2);
    s += leaf(100, 120, 88, 30, 0, g2, g1);
    s += leaf(100, 120, 78, 30, 22, g1, g2);
    s += leaf(100, 120, 60, 24, -42, g2, g1);
    s += leaf(100, 120, 60, 24, 42, g2, g1);
    s += pot(potKey, 100, 120, 56, 56);
    return s;
  }

  function artBushy(g1, g2, potKey) {
    var s = '';
    var angles = [-55, -35, -15, 0, 15, 35, 55];
    for (var i = 0; i < angles.length; i++) {
      var col = i % 2 === 0 ? g1 : g2;
      s += leaf(100, 124, 56 + (i % 3) * 8, 26, angles[i], col, g1);
    }
    s += pot(potKey, 100, 124, 58, 52);
    return s;
  }

  function artPalm(g1, g2, potKey) {
    var s = '';
    s += frond(100, 124, 80, -34, g1);
    s += frond(100, 124, 92, 0, g2);
    s += frond(100, 124, 80, 34, g1);
    s += frond(100, 124, 66, -60, g2);
    s += frond(100, 124, 66, 60, g2);
    s += pot(potKey, 100, 124, 52, 52);
    return s;
  }

  function artTrailing(g1, g2, potKey) {
    var s = '';
    s += pot(potKey, 100, 108, 60, 50);
    // vines spilling over the rim
    for (var d = -1; d <= 1; d += 2) {
      var x0 = 100 + d * 26;
      s += '<path d="M' + x0 + ' 108 q' + (d * 26) + ' 30 ' + (d * 10) + ' 70" stroke="' + g1 +
        '" stroke-width="3" fill="none" stroke-linecap="round"/>';
      for (var k = 0; k < 5; k++) {
        var ly = 116 + k * 13;
        var lx = x0 + d * (6 + k * 3);
        s += '<ellipse cx="' + lx + '" cy="' + ly + '" rx="7" ry="5" fill="' + (k % 2 ? g2 : g1) + '"/>';
      }
    }
    s += leaf(100, 108, 40, 18, 0, g2, g1);
    s += leaf(100, 108, 36, 16, -28, g1, g2);
    s += leaf(100, 108, 36, 16, 28, g1, g2);
    return s;
  }

  function artSucculent(g1, g2, potKey) {
    var s = '';
    var rings = [
      { r: 8, n: 0, len: 26 },
      { r: 0, n: 6, len: 30 },
      { r: 0, n: 8, len: 22 }
    ];
    s += pot(potKey, 100, 132, 64, 44);
    // rosette
    var layers = [{ n: 8, len: 34, w: 13, c: g1 }, { n: 6, len: 24, w: 11, c: g2 }, { n: 4, len: 14, w: 9, c: g1 }];
    for (var li = 0; li < layers.length; li++) {
      var L = layers[li];
      for (var j = 0; j < L.n; j++) {
        var a = (360 / L.n) * j + (li * 22);
        s += leaf(100, 130, L.len, L.w, a, L.c, '');
      }
    }
    s += '<circle cx="100" cy="128" r="6" fill="' + g2 + '"/>';
    return s;
  }

  function artCactus(g1, g2, potKey) {
    var s = '';
    s += pot(potKey, 100, 136, 60, 40);
    s += '<rect x="86" y="74" width="28" height="66" rx="14" fill="' + g1 + '"/>';
    s += '<rect x="62" y="96" width="20" height="40" rx="10" fill="' + g2 + '"/>';
    s += '<rect x="118" y="88" width="20" height="48" rx="10" fill="' + g2 + '"/>';
    s += '<path d="M62 116 q-8 -16 0 -24" stroke="' + g1 + '" stroke-width="8" fill="none" stroke-linecap="round"/>';
    s += '<path d="M138 108 q8 -16 0 -24" stroke="' + g1 + '" stroke-width="8" fill="none" stroke-linecap="round"/>';
    // spine dots
    for (var sp = 0; sp < 6; sp++) {
      s += '<circle cx="100" cy="' + (84 + sp * 9) + '" r="1.6" fill="#eef3e6"/>';
    }
    s += '<circle cx="100" cy="72" r="6" fill="#e88aa6"/>'; // little bloom
    return s;
  }

  function artFern(g1, g2, potKey) {
    var s = '';
    var angles = [-50, -28, -10, 10, 28, 50];
    for (var i = 0; i < angles.length; i++) {
      s += frond(100, 126, 70 + (i % 2) * 12, angles[i], i % 2 ? g2 : g1);
    }
    s += pot(potKey, 100, 126, 52, 50);
    return s;
  }

  /* ---- Non-plant product art ---- */

  function artPlanter(g1, g2, potKey) {
    var c = POT_COLORS[potKey] || POT_COLORS.terracotta;
    var s = '';
    s += pot(potKey, 100, 92, 84, 78);
    s += '<ellipse cx="100" cy="92" rx="46" ry="12" fill="' + c.shade + '" opacity="0.6"/>';
    s += '<ellipse cx="100" cy="90" rx="40" ry="9" fill="#1f2a1c" opacity="0.35"/>';
    return s;
  }

  function artTool(kind, g1, g2) {
    var s = '';
    if (kind === 'trowel') {
      s += '<rect x="92" y="40" width="16" height="56" rx="8" fill="#9caf88"/>';
      s += '<path d="M78 96 q22 60 44 0 Z" fill="#b9b3a7"/>';
      s += '<path d="M78 96 q22 60 44 0" fill="none" stroke="#8a857a" stroke-width="2"/>';
    } else if (kind === 'shears') {
      s += '<path d="M70 60 L110 120" stroke="#62696e" stroke-width="7" stroke-linecap="round"/>';
      s += '<path d="M130 60 L90 120" stroke="#62696e" stroke-width="7" stroke-linecap="round"/>';
      s += '<circle cx="84" cy="138" r="12" fill="none" stroke="#c97a54" stroke-width="7"/>';
      s += '<circle cx="116" cy="138" r="12" fill="none" stroke="#c97a54" stroke-width="7"/>';
    } else if (kind === 'can') {
      s += '<rect x="64" y="86" width="56" height="46" rx="10" fill="#9caf88"/>';
      s += '<path d="M120 96 L160 70 L162 78 L126 110 Z" fill="#849a6d"/>';
      s += '<rect x="78" y="66" width="22" height="24" rx="8" fill="#849a6d"/>';
      s += '<path d="M158 66 q8 0 8 8" stroke="#849a6d" stroke-width="4" fill="none"/>';
      s += '<circle cx="166" cy="66" r="6" fill="#b3c4a2"/>';
    }
    return s;
  }

  /* renderArt: dispatch by style key, return full SVG string. */
  function renderArt(style, p1, p2, potKey, label) {
    var inner;
    switch (style) {
      case 'upright': inner = artUpright(p1, p2, potKey); break;
      case 'bushy': inner = artBushy(p1, p2, potKey); break;
      case 'palm': inner = artPalm(p1, p2, potKey); break;
      case 'trailing': inner = artTrailing(p1, p2, potKey); break;
      case 'succulent': inner = artSucculent(p1, p2, potKey); break;
      case 'cactus': inner = artCactus(p1, p2, potKey); break;
      case 'fern': inner = artFern(p1, p2, potKey); break;
      case 'planter': inner = artPlanter(p1, p2, potKey); break;
      case 'trowel': inner = artTool('trowel'); break;
      case 'shears': inner = artTool('shears'); break;
      case 'can': inner = artTool('can'); break;
      default: inner = artUpright(p1, p2, potKey);
    }
    return svgWrap(inner, label);
  }

  /* ---------------------------------------------------------------------------
   * Product catalog
   * ------------------------------------------------------------------------- */

  var SIZE_VARIANTS = [
    { label: 'Small (4")', delta: 0 },
    { label: 'Medium (6")', delta: 14 },
    { label: 'Large (10")', delta: 38 }
  ];

  var PRODUCTS = [
    {
      id: 'monstera',
      name: 'Monstera Deliciosa',
      botanical: 'Monstera deliciosa',
      price: 38,
      category: 'Indoor',
      light: 'Bright indirect',
      water: 'Weekly',
      added: 14,
      desc: 'The iconic Swiss cheese plant. Dramatic split leaves that grow larger and more fenestrated with age. Wipe leaves monthly and give it a moss pole to climb.',
      art: { style: 'upright', p1: '#3f6b3a', p2: '#56864f', pot: 'terracotta' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'snake',
      name: 'Snake Plant',
      botanical: 'Dracaena trifasciata',
      price: 26,
      category: 'Indoor',
      light: 'Low to bright',
      water: 'Every 2-3 weeks',
      added: 12,
      desc: 'Nearly indestructible upright sword-like leaves. Tolerates neglect, low light, and irregular watering. A top air-purifier and perfect for beginners.',
      art: { style: 'upright', p1: '#4a7a45', p2: '#cdd98a', pot: 'charcoal' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'pothos',
      name: 'Golden Pothos',
      botanical: 'Epipremnum aureum',
      price: 18,
      category: 'Indoor',
      light: 'Low to bright indirect',
      water: 'Weekly',
      added: 9,
      desc: 'Fast-growing trailing vine with heart-shaped, marbled leaves. Forgiving and lush — drape it from a shelf or train it up a wall.',
      art: { style: 'trailing', p1: '#5a8a3f', p2: '#bcd06a', pot: 'clay' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'zz',
      name: 'ZZ Plant',
      botanical: 'Zamioculcas zamiifolia',
      price: 30,
      category: 'Indoor',
      light: 'Low to medium',
      water: 'Every 2-3 weeks',
      added: 7,
      desc: 'Glossy, waxy leaflets on arching stems. Thrives on neglect and low light thanks to water-storing rhizomes. The ultimate low-effort statement plant.',
      art: { style: 'bushy', p1: '#2f5a2c', p2: '#447a3f', pot: 'stone' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'fiddle',
      name: 'Fiddle Leaf Fig',
      botanical: 'Ficus lyrata',
      price: 52,
      category: 'Indoor',
      light: 'Bright indirect',
      water: 'Weekly',
      added: 13,
      desc: 'Bold violin-shaped leaves on a sculptural trunk. A designer favorite that rewards consistency — keep it in one bright spot and avoid drafts.',
      art: { style: 'bushy', p1: '#37662f', p2: '#5a9050', pot: 'sage' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'calathea',
      name: 'Calathea Orbifolia',
      botanical: 'Goeppertia orbifolia',
      price: 34,
      category: 'Indoor',
      light: 'Medium indirect',
      water: 'Keep evenly moist',
      added: 6,
      desc: 'Large round leaves striped silver-green that fold up at night. Loves humidity — perfect for bathrooms or grouped with other plants.',
      art: { style: 'bushy', p1: '#3d6b4a', p2: '#9fc7a0', pot: 'sage' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'parlor-palm',
      name: 'Parlor Palm',
      botanical: 'Chamaedorea elegans',
      price: 28,
      category: 'Indoor',
      light: 'Low to medium',
      water: 'Weekly',
      added: 4,
      desc: 'Delicate feathery fronds that bring a soft tropical feel to low-light corners. Pet-friendly and slow-growing.',
      art: { style: 'palm', p1: '#3f6b3a', p2: '#5a8a4a', pot: 'clay' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'boston-fern',
      name: 'Boston Fern',
      botanical: 'Nephrolepis exaltata',
      price: 24,
      category: 'Indoor',
      light: 'Medium indirect',
      water: 'Keep moist',
      added: 5,
      desc: 'Lush arching fronds that thrive in humidity. A classic hanging plant — mist often and never let it dry out completely.',
      art: { style: 'fern', p1: '#3a7a3a', p2: '#6fae5a', pot: 'forest' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'lavender',
      name: 'English Lavender',
      botanical: 'Lavandula angustifolia',
      price: 16,
      category: 'Outdoor',
      light: 'Full sun',
      water: 'Low, drought-tolerant',
      added: 8,
      desc: 'Fragrant silvery foliage topped with purple blooms. Pollinator magnet that loves sun and well-drained soil. Drought-tolerant once established.',
      art: { style: 'bushy', p1: '#7f8f6a', p2: '#9c84c4', pot: 'stone' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'japanese-maple',
      name: 'Japanese Maple',
      botanical: 'Acer palmatum',
      price: 68,
      category: 'Outdoor',
      light: 'Part shade',
      water: 'Regular',
      added: 11,
      desc: 'Elegant lacy foliage that turns fiery red in autumn. A slow-growing ornamental for patios and shaded borders. Shelter from harsh afternoon sun.',
      art: { style: 'bushy', p1: '#a64b3a', p2: '#c97a54', pot: 'charcoal' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'rosemary',
      name: 'Rosemary',
      botanical: 'Salvia rosmarinus',
      price: 14,
      category: 'Outdoor',
      light: 'Full sun',
      water: 'Low',
      added: 3,
      desc: 'Aromatic evergreen herb with needle-like leaves. Equally at home in the kitchen garden and the flower border. Loves sun and dry feet.',
      art: { style: 'upright', p1: '#4a6b48', p2: '#6f8f5a', pot: 'terracotta' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'echeveria',
      name: 'Echeveria Rosette',
      botanical: 'Echeveria elegans',
      price: 12,
      category: 'Succulents & Cacti',
      light: 'Bright direct',
      water: 'Every 2-3 weeks',
      added: 10,
      desc: 'Symmetrical pale-blue rosette that blushes pink in bright light. Store water in plump leaves — let soil dry fully between drinks.',
      art: { style: 'succulent', p1: '#8fb9a0', p2: '#c8d8c0', pot: 'clay' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'aloe',
      name: 'Aloe Vera',
      botanical: 'Aloe barbadensis',
      price: 18,
      category: 'Succulents & Cacti',
      light: 'Bright direct',
      water: 'Every 2-3 weeks',
      added: 6,
      desc: 'Architectural fleshy spears with soothing gel inside. A practical windowsill succulent — water deeply but infrequently in gritty soil.',
      art: { style: 'succulent', p1: '#5a8a5a', p2: '#9fc78a', pot: 'sage' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'golden-barrel',
      name: 'Golden Barrel Cactus',
      botanical: 'Echinocactus grusonii',
      price: 22,
      category: 'Succulents & Cacti',
      light: 'Full sun',
      water: 'Monthly',
      added: 2,
      desc: 'A round, ribbed cactus crowned with golden spines. Slow and sculptural — give it the sunniest spot and barely any water in winter.',
      art: { style: 'cactus', p1: '#6f9a4a', p2: '#5a8a3f', pot: 'terracotta' },
      variantType: 'size',
      variants: SIZE_VARIANTS
    },
    {
      id: 'terracotta-pot',
      name: 'Hand-Thrown Terracotta Pot',
      botanical: 'Glazed stoneware',
      price: 24,
      category: 'Pots & Planters',
      light: '—',
      water: '—',
      added: 7,
      desc: 'Breathable hand-thrown terracotta with a drainage hole and matching saucer. Develops a beautiful patina over time. Sold empty.',
      art: { style: 'planter', p1: '#c97a54', p2: '#b15f3c', pot: 'terracotta' },
      variantType: 'color',
      variants: [
        { label: 'Terracotta', delta: 0 },
        { label: 'Sage', delta: 0 },
        { label: 'Charcoal', delta: 4 }
      ]
    },
    {
      id: 'stoneware-planter',
      name: 'Glazed Stoneware Planter',
      botanical: 'Reactive glaze ceramic',
      price: 36,
      category: 'Pots & Planters',
      light: '—',
      water: '—',
      added: 9,
      desc: 'A weighty reactive-glaze planter with a self-watering insert. Modern rounded silhouette that suits trailing and upright plants alike.',
      art: { style: 'planter', p1: '#9caf88', p2: '#849a6d', pot: 'sage' },
      variantType: 'color',
      variants: [
        { label: 'Sage', delta: 0 },
        { label: 'Stone', delta: 0 },
        { label: 'Forest', delta: 6 }
      ]
    },
    {
      id: 'trowel',
      name: 'Forged Hand Trowel',
      botanical: 'Stainless & ash wood',
      price: 19,
      category: 'Tools',
      light: '—',
      water: '—',
      added: 4,
      desc: 'A balanced stainless trowel with an oiled ash handle and leather hanging loop. Built to last a lifetime of repotting and bedding.',
      art: { style: 'trowel' },
      variantType: 'size',
      variants: [
        { label: 'Standard', delta: 0 },
        { label: 'Narrow', delta: 0 }
      ]
    },
    {
      id: 'shears',
      name: 'Bypass Pruning Shears',
      botanical: 'Carbon steel blade',
      price: 28,
      category: 'Tools',
      light: '—',
      water: '—',
      added: 5,
      desc: 'Razor-sharp bypass blades for clean cuts that heal fast. Spring-loaded action and a safety lock. Keep them sharp and they will reward you.',
      art: { style: 'shears' },
      variantType: 'size',
      variants: [
        { label: 'Standard', delta: 0 },
        { label: 'Compact', delta: 0 }
      ]
    },
    {
      id: 'watering-can',
      name: 'Long-Spout Watering Can',
      botanical: 'Powder-coated steel',
      price: 32,
      category: 'Tools',
      light: '—',
      water: '—',
      added: 8,
      desc: 'A 1.5L powder-coated can with a long precision spout to reach the soil without splashing leaves. Balanced for one-handed pouring.',
      art: { style: 'can' },
      variantType: 'color',
      variants: [
        { label: 'Sage', delta: 0 },
        { label: 'Clay', delta: 0 },
        { label: 'Charcoal', delta: 3 }
      ]
    }
  ];

  var CATEGORIES = ['Indoor', 'Outdoor', 'Succulents & Cacti', 'Pots & Planters', 'Tools'];

  global.FernwoodData = {
    products: PRODUCTS,
    categories: CATEGORIES,
    renderArt: function (p) {
      return renderArt(p.art.style, p.art.p1, p.art.p2, p.art.pot, p.name);
    },
    renderArtRaw: renderArt
  };
})(window);
