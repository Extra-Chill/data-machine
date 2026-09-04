import { mkdtempSync, writeFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { describe, expect, test } from 'vitest';
import {
  RatchetError,
  detectRegressions,
  readBaseline,
  updateBaseline,
} from './ratchet.js';

function tmp(): string {
  return mkdtempSync(path.join(tmpdir(), 'ratchet-'));
}

describe('readBaseline', () => {
  test('returns undefined when the baseline file is absent', () => {
    expect(readBaseline(tmp(), 'reconstruct', 'deterministic')).toBeUndefined();
  });

  test('throws RatchetError on a corrupt baseline (never silently "no baseline")', () => {
    const dir = tmp();
    writeFileSync(path.join(dir, 'reconstruct__deterministic.json'), '{ not json', 'utf8');
    expect(() => readBaseline(dir, 'reconstruct', 'deterministic')).toThrow(RatchetError);
  });
});

describe('detectRegressions', () => {
  test('is empty when there is no baseline', () => {
    expect(detectRegressions([{ label: 'a/hero', score: 50 }], undefined, 0)).toEqual([]);
  });

  test('flags a fixture that drops more than the threshold', () => {
    const baseline = { engine: 'e', model: 'm', suiteHash: 's', updatedAt: 't', fixtures: { 'a/hero': 90 } };
    const regs = detectRegressions([{ label: 'a/hero', score: 80 }], baseline, 3);
    expect(regs).toHaveLength(1);
    expect(regs[0]).toMatchObject({ label: 'a/hero', baseline: 90, current: 80, drop: 10 });
  });

  test('threshold 0 trips on any drop but not on equal/improved', () => {
    const baseline = { engine: 'e', model: 'm', suiteHash: 's', updatedAt: 't', fixtures: { x: 90, y: 90 } };
    const regs = detectRegressions([{ label: 'x', score: 89 }, { label: 'y', score: 90 }], baseline, 0);
    expect(regs.map((r) => r.label)).toEqual(['x']);
  });

  test('ignores a drop within the tolerance threshold', () => {
    const baseline = { engine: 'e', model: 'm', suiteHash: 's', updatedAt: 't', fixtures: { x: 90 } };
    expect(detectRegressions([{ label: 'x', score: 88 }], baseline, 3)).toEqual([]);
  });
});

describe('updateBaseline', () => {
  test('ratchets up: keeps the best-ever score per fixture and writes the file', () => {
    const dir = tmp();
    updateBaseline(dir, [{ label: 'x', score: 70 }], 'reconstruct', 'deterministic', 'sha256:aaa', '2026-01-01T00:00:00Z');
    const second = updateBaseline(
      dir,
      [{ label: 'x', score: 60 }, { label: 'y', score: 80 }],
      'reconstruct',
      'deterministic',
      'sha256:bbb',
      '2026-01-02T00:00:00Z',
    );
    expect(second.fixtures.x).toBe(70); // never lowered below the prior ceiling
    expect(second.fixtures.y).toBe(80);
    const onDisk = JSON.parse(readFileSync(path.join(dir, 'reconstruct__deterministic.json'), 'utf8'));
    expect(onDisk.fixtures.x).toBe(70);
  });
});
