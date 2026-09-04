import { fork, type ChildProcess } from 'node:child_process';
import { once } from 'node:events';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import cases from '../__fixtures__/cases.json' with { type: 'json' };
import type { ChildToParentMessage, ParentToChildMessage } from '../pool/protocol';
import type { FixResult } from '../pool/types';

type RawConvertFixture = {
  id: string;
  op: 'rawConvert';
  input: string;
  expected: { html: string | null; wpHtmlResidue: number };
};

type CanonicalizeFixture = {
  id: string;
  op: 'canonicalize';
  input: string;
  expected: { html: string; changed: boolean; fixedIssues: string[] };
};

export type Fixture = RawConvertFixture | CanonicalizeFixture;

const here = dirname(fileURLToPath(import.meta.url));
const packageRoot = resolve(here, '..', '..');
const workerChildPath = resolve(here, '..', 'wp', 'worker-child.ts');

const TEST_TIMEOUT_MS = 15_000;

export function fixturesFor(op: 'rawConvert'): RawConvertFixture[];
export function fixturesFor(op: 'canonicalize'): CanonicalizeFixture[];
export function fixturesFor(op: Fixture['op']): Fixture[] {
  return (cases as Fixture[]).filter((fixture) => fixture.op === op);
}

export function fixtureFor(op: 'rawConvert', index?: number): RawConvertFixture;
export function fixtureFor(op: 'canonicalize', index?: number): CanonicalizeFixture;
export function fixtureFor(op: Fixture['op'], index = 0): Fixture {
  const matches = (cases as Fixture[]).filter((fixture) => fixture.op === op);
  const fixture = matches[index];
  if (!fixture) {
    throw new Error(`Missing fixture ${op}[${index}]`);
  }
  return fixture;
}

export function delayedInput(input: string, delayMs = 150): string {
  return `<!-- BLOCKS_ENGINE_TEST_DELAY:${delayMs} -->${input}`;
}

export function crashOnceInput(input: string): string {
  return `<!-- BLOCKS_ENGINE_TEST_CRASH_ONCE -->${input}`;
}

export function crashAlwaysInput(input: string): string {
  return `<!-- BLOCKS_ENGINE_TEST_CRASH_ALWAYS -->${input}`;
}

export function hangInput(input: string): string {
  return `<!-- BLOCKS_ENGINE_TEST_HANG -->${input}`;
}

export function canonicalizeSentinelFor(html: string): FixResult {
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

export function testWorkerEnv(env: NodeJS.ProcessEnv = {}): NodeJS.ProcessEnv {
  return {
    ...env,
    BLOCKS_ENGINE_WORKER_TEST_MODE: '1',
  };
}

export function forkTestWorker(env: NodeJS.ProcessEnv = {}): ChildProcess {
  return fork(workerChildPath, [], {
    cwd: packageRoot,
    env: {
      ...process.env,
      ...env,
    },
    execArgv: ['--import', 'tsx'],
    stdio: ['ignore', 'ignore', 'ignore', 'ipc'],
  });
}

export async function sendWorkerMessage(
  child: ChildProcess,
  message: ParentToChildMessage,
): Promise<ChildToParentMessage> {
  child.send(message);

  return withTimeout(
    Promise.race([
      once(child, 'message').then(([response]) => response as ChildToParentMessage),
      once(child, 'exit').then(([code, signal]) => {
        throw new Error(`Worker exited before response code=${code} signal=${signal}`);
      }),
    ]),
  );
}

export async function cleanupChild(child: ChildProcess): Promise<void> {
  if (child.exitCode !== null || child.signalCode !== null) {
    return;
  }

  const exited = once(child, 'exit').then(() => undefined);
  child.kill('SIGTERM');

  try {
    await withTimeout(exited, 2_000);
  } catch {
    child.kill('SIGKILL');
    await withTimeout(exited, 2_000).catch(() => undefined);
  }
}

export function pidIsAlive(pid: number): boolean {
  try {
    process.kill(pid, 0);
    return true;
  } catch {
    return false;
  }
}

export async function withTimeout<T>(
  promise: Promise<T>,
  timeoutMs = TEST_TIMEOUT_MS,
): Promise<T> {
  let timeout: ReturnType<typeof setTimeout> | undefined;
  try {
    return await Promise.race([
      promise,
      new Promise<T>((_resolve, reject) => {
        timeout = setTimeout(
          () => reject(new Error(`Timed out after ${timeoutMs}ms`)),
          timeoutMs,
        );
      }),
    ]);
  } finally {
    if (timeout) {
      clearTimeout(timeout);
    }
  }
}
