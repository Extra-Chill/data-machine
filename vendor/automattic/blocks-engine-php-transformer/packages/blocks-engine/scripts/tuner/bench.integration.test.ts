import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { describe, expect, test } from 'vitest';
import { loadFixtures } from './corpus.js';
import { deriveAll } from './derive.js';
import { writeSpec } from './specs.js';
import { updateBaseline } from './ratchet.js';
import { BenchError, runBench } from './bench.js';
import { reconstructEngine } from './engines/reconstruct.js';

function tmp(): string {
  return mkdtempSync(path.join(tmpdir(), 'bench-'));
}

describe('corpus', () => {
  test('loads fixtures from ≥2 producers (attribution needs cross-producer signal)', () => {
    const fixtures = loadFixtures();
    expect(fixtures.length).toBeGreaterThan(0);
    expect(new Set(fixtures.map((f) => f.producer)).size).toBeGreaterThanOrEqual(2);
  });
});

describe('runBench end-to-end', () => {
  test('derive then bench: every derived fixture scores 100, no regressions vs no baseline', () => {
    const specsDir = tmp();
    deriveAll(specsDir);
    const run = runBench({ specsDir, baselinesDir: tmp() });
    expect(run.results.length).toBeGreaterThan(0);
    expect(run.results.every((r) => r.score === 100)).toBe(true);
    expect(run.regressions).toEqual([]);
  });

  test('suiteHash is stable across runs on the same suite', () => {
    const specsDir = tmp();
    deriveAll(specsDir);
    const a = runBench({ specsDir, baselinesDir: tmp() });
    const b = runBench({ specsDir, baselinesDir: tmp() });
    expect(a.suiteHash).toBe(b.suiteHash);
  });

  test('a missing spec throws BenchError (exit 2 — never a silent green)', () => {
    expect(() => runBench({ specsDir: tmp(), baselinesDir: tmp() })).toThrow(BenchError);
  });

  test('the ratchet catches a planted regression below baseline', () => {
    const specsDir = tmp();
    deriveAll(specsDir);
    const target = loadFixtures()[0];
    // Plant an ideal spec demanding a block the output cannot produce → score drops.
    writeSpec(specsDir, target.id, { source: 'ideal', tree: [{ block: 'core/does-not-exist-xyz' }] });
    const baselinesDir = tmp();
    updateBaseline(
      baselinesDir,
      [{ label: target.id, score: 100 }],
      reconstructEngine.label,
      reconstructEngine.model,
      'sha256:seed',
      '2026-01-01T00:00:00Z',
    );
    const run = runBench({ specsDir, baselinesDir, threshold: 0 });
    expect(run.regressions.some((r) => r.label === target.id)).toBe(true);
  });
});
