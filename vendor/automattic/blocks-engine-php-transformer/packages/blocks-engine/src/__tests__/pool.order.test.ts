import { describe, expect, it } from 'vitest';

import { createWorker, type PoolEvent } from '../pool/pool';
import {
  delayedInput,
  fixtureFor,
  fixturesFor,
  pidIsAlive,
  testWorkerEnv,
} from './pool-test-helpers';

describe('worker pool ordered batches', () => {
  it('returns rawConvert results 1:1 in input order when the first item completes later', async () => {
    const [first, second] = fixturesFor('rawConvert');
    const pool = createWorker({
      size: 2,
      onEvent: () => {},
    });

    const previousMode = process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
    process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = '1';
    try {
      await expect(pool.rawConvert([delayedInput(first.input), second.input])).resolves.toEqual([
        first.expected,
        second.expected,
      ]);
    } finally {
      await pool.stop();
      if (previousMode === undefined) {
        delete process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
      } else {
        process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = previousMode;
      }
    }
  }, 20_000);

  it('returns canonicalize results 1:1 in input order when the first item completes later', async () => {
    const [first, second] = fixturesFor('canonicalize');
    const pool = createWorker({ size: 2 });

    const previousMode = process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
    process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = '1';
    try {
      await expect(pool.canonicalize([delayedInput(first.input), second.input])).resolves.toMatchObject([
        first.expected,
        second.expected,
      ]);
    } finally {
      await pool.stop();
      if (previousMode === undefined) {
        delete process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
      } else {
        process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = previousMode;
      }
    }
  }, 20_000);

  it('returns empty batches without starting children', async () => {
    const events: PoolEvent[] = [];
    const pool = createWorker({ size: 2, onEvent: (event) => events.push(event) });

    await expect(pool.rawConvert([])).resolves.toEqual([]);
    await expect(pool.canonicalize([])).resolves.toEqual([]);
    await pool.stop();
    await pool.stop();

    expect(events).toEqual([]);
  });

  it('stop resolves after terminating spawned children', async () => {
    const pids: number[] = [];
    const fixture = fixtureFor('rawConvert');
    const pool = createWorker({
      size: 2,
      onEvent: (event) => {
        if (event.type === 'child-spawn' && event.childId !== undefined) {
          pids.push(event.childId);
        }
      },
    });

    await expect(pool.rawConvert([fixture.input])).resolves.toEqual([fixture.expected]);
    await pool.stop();
    await pool.stop();

    expect(pids.length).toBeGreaterThan(0);
    expect(pids.every((pid) => !pidIsAlive(pid))).toBe(true);
  }, 20_000);
});
