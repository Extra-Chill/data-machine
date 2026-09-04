export { convert } from './convert.js';
export { convertReport } from './convert-report.js';
export { compose } from './compose.js';
export { createWorker } from './pool/pool.js';
export { BlocksEngineError } from './errors.js';
export { analyzeRuntimeRegionEffects } from './runtime/region-effect-manifest.js';
export { lintThemeJson, siteToTheme, writeTheme } from './theme/index.js';

export type { ConvertOptions } from './convert.js';
export type { BlocksEngineErrorOptions } from './errors.js';
export type {
  ConversionFinding,
  ConversionFindingCode,
  ConversionFindingSeverity,
  ConversionMetrics,
  ConvertReport,
  ConvertReportStatus,
} from './report/schema.js';
export type { SiteToThemeOptions, ThemeBuildResult, ThemeJsonLintResult } from './theme/index.js';
export type { ConversionContext, Converter, HtmlFallback } from './types.js';
export type { RegionEffectManifest, RuntimeEffectUnit } from './runtime/region-effect-manifest.js';
export type {
  CreateWorker,
  FixResult,
  RawConvertResult,
  WorkerPool,
  WorkerPoolOptions,
} from './pool/types.js';
export type { PoolEvent } from './pool/events.js';
