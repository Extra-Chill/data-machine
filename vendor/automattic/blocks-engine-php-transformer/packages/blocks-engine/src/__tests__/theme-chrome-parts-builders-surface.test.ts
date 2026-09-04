import { describe, expect, expectTypeOf, it } from 'vitest';

import {
  buildCarriedHeaderPart,
  buildFooterPart,
  buildHeaderPart,
  findChromeMounts,
  mountPartMarkup,
  type CarriedHeaderPartOpts,
  type ChromeMount,
  type ChromeMounts,
  type ChromePartConverter,
  type ChromePartSection,
  type FooterPartOpts,
  type HeaderPartOpts,
  type NavLink,
  type StickyBehavior,
} from '../theme/index.js';

describe('chrome-parts builders additive surface', () => {
  it('exports the frozen A6b PORT surface from theme/index', () => {
    expect(typeof buildHeaderPart).toBe('function');
    expect(typeof buildCarriedHeaderPart).toBe('function');
    expect(typeof buildFooterPart).toBe('function');
    expect(typeof findChromeMounts).toBe('function');
    expect(typeof mountPartMarkup).toBe('function');
  });

  it('freezes public function signatures for the ported builders', () => {
    expectTypeOf(buildHeaderPart).toEqualTypeOf<
      (siteTitle: string, nav: NavLink[], pageSlugs: string[], opts?: HeaderPartOpts) => string
    >();
    expectTypeOf(buildCarriedHeaderPart).toEqualTypeOf<
      (header: ChromePartSection, opts?: CarriedHeaderPartOpts) => Promise<string>
    >();
    expectTypeOf(buildFooterPart).toEqualTypeOf<
      (footer: ChromePartSection | null, siteTitle: string, opts?: FooterPartOpts) => Promise<string>
    >();
    expectTypeOf(findChromeMounts).toEqualTypeOf<(html: string) => ChromeMounts>();
    expectTypeOf(mountPartMarkup).toEqualTypeOf<(mount: ChromeMount, sticky?: StickyBehavior) => string>();
    expectTypeOf<ChromePartConverter>().toEqualTypeOf<(html: string) => string | Promise<string>>();
  });
});
