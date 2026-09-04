import { describe, expect, it, vi } from 'vitest';

vi.mock('node:child_process', async () => {
  const actual = await vi.importActual<typeof import('node:child_process')>(
    'node:child_process',
  );
  return {
    ...actual,
    fork: vi.fn(actual.fork),
  };
});

import { fork } from 'node:child_process';

import { rawConvert } from '../wp';
import { fixtureFor } from './pool-test-helpers';

describe('one-shot /wp fast path', () => {
  it('runs in-process without forking', () => {
    const fixture = fixtureFor('rawConvert');

    expect(rawConvert(fixture.input)).toEqual(fixture.expected);
    expect(fork).not.toHaveBeenCalled();
  });
});
