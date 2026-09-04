import { describe, expect, expectTypeOf, it } from 'vitest';
import {
  createWorker,
  type FixResult,
  type RawConvertResult,
  type WorkerPool,
} from '../pool/pool';
import type { PoolEvent } from '../pool/events';
import type { ChildToParentMessage, ParentToChildMessage } from '../pool/protocol';

describe('worker pool contract', () => {
  it('exports the createWorker public surface', () => {
    const pool = createWorker({
      size: 1,
      recycleAfter: 3,
      maxReroutes: 2,
      itemTimeoutMs: 50,
      onEvent: (event) => {
        expect(typeof event.type).toBe('string');
      },
    });

    expect(typeof pool.rawConvert).toBe('function');
    expect(typeof pool.canonicalize).toBe('function');
    expect(typeof pool.stop).toBe('function');
  });

  it('keeps the public and IPC types frozen', () => {
    expectTypeOf(createWorker).toEqualTypeOf<
      (opts?: {
        size?: number;
        recycleAfter?: number;
        maxReroutes?: number;
        itemTimeoutMs?: number;
        onEvent?: (e: PoolEvent) => void;
      }) => WorkerPool
    >();

    expectTypeOf<RawConvertResult>().toEqualTypeOf<{
      html: string | null;
      wpHtmlResidue: number;
    }>();

    expectTypeOf<FixResult>().toEqualTypeOf<{
      html: string;
      changed: boolean;
      fixedIssues: string[];
      blockCount: number;
      htmlIslands: {
        index: number;
        html: string;
      }[];
      htmlIslandCount: number;
      htmlIslandOccurrences?: {
        fingerprint: string;
        html: string;
        count: number;
      }[];
      htmlIslandDistinctCount?: number;
      htmlIslandOccurrencesTruncated?: boolean;
      degraded: boolean;
    }>();

    expectTypeOf<PoolEvent>().toEqualTypeOf<{
      type:
        | 'child-spawn'
        | 'child-crash'
        | 're-route'
        | 'recycle'
        | 'sentinel'
        | 'pool-degraded';
      childId?: number;
      count?: number;
    }>();

    expectTypeOf<ParentToChildMessage>().toEqualTypeOf<{
      id: number;
      op: 'rawConvert' | 'canonicalize';
      payload: string;
    }>();

    expectTypeOf<ChildToParentMessage>().toEqualTypeOf<
      | {
          id: number;
          result: RawConvertResult | FixResult;
        }
      | {
          id: number;
          error: {
            message: string;
            name?: string;
            stack?: string;
          };
        }
    >();
  });
});
