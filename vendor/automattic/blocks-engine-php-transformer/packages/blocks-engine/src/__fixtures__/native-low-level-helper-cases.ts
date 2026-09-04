import type {
  HeadingBlockOptions,
  ImageBlockOptions,
  NativeButtonInput,
  ParagraphBlockOptions,
  TypographyFragments,
  WrapSectionOptions,
} from '../theme/native-block-builders.js';
import type { PaletteToken } from '../theme/native-color.js';
import type { IconImageBlockOptions } from '../theme/native-media.js';
import type { FontFamilyToken } from '../theme/page-reconstruct-helpers.js';
import type { NativeRenderCtx, NativeRenderOut } from '../theme/native-reconstruct-types.js';
import type { SectionSpec, SectionSpecIcon, SectionSpecImage } from '../theme/section-spec.js';

export interface NativeLowLevelImpl {
  MISSING_IMAGE_PLACEHOLDER: string;
  nearestToken(hex: string, tokens: PaletteToken[]): string | null;
  brightness(hex: string): number;
  familyMatches(computed: string, token: FontFamilyToken): boolean;
  nearestFamily(computed: string | undefined, tokens: FontFamilyToken[]): string | null;
  responsiveFontSize(px: number | undefined): string;
  responsiveSpace(px: number): string;
  sectionPad(section: SectionSpec): { padTopPx?: number; padBottomPx?: number };
  centerOf(section: SectionSpec): boolean;
  buttonJustify(section: SectionSpec): 'left' | 'center';
  isTintedSection(section: SectionSpec): boolean;
  opaqueTintHex(color: string | null | undefined): string | null;
  isDarkSection(section: SectionSpec): boolean;
  pickLeadImage(images: SectionSpecImage[]): SectionSpecImage | undefined;
  isWpMediaUrl(url: string): boolean;
  recolorSvg(svg: string, hex: string): string;
  resolveImage(image: SectionSpecImage | undefined, out: NativeRenderOut, context: string): {
    url: string;
    alt: string;
    usable: boolean;
  };
  iconImageBlock(
    icon: SectionSpecIcon,
    out: NativeRenderOut,
    ctx: NativeRenderCtx,
    opts?: IconImageBlockOptions,
  ): string;
  visibleText(html: string): string;
  emptyNativeRenderOut(): NativeRenderOut;
  typographyStyle(fontCss: string, lineHeight?: number): TypographyFragments;
  imageBlock(
    image: SectionSpecImage | undefined,
    out: NativeRenderOut,
    context: string,
    opts?: ImageBlockOptions,
  ): string;
  headingBlock(text: string, out: NativeRenderOut, opts?: HeadingBlockOptions): string;
  paragraphBlock(text: string, out: NativeRenderOut, opts?: ParagraphBlockOptions): string;
  buttonBlock(label: string, out: NativeRenderOut, opts?: { align?: 'left' | 'center' | 'right' }): string;
  ctaButton(
    out: NativeRenderOut,
    ctx: NativeRenderCtx,
    button: NativeButtonInput,
    opts?: { align?: 'left' | 'center' },
  ): string;
  sectionButtons(section: SectionSpec, out: NativeRenderOut, ctx: NativeRenderCtx): string[];
  column(parts: string[], width?: string): string;
  columns(cols: string[], opts?: { fullBleed?: boolean }): string;
  wrapSection(parts: string[], opts: WrapSectionOptions): string;
}

export interface NativeLowLevelCase {
  id: string;
  output: unknown;
}

export interface NativeLowLevelParityFile {
  version: 1;
  derivation: string;
  helpers: Record<string, NativeLowLevelCase[]>;
}

const WP = 'http://localhost:8883/wp-content/uploads/2026/05/';
const CDN = 'https://cdn.example.test/assets/';

function nativeOut(): NativeRenderOut {
  return { markup: '', expectedText: [], bodyText: [], assets: [], flags: [], iconAssets: [] };
}

function nativeCtx(): NativeRenderCtx {
  return {
    mediaTextIndex: 0,
    iconCounter: 0,
    paletteTokens: [
      { slug: 'text-default', hex: '#102030' },
      { slug: 'text-inverse', hex: '#f8fafc' },
      { slug: 'accent-primary', hex: '#008060' },
      { slug: 'surface-raised', hex: '#e8eff1' },
    ],
    fontFamilies: [
      { slug: 'display', family: 'Caldera Display, serif' },
      { slug: 'body', family: 'Caldera, sans-serif' },
      { slug: 'wix', family: 'wf_5499e3d4abcd, sans-serif' },
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

function remoteImage(name: string, width = 800, height = 600): SectionSpecImage {
  return {
    url: `${CDN}${name}`,
    sourceUrl: `${CDN}${name}`,
    alt: name,
    kind: 'img',
    width,
    height,
  };
}

function svgIcon(markup: string): SectionSpecIcon {
  return {
    kind: 'svg',
    markup,
    width: 24,
    height: 24,
  };
}

function section(partial: Partial<SectionSpec> = {}): SectionSpec {
  return {
    sectionIndex: 7,
    interactionModel: 'static',
    top: 0,
    height: 420,
    headings: ['Source Heading'],
    bodyText: ['Source body'],
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
      containerWidth: 1200,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '24px',
    },
    ...partial,
  };
}

function freeze(value: unknown): unknown {
  if (value === undefined) return { __type: 'undefined' };
  if (value === null || typeof value !== 'object') return value;
  if (Array.isArray(value)) return value.map(freeze);
  return Object.fromEntries(Object.entries(value).map(([key, nested]) => [key, freeze(nested)]));
}

function capture(id: string, run: () => unknown): NativeLowLevelCase {
  return { id, output: freeze(run()) };
}

function withOut(run: (out: NativeRenderOut) => unknown): { returnValue: unknown; out: NativeRenderOut } {
  const out = nativeOut();
  return { returnValue: run(out), out };
}

function withOutAndCtx(
  run: (out: NativeRenderOut, ctx: NativeRenderCtx) => unknown,
): { returnValue: unknown; out: NativeRenderOut; ctx: NativeRenderCtx } {
  const out = nativeOut();
  const ctx = nativeCtx();
  return { returnValue: run(out, ctx), out, ctx };
}

export function runNativeLowLevelParity(impl: NativeLowLevelImpl): NativeLowLevelParityFile {
  const lead = image('lead.jpg', 640, 480, 'Lead alt');
  const remote = remoteImage('remote.jpg', 640, 480);
  const small = image('badge.svg', 48, 48, 'Tiny badge');
  const icon = svgIcon('<svg viewBox="0 0 10 10"><path fill="#111" stroke="#222" d="M1 1h8v8H1z"/></svg>');
  const iconNoFill = svgIcon('<svg viewBox="0 0 10 10"><path d="M2 2h6v6H2z"/></svg>');
  const palette = nativeCtx().paletteTokens;
  const fonts = nativeCtx().fontFamilies;

  return {
    version: 1,
    derivation:
      'Mechanically generated from DLA page-reconstruct.ts low-level helpers and footer-color.ts color helpers at DLA commit 1e393c5.',
    helpers: {
      MISSING_IMAGE_PLACEHOLDER: [capture('constant', () => impl.MISSING_IMAGE_PLACEHOLDER)],
      visibleText: [
        capture('tags-and-whitespace', () => impl.visibleText('<h2> Hello <em>there</em></h2>\n<p>Again</p>')),
      ],
      nearestToken: [
        capture('nearest-hex', () => impl.nearestToken('#00815f', palette)),
        capture('nearest-rgb', () => impl.nearestToken('rgb(246, 248, 250)', palette)),
        capture('invalid-color', () => impl.nearestToken('not-a-color', palette)),
      ],
      brightness: [
        capture('hex', () => impl.brightness('#008060')),
        capture('rgb', () => impl.brightness('rgb(16, 32, 48)')),
        capture('invalid', () => impl.brightness('currentColor')),
      ],
      familyMatches: [
        capture('exact-body', () => impl.familyMatches('caldera', fonts[1])),
        capture('hash-overlap', () => impl.familyMatches('wfont_abc_5499e3d4abcd', fonts[2])),
        capture('substring', () => impl.familyMatches('caldera display', fonts[0])),
        capture('miss', () => impl.familyMatches('unrelated', fonts[0])),
      ],
      nearestFamily: [
        capture('body-priority', () => impl.nearestFamily('"Caldera"', fonts)),
        capture('display', () => impl.nearestFamily('Caldera Display', fonts)),
        capture('unknown-falls-body', () => impl.nearestFamily('wix-obfuscated-handle', fonts)),
        capture('generic-null', () => impl.nearestFamily('serif', fonts)),
      ],
      responsiveFontSize: [
        capture('missing', () => impl.responsiveFontSize(undefined)),
        capture('hero', () => impl.responsiveFontSize(92)),
        capture('small', () => impl.responsiveFontSize(12)),
      ],
      responsiveSpace: [
        capture('negative', () => impl.responsiveSpace(-10)),
        capture('small', () => impl.responsiveSpace(16)),
        capture('large', () => impl.responsiveSpace(96)),
      ],
      sectionPad: [
        capture('none', () => impl.sectionPad(section())),
        capture('top-bottom', () =>
          impl.sectionPad(section({ layout: { ...section().layout, padTopPx: 48, padBottomPx: 80 } })),
        ),
      ],
      centerOf: [
        capture('default-center', () => impl.centerOf(section())),
        capture('left', () => impl.centerOf(section({ textAlign: 'left' }))),
      ],
      buttonJustify: [
        capture('center', () => impl.buttonJustify(section({ textAlign: 'center' }))),
        capture('left-for-right-source', () => impl.buttonJustify(section({ textAlign: 'right' }))),
      ],
      isTintedSection: [
        capture('saturated-light', () =>
          impl.isTintedSection(section({ backgroundBrightness: 220, backgroundColor: 'rgb(232, 239, 241)' })),
        ),
        capture('near-white', () =>
          impl.isTintedSection(section({ backgroundBrightness: 248, backgroundColor: 'rgb(250, 250, 250)' })),
        ),
        capture('dark', () =>
          impl.isTintedSection(section({ backgroundBrightness: 80, backgroundColor: 'rgb(20, 80, 100)' })),
        ),
      ],
      opaqueTintHex: [
        capture('rgb-tint', () => impl.opaqueTintHex('rgb(232, 239, 241)')),
        capture('rgba-faint', () => impl.opaqueTintHex('rgba(232, 239, 241, 0.4)')),
        capture('near-white', () => impl.opaqueTintHex('#ffffff')),
        capture('light-neutral', () => impl.opaqueTintHex('#eeeeec')),
      ],
      isDarkSection: [
        capture('dark', () => impl.isDarkSection(section({ backgroundBrightness: 40 }))),
        capture('light', () => impl.isDarkSection(section({ backgroundBrightness: 180 }))),
      ],
      pickLeadImage: [
        capture('skips-small', () => impl.pickLeadImage([small, lead])),
        capture('none', () => impl.pickLeadImage([small])),
      ],
      isWpMediaUrl: [
        capture('wp', () => impl.isWpMediaUrl(`${WP}hero.jpg`)),
        capture('remote', () => impl.isWpMediaUrl(`${CDN}hero.jpg`)),
      ],
      recolorSvg: [
        capture('replace-fill-stroke', () => impl.recolorSvg(icon.markup ?? '', '#ffffff')),
        capture('preserve-none', () =>
          impl.recolorSvg('<svg><path fill="none" stroke="none" d="M1 1h2"/></svg>', '#ffffff'),
        ),
      ],
      resolveImage: [
        capture('missing', () => withOut((out) => impl.resolveImage(undefined, out, 'hero#1'))),
        capture('remote', () => withOut((out) => impl.resolveImage(remote, out, 'hero#2'))),
        capture('wp', () => withOut((out) => impl.resolveImage(lead, out, 'hero#3'))),
      ],
      iconImageBlock: [
        capture('center-fill', () =>
          withOutAndCtx((out, ctx) => impl.iconImageBlock(iconNoFill, out, ctx, { sizePx: 32, fill: '#ffffff' })),
        ),
        capture('left-no-fill', () =>
          withOutAndCtx((out, ctx) => impl.iconImageBlock(icon, out, ctx, { align: 'left' })),
        ),
        capture('glyph-empty', () =>
          withOutAndCtx((out, ctx) =>
            impl.iconImageBlock({ kind: 'glyph', glyph: '*', width: 16, height: 16 }, out, ctx),
          ),
        ),
      ],
      emptyNativeRenderOut: [capture('empty', () => impl.emptyNativeRenderOut())],
      typographyStyle: [
        capture('font-and-line-height', () => impl.typographyStyle('clamp(20px, 4.0vw, 60px)', 1.1)),
        capture('invalid-line-height', () => impl.typographyStyle('16px', 3)),
      ],
      imageBlock: [
        capture('wp-rounded-center', () =>
          withOut((out) => impl.imageBlock(lead, out, 'image#1', { rounded: true, align: 'center' })),
        ),
        capture('remote-placeholder', () =>
          withOut((out) => impl.imageBlock(remote, out, 'image#2', { rounded: true })),
        ),
      ],
      headingBlock: [
        capture('heading-rich', () =>
          withOut((out) =>
            impl.headingBlock('  Launch  <Now> ', out, {
              level: 1,
              center: true,
              inverse: true,
              sizePx: 64,
              fontFamily: 'display',
              lineHeight: 1.05,
            }),
          ),
        ),
        capture('empty', () => withOut((out) => impl.headingBlock('   ', out))),
      ],
      paragraphBlock: [
        capture('paragraph-rich', () =>
          withOut((out) =>
            impl.paragraphBlock(' Body & copy ', out, {
              center: true,
              muted: false,
              sizePx: 18,
              fontFamily: 'body',
              lineHeight: 1.5,
            }),
          ),
        ),
        capture('size-slug', () => withOut((out) => impl.paragraphBlock('Small copy', out, { size: 'small' }))),
      ],
      buttonBlock: [
        capture('default', () => withOut((out) => impl.buttonBlock(' Buy <Now> ', out))),
        capture('left', () => withOut((out) => impl.buttonBlock('Learn', out, { align: 'left' }))),
      ],
      ctaButton: [
        capture('colored-link-icon-after', () =>
          withOutAndCtx((out, ctx) =>
            impl.ctaButton(
              out,
              ctx,
              {
                label: 'Shop',
                href: '/shop',
                background: 'rgb(0, 128, 96)',
                color: 'rgb(248, 250, 252)',
                icon: iconNoFill,
                iconAfter: true,
              },
              { align: 'left' },
            ),
          ),
        ),
        capture('icon-only', () =>
          withOutAndCtx((out, ctx) => impl.ctaButton(out, ctx, { label: '', icon: iconNoFill })),
        ),
      ],
      sectionButtons: [
        capture('structured', () =>
          withOutAndCtx((out, ctx) =>
            impl.sectionButtons(
              section({
                textAlign: 'left',
                buttons: [{ label: 'Contact', href: '/contact', background: '#008060', color: '#f8fafc' }],
              }),
              out,
              ctx,
            ),
          ),
        ),
        capture('labels', () =>
          withOutAndCtx((out, ctx) =>
            impl.sectionButtons(section({ buttonLabels: ['Alpha', 'Beta'], textAlign: 'center' }), out, ctx),
          ),
        ),
      ],
      column: [
        capture('auto', () => impl.column(['A', 'B'])),
        capture('width', () => impl.column(['A'], '33.33%')),
      ],
      columns: [
        capture('empty', () => impl.columns([])),
        capture('standard', () => impl.columns(['<p>A</p>', '<p>B</p>'])),
        capture('full-bleed', () => impl.columns(['<p>A</p>'], { fullBleed: true })),
      ],
      wrapSection: [
        capture('empty', () => impl.wrapSection([], {})),
        capture('raised-constrained', () =>
          impl.wrapSection(['<p>Body</p>'], {
            constrained: '760px',
            raised: true,
            bgColor: 'rgb(232, 239, 241)',
            padTopPx: 48,
            padBottomPx: 64,
          }),
        ),
        capture('inverse-full-bleed', () =>
          impl.wrapSection(['<p>Inverse</p>'], {
            inverse: true,
            fullBleed: true,
            padTopPx: 12,
            padBottomPx: 12,
          }),
        ),
      ],
    },
  };
}
