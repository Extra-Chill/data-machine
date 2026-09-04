import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

import { FALLBACK_INVENTORY_CAP } from '../schema';
import { buildReport } from '../findings';
import type { FixResult } from '../../pool/types';

function fixResult(overrides: Partial<FixResult> = {}): FixResult {
  return {
    html: '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
    changed: false,
    fixedIssues: [],
    blockCount: 1,
    htmlIslands: [],
    htmlIslandCount: 0,
    degraded: false,
    ...overrides,
  };
}

describe('buildReport', () => {
  it('emits a success report with byte metrics and normalized markup diagnostics', () => {
    const inputHtml = '<p>Hi 👋</p>';
    const blockMarkup = '<!-- wp:paragraph --><p>Hi 👋</p><!-- /wp:paragraph -->';

    const report = buildReport({
      inputHtml,
      blockMarkup,
      fixResult: fixResult({
        html: blockMarkup,
        fixedIssues: ['core/paragraph: attribute order normalized'],
        blockCount: 1,
      }),
      transformDurationMs: 42,
    });

    expect(report.status).toBe('success');
    expect(report.blockMarkup).toBe(blockMarkup);
    expect(report.fallbacks).toEqual([]);
    expect(report.diagnostics).toEqual([
      {
        code: 'normalized_markup',
        severity: 'info',
        message: 'core/paragraph: attribute order normalized',
      },
    ]);
    expect(report.metrics).toEqual({
      inputBytes: Buffer.byteLength(inputHtml, 'utf8'),
      outputBytes: Buffer.byteLength(blockMarkup, 'utf8'),
      blockCount: 1,
      fallbackCount: 0,
      diagnosticCount: 1,
      transformDurationMs: 42,
    });
  });

  it('records sanitized capped html island fallbacks, truncation, and degradation warnings', () => {
    const longUnsafeIsland = `<section onclick="bad()">Keep</section><!-- wp:paragraph --><script>alert(1)</script>${'🙂'.repeat(
      2_100,
    )}`;
    const htmlIslands = [
      { index: 7, html: longUnsafeIsland },
      ...Array.from({ length: FALLBACK_INVENTORY_CAP }, (_, i) => ({
        index: i + 8,
        html: `<div>Island ${i}</div>`,
      })),
    ];

    const report = buildReport({
      inputHtml: '<main>source</main>',
      blockMarkup: '<!-- wp:html -->fallback<!-- /wp:html -->',
      fixResult: fixResult({
        html: '<!-- wp:html -->fallback<!-- /wp:html -->',
        fixedIssues: ['core/html: preserved fallback island'],
        blockCount: 1,
        htmlIslands,
        htmlIslandCount: FALLBACK_INVENTORY_CAP + 1,
        degraded: true,
      }),
      transformDurationMs: 7,
    });

    expect(report.status).toBe('success_with_warnings');
    expect(report.fallbacks).toHaveLength(FALLBACK_INVENTORY_CAP);
    expect(report.fallbacks[0]).toMatchObject({
      code: 'unconverted_html',
      severity: 'warning',
      selector: 'core/html[7]',
    });
    expect(Array.from(report.fallbacks[0]?.snippet ?? '')).toHaveLength(2_000);
    expect(report.fallbacks[0]?.snippet).not.toContain('\uFFFD');
    expect(report.fallbacks[0]?.snippet).not.toMatch(/[\uD800-\uDFFF]$/u);
    expect(report.fallbacks[0]?.snippet).toContain('<section>Keep</section>');
    expect(report.fallbacks[0]?.snippet).not.toContain('onclick');
    expect(report.fallbacks[0]?.snippet).not.toContain('<script');
    expect(report.fallbacks[0]?.snippet).not.toContain('<!-- wp:paragraph -->');
    expect(report.diagnostics).toEqual([
      {
        code: 'normalized_markup',
        severity: 'info',
        message: 'core/html: preserved fallback island',
      },
      {
        code: 'conversion_degraded',
        severity: 'warning',
        message: 'Conversion completed with degraded worker results.',
      },
      {
        code: 'fallback_inventory_truncated',
        severity: 'info',
        message: 'Fallback inventory truncated.',
        total: FALLBACK_INVENTORY_CAP + 1,
        kept: FALLBACK_INVENTORY_CAP,
      },
    ]);
    expect(report.metrics.fallbackCount).toBe(FALLBACK_INVENTORY_CAP + 1);
    expect(report.metrics.diagnosticCount).toBe(3);
  });

  it.each([
    {
      name: 'empty output bytes',
      blockMarkup: '',
      blockCount: 1,
    },
    {
      name: 'zero parsed blocks',
      blockMarkup: '<!-- wp:paragraph --><p>Lost</p><!-- /wp:paragraph -->',
      blockCount: 0,
    },
  ])('emits a content_dropped warning for real input with $name', ({ blockMarkup, blockCount }) => {
    const report = buildReport({
      inputHtml: '<main><p>Lost content</p></main>',
      blockMarkup,
      fixResult: fixResult({ html: blockMarkup, blockCount }),
      transformDurationMs: 3,
    });

    expect(report.status).toBe('success_with_warnings');
    expect(report.diagnostics).toEqual([
      {
        code: 'content_dropped',
        severity: 'warning',
        message: 'Input HTML contained content, but conversion produced an empty block result.',
      },
    ]);
    expect(report.metrics.diagnosticCount).toBe(1);
  });

  it.each(['', ' \n\t '])(
    'does not emit content_dropped for empty or whitespace-only input %#',
    (inputHtml) => {
      const report = buildReport({
        inputHtml,
        blockMarkup: '',
        fixResult: fixResult({ html: '', blockCount: 0 }),
        transformDurationMs: 3,
      });

      expect(report.status).toBe('success');
      expect(report.diagnostics).toEqual([]);
      expect(report.metrics.diagnosticCount).toBe(0);
    },
  );

  it('emits reviewer-friendly HTML quality diagnostics for generated Figma exports', () => {
    const inputHtml = `
      <main data-page-path="index.html">
        <section data-figma-node-name="Home Hero">
          <div data-figma-node-name="hero image"></div>
          <div data-figma-node-name="Rectangle"><svg><path fill="#198097" /></svg></div>
        </section>
        <section data-figma-node-name="Frame 1">
          ${Array.from(
            { length: 30 },
            (_, index) => `<div data-figma-node-name="Frame ${index}"><svg></svg></div>`,
          ).join('')}
          ${Array.from(
            { length: 15 },
            (_, index) => `<div data-figma-node-name="Group ${index}"></div>`,
          ).join('')}
          <h6 data-figma-node-name="body">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore.</h6>
          <ol><li><h4>Full service range including</h4></li></ol>
          <a href="index.html" data-figma-link-type="node">Contact Us</a>
        </section>
        <section data-figma-node-name="Featured Posts">
          <article><h2>A Days of thunder lego set is coming</h2></article>
        </section>
        <section data-figma-node-name="Featured Posts">
          <article><h2>A Days of thunder lego set is coming</h2></article>
          <h4>We’ve Tried the LEGO I
deas Twilight Set</h4>
        </section>
      </main>`;

    const report = buildReport({
      inputHtml,
      blockMarkup: '<!-- wp:paragraph --><p>Converted</p><!-- /wp:paragraph -->',
      fixResult: fixResult({ blockCount: 1 }),
      transformDurationMs: 3,
    });

    expect(report.status).toBe('success_with_warnings');
    expect(report.diagnostics).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ code: 'body_text_promoted_to_heading', selector: 'h6' }),
        expect.objectContaining({ code: 'heading_inside_list_item', selector: 'li h4' }),
        expect.objectContaining({
          code: 'scaffold_noise_candidate',
          totalNodes: expect.any(Number),
          scaffoldNodes: expect.any(Number),
          scaffoldRatio: expect.any(Number),
        }),
        expect.objectContaining({ code: 'svg_dense_region', svgCount: 30 }),
        expect.objectContaining({
          code: 'route_self_link_oddity',
          selector: 'a[href="index.html"]',
        }),
        expect.objectContaining({
          code: 'duplicate_canvas_chrome',
          duplicateText: 'a days of thunder lego set is coming',
        }),
      ]),
    );
    expect(report.metrics.diagnosticCount).toBe(6);
  });

  it('does not value-import WordPress packages', () => {
    const reportDir = resolve(dirname(fileURLToPath(import.meta.url)), '..');

    for (const fileName of ['findings.ts', 'contract.ts']) {
      const source = readFileSync(resolve(reportDir, fileName), 'utf8');

      expect(source).not.toMatch(/from\s+['"]@wordpress\//);
      expect(source).not.toMatch(/import\s+['"]@wordpress\//);
    }
  });
});
