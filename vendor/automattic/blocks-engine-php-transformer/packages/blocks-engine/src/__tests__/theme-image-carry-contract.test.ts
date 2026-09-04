import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import * as cheerio from 'cheerio';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { siteToTheme } from '../theme/site-to-theme.js';
import { emptyNativeRenderOut, imageBlock } from '../theme/native-block-builders.js';
import type { SectionSpec, SectionSpecImage } from '../theme/section-spec.js';

afterEach(() => {
  vi.unstubAllGlobals();
});

async function withTempDir<T>(prefix: string, fn: (dir: string) => Promise<T> | T): Promise<T> {
  const dir = mkdtempSync(join(tmpdir(), prefix));
  try {
    return await fn(dir);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function writeSiteImage(dir: string, relPath: string): void {
  const parts = relPath.split('/');
  mkdirSync(join(dir, ...parts.slice(0, -1)), { recursive: true });
  writeFileSync(join(dir, ...parts), new Uint8Array([0x89, 0x50, 0x4e, 0x47]));
}

function writeSitePage(dir: string, imgSrc: string): void {
  writeFileSync(
    join(dir, 'index.html'),
    [
      '<!doctype html>',
      '<html><head><title>Fixture</title></head><body>',
      '<main><section>',
      '<h1>Local image contract</h1>',
      '<p>Body copy.</p>',
      `<img src="${imgSrc}" alt="Local hero" width="640" height="420">`,
      '</section></main>',
      '</body></html>',
    ].join(''),
    'utf8'
  );
}

function writeLossyIdentityPage(dir: string, imgSrc: string): void {
  writeFileSync(
    join(dir, 'index.html'),
    [
      '<!doctype html>',
      '<html><head><title>Fixture</title><style>.fallback{color:red}#lossy{padding-top:1px}</style></head><body>',
      '<main><section id="lossy" class="fallback fallback">',
      '<h2>Lossy source identity</h2>',
      '<p>Source CSS targeting survives.</p>',
      `<img src="${imgSrc}" alt="Remote hero" width="640" height="420">`,
      '</section></main>',
      '</body></html>',
    ].join(''),
    'utf8'
  );
}

function imageSpec(image: SectionSpecImage): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 500,
    headings: ['Local image contract'],
    bodyText: ['Body copy.'],
    buttonLabels: [],
    images: [image],
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
      containerWidth: 960,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '0',
    },
    sectionHtml: [
      '<section>',
      '<h1>Local image contract</h1>',
      '<p>Body copy.</p>',
      `<img src="${image.sourceUrl}" alt="${image.alt}" width="${image.width}" height="${image.height}">`,
      '</section>',
    ].join(''),
  };
}

// Drop sectionHtml so preserve-dom declines and the native fallback (the path this
// file pins) runs. Source identity is supplied directly rather than recovered from
// HTML, while still exercising wp-block-group stripping + dedup of carried classes.
function withoutSectionHtml(spec: SectionSpec): SectionSpec {
  const { sectionHtml: _omit, ...rest } = spec;
  return rest;
}

function lossyIdentitySpec(image: SectionSpecImage): SectionSpec {
  return {
    ...withoutSectionHtml(imageSpec(image)),
    headings: ['Lossy source identity'],
    bodyText: ['Source CSS targeting survives.'],
    sourceId: 'lossy',
    sourceClasses: ['fallback', 'fallback'],
  } as SectionSpec;
}

function sectionImage(url: string): SectionSpecImage {
  return {
    url,
    sourceUrl: url,
    alt: 'Local hero',
    kind: 'img',
    width: 640,
    height: 420,
  };
}

describe('theme local image carry contract', () => {
  it('emits a real core/image block for a carried local image instead of the missing-image paragraph', () => {
    const out = emptyNativeRenderOut();
    const markup = imageBlock(sectionImage('assets/hero.png'), out, 'local-image#0', undefined, {
      mediaUrlMap: new Map([['assets/hero.png', '/wp-content/themes/fixture-theme/assets/img/hero.png']]),
    });

    expect(markup).toContain('<!-- wp:image ');
    expect(markup).toContain('src="/wp-content/themes/fixture-theme/assets/img/hero.png"');
    expect(markup).not.toContain('[image unavailable');
    expect(out.assets).toEqual(['/wp-content/themes/fixture-theme/assets/img/hero.png']);
  });

  it('wires source imgAssets into siteToTheme assets and rewrites native block image URLs', async () => {
    await withTempDir('blocks-engine-local-img-carry-', async (siteDir) => {
      writeSiteImage(siteDir, 'assets/hero.png');
      writeSitePage(siteDir, 'assets/hero.png');

      const result = await siteToTheme(siteDir, {
        outDir: join(siteDir, 'theme'),
        themeMeta: { slug: 'fixture-theme' },
        sections: {
          home: [imageSpec(sectionImage('assets/hero.png'))],
        },
      });

      const template = readFileSync(join(siteDir, 'theme', 'templates', 'front-page.html'), 'utf8');

      expect(result.model.assets).toEqual(
        expect.arrayContaining([
          expect.objectContaining({
            relPath: 'assets/img/hero.png',
            sourcePath: join(siteDir, 'assets', 'hero.png'),
          }),
        ])
      );
      expect(result.written).toEqual(expect.arrayContaining(['assets/img/hero.png']));
      expect(existsSync(join(siteDir, 'theme', 'assets', 'img', 'hero.png'))).toBe(true);
      expect(template).toContain('<!-- wp:image ');
      expect(template).toContain('src="/wp-content/themes/fixture-theme/assets/img/hero.png"');
      expect(template).not.toContain('[image unavailable');
      expect(template).not.toContain('src="assets/hero.png"');
    });
  });

  it('keeps missing local and remote images on the existing placeholder path', async () => {
    await withTempDir('blocks-engine-local-img-missing-', async (siteDir) => {
      writeSitePage(siteDir, 'assets/missing.png');

      const missing = await siteToTheme(siteDir, {
        outDir: join(siteDir, 'missing-theme'),
        themeMeta: { slug: 'fixture-theme' },
        sections: {
          // No sectionHtml: preserve-dom declines, the native renderer emits the
          // image-lost placeholder this contract pins.
          home: [withoutSectionHtml(imageSpec(sectionImage('assets/missing.png')))],
        },
      });
      vi.stubGlobal('fetch', undefined);
      const remote = await siteToTheme(siteDir, {
        outDir: join(siteDir, 'remote-theme'),
        themeMeta: { slug: 'fixture-theme' },
        sections: {
          home: [withoutSectionHtml(imageSpec(sectionImage('https://cdn.example.test/hero.png')))],
        },
      });

      const missingTemplate = missing.model.templates['front-page.html'];
      const remoteTemplate = remote.model.templates['front-page.html'];

      expect(missingTemplate).toContain('[image unavailable');
      expect(missingTemplate).not.toContain('src="assets/missing.png"');
      expect(remoteTemplate).toContain('[image unavailable');
      expect(remoteTemplate).not.toContain('https://cdn.example.test/hero.png');
      expect(remote.model.assets.map((asset) => asset.relPath)).not.toContain('assets/img/hero.png');
    });
  });

  it('keeps source id and class selectors matchable on assembled image-lost section elements', async () => {
    await withTempDir('blocks-engine-lossy-section-identity-', async (siteDir) => {
      const remoteImage = 'https://cdn.example.test/lossy.jpg';
      writeLossyIdentityPage(siteDir, remoteImage);
      vi.stubGlobal('fetch', undefined);

      const result = await siteToTheme(siteDir, {
        outDir: join(siteDir, 'theme'),
        themeMeta: { slug: 'fixture-theme' },
        // This contract freezes the NATIVE lossy-image path (identity preservation +
        // [image unavailable] placeholder), which remains the fallback when preserve-dom
        // declines a section. Pin it off the default rich routing so it keeps testing native.
        routeRichSections: false,
        sections: {
          home: [lossyIdentitySpec(sectionImage(remoteImage))],
        },
      });

      const template = result.model.templates['front-page.html'];
      const $ = cheerio.load(template);
      const sourceTarget = $('section#lossy.fallback');
      expect(sourceTarget.length).toBe(1);

      const tokens = (sourceTarget.attr('class') ?? '').split(/\s+/).filter(Boolean);
      expect(tokens).toEqual(expect.arrayContaining(['wp-block-group', 'alignfull', 'fallback']));
      expect(new Set(tokens).size).toBe(tokens.length);
      expect(template).toContain('[image unavailable');
      expect(result.model.styleCss).toContain('.fallback{color:red}');
      expect(result.model.styleCss).toContain('#lossy{padding-top:1px}');
    });
  });

  it('keeps the source-assets header honest about absent reveal JavaScript carry', () => {
    const source = readFileSync(join(import.meta.dirname, '..', 'theme', 'source-assets.ts'), 'utf8');

    expect(source).not.toContain('JS is carried verbatim');
    expect(source).not.toContain('html.js class snippet');
    expect(source).toMatch(/reveal JS is NOT carried/i);
  });
});
