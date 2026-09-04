/**
 * The benchmark CLI + `runBench` orchestration core.
 *
 * For each fixture: realize (deterministic reconstruct) → parse → score the
 * produced tree against the expected spec → coverage axis. Then the ratchet
 * (exit 1 on regression / exit 2 on corrupt baseline) and attribution (vs the
 * previous comparable run). Fixtures run serially; `@wordpress` parse is pure so
 * there is nothing to boot. block-runner is NOT a dependency — this is an
 * independent implementation of the same tuner shape.
 *
 * Exit codes: 0 clean · 1 regression · 2 harness/usage error.
 */
import { appendFileSync, existsSync, mkdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { attribute, type Attribution } from './attribute.js';
import { loadFixtures } from './corpus.js';
import { reconstructEngine, type BenchEngine } from './engines/reconstruct.js';
import {
  detectRegressions,
  readBaseline,
  updateBaseline,
  RatchetError,
  type Regression,
} from './ratchet.js';
import { coverage, parseToTree, pct, scoreFromAxes, scoreTree, suiteHash } from './score.js';
import { BenchError, loadSpec } from './specs.js';

export { BenchError } from './specs.js';

export interface FixtureResult {
  label: string;
  producer: string;
  layout: string;
  score: number;
  structurePct: number;
  contentPct: number;
  valid: boolean;
  coverage: number;
  source: 'derived' | 'ideal';
  misses: string[];
}

export interface BenchRun {
  engine: string;
  model: string;
  suiteHash: string;
  results: FixtureResult[];
  regressions: Regression[];
  attribution: Attribution;
}

export interface BenchOptions {
  specsDir: string;
  baselinesDir: string;
  resultsPath?: string;
  threshold?: number;
  engine?: BenchEngine;
}

export function runBench(options: BenchOptions): BenchRun {
  const engine = options.engine ?? reconstructEngine;
  const threshold = options.threshold ?? 0;
  const fixtures = loadFixtures();
  if (fixtures.length === 0) {
    throw new BenchError('empty corpus — no fixtures to score');
  }

  const results: FixtureResult[] = [];
  const hashParts: string[] = [];

  for (const fixture of fixtures) {
    const spec = loadSpec(options.specsDir, fixture.id); // throws BenchError if missing/corrupt
    const realized = engine.realize(fixture.specs);
    const tree = parseToTree(realized.output);
    const tally = scoreTree(spec.tree, tree);
    const structurePct = pct(tally.structureMatched, tally.structureTotal);
    const contentPct = pct(tally.contentMatched, tally.contentTotal);
    const score = scoreFromAxes({ structurePct, contentPct, valid: realized.valid });

    results.push({
      label: fixture.id,
      producer: fixture.producer,
      layout: fixture.id.split('/').slice(1).join('/') || fixture.id,
      score,
      structurePct,
      contentPct,
      valid: realized.valid,
      coverage: coverage(realized.expectedText, realized.output),
      source: spec.source,
      misses: tally.misses,
    });
    hashParts.push(`id:${fixture.id}`, `spec:${JSON.stringify(spec.tree)}`);
  }

  const runHash = suiteHash(hashParts);
  const baseline = readBaseline(options.baselinesDir, engine.label, engine.model); // throws RatchetError if corrupt
  const regressions = detectRegressions(results, baseline, threshold);
  const previous = options.resultsPath
    ? loadPreviousComparable(options.resultsPath, runHash, engine.label, engine.model)
    : undefined;
  const attribution = attribute(results, previous);

  return { engine: engine.label, model: engine.model, suiteHash: runHash, results, regressions, attribution };
}

interface RunRecord {
  engine: string;
  model: string;
  suiteHash: string;
  updatedAt: string;
  fixtures: Record<string, number>;
}

function loadPreviousComparable(
  resultsPath: string,
  runHash: string,
  engine: string,
  model: string,
): Record<string, number> | undefined {
  if (!existsSync(resultsPath)) return undefined;
  const matches: RunRecord[] = [];
  for (const line of readFileSync(resultsPath, 'utf8').split('\n')) {
    if (!line.trim()) continue;
    let record: RunRecord;
    try {
      record = JSON.parse(line) as RunRecord;
    } catch {
      continue; // skip a corrupt line; never crash attribution
    }
    if (record.suiteHash === runHash && record.engine === engine && record.model === model) {
      matches.push(record);
    }
  }
  return matches.at(-1)?.fixtures;
}

/** Append a provenance-tagged run record (gitignored results.jsonl). */
export function recordRun(resultsPath: string, run: BenchRun, now: string): void {
  mkdirSync(path.dirname(resultsPath), { recursive: true });
  const record: RunRecord = {
    engine: run.engine,
    model: run.model,
    suiteHash: run.suiteHash,
    updatedAt: now,
    fixtures: Object.fromEntries(run.results.map((r) => [r.label, r.score])),
  };
  appendFileSync(resultsPath, `${JSON.stringify(record)}\n`, 'utf8');
}

// ────────────────────────────── CLI ──────────────────────────────

const PKG_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const BENCH_DIR = path.join(PKG_ROOT, 'benchmarks');

function flag(name: string): string | undefined {
  const index = process.argv.indexOf(`--${name}`);
  return index !== -1 ? process.argv[index + 1] : undefined;
}
function has(name: string): boolean {
  return process.argv.includes(`--${name}`);
}

function printScoreboard(run: BenchRun): void {
  console.log(`\nbench: ${run.engine} / ${run.model}   suite ${run.suiteHash}`);
  for (const r of run.results.slice().sort((a, b) => a.label.localeCompare(b.label))) {
    const tag = r.valid ? '' : ' INVALID';
    const cov = `${Math.round(r.coverage * 100)}%`;
    console.log(`  ${r.label.padEnd(48)} ${String(r.score).padStart(3)}  cov ${cov.padStart(4)}  [${r.source}]${tag}`);
  }
  const avg = run.results.length
    ? Math.round(run.results.reduce((s, r) => s + r.score, 0) / run.results.length)
    : 0;
  console.log(`  ── mean score: ${avg}`);

  if (run.regressions.length === 0) {
    console.log('  ✓ ratchet: no fixture below baseline.');
  } else {
    console.log(`  ✗ ratchet: ${run.regressions.length} regression(s):`);
    for (const reg of run.regressions) {
      console.log(`    ${reg.label.padEnd(48)} ${reg.baseline} → ${reg.current}  (−${reg.drop})`);
    }
  }

  const a = run.attribution;
  if (a.comparable) {
    const verdict =
      a.classification === 'class-move'
        ? 'class-move (generalization)'
        : a.classification === 'single-cell'
          ? '⚠ overfit suspect (single cell / one producer)'
          : a.classification;
    console.log(`  attribution: ${verdict}`);
  }
}

function main(): void {
  try {
    const specsDir = flag('specs') ?? path.join(BENCH_DIR, 'specs');
    const baselinesDir = flag('baselines') ?? path.join(BENCH_DIR, 'baselines');
    const resultsPath = flag('results') ?? path.join(BENCH_DIR, 'results.jsonl');
    const threshold = flag('threshold') ? Number(flag('threshold')) : 0;

    const run = runBench({ specsDir, baselinesDir, resultsPath, threshold });
    printScoreboard(run);

    const now = new Date().toISOString();
    if (has('record')) recordRun(resultsPath, run, now);
    if (has('baseline-update')) {
      updateBaseline(baselinesDir, run.results, run.engine, run.model, run.suiteHash, now);
      console.log('  baseline updated.');
    }

    process.exit(run.regressions.length > 0 && !has('baseline-update') ? 1 : 0);
  } catch (error) {
    if (error instanceof BenchError || error instanceof RatchetError) {
      console.error(`[${error.name}] ${error.message}`);
      process.exit(2);
    }
    throw error;
  }
}

const invokedDirectly =
  Boolean(process.argv[1]) && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (invokedDirectly) main();
