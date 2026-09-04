import type {
  NativeRenderCtx,
  NativeSectionDecision,
  SectionRenderOptions,
  SectionStrategy,
  StrategyState,
} from './native-reconstruct-types.js';
import { preserveDomStrategy } from './preserve-dom/strategy.js';
import { classifySemanticStrategy } from './reconstruct.js';
import type { SectionSpec } from './section-spec.js';
import { sectionCssRichness } from './source-css-signal.js';

type SourceIdentitySection = SectionSpec & {
  sourceId?: string;
  sourceClasses?: string[];
};

export interface RichCssRoutingOptions {
  /** Carried source CSS used to decide, per section, whether it is "rich" (worth preserving). */
  carriedCss: string;
  /** Strategy used for non-rich sections (and as the safety fallback). Defaults to the semantic classifier. */
  native?: SectionStrategy;
}

/**
 * Per-section routing: a section is routed through the class-preserving preserve-dom strategy
 * ONLY when the carried CSS actually targets that section's source identity (sectionCssRichness)
 * AND preserve-dom emits it cleanly. Otherwise it falls back to the native classifier. This is
 * the consumer that activates slice 4's richness signal and slice 5's preserve-dom recursion:
 * rich sections keep their source classes (so carried CSS styles them), while plain sections stay
 * on the canonical native path. The safety fallback (clean-emission check) prevents preserve-dom's
 * downgrade-to-empty from silently dropping content — a lost preserve-dom decision yields to native.
 *
 * drainDedup forwards preserve-dom's content-addressed instance CSS (lib-i...) so the caller can
 * merge it into style.css; when no section routed through preserve-dom it is empty.
 */
export function createRichCssRoutingStrategy(opts: RichCssRoutingOptions): SectionStrategy {
  const native = opts.native ?? classifySemanticStrategy;
  const carriedCss = opts.carriedCss;

  return {
    name: 'rich-css-routing',
    render(
      section: SectionSpec,
      options: SectionRenderOptions,
      ctx: NativeRenderCtx,
      state: StrategyState
    ): NativeSectionDecision | null {
      const source = section as SourceIdentitySection;
      const signal = sectionCssRichness(carriedCss, source.sourceClasses ?? [], source.sourceId);
      if (signal.rich) {
        const preserved = preserveDomStrategy.render(section, options, ctx, state);
        // Only accept preserve-dom output when it emitted cleanly; a lost decision (it dropped
        // content) must not ship — fall back to the native path instead.
        if (preserved && !preserved.coverage.lost) {
          return preserved;
        }
      }
      return native.render(section, options, ctx, state);
    },
    drainDedup(state: StrategyState) {
      return preserveDomStrategy.drainDedup?.(state) ?? { cssRules: [] };
    },
  };
}
