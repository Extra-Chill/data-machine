import type { FixResult, RawConvertResult } from './types.js';

export type WorkerOp = 'rawConvert' | 'canonicalize';

export type ParentToChildMessage = {
  id: number;
  op: WorkerOp;
  payload: string;
};

export type WorkerError = {
  message: string;
  name?: string;
  stack?: string;
};

export type ChildToParentMessage =
  | {
      id: number;
      result: RawConvertResult | FixResult;
    }
  | {
      id: number;
      error: WorkerError;
    };
