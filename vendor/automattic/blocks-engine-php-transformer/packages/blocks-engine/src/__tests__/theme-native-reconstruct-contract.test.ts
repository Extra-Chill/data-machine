import { describe, expect, expectTypeOf, it } from 'vitest';

import type { WorkerPool } from '../pool/types.js';
import {
  reconstruct,
  type SectionBlocks,
  type SectionRenderOptions,
  type SiteToThemeHooks,
  type StageCtx,
} from '../theme/index.js';
import type { FallbackDiagnostic } from '../theme/fallback-diagnostic.js';
import type {
  FormRemainder,
  NativeReconstructAggregate,
  NativeSectionDecision,
  NativeSectionDecisionKind,
  StrategyDedupOutput,
} from '../theme/native-reconstruct-types.js';
import type { CoverageResult } from '../theme/section-coverage.js';
import type { SectionSpec } from '../theme/section-spec.js';

describe('native reconstruct frozen internal contract', () => {
  it('freezes the section decision and aggregate types', () => {
    expect(['converted', 'native', 'fallback']).toEqual([
      'converted',
      'native',
      'fallback',
    ] satisfies NativeSectionDecisionKind[]);

    expectTypeOf<NativeSectionDecision>().toEqualTypeOf<{
      spec: SectionSpec;
      blocks: string;
      coverage: CoverageResult;
      expectedText: string[];
      bodyText: string[];
      expectedAssets: string[];
      provenanceFlags: string[];
      fallbackDiagnostics: FallbackDiagnostic[];
      iconAssets: Array<{ path: string; svg: string }>;
      heroIsCover?: boolean;
      remainder?: FormRemainder;
      decision: NativeSectionDecisionKind;
    }>();

    expectTypeOf<NativeReconstructAggregate>().toEqualTypeOf<{
      sections: NativeSectionDecision[];
      sectionMarkup: string[];
      expectedText: string[];
      bodyText: string[];
      expectedAssets: string[];
      provenanceFlags: string[];
      fallbackDiagnostics: FallbackDiagnostic[];
      iconAssets: Array<{ path: string; svg: string }>;
      heroIsCover: boolean;
      dedup?: StrategyDedupOutput;
    }>();
  });

  it('keeps the public reconstruct hook-stage surface stable', () => {
    expect(reconstruct).toHaveLength(5);
    expectTypeOf(reconstruct).toEqualTypeOf<
      (
        specs: SectionSpec[],
        ctx: StageCtx,
        pool: WorkerPool,
        hooks: SiteToThemeHooks,
        coverageFloor: number,
        renderOptions?: SectionRenderOptions,
      ) => Promise<SectionBlocks[]>
    >();

    expectTypeOf<SectionBlocks>().toEqualTypeOf<{
      spec: SectionSpec;
      blocks: string;
      coverage: number;
      remainder?: FormRemainder;
    }>();
  });
});
