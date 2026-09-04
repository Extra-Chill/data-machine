import { createRequire } from 'node:module';

import { afterEach, describe, expect, it, vi } from 'vitest';

import cases from '../__fixtures__/cases.json' with { type: 'json' };
import type { WorkerPool } from '../pool/types';
import { canonicalize } from '../wp/canonicalize';
import { convert as wpConvert } from '../wp';

type Fixture = {
  id: string;
  op: 'rawConvert' | 'canonicalize' | 'compose';
  input: string;
  expected: unknown;
};

const fixtures = cases as Fixture[];
const fixture = (id: string) => {
  const found = fixtures.find((candidate) => candidate.id === id);
  if (!found) throw new Error(`Missing fixture ${id}`);
  return found;
};

const inputHtml = '<h2>Title</h2><p>Body</p>';
const nativeBlocks = [
  '<!-- wp:heading -->',
  '<h2 class="wp-block-heading">Title</h2>',
  '<!-- /wp:heading -->',
  '',
  '<!-- wp:paragraph -->',
  '<p>Body</p>',
  '<!-- /wp:paragraph -->',
].join('\n');

const requireFromHere = createRequire(import.meta.url);
const requireCache = (requireFromHere as typeof requireFromHere & { cache: Record<string, unknown> })
  .cache;
const poolModuleIds = ['../pool/pool.js', '../pool/pool'];

function requireCacheEntriesForWordPressBlocks(): string[] {
  return Object.keys(requireCache).filter((entry) =>
    entry.includes('/node_modules/@wordpress/blocks/'),
  );
}

function clearWordPressBlocksRequireCache(): void {
  for (const entry of requireCacheEntriesForWordPressBlocks()) {
    delete requireCache[entry];
  }
}

function mockPoolFor(html = nativeBlocks): WorkerPool {
  return {
    rawConvert: vi.fn(async () => [{ html, wpHtmlResidue: 0 }]),
    canonicalize: vi.fn(async (items: string[]) =>
      items.map((item) => ({
        html: item,
        changed: false,
        fixedIssues: [],
        blockCount: 2,
        htmlIslands: [],
        htmlIslandCount: 0,
        degraded: false,
      })),
    ),
    stop: vi.fn(async () => undefined),
  };
}

function mockCreateWorker(pool: WorkerPool): ReturnType<typeof vi.fn> {
  const createWorker = vi.fn(() => pool);
  for (const moduleId of poolModuleIds) {
    vi.doMock(moduleId, () => ({ createWorker }));
  }
  return createWorker;
}

afterEach(() => {
  for (const moduleId of poolModuleIds) {
    vi.doUnmock(moduleId);
  }
  vi.resetModules();
});

describe('main entry convert', () => {
  it('converts simple heading and paragraph html to native blocks through the real one-shot pool', async () => {
    const { convert } = await import('../index.js');

    const out = await convert(inputHtml, { url: '' });

    expect(out).toContain('<!-- wp:heading -->');
    expect(out).toContain('<!-- wp:paragraph -->');
    expect(out).not.toContain('<!-- wp:html');
  }, 20_000);

  it('stops a one-shot pool created for the call', async () => {
    const pool = mockPoolFor();
    const createWorker = mockCreateWorker(pool);
    const { convert } = await import('../index.js');

    const out = await convert(inputHtml, { url: '' });

    expect(out).toContain('<!-- wp:heading -->');
    expect(out).toContain('<!-- wp:paragraph -->');
    expect(out).not.toContain('<!-- wp:html');
    expect(createWorker).toHaveBeenCalledTimes(1);
    expect(pool.rawConvert).toHaveBeenCalledWith([inputHtml]);
    expect(pool.canonicalize).toHaveBeenCalledWith([nativeBlocks]);
    expect(pool.stop).toHaveBeenCalledTimes(1);
  });

  it('does not stop an injected pool', async () => {
    const injectedPool = mockPoolFor();
    const createWorker = mockCreateWorker(mockPoolFor());
    const { convert } = await import('../index.js');

    await expect(convert(inputHtml, { url: '' }, { pool: injectedPool })).resolves.toContain(
      '<!-- wp:heading -->',
    );

    expect(createWorker).not.toHaveBeenCalled();
    expect(injectedPool.rawConvert).toHaveBeenCalledWith([inputHtml]);
    expect(injectedPool.stop).not.toHaveBeenCalled();
  });

  it('does not require @wordpress/blocks into the current process', async () => {
    clearWordPressBlocksRequireCache();
    const pool = mockPoolFor();
    mockCreateWorker(pool);
    const { convert } = await import('../index.js');

    expect(requireCacheEntriesForWordPressBlocks()).toEqual([]);

    await convert(inputHtml, { url: '' });

    expect(requireCacheEntriesForWordPressBlocks()).toEqual([]);
  });
});

describe('/wp convert', () => {
  it('composes the original source when rawConvert leaves html residue, then canonicalizes', () => {
    const composeFixture = fixture('compose.sample-callout-div') as Fixture & { expected: string };
    expect(wpConvert(composeFixture.input, { url: 'https://x.test/' })).toBe(
      canonicalize(composeFixture.expected).html,
    );
  });

  it('keeps clean rawConvert output before canonicalizing', () => {
    const rawFixture = fixture('raw.smoke-heading-paragraph-table') as Fixture & {
      expected: { html: string; wpHtmlResidue: number };
    };
    expect(wpConvert(rawFixture.input, { url: 'https://x.test/' })).toBe(
      canonicalize(rawFixture.expected.html).html,
    );
  });
});

describe('default entry', () => {
  it('exports only the runtime public surface and keeps demoted symbols under internals', async () => {
    const defaultEntry = await import('../index.js');
    const internalsEntry = await import('../internals/index.js');

    const publicRuntimeSymbols = [
      'BlocksEngineError',
		'analyzeRuntimeRegionEffects',
      'compose',
      'convert',
      'convertReport',
      'createWorker',
      'lintThemeJson',
      'siteToTheme',
      'writeTheme',
    ].sort();
    const demotedRuntimeSymbols = [
      'PIPELINE_ISLAND_NAME',
      'PIPELINE_ISLAND_OPENER',
      'UNWRAP_SELECTOR',
      'blockMarkupRoundtrips',
      'buildEmbedBlock',
      'composeFromRecipes',
      'escapeHtml',
      'escapeHtmlAttr',
      'escapeHtmlText',
      'genericHtmlToBlocks',
      'guessEmbedProvider',
      'heuristicBlocks',
      'isRawConvertible',
      'sanitize',
      'scanForInjection',
      'serializeBlockAttrs',
      'validateBlockContract',
      'validateBlockMarkup',
      'verifyComposedOutput',
      'walkBlocks',
    ];

    expect(Object.keys(defaultEntry).sort()).toEqual(publicRuntimeSymbols);

    expect(typeof defaultEntry.convert).toBe('function');
    expect(typeof defaultEntry.compose).toBe('function');
    expect(typeof defaultEntry.BlocksEngineError).toBe('function');
		expect(typeof defaultEntry.analyzeRuntimeRegionEffects).toBe('function');
    expect(typeof defaultEntry.createWorker).toBe('function');
    expect(typeof defaultEntry.lintThemeJson).toBe('function');
    expect(typeof defaultEntry.siteToTheme).toBe('function');
    expect(typeof defaultEntry.writeTheme).toBe('function');

    for (const symbol of demotedRuntimeSymbols) {
      expect(defaultEntry).not.toHaveProperty(symbol);
      expect(internalsEntry).toHaveProperty(symbol);
    }
  });
});
