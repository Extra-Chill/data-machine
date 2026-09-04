import * as cheerio from 'cheerio';
import type { CheerioAPI } from 'cheerio';

import { PIPELINE_ISLAND_OPENER } from './block-policy.js';
import { escapeHtmlAttr as escapeAttr, escapeHtmlText as escapeHtml } from './escape.js';
import { sanitize } from './sanitize.js';
import type { ConversionContext, RecipeRule } from './types.js';

type HtmlFallbackEmitter = (html: string) => string;
export type RecipeElement = NonNullable<Parameters<CheerioAPI>[0]> & {
  type?: string;
  tagName: string;
};

function defaultHtmlFallback(html: string): string {
  return `${PIPELINE_ISLAND_OPENER}\n${sanitize(html)}\n<!-- /wp:html -->`;
}

export function composeFromRecipes(
  html: string,
  recipes: RecipeRule[],
  ctx: ConversionContext,
  htmlFallback: HtmlFallbackEmitter = defaultHtmlFallback,
): string | null {
  if (/<!--\s*wp:/.test(html)) return null;
  const $ = cheerio.load(html, null, false);
  const out: string[] = [];
  $.root().children().each((_, node) => {
    const el = node as unknown as RecipeElement;
    if (el.type !== 'tag') return;
    const recipe = recipes.find((candidate) => $(el).is(candidate.match));
    out.push(recipe ? emitRecipeBlock($, el, recipe, ctx, htmlFallback) : htmlFallback($.html(el)));
  });
  const result = out.filter((block) => block && block.trim());
  return result.length ? result.join('\n\n') : null;
}

export function blockTag(block: string): string {
  return block.startsWith('core/') ? block.slice('core/'.length) : block;
}

export function emitRecipeBlock(
  $: CheerioAPI,
  el: RecipeElement,
  recipe: RecipeRule,
  ctx: ConversionContext,
  htmlFallback: HtmlFallbackEmitter = defaultHtmlFallback,
): string {
  const tag = blockTag(recipe.block);
  const attrs =
    recipe.attrs && Object.keys(recipe.attrs).length
      ? ` ${JSON.stringify(recipe.attrs).replace(/-->/g, '--\\u003e')}`
      : '';
  const open = `<!-- wp:${tag}${attrs} -->`;
  const close = `<!-- /wp:${tag} -->`;
  const mode = recipe.inner ?? 'innerHtml';
  const $el = $(el);
  if (recipe.block === 'core/image' || mode === 'images') {
    const img = $el.is('img') ? $el : $el.find('img').first();
    const rawSrc = img.attr('src') || '';
    if (!rawSrc) return htmlFallback($.html(el));
    const src = ctx.mediaMap?.[rawSrc] ?? rawSrc;
    return `${open}\n<figure class="wp-block-image"><img src="${escapeAttr(src)}" alt="${escapeAttr(img.attr('alt') || '')}"/></figure>\n${close}`;
  }
  if (mode === 'drop') return `${open}\n${close}`;
  const inner = mode === 'text' ? escapeHtml($el.text().trim()) : ($el.html() ?? '');
  return `${open}\n${inner}\n${close}`;
}
