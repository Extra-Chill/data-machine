// Matches all https://fonts.googleapis.com/css or css2 query-string URLs found in
// href attributes, @import rules, or bare text. Handles HTML-encoded & (&amp;).
const GOOGLE_CSS_RE = /https:\/\/fonts\.googleapis\.com\/css2?\?[^"'\s)]+/g;

/** Find unique Google-Fonts css/css2 URLs across html/css source strings (deduped). */
export function extractGoogleFontCssUrls(sources: string[]): string[] {
  const found = new Set<string>();
  for (const src of sources) {
    for (const m of src.matchAll(GOOGLE_CSS_RE)) {
      found.add(m[0].replace(/&amp;/g, '&'));
    }
  }
  return [...found];
}
