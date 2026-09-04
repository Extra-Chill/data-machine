import { convertReport } from './convert-report.js';
import type { WorkerPool } from './pool/types.js';
import type { ConversionContext, Converter, HtmlFallback } from './types.js';

export interface ConvertOptions {
  pool?: WorkerPool;
  converters?: Converter[];
  htmlFallback?: HtmlFallback;
}

export async function convert(
  html: string,
  ctx?: Partial<ConversionContext>,
  opts?: ConvertOptions,
): Promise<string> {
  return (await convertReport(html, ctx, opts)).blockMarkup;
}
