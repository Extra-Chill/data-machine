import type { WorkerPool } from '../pool/types.js';
import type { ConversionFinding, ConvertReportStatus } from '../report/schema.js';
import type { RegionSelectionReport } from './region-audit.js';
import type { SectionSpec } from './section-spec.js';
import type { FormRemainder, SectionRenderOptions } from './native-reconstruct-types.js';
import type { SourceCssCarryOptions } from './source-css-carry.js';

export interface SiteModel {
  root: string;
  pages: SitePage[];
}

export interface SitePage {
  relPath: string;
  slug: string;
  html: string;
  title: string;
  bodyData?: Record<string, string>;
}

export interface FoundationTokens {
  palette: { name: string; color: string }[];
  typography: { body?: string; display?: string };
  breakpoints: { md?: string; lg?: string; xl?: string };
}

export interface FoundationAggregates {
  palette?: unknown;
  typography?: unknown;
  breakpoints?: unknown;
}

export interface AssetFile {
  relPath: string;
  bytes?: Uint8Array;
  sourcePath?: string;
}

export interface ThemeModel {
  styleCss: string;
  themeJson: Record<string, unknown>;
  templates: Record<string, string>;
  parts: Record<string, string>;
  patterns: Record<string, string>;
  styleBlocks?: Record<string, Record<string, unknown>>;
  assets: AssetFile[];
  /**
   * PHP bootstrap (functions.php) that enqueues the theme's own style.css on the
   * front end. Block themes do not auto-enqueue style.css, so when the design is
   * carried in style.css (source CSS / @font-face) rather than theme.json styles,
   * this is required or the front end renders unstyled. Undefined when style.css
   * carries no front-end CSS beyond the header (theme.json styles drive the design).
   */
  functionsPhp?: string;
}

export interface SectionBlocks {
  spec: SectionSpec;
  blocks: string;
  coverage: number;
  remainder?: FormRemainder;
}

export interface AssetInventory {
  assets: AssetFile[];
}

export interface AssetVerdicts {
  keep: string[];
  decoration: string[];
}

export interface StageCtx {
  srcDir: string;
  site: SiteModel;
  themeMeta: ThemeMeta;
  warn(msg: string): void;
}

export interface ThemeMeta {
  name: string;
  slug: string;
  author?: string;
}

export interface ThemeConversionPageDiagnostic {
  slug: string;
  status: ConvertReportStatus;
  fallbackCount: number;
  degraded: boolean;
  findings: ConversionFinding[];
  findingsTruncated: boolean;
}

export interface ThemeConversionDiagnosticGroup {
  fingerprint: string;
  repairBucket: string;
  code: string;
  occurrenceCount: number;
  affectedSourceCount: number;
  sourceExamples: string[];
  sharedShell: boolean;
  truncated: boolean;
}

export interface ThemeConversionDiagnostics {
  pages: ThemeConversionPageDiagnostic[];
  groups: ThemeConversionDiagnosticGroup[];
  occurrenceCount: number;
  repairFamilyCount: number;
  repairFamilyCountTruncated: boolean;
  unrepresentedFallbackOccurrenceCount: number;
  unrepresentedFallbackDistinctCount: number;
  totalFallbacks: number;
  pagesWithFallbacks: number;
  degradedPages: number;
}

export interface SiteToThemeHooks {
  onFoundation?(tokens: FoundationTokens, ctx: StageCtx): Promise<FoundationTokens>;
  onSection?(section: SectionBlocks, ctx: StageCtx): Promise<SectionBlocks>;
  onAssets?(inventory: AssetInventory, ctx: StageCtx): Promise<AssetVerdicts>;
  onRefine?(theme: ThemeModel, ctx: StageCtx): Promise<ThemeModel>;
}

export interface SiteToThemeOptions extends SourceCssCarryOptions {
  outDir?: string;
  pool?: WorkerPool;
  sections?: Record<string, SectionSpec[]>;
  renderOptions?: Record<string, SectionRenderOptions>;
  variationHoist?: false;
  foundationAggregates?: FoundationAggregates;
  hooks?: SiteToThemeHooks;
  fetchImpl?: typeof fetch;
  /**
   * Hostname → resolved-IP lookup used to guard the DEFAULT remote-image fetch path
   * against SSRF (a public hostname resolving to an internal IP). Defaults to node:dns;
   * injectable so SSRF/remote-image tests stay hermetic (no real DNS). Ignored when an
   * explicit fetchImpl is provided (that impl owns its own transport).
   */
  imageHostLookup?: (host: string) => Promise<Array<{ address: string; family: number }>>;
  /**
   * Opt-in: route sections whose source identity is targeted by the carried CSS through the
   * class-preserving preserve-dom strategy (editable blocks that keep source classes). Off by
   * default — the default reconstruction is the convert-or-island hybrid (clean canonical blocks
   * where rawConvert is clean, faithful verbatim islands otherwise), which restores the fidelity
   * lost when native canonical-block reconstruction (commit 86f39fd1) replaced the island path.
   */
  routeRichSections?: boolean;
  coverageFloor?: number;
  themeMeta?: Partial<ThemeMeta>;
}

export interface ThemeDiagnostics {
  /**
   * Per-page conversion-fidelity aggregate. `siteToTheme` always populates this;
   * it is declared optional so adding it stays backward-compatible for external
   * code that constructs a `ThemeBuildResult`/`ThemeDiagnostics` literal (e.g.
   * downstream test fixtures) without forcing a change there.
   */
  conversion?: ThemeConversionDiagnostics;
  regionAudit: RegionSelectionReport[];
}

export interface ThemeBuildResult {
  outDir: string;
  model: ThemeModel;
  written: string[];
  tallies: Record<string, number>;
  warnings: string[];
  diagnostics: ThemeDiagnostics;
}
