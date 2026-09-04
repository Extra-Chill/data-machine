import { describe, expect, test } from 'vitest';
import { nativeSectionRendererCaseGroups } from '../../../src/__fixtures__/native-section-renderer-cases.js';
import { reconstructEngine } from './reconstruct.js';

const firstSection = nativeSectionRendererCaseGroups().renderReviewGrid[0].section;

describe('reconstructEngine.realize', () => {
  test('produces non-empty, structurally valid block markup', () => {
    const result = reconstructEngine.realize([firstSection]);
    expect(result.output.length).toBeGreaterThan(0);
    expect(result.output).toContain('<!-- wp:');
    expect(result.valid).toBe(true);
  });

  test('exposes the aggregate expected text for the coverage axis', () => {
    const result = reconstructEngine.realize([firstSection]);
    expect(result.expectedText.length).toBeGreaterThan(0);
  });

  test('is byte-stable across runs (the determinism the ratchet relies on)', () => {
    const a = reconstructEngine.realize([firstSection]);
    const b = reconstructEngine.realize([firstSection]);
    expect(a.output).toBe(b.output);
  });
});
