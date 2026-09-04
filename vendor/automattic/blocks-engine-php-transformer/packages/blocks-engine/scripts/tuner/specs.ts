/**
 * Expected-tree spec storage (the hybrid model).
 *
 * A spec is the expected block tree for one fixture, plus a `source` tag:
 *   - "derived"  — mechanically generated from current output (scores ~100 by
 *     construction; the ratchet, not the score, catches regressions).
 *   - "ideal"    — hand-authored toward the ideal structure (a <100 score reveals
 *     a real fidelity gap).
 * The scorer treats both identically; `source` is provenance only.
 *
 * A missing or corrupt spec is a loud error (`BenchError` → exit 2), never a
 * silent skip.
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import type { ExpectedNode } from './score.js';

export class BenchError extends Error {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, options);
    this.name = 'BenchError';
  }
}

export interface Spec {
  source: 'derived' | 'ideal';
  tree: ExpectedNode[];
}

export function specPath(dir: string, id: string): string {
  return path.join(dir, id, 'expected.json');
}

export function loadSpec(dir: string, id: string): Spec {
  const file = specPath(dir, id);
  if (!existsSync(file)) {
    throw new BenchError(`no expected spec for fixture "${id}" (looked at ${file}). Run bench:derive.`);
  }
  let parsed: Spec;
  try {
    parsed = JSON.parse(readFileSync(file, 'utf8')) as Spec;
  } catch (error) {
    throw new BenchError(`expected spec corrupt for "${id}": ${file}`, { cause: error });
  }
  if (!parsed || !Array.isArray(parsed.tree)) {
    throw new BenchError(`expected spec malformed for "${id}" (missing tree[]): ${file}`);
  }
  return parsed;
}

export function writeSpec(dir: string, id: string, spec: Spec): void {
  const file = specPath(dir, id);
  mkdirSync(path.dirname(file), { recursive: true });
  writeFileSync(file, `${JSON.stringify(spec, null, 2)}\n`, 'utf8');
}
