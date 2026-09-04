/* Terra & Form — product catalog data
 * Each product renders as a varied inline SVG vessel. The `shape` + `glaze`
 * fields drive the SVG generator in app.js so the grid reads like a gallery.
 * Glaze palette tokens map to fills in renderVessel().
 */

// Glaze swatch tokens -> hex. Muted, dusty earthtone glazes.
const GLAZES = {
  Oatmeal:  '#e3d8c5',
  Sage:     '#a7b09a',
  Charcoal: '#3f3f3c',
  Rust:     '#b07a5c',
  Sand:     '#d6c3a5',
  Ash:      '#b9b6ad',
  Cream:    '#efe7d8',
  Clay:     '#c08a6a'
};

// `added` is a sortable index — higher = newer in the studio.
const PRODUCTS = [
  {
    id: 'tf-01',
    name: 'Oatmeal Dinner Plate',
    category: 'Tableware',
    price: 48,
    added: 13,
    featured: true,
    shape: 'plate',
    glaze: 'Oatmeal',
    dimensions: '27 cm diameter · 2.5 cm high',
    description: 'A generous hand-thrown dinner plate finished in a soft matte oatmeal glaze. Subtle throwing rings catch the light at the rim. Lead-free, food-safe, dishwasher friendly.',
    variants: { label: 'Glaze', options: ['Oatmeal', 'Sage', 'Charcoal'] }
  },
  {
    id: 'tf-02',
    name: 'Sage Breakfast Bowl',
    category: 'Bowls',
    price: 36,
    added: 12,
    featured: true,
    shape: 'bowl',
    glaze: 'Sage',
    dimensions: '15 cm diameter · 7 cm deep',
    description: 'A deep everyday bowl glazed in dusty sage that pools darker toward the foot. Thrown on the wheel and trimmed by hand for a comfortable lifted base.',
    variants: { label: 'Glaze', options: ['Sage', 'Oatmeal', 'Rust'] }
  },
  {
    id: 'tf-03',
    name: 'Stoneware Tumbler',
    category: 'Mugs & Cups',
    price: 28,
    added: 11,
    featured: false,
    shape: 'tumbler',
    glaze: 'Sand',
    dimensions: '8 cm diameter · 10 cm high · 300 ml',
    description: 'A handleless tumbler with a gently tapered waist that sits easily in the hand. Speckled stoneware body under a translucent sand glaze.',
    variants: { label: 'Glaze', options: ['Sand', 'Charcoal', 'Sage'] }
  },
  {
    id: 'tf-04',
    name: 'Charcoal Coffee Mug',
    category: 'Mugs & Cups',
    price: 34,
    added: 10,
    featured: true,
    shape: 'mug',
    glaze: 'Charcoal',
    dimensions: '9 cm diameter · 9 cm high · 350 ml',
    description: 'A morning mug with a full pulled handle and a satin charcoal glaze that breaks to warm brown over the throwing marks. Holds a proper cup of coffee.',
    variants: { label: 'Glaze', options: ['Charcoal', 'Rust', 'Oatmeal'] }
  },
  {
    id: 'tf-05',
    name: 'Bud Vase',
    category: 'Vases',
    price: 42,
    added: 9,
    featured: false,
    shape: 'budvase',
    glaze: 'Rust',
    dimensions: '7 cm diameter · 14 cm high',
    description: 'A slim bud vase for a single stem or a small foraged sprig. Narrow neck, rounded belly, soft rust glaze with a wax-resist line at the foot.',
    variants: { label: 'Glaze', options: ['Rust', 'Sage', 'Charcoal'] }
  },
  {
    id: 'tf-06',
    name: 'Pedestal Vase',
    category: 'Vases',
    price: 96,
    added: 8,
    featured: true,
    shape: 'pedestal',
    glaze: 'Cream',
    dimensions: '16 cm diameter · 28 cm high',
    description: 'A statement vase thrown in two sections and joined at the waist, raised on a turned pedestal foot. A quiet cream glaze keeps the focus on the form.',
    variants: { label: 'Size', options: ['Medium', 'Large'] }
  },
  {
    id: 'tf-07',
    name: 'Serving Platter',
    category: 'Tableware',
    price: 72,
    added: 7,
    featured: false,
    shape: 'platter',
    glaze: 'Ash',
    dimensions: '34 cm diameter · 3 cm high',
    description: 'A wide low platter for sharing — bread, fruit, or a roast at the centre of the table. Hand-trimmed rim with a soft ash glaze that pools into the well.',
    variants: { label: 'Glaze', options: ['Ash', 'Oatmeal', 'Sage'] }
  },
  {
    id: 'tf-08',
    name: 'Nesting Prep Bowls',
    category: 'Bowls',
    price: 64,
    added: 6,
    featured: false,
    shape: 'nesting',
    glaze: 'Clay',
    dimensions: 'Set of three · 11 / 14 / 17 cm',
    description: 'A set of three nesting prep bowls thrown to stack neatly on the shelf. Warm clay glaze with bare unglazed rims that show the speckled body.',
    variants: { label: 'Glaze', options: ['Clay', 'Sage', 'Charcoal'] }
  },
  {
    id: 'tf-09',
    name: 'Espresso Cup & Saucer',
    category: 'Mugs & Cups',
    price: 30,
    added: 5,
    featured: false,
    shape: 'espresso',
    glaze: 'Oatmeal',
    dimensions: '6 cm cup · 11 cm saucer · 90 ml',
    description: 'A small thrown espresso cup with a matching saucer, finished in a creamy oatmeal glaze. The handle is pulled thin to keep the cup feeling delicate.',
    variants: { label: 'Glaze', options: ['Oatmeal', 'Charcoal', 'Rust'] }
  },
  {
    id: 'tf-10',
    name: 'Ikebana Vessel',
    category: 'Vases',
    price: 58,
    added: 4,
    featured: false,
    shape: 'ikebana',
    glaze: 'Sage',
    dimensions: '18 cm wide · 9 cm high',
    description: 'A low wide-mouthed vessel made for minimal ikebana arrangements. Matte sage glaze outside, glossy pooled glaze inside the shallow well.',
    variants: { label: 'Glaze', options: ['Sage', 'Ash', 'Rust'] }
  },
  {
    id: 'tf-11',
    name: 'Incense Holder',
    category: 'Decor',
    price: 24,
    added: 3,
    featured: false,
    shape: 'dish',
    glaze: 'Rust',
    dimensions: '10 cm diameter · 2 cm high',
    description: 'A small round dish with a hand-pierced centre to hold a single incense stick, with a raised lip to catch the ash. Warm rust glaze, unglazed foot.',
    variants: { label: 'Glaze', options: ['Rust', 'Charcoal', 'Sage'] }
  },
  {
    id: 'tf-12',
    name: 'Sculptural Object',
    category: 'Decor',
    price: 88,
    added: 2,
    featured: false,
    shape: 'sculpture',
    glaze: 'Ash',
    dimensions: '12 cm wide · 20 cm high',
    description: 'A purely sculptural thrown-and-altered form for the shelf or mantel. No two are alike — each is pushed and faceted by hand while the clay is soft.',
    variants: { label: 'Size', options: ['Small', 'Large'] }
  },
  {
    id: 'tf-13',
    name: 'Tea Pot',
    category: 'Tableware',
    price: 110,
    added: 1,
    featured: true,
    shape: 'teapot',
    glaze: 'Charcoal',
    dimensions: '18 cm wide · 14 cm high · 700 ml',
    description: 'A round-bodied teapot with a pulled handle, thrown spout, and fitted lid. Satin charcoal glaze. Pours cleanly and keeps two cups hot.',
    variants: { label: 'Glaze', options: ['Charcoal', 'Sage', 'Clay'] }
  }
];

// Expose for non-module scripts.
if (typeof window !== 'undefined') {
  window.PRODUCTS = PRODUCTS;
  window.GLAZES = GLAZES;
}
