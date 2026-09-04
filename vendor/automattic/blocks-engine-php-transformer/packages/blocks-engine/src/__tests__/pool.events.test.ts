import { describe, expect, it } from 'vitest';

import { createWorker, type PoolEvent } from '../pool/pool';
import { crashAlwaysInput, fixtureFor } from './pool-test-helpers';

describe('worker pool telemetry events', () => {
  it('emits one sentinel event per passthrough item and puts count on every event', async () => {
    const previousMode = process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
    process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = '1';

    const fixture = fixtureFor('rawConvert');
    const events: PoolEvent[] = [];
    const pool = createWorker({
      size: 2,
      maxReroutes: 1,
      onEvent: (event) => events.push(event),
    });

    try {
      await expect(
        pool.rawConvert([crashAlwaysInput(fixture.input), crashAlwaysInput(fixture.input)]),
      ).resolves.toEqual([
        { html: null, wpHtmlResidue: Infinity },
        { html: null, wpHtmlResidue: Infinity },
      ]);
    } finally {
      await pool.stop();
      if (previousMode === undefined) {
        delete process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
      } else {
        process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = previousMode;
      }
    }

    expect(events.length).toBeGreaterThan(0);
    expect(events.every((event) => typeof event.count === 'number')).toBe(true);
    expect(events.filter((event) => event.type === 'sentinel')).toHaveLength(2);
  }, 20_000);
});
