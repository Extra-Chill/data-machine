/* SOLE SOCIETY — product catalog
 * Each product carries a `colorway` palette consumed by the inline-SVG
 * sneaker renderer in app.js so every card renders a distinct shoe.
 * `drop` is an integer release index used for "newest" sorting (higher = newer).
 */
window.SOLE_PRODUCTS = [
  {
    id: 'velocity-volt',
    name: 'Velocity Pro "Volt Strike"',
    maker: 'Apex Athletics',
    price: 165,
    category: 'Running',
    drop: 14,
    description:
      'A featherweight daily trainer built for speed. Energy-return foam, breathable engineered mesh, and a no-nonsense volt hit that lights up the pavement.',
    colors: ['Volt Strike', 'Triple Black', 'Storm Grey'],
    colorway: { upper: '#15161a', accent: '#c6ff00', sole: '#f3f3f3', midsole: '#23252b', lace: '#c6ff00', detail: '#0c0d10' }
  },
  {
    id: 'court-classic-cream',
    name: 'Court Classic "Vintage Cream"',
    maker: 'Heritage Court',
    price: 110,
    category: 'Lifestyle',
    drop: 6,
    description:
      'The everyday icon. Premium tumbled leather, a timeless silhouette, and an aged cream sole that only looks better with wear.',
    colors: ['Vintage Cream', 'White/Green', 'Panda'],
    colorway: { upper: '#f4efe2', accent: '#2f6f4f', sole: '#e8dcc0', midsole: '#f4efe2', lace: '#ffffff', detail: '#cfc4a8' }
  },
  {
    id: 'skyhook-magenta',
    name: 'SkyHook 2 "Hyper Magenta"',
    maker: 'Vertical Sports',
    price: 185,
    category: 'Basketball',
    drop: 13,
    description:
      'Built for the rim. Full-length cushioning plate, lockdown midfoot strap geometry, and a hyper magenta blast that demands the spotlight.',
    colors: ['Hyper Magenta', 'Court Purple', 'Blackout'],
    colorway: { upper: '#1a1014', accent: '#ff2d78', sole: '#0e0a0c', midsole: '#2a1620', lace: '#ff2d78', detail: '#3a1f2c' }
  },
  {
    id: 'grip-tape-skate',
    name: 'GripTape Low "Asphalt"',
    maker: 'Curbside Co.',
    price: 85,
    category: 'Skate',
    drop: 8,
    description:
      'Vulcanized for board feel, reinforced for ollies. A padded collar and gum outsole that bites the deck and shrugs off curbs.',
    colors: ['Asphalt', 'Bone', 'Oxblood'],
    colorway: { upper: '#3a3f47', accent: '#d9a441', sole: '#caa472', midsole: '#2b2f35', lace: '#e7e3da', detail: '#23272c' }
  },
  {
    id: 'phantom-limited',
    name: 'Phantom "Eclipse" (Limited)',
    maker: 'SOLE SOCIETY Labs',
    price: 320,
    category: 'Limited',
    drop: 15,
    description:
      'A numbered house drop. Tonal blackout build, reflective ghost detailing, and a sculpted sole unit. Strictly limited — when it is gone, it is gone.',
    colors: ['Eclipse', 'Phantom White'],
    colorway: { upper: '#0c0c0f', accent: '#6f7bff', sole: '#161620', midsole: '#1d1d27', lace: '#2a2a35', detail: '#3a3aff' }
  },
  {
    id: 'aero-glide-ice',
    name: 'AeroGlide "Ice Cyan"',
    maker: 'Apex Athletics',
    price: 145,
    category: 'Running',
    drop: 11,
    description:
      'Long-run comfort with a plush rocker geometry and a cooling ice cyan upper. Your half-marathon weapon of choice.',
    colors: ['Ice Cyan', 'Pure Platinum', 'Black/Volt'],
    colorway: { upper: '#e9f6fb', accent: '#19c3e6', sole: '#ffffff', midsole: '#d8eef6', lace: '#19c3e6', detail: '#bfe2ee' }
  },
  {
    id: 'metro-lux-mocha',
    name: 'Metro Lux "Mocha"',
    maker: 'Heritage Court',
    price: 135,
    category: 'Lifestyle',
    drop: 9,
    description:
      'Luxe streetwear staple. Suede overlays, a quilted heel, and a rich mocha tone that pairs with everything in the rotation.',
    colors: ['Mocha', 'Sail', 'Slate'],
    colorway: { upper: '#6e4a33', accent: '#d8b48a', sole: '#efe6d6', midsole: '#7d5640', lace: '#e9dcc6', detail: '#553622' }
  },
  {
    id: 'rim-rocker-fire',
    name: 'RimRocker "Fire Red"',
    maker: 'Vertical Sports',
    price: 175,
    category: 'Basketball',
    drop: 10,
    description:
      'A bully on the blacktop. Herringbone traction, a stiff TPU shank, and a fire red colorway loud enough to talk trash for you.',
    colors: ['Fire Red', 'Bred', 'University Blue'],
    colorway: { upper: '#1c1416', accent: '#ff3b30', sole: '#f2f2f2', midsole: '#2a1c1f', lace: '#ff3b30', detail: '#0f0a0b' }
  },
  {
    id: 'kickflip-teal',
    name: 'Kickflip Pro "Teal Fade"',
    maker: 'Curbside Co.',
    price: 95,
    category: 'Skate',
    drop: 12,
    description:
      'Pro-model durability with a suede toe box that survives flip tricks. A teal fade that pops under any streetlight.',
    colors: ['Teal Fade', 'Black/Gum', 'Washed Denim'],
    colorway: { upper: '#1f6f74', accent: '#f4d35e', sole: '#caa472', midsole: '#175357', lace: '#fefae0', detail: '#0f3c40' }
  },
  {
    id: 'nova-burst-coral',
    name: 'NovaBurst "Coral Pulse"',
    maker: 'Apex Athletics',
    price: 155,
    category: 'Running',
    drop: 13,
    description:
      'Tempo-day responsiveness with a snappy carbon-ish plate feel and a coral pulse upper that screams negative splits.',
    colors: ['Coral Pulse', 'Solar Yellow', 'Mono Black'],
    colorway: { upper: '#15161a', accent: '#ff6f61', sole: '#ffffff', midsole: '#23252b', lace: '#ff6f61', detail: '#0c0d10' }
  },
  {
    id: 'boulevard-onyx',
    name: 'Boulevard "Onyx Panda"',
    maker: 'Heritage Court',
    price: 120,
    category: 'Lifestyle',
    drop: 7,
    description:
      'The clean two-tone everyone reaches for. Crisp leather panels, an onyx-and-white split, and an everyday-comfort footbed.',
    colors: ['Onyx Panda', 'All White', 'Sail/Gum'],
    colorway: { upper: '#101114', accent: '#ffffff', sole: '#ffffff', midsole: '#f3f3f3', lace: '#ffffff', detail: '#0a0b0d' }
  },
  {
    id: 'tip-off-amber',
    name: 'Tip-Off "Amber Glow"',
    maker: 'Vertical Sports',
    price: 160,
    category: 'Basketball',
    drop: 11,
    description:
      'Quick-guard cushioning with a low, locked-in ride. An amber glow upper that heats up from the opening tip.',
    colors: ['Amber Glow', 'Court Green', 'Triple White'],
    colorway: { upper: '#2a1c0e', accent: '#ffb000', sole: '#1a1106', midsole: '#3a2913', lace: '#ffb000', detail: '#4a3518' }
  },
  {
    id: 'halfpipe-violet',
    name: 'Halfpipe "Ultra Violet"',
    maker: 'Curbside Co.',
    price: 90,
    category: 'Skate',
    drop: 9,
    description:
      'Cupsole support meets vulcanized flex. Abrasion-tough sidewalls and an ultra violet hit for the bowl sessions.',
    colors: ['Ultra Violet', 'Black/White', 'Forest'],
    colorway: { upper: '#3b2a6b', accent: '#b39cff', sole: '#caa472', midsole: '#2c1f52', lace: '#ece6ff', detail: '#1f1640' }
  },
  {
    id: 'archive-gold-limited',
    name: 'Archive "24K" (Limited)',
    maker: 'SOLE SOCIETY Labs',
    price: 280,
    category: 'Limited',
    drop: 15,
    description:
      'A vault reissue dressed in metallic gold detailing on a deep black base. Premium materials, numbered tongue, collector packaging.',
    colors: ['24K Gold', 'Midnight'],
    colorway: { upper: '#121013', accent: '#e6b422', sole: '#1a1a1a', midsole: '#23202a', lace: '#e6b422', detail: '#caa11a' }
  }
];
