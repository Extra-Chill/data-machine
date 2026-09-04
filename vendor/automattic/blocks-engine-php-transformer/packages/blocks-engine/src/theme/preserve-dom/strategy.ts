import * as cheerio from 'cheerio';
import type { Cheerio, CheerioAPI } from 'cheerio';
import { isTag, isText } from 'domhandler';
import type { Element } from 'domhandler';
import { escapeHtmlAttr as escapeHtml } from '../../escape.js';
import type { NativeSectionDecision, SectionStrategy, StrategyState } from '../native-reconstruct-types.js';
import { sanitizeSvgAsset } from '../page-reconstruct-helpers.js';
import type { SectionSpec } from '../section-spec.js';
import { InstanceStyleSheet } from './instance-styles.js';
import { buildHtmlFallbackBlock } from '../html-fallback.js';

/** Wrap an un-convertible element's verbatim source as a nested core/html island
 *  (sanitized, same opener marker as section-level islands). Keeps the element's
 *  visual + classes so carried CSS binds, without dropping it. */
function coreHtmlIsland(html: string): string {
  return buildHtmlFallbackBlock(html);
}

type SourceIdentitySection = SectionSpec & {
  sourceId?: string;
  sourceClasses?: string[];
};

interface ChildResult {
  markup: string;
  clean: boolean;
}

function attrJson(value: string): string {
  return JSON.stringify(value).replace(/--/g, '\\u002d\\u002d');
}

function classNameOf($el: Cheerio<Element>): string {
  return ($el.attr('class') ?? '').split(/\s+/).filter(Boolean).join(' ');
}

function blockAttrs(pairs: string[], className: string): string {
  const all = className ? [...pairs, `"className":${attrJson(className)}`] : pairs;
  return all.length ? ` {${all.join(',')}}` : '';
}

function classNameWithInstance($el: Cheerio<Element>, sheet: InstanceStyleSheet): string {
  const base = classNameOf($el);
  const instance = sheet.classFor($el.attr('style'));
  return [base, instance].filter(Boolean).join(' ');
}

function paragraphBlock(inner: string): string {
  return `<!-- wp:paragraph -->\n<p>${inner}</p>\n<!-- /wp:paragraph -->`;
}

const HEADING = /^h([1-6])$/;
const INLINE_ALLOWED = new Set(['a', 'strong', 'em', 'b', 'i', 'br', 'span']);

function inlineHtml($: CheerioAPI, el: Element): string {
  let out = '';
  for (const node of $(el).contents().get()) {
    if (isText(node)) {
      out += escapeHtml(node.data);
    } else if (isTag(node)) {
      const tag = node.tagName?.toLowerCase() ?? '';
      if (tag === 'br') {
        out += '<br/>';
        continue;
      }
      const cls = ($(node).attr('class') ?? '').trim();
      const styleA = ($(node).attr('style') ?? '').trim();
      if (INLINE_ALLOWED.has(tag)) {
        const inner = inlineHtml($, node);
        const clsAttr = cls ? ` class="${escapeHtml(cls)}"` : '';
        if (tag === 'a') {
          const href = escapeHtml($(node).attr('href') ?? '');
          out += `<a${clsAttr} href="${href}">${inner}</a>`;
        } else {
          const styleAttr = styleA ? ` style="${escapeHtml(styleA)}"` : '';
          out += `<${tag}${clsAttr}${styleAttr}>${inner}</${tag}>`;
        }
      } else {
        out += inlineHtml($, node);
      }
    }
  }
  return out;
}

function imageBlock($: CheerioAPI, imgEl: Element, sheet: InstanceStyleSheet): string {
  const src = escapeHtml($(imgEl).attr('src') ?? '');
  const alt = escapeHtml($(imgEl).attr('alt') ?? '');
  const cls = classNameWithInstance($(imgEl), sheet);
  const attrs = blockAttrs([], cls);
  const figCls = ['wp-block-image', cls].filter(Boolean).join(' ');
  return `<!-- wp:image${attrs} -->\n<figure class="${escapeHtml(figCls)}"><img src="${src}" alt="${alt}"/></figure>\n<!-- /wp:image -->`;
}

function svgAltText($: CheerioAPI, svgEl: Element): string | null {
  const $svg = $(svgEl);
  if (($svg.attr('aria-hidden') ?? '').trim().toLowerCase() === 'true') return '';
  const role = ($svg.attr('role') ?? '').trim().toLowerCase();
  if (role === 'presentation' || role === 'none') return '';

  const labelledBy = ($svg.attr('aria-labelledby') ?? '').trim();
  if (labelledBy) {
    const text = labelledBy
      .split(/\s+/)
      .map((id) => $(`#${id}`).text().trim())
      .filter(Boolean)
      .join(' ');
    if (text) return text;
  }

  const label = ($svg.attr('aria-label') ?? '').trim();
  if (label) return label;

  const title = $svg.children('title').first().text().trim();
  return title || null;
}

function dimensionAttr($el: Cheerio<Element>, name: 'width' | 'height'): string | null {
  const value = ($el.attr(name) ?? '').trim();
  const match = /^\d+(?:\.\d+)?(?:px)?$/i.exec(value);
  return match ? match[0].replace(/px$/i, '') : null;
}

function svgImageBlock($: CheerioAPI, svgEl: Element, sheet: InstanceStyleSheet): string | null {
  const alt = svgAltText($, svgEl);
  if (alt === null) return null;

  const svg = sanitizeSvgAsset($.html(svgEl).trim());
  if (!svg || !/<svg[\s>]/i.test(svg)) return null;

  const $svg = $(svgEl);
  const cls = classNameWithInstance($svg, sheet);
  const attrs = blockAttrs([], cls);
  const figCls = ['wp-block-image', cls].filter(Boolean).join(' ');
  const width = dimensionAttr($svg, 'width');
  const height = dimensionAttr($svg, 'height');
  const style = [width ? `width:${width}px` : '', height ? `height:${height}px` : ''].filter(Boolean).join(';');
  const styleAttr = style ? ` style="${escapeHtml(style)}"` : '';
  const src = `data:image/svg+xml,${encodeURIComponent(svg)}`;
  return `<!-- wp:image${attrs} -->\n<figure class="${escapeHtml(figCls)}"><img src="${escapeHtml(src)}" alt="${escapeHtml(alt)}"${styleAttr}/></figure>\n<!-- /wp:image -->`;
}

function emitChild($: CheerioAPI, el: Element, sheet: InstanceStyleSheet): ChildResult {
  const tag = el.tagName?.toLowerCase() ?? '';
  const $el = $(el);

  const h = HEADING.exec(tag);
  if (h) {
    const level = Number(h[1]);
    const cls = classNameWithInstance($el, sheet);
    const attrs = blockAttrs(level === 2 ? [] : [`"level":${level}`], cls);
    const htmlCls = ['wp-block-heading', cls].filter(Boolean).join(' ');
    const inner = inlineHtml($, el).trim();
    return {
      markup: `<!-- wp:heading${attrs} -->\n<h${level} class="${escapeHtml(htmlCls)}">${inner}</h${level}>\n<!-- /wp:heading -->`,
      clean: true,
    };
  }

  if (tag === 'p') {
    const cls = classNameWithInstance($el, sheet);
    const attrs = blockAttrs([], cls);
    const inner = inlineHtml($, el).trim();
    const clsPart = cls ? ` class="${escapeHtml(cls)}"` : '';
    const open = `<p${clsPart}>`;
    return { markup: `<!-- wp:paragraph${attrs} -->\n${open}${inner}</p>\n<!-- /wp:paragraph -->`, clean: true };
  }

  if (tag === 'img') {
    return { markup: imageBlock($, el, sheet), clean: true };
  }

  if (tag === 'svg') {
    const markup = svgImageBlock($, el, sheet);
    if (markup) return { markup, clean: true };
  }

  const text = $el.text().trim();
  const elementChildren = $el.children().toArray();
  if ((tag === 'div' || tag === 'span') && !$el.attr('id') && elementChildren.length === 0 && text) {
    const cls = classNameWithInstance($el, sheet);
    const attrs = blockAttrs([], cls);
    const clsPart = cls ? ` class="${escapeHtml(cls)}"` : '';
    return {
      markup: `<!-- wp:paragraph${attrs} -->\n<p${clsPart}>${inlineHtml($, el).trim()}</p>\n<!-- /wp:paragraph -->`,
      clean: true,
    };
  }

  // P3-S3: nested non-leaf container (e.g. .menu-grid > .menu-card > h3 + p). Recurse,
  // preserving the container's source class/id as a nested wp:group, so designed
  // structures survive instead of being dropped. `clean` only stays true if every
  // descendant emitted cleanly (an unhandled leaf still downgrades the whole subtree).
  if (NESTABLE_CONTAINERS.has(tag) && elementChildren.length > 0) {
    return emitContainer($, el, tag, sheet);
  }

  // Un-convertible element kind (svg, table, form, media): keep it losslessly as a nested
  // core/html island instead of dropping it — preserves the visual AND its source classes
  // (so carried CSS still binds → visual parity) while the rest of the section stays native
  // editable blocks. This is DLA's per-element island behavior (apply-block-recipe).
  const verbatim = $.html(el).trim();
  if (verbatim) return { markup: coreHtmlIsland(verbatim), clean: true };
  return { markup: '', clean: false };
}

const NESTABLE_CONTAINERS = new Set([
  'div',
  'section',
  'article',
  'header',
  'footer',
  'main',
  'aside',
  'nav',
  'ul',
  'ol',
  'li',
  'figure',
  'figcaption',
]);

function emitContainer(
  $: CheerioAPI,
  el: Element,
  tag: string,
  sheet: InstanceStyleSheet
): ChildResult {
  const $el = $(el);
  const childResults: ChildResult[] = [];
  for (const node of $el.contents().get()) {
    if (isTag(node)) {
      childResults.push(emitChild($, node, sheet));
    } else if (isText(node)) {
      const text = node.data.trim();
      if (text) childResults.push({ markup: paragraphBlock(escapeHtml(text)), clean: true });
    }
  }

  const innerMarkup = childResults
    .map((res) => res.markup)
    .filter(Boolean)
    .join('\n');
  // Container whose children produced no native markup (e.g. an SVG-only wrapper):
  // island it verbatim rather than dropping it.
  if (!innerMarkup) {
    const verbatim = $.html(el).trim();
    return verbatim ? { markup: coreHtmlIsland(verbatim), clean: true } : { markup: '', clean: false };
  }

  const cls = classNameWithInstance($el, sheet);
  const id = $el.attr('id')?.trim();
  // div is the default wp:group tag; keep an explicit tagName for semantic containers.
  const pairs: string[] = [];
  if (tag !== 'div') pairs.push(`"tagName":${attrJson(tag)}`);
  if (id) pairs.push(`"anchor":${attrJson(id)}`);
  const attrs = blockAttrs(pairs, cls);
  const wrapTag = tag === 'div' ? 'div' : tag;
  const divCls = ['wp-block-group', cls].filter(Boolean).join(' ');
  const idPart = id ? ` id="${escapeHtml(id)}"` : '';
  return {
    markup:
      `<!-- wp:group${attrs} -->\n` +
      `<${wrapTag}${idPart} class="${escapeHtml(divCls)}">${innerMarkup}</${wrapTag}>\n` +
      `<!-- /wp:group -->`,
    clean: childResults.every((res) => res.clean),
  };
}

function sheetFromState(state: StrategyState): InstanceStyleSheet {
  if (state.instanceStyles instanceof InstanceStyleSheet) return state.instanceStyles;
  const sheet = new InstanceStyleSheet();
  state.instanceStyles = sheet;
  return sheet;
}

export const preserveDomStrategy: SectionStrategy = {
  name: 'preserve-dom',
  render(section, _options, _ctx, state): NativeSectionDecision | null {
    const source = section as SourceIdentitySection;
    const sourceHtml = section.sectionHtml ?? section.styledHtml ?? '';
    if (!sourceHtml) return null;

    const sheet = sheetFromState(state);
    const $ = cheerio.load(sourceHtml);
    const root = $('section, article, main, div').first();
    const container = root.length ? root : $('body');
    const childMarkup: string[] = [];
    let downgrades = 0;
    let total = 0;

    for (const node of container.contents().get()) {
      if (isTag(node)) {
        total += 1;
        const res = emitChild($, node, sheet);
        if (!res.clean) downgrades += 1;
        if (res.markup) childMarkup.push(res.markup);
      } else if (isText(node)) {
        const text = node.data.trim();
        if (!text) continue;
        total += 1;
        childMarkup.push(paragraphBlock(escapeHtml(text)));
      }
    }

    const inner = childMarkup.join('\n');
    const sourceId = source.sourceId ?? container.attr('id')?.trim();
    const rawSourceClasses =
      source.sourceClasses && source.sourceClasses.length > 0
        ? source.sourceClasses
        : (container.attr('class') ?? '').split(/\s+/).filter(Boolean);
    // Dedup source classes — the source DOM can repeat a class (e.g. class="fallback fallback")
    // and CanonicalSaveShapeValidator rejects duplicate classes on a block.
    const sourceClasses = [...new Set(rawSourceClasses)];
    const cls = sourceClasses.join(' ');
    const sectionInstance = sheet.classFor(container.attr('style'));
    const wrapperCls = [cls, sectionInstance].filter(Boolean).join(' ');
    // Top-level sections break out of the theme's centered content width so full-bleed source
    // bands stay full-bleed — mirrors the native section wrapper, which emits both the align:full
    // attribute and the alignfull class (a core/group with align:full is invalid without it).
    const wrapperPairs = ['"tagName":"section"', '"align":"full"'];
    if (sourceId) wrapperPairs.unshift(`"anchor":${attrJson(sourceId)}`);
    const attrs = blockAttrs(wrapperPairs, wrapperCls);
    const divCls = [...new Set(['wp-block-group', 'alignfull', ...sourceClasses, sectionInstance].filter(Boolean))].join(' ');
    const idPart = sourceId ? ` id="${escapeHtml(sourceId)}"` : '';
    const blocks =
      `<!-- wp:group${attrs} -->\n` +
      `<section${idPart} class="${escapeHtml(divCls)}">${inner}</section>\n` +
      `<!-- /wp:group -->`;

    return {
      spec: section,
      blocks,
      coverage: {
        textCoverage: downgrades > 0 ? 0 : 1,
        missingImages: [],
        lost: downgrades > 0,
      },
      expectedText: section.headings,
      bodyText: section.bodyText,
      expectedAssets: section.images.map((image) => image.url || image.sourceUrl).filter(Boolean),
      provenanceFlags: downgrades > 0 ? [`preserve-dom#${section.sectionIndex}: skipped non-core elements`] : [],
      fallbackDiagnostics: [],
      iconAssets: [],
      decision: 'native',
    };
  },
  drainDedup(state) {
    if (!(state.instanceStyles instanceof InstanceStyleSheet)) return { cssRules: [] };
    return { cssRules: state.instanceStyles.size ? state.instanceStyles.toCss().split('\n') : [] };
  },
};
