/**
 * Tier-A engine adapter: the deterministic reconstruct core.
 *
 * Calls `reconstructNativeAggregate` (sync, hook-free, network-free) and joins
 * its per-section markup. The aggregate already exposes the deduped expected
 * text/assets, which the scorer's coverage axis consumes directly. Structural
 * validity comes from `validateBlockMarkup` (no Gutenberg boot). No cache — this
 * path is deterministic, so the ratchet runs it at threshold 0.
 */
import { reconstructNativeAggregate } from '../../../src/theme/reconstruct.js';
import type { SectionRenderOptions } from '../../../src/theme/native-reconstruct-types.js';
import type { SectionSpec } from '../../../src/theme/section-spec.js';
import { validateBlockMarkup } from '../../../src/validate-block-markup.js';

export interface RealizeResult {
  output: string;
  valid: boolean;
  expectedText: string[];
  expectedAssets: string[];
}

export interface BenchEngine {
  label: string;
  model: string;
  realize(specs: SectionSpec[], options?: SectionRenderOptions): RealizeResult;
}

export const reconstructEngine: BenchEngine = {
  label: 'reconstruct',
  model: 'deterministic',
  realize(specs, options = {}) {
    const aggregate = reconstructNativeAggregate(specs, options);
    const output = aggregate.sectionMarkup.join('\n\n');
    const valid = validateBlockMarkup(output).length === 0;
    return {
      output,
      valid,
      expectedText: aggregate.expectedText,
      expectedAssets: aggregate.expectedAssets,
    };
  },
};
