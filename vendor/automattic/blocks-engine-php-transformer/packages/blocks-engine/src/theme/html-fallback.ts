import { PIPELINE_ISLAND_OPENER } from '../block-policy.js';
import { escapeHtmlAttr, escapeHtmlText } from '../escape.js';
import type { SectionSpec } from './section-spec.js';
import type { SectionSpecButton, SectionSpecImage } from './section-spec.js';
import { scanForInjection } from './injection-scan.js';
import { rewriteInternalLinks, rewriteMediaUrls, type InternalLinkMap } from './url-rewrite.js';

export interface HtmlFallbackOpts {
  mediaUrlMap?: Map<string, string>;
  linkMap?: InternalLinkMap;
}

export type IslandTier = 'responsive' | 'styled' | 'verbatim';

/** Remove script/style/comment blocks, inline event handlers, and PHP tags. */
export function sanitize(html: string): string {
  return html
    // Paired script/style including their contents.
    .replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style\s*>/gi, '')
    // Any residual/unclosed script/style tags.
    .replace(/<\/?(?:script|style)\b[^>]*>/gi, '')
    // PHP (incl. short tags) — no <?php may survive the injection gate.
    .replace(/<\?[\s\S]*?\?>/g, '')
    .replace(/<\?/g, '')
    // HTML comments — strips noise AND any literal `<!-- wp:… -->` lookalikes
    // that would otherwise break block parsing of the surrounding markup.
    .replace(/<!--[\s\S]*?-->/g, '')
    // Inline event handlers (onclick/onerror/onload/…), quoted or bare.
    .replace(/\son[a-z]+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, '');
}

const WP_LAYOUT_MARKER = /(?:is-layout-(?:constrained|flow|flex)|wp-block-|has-global-padding)/;

/** True when the markup carries WP-layout classes the replica block theme styles
 *  responsively (`is-layout-*`, `wp-block-*`, `has-global-padding`). Used to pick
 *  the responsive `sectionHtml` snapshot over the frozen `styledHtml` for WP
 *  sources, so the island reflows via the theme's CSS instead of captured pixels. */
export function isWpLayoutMarkup(html: string): boolean {
  return WP_LAYOUT_MARKER.test(html);
}

/** Choose which captured snapshot the core/html island should use, and classify
 *  the result. WP-native sections (the replica block theme styles their classes)
 *  use the clean, responsive `sectionHtml`; otherwise the `styledHtml` snapshot
 *  (computed dims inlined) is load-bearing; with no styled snapshot, the bare
 *  `sectionHtml` is the verbatim floor. Pure — no I/O. */
export function selectIslandSource(
  section: { sectionHtml?: string; styledHtml?: string },
): { source: string; tier: IslandTier } {
  if (section.sectionHtml && isWpLayoutMarkup(section.sectionHtml)) {
    return { source: section.sectionHtml ?? section.styledHtml ?? '', tier: 'responsive' };
  }
  if (section.styledHtml) return { source: section.styledHtml, tier: 'styled' };
  return { source: section.sectionHtml ?? section.styledHtml ?? '', tier: 'verbatim' };
}

/**
 * Build a sanitized, URL-rewritten `core/html` block from a section's source
 * outerHTML. Throws if sanitization left any injection vector (defensive — a bad
 * island must never silently ship past the gate).
 *
 * The opening delimiter carries the PIPELINE_ISLAND_OPENER marker
 * (`metadata.name = "lib-coverage-island"`): install-time validation
 * (validateReplicaInputs) rejects hand-authored wp:html in theme files but
 * accepts pipeline-emitted coverage islands by this marker, so a
 * previously-reconstructed theme can be reinstalled. The marker is markup-only
 * (a WP-supported block attribute) — it also labels the island in the editor
 * List View.
 */
export function buildHtmlFallbackBlock(
  sectionHtml: string,
  opts: HtmlFallbackOpts = {},
): string {
  let inner = sanitize(sectionHtml);
  if (opts.mediaUrlMap && opts.mediaUrlMap.size > 0) inner = rewriteMediaUrls(inner, opts.mediaUrlMap);
  if (opts.linkMap && opts.linkMap.size > 0) inner = rewriteInternalLinks(inner, opts.linkMap);

  const violations = scanForInjection(inner);
  if (violations.length > 0) {
    throw new Error(`html-fallback sanitization left injection vectors: ${violations.join('; ')}`);
  }

  return `${PIPELINE_ISLAND_OPENER}\n${inner.trim()}\n<!-- /wp:html -->`;
}

function nonEmpty(value: string | null | undefined): string | null {
  const trimmed = value?.trim();
  return trimmed ? trimmed : null;
}

function imageHtml(image: SectionSpecImage): string | null {
  const src = nonEmpty(image.url) ?? nonEmpty(image.sourceUrl);
  if (!src) return null;

  return `<figure><img src="${escapeHtmlAttr(src)}" alt="${escapeHtmlAttr(image.alt)}"></figure>`;
}

function buttonHtml(button: SectionSpecButton): string | null {
  const label = nonEmpty(button.label);
  if (!label) return null;

  const href = nonEmpty(button.href) ?? '#';
  return `<p><a href="${escapeHtmlAttr(href)}">${escapeHtmlText(label)}</a></p>`;
}

function semanticFallbackHtml(spec: SectionSpec): string {
  const fragments: string[] = [];

  for (const [index, heading] of spec.headings.entries()) {
    const text = nonEmpty(heading);
    if (!text) continue;

    const level = index === 0 ? 2 : 3;
    fragments.push(`<h${level}>${escapeHtmlText(text)}</h${level}>`);
  }

  for (const body of spec.bodyText) {
    const text = nonEmpty(body);
    if (text) fragments.push(`<p>${escapeHtmlText(text)}</p>`);
  }

  const structuredButtons =
    spec.buttons?.map(buttonHtml).filter((html): html is string => html !== null) ?? [];
  const labelButtons = spec.buttonLabels
    .map((label) => nonEmpty(label))
    .filter((label): label is string => label !== null)
    .map((label) => `<p><a href="#">${escapeHtmlText(label)}</a></p>`);
  const buttons = structuredButtons.length > 0 ? structuredButtons : labelButtons;
  fragments.push(...buttons);

  fragments.push(...spec.images.map(imageHtml).filter((html): html is string => html !== null));

  return fragments.length > 0 ? `<section>${fragments.join('')}</section>` : '<section></section>';
}

export function buildCoverageIsland(spec: SectionSpec): string {
  const body = spec.sectionHtml?.trim() ? spec.sectionHtml : semanticFallbackHtml(spec);
  return `${PIPELINE_ISLAND_OPENER}\n${body}\n<!-- /wp:html -->`;
}
