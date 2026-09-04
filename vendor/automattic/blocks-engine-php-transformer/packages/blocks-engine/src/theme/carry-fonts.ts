const FONT_EXT = /\.(woff2|woff|ttf|otf|eot)(?:[?#]|$)/i;
// @font-face has no nested braces, so [^{}]* safely bounds one block (multi-line ok).
const FONT_FACE_BLOCK = /@font-face\s*\{[^{}]*\}/gi;
const URL_IN_DECL = /url\(\s*(['"]?)([^'")]+?)\1\s*\)/gi;
const FAMILY_DECL = /font-family\s*:\s*(['"]?)([^;'"}]+)\1/i;

/** Extract the font src URLs from an @font-face block body (remote or font-ext, never data:). */
function fontUrlsInBlock(block: string): string[] {
  const urls: string[] = [];
  const re = new RegExp(URL_IN_DECL.source, 'gi');
  let m: RegExpExecArray | null;
  while ((m = re.exec(block)) !== null) {
    const v = m[2].trim();
    if (v.startsWith('data:')) continue;
    if (/^(https?:)?\/\//i.test(v) || FONT_EXT.test(v)) urls.push(v);
  }
  return urls;
}

export interface StripResult {
  /** CSS with unused @font-face blocks removed. */
  css: string;
  /** Distinct src URLs from the KEPT (used) @font-face blocks. */
  keptUrls: string[];
  /** Number of @font-face blocks removed. */
  stripped: number;
}

export function stripUnusedFontFaces(css: string, usageText: string): StripResult {
  const usage = usageText.toLowerCase();
  const keptUrls = new Set<string>();
  let stripped = 0;
  const out = css.replace(FONT_FACE_BLOCK, (block) => {
    const fam = FAMILY_DECL.exec(block);
    const family = fam ? fam[2].trim() : null;
    const used = family != null && family.length > 0 && usage.includes(family.toLowerCase());
    if (used) {
      for (const u of fontUrlsInBlock(block)) keptUrls.add(u);
      return block;
    }
    stripped++;
    return '';
  });
  return { css: out, keptUrls: [...keptUrls], stripped };
}

export function stripCssSourceMaps(css: string): string {
  return css.replace(/\/\*#?\s*sourceMappingURL=[^*]*\*\//gi, '');
}
