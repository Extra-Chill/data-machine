import type { NativeRenderOut } from '../theme/native-reconstruct-types.js';
import type { SectionSpec, SectionSpecImage } from '../theme/section-spec.js';

export const NATIVE_SECTION_RENDERER_DERIVATION =
  'Mechanically generated from DLA src/lib/replicate/page-reconstruct.ts reconstructPagePattern at DLA commit 1e393c5; section renderer markup strips the final reconstruct body newline.';

export interface NativeSectionRendererImpl {
  renderReviewGrid(section: SectionSpec): NativeRenderOut;
  renderImageRow(section: SectionSpec): NativeRenderOut;
}

export interface NativeSectionRendererCase {
  id: string;
  section: SectionSpec;
}

export interface NativeSectionRendererCaseGroups {
  renderReviewGrid: NativeSectionRendererCase[];
  renderImageRow: NativeSectionRendererCase[];
}

export interface NativeSectionRendererOutputCase {
  id: string;
  output: unknown;
}

export interface NativeSectionRendererParityFile {
  version: 1;
  derivation: string;
  renderers: Record<keyof NativeSectionRendererCaseGroups, NativeSectionRendererOutputCase[]>;
}

const WP = 'http://localhost:8883/wp-content/uploads/2026/05/';
const CDN = 'https://cdn.example.test/native-section/';

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

export function nativeSectionRendererCaseGroups(): NativeSectionRendererCaseGroups {
  const structuredReviews = section({
    sectionIndex: 41,
    interactionModel: 'review-grid',
    height: 520,
    headings: ['Loved by studio teams', '- mobile duplicate'],
    headingSizes: [36, 36],
    headingLineHeights: [1.15, 1.15],
    textAlign: 'center',
    reviews: [
      {
        category: 'studio',
        stars: 5,
        quote: 'The sample wall finally matches how clients actually shop.',
        author: 'Mara Lee',
      },
      {
        category: 'contractor',
        stars: 4.6,
        quote: 'Clean ordering, clear photography, and no extra showroom calls.',
        author: 'Jules Park',
      },
    ],
    backgroundBrightness: 28,
    backgroundColor: 'rgb(13, 31, 52)',
    layout: {
      containerWidth: 1100,
      padding: '0px',
      childLayout: 'grid',
      columnCount: 2,
      gap: '28px',
      padTopPx: 72,
      padBottomPx: 76,
    },
  });

  const bodyTextFallbackReviews = section({
    sectionIndex: 42,
    interactionModel: 'review-grid',
    height: 460,
    headings: ['Customer notes'],
    headingSizes: [32],
    headingLineHeights: [1.18],
    textAlign: 'center',
    bodyText: ['4.8 / 5 rating', 'Installers understood the choices before they arrived.', 'Avery Stone'],
    bodyTextSizes: [15, 18, 14],
    bodyLineHeights: [1.35, 1.55, 1.35],
    backgroundBrightness: 244,
    backgroundColor: 'rgb(239, 248, 245)',
    layout: {
      containerWidth: 980,
      padding: '0px',
      childLayout: 'stack',
      columnCount: 1,
      gap: '22px',
      padTopPx: 56,
      padBottomPx: 58,
    },
  });

  const placeholderReviewGap = section({
    sectionIndex: 43,
    interactionModel: 'review-grid',
    height: 360,
    headings: ['Reviews from homeowners'],
    headingSizes: [30],
    headingLineHeights: [1.2],
    textAlign: 'center',
    backgroundBrightness: 248,
    backgroundColor: 'rgb(255, 255, 255)',
    layout: {
      containerWidth: 900,
      padding: '0px',
      childLayout: 'stack',
      columnCount: 1,
      gap: '20px',
      padTopPx: 48,
      padBottomPx: 52,
    },
  });

  const imageScroller = section({
    sectionIndex: 51,
    interactionModel: 'gallery',
    height: 246,
    headings: ['Recent installs'],
    headingSizes: [34],
    headingLineHeights: [1.16],
    textAlign: 'center',
    bodyText: ['A compact single-row strip keeps mixed aspect ratios intact.'],
    bodyTextSizes: [17],
    bodyLineHeights: [1.55],
    images: [
      image('install-wide-1.jpg', 900, 420, 'Wide install one'),
      image('install-wide-2.jpg', 780, 420, 'Wide install two'),
      image('install-tall.jpg', 360, 540, 'Tall install'),
      image('install-wide-3.jpg', 640, 360, 'Wide install three'),
    ],
    backgroundBrightness: 255,
    backgroundColor: 'rgb(255, 255, 255)',
    layout: {
      containerWidth: 1100,
      padding: '0px',
      childLayout: 'grid',
      columnCount: 4,
      gap: '16px',
      padTopPx: 44,
      padBottomPx: 48,
    },
  });

  const imageWrappingGrid = section({
    sectionIndex: 52,
    interactionModel: 'color-block-grid',
    height: 760,
    headings: ['Finish palette'],
    headingSizes: [32],
    headingLineHeights: [1.18],
    textAlign: 'center',
    images: [
      image('finish-oak.jpg', 320, 320, 'Oak finish'),
      image('finish-walnut.jpg', 320, 320, 'Walnut finish'),
      image('finish-maple.jpg', 320, 320, 'Maple finish'),
      image('finish-ash.jpg', 320, 320, 'Ash finish'),
    ],
    backgroundBrightness: 232,
    backgroundColor: 'rgb(232, 239, 241)',
    layout: {
      containerWidth: 1100,
      padding: '0px',
      childLayout: 'grid',
      columnCount: 4,
      gap: '18px',
      padTopPx: 60,
      padBottomPx: 64,
    },
  });

  return {
    renderReviewGrid: [
      { id: 'structured-review-cards-dark-band', section: structuredReviews },
      { id: 'body-text-fallback-without-structured-reviews', section: bodyTextFallbackReviews },
      { id: 'placeholder-review-gap', section: placeholderReviewGap },
    ],
    renderImageRow: [
      { id: 'gallery-scroller-mixed-aspect-strip', section: imageScroller },
      { id: 'color-block-grid-wrapping-gallery', section: imageWrappingGrid },
    ],
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

function capture(id: string, run: () => unknown): NativeSectionRendererOutputCase {
  return { id, output: freeze(run()) };
}

export function runNativeSectionRendererParity(impl: NativeSectionRendererImpl): NativeSectionRendererParityFile {
  const cases = nativeSectionRendererCaseGroups();

  return {
    version: 1,
    derivation: NATIVE_SECTION_RENDERER_DERIVATION,
    renderers: {
      renderReviewGrid: cases.renderReviewGrid.map((entry) =>
        capture(entry.id, () => impl.renderReviewGrid(clone(entry.section))),
      ),
      renderImageRow: cases.renderImageRow.map((entry) =>
        capture(entry.id, () => impl.renderImageRow(clone(entry.section))),
      ),
    },
  };
}
