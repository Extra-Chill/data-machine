import { convert } from '../convert.js';
import type { WorkerPool } from '../pool/types.js';
import type { StageCtx } from './types.js';

export async function buildChromePart(
  html: string,
  ctx: StageCtx,
  pool: WorkerPool
): Promise<string> {
  void ctx;
  return convert(html, { url: '' }, { pool });
}
