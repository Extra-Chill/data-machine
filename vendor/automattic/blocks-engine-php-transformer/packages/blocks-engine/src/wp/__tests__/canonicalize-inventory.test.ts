import { describe, expect, it, vi } from 'vitest';

import { createWorker } from '../../pool/pool';
import { HTML_FINDING_CHAR_CAP } from '../../report/limits';
import { FALLBACK_INVENTORY_CAP } from '../../report/schema';
import { canonicalize } from '../canonicalize';

function htmlBlock(html: string): string {
  return `<!-- wp:html -->${html}<!-- /wp:html -->`;
}

async function withWorkerTestMode<T>(fn: () => Promise<T>): Promise<T> {
  const previousMode = process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
  process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = '1';

  try {
    return await fn();
  } finally {
    if (previousMode === undefined) {
      delete process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
    } else {
      process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = previousMode;
    }
  }
}

async function withThrowingWordPressParse<T>(
  errorMessage: string,
  fn: (mockedCanonicalize: typeof canonicalize) => T | Promise<T>,
): Promise<T> {
  vi.resetModules();
  vi.doMock('../bootstrap.js', () => ({
    bootstrap: () => undefined,
  }));
  vi.doMock('../require-wp.js', () => ({
    requireWp: (name: string) => {
      if (name === '@wordpress/blocks') {
        return {
          parse: () => {
            throw new Error(errorMessage);
          },
          serialize: () => '',
          createBlock: () => ({ name: 'core/paragraph', attributes: {} }),
          getBlockAttributes: () => ({}),
        };
      }
      if (name === '@wordpress/block-serialization-default-parser') {
        return { parse: () => [] };
      }
      throw new Error(`Unexpected mocked WordPress module: ${name}`);
    },
  }));

  try {
    const module = await import('../canonicalize');
    return await fn(module.canonicalize);
  } finally {
    vi.doUnmock('../bootstrap.js');
    vi.doUnmock('../require-wp.js');
    vi.resetModules();
  }
}

describe('canonicalize inventory metadata', () => {
  it('walks grammar-level parser blocks and records raw core/html islands', () => {
    const firstIsland = '<section data-fallback="1"><span>Raw</span></section>';
    const secondIsland = '<script type="application/json">{"x":1}</script>';
    const markup = [
      '<!-- wp:group -->',
      '<div class="wp-block-group">',
      '<!-- wp:paragraph -->',
      '<p>Intro</p>',
      '<!-- /wp:paragraph -->',
      htmlBlock(firstIsland),
      '<!-- wp:group -->',
      '<div class="wp-block-group">',
      htmlBlock(secondIsland),
      '</div>',
      '<!-- /wp:group -->',
      '</div>',
      '<!-- /wp:group -->',
    ].join('');

    expect(canonicalize(markup)).toMatchObject({
      blockCount: 5,
      htmlIslands: [
        { index: 0, html: `\n${firstIsland}\n` },
        { index: 1, html: `\n${secondIsland}\n` },
      ],
      htmlIslandCount: 2,
      degraded: false,
    });
  });

  it('caps htmlIslands while preserving the true htmlIslandCount', () => {
    const total = FALLBACK_INVENTORY_CAP + 2;
    const markup = Array.from({ length: total }, (_value, index) =>
      htmlBlock(`<div>Fallback ${index}</div>`),
    ).join('');

    const result = canonicalize(markup);

    expect(result.blockCount).toBe(total);
    expect(result.htmlIslandCount).toBe(total);
    expect(result.htmlIslands).toHaveLength(FALLBACK_INVENTORY_CAP);
    expect(result.htmlIslands[0]).toEqual({ index: 0, html: '\n<div>Fallback 0</div>\n' });
    expect(result.htmlIslands.at(-1)).toEqual({
      index: FALLBACK_INVENTORY_CAP - 1,
      html: `\n<div>Fallback ${FALLBACK_INVENTORY_CAP - 1}</div>\n`,
    });
    expect(result.htmlIslandOccurrences).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ html: '\n<div>Fallback 0</div>\n', count: 1 }),
      ]),
    );
    expect(result).toMatchObject({ htmlIslandDistinctCount: total, htmlIslandOccurrencesTruncated: true });
    expect(result.degraded).toBe(false);
  });

  it('retains exact counts for repeated islands beyond the evidence cap', () => {
    const total = FALLBACK_INVENTORY_CAP + 25;
    const result = canonicalize(Array.from({ length: total }, () => htmlBlock('<div>Shared fallback</div>')).join(''));

    expect(result.htmlIslands).toHaveLength(FALLBACK_INVENTORY_CAP);
    expect(result.htmlIslandOccurrences).toEqual([expect.objectContaining({ html: '\n<div>Shared fallback</div>\n', count: total })]);
    expect(result).toMatchObject({ htmlIslandDistinctCount: 1, htmlIslandOccurrencesTruncated: false });
  });

  it('keeps capped-prefix variants structurally distinct without retaining their tails', () => {
    const prefix = `<div>${'x'.repeat(HTML_FINDING_CHAR_CAP)}`;
    const result = canonicalize(`${htmlBlock(`${prefix}first-tail</div>`)}${htmlBlock(`${prefix}second-tail</div>`)}`);

    expect(result.htmlIslandOccurrences).toHaveLength(2);
    expect(result.htmlIslandOccurrences.map((occurrence) => occurrence.html)).toEqual([
      result.htmlIslandOccurrences[0]?.html,
      result.htmlIslandOccurrences[0]?.html,
    ]);
    expect(result.htmlIslandOccurrences[0]?.html).toHaveLength(HTML_FINDING_CHAR_CAP);
    expect(result.htmlIslandOccurrences[0]?.html).not.toContain('first-tail');
    expect(result.htmlIslandOccurrences[0]?.html).not.toContain('second-tail');
    expect(new Set(result.htmlIslandOccurrences.map((occurrence) => occurrence.fingerprint)).size).toBe(2);
    expect(result).toMatchObject({ htmlIslandDistinctCount: 2, htmlIslandOccurrencesTruncated: false });
  });

  it('truncates each html island before returning inventory over IPC', () => {
    const tailMarker = 'SHOULD_NOT_CROSS_IPC_BOUND';
    const longIsland = `<div>${'x'.repeat(HTML_FINDING_CHAR_CAP + 100)}${tailMarker}</div>`;
    const markup = htmlBlock(longIsland);

    const result = canonicalize(markup);

    expect(result.htmlIslandCount).toBe(1);
    expect(result.htmlIslands).toHaveLength(1);
    expect(result.htmlIslands[0].index).toBe(0);
    expect(result.htmlIslands[0].html).toHaveLength(HTML_FINDING_CHAR_CAP);
    expect(result.htmlIslands[0].html).not.toContain(tailMarker);
    expect(result.degraded).toBe(false);
  });

  it('returns degraded safe defaults when the pool emits a canonicalize sentinel', async () =>
    withWorkerTestMode(async () => {
      const input = `<!-- BLOCKS_ENGINE_TEST_HANG -->${htmlBlock('<div>never parsed</div>')}`;
      const pool = createWorker({
        size: 1,
        maxReroutes: 0,
        itemTimeoutMs: 50,
      });

      try {
        await expect(pool.canonicalize([input])).resolves.toEqual([
          {
            html: input,
            changed: false,
            fixedIssues: [],
            blockCount: 0,
            htmlIslands: [],
            htmlIslandCount: 0,
            htmlIslandOccurrences: [],
            htmlIslandDistinctCount: 0,
            htmlIslandOccurrencesTruncated: false,
            degraded: true,
          },
        ]);
      } finally {
        await pool.stop();
      }
    }), 20_000);

  it('returns degraded safe defaults and records the caught parser error', async () => {
    const errorMessage = 'forced canonical parser failure';
    const input = htmlBlock('<div>preserve this markup</div>');

    await withThrowingWordPressParse(errorMessage, (mockedCanonicalize) => {
      const result = mockedCanonicalize(input);

      expect(result).toMatchObject({
        html: input,
        changed: false,
        blockCount: 0,
        htmlIslands: [],
        htmlIslandCount: 0,
        degraded: true,
      });
      expect(result.fixedIssues).toHaveLength(1);
      expect(result.fixedIssues[0]).toContain(errorMessage);
    });
  });
});
