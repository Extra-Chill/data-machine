import { fork, type ChildProcess } from 'node:child_process';
import { existsSync } from 'node:fs';
import { cpus } from 'node:os';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { BlocksEngineError } from '../errors.js';
import type { ChildToParentMessage, WorkerOp } from './protocol.js';
import type {
  CreateWorker,
  FixResult,
  RawConvertResult,
  WorkerPool,
  WorkerPoolOptions,
} from './types.js';

export type {
  FixResult,
  RawConvertResult,
  WorkerPool,
  WorkerPoolOptions,
} from './types.js';
export type { PoolEvent } from './events.js';

const DEFAULT_MAX_REROUTES = 2;
const DEFAULT_ITEM_TIMEOUT_MS = 10_000;
const STOP_GRACE_MS = 10_000;
const BOOTSTRAP_FAIL_EXIT_CODE = 87;

type PoolEventType =
  | 'child-spawn'
  | 'child-crash'
  | 're-route'
  | 'recycle'
  | 'sentinel'
  | 'pool-degraded';

type BatchResultByOp = {
  rawConvert: RawConvertResult;
  canonicalize: FixResult;
};

type BatchItem = {
  index: number;
  input: string;
  payload: string;
  reroutes: number;
};

type BatchState = {
  op: WorkerOp;
  items: string[];
  queue: BatchItem[];
  results: Array<RawConvertResult | FixResult | undefined>;
  remaining: number;
  bootstrapFailedSlots: Set<WorkerSlot>;
  degraded: boolean;
  done: boolean;
  resolve: (results: Array<RawConvertResult | FixResult>) => void;
};

type InFlight = {
  id: number;
  batch: BatchState;
  item: BatchItem;
  timer: ReturnType<typeof setTimeout>;
};

type WorkerSlot = {
  child: ChildProcess | null;
  inFlight: InFlight | null;
  failureInFlight: InFlight | null;
  recyclingBatch: BatchState | null;
  processed: number;
  recycling: boolean;
  stopping: boolean;
};

function defaultSize(): number {
  return Math.max(1, Math.min(cpus().length, 4));
}

function normalizeSize(size: number | undefined): number {
  if (size === undefined) {
    return defaultSize();
  }
  return Math.max(1, Math.floor(size));
}

function workerChildPath(): string {
  const here = fileURLToPath(import.meta.url);
  const poolDir = dirname(here);
  const candidates = [
    resolve(poolDir, 'wp', 'worker-child.js'),
    resolve(poolDir, 'wp', 'worker-child.ts'),
    resolve(poolDir, '..', 'wp', 'worker-child.ts'),
    resolve(poolDir, '..', 'wp', 'worker-child.js'),
  ];
  const workerPath = candidates.find((candidate) => existsSync(candidate));
  if (workerPath) {
    return workerPath;
  }
  throw new BlocksEngineError(
    `Unable to resolve worker child path. Tried: ${candidates.join(', ')}`,
    {
      code: 'WORKER_CHILD_UNRESOLVED',
      hint: 'Run npm run build and confirm the expected dist layout includes the pool/wp worker child file.',
    },
  );
}

function forkExecArgv(path: string): string[] {
  if (!path.endsWith('.ts')) {
    return process.execArgv;
  }
  if (process.execArgv.includes('tsx')) {
    return process.execArgv;
  }
  return [...process.execArgv, '--import', 'tsx'];
}

function rawSentinel(): RawConvertResult {
  return { html: null, wpHtmlResidue: Infinity };
}

function canonicalizeSentinel(html: string): FixResult {
  return {
    html,
    changed: false,
    fixedIssues: [],
    blockCount: 0,
    htmlIslands: [],
    htmlIslandCount: 0,
    htmlIslandOccurrences: [],
    htmlIslandDistinctCount: 0,
    htmlIslandOccurrencesTruncated: false,
    degraded: true,
  };
}

function sentinelFor<Op extends WorkerOp>(
  op: Op,
  input: string,
): BatchResultByOp[Op] {
  return (op === 'rawConvert' ? rawSentinel() : canonicalizeSentinel(input)) as BatchResultByOp[Op];
}

function isTestMode(): boolean {
  return process.env.BLOCKS_ENGINE_WORKER_TEST_MODE === '1';
}

function stripCrashOnceMarker(payload: string): string {
  if (!isTestMode()) {
    return payload;
  }
  return payload.replace(/^<!-- BLOCKS_ENGINE_TEST_CRASH_ONCE -->/, '');
}

function stopChild(child: ChildProcess, graceMs = STOP_GRACE_MS): Promise<void> {
  if (child.exitCode !== null || child.signalCode !== null) {
    return Promise.resolve();
  }

  return new Promise((resolve) => {
    let killTimer: ReturnType<typeof setTimeout> | undefined;
    const onExit = (): void => {
      if (killTimer) {
        clearTimeout(killTimer);
      }
      resolve();
    };
    child.once('exit', onExit);

    try {
      child.kill('SIGTERM');
    } catch {
      onExit();
      return;
    }

    killTimer = setTimeout(() => {
      try {
        child.kill('SIGKILL');
      } catch {
        // already dead
      }
    }, graceMs);
    killTimer.unref();
  });
}

class WorkerPoolImpl implements WorkerPool {
  private readonly size: number;
  private readonly recycleAfter: number;
  private readonly maxReroutes: number;
  private readonly itemTimeoutMs: number;
  private readonly onEvent: WorkerPoolOptions['onEvent'];
  private readonly slots: WorkerSlot[] = [];
  private readonly path = workerChildPath();
  private eventCount = 0;
  private sentinelCount = 0;
  private nextMessageId = 1;
  private stopped = false;
  private stopping = false;
  private stopPromise: Promise<void> = Promise.resolve();
  private chain: Promise<unknown> = Promise.resolve();

  constructor(opts: WorkerPoolOptions = {}) {
    this.size = normalizeSize(opts.size);
    this.recycleAfter = opts.recycleAfter ?? 0;
    this.maxReroutes = opts.maxReroutes ?? DEFAULT_MAX_REROUTES;
    this.itemTimeoutMs = opts.itemTimeoutMs ?? DEFAULT_ITEM_TIMEOUT_MS;
    this.onEvent = opts.onEvent;
  }

  rawConvert(items: string[]): Promise<RawConvertResult[]> {
    return this.enqueue(() => this.runBatch('rawConvert', items));
  }

  canonicalize(items: string[]): Promise<FixResult[]> {
    return this.enqueue(() => this.runBatch('canonicalize', items));
  }

  async stop(): Promise<void> {
    if (this.stopping) {
      return this.stopPromise;
    }

    this.stopped = true;
    this.stopping = true;
    let resolveStop!: () => void;
    let rejectStop!: (error: unknown) => void;
    const stopPromise = new Promise<void>((resolve, reject) => {
      resolveStop = resolve;
      rejectStop = reject;
    });
    this.stopPromise = stopPromise;

    const finishStop = (): void => {
      this.stopping = false;
      this.stopPromise = Promise.resolve();
    };
    void this.stopSlots().then(
      () => {
        finishStop();
        resolveStop();
      },
      (error: unknown) => {
        finishStop();
        rejectStop(error);
      },
    );
    return stopPromise;
  }

  private async stopSlots(): Promise<void> {
    const inFlightBatches = new Set<BatchState>();
    for (const slot of this.slots) {
      if (slot.inFlight) {
        inFlightBatches.add(slot.inFlight.batch);
      }
      if (slot.failureInFlight) {
        inFlightBatches.add(slot.failureInFlight.batch);
      }
      if (slot.recyclingBatch) {
        inFlightBatches.add(slot.recyclingBatch);
      }
    }
    for (const batch of inFlightBatches) {
      this.settleBatchWithSentinels(batch);
    }

    const children = this.slots
      .map((slot) => slot.child)
      .filter((child): child is ChildProcess => child !== null);

    for (const slot of this.slots) {
      slot.stopping = true;
      if (slot.inFlight) {
        clearTimeout(slot.inFlight.timer);
        slot.inFlight = null;
      }
    }

    await Promise.all(children.map((child) => stopChild(child)));
    this.slots.length = 0;
  }

  private enqueue<T>(work: () => Promise<T>): Promise<T> {
    const next = this.chain.then(work, work);
    this.chain = next.catch(() => undefined);
    return next;
  }

  private async runBatch<Op extends WorkerOp>(
    op: Op,
    items: string[],
  ): Promise<BatchResultByOp[Op][]> {
    if (items.length === 0) {
      return [];
    }
    if (this.stopping) {
      await this.stopPromise;
    }

    this.stopped = false;
    this.ensureStarted();

    return new Promise((resolve) => {
      const batch: BatchState = {
        op,
        items,
        queue: items.map((input, index) => ({
          index,
          input,
          payload: input,
          reroutes: 0,
        })),
        results: Array.from({ length: items.length }),
        remaining: items.length,
        bootstrapFailedSlots: new Set(),
        degraded: false,
        done: false,
        resolve: (results) => resolve(results as BatchResultByOp[Op][]),
      };

      this.dispatch(batch);
    });
  }

  private ensureStarted(): void {
    while (this.slots.length < this.size) {
      this.slots.push({
        child: null,
        inFlight: null,
        failureInFlight: null,
        recyclingBatch: null,
        processed: 0,
        recycling: false,
        stopping: false,
      });
    }

    for (const slot of this.slots) {
      if (!slot.child) {
        this.spawn(slot);
      }
    }
  }

  private spawn(slot: WorkerSlot): void {
    slot.stopping = false;
    slot.recycling = false;
    slot.failureInFlight = null;
    slot.recyclingBatch = null;
    slot.inFlight = null;

    const child = fork(this.path, [], {
      env: process.env,
      execArgv: forkExecArgv(this.path),
      stdio: ['ignore', 'ignore', 'ignore', 'ipc'],
    });
    slot.child = child;

    this.emit({ type: 'child-spawn', childId: child.pid });

    child.on('message', (message) => this.handleMessage(slot, message as ChildToParentMessage));
    child.on('exit', (code, signal) => this.handleExit(slot, code, signal));
    child.on('error', () => {
      this.emit({ type: 'child-crash', childId: child.pid });
    });
  }

  private dispatch(batch: BatchState): void {
    if (batch.done || batch.degraded || this.stopped) {
      return;
    }

    for (const slot of this.slots) {
      if (batch.queue.length === 0) {
        return;
      }
      if (!slot.child || slot.inFlight || slot.recycling || slot.stopping) {
        continue;
      }
      if (batch.bootstrapFailedSlots.has(slot)) {
        continue;
      }
      if (this.recycleAfter > 0 && slot.processed >= this.recycleAfter) {
        void this.recycle(slot, batch);
        continue;
      }

      const item = batch.queue.shift();
      if (!item) {
        return;
      }
      this.assign(slot, batch, item);
    }
  }

  private assign(
    slot: WorkerSlot,
    batch: BatchState,
    item: BatchItem,
  ): void {
    const child = slot.child;
    if (!child || !child.connected) {
      batch.queue.unshift(item);
      return;
    }

    const id = this.nextMessageId++;
    const timer = setTimeout(() => this.handleTimeout(slot), this.itemTimeoutMs);
    timer.unref();

    slot.inFlight = { id, batch, item, timer };
    child.send(
      {
        id,
        op: batch.op,
        payload: item.payload,
      },
      (error) => {
        if (error) {
          this.handleFailure(slot, slot.inFlight, 'send-error');
        }
      },
    );
  }

  private handleMessage(slot: WorkerSlot, message: ChildToParentMessage): void {
    const inFlight = slot.inFlight;
    if (!inFlight || inFlight.id !== message.id) {
      return;
    }

    clearTimeout(inFlight.timer);
    slot.inFlight = null;

    if ('error' in message) {
      this.handleFailure(slot, inFlight, 'worker-error');
      return;
    }

    this.completeItem(inFlight.batch, inFlight.item.index, message.result);
    slot.processed += 1;
    this.dispatch(inFlight.batch);
  }

  private handleTimeout(slot: WorkerSlot): void {
    const inFlight = slot.inFlight;
    if (!inFlight) {
      return;
    }

    slot.inFlight = null;
    this.handleFailure(slot, inFlight, 'timeout');

    try {
      slot.child?.kill('SIGKILL');
    } catch {
      // already dead
    }
  }

  private handleExit(
    slot: WorkerSlot,
    code: number | null,
    signal: NodeJS.Signals | null,
  ): void {
    const childId = slot.child?.pid;
    const inFlight = slot.inFlight;
    slot.child = null;
    slot.inFlight = null;

    if (inFlight) {
      clearTimeout(inFlight.timer);
      slot.failureInFlight = inFlight;
    }

    const intentional = slot.stopping || slot.recycling || this.stopped;
    try {
      if (!intentional) {
        this.emit({ type: 'child-crash', childId });
        if (inFlight && code === BOOTSTRAP_FAIL_EXIT_CODE) {
          this.handleBootstrapFailure(slot, inFlight);
        } else if (inFlight) {
          this.handleFailure(slot, inFlight, `exit:${code ?? signal ?? 'unknown'}`);
        }
        if (!this.stopped && !inFlight?.batch.degraded) {
          slot.processed = 0;
          this.spawn(slot);
          if (inFlight) {
            this.dispatch(inFlight.batch);
          }
        }
      }
    } finally {
      if (slot.failureInFlight === inFlight) {
        slot.failureInFlight = null;
      }
    }
  }

  private handleBootstrapFailure(slot: WorkerSlot, inFlight: InFlight): void {
    const batch = inFlight.batch;
    if (batch.done || batch.degraded) {
      return;
    }

    batch.bootstrapFailedSlots.add(slot);
    if (batch.bootstrapFailedSlots.size < this.size) {
      batch.queue.unshift(inFlight.item);
      return;
    }

    batch.degraded = true;
    this.emit({ type: 'pool-degraded' });

    for (const slot of this.slots) {
      if (slot.inFlight?.batch === batch) {
        clearTimeout(slot.inFlight.timer);
        slot.inFlight = null;
      }
    }
    batch.queue.length = 0;

    for (let index = 0; index < batch.items.length; index++) {
      if (batch.results[index] === undefined) {
        this.emitSentinel();
        batch.results[index] = sentinelFor(batch.op, batch.items[index]);
      }
    }

    batch.remaining = 0;
    this.finish(batch);
  }

  private settleBatchWithSentinels(batch: BatchState): void {
    if (batch.done) {
      return;
    }

    batch.queue.length = 0;
    for (let index = 0; index < batch.items.length; index++) {
      if (batch.results[index] === undefined) {
        this.emitSentinel();
        this.completeItem(batch, index, sentinelFor(batch.op, batch.items[index]));
      }
    }
  }

  private handleFailure(
    slot: WorkerSlot,
    inFlight: InFlight | null,
    _reason: string,
  ): void {
    if (!inFlight || inFlight.batch.done || inFlight.batch.degraded) {
      return;
    }

    slot.failureInFlight = inFlight;
    try {
      clearTimeout(inFlight.timer);
      const { batch, item } = inFlight;
      if (item.reroutes >= this.maxReroutes) {
        this.emitSentinel();
        this.completeItem(batch, item.index, sentinelFor(batch.op, item.input));
        this.dispatch(batch);
        return;
      }

      item.reroutes += 1;
      item.payload = stripCrashOnceMarker(item.payload);
      this.emit({ type: 're-route', childId: slot.child?.pid, count: item.reroutes });
      batch.queue.unshift(item);
      this.dispatch(batch);
    } finally {
      if (slot.failureInFlight === inFlight) {
        slot.failureInFlight = null;
      }
    }
  }

  private completeItem(
    batch: BatchState,
    index: number,
    result: RawConvertResult | FixResult,
  ): void {
    if (batch.done || batch.results[index] !== undefined) {
      return;
    }

    batch.results[index] = result;
    batch.remaining -= 1;
    this.finish(batch);
  }

  private finish(batch: BatchState): void {
    if (batch.done || batch.remaining > 0) {
      return;
    }

    batch.done = true;
    batch.resolve(batch.results as Array<RawConvertResult | FixResult>);
  }

  private async recycle(slot: WorkerSlot, batch: BatchState): Promise<void> {
    const child = slot.child;
    if (!child || slot.recycling) {
      return;
    }

    slot.recycling = true;
    slot.recyclingBatch = batch;
    this.emit({
      type: 'recycle',
      childId: child.pid,
      count: batch.items.length - batch.remaining,
    });
    await stopChild(child);

    slot.child = null;
    slot.processed = 0;
    slot.recycling = false;
    slot.recyclingBatch = null;

    if (!this.stopped && !batch.done) {
      this.spawn(slot);
      this.dispatch(batch);
    }
  }

  private emitSentinel(): void {
    this.sentinelCount += 1;
    this.emit({ type: 'sentinel', count: this.sentinelCount });
  }

  private emit(event: {
    type: PoolEventType;
    childId?: number;
    count?: number;
  }): void {
    this.eventCount += 1;
    this.onEvent?.({
      ...event,
      count: event.count ?? this.eventCount,
    });
  }
}

export const createWorker: CreateWorker = (opts?: WorkerPoolOptions) => {
  return new WorkerPoolImpl(opts);
};
