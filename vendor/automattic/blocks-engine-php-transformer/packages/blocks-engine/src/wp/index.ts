import { compose } from '../compose.js';
import type { RawConvertResult } from '../pool/types.js';
import type { ConversionContext } from '../types.js';
import { canonicalize } from './canonicalize.js';
import { rawConvert } from './raw-convert.js';

export { bootstrap } from './bootstrap.js';
export { canonicalize } from './canonicalize.js';
export type { CanonicalizeResult } from './canonicalize.js';
export { rawConvert } from './raw-convert.js';
export type { RawConvertResult } from '../pool/types.js';

export type ConvertContext = Partial<ConversionContext>;

export function convert(html: string, ctx?: ConvertContext): string {
  const raw: RawConvertResult = rawConvert(html);
  const conversionCtx: ConversionContext = {
    url: ctx?.url ?? '',
    ...(ctx?.mediaMap ? { mediaMap: ctx.mediaMap } : {}),
  };
  const blockMarkup =
    raw.html !== null && raw.wpHtmlResidue === 0
      ? raw.html
      : compose(html, conversionCtx, {});
  return canonicalize(blockMarkup).html;
}
