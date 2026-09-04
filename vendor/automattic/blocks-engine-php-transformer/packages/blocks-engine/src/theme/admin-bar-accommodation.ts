/**
 * WP admin-bar accommodation (deterministic, browser-free).
 *
 * When a logged-in user views the FRONT END, WordPress prints a fixed 32px
 * (46px at <=782px) admin bar across the top and bumps the document down with
 * `html { margin-top: 32px !important }`. That bump only moves DOCUMENT-FLOW
 * content — `position: fixed`/`sticky` chrome is viewport-relative, so a carried
 * source header / sidebar / topbar / TOC pinned to `top: 0` (or any top anchor)
 * sits UNDER the bar and the admin bar overlays the page content.
 *
 * This pass scans the assembled carried CSS for top-anchored fixed/sticky rules
 * and emits `body.admin-bar`-scoped overrides that shift each one down by the bar
 * height (a responsive custom property), plus a `html:has(body.admin-bar)`
 * re-assert of the document bump in case carried CSS reset the html/body margin.
 * The output is appended AFTER the carried CSS; `body.admin-bar <selector>`
 * (specificity 0,2,1+) out-ranks the source rule (0,1,0) regardless of order, and
 * the override carries `!important` so it also beats an `!important` source `top`.
 *
 * Limitations (documented, not silent): only explicit `top:` anchors are shifted
 * (a full-viewport `inset: 0` decorative overlay is deliberately left alone); a
 * `top` first defined inside a nested @media is not tracked across that nesting.
 */

/** CSS custom property carrying the responsive admin-bar height. */
const ADMIN_BAR_VAR = '--wp-admin-bar-h';

interface FixedTopRule {
  /** The @media query text this rule lives under, or null for top level. */
  media: string | null;
  selector: string;
  /** The original `top` value (sans `!important`), e.g. `0`, `0.75rem`, `calc(...)`. */
  top: string;
}

/**
 * Build the admin-bar accommodation stylesheet for a carried CSS bundle. Always
 * emits the var definitions + document-bump re-assert; adds one shift override
 * per detected top-anchored fixed/sticky selector. Returns a trailing-newline
 * string safe to append to the bundle (empty-input safe).
 */
export function buildAdminBarAccommodationCss(css: string): string {
  const rules = scanTopAnchoredFixedRules(css);
  const lines: string[] = [
    '/* WP admin-bar accommodation (generated) — shift fixed/sticky top-anchored',
    '   chrome below the admin bar and re-assert the document bump so the bar',
    '   never overlaps page content for logged-in viewers. */',
    `body.admin-bar { ${ADMIN_BAR_VAR}: 32px; }`,
    `@media screen and (max-width: 782px) { body.admin-bar { ${ADMIN_BAR_VAR}: 46px; } }`,
    'html:has(body.admin-bar) { margin-top: 32px !important; }',
    '@media screen and (max-width: 782px) { html:has(body.admin-bar) { margin-top: 46px !important; } }',
  ];

  for (const r of rules.filter((r) => r.media === null)) {
    lines.push(overrideLine(r.selector, r.top));
  }

  const byMedia = new Map<string, FixedTopRule[]>();
  for (const r of rules) {
    if (r.media === null) continue;
    const arr = byMedia.get(r.media) ?? [];
    arr.push(r);
    byMedia.set(r.media, arr);
  }
  for (const [query, arr] of byMedia) {
    const inner = arr.map((r) => `  ${overrideLine(r.selector, r.top)}`).join('\n');
    lines.push(`@media ${query} {\n${inner}\n}`);
  }

  return `${lines.join('\n')}\n`;
}

/** Compose the original top with the bar-height var. Bare `0` is normalized to
 * `0px` (calc rejects a unitless number added to a length); nested calc is valid. */
function overrideLine(selector: string, top: string): string {
  const base = /^[+-]?0$/.test(top.trim()) ? '0px' : top;
  return `body.admin-bar ${selector} { top: calc((${base}) + var(${ADMIN_BAR_VAR}, 0px)) !important; }`;
}

function scanTopAnchoredFixedRules(css: string): FixedTopRule[] {
  const clean = stripCssComments(css);
  const { topLevel, media } = splitMediaBlocks(clean);
  const out: FixedTopRule[] = [];
  collectRegion(topLevel, null, out);
  for (const m of media) collectRegion(m.inner, m.query, out);

  const seen = new Set<string>();
  return out.filter((r) => {
    const key = `${r.media}||${r.selector}||${r.top}`;
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

/** Flat rule scan over a single region (top level or one @media's inner body). */
function collectRegion(region: string, media: string | null, out: FixedTopRule[]): void {
  const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
  let match: RegExpExecArray | null;
  while ((match = ruleRe.exec(region))) {
    const decls = match[2];
    if (!/position\s*:\s*(?:fixed|sticky)\b/i.test(decls)) continue;
    const topMatch = /(?:^|[;{])\s*top\s*:\s*([^;}!]+)/i.exec(decls);
    if (!topMatch) continue;
    const top = topMatch[1].trim();
    if (!top || /^auto$/i.test(top)) continue;
    for (const sel of match[1].split(',').map((s) => s.trim()).filter(Boolean)) {
      if (isEmittableSelector(sel)) out.push({ media, selector: sel, top });
    }
  }
}

/** Reject keyframe steps (`0%`, `from`) and at-rule fragments the flat parse can
 * surface; require at least one usable simple-selector character. */
function isEmittableSelector(sel: string): boolean {
  if (sel.startsWith('@')) return false;
  if (/^(?:from|to|\d+(?:\.\d+)?%)$/i.test(sel)) return false;
  return /[.#[a-zA-Z*]/.test(sel);
}

/** Split CSS into the top-level region (with @media blocks removed) and the list
 * of @media blocks (query + brace-matched inner body). Brace matching tolerates
 * nested rule braces inside a media block. */
function splitMediaBlocks(css: string): { topLevel: string; media: Array<{ query: string; inner: string }> } {
  const media: Array<{ query: string; inner: string }> = [];
  let topLevel = '';
  let i = 0;
  while (i < css.length) {
    const at = css.toLowerCase().indexOf('@media', i);
    if (at < 0) {
      topLevel += css.slice(i);
      break;
    }
    topLevel += css.slice(i, at);
    const braceOpen = css.indexOf('{', at);
    if (braceOpen < 0) {
      topLevel += css.slice(at);
      break;
    }
    const query = css.slice(at + '@media'.length, braceOpen).trim();
    let depth = 1;
    let j = braceOpen + 1;
    for (; j < css.length && depth > 0; j++) {
      if (css[j] === '{') depth++;
      else if (css[j] === '}') depth--;
      if (depth === 0) break;
    }
    media.push({ query, inner: css.slice(braceOpen + 1, j) });
    i = j + 1;
  }
  return { topLevel, media };
}

function stripCssComments(css: string): string {
  return css.replace(/\/\*[\s\S]*?\*\//g, '');
}
