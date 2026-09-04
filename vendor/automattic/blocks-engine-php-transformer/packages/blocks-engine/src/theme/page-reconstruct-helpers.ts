import type { SectionSpec } from './section-spec.js';

/** Registered theme fontFamily token ({slug, family}); used to map a captured
 *  computed font-family to the nearest registered token (the gate wants a token,
 *  not a raw family, and the file must be self-hosted). */
export interface FontFamilyToken {
  slug: string;
  family: string;
}

/** Collapse whitespace; drop zero-width + soft-hyphen noise. Keeps copy verbatim. */
export function normalizeCopy(s: string): string {
  return s
    .replace(/­/g, '')
    .replace(/[​-‍﻿]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Neutralize a value interpolated into the PHP pattern doc-comment header so a
 * crafted source-derived title/slug cannot break OUT of the doc-comment and
 * inject executable PHP. A title shaped like a comment-close + code + comment-open
 * would, in PHP, close the doc-comment early, run the code, then re-open a comment
 * that swallows the rest through the real header close — a comment-breakout RCE.
 * (`validate_artifacts` also defends this via a tempered header match, but the
 * renderer must never EMIT such a header in the first place.) The header is
 * metadata only, never rendered copy, so stripping comment/PHP-tag delimiters and
 * collapsing to one line is lossless here.
 */
export function sanitizePatternHeaderField(s: string): string {
  return s
    .replace(/\*\//g, '') // cannot close the doc-comment early
    .replace(/\/\*/g, '') // cannot open a nested comment
    .replace(/<\?/g, '') // no PHP open tag
    .replace(/\?>/g, '') // no PHP close tag
    .replace(/[\r\n]+/g, ' ') // single line
    .trim();
}

/** Footer/nav chrome detection — the sitewide footer leaks into every page
 *  capture as trailing sections. We render page body only; header + footer come
 *  from the theme parts, so a captured footer section must be stripped or the
 *  page shows TWO footers (the reconstructed one + the theme footer part). */
function isChromeSection(s: SectionSpec): boolean {
  if (s.interactionModel === 'footer' || s.interactionModel === 'nav') return true;
  const heads = s.headings.map((h) => normalizeCopy(h).toLowerCase());
  const body = (s.bodyText ?? []).map((b) => normalizeCopy(b));
  const buttons = (s.buttonLabels ?? []).map((b) => normalizeCopy(b));
  const allText = [...heads, ...body, ...buttons];
  // GENERIC footer signal: a copyright / attribution line. Only footers carry
  // "© <year>", "all rights reserved", "website by", "powered by" — and
  // stripChrome only removes TRAILING sections, so a stray mid-page mention
  // can't be falsely stripped. (This is what swiftlumber's footer carries:
  // "© 2026 Website by Tokuda Technology".)
  const hasCopyright = allText.some((t) =>
    /(?:©|\(c\)\s|copyright\b|all rights reserved|website by|powered by)/i.test(t),
  );
  // getsnooz's footer nav + newsletter (kept for back-compat).
  const hasFooterNav = heads.includes('shop') && heads.includes('support') && heads.includes('company');
  const hasNewsletter = body.some((b) => /get some good snooz/i.test(b));
  return hasCopyright || hasFooterNav || hasNewsletter;
}

/** Leading site-header chrome: a SHORT top-of-page band dominated by nav links
 *  (+ logo), with no real prose. When the section detector captures the whole
 *  page as <section> tiles (flat Wix pages), the header tile arrives as a
 *  `static` section rather than model `nav`, so the nav-model check alone misses
 *  it and the page renders the menu/contact block above its content. The theme
 *  supplies its own header part, so a leading header band is always redundant.
 *  Guarded by height so a tall hero or content band can never match. */
function isHeaderChrome(s: SectionSpec): boolean {
  if (s.interactionModel === 'nav') return true;
  if (s.height > 200) return false; // headers are thin; heroes/content are tall
  const body = (s.bodyText ?? []).map((b) => normalizeCopy(b)).filter(Boolean);
  const heads = s.headings.map((h) => normalizeCopy(h)).filter(Boolean);
  const shortLinkish = body.filter((b) => b.length <= 30).length;
  const hasLongProse = [...body, ...heads].some((t) => t.length > 80);
  return shortLinkish >= 3 && !hasLongProse;
}

/**
 * Drop trailing sitewide chrome (footer + newsletter) and leading header/nav.
 * Only strips from the ends — a dark-bg content band in the page middle (e.g.
 * the "100 Night Happiness Guarantee" block) is preserved.
 */
export function stripChrome(sections: SectionSpec[]): SectionSpec[] {
  let start = 0;
  let end = sections.length;
  while (start < end && isHeaderChrome(sections[start])) start++;
  while (end > start && isChromeSection(sections[end - 1])) end--;
  return sections.slice(start, end);
}

/**
 * Sanitize a source-captured inline SVG before it's written as a theme asset and
 * referenced from a `core/image`. Loading SVG via `<img src>` already prevents
 * script execution in browsers, but strip active content defensively (the SVG is
 * source-derived = attacker-controlled per the project trust boundary): no
 * <script>, <foreignObject>, event-handler attributes, or javascript: URLs.
 */
export function sanitizeSvgAsset(svg: string): string {
  return (
    svg
      .replace(/<script[\s\S]*?<\/script\s*>/gi, '')
      .replace(/<foreignObject[\s\S]*?<\/foreignObject\s*>/gi, '')
      // SMIL animation elements can set event-handler attributes at runtime
      // (e.g. <set attributeName="onload" to="…">) — a direct-navigation XSS
      // vector that the on*= attribute strip below misses. A static icon glyph
      // never animates, so drop these wholesale (both self-closing and paired).
      .replace(/<(set|animate|animateTransform|animateMotion)\b[\s\S]*?(?:\/>|<\/\1\s*>)/gi, '')
      // External / script href on <a>/<use>/<image> (tracking + SSRF-ish on
      // direct navigation). Keep local #fragment refs and inline data:image.
      .replace(
        /\s(?:xlink:)?href\s*=\s*["']\s*(?:https?:|\/\/|data:(?!image\/))[^"']*["']/gi,
        '',
      )
      .replace(/\son[a-z]+\s*=\s*"[^"]*"/gi, '')
      .replace(/\son[a-z]+\s*=\s*'[^']*'/gi, '')
      .replace(/javascript:/gi, '')
      .trim()
  );
}
