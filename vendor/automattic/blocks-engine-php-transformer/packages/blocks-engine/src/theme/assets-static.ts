import { statSync } from 'node:fs';
import { dirname, extname, isAbsolute, relative, resolve, sep } from 'node:path';
import type { AssetFile, SiteModel, SitePage } from './types.js';

export interface StaticImgRef {
  ref: string;
  themeRel: string;
  sourcePath: string;
}

export interface StaticAssetResult {
  assets: AssetFile[];
  imgRefsByPage: Record<string, StaticImgRef[]>;
}

const IMG_TAG_RE = /<img\b[^>]*>/gi;
const SRC_ATTR_RE = /(\s+src\s*=\s*)(["'])([\s\S]*?)\2/gi;
const FIRST_SRC_ATTR_RE = /(\s+src\s*=\s*)(["'])([\s\S]*?)\2/i;
const URL_SCHEME_OR_PROTOCOL_RELATIVE_RE = /^(?:[a-z][a-z\d+.-]*:|\/\/)/i;

export function collectStaticAssets(site: SiteModel, themeSlug: string): StaticAssetResult {
  void themeSlug;

  const root = resolve(site.root);
  const sourceToThemeRel = new Map<string, string>();
  const themeRelToSource = new Map<string, string>();
  const assetsByThemeRel = new Map<string, AssetFile>();
  const imgRefsByPage: Record<string, StaticImgRef[]> = {};

  for (const page of sortedPages(site.pages)) {
    const refs: StaticImgRef[] = [];
    const refsSeenOnPage = new Set<string>();

    for (const ref of extractImgSrcRefs(page.html)) {
      const sourcePath = resolveLocalImagePath(root, page, ref);
      if (!sourcePath) continue;

      const themeRel = themeRelForSource(
        root,
        sourcePath,
        sourceToThemeRel,
        themeRelToSource
      );
      const pageRefKey = `${ref}\0${themeRel}\0${sourcePath}`;
      if (refsSeenOnPage.has(pageRefKey)) continue;

      refsSeenOnPage.add(pageRefKey);
      refs.push({ ref, themeRel, sourcePath });

      if (!assetsByThemeRel.has(themeRel)) {
        assetsByThemeRel.set(themeRel, {
          relPath: themeRel,
          sourcePath,
        });
      }
    }

    if (refs.length > 0) {
      imgRefsByPage[page.slug] = refs;
    }
  }

  const assets = [...assetsByThemeRel.values()].sort(compareAssetFiles);
  return { assets, imgRefsByPage };
}

export function rewriteHtmlImageSrcs(
  html: string,
  refs: StaticImgRef[],
  themeSlug: string
): string {
  const replacements = new Map<string, string>();
  for (const ref of refs) {
    if (!replacements.has(ref.ref)) {
      replacements.set(ref.ref, themeAssetUrl(themeSlug, ref.themeRel));
    }
  }

  if (replacements.size === 0) return html;

  return html.replace(IMG_TAG_RE, (tag) =>
    tag.replace(SRC_ATTR_RE, (match, prefix: string, quote: string, ref: string) => {
      const replacement = replacements.get(ref);
      return replacement ? `${prefix}${quote}${replacement}${quote}` : match;
    })
  );
}

function sortedPages(pages: SitePage[]): SitePage[] {
  return [...pages].sort((a, b) => {
    const rel = a.relPath.localeCompare(b.relPath);
    if (rel !== 0) return rel;
    return a.slug.localeCompare(b.slug);
  });
}

function extractImgSrcRefs(html: string): string[] {
  const refs: string[] = [];
  for (const tagMatch of html.matchAll(IMG_TAG_RE)) {
    const srcAttr = FIRST_SRC_ATTR_RE.exec(tagMatch[0]);
    if (srcAttr?.[3] !== undefined) {
      refs.push(srcAttr[3]);
    }
  }
  return refs;
}

function resolveLocalImagePath(root: string, page: SitePage, ref: string): string | null {
  const cleanRef = cleanLocalRef(ref);
  if (!cleanRef) return null;

  const sourcePath = cleanRef.startsWith('/')
    ? resolve(root, `.${cleanRef}`)
    : resolve(root, dirname(page.relPath), cleanRef);

  if (!isInside(root, sourcePath) || !isFile(sourcePath)) return null;
  return sourcePath;
}

function cleanLocalRef(ref: string): string | null {
  const trimmed = ref.trim();
  if (!trimmed || trimmed.startsWith('#')) return null;
  if (URL_SCHEME_OR_PROTOCOL_RELATIVE_RE.test(trimmed)) return null;

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
  if (URL_SCHEME_OR_PROTOCOL_RELATIVE_RE.test(clean)) return null;
  return clean;
}

function themeRelForSource(
  root: string,
  sourcePath: string,
  sourceToThemeRel: Map<string, string>,
  themeRelToSource: Map<string, string>
): string {
  const existing = sourceToThemeRel.get(sourcePath);
  if (existing) return existing;

  const preferred = preferredThemeRel(root, sourcePath);
  const themeRel = uniqueThemeRel(preferred, sourcePath, themeRelToSource);
  sourceToThemeRel.set(sourcePath, themeRel);
  themeRelToSource.set(themeRel, sourcePath);
  return themeRel;
}

function preferredThemeRel(root: string, sourcePath: string): string {
  const sourceRel = toPosixPath(relative(root, sourcePath));
  return sourceRel === 'assets' || sourceRel.startsWith('assets/')
    ? sourceRel
    : `assets/${sourceRel}`;
}

function uniqueThemeRel(
  preferred: string,
  sourcePath: string,
  themeRelToSource: Map<string, string>
): string {
  if (!themeRelToSource.has(preferred) || themeRelToSource.get(preferred) === sourcePath) {
    return preferred;
  }

  const ext = extname(preferred);
  const base = ext ? preferred.slice(0, -ext.length) : preferred;
  let suffix = 2;
  let candidate = `${base}-${suffix}${ext}`;

  while (themeRelToSource.has(candidate) && themeRelToSource.get(candidate) !== sourcePath) {
    suffix += 1;
    candidate = `${base}-${suffix}${ext}`;
  }

  return candidate;
}

function compareAssetFiles(a: AssetFile, b: AssetFile): number {
  const rel = a.relPath.localeCompare(b.relPath);
  if (rel !== 0) return rel;
  return (a.sourcePath ?? '').localeCompare(b.sourcePath ?? '');
}

function themeAssetUrl(themeSlug: string, themeRel: string): string {
  return `/wp-content/themes/${themeSlug}/${themeRel.replace(/^\/+/, '')}`;
}

function isInside(root: string, file: string): boolean {
  const rel = relative(root, file);
  return rel === '' || (!rel.startsWith('..') && !isAbsolute(rel));
}

function isFile(path: string): boolean {
  try {
    return statSync(path).isFile();
  } catch {
    return false;
  }
}

function toPosixPath(path: string): string {
  return path.split(sep).join('/');
}
