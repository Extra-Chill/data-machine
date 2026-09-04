/** One normalized `@font-face` rule extracted from source CSS. */
export interface ParsedFontFace {
  /** Declared font-family name, unquoted (e.g. "Larsseit"). */
  family: string;
  /** Absolute (or protocol-relative) URL of the first usable font file. */
  src: string;
  /** Lowercased file extension without the dot, e.g. "woff" / "woff2" / "ttf". */
  format: string;
  /** font-weight value as written (e.g. "400", "700", "normal", "bold"). */
  weight: string;
  /** font-style value as written (e.g. "normal", "italic"). */
  style: string;
}

/** A captured font with a resolved LOCAL asset path inside the theme. */
export interface LocalFontFace extends ParsedFontFace {
  /** Theme-relative path the font file was written to, e.g. "assets/fonts/Larsseit-Regular.woff". */
  localPath: string;
}

const FONT_EXTENSIONS = new Set(['woff2', 'woff', 'ttf', 'otf', 'eot', 'svg']);

const GENERIC_FAMILIES = new Set([
  'serif',
  'sans-serif',
  'monospace',
  'cursive',
  'fantasy',
  'system-ui',
  'ui-sans-serif',
  'ui-serif',
  'ui-monospace',
  'inherit',
  'initial',
]);

/** Substrings in a font URL that mark third-party widget fonts (not the site's own). */
const THIRD_PARTY_FONT_HOST_HINTS = ['klaviyo.com', 'gstatic.com', 'typekit.net', 'use.typekit'];

export function parseFontFaces(...cssOrHtml: string[]): ParsedFontFace[] {
  const faces: ParsedFontFace[] = [];
  const seen = new Set<string>();

  for (const input of cssOrHtml) {
    if (!input) continue;
    const blockRe = /@font-face\s*\{([^}]*)\}/gi;
    let block: RegExpExecArray | null;
    while ((block = blockRe.exec(input)) !== null) {
      const body = block[1];

      const family = readDeclaration(body, 'font-family');
      if (!family) continue;
      const familyClean = family.replace(/^["']|["']$/g, '').trim();
      if (!familyClean || GENERIC_FAMILIES.has(familyClean.toLowerCase())) continue;

      const srcDecl = readDeclaration(body, 'src');
      if (!srcDecl) continue;
      const picked = pickBestFontUrl(srcDecl);
      if (!picked) continue;
      if (THIRD_PARTY_FONT_HOST_HINTS.some((h) => picked.url.toLowerCase().includes(h))) continue;

      const weight = normalizeWeight(readDeclaration(body, 'font-weight') ?? '400');
      const style = normalizeStyle(readDeclaration(body, 'font-style'));

      const key = `${familyClean.toLowerCase()}|${weight}|${style}|${picked.url}`;
      if (seen.has(key)) continue;
      seen.add(key);

      faces.push({ family: familyClean, src: picked.url, format: picked.format, weight, style });
    }
  }

  return faces;
}

function readDeclaration(body: string, prop: string): string | null {
  const re = new RegExp(`${prop}\\s*:\\s*([^;]+)`, 'i');
  const m = re.exec(body);
  return m ? m[1].trim() : null;
}

function pickBestFontUrl(srcDecl: string): { url: string; format: string } | null {
  const urlRe = /url\(\s*(['"]?)([^'")]+)\1\s*\)/gi;
  const candidates: { url: string; format: string }[] = [];
  let m: RegExpExecArray | null;
  while ((m = urlRe.exec(srcDecl)) !== null) {
    const url = m[2].trim();
    const ext = fontExtension(url);
    if (ext) candidates.push({ url, format: ext });
  }
  if (candidates.length === 0) return null;
  const byPref = (c: { format: string }): number =>
    c.format === 'woff2' ? 0 : c.format === 'woff' ? 1 : 2;
  candidates.sort((a, b) => byPref(a) - byPref(b));
  return candidates[0];
}

function fontExtension(url: string): string | null {
  // Strip query string + fragment before reading the extension.
  const clean = url.split('?')[0].split('#')[0];
  const dot = clean.lastIndexOf('.');
  if (dot < 0) return null;
  const ext = clean.slice(dot + 1).toLowerCase();
  return FONT_EXTENSIONS.has(ext) ? ext : null;
}

function normalizeWeight(raw: string): string {
  const v = raw.trim().toLowerCase();
  if (v === 'normal') return '400';
  if (v === 'bold') return '700';
  // Weight ranges ("400 700") - keep the first.
  const num = /\d{3}/.exec(v);
  return num ? num[0] : '400';
}

function normalizeStyle(raw: string | null): string {
  const v = (raw ?? '').trim().toLowerCase();
  if (v === 'italic') return 'italic';
  if (v.startsWith('oblique')) return 'oblique';
  return 'normal';
}

export function absolutizeFontUrl(src: string, baseUrl?: string): string {
  const trimmed = src.trim();
  if (trimmed.startsWith('//')) return `https:${trimmed}`;
  if (/^https?:\/\//i.test(trimmed)) return trimmed;
  if (baseUrl) {
    try {
      return new URL(trimmed, baseUrl).toString();
    } catch {
      return trimmed;
    }
  }
  return trimmed;
}

export function fontFilename(face: ParsedFontFace): string {
  const clean = face.src.split('?')[0].split('#')[0];
  const seg = clean.slice(clean.lastIndexOf('/') + 1);
  if (seg && fontExtension(seg)) {
    return seg.replace(/[^A-Za-z0-9._-]/g, '_');
  }
  const familySlug = face.family.replace(/[^A-Za-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  const italic = face.style === 'italic' ? '-italic' : '';
  return `${familySlug}-${face.weight}${italic}.${face.format}`;
}

export function buildFontFaceCss(faces: LocalFontFace[]): string {
  if (faces.length === 0) return '';
  const rules = faces.map((f) => {
    const fmt = cssFormat(f.format);
    return `@font-face {
\tfont-family: '${f.family}';
\tsrc: url('${f.localPath}')${fmt ? ` format('${fmt}')` : ''};
\tfont-weight: ${f.weight};
\tfont-style: ${f.style};
\tfont-display: swap;
}`;
  });
  return `\n/*\n * Self-hosted source fonts. Captured from the source site's @font-face\n * declarations and downloaded into assets/fonts/ so headings + body render in\n * the real typeface rather than a system fallback.\n */\n${rules.join('\n')}\n`;
}

function cssFormat(ext: string): string {
  switch (ext) {
    case 'woff2': return 'woff2';
    case 'woff': return 'woff';
    case 'ttf': return 'truetype';
    case 'otf': return 'opentype';
    case 'eot': return 'embedded-opentype';
    case 'svg': return 'svg';
    default: return '';
  }
}

export interface ThemeFontFamily {
  fontFamily: string;
  name: string;
  slug: string;
  fontFace?: Array<{
    fontFamily: string;
    fontWeight: string;
    fontStyle: string;
    src: string[];
  }>;
}

export function baseFamilyName(family: string): string {
  return family
    .replace(/[-_\s]+(thin|extralight|ultralight|light|regular|book|normal|medium|semibold|demibold|bold|extrabold|ultrabold|black|heavy|italic|oblique)$/i, '')
    .replace(/[-_\s]+(thin|extralight|ultralight|light|regular|book|normal|medium|semibold|demibold|bold|extrabold|ultrabold|black|heavy|italic|oblique)\b/gi, '')
    .replace(/[-_\s]+$/g, '')
    .trim() || family;
}

function weightFromFamilyName(family: string, declared: string): string {
  const m = /(thin|extralight|ultralight|light|regular|book|normal|medium|semibold|demibold|bold|extrabold|ultrabold|black|heavy)/i.exec(family);
  if (!m) return declared;
  const map: Record<string, string> = {
    thin: '100', extralight: '200', ultralight: '200', light: '300',
    regular: '400', book: '400', normal: '400', medium: '500',
    semibold: '600', demibold: '600', bold: '700', extrabold: '800',
    ultrabold: '800', black: '900', heavy: '900',
  };
  return map[m[1].toLowerCase()] ?? declared;
}

export function consolidateFontFaces<T extends ParsedFontFace>(faces: T[]): T[] {
  const byKey = new Map<string, T>();
  for (const f of faces) {
    const base = baseFamilyName(f.family);
    const weight = weightFromFamilyName(f.family, f.weight);
    const key = `${base.toLowerCase()}|${weight}|${f.style}`;
    const merged = { ...f, family: base, weight } as T;
    const existing = byKey.get(key);
    if (!existing) {
      byKey.set(key, merged);
      continue;
    }
    // Prefer woff2, then the URL without a hash-style suffix (cleaner filename).
    const score = (x: ParsedFontFace): number =>
      (x.format === 'woff2' ? 0 : 2) + (/_[0-9a-f]{8,}\./i.test(x.src) ? 1 : 0);
    if (score(merged) < score(existing)) byKey.set(key, merged);
  }
  return [...byKey.values()];
}

function normalizeFamilyBase(name: string): string {
  return name
    .toLowerCase()
    .replace(/^["']|["']$/g, '')
    .replace(/[0-9]{3,}/g, '') // drop builder hashes (e.g. ...light1475496)
    .replace(
      /[-_ ]+(thin|extra-?light|ultra-?light|light|book|regular|normal|roman|text|medium|semi-?bold|demi-?bold|demi|bold|extra-?bold|ultra-?bold|black|heavy|italic|oblique)\b/g,
      '',
    )
    .replace(/[-_ ]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .trim();
}

export function matchCapturedFamily(
  requestedStack: string | null | undefined,
  faces: ParsedFontFace[],
): string | null {
  if (!requestedStack) return null;
  const first = requestedStack.split(',')[0].trim().replace(/^["']|["']$/g, '').toLowerCase();
  if (!first) return null;
  for (const f of faces) {
    if (f.family.toLowerCase() === first) return f.family;
  }
  // Suffix-tolerant fallback: match on the normalized base name.
  const firstBase = normalizeFamilyBase(first);
  if (firstBase.length >= 4) {
    for (const f of faces) {
      if (normalizeFamilyBase(f.family) === firstBase) return f.family;
    }
  }
  return null;
}
