import * as cheerio from 'cheerio';
import type { CheerioAPI } from 'cheerio';
import type { InteractionModel, SectionSpec, SectionSpecImage } from './section-spec.js';
import type { SitePage } from './types.js';
import { cssIdSelector, escapeCssAttrValue } from '../escape.js';

type ElementNode = NonNullable<Parameters<CheerioAPI>[0]> & {
  type?: string;
  tagName: string;
  attribs?: Record<string, string>;
  parent?: ElementNode | null;
};

type ContentNode = {
  type?: string;
  tagName?: string;
  attribs?: Record<string, string>;
  parent?: ElementNode | null;
  data?: string;
};

interface ExtractedContent {
  headings: string[];
  bodyText: string[];
  buttonLabels: string[];
  images: SectionSpecImage[];
}

interface SectionDraft {
  selector: string;
  html: string;
  interactionModel: InteractionModel;
}

const EXPLICIT_TAGS = new Set(['section', 'article', 'nav', 'aside', 'footer', 'header', 'main']);
const LANDMARK_ROLES = new Set([
  'article',
  'banner',
  'complementary',
  'contentinfo',
  'main',
  'navigation',
  'region',
  'search',
]);
const HEADING_SELECTOR = 'h1,h2,h3,h4,h5,h6';
const BODY_TEXT_SELECTOR = 'p,li,blockquote,figcaption';
const EXPLICIT_SELECTOR =
  'section,article,nav,aside,footer,header,main,' +
  '[role="article"],[role="banner"],[role="complementary"],[role="contentinfo"],' +
  '[role="main"],[role="navigation"],[role="region"],[role="search"]';

export function sectionExtract(page: SitePage): SectionSpec[] {
  const $ = cheerio.load(page.html);
  const drafts: SectionDraft[] = [];
  const body = $('body').first();

  if (body.length) {
    walkContainer($, body.get(0) as ElementNode, drafts);
  } else {
    $.root()
      .contents()
      .each((_, node) => {
        if (isElement(node)) processElement($, node, drafts);
      });
  }

  const specs: SectionSpec[] = [];
  for (const draft of drafts) {
    const spec = buildSectionSpec(draft, specs.length);
    if (spec) specs.push(spec);
  }
  return specs;
}

function walkContainer($: CheerioAPI, container: ElementNode, drafts: SectionDraft[]): void {
  const nodes = $(container).contents().toArray() as ContentNode[];

  for (let index = 0; index < nodes.length; index += 1) {
    const node = nodes[index];
    if (isHeading(node)) {
      index = addHeadingBandFrom($, nodes, index, drafts);
      continue;
    }
    if (isElement(node)) processElement($, node, drafts);
  }
}

function processElement($: CheerioAPI, el: ElementNode, drafts: SectionDraft[]): void {
  if (isExplicitCandidate(el)) {
    const tag = tagName(el);

    if (tag === 'main' && hasDirectHeadingChildren($, el)) {
      walkContainer($, el, drafts);
      return;
    }

    if (hasExplicitDescendant($, el) && !hasRecognizableContentOutsideDescendants($, el)) {
      walkContainer($, el, drafts);
      return;
    }

    addElementSection($, el, drafts);
    return;
  }

  if (hasDirectHeadingChildren($, el)) {
    walkContainer($, el, drafts);
    return;
  }

  if (isDesignedBand($, el)) {
    addElementSection($, el, drafts);
    return;
  }

  walkContainer($, el, drafts);
}

// A bare (non-explicit, non-heading) element whose entire subtree carries no
// heading and no explicit/landmark descendant, yet still has recognizable
// content, is a designed band (marquees, ticker strips, decorative copy rows).
// Capture it as a section instead of walking past it and dropping its content.
function isDesignedBand($: CheerioAPI, el: ElementNode): boolean {
  if (hasHeadingDescendant($, el)) return false;
  if (hasExplicitDescendant($, el)) return false;
  return hasRecognizableContent(extractContent($.html(el)));
}

function hasHeadingDescendant($: CheerioAPI, el: ElementNode): boolean {
  return $(el).find(HEADING_SELECTOR).length > 0;
}

function addElementSection($: CheerioAPI, el: ElementNode, drafts: SectionDraft[]): void {
  const html = $.html(el).trim();
  if (!html) return;

  drafts.push({
    selector: selectorForElement($, el),
    html,
    interactionModel: interactionModelForElement(el),
  });
}

function addHeadingBandFrom(
  $: CheerioAPI,
  nodes: ContentNode[],
  index: number,
  drafts: SectionDraft[]
): number {
  const node = nodes[index];
  if (!isHeading(node)) return index;

  const bandNodes: ContentNode[] = [node];
  let cursor = index + 1;
  while (cursor < nodes.length && !isHeading(nodes[cursor])) {
    const current = nodes[cursor];
    if (isElement(current) && isExplicitCandidate(current)) break;
    if (hasRecognizableNodeContent($, current)) bandNodes.push(current);
    cursor += 1;
  }

  const html = bandNodes.map((bandNode) => htmlForNode($, bandNode)).join('').trim();
  if (html) {
    drafts.push({
      selector: selectorForElement($, node),
      html,
      interactionModel: 'static',
    });
  }
  return cursor - 1;
}

function buildSectionSpec(draft: SectionDraft, sectionIndex: number): SectionSpec | null {
  const content = extractContent(draft.html);
  if (!hasRecognizableContent(content)) return null;

  return {
    sectionIndex,
    interactionModel: draft.interactionModel,
    top: 0,
    height: 0,
    headings: content.headings,
    selector: draft.selector,
    bodyText: content.bodyText,
    buttonLabels: content.buttonLabels,
    images: content.images,
    icons: [],
    backgroundBrightness: 1,
    backgroundColor: 'transparent',
    gradient: null,
    gradientSource: null,
    motionProfile: {
      motionClass: 'none',
      signals: [],
      animatedElements: 0,
    },
    dividerAbove: null,
    dividerBelow: null,
    layout: {
      containerWidth: 0,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '0',
    },
    sectionHtml: draft.html,
  };
}

function extractContent(html: string): ExtractedContent {
  const $ = cheerio.load(html, null, false);
  const headings = collectText($, HEADING_SELECTOR);
  const bodyText = collectBodyText($);
  const buttonLabels = collectButtonLabels($);
  const images = collectImages($);

  return { headings, bodyText, buttonLabels, images };
}

function hasRecognizableContent(content: ExtractedContent): boolean {
  return (
    content.headings.length > 0 ||
    content.bodyText.length > 0 ||
    content.buttonLabels.length > 0 ||
    content.images.length > 0
  );
}

function hasRecognizableNodeContent($: CheerioAPI, node: ContentNode): boolean {
  if (isElement(node)) {
    return hasRecognizableContent(extractContent($.html(node)));
  }

  return node.type === 'text' && cleanText(node.data ?? '').length > 0;
}

function hasRecognizableContentOutsideDescendants($: CheerioAPI, el: ElementNode): boolean {
  const clone = $(el).clone();
  clone.find(EXPLICIT_SELECTOR).remove();
  return hasRecognizableContent(extractContent(clone.html() ?? ''));
}

function hasExplicitDescendant($: CheerioAPI, el: ElementNode): boolean {
  return $(el).find(EXPLICIT_SELECTOR).length > 0;
}

function hasDirectHeadingChildren($: CheerioAPI, el: ElementNode): boolean {
  return (
    $(el)
      .contents()
      .toArray()
      .some((node) => isHeading(node as ContentNode))
  );
}

function isExplicitCandidate(el: ElementNode): boolean {
  return EXPLICIT_TAGS.has(tagName(el)) || roleForElement(el) !== null;
}

function interactionModelForElement(el: ElementNode): InteractionModel {
  const tag = tagName(el);
  const role = roleForElement(el);

  if (tag === 'nav' || role === 'navigation') return 'nav';
  if (tag === 'footer' || role === 'contentinfo') return 'footer';
  return 'static';
}

function collectText($: CheerioAPI, selector: string): string[] {
  const values: string[] = [];
  $(selector).each((_, el) => {
    const text = cleanText($(el).text());
    pushUnique(values, text);
  });
  return values;
}

function collectBodyText($: CheerioAPI): string[] {
  const values = collectText($, BODY_TEXT_SELECTOR);
  $('div,span').each((_, el) => {
    const $el = $(el);
    if ($el.closest(`${HEADING_SELECTOR},a,button`).length > 0) return;
    if (
      $el.find(
        `${BODY_TEXT_SELECTOR},${HEADING_SELECTOR},a,button,input,select,textarea`
      ).length > 0
    ) {
      return;
    }
    pushUnique(values, cleanText($el.text()));
  });
  if (values.length === 0) {
    const clone = $.root().clone();
    clone.find(`${HEADING_SELECTOR},a,button,input,select,textarea,script,style`).remove();
    pushUnique(values, cleanText(clone.text()));
  }
  return values;
}

function collectButtonLabels($: CheerioAPI): string[] {
  const labels: string[] = [];
  $('a,button,input[type="button"],input[type="submit"]').each((_, el) => {
    const $el = $(el);
    const text =
      cleanText($el.text()) ||
      cleanText($el.attr('value') ?? '') ||
      cleanText($el.attr('aria-label') ?? '') ||
      cleanText($el.attr('title') ?? '');
    if (text) labels.push(text);
  });
  return labels;
}

function collectImages($: CheerioAPI): SectionSpecImage[] {
  const images: SectionSpecImage[] = [];

  $('img').each((_, el) => {
    const $el = $(el);
    const url = $el.attr('src') ?? '';
    if (!url) return;

    images.push({
      url,
      sourceUrl: url,
      alt: $el.attr('alt') ?? '',
      kind: 'img',
      width: parseDimension($el.attr('width')),
      height: parseDimension($el.attr('height')),
    });
  });

  return images;
}

function parseDimension(value: string | undefined): number {
  if (!value) return 0;
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) ? parsed : 0;
}

function selectorForElement($: CheerioAPI, el: ElementNode): string {
  const tag = tagName(el);
  const id = attr(el, 'id');
  if (id) return cssIdSelector(id, tag);

  const labelledBy = attr(el, 'aria-labelledby');
  if (labelledBy) return `${tag}[aria-labelledby="${escapeCssAttrValue(labelledBy)}"]`;

  const ariaLabel = attr(el, 'aria-label');
  if (ariaLabel) return `${tag}[aria-label="${escapeCssAttrValue(ariaLabel)}"]`;

  const role = attr(el, 'role');
  if (role) return `${tag}[role="${escapeCssAttrValue(role)}"]`;

  if (!/^h[1-6]$/.test(tag) && $(tag).length === 1) return tag;

  const parent = parentElement(el);
  if (!parent || tagName(parent) === 'html') return `${tag}:nth-of-type(${nthOfType($, el)})`;

  return `${selectorForElement($, parent)} > ${tag}:nth-of-type(${nthOfType($, el)})`;
}

function nthOfType($: CheerioAPI, el: ElementNode): number {
  return $(el).prevAll(tagName(el)).length + 1;
}

function roleForElement(el: ElementNode): string | null {
  const role = attr(el, 'role');
  if (!role) return null;

  return role
    .toLowerCase()
    .split(/\s+/)
    .find((candidate) => LANDMARK_ROLES.has(candidate)) ?? null;
}

function htmlForNode($: CheerioAPI, node: ContentNode): string {
  if (isElement(node)) return $.html(node);
  return node.data ?? '';
}

function parentElement(el: ElementNode): ElementNode | null {
  const parent = el.parent;
  return parent && parent.type === 'tag' ? parent : null;
}

function isHeading(node: ContentNode): node is ElementNode {
  return isElement(node) && /^h[1-6]$/.test(tagName(node));
}

function isElement(node: unknown): node is ElementNode {
  return (node as ElementNode | null)?.type === 'tag';
}

function tagName(el: ElementNode): string {
  return el.tagName.toLowerCase();
}

function attr(el: ElementNode, name: string): string | undefined {
  return el.attribs?.[name];
}

function cleanText(value: string): string {
  return value.replace(/\s+/g, ' ').trim();
}

function pushUnique(values: string[], text: string): void {
  if (text && !values.includes(text)) values.push(text);
}

