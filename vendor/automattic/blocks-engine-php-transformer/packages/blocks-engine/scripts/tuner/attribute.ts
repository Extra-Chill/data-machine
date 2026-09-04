/**
 * Honest attribution — the mechanic that kills single-cell overfits.
 *
 * Diffs a run against the previous comparable run (same suiteHash + engine +
 * model, supplied as a fixture→score map) and classifies the change:
 *   - class-move: ≥2 fixtures moved the same direction across ≥2 producers — a
 *     generalization (the win we want).
 *   - single-cell: one fixture moved, or the movement is confined to one producer
 *     (or mixed direction) — flagged as an overfit suspect.
 *
 * "Producer" is the first path segment of a fixture label (the corpus family).
 */
export interface ScoredFixture {
  label: string;
  score: number;
}

export interface FixtureDelta {
  label: string;
  producer: string;
  layout: string;
  before: number;
  after: number;
  delta: number;
}

export type Classification = 'class-move' | 'single-cell' | 'flat' | 'no-baseline';

export interface Attribution {
  comparable: boolean;
  deltas: FixtureDelta[];
  classification: Classification;
  overfitSuspect: boolean;
  byProducer: { producer: string; delta: number }[];
  byLayout: { layout: string; delta: number }[];
}

const CLASS_MOVE_MIN_FIXTURES = 2;
const CLASS_MOVE_MIN_PRODUCERS = 2;
const JITTER_THRESHOLD = 1; // ignore ±0 jitter

function producerOf(label: string): string {
  return label.split('/')[0];
}
function layoutOf(label: string): string {
  return label.split('/').slice(1).join('/') || label;
}

export function attribute(
  results: ScoredFixture[],
  previous: Record<string, number> | undefined,
): Attribution {
  if (!previous) {
    return {
      comparable: false,
      deltas: [],
      classification: 'no-baseline',
      overfitSuspect: false,
      byProducer: [],
      byLayout: [],
    };
  }

  const deltas: FixtureDelta[] = [];
  for (const result of results) {
    const before = previous[result.label];
    if (before === undefined) continue;
    const delta = result.score - before;
    if (Math.abs(delta) < JITTER_THRESHOLD) continue;
    deltas.push({
      label: result.label,
      producer: producerOf(result.label),
      layout: layoutOf(result.label),
      before,
      after: result.score,
      delta,
    });
  }

  const moved = deltas.filter((d) => d.delta !== 0);
  const producersMoved = new Set(moved.map((d) => d.producer));
  const allUp = moved.every((d) => d.delta > 0);
  const allDown = moved.every((d) => d.delta < 0);
  const consistent = moved.length > 0 && (allUp || allDown);

  let classification: Classification;
  let overfitSuspect = false;
  if (moved.length === 0) {
    classification = 'flat';
  } else if (
    consistent &&
    moved.length >= CLASS_MOVE_MIN_FIXTURES &&
    producersMoved.size >= CLASS_MOVE_MIN_PRODUCERS
  ) {
    classification = 'class-move';
  } else {
    classification = 'single-cell';
    overfitSuspect = true;
  }

  const sumBy = (key: (d: FixtureDelta) => string) => {
    const map = new Map<string, number>();
    for (const d of deltas) map.set(key(d), (map.get(key(d)) ?? 0) + d.delta);
    return map;
  };
  const byProducer = [...sumBy((d) => d.producer).entries()]
    .map(([producer, delta]) => ({ producer, delta }))
    .sort((a, b) => a.producer.localeCompare(b.producer));
  const byLayout = [...sumBy((d) => d.layout).entries()]
    .map(([layout, delta]) => ({ layout, delta }))
    .sort((a, b) => a.layout.localeCompare(b.layout));

  return { comparable: true, deltas, classification, overfitSuspect, byProducer, byLayout };
}
