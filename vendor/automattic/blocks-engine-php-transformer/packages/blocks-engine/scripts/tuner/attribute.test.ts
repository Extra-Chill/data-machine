import { describe, expect, test } from 'vitest';
import { attribute } from './attribute.js';

describe('attribute', () => {
  test('no comparable prior run → no-baseline', () => {
    const a = attribute([{ label: 'p/hero', score: 80 }], undefined);
    expect(a.comparable).toBe(false);
    expect(a.classification).toBe('no-baseline');
  });

  test('all within jitter → flat', () => {
    const a = attribute([{ label: 'p/hero', score: 80 }], { 'p/hero': 80 });
    expect(a.classification).toBe('flat');
  });

  test('one fixture moved → single-cell, overfit suspect', () => {
    const a = attribute([{ label: 'p/hero', score: 90 }, { label: 'p/cta', score: 80 }], { 'p/hero': 80, 'p/cta': 80 });
    expect(a.classification).toBe('single-cell');
    expect(a.overfitSuspect).toBe(true);
  });

  test('≥2 fixtures moved same direction across ≥2 producers → class-move', () => {
    const a = attribute(
      [{ label: 'p1/hero', score: 90 }, { label: 'p2/hero', score: 88 }],
      { 'p1/hero': 80, 'p2/hero': 80 },
    );
    expect(a.classification).toBe('class-move');
    expect(a.overfitSuspect).toBe(false);
  });

  test('multiple fixtures but confined to one producer → single-cell / overfit suspect', () => {
    const a = attribute(
      [{ label: 'p1/hero', score: 90 }, { label: 'p1/cta', score: 88 }],
      { 'p1/hero': 80, 'p1/cta': 80 },
    );
    expect(a.classification).toBe('single-cell');
    expect(a.overfitSuspect).toBe(true);
  });

  test('aggregates deltas by producer', () => {
    const a = attribute(
      [{ label: 'p1/hero', score: 90 }, { label: 'p2/hero', score: 70 }],
      { 'p1/hero': 80, 'p2/hero': 80 },
    );
    const byProducer = Object.fromEntries(a.byProducer.map((p) => [p.producer, p.delta]));
    expect(byProducer.p1).toBe(10);
    expect(byProducer.p2).toBe(-10);
  });
});
