import { describe, expect, it } from 'vitest';

import {
  buildHtmlFallbackBlock,
  isWpLayoutMarkup,
  rewriteInternalLinks,
  rewriteMediaUrls,
  sanitize,
  scanForInjection,
  selectIslandSource,
  type InternalLinkMap,
  type IslandTier,
} from '../theme/index.js';

const COVERAGE_ISLAND_OPENER =
  '<!-- wp:html {"metadata":{"name":"lib-coverage-island"}} -->';
const COVERAGE_ISLAND_CLOSER = '<!-- /wp:html -->';

describe('theme html fallback public surface', () => {
  it('freezes the additive A3 named exports', () => {
    const linkMap: InternalLinkMap = new Map([['/about', '/about/']]);
    const tier: IslandTier = selectIslandSource({ sectionHtml: '<section></section>' }).tier;

    expect(typeof sanitize).toBe('function');
    expect(typeof isWpLayoutMarkup).toBe('function');
    expect(typeof selectIslandSource).toBe('function');
    expect(typeof buildHtmlFallbackBlock).toBe('function');
    expect(typeof rewriteMediaUrls).toBe('function');
    expect(typeof rewriteInternalLinks).toBe('function');
    expect(typeof scanForInjection).toBe('function');
    expect(linkMap.get('/about')).toBe('/about/');
    expect(['responsive', 'styled', 'verbatim']).toContain(tier);
  });

  it('sanitizes active content while preserving inert source markup', () => {
    const dirty =
      '<section onclick="evil()" data-ok="1"><script>alert("x")</script><style>.x{color:red}</style><?php echo "x"; ?><? short ?><!-- wp:paragraph --><img src="hero.jpg" onerror=\'boom()\' onload=init alt="Hero"><a href="/shop" onmouseover="track()">Shop</a><p>Keep</p></section>';

    expect(sanitize(dirty)).toBe(
      '<section data-ok="1"><img src="hero.jpg" alt="Hero"><a href="/shop">Shop</a><p>Keep</p></section>',
    );
  });

  it('classifies WordPress layout markup by the DLA marker set', () => {
    expect(
      [
        '<section class="is-layout-constrained"></section>',
        '<div class="is-layout-flow"></div>',
        '<nav class="is-layout-flex"></nav>',
        '<div class="wp-block-group"></div>',
        '<main class="has-global-padding"></main>',
      ].map(isWpLayoutMarkup),
    ).toEqual([true, true, true, true, true]);

    expect(
      [
        '<section class="wixui-section"></section>',
        '<div class="sqs-layout"></div>',
        '<main class="has-padding-global"></main>',
      ].map(isWpLayoutMarkup),
    ).toEqual([false, false, false]);
  });

  it('selects responsive, styled, and verbatim island tiers with concrete sources', () => {
    expect(
      selectIslandSource({
        sectionHtml: '<section class="wp-block-group is-layout-constrained"><p>Responsive</p></section>',
        styledHtml: '<section style="width:1440px"><p>Styled</p></section>',
      }),
    ).toEqual({
      source: '<section class="wp-block-group is-layout-constrained"><p>Responsive</p></section>',
      tier: 'responsive',
    });

    expect(
      selectIslandSource({
        sectionHtml: '<section class="wixui-section"><p>Source</p></section>',
        styledHtml: '<section style="width:1440px"><p>Styled</p></section>',
      }),
    ).toEqual({
      source: '<section style="width:1440px"><p>Styled</p></section>',
      tier: 'styled',
    });

    expect(
      selectIslandSource({
        sectionHtml: '<section class="sqs-layout"><p>Verbatim</p></section>',
      }),
    ).toEqual({
      source: '<section class="sqs-layout"><p>Verbatim</p></section>',
      tier: 'verbatim',
    });
  });

  it('builds a sanitized core/html island with media and internal links rewritten', () => {
    const wixBase = 'https://static.wixstatic.com/media/abc123~mv2.jpg';
    const wixSmall =
      `${wixBase}/v1/fill/w_340,h_255,q_90,enc_avif,quality_auto/Studio%20Hero.jpg`;
    const wixLarge =
      `${wixBase}/v1/fill/w_680,h_510,q_90,enc_avif,quality_auto/Studio%20Hero.jpg`;
    const localMedia = '/wp-content/uploads/2026/06/studio-hero.jpg';
    const sectionHtml =
      `<section onclick="evil()" class="hero"><script>alert(1)</script><style>.hero{color:red}</style><!-- wp:paragraph --><img src="${wixLarge}" srcset="${wixSmall} 340w, ${wixLarge} 680w" alt="Studio"><a href="https://Example.test/About.html#team">About</a><a href="/contact?ref=nav#hours">Contact</a><p>Keep</p></section>`;
    const linkMap: InternalLinkMap = new Map([
      ['example.test/about', '/about/'],
      ['/contact', '/contact/'],
    ]);

    expect(
      buildHtmlFallbackBlock(sectionHtml, {
        mediaUrlMap: new Map([[wixBase, localMedia]]),
        linkMap,
      }),
    ).toBe(
      `${COVERAGE_ISLAND_OPENER}\n<section class="hero"><img src="${localMedia}" srcset="${localMedia} 340w, ${localMedia} 680w" alt="Studio"><a href="/about/#team">About</a><a href="/contact/#hours">Contact</a><p>Keep</p></section>\n${COVERAGE_ISLAND_CLOSER}`,
    );
  });

  it('throws when sanitization leaves a quote-adjacent event handler', () => {
    expect(() => buildHtmlFallbackBlock('<img src="x"onerror="alert(1)">')).toThrowError(
      'html-fallback sanitization left injection vectors: inline event handler attribute (on*=) in markup (not allowed)',
    );
  });

  it('rewrites Wix media transforms longest-first without leaving transform tails', () => {
    const wixBase = 'https://static.wixstatic.com/media/11062b_abcd~mv2.jpg';
    const wixSmall =
      `${wixBase}/v1/fill/w_340,h_255,q_90,enc_avif,quality_auto/Cornelius%20Holmes%20(1).jpg`;
    const wixLarge =
      `${wixBase}/v1/fill/w_680,h_510,q_90,enc_avif,quality_auto/Cornelius%20Holmes%20(1).jpg`;
    const localMedia = '/wp-content/uploads/2026/06/cornelius-holmes.jpg';
    const input =
      `<img src="${wixLarge}" srcset="${wixSmall} 340w, ${wixLarge} 680w"><a href="${wixBase}">Download</a>`;

    expect(rewriteMediaUrls(input, new Map([[wixBase, localMedia]]))).toBe(
      `<img src="${localMedia}" srcset="${localMedia} 340w, ${localMedia} 680w"><a href="${localMedia}">Download</a>`,
    );
  });

  it('rewrites internal hrefs with DLA path, host, fragment, and quote semantics', () => {
    const missed: string[] = [];
    const linkMap: InternalLinkMap = new Map([
      ['example.test/contact', '/contact/'],
      ['/about', '/about/'],
      ['/pricing', '/plans/'],
      ['/docs/get-started', '/guides/start/'],
    ]);
    const input =
      '<a href="https://www.Example.test/contact.html?utm=1#team">Contact</a><a href=\'/about/?x=1#story\'>About</a><a href="./pricing.html">Pricing</a><a href="../docs/get-started.htm#install">Docs</a><a href="https://other.test/about.html#x">Other</a><a href="#top">Top</a><a href="mailto:hi@example.test">Mail</a><a href="/missing">Missing</a>';

    expect(
      rewriteInternalLinks(input, linkMap, {
        onMissing: (href) => missed.push(href),
      }),
    ).toBe(
      '<a href="/contact/#team">Contact</a><a href=\'/about/#story\'>About</a><a href="/plans/">Pricing</a><a href="/guides/start/#install">Docs</a><a href="https://other.test/about.html#x">Other</a><a href="#top">Top</a><a href="mailto:hi@example.test">Mail</a><a href="/missing">Missing</a>',
    );
    expect(missed).toEqual(['/missing']);
  });

  it('reports DLA injection vectors and allows sanctioned theme PHP forms', () => {
    expect(
      scanForInjection(
        '<?php echo "bad"; ?><script>run()</script><img src="x"onerror="alert(1)">',
      ),
    ).toEqual([
      'raw PHP tag in markup (only the pattern-header doc-comment and esc_url(get_theme_file_uri()) are allowed)',
      'raw <script> tag in markup (not allowed)',
      'inline event handler attribute (on*=) in markup (not allowed)',
    ]);

    expect(
      scanForInjection(
        "<?php echo esc_url( get_theme_file_uri( 'assets/logo.png' ) ); ?><?php /**\n * Title: Safe Pattern\n */ ?>",
      ),
    ).toEqual([]);
  });
});
