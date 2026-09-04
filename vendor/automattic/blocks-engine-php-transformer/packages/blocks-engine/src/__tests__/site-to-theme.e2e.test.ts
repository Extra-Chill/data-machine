import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createRequire } from 'node:module';

import { afterEach, describe, expect, it, vi } from 'vitest';

import { classifySemanticStrategy } from '../theme/index.js';
import type { WorkerPool } from '../pool/types.js';
import type {
  AssetFile,
  FoundationTokens,
  SectionBlocks,
  SectionSpec,
  SiteModel,
  StaticImgRef,
  ThemeMeta,
} from '../theme/index.js';

const fixtureRoot = join(import.meta.dirname, 'fixtures/site');
const googleCssUrl = 'https://fonts.googleapis.com/css2?family=Inter';
const gstaticFontUrl = 'https://fonts.gstatic.com/s/inter/v12/inter-latin.woff2';
const fontBytes = new Uint8Array([9, 8, 7, 6]);
const requireFromHere = createRequire(import.meta.url);
const requireWithCache = requireFromHere as typeof requireFromHere & {
  resolve(id: string): string;
  cache: Record<string, unknown>;
};

function requireCacheEntriesForWordPressBlocks(): string[] {
  return Object.keys(requireWithCache.cache).filter((entry) =>
    entry.includes('/node_modules/@wordpress/blocks/'),
  );
}

function clearWordPressBlocksRequireCache(): void {
  for (const entry of requireCacheEntriesForWordPressBlocks()) {
    delete requireWithCache.cache[entry];
  }
}

async function withTempDir<T>(prefix: string, fn: (dir: string) => Promise<T> | T): Promise<T> {
  const dir = mkdtempSync(join(tmpdir(), prefix));
  try {
    return await fn(dir);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function copyFixtureSite(dest: string): void {
  for (const file of ['index.html', 'about.html', 'style.css']) {
    writeFileSync(join(dest, file), readFileSync(join(fixtureRoot, file), 'utf8'), 'utf8');
  }
  cpSync(join(fixtureRoot, 'assets'), join(dest, 'assets'), { recursive: true });
}

function appendFtpSourceCssSentinel(siteDir: string): void {
  const imagePath = join(siteDir, 'assets', 'ftp-bg.png');
  const stylePath = join(siteDir, 'style.css');
  const sourceCss = readFileSync(stylePath, 'utf8');
  writeFileSync(imagePath, new Uint8Array([0x89, 0x50, 0x4e, 0x47]));
  writeFileSync(
    stylePath,
    `${sourceCss}\n.ftp-sentinel{max-width:960px;background-image:url("assets/ftp-bg.png")}\n`,
    'utf8'
  );
}

function semanticSpec(sectionIndex = 0): SectionSpec {
  return {
    sectionIndex,
    interactionModel: 'static',
    top: 0,
    height: 0,
    headings: ['Semantic heading'],
    bodyText: ['Semantic body copy.'],
    buttonLabels: [],
    images: [],
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
      containerWidth: 0,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '0',
    },
    sectionHtml: '<h1>Semantic heading</h1><p>Semantic body copy.</p>',
  };
}

function imageSpec(sectionIndex = 0): SectionSpec {
  return {
    ...semanticSpec(sectionIndex),
    sectionIndex,
    headings: ['Asset heading'],
    bodyText: ['Asset body copy.'],
    sectionHtml: [
      '<section>',
      '<h1>Asset heading</h1>',
      '<p>Asset body copy.</p>',
      '<img src="assets/logo.png" alt="Blocks Engine mark" />',
      '</section>',
    ].join(''),
  };
}

function p1Sections(): Record<string, SectionSpec[]> {
  return {
    about: [semanticSpec(0)],
    home: [imageSpec(0)],
  };
}

function imageBlockMarkup(): string {
  return [
    '<!-- wp:image -->',
    '<figure class="wp-block-image"><img src="assets/logo.png" alt="Blocks Engine mark"/></figure>',
    '<!-- /wp:image -->',
  ].join('\n');
}

function fakePool(markup: string): WorkerPool & { stopCalls: number } {
  return mappedPool(() => markup);
}

function mappedPool(markupForInput: (html: string) => string): WorkerPool & { stopCalls: number } {
  return {
    stopCalls: 0,
    async rawConvert(items: string[]) {
      return items.map((html) => ({ html: markupForInput(html), wpHtmlResidue: 0 }));
    },
    async canonicalize(items: string[]) {
      return items.map((html) => ({
        html,
        changed: false,
        fixedIssues: [],
        blockCount: 0,
        htmlIslands: [],
        htmlIslandCount: 0,
        degraded: false,
      }));
    },
    async stop() {
      this.stopCalls += 1;
    },
  };
}

function p1Pool(): WorkerPool & { stopCalls: number } {
  return mappedPool((html) =>
    html.includes('assets/logo.png')
      ? imageBlockMarkup()
      : '<!-- wp:paragraph -->\n<p>Stable.</p>\n<!-- /wp:paragraph -->'
  );
}

function coverageIslandMarkup(html: string): string {
  return [
    '<!-- wp:html {"metadata":{"name":"lib-coverage-island"}} -->',
    html.trim(),
    '<!-- /wp:html -->',
  ].join('\n');
}

function passthroughIslandPool(): WorkerPool & { stopCalls: number } {
  return mappedPool((html) => coverageIslandMarkup(html));
}

function themeMeta(): ThemeMeta {
  return {
    name: 'Fixture Theme',
    slug: 'fixture-theme',
  };
}

function foundationTokens(): FoundationTokens {
  return {
    palette: [{ name: 'Text Default', color: '#111111' }],
    typography: { body: 'Fixture Sans' },
    breakpoints: { md: '768px', lg: '960px' },
  };
}

function siteModel(root = fixtureRoot): SiteModel {
  return {
    root,
    pages: [
      {
        relPath: 'index.html',
        slug: 'home',
        html: readFileSync(join(fixtureRoot, 'index.html'), 'utf8'),
        title: 'Home',
      },
    ],
  };
}

function logoAsset(sourceRoot = fixtureRoot): AssetFile {
  return {
    relPath: 'assets/logo.png',
    sourcePath: join(sourceRoot, 'assets/logo.png'),
  };
}

function logoRef(sourceRoot = fixtureRoot): StaticImgRef {
  return {
    ref: 'assets/logo.png',
    themeRel: 'assets/logo.png',
    sourcePath: join(sourceRoot, 'assets/logo.png'),
  };
}

type AssemblePartsWithAssets = {
  site: SiteModel;
  tokens: FoundationTokens;
  pages: Record<string, SectionBlocks[]>;
  meta: ThemeMeta;
  assets: AssetFile[];
  fontCss: string;
  imgRefsByPage: Record<string, StaticImgRef[]>;
};

function textResponse(body: string): Response {
  return new Response(body, {
    status: 200,
    headers: { 'content-type': 'text/css' },
  }) as Response;
}

function bytesResponse(bytes: Uint8Array): Response {
  return new Response(bytes, {
    status: 200,
    headers: { 'content-type': 'font/woff2' },
  }) as Response;
}

function routeFetch(routes: Record<string, Response>): {
  fetchImpl: typeof fetch;
  fetchMock: ReturnType<typeof vi.fn>;
} {
  const fetchMock = vi.fn(async (input: Parameters<typeof fetch>[0]) => {
    const url = String(input);
    const response = routes[url];
    if (!response) throw new Error(`Unexpected fetch: ${url}`);

    return response.clone();
  });

  return { fetchImpl: fetchMock as unknown as typeof fetch, fetchMock };
}

function mockFontFetch(): {
  fetchImpl: typeof fetch;
  fetchMock: ReturnType<typeof vi.fn>;
} {
  const googleCss = `
    @font-face {
      font-family: 'Inter';
      src: url('${gstaticFontUrl}') format('woff2');
      font-weight: 400;
      font-style: normal;
    }
  `;

  return routeFetch({
    [googleCssUrl]: textResponse(googleCss),
    [gstaticFontUrl]: bytesResponse(fontBytes),
  });
}

function expectThemesByteIdentical(leftDir: string, rightDir: string, files: string[]): void {
  for (const file of files) {
    expect(readFileSync(join(rightDir, file))).toEqual(readFileSync(join(leftDir, file)));
  }
}

function homeChromeRefs(): { headerRef: string; footerRef: string } {
  return {
    headerRef: '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->',
    footerRef: '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
  };
}

function betweenChromeRefs(template: string): string {
  const { headerRef, footerRef } = homeChromeRefs();
  const headerIndex = template.indexOf(headerRef);
  const footerIndex = template.indexOf(footerRef);
  expect(headerIndex).toBeGreaterThanOrEqual(0);
  expect(footerIndex).toBeGreaterThan(headerIndex);
  return template.slice(headerIndex + headerRef.length, footerIndex);
}

function expectGenericQueriedContentTemplate(template: string): void {
  const { headerRef, footerRef } = homeChromeRefs();

  expect(template).toContain(headerRef);
  expect(template).toContain(footerRef);
  expect(template).toContain('<!-- wp:post-content {"layout":{"type":"constrained"}} /-->');
  expect(template).not.toMatch(/<(?:header|nav|footer)(?:\s|>)/);
}

afterEach(() => {
  vi.doUnmock('../pool/pool.js');
  vi.resetModules();
});

describe('site-to-theme P0-3 orchestration', () => {
  it('planTemplates maps home to front-page and source pages to page', async () => {
    const { planTemplates } = await import('../theme/index.js');
    const homePage = siteModel().pages[0];
    const aboutPage = {
      relPath: 'about.html',
      slug: 'about',
      html: readFileSync(join(fixtureRoot, 'about.html'), 'utf8'),
      title: 'About',
    };

    expect(planTemplates({ root: fixtureRoot, pages: [homePage, aboutPage] })).toEqual({
      templatesByPage: {
        home: 'front-page',
        about: 'page',
      },
    });
  });

  it('planTemplates falls back to index.html as front-page when home slug is absent', async () => {
    const { planTemplates } = await import('../theme/index.js');

    expect(
      planTemplates({
        root: fixtureRoot,
        pages: [
          {
            relPath: 'landing/index.html',
            slug: 'landing',
            html: '<main>Landing</main>',
            title: 'Landing',
          },
          {
            relPath: 'about.html',
            slug: 'about',
            html: '<main>About</main>',
            title: 'About',
          },
        ],
      })
    ).toEqual({
      templatesByPage: {
        landing: 'front-page',
        about: 'page',
      },
    });
  });

  it('planTemplates falls back to the first page when no home or index page exists', async () => {
    const { planTemplates } = await import('../theme/index.js');

    expect(
      planTemplates({
        root: fixtureRoot,
        pages: [
          {
            relPath: 'about.html',
            slug: 'about',
            html: '<main>About</main>',
            title: 'About',
          },
          {
            relPath: 'services.html',
            slug: 'services',
            html: '<main>Services</main>',
            title: 'Services',
          },
        ],
      })
    ).toEqual({
      templatesByPage: {
        about: 'front-page',
        services: 'page',
      },
    });
  });

  it('assemble-template-plan leaves non-home source bodies for queried page content', async () => {
    const { assemble } = await import('../theme/index.js');
    const homePage = siteModel().pages[0];
    const aboutPage = {
      relPath: 'about.html',
      slug: 'about',
      html: readFileSync(join(fixtureRoot, 'about.html'), 'utf8'),
      title: 'About',
    };

    const model = assemble({
      site: {
        root: fixtureRoot,
        pages: [homePage, aboutPage],
      },
      tokens: foundationTokens(),
      pages: {
        home: [
          {
            spec: semanticSpec(0),
            blocks: '<!-- wp:paragraph -->\n<p>Home body.</p>\n<!-- /wp:paragraph -->',
            coverage: 1,
          },
        ],
        about: [
          {
            spec: semanticSpec(0),
            blocks: '<!-- wp:paragraph -->\n<p>About body.</p>\n<!-- /wp:paragraph -->',
            coverage: 1,
          },
        ],
      },
      meta: themeMeta(),
      chromeParts: {
        'header.html': '<!-- wp:html -->header<!-- /wp:html -->',
        'footer.html': '<!-- wp:html -->footer<!-- /wp:html -->',
      },
      chromeSlugsByPage: {
        home: { header: 'header', footer: 'footer' },
        about: { header: 'header', footer: 'footer' },
      },
    } as Parameters<typeof assemble>[0]);

    expect(model.templates['front-page.html']).toContain('Home body.');
    expect(model.templates['front-page.html']).not.toContain('About body.');
    expect(model.templates['index.html']).not.toContain('Home body.');
    expect(model.templates['index.html']).not.toContain('About body.');
    expect(model.templates['page.html']).not.toContain('Home body.');
    expect(model.templates['page.html']).not.toContain('About body.');
    expectGenericQueriedContentTemplate(model.templates['index.html']);
    expectGenericQueriedContentTemplate(model.templates['page.html']);
  });

  it('assemble-threads-assets adds copied assets, font CSS, and rewrites template images', async () => {
    const { assemble } = await import('../theme/index.js');
    const parts: AssemblePartsWithAssets = {
      site: siteModel(),
      tokens: foundationTokens(),
      pages: {
        home: [
          {
            spec: imageSpec(0),
            blocks: imageBlockMarkup(),
            coverage: 1,
          },
        ],
      },
      meta: themeMeta(),
      assets: [logoAsset()],
      fontCss: '/*f*/\n@font-face { font-family: "Inter"; src: url("assets/fonts/inter.woff2"); }',
      imgRefsByPage: {
        home: [logoRef()],
      },
    };

    const model = assemble(parts as Parameters<typeof assemble>[0]);

    expect(model.assets).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          relPath: 'assets/logo.png',
        }),
      ])
    );
    expect(model.styleCss).toContain('/*f*/');
    expect(model.templates['front-page.html']).toContain(
      '/wp-content/themes/fixture-theme/assets/logo.png'
    );
    expect(model.templates['front-page.html']).not.toContain('src="assets/logo.png"');
    expect(model.templates['index.html']).toContain('wp:post-content');
    expect(model.templates['page.html']).toContain('wp:post-content');
  });

  it('assemble emits functions.php enqueuing style.css when source CSS is carried', async () => {
    const { assemble } = await import('../theme/index.js');
    const baseParts = {
      site: siteModel(),
      tokens: foundationTokens(),
      pages: { home: [{ spec: imageSpec(0), blocks: imageBlockMarkup(), coverage: 1 }] },
      meta: themeMeta(),
    } as Parameters<typeof assemble>[0];

    const carried = assemble({
      ...baseParts,
      sourceCss: '.hero{ text-align:center; color:#222 }',
    } as Parameters<typeof assemble>[0]);

    // Carried source CSS lives in style.css and theme.json styles are omitted, so
    // the front end is unstyled unless functions.php enqueues style.css.
    expect(carried.functionsPhp).toBeDefined();
    expect(carried.functionsPhp).toContain("add_action(\n\t'wp_enqueue_scripts'");
    expect(carried.functionsPhp).toContain('wp_enqueue_style(');
    expect(carried.functionsPhp).toContain('get_stylesheet_uri()');
    expect(carried.functionsPhp).toContain("'fixture-theme-style'");
    expect(carried.functionsPhp).toContain("if ( ! defined( 'ABSPATH' ) )");
    expect(carried.themeJson).not.toHaveProperty('styles');
  });

  it('assemble loads carried style.css into the block editor too', async () => {
    const { assemble } = await import('../theme/index.js');
    const carried = assemble({
      site: siteModel(),
      tokens: foundationTokens(),
      pages: { home: [{ spec: imageSpec(0), blocks: imageBlockMarkup(), coverage: 1 }] },
      meta: themeMeta(),
      sourceCss: '.hero{ text-align:center; color:#222 }',
    } as Parameters<typeof assemble>[0]);

    // The block editor renders block markup in an isolated iframe that does NOT
    // pick up the front-end wp_enqueue_scripts stylesheet, so the carried design
    // only shows in the editor if functions.php also registers it as an editor
    // style.
    expect(carried.functionsPhp).toContain('after_setup_theme');
    expect(carried.functionsPhp).toContain("add_editor_style( 'style.css' )");
  });

  it('neutralizes block gap on carried sections only, keeping it for editor-added blocks', async () => {
    const { assemble } = await import('../theme/index.js');
    const carried = assemble({
      site: siteModel(),
      tokens: foundationTokens(),
      pages: { home: [{ spec: imageSpec(0), blocks: imageBlockMarkup(), coverage: 1 }] },
      meta: themeMeta(),
      sourceCss: '.hero{ text-align:center; color:#222 }',
    } as Parameters<typeof assemble>[0]);

    // Block gap stays enabled globally (no theme.json opt-out), so blocks the user
    // adds in the editor still get default spacing...
    expect(carried.themeJson).not.toMatchObject({ settings: { spacing: { blockGap: false } } });
    // ...while a CSS rule zeroes the gap on the carried align:full section wrappers
    // so reconstructed sections sit flush. CSS-only keeps the block markup
    // byte-identical to the DLA reference.
    expect(carried.styleCss).toContain(
      ':where(.is-layout-flow, .is-layout-constrained) > .wp-block-group.alignfull{margin-block-start:0}'
    );
  });

  it('assemble emits functions.php when only font CSS is appended to style.css', async () => {
    const { assemble } = await import('../theme/index.js');
    const model = assemble({
      site: siteModel(),
      tokens: foundationTokens(),
      pages: { home: [{ spec: imageSpec(0), blocks: imageBlockMarkup(), coverage: 1 }] },
      meta: themeMeta(),
      fontCss: '@font-face { font-family: "Inter"; src: url("assets/fonts/inter.woff2"); }',
    } as Parameters<typeof assemble>[0]);

    expect(model.functionsPhp).toBeDefined();
    expect(model.functionsPhp).toContain('get_stylesheet_uri()');
  });

  it('assemble omits functions.php when style.css carries no front-end CSS (theme.json styles drive design)', async () => {
    const { assemble } = await import('../theme/index.js');
    const model = assemble({
      site: siteModel(),
      tokens: foundationTokens(),
      pages: { home: [{ spec: imageSpec(0), blocks: imageBlockMarkup(), coverage: 1 }] },
      meta: themeMeta(),
    } as Parameters<typeof assemble>[0]);

    expect(model.functionsPhp).toBeUndefined();
    // No carried CSS → theme.json keeps its global styles.
    expect(model.themeJson).toHaveProperty('styles');
    // ...and block gap stays enabled, since theme.json spacing drives the design.
    expect(model.themeJson).not.toMatchObject({ settings: { spacing: { blockGap: false } } });
    // No carried sections to neutralize → no gap-reset rule (style.css isn't even enqueued).
    expect(model.styleCss).not.toContain('margin-block-start:0}');
  });

  it('writeTheme writes functions.php to disk when present and skips it when absent', async () => {
    const { assemble } = await import('../theme/index.js');
    const { writeTheme } = await import('../theme/write-theme.js');
    const baseParts = {
      site: siteModel(),
      tokens: foundationTokens(),
      pages: { home: [{ spec: imageSpec(0), blocks: imageBlockMarkup(), coverage: 1 }] },
      meta: themeMeta(),
    } as Parameters<typeof assemble>[0];

    const withCss = assemble({ ...baseParts, sourceCss: '.hero{color:#222}' } as Parameters<typeof assemble>[0]);
    const withDir = mkdtempSync(join(tmpdir(), 'fnphp-with-'));
    const withWritten = await writeTheme(withCss, withDir);
    expect(withWritten).toContain('functions.php');
    expect(readFileSync(join(withDir, 'functions.php'), 'utf8')).toContain('wp_enqueue_style(');
    rmSync(withDir, { recursive: true, force: true });

    const without = assemble(baseParts);
    const withoutDir = mkdtempSync(join(tmpdir(), 'fnphp-without-'));
    const withoutWritten = await writeTheme(without, withoutDir);
    expect(withoutWritten).not.toContain('functions.php');
    expect(existsSync(join(withoutDir, 'functions.php'))).toBe(false);
    rmSync(withoutDir, { recursive: true, force: true });
  });

  it('assemble-threads-chrome emits real parts and wraps front-page with template-part refs', async () => {
    const { assemble } = await import('../theme/index.js');
    const { headerRef, footerRef } = homeChromeRefs();
    const chromeParts = {
      'header.html': '<!-- wp:html -->\n<header><nav>Primary</nav></header>\n<!-- /wp:html -->',
      'footer.html': '<!-- wp:html -->\n<footer>Footer</footer>\n<!-- /wp:html -->',
    };

    const model = assemble({
      site: siteModel(),
      tokens: foundationTokens(),
      pages: {
        home: [
          {
            spec: imageSpec(0),
            blocks: imageBlockMarkup(),
            coverage: 1,
          },
        ],
      },
      meta: themeMeta(),
      assets: [logoAsset()],
      fontCss: '/*f*/\n',
      imgRefsByPage: {
        home: [logoRef()],
      },
      chromeParts,
      chromeSlugsByPage: {
        home: { header: 'header', footer: 'footer' },
      },
    } as Parameters<typeof assemble>[0]);

    const template = model.templates['front-page.html'];
    const body = betweenChromeRefs(template);

    expect(model.parts).toEqual(chromeParts);
    expect(template.indexOf(headerRef)).toBeLessThan(template.indexOf('<!-- wp:group'));
    expect(template.indexOf(footerRef)).toBeGreaterThan(template.indexOf('<!-- /wp:group -->'));
    expect(body).toContain('/wp-content/themes/fixture-theme/assets/logo.png');
    expect(body).not.toMatch(/<(?:header|nav|footer)(?:\s|>)/);
    expectGenericQueriedContentTemplate(model.templates['index.html']);
    expectGenericQueriedContentTemplate(model.templates['page.html']);
  });

  it('assemble-threads-chrome defaults missing home slugs to canonical header and footer', async () => {
    const { assemble } = await import('../theme/index.js');
    const { headerRef, footerRef } = homeChromeRefs();

    const model = assemble({
      site: siteModel(),
      tokens: foundationTokens(),
      pages: {
        home: [
          {
            spec: semanticSpec(0),
            blocks: '<!-- wp:paragraph -->\n<p>Home.</p>\n<!-- /wp:paragraph -->',
            coverage: 1,
          },
        ],
      },
      meta: themeMeta(),
      chromeParts: {
        'header.html': '<!-- wp:html -->header<!-- /wp:html -->',
        'footer.html': '<!-- wp:html -->footer<!-- /wp:html -->',
      },
      chromeSlugsByPage: {},
    } as Parameters<typeof assemble>[0]);

    expect(model.templates['front-page.html']).toContain(headerRef);
    expect(model.templates['front-page.html']).toContain(footerRef);
    expectGenericQueriedContentTemplate(model.templates['index.html']);
    expectGenericQueriedContentTemplate(model.templates['page.html']);
  });

  it('assemble-threads-chrome does not reference chrome parts that were not emitted', async () => {
    const { assemble } = await import('../theme/index.js');

    const model = assemble({
      site: siteModel(),
      tokens: foundationTokens(),
      pages: {
        home: [
          {
            spec: semanticSpec(0),
            blocks: '<!-- wp:paragraph -->\n<p>Home.</p>\n<!-- /wp:paragraph -->',
            coverage: 1,
          },
        ],
      },
      meta: themeMeta(),
      chromeParts: {},
      chromeSlugsByPage: {
        home: { header: 'header', footer: 'footer' },
      },
    } as Parameters<typeof assemble>[0]);

    expect(model.parts).toEqual({});
    expect(model.templates['front-page.html']).not.toContain('wp:template-part');
    expect(model.templates['index.html']).not.toContain('wp:template-part');
    expect(model.templates['page.html']).not.toContain('wp:template-part');
    expect(model.templates['index.html']).toContain('wp:post-content');
    expect(model.templates['page.html']).toContain('wp:post-content');
  });

  it('assemble-threads-chrome chooses the home page slugs even when home is not first', async () => {
    const { assemble } = await import('../theme/index.js');
    const aboutPage = {
      relPath: 'about.html',
      slug: 'about',
      html: readFileSync(join(fixtureRoot, 'about.html'), 'utf8'),
      title: 'About',
    };
    const homePage = siteModel().pages[0];

    const model = assemble({
      site: {
        root: fixtureRoot,
        pages: [aboutPage, homePage],
      },
      tokens: foundationTokens(),
      pages: {
        home: [
          {
            spec: semanticSpec(0),
            blocks: '<!-- wp:paragraph -->\n<p>Home.</p>\n<!-- /wp:paragraph -->',
            coverage: 1,
          },
        ],
      },
      meta: themeMeta(),
      chromeParts: {
        'about-header.html': '<!-- wp:html -->about header<!-- /wp:html -->',
        'about-footer.html': '<!-- wp:html -->about footer<!-- /wp:html -->',
        'home-header.html': '<!-- wp:html -->home header<!-- /wp:html -->',
        'home-footer.html': '<!-- wp:html -->home footer<!-- /wp:html -->',
      },
      chromeSlugsByPage: {
        about: { header: 'about-header', footer: 'about-footer' },
        home: { header: 'home-header', footer: 'home-footer' },
      },
    } as Parameters<typeof assemble>[0]);

    expect(model.templates['front-page.html']).toContain(
      '<!-- wp:template-part {"slug":"home-header","tagName":"header"} /-->'
    );
    expect(model.templates['front-page.html']).toContain(
      '<!-- wp:template-part {"slug":"home-footer","tagName":"footer"} /-->'
    );
    expect(model.templates['front-page.html']).not.toContain('"slug":"about-header"');
    expect(model.templates['index.html']).toContain('wp:post-content');
  });

  it('assemble-threads-assets lets asset-stage entries win relPath collisions', async () => {
    const { assemble } = await import('../theme/index.js');
    const siteWithLegacyAsset = {
      ...siteModel(),
      assets: [
        {
          relPath: 'assets/logo.png',
          bytes: new Uint8Array([1, 2, 3]),
        },
      ],
    };
    const assetStageLogo = logoAsset();

    const model = assemble({
      site: siteWithLegacyAsset,
      tokens: foundationTokens(),
      pages: {},
      meta: themeMeta(),
      assets: [assetStageLogo],
    } as Parameters<typeof assemble>[0]);

    expect(model.assets.find((asset) => asset.relPath === 'assets/logo.png')).toEqual(
      assetStageLogo
    );
  });

  it('siteToTheme-e2e-assets-on-disk writes copied images and mocked font assets', async () => {
    const { siteToTheme } = await import('../theme/index.js');
    const { fetchImpl, fetchMock } = mockFontFetch();

    await withTempDir('blocks-engine-site-to-theme-assets-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');

      const result = await siteToTheme(siteDir, {
        outDir,
        fetchImpl,
        themeMeta: themeMeta(),
      });

      expect(fetchMock).toHaveBeenCalledTimes(2);
      expect(result.written).toEqual(expect.arrayContaining(['assets/img/logo.png']));
      expect(result.written).toEqual(
        expect.arrayContaining(['parts/header.html', 'parts/footer.html'])
      );
      expect(existsSync(join(outDir, 'assets', 'img', 'logo.png'))).toBe(true);
      expect(existsSync(join(outDir, 'parts', 'header.html'))).toBe(true);
      expect(existsSync(join(outDir, 'parts', 'footer.html'))).toBe(true);

      const fontFile = result.written.find((file) =>
        /^assets\/fonts\/[^/]+\.woff2$/.test(file)
      );
      expect(fontFile).toBeTypeOf('string');
      expect(existsSync(join(outDir, fontFile as string))).toBe(true);

      const styleCss = readFileSync(join(outDir, 'style.css'), 'utf8');
      expect(styleCss).toContain('assets/fonts/');
      expect(styleCss).not.toContain(gstaticFontUrl);

      const template = readFileSync(join(outDir, 'templates', 'front-page.html'), 'utf8');
      const indexTemplate = readFileSync(join(outDir, 'templates', 'index.html'), 'utf8');
      const pageTemplate = readFileSync(join(outDir, 'templates', 'page.html'), 'utf8');
      const { headerRef, footerRef } = homeChromeRefs();
      const body = betweenChromeRefs(template);
      const headerPart = readFileSync(join(outDir, 'parts', 'header.html'), 'utf8');
      const footerPart = readFileSync(join(outDir, 'parts', 'footer.html'), 'utf8');

      expect(template).toContain(headerRef);
      expect(template).toContain(footerRef);
      expect(template).not.toMatch(/<(?:header|nav|footer)(?:\s|>)/);
      expect(body).not.toMatch(/<(?:header|nav|footer)(?:\s|>)/);
      expect(template).toContain('Build calmer block themes');
      expect(template).toContain('/wp-content/themes/fixture-theme/assets/img/logo.png');
      // Under the preserve-dom default the resolvable logo renders as a native
      // core/image block pointing at the copied theme asset (not an island).
      expect(template).toMatch(
        /<!-- wp:image -->\s*<figure class="wp-block-image"><img src="\/wp-content\/themes\/fixture-theme\/assets\/img\/logo\.png" alt="Blocks Engine mark"\/><\/figure>\s*<!-- \/wp:image -->/
      );
      expect(template).not.toContain('src="assets/logo.png"');
      expectGenericQueriedContentTemplate(indexTemplate);
      expectGenericQueriedContentTemplate(pageTemplate);
      expect(indexTemplate).not.toContain('/wp-content/themes/fixture-theme/assets/img/logo.png');
      expect(pageTemplate).not.toContain('/wp-content/themes/fixture-theme/assets/img/logo.png');
      expect(headerPart).toContain('About');
      expect(footerPart).toContain('Blocks Engine');
    });
  });

  it('siteToTheme-e2e-local-image-carry does not duplicate a native carried image', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-local-img-once-', async (siteDir) => {
      mkdirSync(join(siteDir, 'assets'), { recursive: true });
      writeFileSync(join(siteDir, 'assets', 'hero.png'), new Uint8Array([0x89, 0x50, 0x4e, 0x47]));
      writeFileSync(
        join(siteDir, 'index.html'),
        [
          '<!doctype html><html><body><main><section>',
          '<h1>Hero image</h1>',
          '<p>Body copy.</p>',
          '<img src="assets/hero.png" alt="Hero image" width="640" height="420">',
          '</section></main></body></html>',
        ].join(''),
        'utf8'
      );

      const image = {
        url: 'assets/hero.png',
        sourceUrl: 'assets/hero.png',
        alt: 'Hero image',
        kind: 'img',
        width: 640,
        height: 420,
      } satisfies SectionSpec['images'][number];
      const result = await siteToTheme(siteDir, {
        outDir: join(siteDir, 'theme-out'),
        themeMeta: themeMeta(),
        sections: {
          home: [
            {
              ...semanticSpec(0),
              headings: ['Hero image'],
              bodyText: ['Body copy.'],
              images: [image],
              sectionHtml:
                '<section><h1>Hero image</h1><p>Body copy.</p><img src="assets/hero.png" alt="Hero image" width="640" height="420"></section>',
            },
          ],
        },
      });

      const template = result.model.templates['front-page.html'];
      const carriedSrc = '/wp-content/themes/fixture-theme/assets/img/hero.png';

      expect(template).toContain(carriedSrc);
      expect(template).not.toContain('lib-coverage-island');
      expect(template.match(/<!-- wp:image /g) ?? []).toHaveLength(1);
      expect(template.match(new RegExp(carriedSrc.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g')) ?? []).toHaveLength(1);
      expect(result.model.assets.map((asset) => asset.relPath)).toContain('assets/img/hero.png');
      expect(result.model.assets.map((asset) => asset.relPath)).not.toContain('assets/hero.png');
    });
  });

  it('FTP1-engine-acceptance-source-css-carry writes sentinel, admin-bar CSS, and no top-level theme styles', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-ftp1-css-', async (siteDir) => {
      copyFixtureSite(siteDir);
      appendFtpSourceCssSentinel(siteDir);
      const outDir = join(siteDir, 'theme-out');

      await siteToTheme(siteDir, {
        outDir,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl: mockFontFetch().fetchImpl,
        themeMeta: themeMeta(),
      });

      const styleCss = readFileSync(join(outDir, 'style.css'), 'utf8');
      const themeJson = JSON.parse(readFileSync(join(outDir, 'theme.json'), 'utf8'));

      expect(styleCss).toContain('.ftp-sentinel');
      expect(styleCss).toContain('url(assets/css/media/ftp-bg.png)');
      expect(styleCss).not.toContain('url(media/ftp-bg.png)');
      expect(styleCss).toContain('body.admin-bar');
      expect(existsSync(join(outDir, 'assets', 'css', 'media', 'ftp-bg.png'))).toBe(true);
      expect(Object.prototype.hasOwnProperty.call(themeJson, 'styles')).toBe(false);
    });
  });

  it('FTP1-engine-acceptance-source-css-carry writes deterministic style.css and theme.json bytes', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-ftp1-determinism-', async (rootDir) => {
      const siteDir = join(rootDir, 'site');
      mkdirSync(siteDir);
      copyFixtureSite(siteDir);
      appendFtpSourceCssSentinel(siteDir);

      const firstOut = join(rootDir, 'theme-first');
      const secondOut = join(rootDir, 'theme-second');

      await siteToTheme(siteDir, {
        outDir: firstOut,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl: mockFontFetch().fetchImpl,
        themeMeta: themeMeta(),
      });
      await siteToTheme(siteDir, {
        outDir: secondOut,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl: mockFontFetch().fetchImpl,
        themeMeta: themeMeta(),
      });

      expectThemesByteIdentical(firstOut, secondOut, ['style.css', 'theme.json']);
    });
  });

  it('siteToTheme-e2e-hero-survives coverage-gated reconstruct', async () => {
    const { siteToTheme } = await import('../theme/index.js');
    const { fetchImpl } = mockFontFetch();

    await withTempDir('blocks-engine-site-to-theme-hero-survives-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');

      await siteToTheme(siteDir, {
        outDir,
        fetchImpl,
        themeMeta: themeMeta(),
      });

      const template = readFileSync(join(outDir, 'templates', 'front-page.html'), 'utf8');

      expect(template).toContain('Build calmer block themes');
      expect(template).toContain('Static source pages become a structured theme pipeline.');
      expect(template).toContain('Learn more');
    });
  });

  it('FTP2g-pre-siteToTheme-renderOptions threads converted sections into reconstruct', async () => {
    const { siteToTheme } = await import('../theme/index.js');
    const { fetchImpl } = mockFontFetch();
    const sectionsByPage = {
      about: [semanticSpec(0)],
      home: [semanticSpec(0)],
    };
    const convertedMarkup = [
      '<!-- wp:group {"className":"ftp2g-pre-converted"} -->',
      '<div class="wp-block-group ftp2g-pre-converted">',
      '<!-- wp:heading -->',
      '<h2>Semantic heading</h2>',
      '<!-- /wp:heading -->',
      '<!-- wp:paragraph -->',
      '<p>Semantic body copy.</p>',
      '<!-- /wp:paragraph -->',
      '</div>',
      '<!-- /wp:group -->',
    ].join('\n');

    await withTempDir('blocks-engine-site-to-theme-render-options-', async (rootDir) => {
      const siteDir = join(rootDir, 'site');
      mkdirSync(siteDir);
      copyFixtureSite(siteDir);
      const controlOut = join(rootDir, 'theme-control');
      const injectedOut = join(rootDir, 'theme-injected');

      await siteToTheme(siteDir, {
        outDir: controlOut,
        pool: p1Pool(),
        sections: sectionsByPage,
        fetchImpl,
        themeMeta: themeMeta(),
      });

      await siteToTheme(siteDir, {
        outDir: injectedOut,
        pool: p1Pool(),
        sections: sectionsByPage,
        renderOptions: {
          home: {
            // convertedSections are only consumed by the semantic classifier path;
            // the preserve-dom default ignores them, so pin the strategy here.
            strategy: classifySemanticStrategy,
            convertedSections: new Map([
              [0, { markup: convertedMarkup, wpHtmlResidue: 0 }],
            ]),
          },
        },
        fetchImpl,
        themeMeta: themeMeta(),
      });

      const controlTemplate = readFileSync(join(controlOut, 'templates', 'front-page.html'), 'utf8');
      const injectedTemplate = readFileSync(join(injectedOut, 'templates', 'front-page.html'), 'utf8');

      expect(controlTemplate).toContain('Semantic heading');
      expect(controlTemplate).toContain('Semantic body copy.');
      expect(controlTemplate).not.toContain('ftp2g-pre-converted');
      expect(injectedTemplate).toContain('ftp2g-pre-converted');
      expect(injectedTemplate).toContain('<p>Semantic body copy.</p>');
      expect(injectedTemplate).not.toContain('lib-coverage-island');
    });
  });

  it('FTP2h-siteToTheme hoists recurring converted block styles by default with an opt-out', async () => {
    const { siteToTheme } = await import('../theme/index.js');
    const { fetchImpl } = mockFontFetch();
    const sectionsByPage = {
      about: [semanticSpec(0)],
      home: [semanticSpec(0), semanticSpec(1)],
    };
    const styledConvertedMarkup = [
      '<!-- wp:group -->',
      '<div class="wp-block-group">',
      '<!-- wp:heading -->',
      '<h2>Semantic heading</h2>',
      '<!-- /wp:heading -->',
      '<!-- wp:paragraph {"style":{"typography":{"fontSize":"18px"},"spacing":{"margin":{"top":"1rem"}}}} -->',
      '<p>Semantic body copy.</p>',
      '<!-- /wp:paragraph -->',
      '</div>',
      '<!-- /wp:group -->',
    ].join('\n');

    await withTempDir('blocks-engine-site-to-theme-hoist-', async (rootDir) => {
      const siteDir = join(rootDir, 'site');
      mkdirSync(siteDir);
      copyFixtureSite(siteDir);
      const hoistedOut = join(rootDir, 'theme-hoisted');
      const optOutOut = join(rootDir, 'theme-opt-out');
      // convertedSections are only consumed by the semantic classifier path; the
      // preserve-dom default ignores them, so pin the strategy per page.
      const renderOptions = {
        about: {
          strategy: classifySemanticStrategy,
          convertedSections: new Map([[0, { markup: styledConvertedMarkup, wpHtmlResidue: 0 }]]),
        },
        home: {
          strategy: classifySemanticStrategy,
          convertedSections: new Map([
            [0, { markup: styledConvertedMarkup, wpHtmlResidue: 0 }],
            [1, { markup: styledConvertedMarkup, wpHtmlResidue: 0 }],
          ]),
        },
      };

      const hoisted = await siteToTheme(siteDir, {
        outDir: hoistedOut,
        pool: p1Pool(),
        sections: sectionsByPage,
        renderOptions,
        fetchImpl,
        themeMeta: themeMeta(),
      });
      const optOut = await siteToTheme(siteDir, {
        outDir: optOutOut,
        pool: p1Pool(),
        sections: sectionsByPage,
        renderOptions,
        variationHoist: false,
        fetchImpl,
        themeMeta: themeMeta(),
      });

      const variationPath = join(hoistedOut, 'styles', 'blocks', 'lib-paragraph-spacing-typography.json');
      const hoistedTemplate = readFileSync(join(hoistedOut, 'templates', 'front-page.html'), 'utf8');
      const optOutTemplate = readFileSync(join(optOutOut, 'templates', 'front-page.html'), 'utf8');

      expect(hoisted.written).toContain('styles/blocks/lib-paragraph-spacing-typography.json');
      expect(hoisted.model.styleBlocks?.['lib-paragraph-spacing-typography.json']).toEqual({
        version: 3,
        slug: 'lib-paragraph-spacing-typography',
        title: 'Paragraph spacing typography',
        blockTypes: ['core/paragraph'],
        styles: { typography: { fontSize: '18px' }, spacing: { margin: { top: '1rem' } } },
      });
      expect(JSON.parse(readFileSync(variationPath, 'utf8'))).toEqual(
        hoisted.model.styleBlocks?.['lib-paragraph-spacing-typography.json']
      );
      expect(hoistedTemplate).toContain('"className":"is-style-lib-paragraph-spacing-typography"');
      expect(hoistedTemplate).not.toContain('"style":{"typography":{"fontSize":"18px"}');

      expect(optOut.written).not.toContain('styles/blocks/lib-paragraph-spacing-typography.json');
      expect(existsSync(join(optOutOut, 'styles'))).toBe(false);
      expect(optOut.model.styleBlocks).toBeUndefined();
      expect(optOutTemplate).toContain('"style":{"typography":{"fontSize":"18px"}');
      expect(optOutTemplate).not.toContain('is-style-lib-paragraph-spacing-typography');
    });
  });

  it('siteToTheme-e2e-chrome runs chrome extraction even when body sections are injected', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-chrome-sections-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');

      await siteToTheme(siteDir, {
        outDir,
        pool: passthroughIslandPool(),
        sections: p1Sections(),
        themeMeta: themeMeta(),
      });

      const headerPart = readFileSync(join(outDir, 'parts', 'header.html'), 'utf8');
      const footerPart = readFileSync(join(outDir, 'parts', 'footer.html'), 'utf8');

      expect(headerPart).toContain('<nav aria-label="Primary">');
      expect(headerPart).toContain('About');
      expect(footerPart).toContain('<footer>');
      expect(footerPart).toContain('Blocks Engine');
    });
  });

  it('siteToTheme-e2e-chrome keeps injected body sections free of raw chrome tags', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-chrome-body-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');

      await siteToTheme(siteDir, {
        outDir,
        pool: passthroughIslandPool(),
        sections: p1Sections(),
        themeMeta: themeMeta(),
      });

      const template = readFileSync(join(outDir, 'templates', 'front-page.html'), 'utf8');
      const indexTemplate = readFileSync(join(outDir, 'templates', 'index.html'), 'utf8');
      const pageTemplate = readFileSync(join(outDir, 'templates', 'page.html'), 'utf8');
      const body = betweenChromeRefs(template);

      expect(template).not.toMatch(/<(?:header|nav|footer)(?:\s|>)/);
      expect(body).not.toMatch(/<(?:header|nav|footer)(?:\s|>)/);
      expect(body).toContain('/wp-content/themes/fixture-theme/assets/img/logo.png');
      expectGenericQueriedContentTemplate(indexTemplate);
      expectGenericQueriedContentTemplate(pageTemplate);
    });
  });

  it('siteToTheme-e2e-chrome reports canonical header and footer parts in outputs', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-chrome-tallies-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');

      const result = await siteToTheme(siteDir, {
        outDir,
        pool: passthroughIslandPool(),
        sections: p1Sections(),
        themeMeta: themeMeta(),
      });

      expect(result.tallies.parts).toBe(2);
      expect(Object.keys(result.model.parts).sort()).toEqual(['footer.html', 'header.html']);
      expect(result.written).toEqual(
        expect.arrayContaining(['parts/header.html', 'parts/footer.html'])
      );
    });
  });

  it('siteToTheme-e2e-template-structure writes front-page and generic page shells', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-templates-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');

      await siteToTheme(siteDir, {
        outDir,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl: mockFontFetch().fetchImpl,
        themeMeta: themeMeta(),
      });

      const frontPage = readFileSync(join(outDir, 'templates', 'front-page.html'), 'utf8');
      const indexTemplate = readFileSync(join(outDir, 'templates', 'index.html'), 'utf8');
      const pageTemplate = readFileSync(join(outDir, 'templates', 'page.html'), 'utf8');

      expect(frontPage).toContain('/wp-content/themes/fixture-theme/assets/img/logo.png');
      expect(frontPage).not.toMatch(/<(?:header|nav|footer)(?:\s|>)/);
      expectGenericQueriedContentTemplate(indexTemplate);
      expectGenericQueriedContentTemplate(pageTemplate);
      expect(indexTemplate).not.toContain('/wp-content/themes/fixture-theme/assets/img/logo.png');
      expect(pageTemplate).not.toContain('/wp-content/themes/fixture-theme/assets/img/logo.png');
    });
  });

  it('siteToTheme-e2e-assets-on-disk keeps assets stage and assemble slug parity', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-slug-assets-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');

      const result = await siteToTheme(siteDir, {
        outDir,
        pool: p1Pool(),
        sections: p1Sections(),
        themeMeta: {
          name: 'Fixture Theme',
          slug: 'Fixture Theme!',
        },
      });

      const template = readFileSync(join(outDir, 'templates', 'front-page.html'), 'utf8');
      expect(result.model.assets.map((asset) => asset.relPath)).toContain('assets/img/logo.png');
      expect(template).toContain('/wp-content/themes/fixture-theme/assets/img/logo.png');
      expect(template).not.toContain('/wp-content/themes/Fixture Theme!');
    });
  });

  it('onAssets-decoration-drop removes decorative assets from the written theme model', async () => {
    const { siteToTheme } = await import('../theme/index.js');
    const { fetchImpl } = mockFontFetch();

    await withTempDir('blocks-engine-site-to-theme-decoration-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');
      const inventories: string[][] = [];

      const result = await siteToTheme(siteDir, {
        outDir,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl,
        themeMeta: themeMeta(),
        hooks: {
          async onAssets(inventory) {
            const relPaths = inventory.assets.map((asset) => asset.relPath);
            inventories.push(relPaths);
            return {
              keep: relPaths,
              decoration: ['assets/logo.png'],
            };
          },
        },
      });

      expect(inventories[0]).toContain('assets/logo.png');
      expect(result.model.assets.map((asset) => asset.relPath)).not.toContain('assets/logo.png');
      expect(result.written).not.toContain('assets/logo.png');
      expect(existsSync(join(outDir, 'assets', 'logo.png'))).toBe(false);
    });
  });

  it('hooks-absent-identity keeps asset output byte-identical for absent, empty, and identity hooks', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-hooks-assets-', async (rootDir) => {
      const siteDir = join(rootDir, 'site');
      mkdirSync(siteDir);
      copyFixtureSite(siteDir);
      const firstOut = join(rootDir, 'theme-no-hooks');
      const secondOut = join(rootDir, 'theme-empty-hooks');
      const thirdOut = join(rootDir, 'theme-identity-hooks');
      const firstFetch = mockFontFetch();
      const secondFetch = mockFontFetch();
      const thirdFetch = mockFontFetch();

      const first = await siteToTheme(siteDir, {
        outDir: firstOut,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl: firstFetch.fetchImpl,
        themeMeta: themeMeta(),
      });
      const second = await siteToTheme(siteDir, {
        outDir: secondOut,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl: secondFetch.fetchImpl,
        themeMeta: themeMeta(),
        hooks: {},
      });
      const third = await siteToTheme(siteDir, {
        outDir: thirdOut,
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl: thirdFetch.fetchImpl,
        themeMeta: themeMeta(),
        hooks: {
          async onFoundation(tokens) {
            return tokens;
          },
          async onSection(section) {
            return section;
          },
          async onAssets(inventory) {
            return {
              keep: inventory.assets.map((asset) => asset.relPath),
              decoration: [],
            };
          },
          async onRefine(theme) {
            return theme;
          },
        },
      });

      expect(first.written).toEqual(expect.arrayContaining(['assets/img/logo.png']));
      expect(first.written.some((file) => file.startsWith('assets/fonts/'))).toBe(true);
      expect(second.model).toEqual(first.model);
      expect(second.written).toEqual(first.written);
      expectThemesByteIdentical(firstOut, secondOut, first.written);
      expect(third.model).toEqual(first.model);
      expect(third.written).toEqual(first.written);
      expectThemesByteIdentical(firstOut, thirdOut, first.written);
    });
  });

  it('writes a lintable block theme with native block markup for semantic input', async () => {
    const { siteToTheme, lintThemeJson } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');

      const result = await siteToTheme(siteDir, {
        outDir,
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
      });

      expect(result.written).toEqual(
        expect.arrayContaining([
          'style.css',
          'theme.json',
          'templates/front-page.html',
          'templates/index.html',
          'templates/page.html',
        ])
      );
      expect(existsSync(join(outDir, 'style.css'))).toBe(true);
      expect(existsSync(join(outDir, 'theme.json'))).toBe(true);
      expect(existsSync(join(outDir, 'templates', 'front-page.html'))).toBe(true);
      expect(existsSync(join(outDir, 'templates', 'index.html'))).toBe(true);
      expect(existsSync(join(outDir, 'templates', 'page.html'))).toBe(true);

      const themeJson = JSON.parse(readFileSync(join(outDir, 'theme.json'), 'utf8'));
      expect(lintThemeJson(themeJson)).toEqual({ ok: true, errors: [] });

      const frontPage = readFileSync(join(outDir, 'templates', 'front-page.html'), 'utf8');
      const indexTemplate = readFileSync(join(outDir, 'templates', 'index.html'), 'utf8');
      const pageTemplate = readFileSync(join(outDir, 'templates', 'page.html'), 'utf8');
      expect(frontPage).toContain('<!-- wp:heading');
      expect(frontPage).toContain('<!-- wp:paragraph');
      expect(frontPage).not.toMatch(/^<!-- wp:html -->[\s\S]*<!-- \/wp:html -->$/);
      expectGenericQueriedContentTemplate(indexTemplate);
      expectGenericQueriedContentTemplate(pageTemplate);
    });
  });

  it('surfaces homepage region audit diagnostics without writing audit artifacts', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-region-audit-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const outDir = join(siteDir, 'theme-out');

      const result = await siteToTheme(siteDir, { outDir });

      expect(result.diagnostics.regionAudit).toHaveLength(1);
      const [audit] = result.diagnostics.regionAudit;
      expect(audit.page).toBe('home');
      expect(audit.entryUrl).toBe('index.html');
      expect(audit.counts.sourceLandmarks).toEqual({ header: 1, main: 1, footer: 1 });
      expect(audit.assignments.map((assignment) => assignment.kind)).toEqual([
        'header_part',
        'page_body_section',
        'non_actionable',
      ]);
      expect(result.written).not.toContain('region-audit.json');
      expect(existsSync(join(outDir, 'region-audit.json'))).toBe(false);
    });
  });

  it('treats absent hooks the same as empty and explicit identity hooks byte-for-byte', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-a-', async (rootDir) => {
      const siteDir = join(rootDir, 'site');
      mkdirSync(siteDir);
      copyFixtureSite(siteDir);
      const firstOut = join(rootDir, 'theme-no-hooks');
      const secondOut = join(rootDir, 'theme-empty-hooks');
      const thirdOut = join(rootDir, 'theme-identity-hooks');
      const pool = fakePool('<!-- wp:paragraph -->\n<p>Stable.</p>\n<!-- /wp:paragraph -->');
      const emptyHooksPool = fakePool('<!-- wp:paragraph -->\n<p>Stable.</p>\n<!-- /wp:paragraph -->');
      const identityHooksPool = fakePool('<!-- wp:paragraph -->\n<p>Stable.</p>\n<!-- /wp:paragraph -->');

      const first = await siteToTheme(siteDir, {
        outDir: firstOut,
        pool,
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
      });
      const second = await siteToTheme(siteDir, {
        outDir: secondOut,
        pool: emptyHooksPool,
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
        hooks: {},
      });
      const third = await siteToTheme(siteDir, {
        outDir: thirdOut,
        pool: identityHooksPool,
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
        hooks: {
          async onFoundation(tokens) {
            return tokens;
          },
          async onSection(section) {
            return section;
          },
          async onRefine(theme) {
            return theme;
          },
        },
      });

      expect(second.model).toEqual(first.model);
      expect(readFileSync(join(secondOut, 'theme.json'), 'utf8')).toBe(
        readFileSync(join(firstOut, 'theme.json'), 'utf8')
      );
      for (const templateFile of ['front-page.html', 'index.html', 'page.html']) {
        expect(readFileSync(join(secondOut, 'templates', templateFile), 'utf8')).toBe(
          readFileSync(join(firstOut, 'templates', templateFile), 'utf8')
        );
      }
      expect(third.model).toEqual(first.model);
      expect(readFileSync(join(thirdOut, 'theme.json'), 'utf8')).toBe(
        readFileSync(join(firstOut, 'theme.json'), 'utf8')
      );
      for (const templateFile of ['front-page.html', 'index.html', 'page.html']) {
        expect(readFileSync(join(thirdOut, 'templates', templateFile), 'utf8')).toBe(
          readFileSync(join(firstOut, 'templates', templateFile), 'utf8')
        );
      }
    });
  });

  it('uses injected pools without stopping them and runs onSection for coverageFloor 1', async () => {
    const { siteToTheme } = await import('../theme/index.js');

    await withTempDir('blocks-engine-site-to-theme-pool-', async (siteDir) => {
      copyFixtureSite(siteDir);
      const pool = fakePool('<!-- wp:heading -->\n<h2>From pool</h2>\n<!-- /wp:heading -->');
      const seenSections: SectionBlocks[] = [];

      await siteToTheme(siteDir, {
        outDir: join(siteDir, 'theme-out'),
        pool,
        coverageFloor: 1,
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
        hooks: {
          async onSection(section) {
            seenSections.push(section);
            return section;
          },
        },
      });

      expect(seenSections).toHaveLength(2);
      expect(pool.stopCalls).toBe(0);
    });
  });

  it('stops the one-shot pool it creates internally', async () => {
    const pool = fakePool('<!-- wp:paragraph -->\n<p>Owned.</p>\n<!-- /wp:paragraph -->');

    vi.doMock('../pool/pool.js', () => ({
      createWorker: () => pool,
    }));

    const { siteToTheme } = await import('../theme/site-to-theme.js');

    await withTempDir('blocks-engine-site-to-theme-owned-', async (siteDir) => {
      copyFixtureSite(siteDir);

      await siteToTheme(siteDir, {
        outDir: join(siteDir, 'theme-out'),
        sections: { about: [semanticSpec(0)], home: [semanticSpec(0)] },
      });

      expect(pool.stopCalls).toBe(1);
    });
  });

  it('react-isolation-after-full-run exports siteToTheme without loading @wordpress/blocks in this process', async () => {
    clearWordPressBlocksRequireCache();

    const entry = await import('../index.js');

    expect(entry.siteToTheme).toBeTypeOf('function');
    expect(entry.writeTheme).toBeTypeOf('function');
    expect(entry.lintThemeJson).toBeTypeOf('function');
    expect(requireCacheEntriesForWordPressBlocks()).toEqual([]);

    await withTempDir('blocks-engine-site-to-theme-main-entry-', async (siteDir) => {
      copyFixtureSite(siteDir);
      await entry.siteToTheme(siteDir, {
        outDir: join(siteDir, 'theme-out'),
        pool: p1Pool(),
        sections: p1Sections(),
        fetchImpl: mockFontFetch().fetchImpl,
        themeMeta: themeMeta(),
      });
    });

    expect(requireCacheEntriesForWordPressBlocks()).toEqual([]);
  });
});
