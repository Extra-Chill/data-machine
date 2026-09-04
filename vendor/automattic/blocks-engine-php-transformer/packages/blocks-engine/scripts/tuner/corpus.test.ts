import { describe, expect, test } from 'vitest';
import { loadFixtures } from './corpus.js';
import { reconstructEngine } from './engines/reconstruct.js';

describe('loadFixtures (expanded corpus)', () => {
  const fixtures = loadFixtures();
  const producers = new Set(fixtures.map((f) => f.producer));

  test('draws from many producer families for cross-producer attribution', () => {
    expect(producers.size).toBeGreaterThanOrEqual(6);
  });

  test('covers the key archetypes reconstruct handles', () => {
    // hero/cover, feature row (media-text), FAQ (→details), and whole pages.
    expect(producers).toContain('renderCover');
    expect(producers).toContain('renderMediaText');
    expect(producers).toContain('renderFaq');
    expect(producers).toContain('pageBaseline');
  });

  test('every fixture has a unique id and at least one spec', () => {
    const ids = fixtures.map((f) => f.id);
    expect(new Set(ids).size).toBe(ids.length);
    expect(fixtures.every((f) => f.specs.length >= 1)).toBe(true);
  });

  test('whole-page fixtures carry multiple sections', () => {
    const page = fixtures.find((f) => f.producer === 'pageBaseline');
    expect(page).toBeDefined();
    expect(page!.specs.length).toBeGreaterThanOrEqual(1);
  });

  test('every fixture produces non-empty, structurally valid markup', () => {
    for (const fixture of fixtures) {
      const result = reconstructEngine.realize(fixture.specs);
      expect(result.output.length, `empty output for ${fixture.id}`).toBeGreaterThan(0);
      expect(result.valid, `invalid output for ${fixture.id}`).toBe(true);
    }
  });
});
