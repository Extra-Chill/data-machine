export interface ParsedFontFace {
  family: string;
  src: string;
  format?: string;
  weight?: string;
  style?: string;
}

const FONT_EXTENSIONS = new Set(['woff2', 'woff', 'ttf', 'otf', 'eot', 'svg']);
const GENERIC_FAMILIES = new Set([
  'serif',
  'sans-serif',
  'monospace',
  'cursive',
  'fantasy',
  'system-ui',
  'ui-serif',
  'ui-sans-serif',
  'ui-monospace',
  'ui-rounded',
  'emoji',
  'math',
  'fangsong',
  'inherit',
  'initial',
  'unset',
  'revert',
  'revert-layer',
]);
const FONT_FACE_BLOCK = /@font-face\s*\{[^{}]*\}/gi;

export function parseFontFaces(...cssOrHtml: string[]): ParsedFontFace[] {
  const faces: ParsedFontFace[] = [];
  const seen = new Set<string>();

  for (const input of cssOrHtml) {
    if (!input) continue;

    const blockRe = /@font-face\s*\{([^{}]*)\}/gi;
    let block: RegExpExecArray | null;
    while ((block = blockRe.exec(input)) !== null) {
      const body = block[1];
      const family = normalizeFamily(readDeclaration(body, 'font-family'));
      if (!family || GENERIC_FAMILIES.has(family.toLowerCase())) continue;

      const srcDecl = readDeclaration(body, 'src');
      if (!srcDecl) continue;

      const picked = pickBestFontUrl(srcDecl);
      if (!picked) continue;

      const weight = normalizeWeight(readDeclaration(body, 'font-weight') ?? '400');
      const style = normalizeStyle(readDeclaration(body, 'font-style'));
      const key = `${family.toLowerCase()}|${weight}|${style}|${picked.url}`;
      if (seen.has(key)) continue;

      seen.add(key);
      faces.push({ family, src: picked.url, format: picked.format, weight, style });
    }
  }

  return faces;
}

export function stripUnusedFontFaces(
  css: string,
  usageText: string
): { css: string; removed: number } {
  const usage = usageText.toLowerCase();
  let removed = 0;

  const out = css.replace(FONT_FACE_BLOCK, (block) => {
    const family = normalizeFamily(readDeclaration(block, 'font-family'));
    const used = family != null && usage.includes(family.toLowerCase());
    if (used) return block;

    removed++;
    return '';
  });

  return { css: out, removed };
}

function readDeclaration(body: string, prop: string): string | null {
  const re = new RegExp(`${prop}\\s*:\\s*([^;]+)`, 'i');
  const match = re.exec(body);
  return match ? match[1].trim() : null;
}

function normalizeFamily(raw: string | null): string | null {
  const family = raw?.replace(/^["']|["']$/g, '').trim();
  return family ? family : null;
}

function pickBestFontUrl(srcDecl: string): { url: string; format: string } | null {
  const urlRe = /url\(\s*(['"]?)([^'")]+)\1\s*\)\s*(?:format\(\s*(['"]?)([^'")]+)\3\s*\))?/gi;
  const candidates: Array<{ url: string; format: string; index: number }> = [];
  let match: RegExpExecArray | null;

  while ((match = urlRe.exec(srcDecl)) !== null) {
    const url = match[2].trim();
    if (!url || url.toLowerCase().startsWith('data:')) continue;

    const format = fontExtension(url) ?? normalizeFormat(match[4]);
    if (!format) continue;

    candidates.push({ url, format, index: candidates.length });
  }

  if (candidates.length === 0) return null;

  candidates.sort((a, b) => fontPreference(a.format) - fontPreference(b.format) || a.index - b.index);
  const picked = candidates[0];
  return { url: picked.url, format: picked.format };
}

function fontExtension(url: string): string | null {
  const clean = url.split('?')[0].split('#')[0];
  const dot = clean.lastIndexOf('.');
  if (dot < 0) return null;

  const ext = clean.slice(dot + 1).toLowerCase();
  return FONT_EXTENSIONS.has(ext) ? ext : null;
}

function normalizeFormat(raw: string | undefined): string | null {
  const value = raw?.trim().toLowerCase();
  if (!value) return null;

  if (value === 'woff2' || value === 'woff') return value;
  if (value === 'truetype') return 'ttf';
  if (value === 'opentype') return 'otf';
  if (value === 'embedded-opentype') return 'eot';
  if (FONT_EXTENSIONS.has(value)) return value;

  return null;
}

function fontPreference(format: string): number {
  if (format === 'woff2') return 0;
  if (format === 'woff') return 1;
  return 2;
}

function normalizeWeight(raw: string): string {
  const value = raw.trim().toLowerCase();
  if (value === 'normal') return '400';
  if (value === 'bold') return '700';

  const number = /\d{3}/.exec(value);
  return number ? number[0] : '400';
}

function normalizeStyle(raw: string | null): string {
  const value = (raw ?? '').trim().toLowerCase();
  if (value === 'italic') return 'italic';
  if (value.startsWith('oblique')) return 'oblique';
  return 'normal';
}
