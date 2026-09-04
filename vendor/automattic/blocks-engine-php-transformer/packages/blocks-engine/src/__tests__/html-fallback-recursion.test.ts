import { describe, expect, it } from 'vitest';

import { compose } from '../compose';

const ctx = { url: 'https://x.test/' };

describe('compose htmlFallback seam', () => {
  it('threads a custom fallback into unmatched siblings after a matched wrapper', () => {
    const marker = (html: string) =>
      `<!-- wp:html {"metadata":{"name":"X"}} -->${html}<!-- /wp:html -->`;

    const out = compose(
      '<div class="callout"><p>hi</p></div><weird-el>z</weird-el>',
      ctx,
      { htmlFallback: marker },
    );

    expect(out).toContain('<!-- wp:group');
    expect(out).toContain('<!-- wp:html {"metadata":{"name":"X"}} --><weird-el>z</weird-el><!-- /wp:html -->');
    expect(out).not.toContain('lib-coverage-island');
  });
});
