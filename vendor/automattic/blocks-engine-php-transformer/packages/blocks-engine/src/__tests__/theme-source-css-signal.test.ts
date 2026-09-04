import { describe, expect, it } from 'vitest';

import {
  collectCssSelectorTokens,
  sectionCssRichness,
} from '../theme/source-css-signal.js';

describe('source-css richness signal', () => {
  it('reports rich when carried CSS targets the section source classes', () => {
    const css = '.hero{padding:40px}.menu-grid{display:grid}.menu-card{border:1px solid}';
    const signal = sectionCssRichness(css, ['hero', 'menu-grid'], undefined);
    expect(signal.rich).toBe(true);
    expect(signal.matchedClasses).toEqual(['hero', 'menu-grid']);
    expect(signal.score).toBe(2);
  });

  it('matches a section id selector', () => {
    const css = '#lossy{background:#111}.fallback{color:red}';
    const signal = sectionCssRichness(css, [], 'lossy');
    expect(signal.matchedId).toBe(true);
    expect(signal.rich).toBe(true);
    expect(signal.score).toBe(1);
  });

  it('is NOT rich when the CSS does not target the section identity (the stripped-body case)', () => {
    // Carried CSS targets .hero, but this section only carries .testimonial.
    const css = '.hero{padding:40px}.cta{color:blue}';
    const signal = sectionCssRichness(css, ['testimonial'], 'section-3');
    expect(signal.rich).toBe(false);
    expect(signal.matchedClasses).toEqual([]);
    expect(signal.matchedId).toBe(false);
    expect(signal.score).toBe(0);
  });

  it('does not treat declaration values (#fff colors, decimals) as id/class matches', () => {
    const css = '.real{color:#fff;line-height:1.5;background:#hero}';
    // `#hero` / `5` appear only in declaration VALUES, never as selectors.
    expect(sectionCssRichness(css, ['5'], 'hero').rich).toBe(false);
    expect(sectionCssRichness(css, [], 'fff').matchedId).toBe(false);
    expect(sectionCssRichness(css, ['real'], undefined).rich).toBe(true);
  });

  it('ignores selectors that appear only inside comments', () => {
    const css = '/* .hero used to be styled here */ .other{color:green}';
    const tokens = collectCssSelectorTokens(css);
    expect(tokens.classes.has('hero')).toBe(false);
    expect(tokens.classes.has('other')).toBe(true);
    expect(sectionCssRichness(css, ['hero'], undefined).rich).toBe(false);
  });

  it('returns not-rich for empty or whitespace CSS', () => {
    expect(sectionCssRichness('', ['hero'], 'x').rich).toBe(false);
    expect(sectionCssRichness(undefined, ['hero'], 'x').rich).toBe(false);
    expect(sectionCssRichness('   \n  ', ['hero'], 'x').score).toBe(0);
  });

  it('dedupes and trims source classes before matching', () => {
    const css = '.hero{color:red}';
    const signal = sectionCssRichness(css, [' hero ', 'hero', ''], undefined);
    expect(signal.matchedClasses).toEqual(['hero']);
    expect(signal.score).toBe(1);
  });
});
