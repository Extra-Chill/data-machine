import * as cheerio from 'cheerio';

import { escapeHtmlText as escapeHtml } from './escape.js';

export interface HeuristicResult {
  handled: boolean;
  blocks?: string;
  reason?: string;
}

const ALLOWED_TEXTISH = new Set(['p', 'h2', 'h3']);

function paragraphBlock(html: string): string {
  return `<!-- wp:paragraph -->\n<p>${html}</p>\n<!-- /wp:paragraph -->`;
}

function headingBlock(level: 2 | 3, html: string): string {
  const attrs = level === 2 ? '' : ` {"level":${level}}`;
  return `<!-- wp:heading${attrs} -->\n<h${level} class="wp-block-heading">${html}</h${level}>\n<!-- /wp:heading -->`;
}

function imageBlock(src: string, alt: string): string {
  const escapedSrc = escapeHtml(src);
  const escapedAlt = escapeHtml(alt);
  return `<!-- wp:image -->\n<figure class="wp-block-image"><img src="${escapedSrc}" alt="${escapedAlt}"/></figure>\n<!-- /wp:image -->`;
}

function groupBlock(inner: string): string {
  return `<!-- wp:group -->\n<div class="wp-block-group">\n${inner}\n</div>\n<!-- /wp:group -->`;
}

interface SimpleEl {
  tag: string;
  innerHtml: string;
  attrs: Record<string, string>;
  childTags: string[];
}

function topLevelChildren(html: string): SimpleEl[] {
  const $ = cheerio.load(`<body>${html}</body>`);
  const body = $('body').first();
  const elements: SimpleEl[] = [];
  body.contents().each((_, node) => {
    if (node.type === 'tag') {
      const $node = $(node);
      const attrs: Record<string, string> = {};
      const tagAttrs = (node as { attribs?: Record<string, string> }).attribs ?? {};
      for (const [key, value] of Object.entries(tagAttrs)) attrs[key] = value;
      const childTags: string[] = [];
      $node.children().each((__, child) => {
        if (child.type === 'tag') childTags.push((child as { tagName: string }).tagName.toLowerCase());
      });
      elements.push({
        tag: (node as { tagName: string }).tagName.toLowerCase(),
        innerHtml: $node.html() ?? '',
        attrs,
        childTags,
      });
    } else if (node.type === 'text') {
      const text = (node as { data: string }).data ?? '';
      if (text.trim()) elements.push({ tag: '#textnode', innerHtml: text, attrs: {}, childTags: [] });
    }
  });
  return elements;
}

interface ImageInfo {
  src: string;
  alt: string;
}

function pickFigureImage(figureInnerHtml: string): ImageInfo | null {
  const $ = cheerio.load(`<body>${figureInnerHtml}</body>`);
  const body = $('body').first();
  const childEls: Array<{ tag: string; src: string; alt: string }> = [];
  body.contents().each((_, node) => {
    if (node.type === 'tag') {
      const tagName = (node as { tagName: string }).tagName.toLowerCase();
      if (tagName === 'img' || tagName === 'figcaption') {
        const $node = $(node);
        childEls.push({
          tag: tagName,
          src: $node.attr('src') ?? '',
          alt: $node.attr('alt') ?? '',
        });
      } else {
        childEls.push({ tag: tagName, src: '', alt: '' });
      }
    }
  });
  const hasOnlyAllowed = childEls.every((child) => child.tag === 'img' || child.tag === 'figcaption');
  const img = childEls.find((child) => child.tag === 'img');
  if (!hasOnlyAllowed || !img) return null;
  return { src: img.src, alt: img.alt };
}

function pickLeadingImage(el: SimpleEl): ImageInfo | null {
  if (el.tag === 'img') return { src: el.attrs.src ?? '', alt: el.attrs.alt ?? '' };
  if (el.tag === 'figure') return pickFigureImage(el.innerHtml);
  return null;
}

function textishToBlock(el: SimpleEl): string {
  const inner = el.innerHtml.trim();
  if (el.tag === 'p') return paragraphBlock(inner);
  if (el.tag === 'h2') return headingBlock(2, inner);
  if (el.tag === 'h3') return headingBlock(3, inner);
  return paragraphBlock(escapeHtml(inner));
}

export function heuristicBlocks(html: string): HeuristicResult {
  if (!html || !html.trim()) return { handled: false, reason: 'empty input' };

  const children = topLevelChildren(html);
  if (children.length === 0) return { handled: false, reason: 'no structured children' };
  if (children.some((child) => child.tag === '#textnode')) {
    return { handled: false, reason: 'top-level stray text' };
  }

  if (children.length === 1 && children[0].tag === 'section') {
    const inner = topLevelChildren(children[0].innerHtml);
    const allTextish = inner.every((child) => ALLOWED_TEXTISH.has(child.tag));
    const hasHeading = inner.some((child) => child.tag === 'h2' || child.tag === 'h3');
    if (allTextish && hasHeading && inner.length > 0) {
      const innerBlocks = inner.map((child) => textishToBlock(child)).join('\n\n');
      return { handled: true, blocks: groupBlock(innerBlocks), reason: 'section-with-heading' };
    }
    return { handled: false, reason: 'section is not pure heading+paragraphs' };
  }

  const leadingImage = pickLeadingImage(children[0]);
  if (leadingImage) {
    const rest = children.slice(1);
    const restAllParagraphs = rest.every((child) => child.tag === 'p');
    if (restAllParagraphs && rest.length > 0) {
      const blocks = [imageBlock(leadingImage.src, leadingImage.alt)];
      for (const paragraph of rest) blocks.push(paragraphBlock(paragraph.innerHtml.trim()));
      return { handled: true, blocks: blocks.join('\n\n'), reason: 'image+paragraphs' };
    }
    return { handled: false, reason: 'leading image not followed by paragraphs only' };
  }

  const allTextish = children.every((child) => ALLOWED_TEXTISH.has(child.tag));
  if (allTextish) {
    return {
      handled: true,
      blocks: children.map((child) => textishToBlock(child)).join('\n\n'),
      reason: 'paragraphs+headings',
    };
  }

  return { handled: false, reason: 'mixed structure outside heuristic shapes' };
}
