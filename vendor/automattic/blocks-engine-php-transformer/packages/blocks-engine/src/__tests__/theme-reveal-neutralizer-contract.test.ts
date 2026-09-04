import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import {
  assemble,
  collectSourceAssets,
  REVEAL_NEUTRALIZER_CSS,
  REVEAL_NEUTRALIZER_SELECTORS,
  WP_COMPAT_CSS,
  type FoundationTokens,
  type SectionBlocks,
  type SiteModel,
  type ThemeMeta,
} from '../theme/index.js';

const DERIVED_REVEAL_SELECTORS = [
  '.reveal',
  '.reveal-up',
  '.reveal-left',
  '.reveal-right',
  '.reveal-scale',
  '.reveal-stagger > *',
  '[data-reveal]',
] as const;

function withTempDir<T>(fn: (dir: string) => T): T {
  const dir = mkdtempSync(join(tmpdir(), 'blocks-engine-reveal-neutralizer-'));
  try {
    return fn(dir);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function writeFixture(dir: string, relPath: string, contents: string): void {
  const abs = join(dir, relPath);
  mkdirSync(join(abs, '..'), { recursive: true });
  writeFileSync(abs, contents, 'utf8');
}

function sourceCssForRevealFixture(extra = ''): string {
  return [
    '.reveal{opacity:0;transform:translateY(36px);transition:opacity .85s,transform .85s}',
    '.reveal-up{opacity:0;transform:translateY(40px)}',
    '.reveal-left{opacity:0;transform:translateX(-22px)}',
    '.reveal-right{opacity:0;transform:translateX(22px)}',
    '.reveal-scale{opacity:0;transform:scale(.93)}',
    '.reveal-stagger > *{opacity:0;transform:translateY(22px)}',
    '[data-reveal]{opacity:0;transform:translateY(26px)}',
    '.reveal-d1{transition-delay:.10s}',
    '.not-reveal{opacity:0.42}',
    extra,
  ].join('\n');
}

function siteModel(root: string): SiteModel {
  return {
    root,
    pages: [
      {
        relPath: 'index.html',
        slug: 'home',
        title: 'Home',
        html: [
          '<link rel="stylesheet" href="style.css">',
          '<section class="reveal">Shown without JS</section>',
          '<section class="not-reveal">Preserve opacity</section>',
        ].join(''),
      },
    ],
  };
}

function foundationTokens(): FoundationTokens {
  return {
    palette: [{ name: 'Base', color: '#111111' }],
    typography: { body: 'Inter', display: 'Inter' },
    breakpoints: { md: '768px', lg: '1024px', xl: '1280px' },
  };
}

function themeMeta(): ThemeMeta {
  return { name: 'Reveal Fixture', slug: 'reveal-fixture' };
}

function sectionBlocks(): SectionBlocks[] {
  return [
    {
      spec: {
        sectionIndex: 0,
        interactionModel: 'static',
        top: 0,
        height: 0,
        headings: ['Shown without JS'],
        bodyText: [],
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
        sectionHtml: '<section class="reveal">Shown without JS</section>',
      },
      blocks: '<!-- wp:paragraph --><p class="reveal">Shown without JS</p><!-- /wp:paragraph -->',
      coverage: 1,
    },
  ];
}

function assembledRevealStyleCss(sourceCss: string): string {
  return withTempDir((dir) => {
    writeFixture(dir, 'style.css', sourceCss);
    const site = siteModel(dir);
    const sourceAssets = collectSourceAssets(dir, site.pages);
    return assemble({
      site,
      tokens: foundationTokens(),
      pages: { home: sectionBlocks() },
      meta: themeMeta(),
      sourceCss: sourceAssets.css,
    }).styleCss;
  });
}

type Declaration = {
  order: number;
  selector: string;
  property: string;
  value: string;
  important: boolean;
};

function declarationsFor(css: string, property: string): Declaration[] {
  const declarations: Declaration[] = [];
  let order = 0;
  for (const match of css.matchAll(/([^{}]+)\{([^{}]+)\}/g)) {
    const selector = match[1].replace(/\/\*[\s\S]*?\*\//g, '').trim();
    const body = match[2];
    if (!selector) continue;
    for (const decl of body.matchAll(/(^|;)\s*([a-z-]+)\s*:\s*([^;]+)/g)) {
      if (decl[2] !== property) continue;
      const rawValue = decl[3].trim();
      declarations.push({
        order,
        selector,
        property,
        value: rawValue.replace(/\s*!important\s*$/i, ''),
        important: /\s!important\s*$/i.test(rawValue),
      });
    }
    order += 1;
  }
  return declarations;
}

function selectorAppliesToReveal(selector: string): boolean {
  return selector
    .split(',')
    .map((part) => part.trim())
    .some((part) => part === '.reveal' || part === ':where(.reveal)' || part.includes('.reveal,'));
}

function winningDeclaration(css: string, property: string): Declaration | undefined {
  return declarationsFor(css, property)
    .filter((decl) => selectorAppliesToReveal(decl.selector))
    .sort((a, b) => Number(a.important) - Number(b.important) || a.order - b.order)
    .at(-1);
}

describe('reveal neutralizer contract', () => {
  it('G1 freezes the corpus-derived reveal selector set and emits it in the carried compat bundle', () => {
    expect(REVEAL_NEUTRALIZER_SELECTORS).toEqual(DERIVED_REVEAL_SELECTORS);
    expect(REVEAL_NEUTRALIZER_CSS).toContain(DERIVED_REVEAL_SELECTORS.join(',\n'));
    expect(WP_COMPAT_CSS).toContain(REVEAL_NEUTRALIZER_CSS);
  });

  it('G2 proves the neutralizer beats source opacity:0 in assembled style.css', () => {
    const styleCss = assembledRevealStyleCss(sourceCssForRevealFixture());
    const sourceRuleIndex = styleCss.indexOf('.reveal{opacity:0');
    const neutralizerIndex = styleCss.indexOf(REVEAL_NEUTRALIZER_CSS);

    expect(sourceRuleIndex).toBeGreaterThan(-1);
    expect(neutralizerIndex).toBeGreaterThan(-1);

    const winningOpacity = winningDeclaration(styleCss, 'opacity');
    const winningTransform = winningDeclaration(styleCss, 'transform');
    expect(winningOpacity).toMatchObject({ value: '1', important: true });
    expect(winningTransform).toMatchObject({ value: 'none', important: true });
  });

  it('G3 scopes the opacity override to reveal selectors only', () => {
    const styleCss = assembledRevealStyleCss(sourceCssForRevealFixture());
    const nonRevealOpacityRules = declarationsFor(styleCss, 'opacity').filter((decl) =>
      decl.selector.includes('.not-reveal')
    );

    expect(REVEAL_NEUTRALIZER_SELECTORS).not.toContain('.not-reveal');
    expect(nonRevealOpacityRules).toEqual([
      expect.objectContaining({
        selector: '.not-reveal',
        value: '0.42',
        important: false,
      }),
    ]);
  });

  it('G4 keeps this slice CSS-only with no script enqueue or JS asset output', () => {
    const model = withTempDir((dir) => {
      writeFixture(dir, 'style.css', sourceCssForRevealFixture());
      writeFixture(dir, 'main.js', 'document.documentElement.classList.add("js");');
      const site = siteModel(dir);
      site.pages[0].html += '<script src="main.js"></script>';
      const sourceAssets = collectSourceAssets(dir, site.pages);
      return assemble({
        site,
        tokens: foundationTokens(),
        pages: { home: sectionBlocks() },
        meta: themeMeta(),
        sourceCss: sourceAssets.css,
      });
    });

    expect(model.functionsPhp).toBeDefined();
    expect(model.functionsPhp).toContain('wp_enqueue_style(');
    expect(model.functionsPhp).not.toMatch(/\bwp_enqueue_script\s*\(/);
    expect(model.assets.map((asset) => asset.relPath)).not.toContain('assets/js/source.js');
  });
});
