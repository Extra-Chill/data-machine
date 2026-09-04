import * as cheerio from 'cheerio';
import type { CheerioAPI } from 'cheerio';

import { PIPELINE_ISLAND_OPENER } from './block-policy.js';
import { buildEmbedBlock, guessEmbedProvider } from './embed.js';
import { escapeHtmlAttr as escapeAttr, escapeHtmlText as escapeHtml } from './escape.js';
import { sanitize } from './sanitize.js';
import type { ConversionContext } from './types.js';

type HtmlFallbackEmitter = (html: string) => string;
type Element = NonNullable<Parameters<CheerioAPI>[0]> & {
  type?: string;
  tagName: string;
};

interface Converted {
  matched: boolean;
  markup: string;
}

function defaultHtmlFallback(html: string): string {
  return `${PIPELINE_ISLAND_OPENER}\n${sanitize(html)}\n<!-- /wp:html -->`;
}

export function genericHtmlToBlocks(
  html: string,
  ctx: ConversionContext,
  htmlFallback: HtmlFallbackEmitter = defaultHtmlFallback,
): string | null {
  if (!html || !html.trim()) return null;
  if (/<!--\s*wp:/.test(html)) return null;

  const $ = cheerio.load(html, null, false);
  const out: string[] = [];
  let matchedAny = false;

  $.root().children().each((_, node) => {
    if ((node as Element).type !== 'tag') return;
    const el = node as Element;
    const converted = convertElement($, el, ctx, htmlFallback);
    if (converted.matched) matchedAny = true;
    if (converted.markup.trim()) out.push(converted.markup);
  });

  if (!matchedAny) return null;
  return out.length ? out.join('\n\n') : null;
}

function convertElement(
  $: CheerioAPI,
  el: Element,
  ctx: ConversionContext,
  htmlFallback: HtmlFallbackEmitter,
): Converted {
  const embed = tryEmbed($, el);
  if (embed) return { matched: true, markup: embed };
  const details = tryDetails($, el, ctx, htmlFallback);
  if (details) return { matched: true, markup: details };
  const detailsPairs = tryDetailsPairs($, el, ctx, htmlFallback);
  if (detailsPairs) return { matched: true, markup: detailsPairs };
  const callout = tryCallout($, el, ctx, htmlFallback);
  if (callout) return { matched: true, markup: callout };
  const pull = tryPullquote($, el);
  if (pull) return { matched: true, markup: pull };
  const buttons = tryButtons($, el);
  if (buttons) return { matched: true, markup: buttons };
  const mediaText = tryMediaText($, el, ctx, htmlFallback);
  if (mediaText) return { matched: true, markup: mediaText };
  return { matched: false, markup: htmlFallback($.html(el)) };
}

const EMBED_WRAPPER_TAGS = new Set(['figure', 'div', 'p', 'span']);

function tryEmbed($: CheerioAPI, el: Element): string | null {
  const $el = $(el);
  let src: string | undefined;

  if (el.tagName === 'iframe') {
    src = $el.attr('src');
  } else if (EMBED_WRAPPER_TAGS.has(el.tagName)) {
    const iframes = $el.find('iframe');
    if (iframes.length !== 1) return null;
    const clone = $el.clone();
    clone.find('iframe').remove();
    if (clone.text().trim()) return null;
    src = iframes.first().attr('src');
  } else {
    return null;
  }

  if (!src || !/^https?:\/\//i.test(src)) return null;
  if (!guessEmbedProvider(src)) return null;
  return buildEmbedBlock(src);
}

function tryDetails(
  $: CheerioAPI,
  el: Element,
  ctx: ConversionContext,
  htmlFallback: HtmlFallbackEmitter,
): string | null {
  const $el = $(el);
  const isDetails = el.tagName === 'details';
  const isAccordionClass = /\b(accordion|faq-item)\b/.test($el.attr('class') || '');
  if (!isDetails && !isAccordionClass) return null;

  const summary =
    $el.find('summary').first().text().trim() ||
    $el.children('h1,h2,h3,h4,h5,h6,.accordion-title,.faq-question').first().text().trim();
  if (!summary) return null;

  const bodyHtml = bodyExcludingSummary($, $el);
  const inner = recurseInner(bodyHtml, ctx, htmlFallback);
  return (
    `<!-- wp:details -->\n` +
    `<details class="wp-block-details"><summary>${escapeHtml(summary)}</summary>${inner}</details>\n` +
    `<!-- /wp:details -->`
  );
}

function bodyExcludingSummary($: CheerioAPI, $el: ReturnType<CheerioAPI>): string {
  const clone = $el.clone();
  clone.find('summary').first().remove();
  clone.children('h1,h2,h3,h4,h5,h6,.accordion-title,.faq-question').first().remove();
  return clone.html() ?? '';
}

function recurseInner(
  html: string,
  ctx: ConversionContext,
  htmlFallback: HtmlFallbackEmitter,
): string {
  const nested = genericHtmlToBlocks(html, ctx, htmlFallback);
  if (nested && nested.trim()) return `\n${nested}\n`;
  const clean = sanitize(html).trim();
  return clean ? clean : '';
}

const HEADING_TAGS = new Set(['h1', 'h2', 'h3', 'h4', 'h5', 'h6']);

function isHiddenContent($: CheerioAPI, el: Element): boolean {
  const $el = $(el);
  return (
    $el.attr('hidden') !== undefined ||
    $el.attr('aria-hidden') === 'true' ||
    /display\s*:\s*none/.test($el.attr('style') ?? '')
  );
}

function tryDetailsPairs(
  $: CheerioAPI,
  el: Element,
  ctx: ConversionContext,
  htmlFallback: HtmlFallbackEmitter,
): string | null {
  const kids = $(el).children().toArray();
  if (kids.length < 2 || kids.length % 2 !== 0) return null;
  const pairs: Array<{ summary: string; bodyHtml: string }> = [];
  for (let i = 0; i < kids.length; i += 2) {
    const head = kids[i];
    const body = kids[i + 1];
    if (!HEADING_TAGS.has(head.tagName)) return null;
    if (HEADING_TAGS.has(body.tagName) || !isHiddenContent($, body)) return null;
    const summary = $(head).text().trim();
    if (!summary) return null;
    pairs.push({ summary, bodyHtml: $(body).html() ?? '' });
  }
  if (pairs.length === 0) return null;
  return pairs
    .map(
      (pair) =>
        `<!-- wp:details -->\n` +
        `<details class="wp-block-details"><summary>${escapeHtml(pair.summary)}</summary>${recurseInner(pair.bodyHtml, ctx, htmlFallback)}</details>\n` +
        `<!-- /wp:details -->`,
    )
    .join('\n\n');
}

const CALLOUT_RE = /\b(callout|notice|alert|card)\b/;

function tryCallout(
  $: CheerioAPI,
  el: Element,
  ctx: ConversionContext,
  htmlFallback: HtmlFallbackEmitter,
): string | null {
  const $el = $(el);
  const cls = $el.attr('class') || '';
  if (!CALLOUT_RE.test(cls)) return null;
  const inner = recurseInner($el.html() ?? '', ctx, htmlFallback);
  if (!inner.trim()) return null;
  const matchedClass = (cls.match(CALLOUT_RE) || [])[0] ?? 'callout';
  const className = `wp-block-group is-style-${matchedClass}`;
  return (
    `<!-- wp:group {"className":${JSON.stringify(className)},"layout":{"type":"constrained"}} -->\n` +
    `<div class="${escapeAttr(className)}">${inner}</div>\n` +
    `<!-- /wp:group -->`
  );
}

function tryPullquote($: CheerioAPI, el: Element): string | null {
  const $el = $(el);
  const cls = $el.attr('class') || '';
  const isPull =
    (el.tagName === 'blockquote' && /\bpull/.test(cls)) ||
    (el.tagName === 'aside' && $el.find('blockquote').length > 0);
  if (!isPull) return null;
  const $quote = $el.is('blockquote') ? $el : $el.find('blockquote').first();
  const text = $quote.find('p').first().text().trim() || $quote.text().trim();
  if (!text) return null;
  const cite = $el.find('cite').first().text().trim();
  const citeHtml = cite ? `<cite>${escapeHtml(cite)}</cite>` : '';
  return (
    `<!-- wp:pullquote -->\n` +
    `<figure class="wp-block-pullquote"><blockquote><p>${escapeHtml(text)}</p>${citeHtml}</blockquote></figure>\n` +
    `<!-- /wp:pullquote -->`
  );
}

const BTN_RE = /\b(button|btn)\b/;

function isButtonLink($: CheerioAPI, el: Element): boolean {
  return el.tagName === 'a' && BTN_RE.test($(el).attr('class') || '') && Boolean($(el).attr('href'));
}

function tryButtons($: CheerioAPI, el: Element): string | null {
  const $el = $(el);
  let links: Element[];
  if (isButtonLink($, el)) {
    links = [el];
  } else {
    links = $el.children('a').toArray().filter((anchor) => isButtonLink($, anchor));
    if (links.length === 0) return null;
  }
  const buttonBlocks = links.map((anchor) => {
    const $anchor = $(anchor);
    const href = $anchor.attr('href') || '';
    const label = $anchor.text().trim();
    return (
      `<!-- wp:button -->\n` +
      `<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="${escapeAttr(href)}">${escapeHtml(label)}</a></div>\n` +
      `<!-- /wp:button -->`
    );
  });
  return (
    `<!-- wp:buttons -->\n` +
    `<div class="wp-block-buttons">\n${buttonBlocks.join('\n')}\n</div>\n` +
    `<!-- /wp:buttons -->`
  );
}

const MEDIA_TEXT_RE = /\b(media-text|media-object|image-text|text-image)\b/;
const LAYOUT_HINT_CLASS_RE = /\b(split(?:-\w+)?|two-col(?:umn)?s?|cols?-2|grid|row|flex)\b/;
const LAYOUT_HINT_STYLE_RE = /display\s*:\s*(flex|grid)\b/;

function isMediaChild($: CheerioAPI, el: Element): boolean {
  if (el.tagName === 'img' || el.tagName === 'picture' || el.tagName === 'figure') {
    return $(el).find('img').length > 0 || el.tagName === 'img';
  }
  return $(el).find('img').length === 1 && !$(el).text().trim();
}

function tryMediaText(
  $: CheerioAPI,
  el: Element,
  ctx: ConversionContext,
  htmlFallback: HtmlFallbackEmitter,
): string | null {
  const $el = $(el);
  const classNamed = MEDIA_TEXT_RE.test($el.attr('class') || '');

  let mediaOnRight = false;
  if (!classNamed) {
    const kids = $el.children().toArray();
    if (kids.length !== 2) return null;
    const hasLayoutHint =
      LAYOUT_HINT_CLASS_RE.test($el.attr('class') || '') ||
      LAYOUT_HINT_STYLE_RE.test($el.attr('style') || '');
    if (!hasLayoutHint) return null;
    const [first, second] = kids;
    const firstIsMedia = isMediaChild($, first);
    const secondIsMedia = isMediaChild($, second);
    if (firstIsMedia === secondIsMedia) return null;
    const textChild = firstIsMedia ? second : first;
    if ($(textChild).find('img').length > 0) return null;
    mediaOnRight = secondIsMedia;
  }

  const img = $el.find('img').first();
  if (img.length === 0) return null;
  const rawSrc = img.attr('src') || '';
  if (!rawSrc) return null;
  const src = ctx.mediaMap?.[rawSrc] ?? rawSrc;
  const alt = img.attr('alt') || '';
  const textNode = $el.children().toArray().find((child) => {
    const tag = child.tagName;
    return tag && tag !== 'figure' && tag !== 'img' && tag !== 'picture' && $(child).find('img').length === 0;
  });
  const textHtml = textNode ? ($(textNode).html() ?? '') : '';
  const innerText =
    recurseInner(textHtml, ctx, htmlFallback).trim() ||
    `<!-- wp:paragraph -->\n<p>${escapeHtml($el.text().trim())}</p>\n<!-- /wp:paragraph -->`;
  const attrs = mediaOnRight ? `{"mediaType":"image","mediaPosition":"right"}` : `{"mediaType":"image"}`;
  const cls = `wp-block-media-text is-stacked-on-mobile${mediaOnRight ? ' has-media-on-the-right' : ''}`;
  return (
    `<!-- wp:media-text ${attrs} -->\n` +
    `<div class="${cls}">` +
    `<figure class="wp-block-media-text__media"><img src="${escapeAttr(src)}" alt="${escapeAttr(alt)}"/></figure>` +
    `<div class="wp-block-media-text__content">${innerText}</div>` +
    `</div>\n` +
    `<!-- /wp:media-text -->`
  );
}
