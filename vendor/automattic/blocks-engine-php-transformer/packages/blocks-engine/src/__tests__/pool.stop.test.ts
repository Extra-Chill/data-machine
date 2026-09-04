import type { ChildProcess } from 'node:child_process';

import { beforeEach, describe, expect, it, vi } from 'vitest';

import { createWorker } from '../pool/pool';
import {
  canonicalizeSentinelFor,
  crashAlwaysInput,
  fixtureFor,
  hangInput,
  withTimeout,
} from './pool-test-helpers';

const childActivity = vi.hoisted(() => {
  type Waiter = { target: number; resolve: () => void };

  const state = {
    sends: 0,
    responses: 0,
    exits: 0,
    sendWaiters: [] as Waiter[],
    responseWaiters: [] as Waiter[],
    exitWaiters: [] as Waiter[],
  };

  function resolveReady(waiters: Waiter[], count: number): Waiter[] {
    const pending: Waiter[] = [];
    for (const waiter of waiters) {
      if (count >= waiter.target) {
        waiter.resolve();
      } else {
        pending.push(waiter);
      }
    }
    return pending;
  }

  return {
    reset(): void {
      state.sends = 0;
      state.responses = 0;
      state.exits = 0;
      state.sendWaiters = [];
      state.responseWaiters = [];
      state.exitWaiters = [];
    },
    noteSend(): void {
      state.sends += 1;
      state.sendWaiters = resolveReady(state.sendWaiters, state.sends);
    },
    noteResponse(): void {
      state.responses += 1;
      state.responseWaiters = resolveReady(state.responseWaiters, state.responses);
    },
    noteExit(): void {
      state.exits += 1;
      state.exitWaiters = resolveReady(state.exitWaiters, state.exits);
    },
    exitCount(): number {
      return state.exits;
    },
    waitForSends(target: number): Promise<void> {
      if (state.sends >= target) {
        return Promise.resolve();
      }
      return new Promise((resolve) => state.sendWaiters.push({ target, resolve }));
    },
    waitForResponses(target: number): Promise<void> {
      if (state.responses >= target) {
        return Promise.resolve();
      }
      return new Promise((resolve) => state.responseWaiters.push({ target, resolve }));
    },
    waitForExits(target: number): Promise<void> {
      if (state.exits >= target) {
        return Promise.resolve();
      }
      return new Promise((resolve) => state.exitWaiters.push({ target, resolve }));
    },
  };
});

vi.mock('node:child_process', async (importOriginal) => {
  const actual = await importOriginal<typeof import('node:child_process')>();
  const fork = ((...args: unknown[]) => {
    const child = Reflect.apply(actual.fork, actual, args) as ChildProcess;
    const originalSend = child.send.bind(child) as (
      ...sendArgs: Parameters<ChildProcess['send']>
    ) => boolean;

    child.send = ((...sendArgs: Parameters<ChildProcess['send']>) => {
      childActivity.noteSend();
      return originalSend(...sendArgs);
    }) as ChildProcess['send'];

    child.on('message', () => childActivity.noteResponse());
    child.once('exit', () => childActivity.noteExit());
    return child;
  }) as typeof actual.fork;

  return { ...actual, fork };
});

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

async function waitForSecondItemInFlight(): Promise<void> {
  await withLabel('first worker response', childActivity.waitForResponses(1), 15_000);
  await withLabel('second worker send', childActivity.waitForSends(2), 15_000);
}

async function withLabel<T>(
  label: string,
  promise: Promise<T>,
  timeoutMs = 3_000,
): Promise<T> {
  try {
    return await withTimeout(promise, timeoutMs);
  } catch (error) {
    throw new Error(`${label}: ${error instanceof Error ? error.message : String(error)}`);
  }
}

describe('worker pool stop during in-flight batches', () => {
  beforeEach(() => {
    childActivity.reset();
  });

  it('resolves a stopped rawConvert batch to the oracle sentinel shape', async () =>
    withTestMode(async () => {
      const fixture = fixtureFor('rawConvert', 1);
      const pool = createWorker({ size: 1 });

      try {
        const batch = pool.rawConvert([hangInput(fixture.input)]);
        await withLabel('rawConvert worker send', childActivity.waitForSends(1));

        const stopped = pool.stop();
        await expect(withLabel('stopped rawConvert batch', batch)).resolves.toEqual([
          { html: null, wpHtmlResidue: Infinity },
        ]);
        await withLabel('stop after rawConvert batch', stopped);
      } finally {
        await pool.stop();
      }
    }), 20_000);

  it('keeps completed canonicalize results and does not wedge later batches', async () =>
    withTestMode(async () => {
      const first = fixtureFor('canonicalize', 0);
      const second = fixtureFor('canonicalize', 1);
      const hungInput = hangInput(second.input);
      const pool = createWorker({ size: 1 });

      try {
        const batch = pool.canonicalize([first.input, hungInput]);
        await waitForSecondItemInFlight();

        const stopped = pool.stop();
        await expect(withLabel('stopped canonicalize batch', batch)).resolves.toMatchObject([
          first.expected,
          canonicalizeSentinelFor(hungInput),
        ]);
        await withLabel('stop after canonicalize batch', stopped);

        await expect(withLabel('empty batch after stop', pool.canonicalize([]))).resolves.toEqual([]);
      } finally {
        await pool.stop();
      }
    }), 20_000);

  it('does not wedge a non-empty batch queued before stop settles the in-flight batch', async () =>
    withTestMode(async () => {
      const first = fixtureFor('canonicalize', 0);
      const second = fixtureFor('canonicalize', 1);
      const third = fixtureFor('canonicalize', 2);
      const stoppedInput = hangInput(second.input);
      const queuedInput = crashAlwaysInput(third.input);
      const pool = createWorker({
        size: 1,
        maxReroutes: 0,
      });

      try {
        const inFlightBatch = pool.canonicalize([first.input, stoppedInput]);
        const queuedBatch = pool.canonicalize([queuedInput]);
        await waitForSecondItemInFlight();

        const stopped = pool.stop();
        await expect(withLabel('stopped canonicalize batch', inFlightBatch)).resolves.toMatchObject([
          first.expected,
          canonicalizeSentinelFor(stoppedInput),
        ]);
        await withLabel('stop after canonicalize batch', stopped);

        await expect(withLabel('queued canonicalize batch', queuedBatch)).resolves.toMatchObject([
          canonicalizeSentinelFor(queuedInput),
        ]);
      } finally {
        await pool.stop();
      }
    }), 20_000);

  it('resolves a batch stopped while recycling between items', async () =>
    withTestMode(async () => {
      const first = fixtureFor('canonicalize', 0);
      const second = fixtureFor('canonicalize', 1);
      let stopDuringRecycle: Promise<void> | undefined;
      let sawRecycle: () => void = () => {};
      const recycleSeen = new Promise<void>((resolve) => {
        sawRecycle = resolve;
      });
      const pool = createWorker({
        size: 1,
        recycleAfter: 1,
        onEvent: (event) => {
          if (event.type === 'recycle' && !stopDuringRecycle) {
            stopDuringRecycle = pool.stop();
            sawRecycle();
          }
        },
      });

      try {
        const batch = pool.canonicalize([first.input, second.input]);
        await withLabel('recycle event', recycleSeen, 15_000);

        await expect(withLabel('batch stopped during recycle', batch)).resolves.toMatchObject([
          first.expected,
          canonicalizeSentinelFor(second.input),
        ]);
        await withLabel(
          'stop during recycle',
          stopDuringRecycle ?? Promise.reject(new Error('Missing stop during recycle')),
        );
      } finally {
        await pool.stop();
      }
    }), 20_000);

  it('resolves a batch stopped from a child-crash event before reroute', async () =>
    withTestMode(async () => {
      const fixture = fixtureFor('canonicalize', 0);
      const input = crashAlwaysInput(fixture.input);
      let stopDuringCrash: Promise<void> | undefined;
      let sawCrash: () => void = () => {};
      const crashSeen = new Promise<void>((resolve) => {
        sawCrash = resolve;
      });
      const pool = createWorker({
        size: 1,
        maxReroutes: 2,
        onEvent: (event) => {
          if (event.type === 'child-crash' && !stopDuringCrash) {
            stopDuringCrash = pool.stop();
            sawCrash();
          }
        },
      });

      try {
        const batch = pool.canonicalize([input]);
        await withLabel('child-crash event', crashSeen, 15_000);

        await expect(withLabel('batch stopped during child crash', batch)).resolves.toMatchObject([
          canonicalizeSentinelFor(input),
        ]);
        await withLabel(
          'stop during child crash',
          stopDuringCrash ?? Promise.reject(new Error('Missing stop during child crash')),
        );
      } finally {
        await pool.stop();
      }
    }), 20_000);

  it('returns the active teardown promise for stop re-entered from sentinel callbacks', async () =>
    withTestMode(async () => {
      const fixture = fixtureFor('rawConvert', 1);
      let nestedStop: Promise<void> | undefined;
      let nestedResolvedBeforeExit = false;
      const pool = createWorker({
        size: 1,
        onEvent: (event) => {
          if (event.type === 'sentinel' && !nestedStop) {
            nestedStop = pool.stop().then(() => {
              nestedResolvedBeforeExit = childActivity.exitCount() === 0;
            });
          }
        },
      });

      try {
        const batch = pool.rawConvert([hangInput(fixture.input)]);
        await withLabel('rawConvert worker send', childActivity.waitForSends(1));

        const stopped = pool.stop();
        await expect(withLabel('stopped rawConvert batch', batch)).resolves.toEqual([
          { html: null, wpHtmlResidue: Infinity },
        ]);
        await withLabel(
          'nested stop from sentinel',
          nestedStop ?? Promise.reject(new Error('Missing nested stop from sentinel')),
        );
        await withLabel('outer stop from sentinel test', stopped);
        await withLabel('worker exit after sentinel stop', childActivity.waitForExits(1));
        expect(nestedResolvedBeforeExit).toBe(false);
      } finally {
        await pool.stop();
      }
    }), 20_000);
});
