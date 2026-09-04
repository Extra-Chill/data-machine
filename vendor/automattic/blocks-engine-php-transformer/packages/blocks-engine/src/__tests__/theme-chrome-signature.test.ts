import { describe, expect, expectTypeOf, it } from 'vitest';

import {
  assignChromeVariants,
  chromeSignature,
  type ChromeSlugs,
} from '../theme/index.js';

describe('chrome signature contract', () => {
  it('freezes the public signatures exported from the theme entrypoint', () => {
    expect(typeof chromeSignature).toBe('function');
    expect(typeof assignChromeVariants).toBe('function');

    expectTypeOf(chromeSignature).toEqualTypeOf<
      (headerHtml: string, footerHtml: string) => string
    >();
    expectTypeOf(assignChromeVariants).toEqualTypeOf<
      (
        pages: Array<{ slug: string; headerHtml: string; footerHtml: string }>
      ) => {
        slugsByPage: Record<string, ChromeSlugs>;
        canonical: Record<string, { headerHtml: string; footerHtml: string }>;
      }
    >();
  });

  it('treats active nav state and lowercase Wix comp instance ids as signature-neutral', () => {
    const footerHtml = `
      <footer id="comp-footeraaa">
        <p>Acme Studio</p>
      </footer>
    `;

    const headerHtml = `
      <header id="comp-headeraaa">
        <nav>
          <a id="comp-homeaaa" href="/" aria-current="page" data-selected="true" data-interactive="false">Home</a>
          <a id="comp-aboutaaa" href="/about" data-interactive="true">About</a>
        </nav>
      </header>
    `;
    const sameHeaderHtml = `
      <header id="comp-headerbbb">
        <nav>
          <a id="comp-homebbb" href="/" data-interactive="true">Home</a>
          <a id="comp-aboutbbb" href="/about" aria-current="page" data-selected="true" data-interactive="false">About</a>
        </nav>
      </header>
    `;
    const differentHeaderHtml = `
      <header id="comp-headerccc">
        <nav>
          <a id="comp-homeccc" href="/" aria-current="page" data-selected="true" data-interactive="false">Home</a>
          <a id="comp-contactccc" href="/contact" data-interactive="true">Contact</a>
        </nav>
      </header>
    `;

    expect(chromeSignature(headerHtml, footerHtml)).toBe(
      chromeSignature(sameHeaderHtml, footerHtml)
    );
    expect(chromeSignature(headerHtml, footerHtml)).not.toBe(
      chromeSignature(differentHeaderHtml, footerHtml)
    );
  });

  it('preserves shared Wix component tails while normalizing page instance prefixes', () => {
    const headerHtml =
      '<header id="comp-aaa_r_comp-shared"><nav><a id="comp-linkaaa_r_comp-menu" href="/">Home</a></nav></header>';
    const sameHeaderHtml =
      '<header id="comp-bbb_r_comp-shared"><nav><a id="comp-linkbbb_r_comp-menu" href="/">Home</a></nav></header>';
    const differentComponentTailHtml =
      '<header id="comp-ccc_r_comp-different"><nav><a id="comp-linkccc_r_comp-menu" href="/">Home</a></nav></header>';

    expect(chromeSignature(headerHtml, '')).toBe(chromeSignature(sameHeaderHtml, ''));
    expect(chromeSignature(headerHtml, '')).not.toBe(
      chromeSignature(differentComponentTailHtml, '')
    );
  });

  it('assigns stable chrome variants and keeps the first page markup canonical', () => {
    const pageAHeaderHtml = `
      <header id="comp-headeraaa">
        <nav>
          <a id="comp-homeaaa" href="/" aria-current="page" data-selected="true" data-interactive="false">Home</a>
          <a id="comp-workaaa" href="/work" data-interactive="true">Work</a>
        </nav>
      </header>
    `;
    const pageAFooterHtml = `
      <footer id="comp-footeraaa">
        <p>Acme Studio</p>
      </footer>
    `;
    const pageBHeaderHtml = `
      <header id="comp-headerbbb">
        <nav>
          <a id="comp-homebbb" href="/" data-interactive="true">Home</a>
          <a id="comp-workbbb" href="/work" aria-current="page" data-selected="true" data-interactive="false">Work</a>
        </nav>
      </header>
    `;
    const pageBFooterHtml = `
      <footer id="comp-footerbbb">
        <p>Acme Studio</p>
      </footer>
    `;
    const pageCHeaderHtml = `
      <header id="comp-headerccc">
        <nav>
          <a id="comp-homeccc" href="/" aria-current="page" data-selected="true" data-interactive="false">Home</a>
          <a id="comp-servicesccc" href="/services" data-interactive="true">Services</a>
        </nav>
      </header>
    `;
    const pageCFooterHtml = `
      <footer id="comp-footerccc">
        <p>Acme Services</p>
      </footer>
    `;

    const variants = assignChromeVariants([
      { slug: 'a', headerHtml: pageAHeaderHtml, footerHtml: pageAFooterHtml },
      { slug: 'b', headerHtml: pageBHeaderHtml, footerHtml: pageBFooterHtml },
      { slug: 'c', headerHtml: pageCHeaderHtml, footerHtml: pageCFooterHtml },
    ]);

    expect(variants.slugsByPage).toEqual({
      a: { header: 'header', footer: 'footer' },
      b: { header: 'header', footer: 'footer' },
      c: { header: 'header-2', footer: 'footer-2' },
    });
    expect(Object.keys(variants.canonical)).toEqual(['header', 'header-2']);
    expect(variants.canonical.header).toEqual({
      headerHtml: pageAHeaderHtml,
      footerHtml: pageAFooterHtml,
    });
    expect(variants.canonical['header-2']).toEqual({
      headerHtml: pageCHeaderHtml,
      footerHtml: pageCFooterHtml,
    });
  });

  it('assigns variant names by input page order instead of slug order', () => {
    const firstHeader = '<header id="comp-first"><nav><a href="/first">First</a></nav></header>';
    const secondHeader =
      '<header id="comp-second"><nav><a href="/second">Second</a></nav></header>';

    const variants = assignChromeVariants([
      { slug: 'z-last', headerHtml: firstHeader, footerHtml: '' },
      { slug: 'a-first', headerHtml: secondHeader, footerHtml: '' },
      { slug: 'm-middle', headerHtml: firstHeader, footerHtml: '' },
    ]);

    expect(variants.slugsByPage).toEqual({
      'z-last': { header: 'header', footer: 'footer' },
      'a-first': { header: 'header-2', footer: 'footer-2' },
      'm-middle': { header: 'header', footer: 'footer' },
    });
    expect(variants.canonical).toEqual({
      header: { headerHtml: firstHeader, footerHtml: '' },
      'header-2': { headerHtml: secondHeader, footerHtml: '' },
    });
  });

  it('keeps an empty header and footer addressable by signature and slugs', () => {
    expect(chromeSignature('', '')).toEqual(expect.any(String));
    expect(chromeSignature('', '')).not.toBe('');

    expect(
      assignChromeVariants([{ slug: 'empty', headerHtml: '', footerHtml: '' }])
    ).toEqual({
      slugsByPage: {
        empty: { header: 'header', footer: 'footer' },
      },
      canonical: {
        header: { headerHtml: '', footerHtml: '' },
      },
    });
  });
});
