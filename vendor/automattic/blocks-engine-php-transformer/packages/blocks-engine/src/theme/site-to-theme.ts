import { basename, extname, join, resolve } from 'node:path';

import { createWorker } from '../pool/pool.js';
import { assemble } from './assemble.js';
import type { StaticImgRef } from './assets-static.js';
import { assets as runAssetsStage } from './assets.js';
import { chrome } from './chrome.js';
import { buildThemeConversionDiagnostics } from './conversion-diagnostics.js';
import { foundation } from './foundation.js';
import { ingest } from './ingest.js';
import { detectLayoutOffsetWrapper } from './layout-offset-wrapper.js';
import type { SectionRenderOptions } from './native-reconstruct-types.js';
import { reconstruct } from './reconstruct.js';
import { extractSourceLandmarksFromHtml } from './region-census.js';
import { reconcileRegions, type PlacedRegion, type RegionSelectionReport } from './region-audit.js';
import { sectionExtract } from './section-extract.js';
import { collectSourceAssets, type ImgAssetRef, type MediaAsset } from './source-assets.js';
import { hasCarriedSourceCss, shouldCarrySourceCss } from './source-css-carry.js';
import {
  collectRemoteImageAssets,
  createPublicHostGuardedFetch,
  type HostLookup,
} from './remote-images.js';
import { createRichCssRoutingStrategy } from './routing-strategy.js';
import { applyHoistSwaps, hoistVariations, type HoistedVariation } from './variation-hoist.js';
import { writeTheme } from './write-theme.js';
import type {
  AssetFile,
  AssetInventory,
  AssetVerdicts,
  SectionBlocks,
  SiteToThemeOptions,
  StageCtx,
  ThemeBuildResult,
  ThemeConversionDiagnostics,
  ThemeMeta,
} from './types.js';

export async function siteToTheme(
  srcDir: string,
  options?: SiteToThemeOptions
): Promise<ThemeBuildResult> {
  const site = ingest(srcDir);
  const warnings: string[] = [];
  const themeMeta = normalizeThemeMeta(srcDir, options?.themeMeta);
  const ctx: StageCtx = {
    srcDir,
    site,
    themeMeta,
    warn(message: string) {
      warnings.push(message);
    },
  };
  const ownsPool = options?.pool === undefined;
  const pool = options?.pool ?? createWorker();
  const outDir = options?.outDir ?? join(srcDir, 'theme');
  const coverageFloor = options?.coverageFloor ?? 0;
  const hooks = options?.hooks ?? {};

  try {
    const baseTokens = foundation(site, options?.foundationAggregates);
    const tokens = hooks.onFoundation ? await hooks.onFoundation(baseTokens, ctx) : baseTokens;
    const chromeRes = await chrome(ctx, pool);
    const sourceAssets = collectSourceAssets(
      site.root,
      site.pages.map((page) => ({ relPath: page.relPath, html: page.html }))
    );
    const sourceImgRefsByPage = sourceImgRefsBySlug(
      site.pages,
      sourceAssets.imgRewritesByPage,
      sourceAssets.imgAssets
    );
    const remoteImageCarry = await collectRemoteImageAssets(site.pages, {
      fetchImpl: resolveRemoteImageFetchImpl(options?.fetchImpl, options?.imageHostLookup),
      occupiedRelPaths: sourceAssets.imgAssets.map((asset) => asset.themeRel),
    });
    for (const warning of remoteImageCarry.warnings) {
      ctx.warn(warning);
    }
    const carriedImgRefsByPage = mergeImgRefsByPage(
      sourceImgRefsByPage,
      remoteImageCarry.imgRefsByPage
    );
    const sourceMediaUrlMapsByPage = sourceMediaUrlMapsBySlug(carriedImgRefsByPage, themeMeta.slug);
    const pages: Record<string, SectionBlocks[]> = {};

    // Per-section routing: when enabled and source CSS is carried, route rich sections through
    // class-preserving preserve-dom so the carried CSS styles the body. Its drained instance CSS
    // (lib-i...) is accumulated across pages and appended to style.css via assemble's dedupCss.
    const dedupCssRules = new Set<string>();
    const routingStrategy =
      options?.routeRichSections && hasCarriedSourceCss(sourceAssets.css)
        ? createRichCssRoutingStrategy({ carriedCss: sourceAssets.css })
        : undefined;

    for (const page of site.pages) {
      const specs =
        options?.sections?.[page.slug] ??
        sectionExtract({ ...page, html: chromeRes.mainHtmlByPage[page.slug] ?? page.html });
      const baseRenderOptions = pageRenderOptions(
        options?.renderOptions?.[page.slug],
        sourceMediaUrlMapsByPage[page.slug]
      );
      const renderOptions = {
        ...(baseRenderOptions ?? {}),
        ...(routingStrategy ? { strategy: routingStrategy } : {}),
        // Collect drained lib-i instance CSS from whichever strategy runs — the default
        // preserve-dom strategy hashes inline styles into lib-i classes too, so onDedup must
        // be wired unconditionally or those rules never reach style.css (the lib-i classes
        // would appear in markup with no backing CSS).
        onDedup: (rules: string[]) => {
          for (const rule of rules) dedupCssRules.add(rule);
        },
      };
      pages[page.slug] = await reconstruct(specs, ctx, pool, hooks, coverageFloor, renderOptions);
    }
    const dedupCss = dedupCssRules.size ? [...dedupCssRules].join('\n') : undefined;

    const regionAudit = buildRegionAuditDiagnostics(site, pages, chromeRes.parts, chromeRes.slugsByPage, warnings);
    const styleBlocks =
      options?.variationHoist === false ? undefined : hoistPageStyleBlocks(site, pages, warnings);
    const conversionDiagnostics = await buildThemeConversionDiagnostics(
      site.pages.map((page) => ({
        slug: page.slug,
        inputHtml: chromeRes.mainHtmlByPage[page.slug] ?? page.html,
        sections: pages[page.slug] ?? [],
      })),
      pool
    );
    pushConversionWarnings(conversionDiagnostics, ctx.warn);
    const assetStage = await runAssetsStage(ctx, { fetchImpl: options?.fetchImpl });
    const layoutOffsetWrapperClass = detectLayoutOffsetWrapper(
      homePage(site)?.html ?? '',
      sourceAssets.css
    );
    const carrySourceCss = shouldCarrySourceCss(sourceAssets.css, options);
    const inventory = hooks.onAssets
      ? filterDecorativeAssets(
          assetStage.inventory,
          await hooks.onAssets(assetStage.inventory, ctx)
        )
      : assetStage.inventory;
    const sourceCssCarry = carrySourceCss
      ? prepareSourceCssCarry(sourceAssets.css, sourceAssets.mediaAssets, inventory.assets)
      : undefined;
    const assembledAssets = mergeAssetFiles(
      mergeCarriedImgAssets(
        sourceCssCarry?.assets ?? inventory.assets,
        sourceAssets.imgAssets
      ),
      remoteImageCarry.assets
    );
    const assembled = assemble({
      site,
      tokens,
      pages,
      meta: themeMeta,
      assets: assembledAssets,
      fontCss: assetStage.fontCss,
      imgRefsByPage: mergeImgRefsByPage(carriedImgRefsByPage, assetStage.imgRefsByPage),
      chromeParts: chromeRes.parts,
      chromeSlugsByPage: chromeRes.slugsByPage,
      layoutOffsetWrapperClass,
      styleBlocks,
      sourceCss: sourceCssCarry?.css,
      dedupCss,
    });
    const model = hooks.onRefine ? await hooks.onRefine(assembled, ctx) : assembled;
    const written = await writeTheme(model, outDir);

    return {
      outDir,
      model,
      written,
      tallies: {
        pages: site.pages.length,
        sections: Object.values(pages).reduce((sum, sections) => sum + sections.length, 0),
        templates: Object.keys(model.templates).length,
        parts: Object.keys(model.parts).length,
        patterns: Object.keys(model.patterns).length,
        assets: model.assets.length,
        fallbacks: conversionDiagnostics.totalFallbacks,
        warnings: warnings.length,
      },
      warnings,
      diagnostics: {
        conversion: conversionDiagnostics,
        regionAudit,
      },
    };
  } finally {
    if (ownsPool) {
      await pool.stop();
    }
  }
}

function pushConversionWarnings(
  diagnostics: ThemeConversionDiagnostics,
  warn: (message: string) => void
): void {
  for (const page of diagnostics.pages) {
    if (page.fallbackCount > 0) {
      warn(`page ${page.slug}: ${page.fallbackCount} section(s) fell back to raw HTML`);
    }
    if (page.degraded) {
      warn(`page ${page.slug}: conversion degraded`);
    }
  }
}

function sourceImgRefsBySlug(
  pages: Array<{ slug: string; relPath: string }>,
  imgRewritesByPage: Record<string, ImgAssetRef[]>,
  imgAssets: MediaAsset[]
): Record<string, StaticImgRef[]> {
  const sourcePathByThemeRel = new Map(
    imgAssets.map((asset) => [asset.themeRel, asset.srcAbs])
  );
  const out: Record<string, StaticImgRef[]> = {};

  for (const page of pages) {
    const refs = imgRewritesByPage[page.relPath];
    if (!refs?.length) continue;

    out[page.slug] = refs.map((ref) => ({
      ref: ref.ref,
      themeRel: ref.themeRel,
      sourcePath: sourcePathByThemeRel.get(ref.themeRel) ?? '',
    }));
  }

  return out;
}

function sourceMediaUrlMapsBySlug(
  imgRefsByPage: Record<string, StaticImgRef[]>,
  themeSlug: string
): Record<string, Map<string, string>> {
  const out: Record<string, Map<string, string>> = {};

  for (const [slug, refs] of Object.entries(imgRefsByPage)) {
    const mediaUrlMap = new Map<string, string>();
    for (const ref of refs) {
      mediaUrlMap.set(ref.ref, themeAssetUrl(themeSlug, ref.themeRel));
    }
    if (mediaUrlMap.size > 0) out[slug] = mediaUrlMap;
  }

  return out;
}

function pageRenderOptions(
  options: SectionRenderOptions | undefined,
  sourceMediaUrlMap: Map<string, string> | undefined
): SectionRenderOptions | undefined {
  if (!sourceMediaUrlMap?.size) return options;

  const mediaUrlMap = new Map(sourceMediaUrlMap);
  for (const [from, to] of options?.mediaUrlMap ?? []) {
    mediaUrlMap.set(from, to);
  }

  return {
    ...options,
    mediaUrlMap,
  };
}

function mergeImgRefsByPage(
  carriedRefsByPage: Record<string, StaticImgRef[]>,
  staticRefsByPage: Record<string, StaticImgRef[]>
): Record<string, StaticImgRef[]> {
  const out: Record<string, StaticImgRef[]> = {};
  const slugs = new Set([
    ...Object.keys(carriedRefsByPage),
    ...Object.keys(staticRefsByPage),
  ]);

  for (const slug of slugs) {
    const refs = dedupeImgRefs([
      ...(carriedRefsByPage[slug] ?? []),
      ...(staticRefsByPage[slug] ?? []),
    ]);
    if (refs.length > 0) out[slug] = refs;
  }

  return out;
}

function dedupeImgRefs(refs: StaticImgRef[]): StaticImgRef[] {
  const seen = new Set<string>();
  const out: StaticImgRef[] = [];

  for (const ref of refs) {
    if (seen.has(ref.ref)) continue;
    seen.add(ref.ref);
    out.push(ref);
  }

  return out;
}

function resolveRemoteImageFetchImpl(
  fetchImpl: typeof fetch | undefined,
  lookup?: HostLookup
): typeof fetch | undefined {
  // An injected fetchImpl owns its own transport + SSRF posture — do not double-guard it.
  if (fetchImpl) return fetchImpl;
  const defaultFetch = globalThis.fetch;
  if (typeof defaultFetch !== 'function') return undefined;
  // The default path fetches URLs scraped from (potentially untrusted) source HTML, so
  // guard resolved hostnames against internal IPs on top of the per-URL string guard.
  return createPublicHostGuardedFetch(defaultFetch.bind(globalThis) as typeof fetch, lookup);
}

function mergeAssetFiles(a: AssetFile[], b: AssetFile[]): AssetFile[] {
  if (b.length === 0) return a;

  const byRelPath = new Map<string, AssetFile>();
  for (const asset of a) {
    byRelPath.set(asset.relPath, asset);
  }
  // `a` (CSS-carried / inventory / local image assets) wins on collision: a remote
  // image must never silently overwrite an already-allocated asset at the same relPath
  // (local img assets are already collision-protected via occupiedRelPaths; this guards
  // the css/inventory set, which is computed after the remote fetch and cannot be seeded).
  for (const asset of b) {
    if (!byRelPath.has(asset.relPath)) byRelPath.set(asset.relPath, asset);
  }
  return sortAssetFiles([...byRelPath.values()]);
}

function mergeCarriedImgAssets(
  assets: AssetFile[],
  imgAssets: MediaAsset[]
): AssetFile[] {
  if (imgAssets.length === 0) return assets;

  const byRelPath = new Map<string, AssetFile>();
  const carriedSourcePaths = new Set(imgAssets.map((asset) => resolve(asset.srcAbs)));
  for (const asset of assets) {
    if (asset.sourcePath && carriedSourcePaths.has(resolve(asset.sourcePath))) continue;
    byRelPath.set(asset.relPath, asset);
  }
  for (const imgAsset of imgAssets) {
    byRelPath.set(imgAsset.themeRel, {
      relPath: imgAsset.themeRel,
      sourcePath: imgAsset.srcAbs,
    });
  }

  return sortAssetFiles([...byRelPath.values()]);
}

function themeAssetUrl(themeSlug: string, themeRel: string): string {
  return `/wp-content/themes/${themeSlug}/${themeRel.replace(/^\/+/, '')}`;
}

function buildRegionAuditDiagnostics(
  site: { pages: Array<{ slug: string; relPath: string; html: string }> },
  pages: Record<string, SectionBlocks[]>,
  chromeParts: Record<string, string>,
  chromeSlugsByPage: Record<string, { header: string; footer: string }>,
  warnings: string[]
): RegionSelectionReport[] {
  const home = homePage(site);
  if (!home) return [];

  try {
    const census = extractSourceLandmarksFromHtml(home.html);
    const placed: PlacedRegion[] = (pages[home.slug] ?? [])
      .map((section) => section.spec.selector)
      .filter((selector): selector is string => Boolean(selector))
      .map((selector) => ({ kind: 'page_body_section' as const, selector }));
    const chromeSlugs = chromeSlugsByPage[home.slug];
    if (chromeSlugs && chromeParts[`${chromeSlugs.header}.html`]) {
      placed.push({ kind: 'header_part', role: 'header' });
    }
    if (chromeSlugs && chromeParts[`${chromeSlugs.footer}.html`]) {
      placed.push({ kind: 'footer_part', role: 'footer' });
    }

    return [reconcileRegions(census, placed, home.slug, home.relPath)];
  } catch (error) {
    warnings.push(`region audit failed (continuing): ${error instanceof Error ? error.message : String(error)}`);
    return [];
  }
}

function hoistPageStyleBlocks(
  site: { pages: Array<{ slug: string }> },
  pages: Record<string, SectionBlocks[]>,
  warnings: string[]
): Record<string, Record<string, unknown>> | undefined {
  const hoistPages = site.pages
    .map((page) => ({
      slug: page.slug,
      markup: joinHoistSections(pages[page.slug] ?? []),
    }))
    .filter((page) => page.markup);

  if (hoistPages.length === 0) return undefined;

  try {
    const hoisted = hoistVariations(hoistPages);
    if (hoisted.variations.length === 0) return undefined;

    for (const sections of Object.values(pages)) {
      for (const section of sections) {
        section.blocks = applyHoistSwaps(section.blocks, hoisted.variations);
      }
    }

    return styleBlocksFromVariations(hoisted.variations);
  } catch (error) {
    warnings.push(
      `variation hoist failed (continuing un-hoisted): ${
        error instanceof Error ? error.message : String(error)
      }`
    );
    return undefined;
  }
}

function joinHoistSections(sections: SectionBlocks[]): string {
  return sections
    .map((section) => section.blocks.trim())
    .filter(Boolean)
    .join('\n\n');
}

function styleBlocksFromVariations(
  variations: HoistedVariation[]
): Record<string, Record<string, unknown>> {
  return Object.fromEntries(
    variations.map((variation) => [
      `${variation.slug}.json`,
      {
        version: 3,
        slug: variation.slug,
        title: variation.title,
        blockTypes: variation.blockTypes,
        styles: variation.styles,
      },
    ])
  );
}

function prepareSourceCssCarry(
  css: string,
  mediaAssets: MediaAsset[],
  inventoryAssets: AssetFile[]
): { css: string; assets: AssetFile[] } {
  if (mediaAssets.length === 0) {
    return { css: rebaseSourceCssMediaUrls(css, []), assets: inventoryAssets };
  }

  const occupiedByRel = new Map<string, AssetFile>();
  for (const asset of inventoryAssets) {
    occupiedByRel.set(asset.relPath, asset);
  }

  const carriedAssets: AssetFile[] = [];
  const rewrites: Array<{ from: string; to: string }> = [];

  for (const mediaAsset of mediaAssets) {
    const targetRel = availableSourceCssMediaRel(mediaAsset, occupiedByRel);
    rewrites.push({
      from: `media/${basename(mediaAsset.themeRel)}`,
      to: targetRel,
    });

    const existing = occupiedByRel.get(targetRel);
    if (!existing || !sameSourceAsset(existing, mediaAsset.srcAbs)) {
      const asset = { relPath: targetRel, sourcePath: mediaAsset.srcAbs };
      occupiedByRel.set(targetRel, asset);
      carriedAssets.push(asset);
    }
  }

  return {
    css: rebaseSourceCssMediaUrls(css, rewrites),
    assets: sortAssetFiles([...inventoryAssets, ...carriedAssets]),
  };
}

function availableSourceCssMediaRel(
  mediaAsset: MediaAsset,
  occupiedByRel: Map<string, AssetFile>
): string {
  const existing = occupiedByRel.get(mediaAsset.themeRel);
  if (!existing || sameSourceAsset(existing, mediaAsset.srcAbs)) return mediaAsset.themeRel;

  const ext = extname(mediaAsset.themeRel);
  const base = ext ? mediaAsset.themeRel.slice(0, -ext.length) : mediaAsset.themeRel;
  let suffix = 2;
  let candidate = `${base}-${suffix}${ext}`;

  while (
    occupiedByRel.has(candidate) &&
    !sameSourceAsset(occupiedByRel.get(candidate), mediaAsset.srcAbs)
  ) {
    suffix += 1;
    candidate = `${base}-${suffix}${ext}`;
  }

  return candidate;
}

function sameSourceAsset(asset: AssetFile | undefined, sourcePath: string): boolean {
  return Boolean(asset?.sourcePath && resolve(asset.sourcePath) === resolve(sourcePath));
}

function rebaseSourceCssMediaUrls(
  css: string,
  rewrites: Array<{ from: string; to: string }>
): string {
  let out = css;
  for (const rewrite of rewrites) {
    out = out.split(`url(${rewrite.from})`).join(`url(${rewrite.to})`);
  }
  return out;
}

function sortAssetFiles(files: AssetFile[]): AssetFile[] {
  return [...files].sort((a, b) => {
    const rel = a.relPath.localeCompare(b.relPath);
    if (rel !== 0) return rel;
    return (a.sourcePath ?? '').localeCompare(b.sourcePath ?? '');
  });
}

function filterDecorativeAssets(
  inventory: AssetInventory,
  verdicts: AssetVerdicts
): AssetInventory {
  const decoration = new Set(verdicts.decoration);
  if (decoration.size === 0) return inventory;

  return {
    assets: inventory.assets.filter((asset) => !decoration.has(asset.relPath)),
  };
}

function normalizeThemeMeta(srcDir: string, meta: Partial<ThemeMeta> | undefined): ThemeMeta {
  const fallback = srcDir.split(/[\\/]+/).filter(Boolean).at(-1) ?? 'blocks-engine-theme';
  const slug = slugify(meta?.slug ?? fallback);
  return {
    name: meta?.name ?? titleFromSlug(slug),
    slug,
    ...(meta?.author ? { author: meta.author } : {}),
  };
}

function homePage<T extends { slug: string }>(site: { pages: T[] }): T | undefined {
  return site.pages.find((page) => page.slug === 'home') ?? site.pages[0];
}

function titleFromSlug(slug: string): string {
  return slug
    .split('-')
    .filter(Boolean)
    .map((part) => `${part.charAt(0).toUpperCase()}${part.slice(1)}`)
    .join(' ') || 'Blocks Engine Theme';
}

function slugify(value: string): string {
  return (
    value
      .toLowerCase()
      .replace(/[^a-z0-9-]+/g, '-')
      .replace(/-{2,}/g, '-')
      .replace(/^-+|-+$/g, '') || 'blocks-engine-theme'
  );
}
