import { describe, expect, it } from 'vitest';
import cases from '../__fixtures__/cases.json' with { type: 'json' };

const OPS = ['rawConvert', 'canonicalize', 'compose'] as const;

describe('golden fixtures', () => {
  it('includes at least one populated case for each pinned operation', () => {
    expect(Array.isArray(cases)).toBe(true);

    for (const op of OPS) {
      expect(cases.some((fixture) => fixture.op === op)).toBe(true);
    }

    for (const fixture of cases) {
      expect(typeof fixture.id).toBe('string');
      expect(fixture.id.length).toBeGreaterThan(0);
      expect(OPS).toContain(fixture.op);
      expect(typeof fixture.input).toBe('string');
      expect(fixture.input.length).toBeGreaterThan(0);
      expect(fixture.expected).toBeDefined();
    }
  });
});
