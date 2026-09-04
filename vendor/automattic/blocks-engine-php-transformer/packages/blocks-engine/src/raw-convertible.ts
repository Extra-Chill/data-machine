import * as cheerio from 'cheerio';

export const UNWRAP_SELECTOR =
  'main, div.wp-block-group, div.wp-block-post-content, div.entry-content, div.wp-block-group__inner-container';

const SEMANTIC_TAGS = new Set([
  'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'dl',
  'blockquote', 'table', 'pre', 'figure', 'hr', 'address',
]);

const SEMANTIC_FLOOR = 0.6;

export function isRawConvertible(html: string): boolean {
  if (!html || !html.trim()) return false;
  const $ = cheerio.load(html, null, false);

  let changed = true;
  while (changed) {
    changed = false;
    $(UNWRAP_SELECTOR).each((_, el) => {
      const $el = $(el);
      if (($el.attr('class') || '').includes('wp-block-spacer')) return;
      $el.replaceWith($el.contents());
      changed = true;
    });
  }

  let semantic = 0;
  let nonSemantic = 0;
  $.root()
    .children()
    .each((_, el) => {
      const node = el as { type?: string; tagName?: string };
      if (node.type !== 'tag') return;
      const tag = (node.tagName || '').toLowerCase();
      const cls = $(el).attr('class') || '';
      if (cls.includes('wp-block-spacer')) return;
      if (SEMANTIC_TAGS.has(tag)) semantic++;
      else nonSemantic++;
    });

  const total = semantic + nonSemantic;
  return total > 0 && semantic >= 1 && semantic / total >= SEMANTIC_FLOOR;
}
