import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { describe, expect, test } from 'vitest';
import { deriveAll } from './derive.js';
import { loadFixtures } from './corpus.js';
import { loadSpec, writeSpec } from './specs.js';

function tmp(): string {
  return mkdtempSync(path.join(tmpdir(), 'derive-'));
}

describe('deriveAll', () => {
  test('writes a derived structure-only spec for every fixture', () => {
    const dir = tmp();
    const written = deriveAll(dir);
    expect(written.length).toBe(loadFixtures().length);
    expect(loadSpec(dir, written[0]).source).toBe('derived');
  });

  test('preserves an existing hand-authored ideal spec (never clobbers it)', () => {
    const dir = tmp();
    const target = loadFixtures()[0].id;
    writeSpec(dir, target, { source: 'ideal', tree: [{ block: 'core/heading', contains: 'KEEP ME' }] });
    deriveAll(dir);
    const spec = loadSpec(dir, target);
    expect(spec.source).toBe('ideal');
    expect(spec.tree[0].contains).toBe('KEEP ME');
  });
});
