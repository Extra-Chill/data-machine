import { describe, expect, it } from 'vitest';
import * as theme from '../index.js';

describe('theme barrel exports the harness entry', () => {
  it('exports reconstructNativeAggregate', () => {
    expect(typeof theme.reconstructNativeAggregate).toBe('function');
  });

  it('reconstructNativeAggregate returns an aggregate shape for empty specs', () => {
    const agg = theme.reconstructNativeAggregate([]);
    expect(agg.sectionMarkup).toEqual([]);
    expect(agg.sections).toEqual([]);
    expect(agg.heroIsCover).toBe(false);
  });
});
