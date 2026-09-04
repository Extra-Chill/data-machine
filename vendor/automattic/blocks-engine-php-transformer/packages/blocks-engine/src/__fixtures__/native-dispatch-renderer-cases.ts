import type { NativeRenderCtx, NativeRenderOut } from '../theme/native-reconstruct-types.js';
import type {
  InteractionModel,
  SectionSpec,
  SectionSpecCell,
  SectionSpecIcon,
  SectionSpecImage,
} from '../theme/section-spec.js';
import type { CardGroupPadding } from '../theme/native-renderers-grid.js';

export const NATIVE_DISPATCH_RENDERER_DERIVATION =
  'Mechanically generated from DLA src/lib/replicate/page-reconstruct.ts reconstructPagePattern at DLA commit 1e393c5; renderer markup strips the final reconstruct body newline, and footer/nav renderSection cases are sandwiched between non-chrome sentinels so DLA stripChrome does not remove them before dispatch.';

export const ALL_RENDER_SECTION_INTERACTION_MODELS: InteractionModel[] = [
  'static',
  'cover-with-headline',
  'animated-cover',
  'media-text',
  'columns',
  'gallery',
  'logo-strip',
  'testimonial',
  'cta',
  'blog-card-grid',
  'project-card-grid',
  'price-list',
  'product-card-row',
  'review-grid',
  'app-download',
  'color-block-grid',
  'marquee-strip',
  'horizontal-showcase',
  'footer',
  'nav',
];

export type DispatchRendererName =
  | 'renderTextBand'
  | 'renderCover'
  | 'renderMediaText'
  | 'renderCardGrid'
  | 'renderReviewGrid'
  | 'renderImageRow'
  | 'renderFaq'
  | 'renderCellGrid';

export interface NativeDispatchRendererImpl {
  renderCardGrid(section: SectionSpec, withButtons: boolean): NativeRenderOut;
  renderFaq(section: SectionSpec): NativeRenderOut;
  renderCellGrid(section: SectionSpec, ctx: NativeRenderCtx): NativeRenderOut;
  cardGroup(parts: string[], bgToken: string, dark: boolean, radius: number, padding: CardGroupPadding | null): string;
  renderSection(section: SectionSpec, ctx: NativeRenderCtx): NativeRenderOut;
}

export interface NativeRendererSectionCase {
  id: string;
  section: SectionSpec;
}

export interface NativeCardGridRendererCase extends NativeRendererSectionCase {
  withButtons: boolean;
}

export interface NativeCardGroupRendererCase {
  id: string;
  parts: string[];
  bgToken: string;
  dark: boolean;
  radius: number;
  padding: CardGroupPadding | null;
  dlaSection: SectionSpec;
}

export interface NativeRenderSectionCase extends NativeRendererSectionCase {
  expectedRenderer: DispatchRendererName;
  expectedFlip?: boolean;
  chromeSandwich?: boolean;
}

export interface NativeDispatchRendererCaseGroups {
  renderCardGrid: NativeCardGridRendererCase[];
  renderFaq: NativeRendererSectionCase[];
  renderCellGrid: NativeRendererSectionCase[];
  cardGroup: NativeCardGroupRendererCase[];
  renderSection: NativeRenderSectionCase[];
}

export interface NativeDispatchRendererOutputCase {
  id: string;
  output: unknown;
}

export interface NativeDispatchRendererParityFile {
  version: 1;
  derivation: string;
  renderers: Record<keyof NativeDispatchRendererCaseGroups, NativeDispatchRendererOutputCase[]>;
}

const WP = 'http://localhost:8883/wp-content/uploads/2026/05/';
const CDN = 'https://cdn.example.test/native-dispatch/';

export function nativeDispatchRenderCtx(): NativeRenderCtx {
  return {
    mediaTextIndex: 0,
    iconCounter: 0,
    paletteTokens: [
      { slug: 'text-default', hex: '#102030' },
      { slug: 'text-inverse', hex: '#f8fafc' },
      { slug: 'text-muted', hex: '#4b5563' },
      { slug: 'text-subtle', hex: '#6b7280' },
      { slug: 'accent-primary', hex: '#008060' },
      { slug: 'surface-base', hex: '#ffffff' },
      { slug: 'surface-raised', hex: '#e8eff1' },
      { slug: 'surface-inverse', hex: '#111827' },
    ],
    fontFamilies: [
      { slug: 'display', family: 'Caldera Display, serif' },
      { slug: 'body', family: 'Caldera, sans-serif' },
    ],
  };
}

function image(name: string, width = 800, height = 600, alt = name): SectionSpecImage {
  return {
    url: `${WP}${name}`,
    sourceUrl: `${CDN}${name}`,
    alt,
    kind: 'img',
    width,
    height,
  };
}

function backgroundImage(name: string, width = 1440, height = 900, alt = name): SectionSpecImage {
  return {
    ...image(name, width, height, alt),
    kind: 'background',
  };
}

function icon(markup = '<svg viewBox="0 0 20 20"><path d="M2 10h16v2H2z"/></svg>'): SectionSpecIcon {
  return {
    kind: 'svg',
    markup,
    width: 20,
    height: 20,
  };
}

function cell(partial: Partial<SectionSpecCell>): SectionSpecCell {
  return {
    heading: null,
    body: [],
    image: null,
    icon: null,
    button: null,
    ...partial,
  };
}

function section(partial: Partial<SectionSpec> = {}): SectionSpec {
  return {
    sectionIndex: 1,
    interactionModel: 'static',
    top: 0,
    height: 480,
    headings: [],
    bodyText: [],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 255,
    backgroundColor: 'rgb(255, 255, 255)',
    gradient: null,
    gradientSource: null,
    motionProfile: { motionClass: 'none', signals: [], animatedElements: 0 },
    dividerAbove: null,
    dividerBelow: null,
    layout: {
      containerWidth: 1100,
      padding: '0px',
      childLayout: 'stack',
      columnCount: 1,
      gap: '24px',
    },
    ...partial,
  };
}

function basicSection(index: number, interactionModel: InteractionModel, label: string): SectionSpec {
  return section({
    sectionIndex: index,
    interactionModel,
    headings: [`${label} headline`],
    bodyText: [`${label} body copy`],
    buttonLabels: [`${label} action`],
    height: 420,
    layout: {
      containerWidth: 1000,
      padding: '0px',
      childLayout: 'stack',
      columnCount: 1,
      gap: '24px',
      padTopPx: 44,
      padBottomPx: 46,
    },
  });
}

const cardIntro = section({
  sectionIndex: 61,
  interactionModel: 'product-card-row',
  headings: ['Sleep essentials'],
  bodyText: ['Choose the pieces that fit your room.'],
  buttonLabels: ['Shop bundle', 'Shop lamp'],
  images: [image('bundle.jpg', 640, 420, 'Bundle'), image('lamp.jpg', 620, 420, 'Lamp')],
  layout: { containerWidth: 1120, padding: '0px', childLayout: 'grid', columnCount: 2, gap: '24px' },
});

const projectCards = section({
  sectionIndex: 62,
  interactionModel: 'project-card-grid',
  headings: ['Cabin refresh', 'Studio wall'],
  bodyText: ['Warm oak across the room.', 'A quiet wall for display.'],
  images: [image('cabin.jpg', 600, 430, 'Cabin'), image('studio.jpg', 610, 430, 'Studio')],
  layout: { containerWidth: 1120, padding: '0px', childLayout: 'grid', columnCount: 2, gap: '24px' },
});

const faqComplete = section({
  sectionIndex: 63,
  interactionModel: 'static',
  headings: ['Frequently Asked Questions'],
  faqs: [
    { question: 'Can I install it myself?', answer: 'Yes, the kit includes a measured guide.' },
    { question: 'How fast does it ship?', answer: 'Most orders leave the studio in two days.' },
  ],
  layout: { containerWidth: 760, padding: '0px', childLayout: 'stack', columnCount: 1, gap: '18px' },
});

const faqMissingAnswer = section({
  sectionIndex: 64,
  interactionModel: 'cta',
  headings: ['Questions before ordering'],
  faqs: [{ question: 'Is commercial pricing available?', answer: '' }],
  backgroundBrightness: 238,
  backgroundColor: 'rgb(232, 239, 241)',
});

const styledCells = section({
  sectionIndex: 65,
  interactionModel: 'static',
  headings: ['Three bedtime essentials.'],
  cells: [
    cell({
      heading: 'Quiet motor',
      body: ['A low-hum motor keeps the room calm.'],
      icon: icon(),
      background: 'rgb(17, 24, 39)',
      radius: 24,
      padding: { top: 24, right: 32, bottom: 28, left: 32 },
      headingSize: 28,
      headingLineHeight: 1.15,
      bodyLineHeight: 1.45,
      align: 'center',
      iconAlign: 'center',
    }),
    cell({
      heading: 'Warm light',
      body: ['A dimmable surface light replaces harsh lamps.'],
      image: image('warm-light.jpg', 360, 260, 'Warm light'),
      background: 'rgb(232, 239, 241)',
      radius: 12,
      padding: { top: 20, right: 24, bottom: 20, left: 24 },
      headingSize: 24,
      headingLineHeight: 1.18,
      bodyLineHeight: 1.45,
      align: 'left',
      iconAlign: 'left',
    }),
  ],
  layout: { containerWidth: 1100, padding: '0px', childLayout: 'grid', columnCount: 2, gap: '28px' },
});

const cellWithSectionImage = section({
  sectionIndex: 66,
  interactionModel: 'price-list',
  headings: ['Compare the setups'],
  cells: [
    cell({ heading: 'Starter', body: ['A simple bedside setup.'] }),
    cell({ heading: 'Studio', body: ['A deeper package with samples.'] }),
  ],
  images: [image('comparison-photo.jpg', 480, 320, 'Comparison photo')],
  layout: { containerWidth: 1100, padding: '0px', childLayout: 'grid', columnCount: 3, gap: '24px' },
});

const fullBleedCells = section({
  sectionIndex: 67,
  interactionModel: 'horizontal-showcase',
  cells: [
    cell({ heading: 'Left edge', body: ['The first card reaches the viewport edge.'] }),
    cell({ heading: 'Right edge', body: ['The last card follows the source rail.'] }),
  ],
  layout: { containerWidth: 1440, padding: '0px', childLayout: 'grid', columnCount: 2, gap: '0px' },
});

const cardGroupDlaSection = section({
  sectionIndex: 68,
  interactionModel: 'static',
  cells: [
    cell({
      heading: 'Quiet card',
      body: ['Padded copy'],
      background: 'rgb(17, 24, 39)',
      radius: 24,
      padding: { top: 24, right: 32, bottom: 28, left: 32 },
      headingSize: 28,
      headingLineHeight: 1.15,
      bodyLineHeight: 1.45,
      align: 'center',
    }),
    cell({ heading: 'Second card', body: ['Keeps the cell-grid route active.'] }),
  ],
  layout: { containerWidth: 1100, padding: '0px', childLayout: 'grid', columnCount: 2, gap: '24px' },
});

const cardGroupParts = [
  '<!-- wp:heading {"textAlign":"center","style":{"typography":{"fontSize":"clamp(16px, 1.9vw, 28px)","lineHeight":"1.15"}},"level":3,"fontFamily":"display","textColor":"text-inverse"} -->\n<h3 class="wp-block-heading has-text-align-center has-text-inverse-color has-text-color has-display-font-family" style="font-size:clamp(16px, 1.9vw, 28px);line-height:1.15">Quiet card</h3>\n<!-- /wp:heading -->',
  '<!-- wp:paragraph {"align":"center","style":{"typography":{"lineHeight":"1.45"}},"fontSize":"small","textColor":"text-inverse"} -->\n<p class="has-text-align-center has-text-inverse-color has-text-color has-small-font-size" style="line-height:1.45">Padded copy</p>\n<!-- /wp:paragraph -->',
];

function renderSectionCases(): NativeRenderSectionCase[] {
  const cases: NativeRenderSectionCase[] = [
    {
      id: 'faq-override-before-model-switch',
      section: faqComplete,
      expectedRenderer: 'renderFaq',
    },
    {
      id: 'cell-grid-override-for-flatten-prone-model',
      section: styledCells,
      expectedRenderer: 'renderCellGrid',
    },
    {
      id: 'media-layout-override-image-left',
      section: section({
        sectionIndex: 81,
        interactionModel: 'static',
        mediaLayout: 'image-left',
        headings: ['Image-led story row'],
        bodyText: ['The source puts media before the copy.'],
        images: [image('story.jpg', 640, 520, 'Story')],
        layout: { containerWidth: 1100, padding: '0px', childLayout: 'flex-row', columnCount: 2, gap: '32px' },
      }),
      expectedRenderer: 'renderMediaText',
      expectedFlip: true,
    },
    {
      id: 'columns-single-image-media-text',
      section: section({
        sectionIndex: 82,
        interactionModel: 'columns',
        headings: ['Columns become media text'],
        bodyText: ['One image and copy is a two-up row.'],
        images: [image('columns.jpg', 640, 480, 'Columns')],
        layout: { containerWidth: 1100, padding: '0px', childLayout: 'flex-row', columnCount: 2, gap: '28px' },
      }),
      expectedRenderer: 'renderMediaText',
      expectedFlip: false,
    },
    {
      id: 'columns-without-image-text-band',
      section: basicSection(83, 'columns', 'Columns default'),
      expectedRenderer: 'renderTextBand',
    },
    {
      id: 'cover-full-bleed-wide-image',
      section: section({
        sectionIndex: 84,
        interactionModel: 'cover-with-headline',
        fullBleed: true,
        height: 720,
        headings: ['Cover headline'],
        bodyText: ['Copy sits over the captured background image.'],
        images: [backgroundImage('cover-wide.jpg', 1440, 900, 'Cover')],
        backgroundBrightness: 32,
        backgroundColor: 'rgb(16, 32, 48)',
      }),
      expectedRenderer: 'renderCover',
    },
    {
      id: 'cover-non-full-bleed-media-text',
      section: section({
        sectionIndex: 85,
        interactionModel: 'cover-with-headline',
        fullBleed: false,
        headings: ['Cover falls to media text'],
        bodyText: ['A contained photo belongs beside the copy.'],
        images: [image('cover-contained.jpg', 640, 520, 'Contained cover')],
      }),
      expectedRenderer: 'renderMediaText',
      expectedFlip: false,
    },
    {
      id: 'cover-without-image-text-band',
      section: basicSection(86, 'cover-with-headline', 'Cover text'),
      expectedRenderer: 'renderTextBand',
    },
    {
      id: 'animated-cover-wide-image',
      section: section({
        sectionIndex: 87,
        interactionModel: 'animated-cover',
        height: 680,
        headings: ['Animated cover'],
        bodyText: ['Wide media uses the cover renderer.'],
        images: [backgroundImage('animated-wide.jpg', 1320, 820, 'Animated')],
        backgroundBrightness: 40,
        backgroundColor: 'rgb(18, 28, 42)',
      }),
      expectedRenderer: 'renderCover',
    },
    {
      id: 'animated-cover-contained-media-text',
      section: section({
        sectionIndex: 88,
        interactionModel: 'animated-cover',
        headings: ['Animated contained'],
        bodyText: ['A smaller captured photo uses media text.'],
        images: [image('animated-contained.jpg', 620, 480, 'Contained')],
      }),
      expectedRenderer: 'renderMediaText',
      expectedFlip: false,
    },
    {
      id: 'animated-cover-without-image-text-band',
      section: basicSection(89, 'animated-cover', 'Animated text'),
      expectedRenderer: 'renderTextBand',
    },
    {
      id: 'media-text-model',
      section: section({
        sectionIndex: 90,
        interactionModel: 'media-text',
        headings: ['Media text section'],
        bodyText: ['The explicit model uses media-text.'],
        images: [image('media-text.jpg', 640, 480, 'Media')],
      }),
      expectedRenderer: 'renderMediaText',
      expectedFlip: false,
    },
    { id: 'product-card-row-model', section: cardIntro, expectedRenderer: 'renderCardGrid' },
    { id: 'project-card-grid-model', section: projectCards, expectedRenderer: 'renderCardGrid' },
    {
      id: 'blog-card-grid-model',
      section: { ...projectCards, sectionIndex: 91, interactionModel: 'blog-card-grid' },
      expectedRenderer: 'renderCardGrid',
    },
    {
      id: 'review-grid-model',
      section: {
        ...basicSection(92, 'review-grid', 'Review grid'),
        reviews: [{ category: null, stars: 5, quote: 'The review copy stays verbatim.', author: 'Lee' }],
      },
      expectedRenderer: 'renderReviewGrid',
    },
    {
      id: 'testimonial-model',
      section: {
        ...basicSection(93, 'testimonial', 'Testimonial'),
        reviews: [{ category: null, stars: 4.8, quote: 'A compact testimonial quote.', author: 'Avery' }],
      },
      expectedRenderer: 'renderReviewGrid',
    },
    {
      id: 'gallery-model',
      section: {
        ...basicSection(94, 'gallery', 'Gallery'),
        images: [image('gallery-1.jpg', 520, 360), image('gallery-2.jpg', 520, 360), image('gallery-3.jpg', 520, 360)],
      },
      expectedRenderer: 'renderImageRow',
    },
    {
      id: 'logo-strip-model',
      section: {
        ...basicSection(95, 'logo-strip', 'Logo strip'),
        images: [image('logo-1.png', 240, 120), image('logo-2.png', 240, 120), image('logo-3.png', 240, 120)],
      },
      expectedRenderer: 'renderImageRow',
    },
    {
      id: 'color-block-grid-model',
      section: {
        ...basicSection(96, 'color-block-grid', 'Color grid'),
        images: [image('color-1.jpg', 320, 320), image('color-2.jpg', 320, 320), image('color-3.jpg', 320, 320)],
      },
      expectedRenderer: 'renderImageRow',
    },
    {
      id: 'marquee-strip-model',
      section: {
        ...basicSection(97, 'marquee-strip', 'Marquee strip'),
        images: [image('marquee-1.jpg', 520, 300), image('marquee-2.jpg', 520, 300), image('marquee-3.jpg', 520, 300)],
      },
      expectedRenderer: 'renderImageRow',
    },
  ];

  const defaultTextModels: InteractionModel[] = [
    'static',
    'cta',
    'price-list',
    'app-download',
    'horizontal-showcase',
    'footer',
    'nav',
  ];
  for (const model of defaultTextModels) {
    const chromeSandwich = model === 'footer' || model === 'nav';
    cases.push({
      id: `${model}-default-text-band`,
      section: basicSection(100 + cases.length, model, model),
      expectedRenderer: 'renderTextBand',
      chromeSandwich,
    });
  }

  return cases;
}

export function nativeDispatchRendererCaseGroups(): NativeDispatchRendererCaseGroups {
  return {
    renderCardGrid: [
      { id: 'product-card-row-with-buttons', section: cardIntro, withButtons: true },
      { id: 'project-card-grid-without-buttons', section: projectCards, withButtons: false },
    ],
    renderFaq: [
      { id: 'faq-complete-answers', section: faqComplete },
      { id: 'faq-missing-answer-placeholder', section: faqMissingAnswer },
    ],
    renderCellGrid: [
      { id: 'styled-cell-grid-with-icon-and-card', section: styledCells },
      { id: 'cell-grid-recovers-section-image-column', section: cellWithSectionImage },
      { id: 'full-bleed-cell-grid', section: fullBleedCells },
    ],
    cardGroup: [
      {
        id: 'dark-card-group-with-captured-padding',
        parts: cardGroupParts,
        bgToken: 'surface-inverse',
        dark: true,
        radius: 24,
        padding: { top: 24, right: 32, bottom: 28, left: 32 },
        dlaSection: cardGroupDlaSection,
      },
    ],
    renderSection: renderSectionCases(),
  };
}

function clone<T>(value: T): T {
  if (value === undefined) return value;
  return JSON.parse(JSON.stringify(value)) as T;
}

function freeze(value: unknown): unknown {
  if (value === undefined) return { __type: 'undefined' };
  if (value === null || typeof value !== 'object') return value;
  if (Array.isArray(value)) return value.map(freeze);
  return Object.fromEntries(Object.entries(value).map(([key, nested]) => [key, freeze(nested)]));
}

function capture(id: string, run: () => unknown): NativeDispatchRendererOutputCase {
  return { id, output: freeze(run()) };
}

export function runNativeDispatchRendererParity(impl: NativeDispatchRendererImpl): NativeDispatchRendererParityFile {
  const cases = nativeDispatchRendererCaseGroups();

  return {
    version: 1,
    derivation: NATIVE_DISPATCH_RENDERER_DERIVATION,
    renderers: {
      renderCardGrid: cases.renderCardGrid.map((entry) =>
        capture(entry.id, () => impl.renderCardGrid(clone(entry.section), entry.withButtons)),
      ),
      renderFaq: cases.renderFaq.map((entry) => capture(entry.id, () => impl.renderFaq(clone(entry.section)))),
      renderCellGrid: cases.renderCellGrid.map((entry) =>
        capture(entry.id, () => impl.renderCellGrid(clone(entry.section), nativeDispatchRenderCtx())),
      ),
      cardGroup: cases.cardGroup.map((entry) =>
        capture(entry.id, () => ({
          returnValue: impl.cardGroup(clone(entry.parts), entry.bgToken, entry.dark, entry.radius, clone(entry.padding)),
        })),
      ),
      renderSection: cases.renderSection.map((entry) =>
        capture(entry.id, () => impl.renderSection(clone(entry.section), nativeDispatchRenderCtx())),
      ),
    },
  };
}
