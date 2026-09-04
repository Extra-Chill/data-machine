import { describe, expect, it } from 'vitest';

import { splitPageChrome, type RegionSplit } from '../theme/index.js';

describe('splitPageChrome', () => {
  it('splits shallow top-level header, main, and footer regions', () => {
    const input =
      '<header><nav>Primary</nav></header><main><h1>Welcome</h1><p>Body copy.</p></main><footer><p>Footer copy.</p></footer>';

    const split: RegionSplit = splitPageChrome(input);

    expect(split).toEqual({
      headerHtml: '<header><nav>Primary</nav></header>',
      mainHtml: '<main><h1>Welcome</h1><p>Body copy.</p></main>',
      footerHtml: '<footer><p>Footer copy.</p></footer>',
    });
  });

  it('falls back to the shallow role-based splitter when no semantic tags exist', () => {
    // Note: a banner that holds a leading <nav> would now be intercepted by the
    // nav-routing rule in the deep splitter; this case targets the role-based
    // shallow fallback specifically, so the banner uses a non-nav child.
    const input =
      '<div role="banner"><a href="/">Primary</a></div><main><p>Body copy.</p></main><div role="contentinfo"><p>Footer copy.</p></div>';

    expect(splitPageChrome(input)).toEqual({
      headerHtml: '<div role="banner"><a href="/">Primary</a></div>',
      mainHtml: '<main><p>Body copy.</p></main>',
      footerHtml: '<div role="contentinfo"><p>Footer copy.</p></div>',
    });
  });

  it('finds deep nested chrome while keeping the surrounding page wrapper and content', () => {
    const input =
      '<div class="site"><div class="frame"><header id="SITE_HEADER"><nav>Primary</nav></header><main data-page="home"><section id="hero"><h1>Hero</h1></section><section id="story"><p>Story copy.</p></section></main><footer id="SITE_FOOTER"><p>Footer copy.</p></footer></div></div>';

    const split = splitPageChrome(input);

    expect(split.headerHtml).toBe('<header id="SITE_HEADER"><nav>Primary</nav></header>');
    expect(split.footerHtml).toBe('<footer id="SITE_FOOTER"><p>Footer copy.</p></footer>');
    expect(split.mainHtml).toBe(
      '<div class="site"><div class="frame"><main data-page="home"><section id="hero"><h1>Hero</h1></section><section id="story"><p>Story copy.</p></section></main></div></div>'
    );
    expect(split.mainHtml).toContain('<div class="site"><div class="frame">');
    expect(split.mainHtml).toContain('<section id="hero"><h1>Hero</h1></section>');
    expect(split.mainHtml).toContain('<section id="story"><p>Story copy.</p></section>');
    expect(split.mainHtml).not.toContain('SITE_HEADER');
    expect(split.mainHtml).not.toContain('SITE_FOOTER');
    expect(split.mainHtml).not.toContain('<nav>Primary</nav>');
    expect(split.mainHtml).not.toContain('<p>Footer copy.</p>');
  });

  it('keeps custom elements that start with header inside main content', () => {
    const input =
      '<header><header-drawer>Menu</header-drawer><nav>Primary</nav></header><main><header-menu>Inline menu widget</header-menu><p>Body copy.</p></main><footer>Footer</footer>';

    const split = splitPageChrome(input);

    expect(split.headerHtml).toBe(
      '<header><header-drawer>Menu</header-drawer><nav>Primary</nav></header>'
    );
    expect(split.mainHtml).toBe(
      '<main><header-menu>Inline menu widget</header-menu><p>Body copy.</p></main>'
    );
    expect(split.footerHtml).toBe('<footer>Footer</footer>');
  });

  it('returns header-only chrome with the remaining body as main HTML', () => {
    const input =
      '<div class="site"><header id="SITE_HEADER">Header</header><main><section>Only body</section></main></div>';

    expect(splitPageChrome(input)).toEqual({
      headerHtml: '<header id="SITE_HEADER">Header</header>',
      mainHtml: '<div class="site"><main><section>Only body</section></main></div>',
      footerHtml: '',
    });
  });

  it('passes through body HTML exactly when no chrome is present', () => {
    const input =
      '<div class="content"><section><h2>Plain content</h2><p>No site chrome here.</p></section></div>';

    expect(splitPageChrome(input)).toEqual({
      headerHtml: '',
      mainHtml: input,
      footerHtml: '',
    });
  });
});
