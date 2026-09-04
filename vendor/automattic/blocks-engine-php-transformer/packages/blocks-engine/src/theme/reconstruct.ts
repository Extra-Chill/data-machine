import * as cheerio from 'cheerio';
import { escapeHtmlAttr } from '../escape.js';
import type { WorkerPool } from '../pool/types.js';
import { buildFallbackDiagnostic } from './fallback-diagnostic.js';
import { formToBlocks, SKIPPED_FIELD_KINDS } from './form-blocks.js';
import { buildHtmlFallbackBlock, selectIslandSource, type HtmlFallbackOpts } from './html-fallback.js';
import { hasUnmigratedRemoteAsset, scanForInjection } from './injection-scan.js';
import { preserveDomStrategy } from './preserve-dom/strategy.js';
import { imageBlock, visibleText } from './native-block-builders.js';
import { nearestFamily } from './native-fonts.js';
import { centerOf } from './native-layout.js';
import {
  MISSING_IMAGE_PLACEHOLDER,
  isUsableNativeImage,
  resolveNativeImageUrl,
  type NativeImageResolutionContext,
} from './native-media.js';
import { renderSection } from './native-renderers-dispatch.js';
import type {
  ConvertedSectionInput,
  NativeReconstructAggregate,
  NativeRenderCtx,
  NativeRenderOut,
  NativeSectionDecision,
  SectionRenderOptions,
  SectionStrategy,
  StrategyState,
} from './native-reconstruct-types.js';
import { normalizeCopy, stripChrome } from './page-reconstruct-helpers.js';
import {
  captureSectionContent,
  foldText,
  measureConvertedCoverage,
  measureSectionCoverage,
  TEXT_FLOOR,
} from './section-coverage.js';
import type { CapturedSectionContent, CoverageResult } from './section-coverage.js';
import type { SectionSpec, SectionSpecCell, SectionSpecImage } from './section-spec.js';
import type { SectionBlocks, SiteToThemeHooks, StageCtx } from './types.js';
import { rewriteMediaUrls } from './url-rewrite.js';
import type { InternalLinkMap } from './url-rewrite.js';

const HTML_ISLAND_RE = /<!--\s+wp:(?:core\/)?html(?:\s|-->|{)/;
const UNRESOLVED_PLACEHOLDER_RE = /\{\{[\w -]+\}\}/;
const MIN_CELL_IMAGE_PX = 90;
const SECTION_CLOSE = '</section>\n<!-- /wp:group -->';

type RewriteCtx = StageCtx & SectionRenderOptions & {
  linkMap?: InternalLinkMap;
};

type SourceIdentitySection = SectionSpec & {
  sourceId?: string;
  sourceClasses?: string[];
};

function fallbackOptions(options: SectionRenderOptions | RewriteCtx): HtmlFallbackOpts {
  const rewriteCtx = options as RewriteCtx;
  const opts: HtmlFallbackOpts = {};
  if (rewriteCtx.mediaUrlMap) opts.mediaUrlMap = rewriteCtx.mediaUrlMap;
  if (rewriteCtx.linkMap) opts.linkMap = rewriteCtx.linkMap;
  return opts;
}

function rewriteConvertedMedia(blocks: string, options: SectionRenderOptions): string {
  const mediaUrlMap = options.mediaUrlMap;
  return mediaUrlMap && mediaUrlMap.size > 0 ? rewriteMediaUrls(blocks, mediaUrlMap) : blocks;
}

function convertedOutputLost(captured: CapturedSectionContent, blocks: string): CoverageResult | null {
  if (
    scanForInjection(blocks).length > 0 ||
    hasUnmigratedRemoteAsset(blocks) ||
    UNRESOLVED_PLACEHOLDER_RE.test(blocks)
  ) {
    return null;
  }
  const coverage = measureConvertedCoverage(captured, blocks);
  return coverage.lost ? null : coverage;
}

function publicCoverage(blocks: string, coverage: CoverageResult): number {
  if (HTML_ISLAND_RE.test(blocks)) return 0;
  return coverage.lost ? 0 : 1;
}

function dedupe(values: string[]): string[] {
  return [...new Set(values.filter(Boolean))];
}

function jsonAttrs(attrs: Record<string, unknown>): string {
  return JSON.stringify(attrs).replace(/--/g, '\\u002d\\u002d');
}

function classTokens(value: string | undefined): string[] {
  return value ? value.split(/\s+/).filter(Boolean) : [];
}

function sourceIdentity(section: SectionSpec): { anchor?: string; classes: string[] } {
  const source = section as SourceIdentitySection;
  return {
    ...(source.sourceId?.trim() ? { anchor: source.sourceId.trim() } : {}),
    classes: dedupe(
      (source.sourceClasses ?? [])
        .map((value) => value.trim())
        .filter((value) => value && !isGeneratedGroupWrapperClass(value)),
    ),
  };
}

function isGeneratedGroupWrapperClass(value: string): boolean {
  return (
    value === 'wp-block-group' ||
    value === 'has-text-color' ||
    value === 'has-background' ||
    /^align(?:full|wide|left|right|center)$/.test(value) ||
    /^is-layout-[a-z0-9-]+$/.test(value) ||
    /^has-[a-z0-9-]+-(?:color|background-color|gradient-background)$/.test(value)
  );
}

function orderGroupAttrsWithIdentity(
  attrs: Record<string, unknown>,
  anchor: string | undefined,
  className: string | undefined,
): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const key of ['tagName', 'align']) {
    if (key in attrs) out[key] = attrs[key];
  }
  if (anchor) out.anchor = anchor;
  if (className) out.className = className;
  for (const [key, value] of Object.entries(attrs)) {
    if (key === 'tagName' || key === 'align' || key === 'anchor' || key === 'className') continue;
    out[key] = value;
  }
  return out;
}

function mergeSectionOpenTagIdentity(openTag: string, anchor: string | undefined, sourceClasses: string[]): string {
  let out = openTag;
  if (anchor) {
    const id = escapeHtmlAttr(anchor);
    out = /\sid="[^"]*"/.test(out)
      ? out.replace(/\sid="[^"]*"/, ` id="${id}"`)
      : out.replace(/^<section\b/, `<section id="${id}"`);
  }
  if (sourceClasses.length > 0) {
    out = /\bclass="([^"]*)"/.test(out)
      ? out.replace(/\bclass="([^"]*)"/, (_match, value: string) => {
          const merged = dedupe([...classTokens(value), ...sourceClasses]);
          return `class="${escapeHtmlAttr(merged.join(' '))}"`;
        })
      : out.replace(/^<section\b/, `<section class="${escapeHtmlAttr(sourceClasses.join(' '))}"`);
  }
  return out;
}

function sectionOpenClassTokens(openTag: string): string[] {
  return classTokens(/\bclass="([^"]*)"/.exec(openTag)?.[1]);
}

function preserveNativePlaceholderSourceIdentity(markup: string, section: SectionSpec): string {
  const identity = sourceIdentity(section);
  if (!identity.anchor && identity.classes.length === 0) return markup;

  const groupMatch = /^(\s*<!-- wp:group )(\{[^\n]+})( -->\n)(<section\b[^>]*>)/.exec(markup);
  if (!groupMatch) return markup;

  let attrs: Record<string, unknown>;
  try {
    attrs = JSON.parse(groupMatch[2]) as Record<string, unknown>;
  } catch {
    return markup;
  }

  const generatedSectionClasses = new Set(sectionOpenClassTokens(groupMatch[4]));
  const sourceClassNameClasses = identity.classes.filter((value) => !generatedSectionClasses.has(value));
  const className = dedupe([
    ...classTokens(typeof attrs.className === 'string' ? attrs.className : undefined),
    ...sourceClassNameClasses,
  ]).join(' ');
  const nextAttrs = orderGroupAttrsWithIdentity(attrs, identity.anchor, className || undefined);
  const nextOpenTag = mergeSectionOpenTagIdentity(groupMatch[4], identity.anchor, identity.classes);
  return markup.replace(groupMatch[0], `${groupMatch[1]}${jsonAttrs(nextAttrs)}${groupMatch[3]}${nextOpenTag}`);
}

function nativeCapturedContent(section: SectionSpec): CapturedSectionContent {
  const captured = captureSectionContent(section);
  if (captured.images.length > 0 || !(section.sectionHtml || section.styledHtml)) return captured;

  const source = section.sectionHtml ?? section.styledHtml ?? '';
  const $ = cheerio.load(source, null, false);
  const sourceImages = $('img[src]')
    .map((_, element) => $(element).attr('src')?.trim() ?? '')
    .get()
    .filter(Boolean);

  return sourceImages.length ? { ...captured, images: dedupe(sourceImages) } : captured;
}

function normalizeSections(sections: SectionSpec[], options: SectionRenderOptions): SectionSpec[] {
  const famTokens = options.fontFamilies ?? [];
  const resolveFamilies = (names?: string[]): string[] | undefined =>
    names ? names.map((name) => nearestFamily(name, famTokens) ?? '') : undefined;
  const sourceIdentity = (section: SectionSpec): Partial<SourceIdentitySection> => {
    const current = section as SourceIdentitySection;
    const patch: Partial<SourceIdentitySection> = {};
    const readRoot = (sourceHtml?: string): { id?: string; classes: string[] } => {
      if (!sourceHtml) return { classes: [] };
      const root = cheerio.load(sourceHtml, null, false).root().children().first();
      return {
        id: root.attr('id')?.trim() || undefined,
        classes: (root.attr('class') ?? '').split(/\s+/).filter(Boolean),
      };
    };
    const sectionRoot = readRoot(section.sectionHtml);
    const styledRoot = readRoot(section.styledHtml);
    const id = sectionRoot.id ?? styledRoot.id;
    const classes = sectionRoot.classes.length > 0 ? sectionRoot.classes : styledRoot.classes;
    if (!('sourceId' in current) && id) patch.sourceId = id;
    if (!('sourceClasses' in current) && classes.length > 0) patch.sourceClasses = classes;
    return patch;
  };

  return stripChrome(sections).map((section) => {
    const headFamSlugs = resolveFamilies(section.headingFamilies);
    const bodyFamSlugs = resolveFamilies(section.bodyFamilies);
    const identity = sourceIdentity(section);
    let out = section;
    if (headFamSlugs || bodyFamSlugs || Object.keys(identity).length > 0) {
      out = {
        ...out,
        ...(headFamSlugs ? { headingFamilies: headFamSlugs } : {}),
        ...(bodyFamSlugs ? { bodyFamilies: bodyFamSlugs } : {}),
        ...identity,
      };
    }

    const sourceHtml = section.sectionHtml ?? section.styledHtml;
    if (!(sourceHtml && (out.bodyText ?? []).length && out.headings.length)) return out;

    const sourceText = foldText(cheerio.load(sourceHtml, null, false).root().text());
    const headingSet = new Set(out.headings.map((heading) => foldText(heading)));
    const countOccurrences = (needle: string): number => {
      if (!needle) return 0;
      let count = 0;
      for (
        let index = sourceText.indexOf(needle);
        index !== -1;
        index = sourceText.indexOf(needle, index + needle.length)
      ) {
        count++;
      }
      return count;
    };
    const keep = (out.bodyText ?? []).map((body) => {
      const normalized = foldText(body);
      return !(headingSet.has(normalized) && countOccurrences(normalized) === 1);
    });

    if (!keep.includes(false)) return out;

    const filterAligned = <T>(arr: T[]): T[] => arr.filter((_, index) => keep[index] !== false);
    return {
      ...out,
      bodyText: (out.bodyText ?? []).filter((_, index) => keep[index]),
      ...(out.bodyTextSizes ? { bodyTextSizes: filterAligned(out.bodyTextSizes) } : {}),
      ...(out.bodyFamilies ? { bodyFamilies: filterAligned(out.bodyFamilies) } : {}),
      ...(out.bodyLineHeights ? { bodyLineHeights: filterAligned(out.bodyLineHeights) } : {}),
    };
  });
}

function renderSectionForms(section: SectionSpec, expectedText: string[], flags: string[]): string {
  if (!section.forms || section.forms.length === 0) return '';
  const parts: string[] = [];
  for (const form of section.forms) {
    const formBlocks = formToBlocks(form);
    parts.push(formBlocks.markup);
    for (const field of form.fields) {
      if (SKIPPED_FIELD_KINDS.has(field.kind)) continue;
      expectedText.push(field.label, ...(field.options ?? []));
    }
    expectedText.push(form.submitLabel);
    for (const skipped of formBlocks.skipped) {
      flags.push(
        `form-field-skipped#${section.sectionIndex}: ${skipped.kind} field "${skipped.label}" has no Jetpack form equivalent`,
      );
    }
  }
  return parts.join('\n');
}

function suppressFormEchoes(section: SectionSpec): SectionSpec {
  if (!section.forms || section.forms.length === 0) return section;

  const submitBudget = (): Map<string, number> => {
    const budget = new Map<string, number>();
    for (const form of section.forms ?? []) {
      const key = normalizeCopy(form.submitLabel).toLowerCase();
      budget.set(key, (budget.get(key) ?? 0) + 1);
    }
    return budget;
  };
  const dropOncePerSubmit = (budget: Map<string, number>) => (label: string) => {
    const key = normalizeCopy(label).toLowerCase();
    const left = budget.get(key) ?? 0;
    if (left > 0) {
      budget.set(key, left - 1);
      return false;
    }
    return true;
  };
  const keepLabel = dropOncePerSubmit(submitBudget());
  const keepButton = dropOncePerSubmit(submitBudget());
  const keepCellButton = dropOncePerSubmit(submitBudget());

  const fieldBudget = new Map<string, number>();
  for (const form of section.forms ?? []) {
    for (const field of form.fields) {
      const key = normalizeCopy(field.label).toLowerCase();
      fieldBudget.set(key, (fieldBudget.get(key) ?? 0) + 1);
    }
  }
  const cellIsFieldEcho = (cell: SectionSpecCell): boolean => {
    if (!cell.heading || cell.image || cell.icon || cell.button) return false;
    if ((cell.body ?? []).some((body) => /[a-z0-9]/i.test(normalizeCopy(body)))) return false;
    const key = normalizeCopy(cell.heading).toLowerCase();
    const left = fieldBudget.get(key) ?? 0;
    if (left <= 0) return false;
    fieldBudget.set(key, left - 1);
    return true;
  };
  const cellsSansFieldEchoes = section.cells?.filter((cell) => !cellIsFieldEcho(cell));

  return (
    {
      ...section,
      buttonLabels: (section.buttonLabels ?? []).filter(keepLabel),
      ...(section.buttons ? { buttons: section.buttons.filter((button) => keepButton(button.label)) } : {}),
      ...(cellsSansFieldEchoes
        ? {
            cells: cellsSansFieldEchoes.map((cell) => {
              if (!(cell.button && !keepCellButton(cell.button))) return cell;
              const headingEchoesButton =
                cell.heading != null &&
                normalizeCopy(cell.heading).toLowerCase() === normalizeCopy(cell.button).toLowerCase();
              return { ...cell, button: null, ...(headingEchoesButton ? { heading: null } : {}) };
            }),
          }
        : {}),
    }
  );
}

function appendFormBlock(out: NativeRenderOut, formBlock: string): void {
  if (!formBlock) return;
  out.remainder = { forms: out.remainder?.forms ?? [] };
  out.markup = out.markup.endsWith(SECTION_CLOSE)
    ? out.markup.slice(0, -SECTION_CLOSE.length) + formBlock + '\n' + SECTION_CLOSE
    : out.markup
      ? out.markup + '\n\n' + formBlock
      : formBlock;
}

function appendRecoverableImages(
  section: SectionSpec,
  out: NativeRenderOut,
  captured: CapturedSectionContent,
  coverage: CoverageResult,
  resolutionContext?: NativeImageResolutionContext,
): CoverageResult {
  if (!(coverage.lost && coverage.missingImages.length > 0)) return coverage;

  const recoverable = coverage.missingImages
    .map((url) => (section.images ?? []).find((image) => image.url === url))
    .filter(
      (image): image is SectionSpecImage =>
        !!image &&
        isUsableNativeImage(image, resolutionContext) &&
        Math.min(image.width || 0, image.height || 0) >= MIN_CELL_IMAGE_PX,
    );
  if (recoverable.length !== coverage.missingImages.length) return coverage;

  const recovered = recoverable
    .map((image) =>
      imageBlock(
        image,
        out,
        `recovered#${section.sectionIndex}`,
        {
          align: centerOf(section) ? 'center' : null,
          rounded: true,
        },
        resolutionContext,
      ),
    )
    .filter(Boolean);
  const augmented = out.markup.endsWith(SECTION_CLOSE)
    ? out.markup.slice(0, -SECTION_CLOSE.length) + recovered.join('\n') + '\n' + SECTION_CLOSE
    : (out.markup ? out.markup + '\n\n' : '') + recovered.join('\n');
  const reMeasured = measureSectionCoverage(captured, augmented);
  const unrecoveredMissing = reMeasured.missingImages.filter((url) => !coverage.missingImages.includes(url));
  if (unrecoveredMissing.length > 0 || reMeasured.textCoverage < TEXT_FLOOR) return coverage;

  out.markup = augmented;
  out.flags.push(
    `media-recovered#${section.sectionIndex}: appended ${recovered.length} dropped image(s) as blocks (island averted)`,
  );
  return { ...reMeasured, missingImages: unrecoveredMissing, lost: false };
}

function accountForRenderedMappedImages(
  section: SectionSpec,
  out: NativeRenderOut,
  coverage: CoverageResult,
  resolutionContext?: NativeImageResolutionContext,
): CoverageResult {
  if (!(coverage.lost && coverage.missingImages.length > 0)) return coverage;

  const missingImages = coverage.missingImages.filter((url) => {
    const image = (section.images ?? []).find((candidate) => candidate.url === url);
    const resolved = resolveNativeImageUrl(image, resolutionContext);
    return !resolved || resolved === url || !out.markup.includes(resolved);
  });
  if (missingImages.length === coverage.missingImages.length) return coverage;

  return {
    ...coverage,
    missingImages,
    lost: missingImages.length > 0 || coverage.textCoverage < TEXT_FLOOR,
  };
}

function shouldPreserveNativeImagePlaceholder(out: NativeRenderOut, coverage: CoverageResult): boolean {
  return (
    coverage.lost &&
    coverage.missingImages.length > 0 &&
    coverage.textCoverage >= TEXT_FLOOR &&
    out.markup.includes(MISSING_IMAGE_PLACEHOLDER)
  );
}

function fallbackDecision(
  section: SectionSpec,
  coverage: CoverageResult,
  options: SectionRenderOptions,
): NativeSectionDecision | null {
  if (!(coverage.lost && (section.styledHtml || section.sectionHtml))) return null;

  const { source, tier } = selectIslandSource(section);
  const island = buildHtmlFallbackBlock(source, fallbackOptions(options));
  const page = options.sourceUrl ?? options.slug ?? '';
  const slug = options.slug ?? options.sourceUrl ?? '';
  return {
    spec: section,
    blocks: island,
    coverage,
    expectedText: [],
    bodyText: [],
    expectedAssets: [],
    provenanceFlags: [
      `html-fallback${tier === 'verbatim' ? '' : `-${tier}`}#${section.sectionIndex}: structured render dropped content ` +
        `(${coverage.missingImages.length} images missing, text ${Math.round(coverage.textCoverage * 100)}%) — ` +
        `emitted ${tier} core/html`,
    ],
    fallbackDiagnostics: [
      buildFallbackDiagnostic({
        page,
        slug,
        section,
        coverage,
        islandKind: tier,
        islandMarkup: island,
      }),
    ],
    iconAssets: [],
    decision: 'fallback',
  };
}

function convertedDecision(
  section: SectionSpec,
  converted: ConvertedSectionInput | undefined,
  options: SectionRenderOptions,
): NativeSectionDecision | null {
  if (!(converted && converted.markup && converted.wpHtmlResidue === 0)) return null;

  const markup = rewriteConvertedMedia(converted.markup, options);
  const captured = captureSectionContent(section);
  const coverage = convertedOutputLost(captured, markup);
  if (!coverage) return null;

  const expectedText: string[] = [];
  const bodyText: string[] = [];
  const expectedAssets = [...captured.images];
  const provenanceFlags = [
    `html-to-blocks#${section.sectionIndex}: converted native blocks ` +
      `(0 wp:html, text ${Math.round(coverage.textCoverage * 100)}%)`,
  ];
  for (const match of markup.matchAll(/<h[1-6][^>]*>([\s\S]*?)<\/h[1-6]>/gi)) {
    expectedText.push(visibleText(match[1]));
  }
  for (const match of markup.matchAll(/<p\b[^>]*>([\s\S]*?)<\/p>/gi)) {
    bodyText.push(visibleText(match[1]));
  }

  const formBlock = renderSectionForms(section, expectedText, provenanceFlags);
  const blocks = formBlock ? `${markup}\n\n${formBlock}` : markup;
  return {
    spec: section,
    blocks,
    coverage,
    expectedText,
    bodyText,
    expectedAssets,
    provenanceFlags,
    fallbackDiagnostics: [],
    iconAssets: [],
    remainder: formBlock && section.forms ? { forms: section.forms } : undefined,
    decision: 'converted',
  };
}

function nativeDecision(section: SectionSpec, options: SectionRenderOptions, ctx: NativeRenderCtx): NativeSectionDecision | null {
  const renderSpec = suppressFormEchoes(section);
  const out = renderSection(renderSpec, ctx);
  const formBlock = renderSectionForms(section, out.expectedText, out.flags);
  if (formBlock) {
    appendFormBlock(out, formBlock);
    if (section.forms) out.remainder = { forms: section.forms };
  }

  const captured = nativeCapturedContent(section);
  let coverage = measureSectionCoverage(captured, out.markup);
  coverage = accountForRenderedMappedImages(section, out, coverage, options);
  coverage = appendRecoverableImages(section, out, captured, coverage, options);

  const preserveNativeImagePlaceholder = shouldPreserveNativeImagePlaceholder(out, coverage);
  if (preserveNativeImagePlaceholder) {
    out.markup = preserveNativePlaceholderSourceIdentity(out.markup, section);
  }

  const fallback = preserveNativeImagePlaceholder ? null : fallbackDecision(section, coverage, options);
  if (fallback) return fallback;

  if (!out.markup) return null;
  return {
    spec: section,
    blocks: out.markup,
    coverage,
    expectedText: out.expectedText,
    bodyText: out.bodyText,
    expectedAssets: out.assets,
    provenanceFlags: out.flags,
    fallbackDiagnostics: [],
    iconAssets: out.iconAssets,
    remainder: out.remainder,
    decision: 'native',
  };
}

// Verbatim-island fallback (faithful). When a section did not convert cleanly,
// emit its original DOM verbatim inside a core/html island so every source class
// survives and the carried CSS binds 1:1. Wrapped in an align:full group so the
// section breaks out of main's constrained content width and renders full-bleed
// (the un-wrapped island boxed into contentSize — the 5a8720b7 fidelity gap).
function islandDecision(
  section: SectionSpec,
  options: SectionRenderOptions,
): NativeSectionDecision | null {
  if (!(section.styledHtml || section.sectionHtml)) return null;

  const { source, tier } = selectIslandSource(section);
  const island = buildHtmlFallbackBlock(source, fallbackOptions(options));
  const blocks =
    `<!-- wp:group {"tagName":"section","align":"full"} -->\n` +
    `<section class="wp-block-group alignfull">\n` +
    `${island}\n` +
    `</section>\n` +
    `<!-- /wp:group -->`;
  const coverage: CoverageResult = { textCoverage: 1, missingImages: [], lost: false };
  return {
    spec: section,
    blocks,
    coverage,
    expectedText: section.headings,
    bodyText: section.bodyText,
    expectedAssets: section.images.map((image) => image.url || image.sourceUrl).filter(Boolean),
    provenanceFlags: [
      `html-island${tier === 'verbatim' ? '' : `-${tier}`}#${section.sectionIndex}: verbatim source (faithful)`,
    ],
    fallbackDiagnostics: [],
    iconAssets: [],
    decision: 'fallback',
  };
}

export const classifySemanticStrategy: SectionStrategy = {
  name: 'classify-semantic',
  render(section, options, ctx) {
    // Convert-or-island hybrid (ported from data-liberation-agent): a clean canonical
    // conversion (wpHtmlResidue 0) becomes editable blocks; otherwise the section
    // falls back to a faithful verbatim island. nativeDecision is the last resort for
    // synthetic specs that carry no source HTML to island.
    const converted = options.convertedSections?.get(section.sectionIndex);
    return (
      convertedDecision(section, converted, options) ??
      islandDecision(section, options) ??
      nativeDecision(section, options, ctx)
    );
  },
};

// Default reconstruction (the DLA-faithful path): preserve-dom first — native editable
// blocks that keep their source classes (so carried CSS binds → visual parity), with nested
// core/html islands for the elements that can't become core blocks. Falls back to a whole-
// section verbatim island, then native rendering, only when preserve-dom has no usable source
// HTML. Drains preserve-dom's deduped lib-i instance CSS so inline styles resolve.
export const defaultReconstructStrategy: SectionStrategy = {
  name: 'preserve-dom-default',
  render(section, options, ctx, state) {
    const preserved = preserveDomStrategy.render(section, options, ctx, state);
    if (preserved && !preserved.coverage.lost) return preserved;
    return islandDecision(section, options) ?? nativeDecision(section, options, ctx);
  },
  drainDedup(state) {
    return preserveDomStrategy.drainDedup?.(state) ?? { cssRules: [] };
  },
};

// Structured reconstruction (the pre-preserve-dom DLA blocks-path fidelity): interpret the
// SectionSpec into clean, theme-styled canonical blocks FIRST (nativeDecision → renderSection →
// renderCover/renderCardGrid/renderMediaText/…), with a verbatim core/html island only as the
// coverage fallback. Unlike defaultReconstructStrategy/classifySemanticStrategy, it does NOT
// preserve source classes or island whole sections by default — so the output is self-contained
// and renders from the THEME alone, with no dependency on carried source CSS. This is the right
// strategy for a no-CSS-carry blocks pipeline (e.g. data-liberation's blocks reconstruct path);
// the carried-CSS paths (local-convert, theme-carry) keep defaultReconstructStrategy. Additive:
// selecting it does not change the default, so those paths are unaffected.
export const structuredStrategy: SectionStrategy = {
  name: 'structured',
  render(section, options, ctx) {
    const converted = options.convertedSections?.get(section.sectionIndex);
    return (
      convertedDecision(section, converted, options) ??
      nativeDecision(section, options, ctx) ??
      islandDecision(section, options)
    );
  },
};

function optionsFromCtx(ctx: StageCtx): SectionRenderOptions {
  const rewriteCtx = ctx as RewriteCtx;
  return {
    ...(rewriteCtx.mediaUrlMap ? { mediaUrlMap: rewriteCtx.mediaUrlMap } : {}),
    ...(rewriteCtx.convertedSections ? { convertedSections: rewriteCtx.convertedSections } : {}),
    ...(rewriteCtx.paletteTokens ? { paletteTokens: rewriteCtx.paletteTokens } : {}),
    ...(rewriteCtx.fontFamilies ? { fontFamilies: rewriteCtx.fontFamilies } : {}),
    ...(rewriteCtx.sourceUrl ? { sourceUrl: rewriteCtx.sourceUrl } : {}),
    ...(rewriteCtx.slug ? { slug: rewriteCtx.slug } : {}),
  };
}

export function reconstructNativeAggregate(
  specs: SectionSpec[],
  options: SectionRenderOptions = {},
): NativeReconstructAggregate {
  const ctx: NativeRenderCtx = {
    mediaTextIndex: 0,
    iconCounter: 0,
    paletteTokens: options.paletteTokens ?? [],
    fontFamilies: options.fontFamilies ?? [],
    ...(options.mediaUrlMap ? { mediaUrlMap: options.mediaUrlMap } : {}),
  };

  const strategy = options.strategy ?? defaultReconstructStrategy;
  const state: StrategyState = {};
  const decisions: NativeSectionDecision[] = [];
  const sectionMarkup: string[] = [];
  const expectedText: string[] = [];
  const bodyText: string[] = [];
  const expectedAssets: string[] = [];
  const provenanceFlags: string[] = [];
  const fallbackDiagnostics: NativeReconstructAggregate['fallbackDiagnostics'] = [];
  const iconAssets: NativeReconstructAggregate['iconAssets'] = [];

  for (const section of normalizeSections(specs, options)) {
    const decision = strategy.render(section, options, ctx, state);
    if (!decision) continue;

    decisions.push(decision);
    sectionMarkup.push(decision.blocks);
    expectedText.push(...decision.expectedText);
    bodyText.push(...decision.bodyText);
    expectedAssets.push(...decision.expectedAssets);
    provenanceFlags.push(...decision.provenanceFlags);
    fallbackDiagnostics.push(...decision.fallbackDiagnostics);
    iconAssets.push(...decision.iconAssets);
  }

  const aggregate: NativeReconstructAggregate = {
    sections: decisions,
    sectionMarkup,
    expectedText: dedupe(expectedText),
    bodyText: dedupe(bodyText),
    expectedAssets: dedupe(expectedAssets),
    provenanceFlags,
    fallbackDiagnostics,
    iconAssets,
    heroIsCover: sectionMarkup.length > 0 && /^\s*<!-- wp:cover\b/.test(sectionMarkup[0]),
  };
  const dedup = strategy.drainDedup?.(state);
  if (dedup) aggregate.dedup = dedup;
  return aggregate;
}

export async function reconstruct(
  specs: SectionSpec[],
  ctx: StageCtx,
  pool: WorkerPool,
  hooks: SiteToThemeHooks,
  coverageFloor: number,
  renderOptions: SectionRenderOptions = {}
): Promise<SectionBlocks[]> {
  void pool;
  const aggregate = reconstructNativeAggregate(specs, {
    ...optionsFromCtx(ctx),
    ...renderOptions,
  });
  renderOptions.onDedup?.(aggregate.dedup?.cssRules ?? []);
  const sections: SectionBlocks[] = [];

  for (const decision of aggregate.sections) {
    const section: SectionBlocks = {
      spec: decision.spec,
      blocks: decision.blocks,
      coverage: publicCoverage(decision.blocks, decision.coverage),
      ...(decision.remainder ? { remainder: decision.remainder } : {}),
    };
    const shouldFire = section.coverage <= coverageFloor || Boolean(section.remainder?.forms.length);
    sections.push(shouldFire && hooks.onSection ? await hooks.onSection(section, ctx) : section);
  }

  return sections;
}
