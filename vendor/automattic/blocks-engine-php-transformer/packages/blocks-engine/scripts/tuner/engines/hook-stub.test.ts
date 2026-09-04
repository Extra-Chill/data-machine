import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { describe, expect, test } from 'vitest';
import { nativeSectionRendererCaseGroups } from '../../../src/__fixtures__/native-section-renderer-cases.js';
import { hookStubEngine } from './hook-stub.js';

const section = nativeSectionRendererCaseGroups().renderReviewGrid[0].section;
function tmp(): string {
  return mkdtempSync(path.join(tmpdir(), 'hookstub-'));
}

describe('hookStubEngine (Tier-B seam)', () => {
  test('propose caches the artifact: first call computes, second replays from cache', () => {
    const dir = tmp();
    const first = hookStubEngine.propose([section], dir, '2026-01-01T00:00:00Z');
    const second = hookStubEngine.propose([section], dir, '2026-01-02T00:00:00Z');
    expect(first.fromCache).toBe(false);
    expect(second.fromCache).toBe(true);
    expect(second.raw).toBe(first.raw);
  });

  test('realize over the cached artifact is deterministic and valid', () => {
    const dir = tmp();
    const { raw } = hookStubEngine.propose([section], dir, '2026-01-01T00:00:00Z');
    const out = hookStubEngine.realize(raw);
    expect(out.valid).toBe(true);
    expect(out.output).toBe(raw);
  });
});
