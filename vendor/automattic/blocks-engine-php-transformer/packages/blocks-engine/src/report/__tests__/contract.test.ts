import { describe, expect, it } from 'vitest';

import { assertConvertReport } from '../contract';
import {
  CONVERSION_FINDING_CODES,
  CONVERT_REPORT_SCHEMA,
  type ConversionMetrics,
  type ConvertReport,
} from '../schema';

function validReport(overrides: Partial<ConvertReport> = {}): ConvertReport {
  return {
    schema: CONVERT_REPORT_SCHEMA,
    status: 'success',
    blockMarkup: '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
    fallbacks: [],
    diagnostics: [],
    metrics: {
      inputBytes: 9,
      outputBytes: 57,
      blockCount: 1,
      fallbackCount: 0,
      diagnosticCount: 0,
      transformDurationMs: 4,
    },
    ...overrides,
  };
}

describe('assertConvertReport', () => {
  it('accepts a valid convert report envelope', () => {
    expect(() => assertConvertReport(validReport())).not.toThrow();
  });

  it('throws when a required envelope key is missing', () => {
    const report = validReport() as unknown as Record<string, unknown>;
    delete report.blockMarkup;

    expect(() => assertConvertReport(report)).toThrow(/blockMarkup/);
  });

  it('throws when the schema or status are outside the frozen contract', () => {
    expect(() =>
      assertConvertReport({ ...validReport(), schema: 'blocks-engine/convert-report/v2' }),
    ).toThrow(/schema/);
    expect(() => assertConvertReport({ ...validReport(), status: 'ok' })).toThrow(/status/);
  });

  it('requires fallback and diagnostic inventories to be arrays', () => {
    expect(() => assertConvertReport({ ...validReport(), fallbacks: {} })).toThrow(/fallbacks/);
    expect(() => assertConvertReport({ ...validReport(), diagnostics: {} })).toThrow(
      /diagnostics/,
    );
  });

  it('throws when a finding severity is outside the frozen contract', () => {
    expect(() =>
      assertConvertReport({
        ...validReport(),
        fallbacks: [{ code: 'unconverted_html', severity: 'notice' }],
      }),
    ).toThrow(/severity/);
    expect(() =>
      assertConvertReport({
        ...validReport(),
        diagnostics: [{ code: 'normalized_markup', severity: 'notice' }],
      }),
    ).toThrow(/severity/);
  });

  it('throws when metric fields use the wrong schema', () => {
    expect(() =>
      assertConvertReport({
        ...validReport(),
        metrics: { ...validReport().metrics, fallbackCount: '1' },
      }),
    ).toThrow(/fallbackCount/);
  });

  it('freezes generated HTML quality diagnostic codes in the report contract', () => {
    expect(CONVERSION_FINDING_CODES).toEqual(
      expect.arrayContaining([
        'hero_image_layering_risk',
        'body_text_promoted_to_heading',
        'heading_inside_list_item',
        'scaffold_noise_candidate',
        'svg_dense_region',
        'route_self_link_oddity',
        'duplicate_canvas_chrome',
        'split_word_heading',
      ]),
    );
    expect(() =>
      assertConvertReport({
        ...validReport(),
        diagnostics: [
          {
            code: 'svg_dense_region',
            severity: 'warning',
            message: 'A single semantic region contains many inline SVGs.',
            selector: 'section',
            svgCount: 12,
          },
        ],
      }),
    ).not.toThrow();
  });

  it.each([
    ['inputBytes', -1],
    ['outputBytes', Number.POSITIVE_INFINITY],
    ['blockCount', Number.NaN],
  ] as const)(
    'throws when metrics.%s is negative or non-finite',
    (key: keyof ConversionMetrics, value) => {
      expect(() =>
        assertConvertReport({
          ...validReport(),
          metrics: { ...validReport().metrics, [key]: value },
        }),
      ).toThrow(new RegExp(key));
    },
  );
});
