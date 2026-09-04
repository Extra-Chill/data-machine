/* Lumen Coffee Roasters — product catalog data + inline SVG illustrations */
(function (global) {
  'use strict';

  // Category color schemes for SVG bag illustrations.
  var SCHEMES = {
    'single-origin': { bag: '#c2703d', band: '#8c4a25', tag: '#f5ece0', ink: '#3a2418' },
    'blend':         { bag: '#5a3a28', band: '#3a2418', tag: '#f5ece0', ink: '#3a2418' },
    'espresso':      { bag: '#2b1d16', band: '#120b07', tag: '#e7c9a0', ink: '#2b1d16' },
    'decaf':         { bag: '#7a8b5a', band: '#566234', tag: '#f5ece0', ink: '#33401d' },
    'gear':          { bag: '#d8c4a8', band: '#b69a72', tag: '#3a2418', ink: '#3a2418' }
  };

  // Coffee bag illustration (used for all bean products).
  function bagSVG(scheme, initials) {
    return '' +
      '<svg viewBox="0 0 200 240" role="img" aria-hidden="true" preserveAspectRatio="xMidYMid meet">' +
        '<rect width="200" height="240" fill="none"/>' +
        // bag body
        '<path d="M50 60 Q50 50 60 50 L140 50 Q150 50 150 60 L156 210 Q157 222 145 222 L55 222 Q43 222 44 210 Z" fill="' + scheme.bag + '"/>' +
        // side shadow
        '<path d="M120 50 L150 60 L156 210 Q157 222 145 222 L122 222 Z" fill="rgba(0,0,0,0.12)"/>' +
        // top folded seam
        '<rect x="52" y="44" width="96" height="14" rx="4" fill="' + scheme.band + '"/>' +
        '<rect x="52" y="44" width="96" height="5" rx="2" fill="rgba(255,255,255,0.12)"/>' +
        // label
        '<rect x="66" y="92" width="68" height="92" rx="6" fill="' + scheme.tag + '"/>' +
        // steam / lumen mark
        '<circle cx="100" cy="120" r="15" fill="none" stroke="' + scheme.ink + '" stroke-width="3"/>' +
        '<path d="M100 105 L100 99 M115 120 L121 120 M100 135 L100 141 M85 120 L79 120 M110.6 109.4 L114.9 105.1 M110.6 130.6 L114.9 134.9 M89.4 130.6 L85.1 134.9 M89.4 109.4 L85.1 105.1" stroke="' + scheme.ink + '" stroke-width="2.5" stroke-linecap="round"/>' +
        '<text x="100" y="125" text-anchor="middle" font-family="Georgia, serif" font-size="13" font-weight="700" fill="' + scheme.ink + '">' + initials + '</text>' +
        '<rect x="74" y="158" width="52" height="4" rx="2" fill="' + scheme.ink + '" opacity="0.5"/>' +
        '<rect x="80" y="168" width="40" height="4" rx="2" fill="' + scheme.ink + '" opacity="0.3"/>' +
      '</svg>';
  }

  // Pour-over dripper illustration.
  function dripperSVG(scheme) {
    return '' +
      '<svg viewBox="0 0 200 240" role="img" aria-hidden="true" preserveAspectRatio="xMidYMid meet">' +
        '<path d="M55 70 L145 70 L112 150 L88 150 Z" fill="' + scheme.bag + '"/>' +
        '<path d="M100 70 L145 70 L112 150 L100 150 Z" fill="rgba(0,0,0,0.12)"/>' +
        '<rect x="50" y="62" width="100" height="12" rx="6" fill="' + scheme.band + '"/>' +
        '<rect x="84" y="150" width="32" height="10" fill="' + scheme.band + '"/>' +
        // ridges
        '<path d="M70 78 L96 140 M86 74 L100 142 M114 78 L104 140 M130 78 L108 138" stroke="rgba(0,0,0,0.18)" stroke-width="2" fill="none"/>' +
        // carafe
        '<path d="M70 162 L130 162 L138 214 Q139 224 128 224 L72 224 Q61 224 62 214 Z" fill="none" stroke="' + scheme.band + '" stroke-width="4"/>' +
        // drip
        '<circle cx="100" cy="172" r="3" fill="' + scheme.band + '"/>' +
        '<circle cx="100" cy="186" r="2.4" fill="' + scheme.band + '" opacity="0.7"/>' +
      '</svg>';
  }

  // Ceramic mug illustration.
  function mugSVG(scheme) {
    return '' +
      '<svg viewBox="0 0 200 240" role="img" aria-hidden="true" preserveAspectRatio="xMidYMid meet">' +
        '<path d="M62 90 L138 90 L132 196 Q131 208 119 208 L81 208 Q69 208 68 196 Z" fill="' + scheme.bag + '"/>' +
        '<path d="M100 90 L138 90 L132 196 Q131 208 119 208 L100 208 Z" fill="rgba(0,0,0,0.10)"/>' +
        '<ellipse cx="100" cy="90" rx="38" ry="9" fill="' + scheme.band + '"/>' +
        '<ellipse cx="100" cy="90" rx="30" ry="6" fill="rgba(0,0,0,0.25)"/>' +
        '<path d="M138 110 Q172 112 170 140 Q168 168 134 166" fill="none" stroke="' + scheme.band + '" stroke-width="10"/>' +
        // steam
        '<path d="M88 76 q-6 -10 0 -20 q6 -10 0 -20" fill="none" stroke="' + scheme.band + '" stroke-width="3" stroke-linecap="round" opacity="0.6"/>' +
        '<path d="M112 76 q-6 -10 0 -20 q6 -10 0 -20" fill="none" stroke="' + scheme.band + '" stroke-width="3" stroke-linecap="round" opacity="0.6"/>' +
      '</svg>';
  }

  function svgFor(p) {
    var scheme = SCHEMES[p.category] || SCHEMES.blend;
    if (p.id === 'pour-over-dripper') return dripperSVG(scheme);
    if (p.id === 'stoneware-mug') return mugSVG(scheme);
    return bagSVG(scheme, p.initials || p.name.slice(0, 2).toUpperCase());
  }

  var BEAN_SIZE = [
    { label: '250g', delta: 0 },
    { label: '500g', delta: 14 },
    { label: '1kg', delta: 30 }
  ];
  var GRIND = ['Whole Bean', 'Filter', 'Pour Over', 'Espresso'];

  // `date` is a monotonically increasing release index used for "newest" sorting.
  var PRODUCTS = [
    {
      id: 'ethiopia-yirgacheffe', name: 'Ethiopia Yirgacheffe', category: 'single-origin',
      price: 21, roast: 'Light', date: 12, initials: 'ET',
      notes: 'Jasmine and bergamot lift into a syrupy stone-fruit body with a clean, tea-like finish.',
      options: { Size: BEAN_SIZE, Grind: GRIND }
    },
    {
      id: 'colombia-huila', name: 'Colombia Huila', category: 'single-origin',
      price: 19, roast: 'Medium', date: 9, initials: 'CO',
      notes: 'Red apple and caramel sweetness with a rounded milk-chocolate finish. An everyday favorite.',
      options: { Size: BEAN_SIZE, Grind: GRIND }
    },
    {
      id: 'kenya-nyeri', name: 'Kenya Nyeri AA', category: 'single-origin',
      price: 23, roast: 'Light', date: 11, initials: 'KE',
      notes: 'Blackcurrant and tomato vine acidity, dense and juicy with a brown-sugar sweetness.',
      options: { Size: BEAN_SIZE, Grind: GRIND }
    },
    {
      id: 'guatemala-antigua', name: 'Guatemala Antigua', category: 'single-origin',
      price: 20, roast: 'Medium', date: 7, initials: 'GU',
      notes: 'Cocoa nib and toasted almond with a gentle orange-zest brightness and full body.',
      options: { Size: BEAN_SIZE, Grind: GRIND }
    },
    {
      id: 'lumen-house-blend', name: 'Lumen House Blend', category: 'blend',
      price: 17, roast: 'Medium', date: 6, initials: 'HB',
      notes: 'Our flagship: balanced cocoa, hazelnut and dried cherry. Forgiving across every brew method.',
      options: { Size: BEAN_SIZE, Grind: GRIND }
    },
    {
      id: 'midnight-blend', name: 'Midnight Blend', category: 'blend',
      price: 18, roast: 'Dark', date: 8, initials: 'MN',
      notes: 'Deep and smoky with dark-chocolate, molasses and a lingering pipe-tobacco richness.',
      options: { Size: BEAN_SIZE, Grind: GRIND }
    },
    {
      id: 'sunrise-blend', name: 'Sunrise Breakfast Blend', category: 'blend',
      price: 17, roast: 'Medium', date: 5, initials: 'SR',
      notes: 'Bright and easy-drinking: honey, toasted oat and a soft citrus lift to start the day.',
      options: { Size: BEAN_SIZE, Grind: GRIND }
    },
    {
      id: 'gravita-espresso', name: 'Gravita Espresso', category: 'espresso',
      price: 22, roast: 'Dark', date: 10, initials: 'GR',
      notes: 'Built for the machine: thick crema, dark chocolate and dried fig with a clean caramel tail.',
      options: { Size: BEAN_SIZE, Grind: GRIND }
    },
    {
      id: 'aurora-espresso', name: 'Aurora Espresso', category: 'espresso',
      price: 24, roast: 'Medium-Dark', date: 13, initials: 'AU',
      notes: 'A modern espresso — red berries and brown sugar that blooms beautifully in milk.',
      options: { Size: BEAN_SIZE, Grind: GRIND }
    },
    {
      id: 'twilight-decaf', name: 'Twilight Decaf', category: 'decaf',
      price: 19, roast: 'Medium', date: 4, initials: 'TW',
      notes: 'Swiss Water processed. Smooth cocoa and roasted hazelnut with none of the caffeine.',
      options: { Size: BEAN_SIZE, Grind: GRIND }
    },
    {
      id: 'pour-over-dripper', name: 'Ceramic Pour-Over Dripper', category: 'gear',
      price: 32, roast: 'N/A', date: 3, initials: 'PD',
      notes: 'Hand-glazed stoneware dripper with a single large hole for full control over your brew.',
      options: { Color: ['Espresso Brown', 'Parchment Cream', 'Terracotta'] }
    },
    {
      id: 'stoneware-mug', name: 'Stoneware Mug 350ml', category: 'gear',
      price: 18, roast: 'N/A', date: 2, initials: 'SM',
      notes: 'A weighty, comfortable 350ml mug with a reactive glaze — no two are exactly alike.',
      options: { Color: ['Espresso Brown', 'Sage Green', 'Amber'] }
    }
  ];

  // Compute unit price for a chosen set of options.
  function priceFor(product, selection) {
    var price = product.price;
    var opts = product.options || {};
    Object.keys(opts).forEach(function (group) {
      var choices = opts[group];
      var chosen = selection && selection[group];
      if (Array.isArray(choices) && choices.length && typeof choices[0] === 'object') {
        var match = choices.filter(function (c) { return c.label === chosen; })[0] || choices[0];
        price += (match.delta || 0);
      }
    });
    return price;
  }

  // Default selection (first option of every group).
  function defaultSelection(product) {
    var sel = {};
    var opts = product.options || {};
    Object.keys(opts).forEach(function (group) {
      var c = opts[group][0];
      sel[group] = (typeof c === 'object') ? c.label : c;
    });
    return sel;
  }

  function byId(id) {
    return PRODUCTS.filter(function (p) { return p.id === id; })[0] || null;
  }

  global.LUMEN = global.LUMEN || {};
  global.LUMEN.products = PRODUCTS;
  global.LUMEN.svgFor = svgFor;
  global.LUMEN.priceFor = priceFor;
  global.LUMEN.defaultSelection = defaultSelection;
  global.LUMEN.productById = byId;
  global.LUMEN.CATEGORIES = [
    { id: 'single-origin', label: 'Single Origin' },
    { id: 'blend', label: 'Blends' },
    { id: 'espresso', label: 'Espresso' },
    { id: 'decaf', label: 'Decaf' },
    { id: 'gear', label: 'Gear' }
  ];
})(window);
