// src/lib/html-escape.ts
//
// The single home for HTML entity and CSS selector escaping. Pick the variant
// by context, not convenience.

/** Escape &, <, >: the minimal set for HTML text-node content. */
export function escapeHtmlText(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Escape &, <, >, ": text plus double-quoted attribute values. */
export function escapeHtmlAttr(s: string): string {
  return escapeHtmlText(s).replace(/"/g, '&quot;');
}

/** Escape &, <, >, ", ': the full set, safe in any HTML context. */
export function escapeHtml(s: string): string {
  return escapeHtmlAttr(s).replace(/'/g, '&#039;');
}

// CSS selector escaping, for synthesizing selectors from attribute values.

/** True when `value` is a plain CSS identifier usable directly after `#`. */
export function cssSimpleIdent(value: string): boolean {
  return /^[A-Za-z_][A-Za-z0-9_-]*$/.test(value);
}

/** Escape a value for embedding in a double-quoted CSS attribute selector. */
export function escapeCssAttrValue(value: string): string {
  return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
}

/** Selector for an element id: `#id` when plain, else `tag[id="…"]`. */
export function cssIdSelector(id: string, tag = ''): string {
  return cssSimpleIdent(id) ? `#${id}` : `${tag}[id="${escapeCssAttrValue(id)}"]`;
}
