import { join } from 'node:path';

import { describe, expect, expectTypeOf, it } from 'vitest';

import {
  buildChromePart,
  chrome,
  ingest,
  type ChromeResult,
  type ChromeSlugs,
  type SiteModel,
  type StageCtx,
} from '../theme/index.js';
import type { RawConvertResult, WorkerPool } from '../pool/types.js';

const fixtureRoot = join(import.meta.dirname, 'fixtures/site');

type CapturingPool = WorkerPool & {
  rawInputs: string[][];
  canonicalInputs: string[][];
  stopCalls: number;
};

const sharedHeaderHtml = [
  '<header>',
  '      <nav aria-label="Primary">',
  '        <a href="./index.html">Home</a>',
  '        <a href="./about.html">About</a>',
  '      </nav>',
  '    </header>',
].join('\n');

const sharedFooterHtml = [
  '<footer>',
  '      <p>Blocks Engine</p>',
  '    </footer>',
].join('\n');

const servicesHeaderHtml = [
  '<header>',
  '      <nav aria-label="Primary">',
  '        <a href="./index.html">Home</a>',
  '        <a href="./about.html">About</a>',
  '        <a href="./services.html">Services</a>',
  '      </nav>',
  '    </header>',
].join('\n');

const servicesFooterHtml = [
  '<footer>',
  '      <p>Blocks Engine Services</p>',
  '    </footer>',
].join('\n');

function coverageIsland(html: string): string {
  return [
    '<!-- wp:html {"metadata":{"name":"lib-coverage-island"}} -->',
    html.trim(),
    '<!-- /wp:html -->',
  ].join('\n');
}

function stageCtx(site: SiteModel, warnings: string[] = []): StageCtx {
  return {
    srcDir: site.root,
    site,
    themeMeta: {
      name: 'Fixture Theme',
      slug: 'fixture-theme',
    },
    warn(message) {
      warnings.push(message);
    },
  };
}

function fakePool(rawForHtml: (html: string) => RawConvertResult): CapturingPool {
  return {
    rawInputs: [],
    canonicalInputs: [],
    stopCalls: 0,
    async rawConvert(items) {
      this.rawInputs.push(items);
      return items.map(rawForHtml);
    },
    async canonicalize(items) {
      this.canonicalInputs.push(items);
      return items.map((html) => ({
        html,
        changed: false,
        fixedIssues: [],
        blockCount: 0,
        htmlIslands: [],
        htmlIslandCount: 0,
        degraded: false,
      }));
    },
    async stop() {
      this.stopCalls += 1;
    },
  };
}

function fallbackPool(): CapturingPool {
  return fakePool(() => ({ html: null, wpHtmlResidue: 0 }));
}

function fixtureChromeCtx(warnings: string[] = []): StageCtx {
  return stageCtx(ingest(fixtureRoot), warnings);
}

function expectMainWithoutChrome(
  mainHtml: string,
  bodyText: string,
  headerHtml: string,
  footerHtml: string
): void {
  expect(mainHtml).toContain(bodyText);
  expect(mainHtml).not.toContain(headerHtml);
  expect(mainHtml).not.toContain(footerHtml);
  expect(mainHtml).not.toContain('<header>');
  expect(mainHtml).not.toContain('<footer>');
}

describe('chrome parts contract', () => {
  it('freezes the public signatures exported from the theme entrypoint', () => {
    expect(typeof buildChromePart).toBe('function');
    expect(typeof chrome).toBe('function');

    expectTypeOf(buildChromePart).toEqualTypeOf<
      (html: string, ctx: StageCtx, pool: WorkerPool) => Promise<string>
    >();
    expectTypeOf(chrome).toEqualTypeOf<
      (ctx: StageCtx, pool: WorkerPool) => Promise<ChromeResult>
    >();
    expectTypeOf<ChromeResult>().toEqualTypeOf<{
      parts: Record<string, string>;
      slugsByPage: Record<string, ChromeSlugs>;
      mainHtmlByPage: Record<string, string>;
      warnings: string[];
    }>();
  });

  it('returns native raw convert output without adding chrome wrappers', async () => {
    const html = '<header><nav>Primary</nav></header>';
    const nativePart = [
      '<!-- wp:navigation -->',
      '<nav class="wp-block-navigation">Primary</nav>',
      '<!-- /wp:navigation -->',
    ].join('\n');
    const pool = fakePool(() => ({ html: nativePart, wpHtmlResidue: 0 }));

    const out = await buildChromePart(html, stageCtx({ root: fixtureRoot, pages: [] }), pool);

    expect(out).toBe(nativePart);
    expect(out).not.toContain('<header>');
    expect(pool.rawInputs).toEqual([[html]]);
    expect(pool.canonicalInputs).toEqual([[nativePart]]);
    expect(pool.stopCalls).toBe(0);
  });

  it('returns fallback core/html lib coverage island output when raw convert leaves residue', async () => {
    const html = '<div class="site-chrome"><span>Primary chrome</span></div>';
    const expected = coverageIsland(html);
    const pool = fakePool(() => ({
      html: '<!-- wp:html -->raw residue<!-- /wp:html -->',
      wpHtmlResidue: 1,
    }));

    const out = await buildChromePart(html, stageCtx({ root: fixtureRoot, pages: [] }), pool);

    expect(out).toBe(expected);
    expect(pool.rawInputs).toEqual([[html]]);
    expect(pool.canonicalInputs).toEqual([[expected]]);
    expect(pool.stopCalls).toBe(0);
  });

  it('passes empty chrome input through convert without stage special casing', async () => {
    const expected = coverageIsland('');
    const pool = fallbackPool();

    const out = await buildChromePart('', stageCtx({ root: fixtureRoot, pages: [] }), pool);

    expect(out).toBe(expected);
    expect(pool.rawInputs).toEqual([['']]);
    expect(pool.canonicalInputs).toEqual([[expected]]);
    expect(pool.stopCalls).toBe(0);
  });

  it('builds deterministic fixture chrome parts, variant slugs, and page main HTML', async () => {
    const warnings: string[] = [];
    const first = await chrome(fixtureChromeCtx(warnings), fallbackPool());
    const second = await chrome(fixtureChromeCtx(), fallbackPool());

    expect(first).toEqual(second);
    expect(first.warnings).toEqual([]);
    expect(warnings).toEqual([]);
    expect(Object.keys(first.parts).sort()).toEqual([
      'footer-2.html',
      'footer.html',
      'header-2.html',
      'header.html',
    ]);
    expect(first.parts).toEqual({
      'header.html': coverageIsland(sharedHeaderHtml),
      'footer.html': coverageIsland(sharedFooterHtml),
      'header-2.html': coverageIsland(servicesHeaderHtml),
      'footer-2.html': coverageIsland(servicesFooterHtml),
    });
    expect(first.slugsByPage).toEqual({
      about: { header: 'header', footer: 'footer' },
      home: { header: 'header', footer: 'footer' },
      services: { header: 'header-2', footer: 'footer-2' },
    });

    expectMainWithoutChrome(
      first.mainHtmlByPage.home,
      'Build calmer block themes',
      sharedHeaderHtml,
      sharedFooterHtml
    );
    expectMainWithoutChrome(
      first.mainHtmlByPage.about,
      'About the assembler',
      sharedHeaderHtml,
      sharedFooterHtml
    );
    expectMainWithoutChrome(
      first.mainHtmlByPage.services,
      'Service design for block themes',
      servicesHeaderHtml,
      servicesFooterHtml
    );
  });

  it('skips empty canonical chrome sources with warnings', async () => {
    const warnings: string[] = [];
    const html =
      '<!doctype html><html><head><title>Plain</title></head><body><main><p>No chrome</p></main></body></html>';
    const pool = fallbackPool();

    const out = await chrome(
      stageCtx(
        {
          root: fixtureRoot,
          pages: [{ relPath: 'plain.html', slug: 'plain', html, title: 'Plain' }],
        },
        warnings
      ),
      pool
    );

    expect(out).toEqual({
      parts: {},
      slugsByPage: {
        plain: { header: 'header', footer: 'footer' },
      },
      mainHtmlByPage: {
        plain: html,
      },
      warnings: [
        'skipped empty header chrome source for header.html',
        'skipped empty footer chrome source for footer.html',
      ],
    });
    expect(warnings).toEqual(out.warnings);
    expect(pool.rawInputs).toEqual([]);
    expect(pool.canonicalInputs).toEqual([]);
    expect(pool.stopCalls).toBe(0);
  });
});
