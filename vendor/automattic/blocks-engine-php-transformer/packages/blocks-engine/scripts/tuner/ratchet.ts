/**
 * Regression ratchet — "nothing degrades in silence."
 *
 * Per engine/model, a committed baseline keeps a best-ever score per fixture. A
 * run where a fixture drops below its baseline by more than the threshold trips a
 * non-zero exit. Tier A (deterministic reconstruct) uses threshold 0.
 *
 * Finding 1 (plan-deep-review): a baseline file that exists but won't parse is an
 * ERROR (`RatchetError` → exit 2), never silently treated as "no baseline" — that
 * would let a real regression pass green, the exact failure this gate prevents.
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

export class RatchetError extends Error {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, options);
    this.name = 'RatchetError';
  }
}

export interface Baseline {
  engine: string;
  model: string;
  suiteHash: string;
  updatedAt: string;
  fixtures: Record<string, number>;
}

export interface ScoredFixture {
  label: string;
  score: number;
}

export interface Regression {
  label: string;
  baseline: number;
  current: number;
  drop: number;
}

function baselineKey(engine: string, model: string): string {
  const safe = (value: string) => value.replace(/[^a-z0-9._-]+/gi, '-');
  return `${safe(engine)}__${safe(model)}`;
}

function baselinePath(dir: string, engine: string, model: string): string {
  return path.join(dir, `${baselineKey(engine, model)}.json`);
}

/** Absent file → undefined (no baseline yet). Present-but-unparseable → RatchetError. */
export function readBaseline(dir: string, engine: string, model: string): Baseline | undefined {
  const file = baselinePath(dir, engine, model);
  if (!existsSync(file)) return undefined;
  let raw: string;
  try {
    raw = readFileSync(file, 'utf8');
  } catch (error) {
    throw new RatchetError(`baseline unreadable: ${file}`, { cause: error });
  }
  try {
    return JSON.parse(raw) as Baseline;
  } catch (error) {
    throw new RatchetError(`baseline corrupt (won't parse): ${file}`, { cause: error });
  }
}

/** Fixtures that dropped more than `threshold` below their baseline. */
export function detectRegressions(
  results: ScoredFixture[],
  baseline: Baseline | undefined,
  threshold: number,
): Regression[] {
  if (!baseline) return [];
  const regressions: Regression[] = [];
  for (const result of results) {
    const best = baseline.fixtures[result.label];
    if (best === undefined) continue;
    const drop = best - result.score;
    if (drop > threshold) {
      regressions.push({ label: result.label, baseline: best, current: result.score, drop });
    }
  }
  return regressions.sort((a, b) => b.drop - a.drop);
}

/** Write a baseline that ratchets up: best-ever per fixture (max of current and prior). */
export function updateBaseline(
  dir: string,
  results: ScoredFixture[],
  engine: string,
  model: string,
  suiteHash: string,
  now: string,
): Baseline {
  const prior = readBaseline(dir, engine, model);
  const fixtures: Record<string, number> = { ...(prior?.fixtures ?? {}) };
  for (const result of results) {
    fixtures[result.label] = Math.max(fixtures[result.label] ?? -Infinity, result.score);
  }
  const baseline: Baseline = {
    engine,
    model,
    suiteHash,
    updatedAt: now,
    fixtures: Object.fromEntries(Object.entries(fixtures).sort(([a], [b]) => a.localeCompare(b))),
  };
  mkdirSync(dir, { recursive: true });
  writeFileSync(baselinePath(dir, engine, model), `${JSON.stringify(baseline, null, 2)}\n`, 'utf8');
  return baseline;
}
