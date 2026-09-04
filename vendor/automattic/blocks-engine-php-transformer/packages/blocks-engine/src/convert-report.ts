import { performance } from 'node:perf_hooks';

import { compose } from './compose.js';
import { createWorker } from './pool/pool.js';
import { buildReport } from './report/findings.js';
import type { ConvertReport } from './report/schema.js';
import type { ConvertOptions } from './convert.js';
import type { ConversionContext } from './types.js';

function normalizeContext(ctx?: Partial<ConversionContext>): ConversionContext {
  return ctx?.mediaMap === undefined
    ? { url: ctx?.url ?? '' }
    : { url: ctx?.url ?? '', mediaMap: ctx.mediaMap };
}

export async function convertReport(
  html: string,
  ctx?: Partial<ConversionContext>,
  opts?: ConvertOptions,
): Promise<ConvertReport> {
  const fullCtx = normalizeContext(ctx);
  const injectedPool = opts?.pool;
  const ownsPool = injectedPool === undefined;
  const pool = injectedPool ?? createWorker();
  const startedAt = performance.now();

  try {
    const [raw] = await pool.rawConvert([html]);
    const rawConversionDegraded = raw.html === null && !Number.isFinite(raw.wpHtmlResidue);
    const blockMarkup =
      raw.html !== null && raw.wpHtmlResidue === 0
        ? raw.html
        : compose(html, fullCtx, {
            converters: opts?.converters,
            htmlFallback: opts?.htmlFallback,
          });
    const [fixed] = await pool.canonicalize([blockMarkup]);
    const fixResult = rawConversionDegraded ? { ...fixed, degraded: true } : fixed;
    const transformDurationMs = performance.now() - startedAt;

    return buildReport({
      inputHtml: html,
      blockMarkup: fixResult.html,
      fixResult,
      transformDurationMs,
    });
  } finally {
    if (ownsPool) {
      await pool.stop();
    }
  }
}
