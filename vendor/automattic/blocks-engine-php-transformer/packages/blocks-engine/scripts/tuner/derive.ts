/**
 * `bench:derive` — seed structure-only expected specs from current reconstruct
 * output. MANUAL-ONLY: never run inside `bench`, or a regression would be baked
 * into "expected" and mask itself. Deriving is a deliberate seeding act; the
 * ratchet baselines, not derived specs, catch regressions.
 */
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadFixtures } from './corpus.js';
import { reconstructEngine } from './engines/reconstruct.js';
import { parseToTree, parsedToExpected } from './score.js';
import { specPath, writeSpec, type Spec } from './specs.js';
import { existsSync, readFileSync } from 'node:fs';

export function deriveSpec(fixture: ReturnType<typeof loadFixtures>[number]): Spec {
  const { output } = reconstructEngine.realize(fixture.specs);
  return { source: 'derived', tree: parsedToExpected(parseToTree(output)) };
}

/**
 * Derive + write a structure-only spec for every fixture. Returns fixture ids
 * written. By default an existing `source: "ideal"` spec is preserved (re-deriving
 * never clobbers hand-authored ideals); pass `overwriteIdeals` to force.
 */
export function deriveAll(specsDir: string, overwriteIdeals = false): string[] {
  const written: string[] = [];
  for (const fixture of loadFixtures()) {
    if (!overwriteIdeals && isIdeal(specsDir, fixture.id)) continue;
    writeSpec(specsDir, fixture.id, deriveSpec(fixture));
    written.push(fixture.id);
  }
  return written;
}

function isIdeal(specsDir: string, id: string): boolean {
  const file = specPath(specsDir, id);
  if (!existsSync(file)) return false;
  try {
    return (JSON.parse(readFileSync(file, 'utf8')) as Spec).source === 'ideal';
  } catch {
    return false;
  }
}

const invokedDirectly =
  Boolean(process.argv[1]) && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (invokedDirectly) {
  const pkgRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
  const specsDir = path.join(pkgRoot, 'benchmarks', 'specs');
  const written = deriveAll(specsDir, process.argv.includes('--overwrite-ideals'));
  console.log(`bench:derive — wrote ${written.length} derived spec(s) to ${path.relative(pkgRoot, specsDir)} (ideals preserved).`);
}
