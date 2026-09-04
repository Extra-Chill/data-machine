import { lstatSync, readdirSync, readFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import * as cheerio from 'cheerio';
import { BlocksEngineError } from '../errors.js';
import type { SiteModel, SitePage } from './types.js';

/** Recursively list *.html / *.htm files under root (skips dotdirs, node_modules, and symlinks). */
function listHtmlFiles(root: string): string[] {
  const out: string[] = [];
  const walk = (dir: string): void => {
    for (const name of readdirSync(dir)) {
      if (name.startsWith('.') || name === 'node_modules') continue;
      const full = join(dir, name);
      const st = lstatSync(full);
      if (st.isSymbolicLink()) continue;
      if (st.isDirectory()) walk(full);
      else if (name.toLowerCase().endsWith('.html') || name.toLowerCase().endsWith('.htm')) out.push(full);
    }
  };
  walk(root);
  return out;
}

/** One path segment -> slug-safe: lowercase, [^a-z0-9-] runs -> '-', collapse '--', trim edge dashes. */
function sanitizeSegment(seg: string): string {
  return seg
    .toLowerCase()
    .replace(/[^a-z0-9-]+/g, '-')
    .replace(/-{2,}/g, '-')
    .replace(/^-+|-+$/g, '');
}

function joinSlugSegments(parts: string[]): string {
  return parts.map(sanitizeSegment).filter(Boolean).join('-');
}

export function slugFromRelPath(relPath: string): string {
  const noExt = relPath.replace(/\.html?$/i, '');
  const parts = noExt.split(sep).filter(Boolean);
  if (parts.length === 0) return 'home';
  const last = parts[parts.length - 1];
  if (last.toLowerCase() === 'index') {
    if (parts.length === 1) return 'home';
    return joinSlugSegments(parts.slice(0, -1)) || 'home';
  }
  return joinSlugSegments(parts) || 'home';
}

export function ingest(srcDir: string): SiteModel {
  const files = listHtmlFiles(srcDir);
  if (files.length === 0) {
    throw new BlocksEngineError(`no html pages found under ${srcDir}`, {
      code: 'THEME_INGEST_NO_HTML',
      hint: 'Static site ingest requires at least one .html or .htm page.',
    });
  }

  const pages: SitePage[] = files.map((full) => {
    const html = readFileSync(full, 'utf8');
    const $ = cheerio.load(html);
    const relPath = relative(srcDir, full);
    const bodyData: Record<string, string> = {};
    const bodyAttrs = $('body').attr() ?? {};
    for (const [key, value] of Object.entries(bodyAttrs)) {
      if (key.startsWith('data-')) bodyData[key.slice('data-'.length)] = value;
    }
    return {
      relPath,
      slug: slugFromRelPath(relPath),
      html,
      title: $('title').first().text().trim(),
      ...(Object.keys(bodyData).length > 0 ? { bodyData } : {}),
    };
  });

  pages.sort((a, b) => a.slug.localeCompare(b.slug));

  const seen = new Set<string>();
  for (const page of pages) {
    if (seen.has(page.slug)) {
      throw new BlocksEngineError(`slug collision: "${page.slug}" from "${page.relPath}"`, {
        code: 'THEME_INGEST_DUPLICATE_SLUG',
        hint: 'Static site ingest requires every html page to map to a unique slug.',
      });
    }
    seen.add(page.slug);
  }

  return { root: srcDir, pages };
}
