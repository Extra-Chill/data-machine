import { describe, expect, it } from 'vitest';

import { createWorker, type PoolEvent } from '../pool/pool';
import { fixturesFor } from './pool-test-helpers';

describe('worker pool recycle', () => {
  it('recycles a child after the configured processed count boundary', async () => {
    const rawFixtures = fixturesFor('rawConvert');
    const batch = Array.from({ length: 7 }, (_value, index) => {
      return rawFixtures[index % rawFixtures.length];
    });
    const events: PoolEvent[] = [];
    const pool = createWorker({
      size: 1,
      recycleAfter: 3,
      onEvent: (event) => events.push(event),
    });

    try {
      await expect(pool.rawConvert(batch.map((fixture) => fixture.input))).resolves.toEqual(
        batch.map((fixture) => fixture.expected),
      );
    } finally {
      await pool.stop();
    }

    const spawnedPids = events
      .filter((event) => event.type === 'child-spawn')
      .map((event) => event.childId)
      .filter((pid): pid is number => pid !== undefined);
    const recycleCounts = events
      .filter((event) => event.type === 'recycle')
      .map((event) => event.count);

    expect(new Set(spawnedPids).size).toBeGreaterThanOrEqual(3);
    expect(recycleCounts).toEqual([3, 6]);
  }, 30_000);
});
