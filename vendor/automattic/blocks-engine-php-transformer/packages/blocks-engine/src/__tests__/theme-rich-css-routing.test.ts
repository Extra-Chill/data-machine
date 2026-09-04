import { describe, expect, it } from 'vitest';

import { classifySemanticStrategy, reconstructNativeAggregate } from '../theme/reconstruct.js';
import { createRichCssRoutingStrategy } from '../theme/routing-strategy.js';
import type { SectionSpec } from '../theme/section-spec.js';

function sectionSpec(overrides: Partial<SectionSpec> = {}): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 0,
    headings: [],
    bodyText: [],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 1,
    backgroundColor: 'transparent',
    gradient: null,
    gradientSource: null,
    motionProfile: { motionClass: 'none', signals: [], animatedElements: 0 },
    dividerAbove: null,
    dividerBelow: null,
    layout: { containerWidth: 0, padding: '0', childLayout: 'stack', columnCount: 1, gap: '0' },
    ...overrides,
  };
}

const richSection = () =>
  sectionSpec({
    sectionIndex: 1,
    headings: ['Our menu'],
    bodyText: ['Oat flat white'],
    sectionHtml:
      '<section class="menu shell"><h2 class="eyebrow">Our menu</h2><p class="lede">Oat flat white</p></section>',
  });

describe('rich-css routing strategy', () => {
  it('routes a section through preserve-dom when carried CSS targets its source classes', () => {
    const carriedCss = '.menu{padding:4rem}.eyebrow{letter-spacing:.1em}';
    const aggregate = reconstructNativeAggregate([richSection()], {
      strategy: createRichCssRoutingStrategy({ carriedCss }),
    });

    const markup = aggregate.sectionMarkup[0] ?? '';
    // preserve-dom keeps the source section + child classes so the carried CSS targets them.
    expect(markup).toContain('"className":"menu shell"');
    expect(markup).toContain('class="wp-block-group alignfull menu shell"');
    expect(markup).toContain('eyebrow');
    expect(markup).toContain('Our menu');
    expect(aggregate.sections[0]?.coverage.lost).toBe(false);
  });

  it('stays on the native path when carried CSS does NOT target the section (no false routing)', () => {
    const carriedCss = '.unrelated{color:red}#other{margin:0}';
    const routed = reconstructNativeAggregate([richSection()], {
      strategy: createRichCssRoutingStrategy({ carriedCss }),
    });
    // The routing strategy's non-rich fallback is the semantic classifier, so the
    // baseline must use that same strategy (not the preserve-dom default).
    const native = reconstructNativeAggregate([richSection()], {
      strategy: classifySemanticStrategy,
    });

    // Not rich → identical to the native classifier output; source section classes are NOT carried.
    expect(routed.sectionMarkup).toEqual(native.sectionMarkup);
    expect(routed.sectionMarkup[0] ?? '').not.toContain('"className":"menu shell"');
  });

  it('drains preserve-dom instance CSS (lib-i) for routed sections', () => {
    const carriedCss = '.card{display:grid}';
    const aggregate = reconstructNativeAggregate(
      [
        sectionSpec({
          sectionIndex: 2,
          bodyText: ['Boxed'],
          sectionHtml:
            '<section class="card"><p class="lede" style="max-width:46ch">Boxed</p></section>',
        }),
      ],
      { strategy: createRichCssRoutingStrategy({ carriedCss }) }
    );

    expect(aggregate.sectionMarkup[0] ?? '').toContain('lib-i');
    expect(aggregate.dedup?.cssRules?.some((rule) => rule.includes('lib-i'))).toBe(true);
  });

  it('falls back to native when preserve-dom would drop content (safety fallback)', () => {
    // A rich section whose body preserve-dom cannot emit cleanly (a table is an unhandled leaf).
    const lossy = sectionSpec({
      sectionIndex: 3,
      bodyText: ['Hours'],
      headings: ['Hours'],
      sectionHtml:
        '<section class="hours"><h2 class="t">Hours</h2><table><tr><td>Mon</td></tr></table></section>',
    });
    const carriedCss = '.hours{padding:2rem}';

    const routed = reconstructNativeAggregate([lossy], {
      strategy: createRichCssRoutingStrategy({ carriedCss }),
    });
    const native = reconstructNativeAggregate([structuredClone(lossy)]);

    // Routing must NOT ship a lost preserve-dom decision; it yields to the native output.
    expect(routed.sectionMarkup).toEqual(native.sectionMarkup);
  });
});
