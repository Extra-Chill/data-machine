import * as cheerio from 'cheerio';
import type { Element } from 'domhandler';
import { convert } from '../convert.js';
import { escapeHtmlAttr } from '../escape.js';
import { slugFromRelPath } from './ingest.js';

export interface NavLink {
  fromSlug: string;
  toSlug: string;
  label: string;
  inNav?: boolean;
}

export interface StickyBehavior {
  kind: 'sticky';
  toggleClass: string;
  offset: number;
}

export interface HeaderPartOpts {
  plain?: boolean;
  sticky?: StickyBehavior;
}

export interface ChromePartSection {
  id: string;
  role: 'body' | 'header' | 'nav' | 'footer';
  chromeSource?: 'layout-rail';
  html: string;
  classes?: string[];
}

export type ChromePartConverter = (html: string) => string | Promise<string>;

export interface CarriedHeaderPartOpts {
  pageSlugs?: string[];
  sticky?: StickyBehavior;
  labelToUrl?: (label: string, sourceHref: string) => string | undefined;
  convertPart?: ChromePartConverter;
}

export interface FooterPartOpts {
  pageSlugs?: string[];
  bgToken?: string;
  textToken?: string;
  convertPart?: ChromePartConverter;
}

export interface ChromeMount {
  id: string;
  classes: string[];
}

export interface ChromeMounts {
  header?: ChromeMount;
  footer?: ChromeMount;
}

export function buildHeaderPart(
  siteTitle: string,
  nav: NavLink[],
  pageSlugs: string[],
  opts?: HeaderPartOpts
): string {
  void siteTitle;
  const resolvedOpts = opts ?? {};
  const links = selectNavLinks(nav, pageSlugs)
    .map((link) => `<!-- wp:navigation-link {"label":${attrJson(link.label)},"url":${attrJson(link.url)}} /-->`)
    .join('\n');
  const siteTitleBlock = `<!-- wp:site-title {"level":0,"className":"brand"} /-->`;

  if (resolvedOpts.plain) {
    const plainNav =
      `<!-- wp:navigation {"overlayMenu":"never","layout":{"type":"flex"}} -->\n` +
      `${links}\n` +
      `<!-- /wp:navigation -->`;
    const sticky = resolvedOpts.sticky ? stickyStateBlock(resolvedOpts.sticky) : '';
    return `${siteTitleBlock}\n${plainNav}${sticky}`;
  }

  const navBlock =
    `<!-- wp:navigation {"overlayMenu":"mobile","layout":{"type":"flex"}} -->\n` +
    `${links}\n` +
    `<!-- /wp:navigation -->`;
  return (
    `<!-- wp:group {"align":"full","layout":{"type":"flex","justifyContent":"space-between"},"style":{"spacing":{"padding":{"top":"1rem","bottom":"1rem","left":"1.5rem","right":"1.5rem"}}}} -->\n` +
    `<div class="wp-block-group alignfull" style="padding-top:1rem;padding-right:1.5rem;padding-bottom:1rem;padding-left:1.5rem">` +
    `${siteTitleBlock}\n` +
    navBlock +
    `</div>\n` +
    `<!-- /wp:group -->`
  );
}

export async function buildCarriedHeaderPart(
  header: ChromePartSection,
  opts?: CarriedHeaderPartOpts
): Promise<string> {
  const resolvedOpts = opts ?? {};
  let html = header.html;
  if (resolvedOpts.pageSlugs?.length) html = rewriteInternalHrefs(html, resolvedOpts.pageSlugs);
  if (resolvedOpts.labelToUrl) {
    const $ = cheerio.load(html);
    $('a[href]').each((_, el) => {
      const label = $(el).text().trim();
      const href = $(el).attr('href') ?? '';
      const resolved = resolvedOpts.labelToUrl?.(label, href);
      if (resolved) $(el).attr('href', resolved);
    });
    html = $('body').html() ?? html;
  }

  const normalizedHtml = html.replace(/^<header(\b[^>]*>)/i, '<div$1').replace(/<\/header>\s*$/i, '</div>');
  const markup = await convertPartMarkup(normalizedHtml, resolvedOpts.convertPart);
  const sticky = resolvedOpts.sticky ? `\n${stickyStateBlock(resolvedOpts.sticky)}` : '';
  return markup + sticky;
}

export async function buildFooterPart(
  footer: ChromePartSection | null,
  siteTitle: string,
  opts?: FooterPartOpts
): Promise<string> {
  const resolvedOpts = opts ?? {};

  if (footer) {
    let html = footer.html;
    if (resolvedOpts.pageSlugs?.length) html = rewriteInternalHrefs(html, resolvedOpts.pageSlugs);
    const normalizedHtml = html.replace(/^<footer(\b[^>]*>)/i, '<div$1').replace(/<\/footer>\s*$/i, '</div>');
    const inner = await convertPartMarkup(normalizedHtml, resolvedOpts.convertPart);
    return wrapFooterGroup(inner, resolvedOpts);
  }

  return wrapFooterGroup(
    `<!-- wp:group {"align":"full","layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"2rem","bottom":"2rem"}}}} -->\n` +
      `<div class="wp-block-group alignfull" style="padding-top:2rem;padding-bottom:2rem">` +
      `<!-- wp:paragraph {"align":"center"} -->\n<p class="has-text-align-center">${escapeHtmlAttr(siteTitle)}</p>\n<!-- /wp:paragraph -->` +
      `</div>\n` +
      `<!-- /wp:group -->`,
    resolvedOpts
  );
}

export function findChromeMounts(html: string): ChromeMounts {
  const $ = cheerio.load(html);
  const main = $('main').first();
  if (main.length === 0) return {};

  const all = $('*').toArray() as Element[];
  const mainIdx = all.indexOf(main.get(0)!);
  const out: ChromeMounts = {};

  for (const el of all) {
    if (el.tagName !== 'div') continue;
    const $el = $(el);
    const id = $el.attr('id');
    if (!id) continue;
    if (main.length && ($.contains(main.get(0)!, el) || main.get(0) === el)) continue;
    if ($el.children().length > 0 || $el.text().trim()) continue;
    const idx = all.indexOf(el);
    const classes = ($el.attr('class') ?? '').split(/\s+/).filter(Boolean);
    if (idx < mainIdx) {
      out.header = { id, classes };
    } else if (!out.footer) {
      out.footer = { id, classes };
    }
  }

  return out;
}

export function mountPartMarkup(mount: ChromeMount, sticky?: StickyBehavior): string {
  const cls = mount.classes.join(' ');
  const pairs = [`"anchor":${attrJson(mount.id)}`, '"tagName":"div"'];
  if (cls) pairs.push(`"className":${attrJson(cls)}`);
  const divCls = ['wp-block-group', cls].filter(Boolean).join(' ');
  const stickyBlock = sticky ? `\n${stickyStateBlock(sticky)}` : '';
  return (
    `<!-- wp:group {${pairs.join(',')}} -->\n` +
    `<div id="${escapeHtmlAttr(mount.id)}" class="${escapeHtmlAttr(divCls)}"></div>\n` +
    `<!-- /wp:group -->${stickyBlock}`
  );
}

export type { Element as ChromePartElement };

function selectNavLinks(nav: NavLink[], pageSlugs: string[]): Array<{ label: string; url: string }> {
  const fromHome = nav.filter((link) => link.fromSlug === 'home');
  const pool = fromHome.some((link) => link.inNav) ? fromHome.filter((link) => link.inNav) : fromHome;
  const seen = new Set<string>();
  const links: Array<{ label: string; url: string }> = [];

  for (const link of pool) {
    if (seen.has(link.toSlug)) continue;
    seen.add(link.toSlug);
    links.push({ label: link.label || link.toSlug, url: slugToUrl(link.toSlug) });
  }

  if (links.length > 0) return links;
  return pageSlugs
    .filter((slug) => slug !== 'home')
    .map((slug) => ({
      label: slug
        .split('-')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' '),
      url: slugToUrl(slug),
    }));
}

function attrJson(value: string): string {
  return JSON.stringify(value).replace(/--/g, '\\u002d\\u002d');
}

function stickyStateBlock(sticky: StickyBehavior): string {
  const json = `{"toggleClass":${attrJson(sticky.toggleClass)},"offset":${JSON.stringify(sticky.offset)}}`.replace(
    /'/g,
    '\\u0027'
  );
  return (
    `\n<!-- wp:dla/sticky ${json} -->\n` +
    `<div class="wp-block-dla-sticky" style="display:none" data-wp-interactive="dla/sticky"` +
    ` data-wp-context='${json}' data-wp-init="callbacks.init"></div>\n` +
    `<!-- /wp:dla/sticky -->`
  );
}

function slugToUrl(slug: string): string {
  return slug === 'home' ? '/' : `/${slug}/`;
}

function rewriteInternalHrefs(html: string, pageSlugs: string[]): string {
  const $ = cheerio.load(html);
  const known = new Set(pageSlugs);

  $('a[href]').each((_, el) => {
    const raw = $(el).attr('href') ?? '';
    if (!raw || /^[a-z]+:/i.test(raw) || raw.startsWith('//') || raw.startsWith('#')) return;

    let cleaned = raw.split(/[?#]/)[0];
    try {
      cleaned = decodeURIComponent(cleaned);
    } catch {
      // Keep malformed escapes in their original source form.
    }

    const slug = slugFromRelPath(cleaned.replace(/^\.\//, '').replace(/^\//, ''));
    if (!known.has(slug)) return;
    $(el).attr('href', slugToUrl(slug));
  });

  return $('body').html() ?? html;
}

async function convertPartMarkup(html: string, convertPart?: ChromePartConverter): Promise<string> {
  const markup = convertPart ? await convertPart(html) : await convert(html, { url: '' });
  return unwrapHtmlIslands(markup);
}

function unwrapHtmlIslands(markup: string): string {
  return markup.replace(/<!-- wp:html(?:\s+\{[\s\S]*?\})? -->\n?([\s\S]*?)\n?<!-- \/wp:html -->/g, (_match, inner: string) =>
    inner.trim()
  );
}

function wrapFooterGroup(inner: string, opts: FooterPartOpts): string {
  if (!opts.bgToken && !opts.textToken) return inner;
  const attrs: string[] = ['"align":"full"'];
  if (opts.bgToken) attrs.push(`"backgroundColor":"${opts.bgToken}"`);
  if (opts.textToken) attrs.push(`"textColor":"${opts.textToken}"`);
  attrs.push('"layout":{"type":"constrained"}', '"style":{"spacing":{"padding":{"top":"2.5rem","bottom":"2.5rem"}}}');

  const classes = ['wp-block-group', 'alignfull'];
  if (opts.textToken) classes.push(`has-${opts.textToken}-color`);
  if (opts.bgToken) classes.push(`has-${opts.bgToken}-background-color`);
  if (opts.textToken) classes.push('has-text-color');
  if (opts.bgToken) classes.push('has-background');

  return (
    `<!-- wp:group {${attrs.join(',')}} -->\n` +
    `<div class="${classes.join(' ')}" style="padding-top:2.5rem;padding-bottom:2.5rem">${inner}</div>\n` +
    `<!-- /wp:group -->`
  );
}
