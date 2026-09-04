import { describe, expect, it } from 'vitest';

import { blockMarkupRoundtrips } from '../validate.js';
import {
  buildCarriedHeaderPart,
  buildFooterPart,
  buildHeaderPart,
  findChromeMounts,
  mountPartMarkup,
  type ChromePartConverter,
  type ChromePartSection,
  type NavLink,
} from '../theme/index.js';

describe('chrome-parts builders DLA parity', () => {
  it('buildHeaderPart emits DLA-faithful native nav markup with inNav preference', () => {
    const nav: NavLink[] = [
      { fromSlug: 'home', toSlug: 'home', label: 'Brand Co' },
      { fromSlug: 'home', toSlug: 'home', label: 'Home', inNav: true },
      { fromSlug: 'home', toSlug: 'about', label: 'About', inNav: true },
      { fromSlug: 'home', toSlug: 'services', label: 'inline services link' },
    ];

    expect(buildHeaderPart('Brand Co', nav, ['home', 'about', 'services'])).toBe(
      `<!-- wp:group {"align":"full","layout":{"type":"flex","justifyContent":"space-between"},"style":{"spacing":{"padding":{"top":"1rem","bottom":"1rem","left":"1.5rem","right":"1.5rem"}}}} -->\n` +
        `<div class="wp-block-group alignfull" style="padding-top:1rem;padding-right:1.5rem;padding-bottom:1rem;padding-left:1.5rem">` +
        `<!-- wp:site-title {"level":0,"className":"brand"} /-->\n` +
        `<!-- wp:navigation {"overlayMenu":"mobile","layout":{"type":"flex"}} -->\n` +
        `<!-- wp:navigation-link {"label":"Home","url":"/"} /-->\n` +
        `<!-- wp:navigation-link {"label":"About","url":"/about/"} /-->\n` +
        `<!-- /wp:navigation --></div>\n` +
        `<!-- /wp:group -->`
    );
  });

  it('buildCarriedHeaderPart rewrites carried header links before the disclosed convert rewire', async () => {
    const header: ChromePartSection = {
      id: 'header',
      role: 'header',
      classes: ['bp-header'],
      html:
        '<header class="bp-header"><nav><ul>' +
        '<li><a href="archive.html">Subjects</a></li>' +
        '<li><a href="archive.html">Community</a></li>' +
        '<li><a href="page.html">About</a></li>' +
        '</ul></nav></header>',
    };
    const seen: string[] = [];
    const convertPart: ChromePartConverter = (html) => {
      seen.push(html);
      return `<!-- wp:group -->\n<div class="wp-block-group">${html}</div>\n<!-- /wp:group -->`;
    };

    const html = await buildCarriedHeaderPart(header, {
      pageSlugs: ['home', 'archive', 'page'],
      labelToUrl: (label) => {
        const key = label.toLowerCase().trim();
        if (key === 'community') return '/category/community/';
        if (key === 'about') return '/about/';
        return undefined;
      },
      sticky: { kind: 'sticky', toggleClass: 'is-scrolled', offset: 24 },
      convertPart,
    });

    expect(seen).toEqual([
      '<div class="bp-header"><nav><ul><li><a href="/archive/">Subjects</a></li><li><a href="/category/community/">Community</a></li><li><a href="/about/">About</a></li></ul></nav></div>',
    ]);
    expect(html).toBe(
      `<!-- wp:group -->\n` +
        `<div class="wp-block-group"><div class="bp-header"><nav><ul><li><a href="/archive/">Subjects</a></li><li><a href="/category/community/">Community</a></li><li><a href="/about/">About</a></li></ul></nav></div></div>\n` +
        `<!-- /wp:group -->\n` +
        `\n` +
        `<!-- wp:dla/sticky {"toggleClass":"is-scrolled","offset":24} -->\n` +
        `<div class="wp-block-dla-sticky" style="display:none" data-wp-interactive="dla/sticky" data-wp-context='{"toggleClass":"is-scrolled","offset":24}' data-wp-init="callbacks.init"></div>\n` +
        `<!-- /wp:dla/sticky -->`
    );
    expect(blockMarkupRoundtrips(html)).toEqual({ ok: true });
  });

  it('buildFooterPart applies DLA footer normalization, href rewrite, and token wrapper around converted markup', async () => {
    const footer: ChromePartSection = {
      id: 'footer',
      role: 'footer',
      html: '<footer><p><a href="about.html">About us</a> - <a href="https://x.com">Ext</a></p></footer>',
    };
    const seen: string[] = [];
    const convertPart: ChromePartConverter = (html) => {
      seen.push(html);
      return `<!-- wp:paragraph -->\n<p><a href="/about/">About us</a> - <a href="https://x.com">Ext</a></p>\n<!-- /wp:paragraph -->`;
    };

    const html = await buildFooterPart(footer, 'Acme', {
      pageSlugs: ['home', 'about'],
      bgToken: 'surface-inverse',
      textToken: 'text-inverse',
      convertPart,
    });

    expect(seen).toEqual([
      '<div><p><a href="/about/">About us</a> - <a href="https://x.com">Ext</a></p></div>',
    ]);
    expect(html).toBe(
      `<!-- wp:group {"align":"full","backgroundColor":"surface-inverse","textColor":"text-inverse","layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"2.5rem","bottom":"2.5rem"}}}} -->\n` +
        `<div class="wp-block-group alignfull has-text-inverse-color has-surface-inverse-background-color has-text-color has-background" style="padding-top:2.5rem;padding-bottom:2.5rem">` +
        `<!-- wp:paragraph -->\n<p><a href="/about/">About us</a> - <a href="https://x.com">Ext</a></p>\n<!-- /wp:paragraph -->` +
        `</div>\n` +
        `<!-- /wp:group -->`
    );
    expect(blockMarkupRoundtrips(html)).toEqual({ ok: true });
  });

  it('findChromeMounts discovers DLA JS-rendered chrome mounts around main', () => {
    const page = `<html><body><div class="page-wrap">
      <div id="topBanner"><p>Sale</p></div>
      <div id="siteHeader" class="chrome shell"></div>
      <main><section id="hero"><h1>Hi</h1><div id="insideMain"></div></section></main>
      <div id="siteFooter"></div>
      <div id="lateFooter"></div>
    </div></body></html>`;

    expect(findChromeMounts(page)).toEqual({
      header: { id: 'siteHeader', classes: ['chrome', 'shell'] },
      footer: { id: 'siteFooter', classes: [] },
    });
  });

  it('mountPartMarkup emits DLA anchored empty group markup with optional sticky state', () => {
    const html = mountPartMarkup(
      { id: 'siteHeader', classes: ['chrome'] },
      { kind: 'sticky', toggleClass: 'is-scrolled', offset: 24 }
    );

    expect(html).toBe(
      `<!-- wp:group {"anchor":"siteHeader","tagName":"div","className":"chrome"} -->\n` +
        `<div id="siteHeader" class="wp-block-group chrome"></div>\n` +
        `<!-- /wp:group -->\n` +
        `\n` +
        `<!-- wp:dla/sticky {"toggleClass":"is-scrolled","offset":24} -->\n` +
        `<div class="wp-block-dla-sticky" style="display:none" data-wp-interactive="dla/sticky" data-wp-context='{"toggleClass":"is-scrolled","offset":24}' data-wp-init="callbacks.init"></div>\n` +
        `<!-- /wp:dla/sticky -->`
    );
    expect(blockMarkupRoundtrips(html)).toEqual({ ok: true });
  });
});
