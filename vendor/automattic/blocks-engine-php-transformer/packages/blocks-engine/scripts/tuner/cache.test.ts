import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { describe, expect, test } from 'vitest';
import { cacheKey, readCache, writeCache, type CacheKeyParts } from './cache.js';

function parts(over: Partial<CacheKeyParts> = {}): CacheKeyParts {
  return { engineLabel: 'hook', model: 'stub', effort: 'none', inputHtml: '<spec>', promptHash: 'c-1', ...over };
}
function tmp(): string {
  return mkdtempSync(path.join(tmpdir(), 'cache-'));
}

describe('cacheKey', () => {
  test('is stable for identical parts', () => {
    expect(cacheKey(parts())).toBe(cacheKey(parts()));
  });

  test('changes when the prompt hash changes', () => {
    expect(cacheKey(parts())).not.toBe(cacheKey(parts({ promptHash: 'c-2' })));
  });

  test('changes when the input changes', () => {
    expect(cacheKey(parts())).not.toBe(cacheKey(parts({ inputHtml: '<other>' })));
  });
});

describe('read/write cache', () => {
  test('round-trips a written artifact', () => {
    const dir = tmp();
    writeCache(dir, parts(), 'p/hero', '<!-- wp:group --><!-- /wp:group -->', '2026-01-01T00:00:00Z', 1200);
    const entry = readCache(dir, parts());
    expect(entry?.raw).toBe('<!-- wp:group --><!-- /wp:group -->');
    expect(entry?.label).toBe('p/hero');
    expect(entry?.proposeMs).toBe(1200);
  });

  test('a stale key (changed promptHash) misses', () => {
    const dir = tmp();
    writeCache(dir, parts(), 'p/hero', 'raw', '2026-01-01T00:00:00Z');
    expect(readCache(dir, parts({ promptHash: 'c-2' }))).toBeUndefined();
  });

  test('an absent entry returns undefined', () => {
    expect(readCache(tmp(), parts())).toBeUndefined();
  });
});
