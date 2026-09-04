import { describe, expect, it } from 'vitest';

import { isRawConvertible, UNWRAP_SELECTOR } from '../raw-convertible';

describe('isRawConvertible', () => {
  it('is true for a flat list of semantic blocks wrapped in layout containers', () => {
    const html = '<main><div class="wp-block-group">' +
      '<div class="wp-block-spacer" style="height:40px"></div>' +
      '<h1 class="wp-block-post-title">Fictional Title</h1>' +
      '<p>Body copy about nothing in particular.</p>' +
      '<figure class="wp-block-table"><table><tr><td>x</td></tr></table></figure>' +
      '</div></main>';
    expect(isRawConvertible(html)).toBe(true);
  });

  it('is true for a heading and figure hero with spacers ignored', () => {
    const html = '<div class="wp-block-spacer" style="height:30px"></div>' +
      '<h1>Stacked Hero</h1>' +
      '<div class="wp-block-spacer" style="height:30px"></div>' +
      '<figure class="wp-block-image"><img src="hero.jpg"/></figure>';
    expect(isRawConvertible(html)).toBe(true);
  });

  it('is false for positioned div-soup with no semantic structure', () => {
    const html = '<div class="comp-abc"><div class="comp-def"><div class="comp-ghi">' +
      '<span>Label</span></div></div><div class="comp-jkl"><span>Other</span></div></div>';
    expect(isRawConvertible(html)).toBe(false);
  });

  it('is false for empty input', () => {
    expect(isRawConvertible('')).toBe(false);
  });

  it('is false at ratio 0.5 and true at ratio 0.6', () => {
    expect(isRawConvertible(
      '<p>First paragraph.</p><p>Second paragraph.</p><div class="box"><span>x</span></div><div class="box"><span>y</span></div>',
    )).toBe(false);
    expect(isRawConvertible(
      '<h2>Heading</h2><p>First paragraph.</p><p>Second paragraph.</p><div class="box"><span>x</span></div><div class="box"><span>y</span></div>',
    )).toBe(true);
  });

  it('unwraps nested layout wrappers', () => {
    const html =
      '<main><div class="wp-block-group"><div class="wp-block-post-content">' +
      '<h1>Title</h1><p>copy</p>' +
      '</div></div></main>';
    expect(isRawConvertible(html)).toBe(true);
  });

  it('exports the shared unwrap selector used by rawConvert', () => {
    expect(UNWRAP_SELECTOR).toBe(
      'main, div.wp-block-group, div.wp-block-post-content, div.entry-content, div.wp-block-group__inner-container',
    );
  });
});
