import type { GalleryBlockOptions } from '../theme/native-renderers-text.js';
import type { NativeRenderCtx, NativeRenderOut } from '../theme/native-reconstruct-types.js';
import type { SectionSpec, SectionSpecImage } from '../theme/section-spec.js';

export const NATIVE_TEXT_RENDERER_DERIVATION =
  'Mechanically generated from DLA src/lib/replicate/page-reconstruct.ts reconstructPagePattern at DLA commit 1e393c5; section renderer markup strips the final reconstruct body newline, and direct galleryBlock outputs extract the nested wp:gallery block.';

export interface NativeTextRendererImpl {
  renderTextBand(section: SectionSpec, ctx: NativeRenderCtx): NativeRenderOut;
  renderCover(section: SectionSpec, ctx: NativeRenderCtx): NativeRenderOut;
  renderMediaText(section: SectionSpec, flip: boolean, ctx: NativeRenderCtx): NativeRenderOut;
  galleryBlock(images: SectionSpecImage[], out: NativeRenderOut, opts?: GalleryBlockOptions): string;
}

export interface NativeSectionRendererCase {
  id: string;
  section: SectionSpec;
}

export interface NativeMediaTextRendererCase extends NativeSectionRendererCase {
  flip: boolean;
}

export interface NativeGalleryBlockCase {
  id: string;
  images: SectionSpecImage[];
  opts?: GalleryBlockOptions;
}

export interface NativeTextRendererCaseGroups {
  renderTextBand: NativeSectionRendererCase[];
  renderCover: NativeSectionRendererCase[];
  renderMediaText: NativeMediaTextRendererCase[];
  galleryBlock: NativeGalleryBlockCase[];
}

export interface NativeTextRendererOutputCase {
  id: string;
  output: unknown;
}

export interface NativeTextRendererParityFile {
  version: 1;
  derivation: string;
  renderers: Record<keyof NativeTextRendererCaseGroups, NativeTextRendererOutputCase[]>;
}

const WP = 'http://localhost:8883/wp-content/uploads/2026/05/';
const CDN = 'https://cdn.example.test/native-text/';

export function nativeRenderOut(): NativeRenderOut {
  return { markup: '', expectedText: [], bodyText: [], assets: [], flags: [], iconAssets: [] };
}

export function nativeRenderCtx(): NativeRenderCtx {
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
      containerWidth: 1180,
      padding: '0px',
      childLayout: 'stack',
      columnCount: 1,
      gap: '24px',
    },
    ...partial,
  };
}

export function nativeTextRendererCaseGroups(): NativeTextRendererCaseGroups {
  const textBand = section({
    sectionIndex: 21,
    interactionModel: 'static',
    height: 560,
    headings: ['Build calmer block themes', 'Without losing source copy'],
    headingSizes: [58, 24],
    headingLineHeights: [1.05, 1.25],
    textAlign: 'center',
    bodyText: ['Every heading, paragraph, button, and media asset traces back to capture.'],
    bodyTextSizes: [18],
    bodyLineHeights: [1.55],
    buttonLabels: ['See the demo'],
    buttons: [
      {
        label: 'See the demo',
        href: '/demo',
        background: 'rgb(0, 128, 96)',
        color: 'rgb(248, 250, 252)',
      },
    ],
    images: [image('theme-preview.jpg', 720, 480, 'Theme preview')],
    backgroundBrightness: 35,
    backgroundColor: 'rgb(16, 32, 48)',
    layout: {
      containerWidth: 1180,
      padding: '0px',
      childLayout: 'stack',
      columnCount: 1,
      gap: '28px',
      padTopPx: 72,
      padBottomPx: 80,
    },
  });

  const cover = section({
    sectionIndex: 22,
    interactionModel: 'cover-with-headline',
    height: 733,
    fullBleed: true,
    headings: ['A native cover from captured hero media'],
    headingSizes: [68],
    headingLineHeights: [1.02],
    textAlign: 'center',
    bodyText: ['The headline and CTA stay over the migrated background image.'],
    bodyTextSizes: [20],
    bodyLineHeights: [1.5],
    buttonLabels: ['Explore patterns'],
    buttons: [
      {
        label: 'Explore patterns',
        href: '/patterns',
        background: 'rgb(255, 255, 255)',
        color: 'rgb(16, 32, 48)',
      },
    ],
    images: [backgroundImage('hero-cover.jpg', 1440, 900, 'Workspace with block patterns')],
    backgroundBrightness: 42,
    backgroundColor: 'rgb(18, 28, 42)',
  });

  const mediaTextRight = section({
    sectionIndex: 23,
    interactionModel: 'media-text',
    height: 560,
    headings: ['Media beside structured copy'],
    headingSizes: [40],
    headingLineHeights: [1.15],
    textAlign: 'left',
    bodyText: ['A two-up row carries the main product image and preserves the sample strip below it.'],
    bodyTextSizes: [17],
    bodyLineHeights: [1.6],
    buttonLabels: ['Compare specs'],
    buttons: [
      {
        label: 'Compare specs',
        href: '/compare',
        background: 'rgb(0, 128, 96)',
        color: 'rgb(248, 250, 252)',
      },
    ],
    images: [
      image('media-primary.jpg', 720, 520, 'Primary product'),
      image('sample-oak.jpg', 320, 180, 'Oak sample'),
      image('sample-walnut.jpg', 300, 180, 'Walnut sample'),
      image('sample-maple.jpg', 280, 180, 'Maple sample'),
    ],
    backgroundBrightness: 236,
    backgroundColor: 'rgb(232, 239, 241)',
    layout: {
      containerWidth: 1100,
      padding: '0px',
      childLayout: 'flex-row',
      columnCount: 2,
      gap: '36px',
      padTopPx: 64,
      padBottomPx: 64,
    },
  });

  const mediaTextLeft = section({
    sectionIndex: 24,
    interactionModel: 'static',
    mediaLayout: 'image-left',
    height: 520,
    headings: ['Image-led story row'],
    headingSizes: [36],
    headingLineHeights: [1.16],
    textAlign: 'left',
    bodyText: ['When the source puts media first, the parity corpus fixes the column order.'],
    bodyTextSizes: [16],
    bodyLineHeights: [1.55],
    images: [
      image('story-portrait.jpg', 520, 640, 'Portrait crop'),
      image('story-detail.jpg', 360, 260, 'Detail crop'),
    ],
    backgroundBrightness: 255,
    backgroundColor: 'rgb(255, 255, 255)',
    layout: {
      containerWidth: 1080,
      padding: '0px',
      childLayout: 'flex-row',
      columnCount: 2,
      gap: '32px',
      padTopPx: 48,
      padBottomPx: 56,
    },
  });

  return {
    renderTextBand: [{ id: 'dark-band-cta-lead-image', section: textBand }],
    renderCover: [{ id: 'full-bleed-hero-cover', section: cover }],
    renderMediaText: [
      { id: 'image-right-with-gallery-strip', section: mediaTextRight, flip: false },
      { id: 'image-left-with-stacked-extra', section: mediaTextLeft, flip: true },
    ],
    galleryBlock: [
      {
        id: 'scroller-landscape-strip',
        images: [
          image('gallery-wide-1.jpg', 900, 420, 'Wide one'),
          image('gallery-wide-2.jpg', 780, 420, 'Wide two'),
          image('gallery-tall.jpg', 360, 540, 'Tall item'),
          image('gallery-wide-3.jpg', 640, 360, 'Wide three'),
        ],
        opts: { sectionHeight: 240 },
      },
      {
        id: 'multi-row-cropped-grid',
        images: [
          image('gallery-grid-1.jpg', 320, 320, 'Grid one'),
          image('gallery-grid-2.jpg', 320, 320, 'Grid two'),
          image('gallery-grid-3.jpg', 320, 320, 'Grid three'),
          image('gallery-grid-4.jpg', 320, 320, 'Grid four'),
        ],
        opts: { sectionHeight: 760 },
      },
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

function capture(id: string, run: () => unknown): NativeTextRendererOutputCase {
  return { id, output: freeze(run()) };
}

export function runNativeTextRendererParity(impl: NativeTextRendererImpl): NativeTextRendererParityFile {
  const cases = nativeTextRendererCaseGroups();

  return {
    version: 1,
    derivation: NATIVE_TEXT_RENDERER_DERIVATION,
    renderers: {
      renderTextBand: cases.renderTextBand.map((entry) =>
        capture(entry.id, () => impl.renderTextBand(clone(entry.section), nativeRenderCtx())),
      ),
      renderCover: cases.renderCover.map((entry) =>
        capture(entry.id, () => impl.renderCover(clone(entry.section), nativeRenderCtx())),
      ),
      renderMediaText: cases.renderMediaText.map((entry) =>
        capture(entry.id, () => impl.renderMediaText(clone(entry.section), entry.flip, nativeRenderCtx())),
      ),
      galleryBlock: cases.galleryBlock.map((entry) =>
        capture(entry.id, () => {
          const out = nativeRenderOut();
          return {
            returnValue: impl.galleryBlock(clone(entry.images), out, clone(entry.opts)),
            out,
          };
        }),
      ),
    },
  };
}
