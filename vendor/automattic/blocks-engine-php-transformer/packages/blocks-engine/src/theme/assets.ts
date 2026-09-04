import { readFileSync, statSync } from 'node:fs';
import { dirname, relative, resolve } from 'node:path';
import { collectStaticAssets, type StaticImgRef } from './assets-static.js';
import { parseFontFaces, type ParsedFontFace } from './font-faces.js';
import { selfHostFonts } from './fonts-network.js';
import type { AssetFile, AssetInventory, SitePage, StageCtx } from './types.js';

export interface AssetStageResult {
  inventory: AssetInventory;
  imgRefsByPage: Record<string, StaticImgRef[]>;
  fontCss: string;
  warnings: string[];
}

const MISSING_FETCH_WARNING =
  'Remote font assets were detected but fetchImpl was not provided; skipping font self-hosting.';
const GOOGLE_FONTS_ORIGIN = 'https://fonts.googleapis.com';
const REMOTE_HTTP_RE = /^https?:\/\//i;

export async function assets(
  ctx: StageCtx,
  opts: { fetchImpl?: typeof fetch }
): Promise<AssetStageResult> {
  const { assets, imgRefsByPage } = collectStaticAssets(ctx.site, ctx.themeMeta.slug);
  const linkedCss = collectLinkedStylesheetCss(ctx);
  const parsedFontFaces: ParsedFontFace[] = parseFontFaces(...linkedCss);
  const remoteFontFaces = parsedFontFaces.filter(isRemoteFontFace);
  const cssUrls = collectGoogleFontCssUrls(ctx, linkedCss);

  if (cssUrls.length === 0 && remoteFontFaces.length === 0) {
    return {
      inventory: { assets },
      imgRefsByPage,
      fontCss: '',
      warnings: [],
    };
  }

  if (!opts.fetchImpl) {
    ctx.warn(MISSING_FETCH_WARNING);
    return {
      inventory: { assets },
      imgRefsByPage,
      fontCss: '',
      warnings: [MISSING_FETCH_WARNING],
    };
  }

  const hosted = await selfHostFonts(cssUrls, remoteFontFaces, {
    themeSlug: ctx.themeMeta.slug,
    fetchImpl: opts.fetchImpl,
  });

  for (const warning of hosted.warnings) {
    ctx.warn(warning);
  }

  return {
    inventory: { assets: sortAssetFiles([...assets, ...hosted.assets]) },
    imgRefsByPage,
    fontCss: hosted.localizedCss,
    warnings: hosted.warnings,
  };
}

function collectGoogleFontCssUrls(ctx: StageCtx, linkedCss: string[]): string[] {
  const urls = new Set<string>();

  for (const page of sortedPages(ctx.site.pages)) {
    for (const tag of page.html.matchAll(/<link\b[^>]*>/gi)) {
      const attrs = attributesFromTag(tag[0]);
      const rel = (attrs.get('rel') ?? '').toLowerCase().split(/\s+/).filter(Boolean);
      if (!rel.includes('stylesheet')) continue;

      const href = normalizeGoogleFontCssUrl(attrs.get('href'));
      if (href) urls.add(href);
    }
  }

  for (const css of linkedCss) {
    for (const ref of googleFontCssImports(css)) {
      const href = normalizeGoogleFontCssUrl(ref);
      if (href) urls.add(href);
    }
  }

  return [...urls].sort((a, b) => a.localeCompare(b));
}

function collectLinkedStylesheetCss(ctx: StageCtx): string[] {
  const root = resolve(ctx.srcDir);
  const cssByPath = new Map<string, string>();

  for (const page of sortedPages(ctx.site.pages)) {
    for (const tag of page.html.matchAll(/<link\b[^>]*>/gi)) {
      const attrs = attributesFromTag(tag[0]);
      const rel = (attrs.get('rel') ?? '').toLowerCase().split(/\s+/).filter(Boolean);
      if (!rel.includes('stylesheet')) continue;

      const cssPath = resolveLocalStylesheet(root, page, attrs.get('href'));
      if (!cssPath || cssByPath.has(cssPath)) continue;

      try {
        cssByPath.set(cssPath, readFileSync(cssPath, 'utf8'));
      } catch {
        // P1-1 treats linked CSS as inventory readiness only.
      }
    }
  }

  return Array.from(cssByPath.entries())
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([, css]) => css);
}

function resolveLocalStylesheet(
  root: string,
  page: SitePage,
  href: string | undefined
): string | null {
  const cleanHref = cleanLocalRef(href);
  if (!cleanHref) return null;

  const path = cleanHref.startsWith('/')
    ? resolve(root, `.${cleanHref}`)
    : resolve(root, dirname(page.relPath), cleanHref);

  if (!isInside(root, path) || !isFile(path)) return null;
  return path;
}

function googleFontCssImports(css: string): string[] {
  const refs: string[] = [];
  const importPattern =
    /@import\s+(?:url\(\s*)?(?:"([^"]+)"|'([^']+)'|([^'"\s)]+))\s*\)?/gi;

  for (const match of css.matchAll(importPattern)) {
    const ref = match[1] ?? match[2] ?? match[3];
    if (ref) refs.push(ref);
  }

  return refs;
}

function normalizeGoogleFontCssUrl(ref: string | undefined): string | null {
  const trimmed = decodeHtmlRef(ref).trim();
  if (!trimmed) return null;

  let candidate = trimmed;
  if (candidate.startsWith('//')) {
    candidate = `https:${candidate}`;
  } else if (candidate.startsWith('/css?') || candidate.startsWith('/css2?')) {
    candidate = `${GOOGLE_FONTS_ORIGIN}${candidate}`;
  }

  let url: URL;
  try {
    url = new URL(candidate);
  } catch {
    return null;
  }

  if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
  if (url.hostname !== 'fonts.googleapis.com') return null;
  if (url.pathname !== '/css' && url.pathname !== '/css2') return null;
  if (!url.search) return null;

  url.hash = '';
  return url.toString();
}

function decodeHtmlRef(ref: string | undefined): string {
  return (ref ?? '')
    .replace(/&amp;/gi, '&')
    .replace(/&#38;/g, '&')
    .replace(/&#x26;/gi, '&');
}

function cleanLocalRef(ref: string | undefined): string | null {
  const trimmed = ref?.trim();
  if (!trimmed || trimmed.startsWith('#')) return null;
  if (/^(?:[a-z][a-z\d+.-]*:|\/\/)/i.test(trimmed)) return null;

  const withoutHash = trimmed.split('#', 1)[0] ?? '';
  const withoutQuery = withoutHash.split('?', 1)[0] ?? '';
  if (!withoutQuery) return null;

  let decoded: string;
  try {
    decoded = decodeURIComponent(withoutQuery);
  } catch {
    return null;
  }

  const clean = decoded.trim();
  if (!clean || clean.startsWith('#') || clean.includes('\0')) return null;
  if (/^(?:[a-z][a-z\d+.-]*:|\/\/)/i.test(clean)) return null;
  return clean;
}

function isInside(root: string, file: string): boolean {
  const rel = relative(root, file);
  return rel === '' || (!rel.startsWith('..') && !rel.startsWith('/'));
}

function isFile(path: string): boolean {
  try {
    return statSync(path).isFile();
  } catch {
    return false;
  }
}

function isRemoteFontFace(face: ParsedFontFace): boolean {
  return REMOTE_HTTP_RE.test(face.src);
}

function sortAssetFiles(files: AssetFile[]): AssetFile[] {
  return [...files].sort(compareAssetFiles);
}

function compareAssetFiles(a: AssetFile, b: AssetFile): number {
  const rel = a.relPath.localeCompare(b.relPath);
  if (rel !== 0) return rel;
  return (a.sourcePath ?? '').localeCompare(b.sourcePath ?? '');
}

function attributesFromTag(tag: string): Map<string, string> {
  const attrs = new Map<string, string>();
  const attrPattern = /([^\s"'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;

  for (const match of tag.matchAll(attrPattern)) {
    const key = match[1]?.toLowerCase();
    if (!key || key === 'link') continue;
    attrs.set(key, match[2] ?? match[3] ?? match[4] ?? '');
  }

  return attrs;
}

function sortedPages(pages: SitePage[]): SitePage[] {
  return [...pages].sort((a, b) => {
    const rel = a.relPath.localeCompare(b.relPath);
    if (rel !== 0) return rel;
    return a.slug.localeCompare(b.slug);
  });
}
