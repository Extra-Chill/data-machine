import { bootstrap } from './bootstrap.js';
import { canonicalize } from './canonicalize.js';
import { rawConvert } from './raw-convert.js';
import type {
  ChildToParentMessage,
  ParentToChildMessage,
  WorkerError,
} from '../pool/protocol.js';

const POISON_EXIT_CODE = 86;
const BOOTSTRAP_FAIL_EXIT_CODE = 87;

let bootstrapped = false;
let hangRef: ReturnType<typeof setInterval> | undefined;

function testMode(): boolean {
  return process.env.BLOCKS_ENGINE_WORKER_TEST_MODE === '1';
}

function serializeError(error: unknown): WorkerError {
  if (error instanceof Error) {
    return {
      message: error.message,
      name: error.name,
      stack: error.stack,
    };
  }
  return { message: String(error) };
}

function delay(ms: number): Promise<void> {
  return new Promise((resolveDelay) => setTimeout(resolveDelay, ms));
}

function preparePayload(payload: string): {
  payload: string;
  delayMs: number;
  crash: boolean;
  hang: boolean;
} {
  if (!testMode()) {
    return { payload, delayMs: 0, crash: false, hang: false };
  }

  let nextPayload = payload;
  let delayMs = 0;
  let crash = false;
  let hang = false;
  let changed = true;

  while (changed) {
    changed = false;

    const delayMatch = nextPayload.match(/^<!-- BLOCKS_ENGINE_TEST_DELAY:(\d+) -->/);
    if (delayMatch) {
      delayMs = Number(delayMatch[1]);
      nextPayload = nextPayload.slice(delayMatch[0].length);
      changed = true;
      continue;
    }

    if (nextPayload.startsWith('<!-- BLOCKS_ENGINE_TEST_CRASH_ONCE -->')) {
      crash = true;
      nextPayload = nextPayload.slice('<!-- BLOCKS_ENGINE_TEST_CRASH_ONCE -->'.length);
      changed = true;
      continue;
    }

    if (nextPayload.startsWith('<!-- BLOCKS_ENGINE_TEST_CRASH_ALWAYS -->')) {
      crash = true;
      nextPayload = nextPayload.slice('<!-- BLOCKS_ENGINE_TEST_CRASH_ALWAYS -->'.length);
      changed = true;
      continue;
    }

    if (nextPayload.startsWith('<!-- BLOCKS_ENGINE_TEST_HANG -->')) {
      hang = true;
      nextPayload = nextPayload.slice('<!-- BLOCKS_ENGINE_TEST_HANG -->'.length);
      changed = true;
    }
  }

  return { payload: nextPayload, delayMs, crash, hang };
}

function send(message: ChildToParentMessage): void {
  process.send?.(message);
}

process.on('message', async (message: ParentToChildMessage) => {
  const { id, op } = message;

  try {
    if (testMode() && process.env.BLOCKS_ENGINE_FORCE_BOOTSTRAP_FAIL === '1') {
      process.exit(BOOTSTRAP_FAIL_EXIT_CODE);
    }

    const prepared = preparePayload(message.payload);
    if (prepared.crash) {
      process.exit(POISON_EXIT_CODE);
    }
    if (prepared.hang) {
      hangRef ??= setInterval(() => {}, 1_000);
      return;
    }
    if (prepared.delayMs > 0) {
      await delay(prepared.delayMs);
    }

    if (!bootstrapped) {
      bootstrap();
      bootstrapped = true;
    }

    if (op === 'rawConvert') {
      send({ id, result: rawConvert(prepared.payload) });
      return;
    }

    if (op === 'canonicalize') {
      send({ id, result: canonicalize(prepared.payload) });
      return;
    }

    send({ id, error: { message: `Unknown worker op: ${String(op)}` } });
  } catch (error) {
    send({ id, error: serializeError(error) });
  }
});
