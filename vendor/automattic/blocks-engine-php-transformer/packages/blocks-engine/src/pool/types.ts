import type { HtmlIslandOccurrence } from '../wp/canonicalize.js';
import type { PoolEvent } from './events.js';

export interface RawConvertResult {
  html: string | null;
  wpHtmlResidue: number;
}

export interface HtmlIsland {
  index: number;
  html: string;
}

export interface FixResult {
  html: string;
  changed: boolean;
  fixedIssues: string[];
  blockCount: number;
  htmlIslands: HtmlIsland[];
  htmlIslandCount: number;
  htmlIslandOccurrences?: HtmlIslandOccurrence[];
  htmlIslandDistinctCount?: number;
  htmlIslandOccurrencesTruncated?: boolean;
  degraded: boolean;
}

export interface WorkerPoolOptions {
  size?: number;
  recycleAfter?: number;
  maxReroutes?: number;
  itemTimeoutMs?: number;
  onEvent?: (e: PoolEvent) => void;
}

export interface WorkerPool {
  rawConvert(items: string[]): Promise<RawConvertResult[]>;
  canonicalize(items: string[]): Promise<FixResult[]>;
  stop(): Promise<void>;
}

export type CreateWorker = (opts?: WorkerPoolOptions) => WorkerPool;
