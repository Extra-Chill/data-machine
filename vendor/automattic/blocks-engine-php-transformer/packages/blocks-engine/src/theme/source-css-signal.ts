/**
 * Match-based "rich source CSS" signal.
 *
 * The fidelity strategy (carrying source CSS so a class-preserving DOM is styled) is only
 * worthwhile for a section when the carried CSS ACTUALLY targets that section's source
 * identity (its original classes / id). A non-empty check is not enough: carrying CSS that
 * targets `.hero`/`.menu-card` against a class-less native DOM is exactly the stripped-body
 * bug this work exists to fix. This module answers, per section, "does the carried CSS
 * contain selectors that match this section's sourceClasses / sourceId?" — the signal that
 * gates routing a section through class-preserving reconstruction (preserve-dom).
 *
 * It is a heuristic, not a full CSS parser: it scans selector preludes (text before each
 * `{`, comments stripped) so declaration values like `#fff` or `1.5` never count as
 * id/class matches.
 */

export interface SourceCssSignal {
  /** Source classes that appear as class selectors in the CSS. */
  matchedClasses: string[];
  /** Whether the source id appears as an id selector in the CSS. */
  matchedId: boolean;
  /** Count of distinct source-identity tokens the CSS targets (classes + id). */
  score: number;
  /** True when the CSS targets at least one of the section's identity tokens. */
  rich: boolean;
}

const SELECTOR_CLASS_RE = /\.(-?[A-Za-z_][\w-]*)/g;
const SELECTOR_ID_RE = /#(-?[A-Za-z_][\w-]*)/g;
const COMMENT_RE = /\/\*[\s\S]*?\*\//g;

function dedupeTrimmed(values: readonly string[]): string[] {
  const seen = new Set<string>();
  const out: string[] = [];
  for (const raw of values) {
    const value = raw.trim();
    if (value && !seen.has(value)) {
      seen.add(value);
      out.push(value);
    }
  }
  return out;
}

/** Join the selector prelude of every rule (text before `{`), comments removed. */
function selectorPreludes(css: string): string {
  const noComments = css.replace(COMMENT_RE, '');
  const preludes: string[] = [];
  for (const match of noComments.matchAll(/([^{}]*)\{/g)) {
    preludes.push(match[1]);
  }
  return preludes.join('\n');
}

/** Distinct class and id selector tokens used anywhere in the CSS's selectors. */
export function collectCssSelectorTokens(css: string): { classes: Set<string>; ids: Set<string> } {
  const prelude = selectorPreludes(css);
  const classes = new Set<string>();
  const ids = new Set<string>();
  for (const match of prelude.matchAll(SELECTOR_CLASS_RE)) classes.add(match[1]);
  for (const match of prelude.matchAll(SELECTOR_ID_RE)) ids.add(match[1]);
  return { classes, ids };
}

/**
 * Whether the carried CSS targets a section's source identity. Pass the carried source CSS
 * (or the full carried bundle — compat selectors won't collide with real source classes).
 */
export function sectionCssRichness(
  css: string | undefined,
  sourceClasses: readonly string[],
  sourceId?: string
): SourceCssSignal {
  if (!css || !css.trim()) {
    return { matchedClasses: [], matchedId: false, score: 0, rich: false };
  }
  const { classes, ids } = collectCssSelectorTokens(css);
  const matchedClasses = dedupeTrimmed(sourceClasses).filter((token) => classes.has(token));
  const id = sourceId?.trim();
  const matchedId = Boolean(id && ids.has(id));
  const score = matchedClasses.length + (matchedId ? 1 : 0);
  return { matchedClasses, matchedId, score, rich: score > 0 };
}
