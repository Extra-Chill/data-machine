import { performance } from 'node:perf_hooks';

import { describe, expect, it, vi } from 'vitest';

import cases from '../__fixtures__/cases.json' with { type: 'json' };
import type { ConvertOptions } from '../convert';
import { convert, convertReport } from '../index.js';
import type { RawConvertResult, WorkerPool } from '../pool/types';
import { canonicalize } from '../wp/canonicalize';

type Fixture = {
  id: string;
  op: 'rawConvert' | 'canonicalize' | 'compose';
  input: string;
  expected: unknown;
};

const fixtures = cases as Fixture[];

function fixture(id: string): Fixture {
  const found = fixtures.find((candidate) => candidate.id === id);
  if (!found) throw new Error(`Missing fixture ${id}`);
  return found;
}

function poolWithRawResult(raw: RawConvertResult): WorkerPool {
  return {
    rawConvert: vi.fn(async () => [raw]),
    canonicalize: vi.fn(async (items: string[]) => items.map((item) => canonicalize(item))),
    stop: vi.fn(async () => undefined),
  };
}

describe('convertReport', () => {
  it('reports clean conversion as success with no fallbacks', async () => {
    const report = await convertReport('<h2>Title</h2><p>Body</p>', { url: '' });

    expect(report.schema).toBe('blocks-engine/convert-report/v1');
    expect(report.status).toBe('success');
    expect(report.blockMarkup).toContain('<!-- wp:heading -->');
    expect(report.blockMarkup).toContain('<!-- wp:paragraph -->');
    expect(report.blockMarkup).not.toContain('<!-- wp:html');
    expect(report.fallbacks).toEqual([]);
    expect(report.metrics.fallbackCount).toBe(0);
    expect(report.metrics.blockCount).toBeGreaterThan(0);
    expect(report.metrics.transformDurationMs).toBeGreaterThanOrEqual(0);
  });

  it('reports surviving core/html as a warning fallback', async () => {
    const input = fixture('raw.sample-semantic-section').input;

    const report = await convertReport(input, { url: 'https://x.test/' });

    expect(report.status).toBe('success_with_warnings');
    expect(report.blockMarkup).toContain('<!-- wp:html');
    expect(report.blockMarkup).toContain('<section><h2>Our process</h2>');
    expect(report.fallbacks).toHaveLength(1);
    expect(report.fallbacks[0]).toEqual(
      expect.objectContaining({
        code: 'unconverted_html',
        severity: 'warning',
      }),
    );
    expect(report.metrics.fallbackCount).toBe(1);
  });

  it('reports conversion_degraded when rawConvert returns the pool sentinel', async () => {
    const pool = poolWithRawResult({ html: null, wpHtmlResidue: Infinity });

    const report = await convertReport('<h2>Title</h2><p>Body</p>', { url: '' }, { pool });

    expect(report.status).toBe('success_with_warnings');
    expect(report.diagnostics).toContainEqual(
      expect.objectContaining({
        code: 'conversion_degraded',
        severity: 'warning',
      }),
    );
    expect(pool.stop).not.toHaveBeenCalled();
  });

  it('does not report conversion_degraded for finite-residue compose fallback', async () => {
    const input = fixture('raw.sample-semantic-section').input;
    const pool = poolWithRawResult({
      html: '<!-- wp:html -->\n<div>raw residue</div>\n<!-- /wp:html -->',
      wpHtmlResidue: 1,
    });

    const report = await convertReport(input, { url: 'https://x.test/' }, { pool });

    expect(pool.canonicalize).toHaveBeenCalledWith([expect.stringContaining('<!-- wp:html')]);
    expect(report.fallbacks).toHaveLength(1);
    expect(report.diagnostics).not.toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          code: 'conversion_degraded',
        }),
      ]),
    );
  });

  it('starts transform timing after injected pool acquisition', async () => {
    const pool = poolWithRawResult({
      html: '<!-- wp:heading -->\n<h2 class="wp-block-heading">Title</h2>\n<!-- /wp:heading -->',
      wpHtmlResidue: 0,
    });
    const poolGetter = vi.fn(() => pool);
    const opts = Object.defineProperty({}, 'pool', { get: poolGetter }) as ConvertOptions;
    const nowSpy = vi.spyOn(performance, 'now');

    try {
      await convertReport('<h2>Title</h2>', { url: '' }, opts);

      const firstNowCall = nowSpy.mock.invocationCallOrder[0];
      const lastPoolGet = Math.max(...poolGetter.mock.invocationCallOrder);
      expect(firstNowCall).toBeGreaterThan(lastPoolGet);
    } finally {
      nowSpy.mockRestore();
    }
  });

  it('keeps convert as the blockMarkup projection of convertReport', async () => {
    const input = '<h2>Title</h2><p>Body</p>';

    await expect(convert(input, { url: '' })).resolves.toBe(
      (await convertReport(input, { url: '' })).blockMarkup,
    );
  });
});
