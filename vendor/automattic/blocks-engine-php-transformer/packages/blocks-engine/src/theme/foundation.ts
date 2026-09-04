import { existsSync, readFileSync } from 'node:fs';
import { dirname, relative, resolve } from 'node:path';
import * as cheerio from 'cheerio';
import type { FoundationAggregates, FoundationTokens, SiteModel, SitePage } from './types.js';

type CssFragment = {
  key: string;
  css: string;
};

type ColorCandidate = {
  color: string;
  count: number;
  firstIndex: number;
  name?: string;
};

type FontCandidate = {
  family: string;
  count: number;
  firstIndex: number;
  bodyCount: number;
  displayCount: number;
};

const MAX_PALETTE_COLORS = 12;

const COLOR_VALUE_RE = /#(?:[\da-f]{3,4}|[\da-f]{6}|[\da-f]{8})\b|(?:rgb|hsl)a?\(\s*[^)]*\)/gi;
const COLOR_DECL_RE =
  /(?:^|[;{\s])((?:--[-\w]*(?:color|primary|secondary|accent|background|foreground|text|brand)[-\w]*)|color|background(?:-color)?|border(?:-[\w]+)?-color|outline-color|fill|stroke)\s*:\s*([^;{}]+)/gi;
const FONT_DECL_RE = /font-family\s*:\s*([^;{}]+)/gi;
const MEDIA_RE = /@media[^{]*/gi;
const MEDIA_WIDTH_RE = /\(\s*(?:min|max)-width\s*:\s*(\d+)px\s*\)/gi;

export function foundation(site: SiteModel, aggregates?: FoundationAggregates): FoundationTokens {
  const cssFragments = collectCssFragments(site);
  const cssTexts = cssFragments.map((fragment) => stripCssComments(fragment.css));

  const tokens: FoundationTokens = {
    palette: extractPalette(cssTexts),
    typography: extractTypography(cssTexts),
    breakpoints: extractBreakpoints(cssTexts),
  };

  const aggregatePalette = coercePalette(aggregates?.palette);
  if (aggregatePalette) tokens.palette = aggregatePalette;

  const aggregateTypography = coerceTypography(aggregates?.typography);
  if (aggregateTypography) {
    tokens.typography = { ...tokens.typography, ...aggregateTypography };
  }

  const aggregateBreakpoints = coerceBreakpoints(aggregates?.breakpoints);
  if (aggregateBreakpoints) {
    tokens.breakpoints = { ...tokens.breakpoints, ...aggregateBreakpoints };
  }

  return tokens;
}

function collectCssFragments(site: SiteModel): CssFragment[] {
  const root = resolve(site.root);
  const fragments: CssFragment[] = [];
  const linked = new Set<string>();

  for (const page of sortedPages(site.pages)) {
    const $ = cheerio.load(page.html);

    $('link').each((index, element) => {
      const rel = ($(element).attr('rel') ?? '').toLowerCase().split(/\s+/).filter(Boolean);
      if (!rel.includes('stylesheet')) return;

      const cssPath = resolveStylesheetPath(root, page, $(element).attr('href'));
      if (!cssPath || linked.has(cssPath) || !existsSync(cssPath)) return;

      linked.add(cssPath);
      fragments.push({
        key: `link:${relative(root, cssPath)}`,
        css: readFileSync(cssPath, 'utf8'),
      });
    });

    $('style').each((index, element) => {
      fragments.push({
        key: `style:${page.relPath}:${index.toString().padStart(4, '0')}`,
        css: $(element).html() ?? $(element).text(),
      });
    });
  }

  return fragments.sort((a, b) => a.key.localeCompare(b.key));
}

function sortedPages(pages: SitePage[]): SitePage[] {
  return [...pages].sort((a, b) => {
    const rel = a.relPath.localeCompare(b.relPath);
    if (rel !== 0) return rel;
    return a.slug.localeCompare(b.slug);
  });
}

function resolveStylesheetPath(root: string, page: SitePage, href: string | undefined): string | null {
  const cleanHref = cleanLocalHref(href);
  if (!cleanHref) return null;

  const cssPath = cleanHref.startsWith('/')
    ? resolve(root, `.${cleanHref}`)
    : resolve(root, dirname(page.relPath), cleanHref);

  return isInside(root, cssPath) ? cssPath : null;
}

function cleanLocalHref(href: string | undefined): string | null {
  const trimmed = href?.trim();
  if (!trimmed) return null;
  if (/^(?:[a-z][a-z\d+.-]*:|\/\/)/i.test(trimmed)) return null;

  const withoutHash = trimmed.split('#', 1)[0] ?? '';
  const withoutQuery = withoutHash.split('?', 1)[0] ?? '';
  return withoutQuery || null;
}

function isInside(root: string, file: string): boolean {
  const rel = relative(root, file);
  return rel === '' || (!rel.startsWith('..') && !rel.startsWith('/'));
}

function stripCssComments(css: string): string {
  return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

function extractPalette(cssTexts: string[]): FoundationTokens['palette'] {
  const candidates = new Map<string, ColorCandidate>();
  let index = 0;

  for (const css of cssTexts) {
    for (const declaration of css.matchAll(COLOR_DECL_RE)) {
      const property = declaration[1] ?? '';
      const value = declaration[2] ?? '';
      const name = property.startsWith('--') ? nameFromToken(property) : undefined;
      for (const colorMatch of value.matchAll(COLOR_VALUE_RE)) {
        addColor(candidates, normalizeColor(colorMatch[0]), index, name);
        index += 1;
      }
    }
  }

  return Array.from(candidates.values())
    .sort(compareColorCandidates)
    .slice(0, MAX_PALETTE_COLORS)
    .map((candidate, paletteIndex) => ({
      name: candidate.name ?? `Color ${paletteIndex + 1}`,
      color: candidate.color,
    }));
}

function addColor(
  candidates: Map<string, ColorCandidate>,
  color: string,
  firstIndex: number,
  name: string | undefined
): void {
  const existing = candidates.get(color);
  if (existing) {
    existing.count += 1;
    if (!existing.name && name) existing.name = name;
    return;
  }

  candidates.set(color, {
    color,
    count: 1,
    firstIndex,
    ...(name ? { name } : {}),
  });
}

function compareColorCandidates(a: ColorCandidate, b: ColorCandidate): number {
  return b.count - a.count || a.firstIndex - b.firstIndex || a.color.localeCompare(b.color);
}

function normalizeColor(color: string): string {
  const trimmed = color.trim().replace(/\s+/g, ' ');
  return trimmed.startsWith('#') ? trimmed.toLowerCase() : trimmed;
}

function extractTypography(cssTexts: string[]): FoundationTokens['typography'] {
  const candidates = new Map<string, FontCandidate>();
  let index = 0;

  for (const css of cssTexts) {
    for (const match of css.matchAll(FONT_DECL_RE)) {
      const family = normalizeFontFamily(match[1] ?? '');
      if (!family) continue;

      const selector = selectorForDeclaration(css, match.index ?? 0);
      const candidate = candidates.get(family) ?? {
        family,
        count: 0,
        firstIndex: index,
        bodyCount: 0,
        displayCount: 0,
      };

      candidate.count += 1;
      if (isBodySelector(selector)) candidate.bodyCount += 1;
      if (isDisplaySelector(selector)) candidate.displayCount += 1;
      candidates.set(family, candidate);
      index += 1;
    }
  }

  const ranked = Array.from(candidates.values()).sort(compareFontCandidates);
  if (ranked.length === 0) return {};

  const body = selectFont(ranked, 'bodyCount')?.family ?? ranked[0]?.family;
  const display = selectFont(ranked, 'displayCount')?.family ?? ranked.find((font) => font.family !== body)?.family ?? body;

  return {
    ...(body ? { body } : {}),
    ...(display ? { display } : {}),
  };
}

function normalizeFontFamily(value: string): string {
  return value.replace(/\s*!important\s*$/i, '').replace(/\s+/g, ' ').trim();
}

function selectorForDeclaration(css: string, declarationIndex: number): string {
  const blockStart = css.lastIndexOf('{', declarationIndex);
  if (blockStart === -1) return '';

  const previousBlockEnd = css.lastIndexOf('}', blockStart);
  return css.slice(previousBlockEnd + 1, blockStart).trim();
}

function isBodySelector(selector: string): boolean {
  return /(?:^|[\s,{>+~])(?:html|body|main|article|p)(?:$|[\s,.#:>+~])/i.test(selector);
}

function isDisplaySelector(selector: string): boolean {
  return /(?:^|[\s,{>+~.])(?:h[1-6]|heading|title|display|hero)(?:$|[\s,.#:>+~-])/i.test(selector);
}

function compareFontCandidates(a: FontCandidate, b: FontCandidate): number {
  return b.count - a.count || a.firstIndex - b.firstIndex || a.family.localeCompare(b.family);
}

function selectFont(
  candidates: FontCandidate[],
  preferredCount: 'bodyCount' | 'displayCount'
): FontCandidate | undefined {
  return [...candidates]
    .sort(
      (a, b) =>
        b[preferredCount] - a[preferredCount] ||
        b.count - a.count ||
        a.firstIndex - b.firstIndex ||
        a.family.localeCompare(b.family)
    )
    .find((candidate) => candidate[preferredCount] > 0);
}

function extractBreakpoints(cssTexts: string[]): FoundationTokens['breakpoints'] {
  const widths = new Set<number>();

  for (const css of cssTexts) {
    for (const media of css.matchAll(MEDIA_RE)) {
      for (const width of media[0].matchAll(MEDIA_WIDTH_RE)) {
        widths.add(Number(width[1]));
      }
    }
  }

  const sorted = Array.from(widths).filter(Number.isInteger).sort((a, b) => a - b);
  return {
    ...(sorted[0] !== undefined ? { md: `${sorted[0]}px` } : {}),
    ...(sorted[1] !== undefined ? { lg: `${sorted[1]}px` } : {}),
    ...(sorted[2] !== undefined ? { xl: `${sorted[2]}px` } : {}),
  };
}

function coercePalette(value: unknown): FoundationTokens['palette'] | undefined {
  if (Array.isArray(value)) {
    return value.flatMap((entry, index) => {
      if (typeof entry === 'string') {
        const color = normalizeColor(entry);
        return color ? [{ name: `Color ${index + 1}`, color }] : [];
      }

      if (!isRecord(entry) || typeof entry.color !== 'string') return [];
      const name = typeof entry.name === 'string' && entry.name.trim() ? entry.name.trim() : `Color ${index + 1}`;
      return [{ name, color: normalizeColor(entry.color) }];
    });
  }

  if (!isRecord(value)) return undefined;
  return Object.entries(value).flatMap(([name, color]) =>
    typeof color === 'string' ? [{ name: nameFromToken(name) ?? name, color: normalizeColor(color) }] : []
  );
}

function coerceTypography(value: unknown): FoundationTokens['typography'] | undefined {
  if (!isRecord(value)) return undefined;

  const body = typeof value.body === 'string' ? normalizeFontFamily(value.body) : undefined;
  const display = typeof value.display === 'string' ? normalizeFontFamily(value.display) : undefined;

  return {
    ...(body ? { body } : {}),
    ...(display ? { display } : {}),
  };
}

function coerceBreakpoints(value: unknown): FoundationTokens['breakpoints'] | undefined {
  if (!isRecord(value)) return undefined;

  return {
    ...coerceBreakpoint('md', value.md),
    ...coerceBreakpoint('lg', value.lg),
    ...coerceBreakpoint('xl', value.xl),
  };
}

function coerceBreakpoint(
  key: keyof FoundationTokens['breakpoints'],
  value: unknown
): FoundationTokens['breakpoints'] {
  if (typeof value === 'number' && Number.isInteger(value)) return { [key]: `${value}px` };
  if (typeof value === 'string' && value.trim()) return { [key]: value.trim() };
  return {};
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function nameFromToken(token: string): string | undefined {
  const words = token
    .replace(/^--/, '')
    .replace(/\bcolor\b/g, '')
    .split(/[-_\s]+/)
    .map((word) => word.trim())
    .filter(Boolean);

  if (words.length === 0) return undefined;
  return words.map((word) => word[0]?.toUpperCase() + word.slice(1)).join(' ');
}
