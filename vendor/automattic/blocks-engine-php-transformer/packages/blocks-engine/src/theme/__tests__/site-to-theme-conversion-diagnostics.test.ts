import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import type { FixResult, WorkerPool } from '../../pool/types.js';
import { siteToTheme, type SectionSpec, type SectionStrategy } from '../index.js';

function withTempDir<T>(prefix: string, fn: (dir: string) => Promise<T> | T): Promise<T> {
  const dir = mkdtempSync(join(tmpdir(), prefix));
  return Promise.resolve()
    .then(() => fn(dir))
    .finally(() => {
      rmSync(dir, { recursive: true, force: true });
    });
}

function writePage(root: string, relPath: string, title: string, body: string): void {
  writeFileSync(
    join(root, relPath),
    [
      '<!doctype html>',
      '<html>',
      `<head><title>${title}</title></head>`,
      '<body>',
      '<header><p>Site header</p></header>',
      body,
      '<footer><p>Site footer</p></footer>',
      '</body>',
      '</html>',
    ].join('\n'),
    'utf8'
  );
}

function writeSite(root: string, pages: Record<string, string>): void {
  mkdirSync(root, { recursive: true });
  for (const [relPath, body] of Object.entries(pages)) {
    writePage(root, relPath, relPath.replace(/\.html$/i, ''), body);
  }
}

function sectionSpec(overrides: Partial<SectionSpec> = {}): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 120,
    headings: ['Diagnostics heading'],
    bodyText: ['Diagnostics body copy.'],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 1,
    backgroundColor: '#ffffff',
    gradient: null,
    gradientSource: null,
    motionProfile: {
      motionClass: 'none',
      signals: [],
      animatedElements: 0,
    },
    dividerAbove: null,
    dividerBelow: null,
    layout: {
      containerWidth: 960,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '0',
    },
    sectionHtml: '<section><h1>Diagnostics heading</h1><p>Diagnostics body copy.</p></section>',
    ...overrides,
  };
}

function strategyWithBlocks(blocks: string): SectionStrategy {
  return {
    name: 'test-conversion-diagnostics-strategy',
    render(section) {
      return {
        spec: section,
        blocks,
        coverage: { textCoverage: 1, missingImages: [], lost: false },
        expectedText: section.headings,
        bodyText: section.bodyText,
        expectedAssets: [],
        provenanceFlags: [],
        fallbackDiagnostics: [],
        iconAssets: [],
        decision: blocks.includes('wp:html') ? 'fallback' : 'native',
      };
    },
  };
}

function htmlIslandBlock(body: string): string {
  return [
    '<!-- wp:html -->',
    `<div class="raw-html-fixture">${body}</div>`,
    '<!-- /wp:html -->',
  ].join('\n');
}

function paragraphBlock(body: string): string {
  return [
    '<!-- wp:paragraph -->',
    `<p>${body}</p>`,
    '<!-- /wp:paragraph -->',
  ].join('\n');
}

function fakePool(options: { degradeWhen?: (html: string) => boolean } = {}): WorkerPool {
  return {
    async rawConvert(items: string[]) {
      return items.map((html) => ({ html, wpHtmlResidue: 0 }));
    },
    async canonicalize(items: string[]) {
      return items.map((html) => fixResultFor(html, Boolean(options.degradeWhen?.(html))));
    },
    async stop() {},
  };
}

function fixResultFor(html: string, degraded: boolean): FixResult {
  const htmlIslands = [...html.matchAll(/<!--\s+wp:html(?:\s+[^>]*)?-->([\s\S]*?)<!--\s+\/wp:html\s+-->/g)].map(
    (match, index) => ({
      index,
      html: match[1],
    })
  );

  return {
    html,
    changed: false,
    fixedIssues: [],
    blockCount: [...html.matchAll(/<!--\s+wp:/g)].length,
    htmlIslands,
    htmlIslandCount: htmlIslands.length,
    degraded,
  };
}

describe('siteToTheme conversion diagnostics', () => {
  it('surfaces per-page core/html fallback and degraded conversion reports as diagnostics and warnings', async () => {
    await withTempDir('blocks-engine-theme-conversion-diagnostics-', async (rootDir) => {
      const siteDir = join(rootDir, 'site');
      const outDir = join(rootDir, 'theme');
      writeSite(siteDir, {
        'about.html':
          '<main><section><h1>Diagnostics heading</h1><p>Diagnostics body copy.</p></section></main>',
        'index.html':
          '<main><section><h1>Diagnostics heading</h1><p>Diagnostics body copy.</p></section></main>',
      });

      const result = await siteToTheme(siteDir, {
        outDir,
        pool: fakePool({ degradeWhen: (html) => html.includes('degraded-conversion-marker') }),
        sections: {
          about: [sectionSpec({ sectionIndex: 0 })],
          home: [sectionSpec({ sectionIndex: 0 })],
        },
        renderOptions: {
          about: {
            strategy: strategyWithBlocks(htmlIslandBlock('degraded-conversion-marker')),
          },
          home: {
            strategy: strategyWithBlocks(htmlIslandBlock('core-html-fallback-marker')),
          },
        },
        variationHoist: false,
      });

      expect(result.diagnostics.conversion).toMatchObject({
        pages: [
          {
            slug: 'about',
            status: 'success_with_warnings',
            fallbackCount: 1,
            degraded: true,
          },
          {
            slug: 'home',
            status: 'success_with_warnings',
            fallbackCount: 1,
            degraded: false,
          },
        ],
        totalFallbacks: 2,
        pagesWithFallbacks: 2,
        degradedPages: 1,
        occurrenceCount: 3,
        repairFamilyCount: 3,
      });
      expect(result.warnings).toEqual(
        expect.arrayContaining([
          'page about: 1 section(s) fell back to raw HTML',
          expect.stringMatching(/page about: .*conversion degraded/i),
          'page home: 1 section(s) fell back to raw HTML',
        ])
      );
    });
  });

  it('does not emit conversion warnings for clean all-native pages', async () => {
    await withTempDir('blocks-engine-theme-conversion-clean-', async (rootDir) => {
      const siteDir = join(rootDir, 'site');
      const outDir = join(rootDir, 'theme');
      writeSite(siteDir, {
        'about.html':
          '<main><section><h1>Diagnostics heading</h1><p>Diagnostics body copy.</p></section></main>',
        'index.html':
          '<main><section><h1>Diagnostics heading</h1><p>Diagnostics body copy.</p></section></main>',
      });

      const result = await siteToTheme(siteDir, {
        outDir,
        pool: fakePool(),
        sections: {
          about: [sectionSpec({ sectionIndex: 0 })],
          home: [sectionSpec({ sectionIndex: 0 })],
        },
        renderOptions: {
          about: {
            strategy: strategyWithBlocks(paragraphBlock('Diagnostics body copy.')),
          },
          home: {
            strategy: strategyWithBlocks(paragraphBlock('Diagnostics body copy.')),
          },
        },
        variationHoist: false,
      });

      expect(result.diagnostics.conversion).toMatchObject({
        pages: [
          {
            slug: 'about',
            status: 'success',
            fallbackCount: 0,
            degraded: false,
          },
          {
            slug: 'home',
            status: 'success',
            fallbackCount: 0,
            degraded: false,
          },
        ],
        totalFallbacks: 0,
        pagesWithFallbacks: 0,
        degradedPages: 0,
        occurrenceCount: 0,
        repairFamilyCount: 0,
      });
      expect(result.warnings.filter((warning) => /core\/html|fallback|raw HTML|conversion degraded/i.test(warning))).toEqual([]);
    });
  });
});
