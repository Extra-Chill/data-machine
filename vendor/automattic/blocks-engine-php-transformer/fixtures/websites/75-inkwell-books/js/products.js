/* Inkwell & Quill — product catalog data
 * Shared across all pages. Exposes window.INKWELL_PRODUCTS and helpers.
 * Each product carries a "cover" descriptor used by buildCoverSVG() in app.js
 * so every book renders a unique inline SVG "cover".
 */
(function () {
  'use strict';

  // Genre palettes — drive the SVG cover colors so each genre reads distinctly.
  var GENRE_THEMES = {
    'Fiction':            { bg: '#1f2d4a', panel: '#27406b', ink: '#f4ecd8', accent: '#9b2226' },
    'Non-Fiction':        { bg: '#3a3226', panel: '#574a36', ink: '#f4ecd8', accent: '#c9a227' },
    'Mystery':            { bg: '#14181f', panel: '#222a36', ink: '#e8e2d0', accent: '#8c1c13' },
    'Sci-Fi & Fantasy':   { bg: '#1b2a3a', panel: '#123047', ink: '#d8f0ff', accent: '#5fb0c9' },
    'Poetry':             { bg: '#2e2440', panel: '#3f315a', ink: '#f3e9ff', accent: '#caa15a' },
    "Children's":         { bg: '#0f5c63', panel: '#147a82', ink: '#fff7e6', accent: '#f4a259' },
    'History':            { bg: '#3d2c20', panel: '#5a4130', ink: '#f0e3cf', accent: '#a86a3d' }
  };

  // Standard format variants. Price is computed as a delta on the base price.
  var FORMATS = [
    { id: 'hardcover', label: 'Hardcover', delta: 0 },
    { id: 'paperback', label: 'Paperback', delta: -6 },
    { id: 'ebook',     label: 'eBook',     delta: -9 }
  ];

  var PRODUCTS = [
    {
      id: 'the-lantern-keepers',
      title: 'The Lantern Keepers',
      author: 'Marguerite Ellison',
      genre: 'Fiction',
      price: 27.0,
      added: '2026-06-20',
      bestseller: true,
      staffPick: true,
      coverStyle: 'stripe',
      description: 'On a windswept island where the lighthouse never sleeps, three generations of women guard a secret the sea keeps trying to reclaim. A luminous, character-driven novel about inheritance, grief, and the small mercies that keep us tethered to one another.'
    },
    {
      id: 'salt-and-cedar',
      title: 'Salt & Cedar',
      author: 'Tomas Reyes',
      genre: 'Fiction',
      price: 24.0,
      added: '2026-05-02',
      bestseller: false,
      staffPick: true,
      coverStyle: 'arch',
      description: 'A sweeping family saga that follows a fishing community across four decades of boom, bust, and stubborn love. Reyes writes the ordinary with such tenderness that you will swear you can smell the harbor.'
    },
    {
      id: 'the-quiet-algorithm',
      title: 'The Quiet Algorithm',
      author: 'Dr. Priya Nandakumar',
      genre: 'Non-Fiction',
      price: 29.0,
      added: '2026-06-12',
      bestseller: true,
      staffPick: false,
      coverStyle: 'grid',
      description: 'A clear-eyed field guide to the invisible systems shaping modern life — from credit scores to recommendation engines. Nandakumar trades hype for humanity, asking not what machines can do, but what we should let them.'
    },
    {
      id: 'breadcrumbs-and-empires',
      title: 'Breadcrumbs & Empires',
      author: 'Helena Brandt',
      genre: 'History',
      price: 32.0,
      added: '2026-03-18',
      bestseller: false,
      staffPick: false,
      coverStyle: 'banner',
      description: 'A surprising history of the world told through bread — the loaf as currency, weapon, ritual, and revolution. Meticulously researched and impossible to put down on an empty stomach.'
    },
    {
      id: 'midnight-at-the-ledger',
      title: 'Midnight at the Ledger',
      author: 'Cormac Vane',
      genre: 'Mystery',
      price: 25.0,
      added: '2026-06-25',
      bestseller: true,
      staffPick: true,
      coverStyle: 'noir',
      description: 'When a reclusive accountant is found dead among his immaculate books, the only clue is a single balanced column that should not balance. A twisting, atmospheric whodunit for fans of slow-burning dread.'
    },
    {
      id: 'the-hollow-tide',
      title: 'The Hollow Tide',
      author: 'Imani Okafor',
      genre: 'Mystery',
      price: 26.0,
      added: '2026-04-09',
      bestseller: false,
      staffPick: false,
      coverStyle: 'noir',
      description: 'A detective returns to her drowned hometown to solve a cold case that the rising water keeps unburying. Taut, lyrical, and laced with secrets that refuse to stay submerged.'
    },
    {
      id: 'starlight-cartographers',
      title: 'Starlight Cartographers',
      author: 'Wren Adekoya',
      genre: 'Sci-Fi & Fantasy',
      price: 28.0,
      added: '2026-06-22',
      bestseller: true,
      staffPick: true,
      coverStyle: 'orbit',
      description: 'In a galaxy where maps are illegal and memory is the only reliable navigation, a disgraced cartographer is hired to chart a route no one has survived. Grand, inventive space opera with a beating human heart.'
    },
    {
      id: 'the-glass-thicket',
      title: 'The Glass Thicket',
      author: 'Solveig Marsh',
      genre: 'Sci-Fi & Fantasy',
      price: 27.0,
      added: '2026-02-14',
      bestseller: false,
      staffPick: false,
      coverStyle: 'orbit',
      description: 'A young hedge-witch discovers that the forest swallowing her village is not growing but remembering. Lush, eerie fantasy about the price of forgetting and the courage of staying soft.'
    },
    {
      id: 'small-gods-of-the-kitchen',
      title: 'Small Gods of the Kitchen',
      author: 'Beatriz Llanos',
      genre: 'Poetry',
      price: 19.0,
      added: '2026-05-30',
      bestseller: false,
      staffPick: true,
      coverStyle: 'minimal',
      description: 'A debut collection that finds the sacred in chopped onions, chipped mugs, and Sunday rice. Llanos writes domestic life as devotion — tender, funny, and quietly devastating.'
    },
    {
      id: 'field-notes-on-longing',
      title: 'Field Notes on Longing',
      author: 'Aria Petrov',
      genre: 'Poetry',
      price: 18.0,
      added: '2026-01-21',
      bestseller: false,
      staffPick: false,
      coverStyle: 'minimal',
      description: 'Spare, aching poems mapping the geography of want — for places, for people, for versions of ourselves we never became. A collection to keep on the nightstand and return to often.'
    },
    {
      id: 'the-button-moon-express',
      title: 'The Button-Moon Express',
      author: 'Daisy Whitlock',
      genre: "Children's",
      price: 17.0,
      added: '2026-06-18',
      bestseller: true,
      staffPick: false,
      coverStyle: 'playful',
      description: 'All aboard the train that runs on bedtime! A rhyming, riotously illustrated adventure where a brave conductor cat collects yawns to fuel the journey to the Button-Moon. For ages 3 to 7.'
    },
    {
      id: 'how-to-befriend-a-dragon',
      title: 'How to Befriend a Dragon',
      author: 'Oliver Finch',
      genre: "Children's",
      price: 16.0,
      added: '2026-04-27',
      bestseller: false,
      staffPick: true,
      coverStyle: 'playful',
      description: 'Step one: do not scream. A warm, witty picture book about friendship, courage, and sharing your sandwiches with very large reptiles. For ages 4 to 8.'
    },
    {
      id: 'the-cartographers-daughter',
      title: "The Cartographer's Daughter",
      author: 'Noor Hassan',
      genre: 'Non-Fiction',
      price: 30.0,
      added: '2026-06-05',
      bestseller: false,
      staffPick: false,
      coverStyle: 'grid',
      description: 'Part memoir, part travelogue, this is the story of growing up between borders, languages, and the maps her father drew by hand. A moving meditation on belonging and the places that make us.'
    },
    {
      id: 'an-honest-history-of-fire',
      title: 'An Honest History of Fire',
      author: 'Gregor Adamou',
      genre: 'History',
      price: 31.0,
      added: '2026-06-28',
      bestseller: true,
      staffPick: true,
      coverStyle: 'banner',
      description: 'From the first spark to the combustion engine, a vivid history of humanity told through our oldest, most dangerous tool. Adamou makes the past crackle with life — and warning.'
    }
  ];

  // Compute the price for a given product + format id.
  function priceFor(product, formatId) {
    var fmt = FORMATS.filter(function (f) { return f.id === formatId; })[0] || FORMATS[0];
    return Math.max(0, product.price + fmt.delta);
  }

  function getProduct(id) {
    return PRODUCTS.filter(function (p) { return p.id === id; })[0] || null;
  }

  function formatLabel(formatId) {
    var fmt = FORMATS.filter(function (f) { return f.id === formatId; })[0];
    return fmt ? fmt.label : formatId;
  }

  window.INKWELL_PRODUCTS = PRODUCTS;
  window.INKWELL_FORMATS = FORMATS;
  window.INKWELL_GENRE_THEMES = GENRE_THEMES;
  window.INKWELL_DATA = {
    products: PRODUCTS,
    formats: FORMATS,
    genreThemes: GENRE_THEMES,
    priceFor: priceFor,
    getProduct: getProduct,
    formatLabel: formatLabel
  };
})();
