import { describe, expect, it, vi } from 'vitest';

import type { FixResult, WorkerPool } from '../../pool/types.js';
import {
  buildThemeConversionDiagnostics,
  deriveThemeConversionDiagnostics,
} from '../conversion-diagnostics';
import type { SectionBlocks } from '../types.js';

function fixResult(overrides: Partial<FixResult> = {}): FixResult {
  return {
    html: '<!-- wp:paragraph --><p>Canonical</p><!-- /wp:paragraph -->',
    changed: false,
    fixedIssues: [],
    blockCount: 1,
    htmlIslands: [],
    htmlIslandCount: 0,
    degraded: false,
    ...overrides,
  };
}

function section(blocks: string): SectionBlocks {
  return {
    spec: {} as SectionBlocks['spec'],
    blocks,
    coverage: 1,
  };
}

function poolReturning(results: FixResult[]): WorkerPool {
  return {
    rawConvert: vi.fn<WorkerPool['rawConvert']>(async (_items) => {
      throw new Error('rawConvert should not be used for conversion diagnostics');
    }),
    canonicalize: vi.fn<WorkerPool['canonicalize']>(async (_items) => results),
    stop: vi.fn<WorkerPool['stop']>(async () => undefined),
  };
}

describe('deriveThemeConversionDiagnostics', () => {
  it('derives per-page and aggregate conversion diagnostics from report findings', async () => {
    const pageOneBlocks = [
      '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->',
      '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
    ].join('\n\n');
    const pool = poolReturning([
      fixResult({
        html: pageOneBlocks,
        blockCount: 2,
      }),
      fixResult({
        html: '<!-- wp:html --><div>Fallback</div><!-- /wp:html -->',
        blockCount: 1,
        htmlIslands: [{ index: 0, html: '<div>Fallback</div>' }],
        htmlIslandCount: 3,
        degraded: true,
      }),
    ]);

    const diagnostics = await deriveThemeConversionDiagnostics(
      {
        clean: [section(` ${pageOneBlocks} `), section('  ')],
        fallback: [section('<!-- wp:html --><div>Fallback</div><!-- /wp:html -->')],
      },
      pool,
    );

    expect(pool.rawConvert).not.toHaveBeenCalled();
    expect(pool.stop).not.toHaveBeenCalled();
    expect(pool.canonicalize).toHaveBeenCalledWith([
      pageOneBlocks,
      '<!-- wp:html --><div>Fallback</div><!-- /wp:html -->',
    ]);
    expect(diagnostics.pages).toMatchObject([
      { slug: 'clean', status: 'success', fallbackCount: 0, degraded: false, findings: [], findingsTruncated: false },
      { slug: 'fallback', status: 'success_with_warnings', fallbackCount: 3, degraded: true, findingsTruncated: false },
    ]);
    expect(diagnostics).toMatchObject({
      occurrenceCount: 3,
      repairFamilyCount: 3,
      totalFallbacks: 3,
      pagesWithFallbacks: 1,
      degradedPages: 1,
    });
  });

  it('builds diagnostics from explicit page inputs without owning the worker pool', async () => {
    const pool = poolReturning([
      fixResult({
        html: '',
        blockCount: 0,
      }),
    ]);

    const result = await buildThemeConversionDiagnostics(
      [
        {
          slug: 'empty-output',
          inputHtml: '<main><p>Source content</p></main>',
          sections: [section('  ')],
        },
      ],
      pool,
    );

    expect(pool.canonicalize).toHaveBeenCalledWith(['']);
    expect(pool.stop).not.toHaveBeenCalled();
    expect(result.pages).toMatchObject([
      { slug: 'empty-output', status: 'success_with_warnings', fallbackCount: 0, degraded: false, findingsTruncated: false },
    ]);
    expect(result).toMatchObject({
      occurrenceCount: 1,
      repairFamilyCount: 1,
      totalFallbacks: 0,
      pagesWithFallbacks: 0,
      degradedPages: 0,
    });
  });

  it('groups 25 equivalent findings deterministically regardless of route order', async () => {
    const pages = Array.from({ length: 25 }, (_, index) => ({
      slug: `route-${String(index + 1).padStart(2, '0')}`,
      inputHtml: '<main><a href="route.html" class="active">Shared navigation</a></main>',
      sections: [section('<!-- wp:html --><div>Fallback</div><!-- /wp:html -->')],
    }));
    const results = pages.map(() =>
      fixResult({
        html: '<!-- wp:html --><div>Fallback</div><!-- /wp:html -->',
        htmlIslands: [{ index: 0, html: '<div>Fallback</div>' }],
        htmlIslandCount: 1,
      }),
    );

    const forward = await buildThemeConversionDiagnostics(pages, poolReturning(results));
    const reverse = await buildThemeConversionDiagnostics([...pages].reverse(), poolReturning(results));

    expect(forward.groups).toEqual(reverse.groups);
    expect(forward).toMatchObject({ occurrenceCount: 25, repairFamilyCount: 1 });
    expect(forward.groups).toEqual([
      expect.objectContaining({
        occurrenceCount: 25,
        affectedSourceCount: 25,
        sourceExamples: ['route-01', 'route-02', 'route-03', 'route-04', 'route-05'],
        sharedShell: true,
        truncated: true,
      }),
    ]);
  });

  it('keeps structurally distinct findings in separate repair families', async () => {
    const pages = ['one', 'two'].map((slug) => ({
      slug,
      sections: [section('<!-- wp:html --><div>Fallback</div><!-- /wp:html -->')],
    }));
    const diagnostics = await buildThemeConversionDiagnostics(
      pages,
      poolReturning([
        fixResult({ htmlIslands: [{ index: 0, html: '<form><input name="email"></form>' }], htmlIslandCount: 1 }),
        fixResult({ htmlIslands: [{ index: 0, html: '<nav><a href="about.html">About</a></nav>' }], htmlIslandCount: 1 }),
      ]),
    );

    expect(diagnostics).toMatchObject({ occurrenceCount: 2, repairFamilyCount: 2 });
  });

  it('counts more than the evidence cap of equivalent fallback islands exactly', async () => {
    const total = 125;
    const result = await buildThemeConversionDiagnostics(
      [{ slug: 'many-fallbacks', sections: [section('<!-- wp:html --><div>Fallback</div><!-- /wp:html -->')] }],
      poolReturning([
        fixResult({
          htmlIslands: Array.from({ length: 100 }, (_value, index) => ({ index, html: '<div>Shared fallback</div>' })),
          htmlIslandCount: total,
          htmlIslandOccurrences: [{ fingerprint: 'shared', html: '<div>Shared fallback</div>', count: total }],
        }),
      ]),
    );

    expect(result).toMatchObject({
      occurrenceCount: total + 1,
      repairFamilyCount: 2,
      repairFamilyCountTruncated: false,
      unrepresentedFallbackOccurrenceCount: 0,
      unrepresentedFallbackDistinctCount: 0,
    });
    expect(result.pages[0]).toMatchObject({ findingsTruncated: true });
    expect(result.pages[0]?.findings).toHaveLength(100);
    expect(result.groups).toContainEqual(expect.objectContaining({ occurrenceCount: total, affectedSourceCount: 1, sourceExamples: ['many-fallbacks'], truncated: false }));
  });

  it('bounds distinct fallback samples while preserving exact aggregate totals', async () => {
    const total = 125;
    const occurrences = Array.from({ length: 100 }, (_value, index) => ({ fingerprint: `fallback-${index}`, html: `<div>Fallback ${index}</div>`, count: 1 }));
    const page = { slug: 'many-distinct-fallbacks', sections: [section('<!-- wp:html --><div>Fallback</div><!-- /wp:html -->')] };
    const makeResult = () => fixResult({
      htmlIslands: occurrences.map(({ html }, index) => ({ index, html })),
      htmlIslandCount: total,
      htmlIslandOccurrences: occurrences,
      htmlIslandDistinctCount: total,
      htmlIslandOccurrencesTruncated: true,
    });
    const forward = await buildThemeConversionDiagnostics([page], poolReturning([makeResult()]));
    const reverse = await buildThemeConversionDiagnostics([page], poolReturning([makeResult()]));

    expect(forward.groups).toEqual(reverse.groups);
    expect(forward).toMatchObject({
      occurrenceCount: total + 1,
      repairFamilyCount: 101,
      repairFamilyCountTruncated: true,
      unrepresentedFallbackOccurrenceCount: 25,
      unrepresentedFallbackDistinctCount: 25,
    });
    expect(forward.groups.filter((group) => group.repairBucket === 'fallback')).toHaveLength(100);
  });

  it('keeps capped-prefix variants as complete distinct repair families', async () => {
    const result = await buildThemeConversionDiagnostics(
      [{ slug: 'long-variants', sections: [section('<!-- wp:html --><div>Fallback</div><!-- /wp:html -->')] }],
      poolReturning([fixResult({
        htmlIslandCount: 2,
        htmlIslandOccurrences: [
          { fingerprint: 'first-full-hash', html: '<div>shared capped prefix</div>', count: 1 },
          { fingerprint: 'second-full-hash', html: '<div>shared capped prefix</div>', count: 1 },
        ],
        htmlIslandDistinctCount: 2,
        htmlIslandOccurrencesTruncated: false,
      })]),
    );

    expect(result).toMatchObject({ repairFamilyCount: 3, repairFamilyCountTruncated: false, unrepresentedFallbackDistinctCount: 0 });
    expect(result.groups.filter((group) => group.repairBucket === 'fallback')).toHaveLength(2);
  });
});
