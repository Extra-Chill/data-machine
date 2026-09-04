import type { WorkerPool } from '../pool/types.js';
import type { ChromeSlugs } from './chrome-signature.js';
import { assignChromeVariants } from './chrome-signature.js';
import { splitPageChrome } from './chrome-split.js';
import { buildChromePart } from './chrome-parts.js';
import type { StageCtx } from './types.js';

export interface ChromeResult {
  parts: Record<string, string>;
  slugsByPage: Record<string, ChromeSlugs>;
  mainHtmlByPage: Record<string, string>;
  warnings: string[];
}

export async function chrome(ctx: StageCtx, pool: WorkerPool): Promise<ChromeResult> {
  const pages = ctx.site.pages.map((page) => {
    const split = splitPageChrome(page.html);
    return {
      slug: page.slug,
      headerHtml: split.headerHtml,
      mainHtml: split.mainHtml,
      footerHtml: split.footerHtml,
    };
  });

  const mainHtmlByPage: Record<string, string> = {};
  for (const page of pages) {
    mainHtmlByPage[page.slug] = page.mainHtml;
  }

  const { slugsByPage, canonical } = assignChromeVariants(pages);
  const parts: Record<string, string> = {};
  const warnings: string[] = [];

  const warn = (message: string) => {
    warnings.push(message);
    ctx.warn(message);
  };

  for (const [headerSlug, variant] of Object.entries(canonical)) {
    const footerSlug = footerSlugFromHeaderSlug(headerSlug);

    await buildPartIfPresent({
      html: variant.headerHtml,
      slug: headerSlug,
      kind: 'header',
      ctx,
      pool,
      parts,
      warn,
    });
    await buildPartIfPresent({
      html: variant.footerHtml,
      slug: footerSlug,
      kind: 'footer',
      ctx,
      pool,
      parts,
      warn,
    });
  }

  return {
    parts,
    slugsByPage,
    mainHtmlByPage,
    warnings,
  };
}

function footerSlugFromHeaderSlug(headerSlug: ChromeSlugs['header']): ChromeSlugs['footer'] {
  if (headerSlug === 'header') return 'footer';
  return headerSlug.replace(/^header/, 'footer');
}

async function buildPartIfPresent(args: {
  html: string;
  slug: string;
  kind: 'header' | 'footer';
  ctx: StageCtx;
  pool: WorkerPool;
  parts: Record<string, string>;
  warn(message: string): void;
}): Promise<void> {
  const partKey = `${args.slug}.html`;
  if (args.html.trim() === '') {
    args.warn(`skipped empty ${args.kind} chrome source for ${partKey}`);
    return;
  }

  const part = await buildChromePart(args.html, args.ctx, args.pool);
  args.parts[partKey] = part;
}
