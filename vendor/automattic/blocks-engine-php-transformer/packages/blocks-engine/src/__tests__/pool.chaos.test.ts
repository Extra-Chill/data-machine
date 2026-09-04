import { describe, expect, it } from 'vitest';

import { createWorker, type PoolEvent } from '../pool/pool';
import {
  canonicalizeSentinelFor,
  crashAlwaysInput,
  crashOnceInput,
  fixtureFor,
  hangInput,
  withTimeout,
} from './pool-test-helpers';

function withTestMode<T>(fn: () => Promise<T>): Promise<T> {
  const previousMode = process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
  process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = '1';

  return fn().finally(() => {
    if (previousMode === undefined) {
      delete process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
    } else {
      process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = previousMode;
    }
  });
}

describe('worker pool chaos recovery', () => {
  it('re-routes an item after a child crashes and still returns the golden result', async () =>
    withTestMode(async () => {
      const fixture = fixtureFor('rawConvert');
      const events: PoolEvent[] = [];
      const pool = createWorker({
        size: 2,
        maxReroutes: 2,
        onEvent: (event) => events.push(event),
      });

      try {
        await expect(pool.rawConvert([crashOnceInput(fixture.input)])).resolves.toEqual([
          fixture.expected,
        ]);
      } finally {
        await pool.stop();
      }

      expect(events.some((event) => event.type === 'child-crash')).toBe(true);
      expect(events.some((event) => event.type === 're-route')).toBe(true);
      expect(events.some((event) => event.type === 'sentinel')).toBe(false);
    }), 20_000);

  it('returns the rawConvert sentinel after a poison item exhausts re-routes', async () =>
    withTestMode(async () => {
      const fixture = fixtureFor('rawConvert');
      const events: PoolEvent[] = [];
      const pool = createWorker({
        size: 2,
        maxReroutes: 2,
        onEvent: (event) => events.push(event),
      });

      try {
        await expect(pool.rawConvert([crashAlwaysInput(fixture.input)])).resolves.toEqual([
          { html: null, wpHtmlResidue: Infinity },
        ]);
      } finally {
        await pool.stop();
      }

      expect(events.some((event) => event.type === 'child-crash')).toBe(true);
      expect(events.some((event) => event.type === 're-route')).toBe(true);
      expect(events.filter((event) => event.type === 'sentinel')).toHaveLength(1);
    }), 20_000);

  it('uses itemTimeoutMs to sentinel a hung canonicalize item without waiting 10 seconds', async () =>
    withTestMode(async () => {
      const fixture = fixtureFor('canonicalize');
      const events: PoolEvent[] = [];
      const input = hangInput(fixture.input);
      const pool = createWorker({
        size: 1,
        maxReroutes: 1,
        itemTimeoutMs: 50,
        onEvent: (event) => events.push(event),
      });

      try {
        await expect(pool.canonicalize([input])).resolves.toEqual([
          canonicalizeSentinelFor(input),
        ]);
      } finally {
        await pool.stop();
      }

      expect(events.some((event) => event.type === 're-route')).toBe(true);
      expect(events.filter((event) => event.type === 'sentinel')).toHaveLength(1);
    }), 20_000);

  it('degrades the whole batch when every child fails bootstrap', async () => {
    const previousMode = process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
    const previousBootstrapFail = process.env.BLOCKS_ENGINE_FORCE_BOOTSTRAP_FAIL;
    process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = '1';
    process.env.BLOCKS_ENGINE_FORCE_BOOTSTRAP_FAIL = '1';

    const first = fixtureFor('rawConvert', 0);
    const second = fixtureFor('rawConvert', 1);
    const events: PoolEvent[] = [];
    const pool = createWorker({
      size: 2,
      maxReroutes: 2,
      onEvent: (event) => events.push(event),
    });

    try {
      await expect(pool.rawConvert([first.input, second.input])).resolves.toEqual([
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
      if (previousBootstrapFail === undefined) {
        delete process.env.BLOCKS_ENGINE_FORCE_BOOTSTRAP_FAIL;
      } else {
        process.env.BLOCKS_ENGINE_FORCE_BOOTSTRAP_FAIL = previousBootstrapFail;
      }
    }

    expect(events.some((event) => event.type === 'pool-degraded')).toBe(true);
    expect(events.filter((event) => event.type === 'sentinel')).toHaveLength(2);
  }, 20_000);

  it('degrades a one-item batch when every child fails bootstrap', async () => {
    const previousMode = process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
    const previousBootstrapFail = process.env.BLOCKS_ENGINE_FORCE_BOOTSTRAP_FAIL;
    process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = '1';
    process.env.BLOCKS_ENGINE_FORCE_BOOTSTRAP_FAIL = '1';

    const fixture = fixtureFor('rawConvert');
    const events: PoolEvent[] = [];
    const pool = createWorker({
      size: 2,
      maxReroutes: 2,
      onEvent: (event) => events.push(event),
    });

    try {
      await expect(withTimeout(pool.rawConvert([fixture.input]), 3_000)).resolves.toEqual([
        { html: null, wpHtmlResidue: Infinity },
      ]);
    } finally {
      await pool.stop();
      if (previousMode === undefined) {
        delete process.env.BLOCKS_ENGINE_WORKER_TEST_MODE;
      } else {
        process.env.BLOCKS_ENGINE_WORKER_TEST_MODE = previousMode;
      }
      if (previousBootstrapFail === undefined) {
        delete process.env.BLOCKS_ENGINE_FORCE_BOOTSTRAP_FAIL;
      } else {
        process.env.BLOCKS_ENGINE_FORCE_BOOTSTRAP_FAIL = previousBootstrapFail;
      }
    }

    expect(events.some((event) => event.type === 'pool-degraded')).toBe(true);
    expect(events.filter((event) => event.type === 'sentinel')).toHaveLength(1);
  }, 10_000);
});
