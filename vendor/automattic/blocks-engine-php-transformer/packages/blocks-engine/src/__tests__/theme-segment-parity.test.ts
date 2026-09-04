import { describe, expect, it } from 'vitest';

import * as theme from '../theme/index.js';
import { segmentPage } from '../theme/index.js';

describe('segmentPage DLA parity', () => {
  it('exposes the additive segmentPage public surface from theme/index', () => {
    expect(theme).toEqual(
      expect.objectContaining({
        segmentPage: expect.any(Function),
      })
    );
  });

  it('identifies header, nav, footer, and body sections on a DLA fixture', () => {
    const html =
      '<body><header id="masthead" class="site-head"><nav><a href="x.html">X</a></nav></header><nav id="main-nav" class="primary"><a href="a.html">A</a></nav><main><section id="hero" class="hero splash"><h1>Hi</h1></section><section class="features"><h2>Feat</h2></section></main><footer class="site-foot"><p>(c)</p></footer></body>';

    expect(segmentPage(html)).toEqual([
      {
        id: 'header',
        role: 'header',
        html: '<header id="masthead" class="site-head"><nav><a href="x.html">X</a></nav></header>',
        classes: ['site-head'],
      },
      {
        id: 'nav',
        role: 'nav',
        html: '<nav id="main-nav" class="primary"><a href="a.html">A</a></nav>',
        classes: ['primary'],
      },
      {
        id: 'footer',
        role: 'footer',
        html: '<footer class="site-foot"><p>(c)</p></footer>',
        classes: ['site-foot'],
      },
      {
        id: 'hero',
        role: 'body',
        html: '<section id="hero" class="hero splash"><h1>Hi</h1></section>',
        classes: ['hero', 'splash'],
      },
      {
        id: 'feat',
        role: 'body',
        html: '<section class="features"><h2>Feat</h2></section>',
        classes: ['features'],
      },
    ]);
  });

  it('keeps chrome-vs-body split golden for loose body siblings', () => {
    const html =
      '<body><aside class="badge">New</aside><main><section id="content"><h1>Content</h1></section></main></body>';

    expect(segmentPage(html)).toEqual([
      {
        id: 'badge',
        role: 'body',
        html: '<aside class="badge">New</aside>',
        classes: ['badge'],
      },
      {
        id: 'content',
        role: 'body',
        html: '<section id="content"><h1>Content</h1></section>',
        classes: [],
      },
    ]);
  });

  it('segments a no-chrome page and escapes loose decoded text like DLA', () => {
    const html =
      '<main>Hello &lt;b&gt;bold&lt;/b&gt;<section id="s"><p>S text</p></section><script>var x=1;</script></main>';

    expect(segmentPage(html)).toEqual([
      {
        id: 'section-0545a7d4-0',
        role: 'body',
        html: '<p>Hello &lt;b&gt;bold&lt;/b&gt;</p>',
        classes: [],
      },
      {
        id: 's',
        role: 'body',
        html: '<section id="s"><p>S text</p></section>',
        classes: [],
      },
    ]);
  });

  it('promotes layout rail sidebars with wrapper metadata', () => {
    const html =
      '<body><div class="docs-grid content-shell"><aside class="docs-sidebar"><nav><a href="intro.html">Intro</a><a href="api.html">API</a></nav></aside><main><section id="overview"><h1>Overview</h1></section></main></div></body>';

    expect(segmentPage(html)).toEqual([
      {
        id: 'docs-sidebar',
        role: 'nav',
        chromeSource: 'layout-rail',
        html: '<aside class="docs-sidebar"><nav><a href="intro.html">Intro</a><a href="api.html">API</a></nav></aside>',
        classes: ['docs-sidebar'],
        layoutWrapperTag: 'div',
        layoutWrapperClasses: ['docs-grid', 'content-shell'],
        layoutWrapperRailPosition: 'beforeMain',
      },
      {
        id: 'overview',
        role: 'body',
        html: '<section id="overview"><h1>Overview</h1></section>',
        classes: [],
      },
    ]);
  });

  it('promotes a right rail nested inside an outer content-landmark wrapper', () => {
    // The library/grant-readiness layout: <section class="section"> wraps the
    // whole two-column block. The <aside> is a grid SIBLING of <main> (afterMain),
    // not in-content chrome — the content-landmark-ancestor gate must not drop it.
    const html =
      '<body><section class="section"><div class="container"><div class="sidebar-layout"><main class="article-body"><div class="guide-section"><h1>Guide</h1><p>Body copy that is long enough to be a real section.</p></div></main><aside class="sidebar" aria-label="Table of contents"><nav><a href="#a">One</a><a href="#b">Two</a></nav></aside></div></div></section></body>';

    expect(segmentPage(html)).toEqual([
      {
        id: 'sidebar',
        role: 'nav',
        chromeSource: 'layout-rail',
        html: '<aside class="sidebar" aria-label="Table of contents"><nav><a href="#a">One</a><a href="#b">Two</a></nav></aside>',
        classes: ['sidebar'],
        layoutWrapperTag: 'div',
        layoutWrapperClasses: ['sidebar-layout'],
        layoutWrapperRailPosition: 'afterMain',
      },
      {
        id: 'guide',
        role: 'body',
        html: '<div class="guide-section"><h1>Guide</h1><p>Body copy that is long enough to be a real section.</p></div>',
        classes: ['guide-section'],
      },
    ]);
  });
});
