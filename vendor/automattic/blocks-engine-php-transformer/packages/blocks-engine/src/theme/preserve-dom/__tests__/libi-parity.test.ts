import { describe, expect, it } from 'vitest';

import { preserveDomStrategy, reconstructNativeAggregate } from '../../index.js';
import type { SectionSpec } from '../../section-spec.js';

const LIB_I_CORE_HTML =
  '<section id="hero" class="hero cover"><h1 class="display" style="margin:20px 0 0;font-size:clamp(3rem,9vw,6.5rem)">Scent</h1><p class="lead" style="max-width:46ch">Made to order.</p></section>';

const DLA_CAPTURED_MARKUP =
  '<!-- wp:group {"anchor":"hero","tagName":"section","align":"full","className":"hero cover"} -->\n' +
  '<section id="hero" class="wp-block-group alignfull hero cover"><!-- wp:heading {"level":1,"className":"display lib-i513454c2bb"} -->\n' +
  '<h1 class="wp-block-heading display lib-i513454c2bb">Scent</h1>\n' +
  '<!-- /wp:heading -->\n' +
  '<!-- wp:paragraph {"className":"lead lib-i91a84cc172"} -->\n' +
  '<p class="lead lib-i91a84cc172">Made to order.</p>\n' +
  '<!-- /wp:paragraph --></section>\n' +
  '<!-- /wp:group -->';

const DLA_CAPTURED_CSS =
  '.lib-i513454c2bb{margin:20px 0 0;font-size:clamp(3rem,9vw,6.5rem)}\n' +
  '.lib-i91a84cc172{max-width:46ch}';

function sectionSpec(overrides: Partial<SectionSpec> = {}): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 0,
    headings: ['Scent'],
    bodyText: ['Made to order.'],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 1,
    backgroundColor: 'transparent',
    gradient: null,
    gradientSource: null,
    motionProfile: {
      motionClass: 'none',
      signals: [],
      animatedElements: 0,
    },
    dividerAbove: null,
    dividerBelow: null,
    layout: {
      containerWidth: 0,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '0',
    },
    ...overrides,
  };
}

describe('preserveDomStrategy lib-i parity', () => {
  it('emits byte-identical lib-i core markup and css to DLA emitSectionBlocks', () => {
    const aggregate = reconstructNativeAggregate(
      [
        sectionSpec({
          sectionHtml: LIB_I_CORE_HTML,
        }),
      ],
      { strategy: preserveDomStrategy },
    );

    const engineMarkup = aggregate.sectionMarkup[0];
    const engineCss = aggregate.dedup?.cssRules?.join('\n') ?? '';

    expect(engineMarkup).toBe(DLA_CAPTURED_MARKUP);
    expect(engineCss).toBe(DLA_CAPTURED_CSS);
    console.info('P3-S3 lib-i DLA parity identical=true');
  });
});
