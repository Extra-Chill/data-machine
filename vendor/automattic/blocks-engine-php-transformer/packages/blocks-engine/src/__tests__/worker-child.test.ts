import { describe, expect, it } from 'vitest';

import {
  cleanupChild,
  fixtureFor,
  forkTestWorker,
  sendWorkerMessage,
} from './pool-test-helpers';

describe('worker child IPC', () => {
  it('runs rawConvert and canonicalize through a real forked child', async () => {
    const child = forkTestWorker();
    const rawFixture = fixtureFor('rawConvert');
    const canonicalizeFixture = fixtureFor('canonicalize');

    try {
      await expect(
        sendWorkerMessage(child, {
          id: 1,
          op: 'rawConvert',
          payload: rawFixture.input,
        }),
      ).resolves.toEqual({ id: 1, result: rawFixture.expected });

      await expect(
        sendWorkerMessage(child, {
          id: 2,
          op: 'canonicalize',
          payload: canonicalizeFixture.input,
        }),
      ).resolves.toMatchObject({ id: 2, result: canonicalizeFixture.expected });
    } finally {
      await cleanupChild(child);
    }
  }, 20_000);
});
