import { describe, expect, it } from 'vitest';

import type { WorkerPool } from '../../pool/types.js';
import {
  classifySemanticStrategy,
  reconstruct,
  reconstructNativeAggregate,
  type SectionStrategy,
  structuredStrategy,
} from '../index.js';
import type { SectionSpec } from '../section-spec.js';
import type { StageCtx } from '../types.js';

type SourceIdentitySection = SectionSpec & {
  sourceId?: string;
  sourceClasses?: string[];
};

function sectionSpec(overrides: Partial<SectionSpec> = {}): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 0,
    headings: [],
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
    ...overrides,
  };
}

function sectionImage(
  url: string,
  overrides: Partial<SectionSpec['images'][number]> = {},
): SectionSpec['images'][number] {
  return {
    url,
    sourceUrl: url,
    alt: 'Fixture image',
    kind: 'img',
    width: 1200,
    height: 800,
    ...overrides,
  };
}

function fakePool(): WorkerPool {
  return {
    async rawConvert(items: string[]) {
      return items.map((html) => ({ html, wpHtmlResidue: 0 }));
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
    async stop() {},
  };
}

function stageCtx(overrides: Partial<StageCtx> & Record<string, unknown> = {}): StageCtx {
  return {
    srcDir: '/tmp/strategy-site',
    site: { root: '/tmp/strategy-site', pages: [] },
    themeMeta: { name: 'Strategy Fixture', slug: 'strategy-fixture' },
    warn() {},
    ...overrides,
  };
}

const convertedMarkup = [
  '<!-- wp:heading -->',
  '<h2>Converted service</h2>',
  '<!-- /wp:heading -->',
  '<!-- wp:paragraph -->',
  '<p>Converted copy survives intact.</p>',
  '<!-- /wp:paragraph -->',
].join('\n');

const sections: SectionSpec[] = [
  sectionSpec({
    sectionIndex: 1,
    interactionModel: 'cover-with-headline',
    height: 720,
    headings: ['Cover hero'],
    bodyText: ['Hero body copy.'],
    fullBleed: true,
    images: [sectionImage('/wp-content/uploads/2026/hero.jpg', { width: 1440, height: 900 })],
    sectionHtml:
      '<section id="hero" class="hero cover"><h1>Cover hero</h1><p>Hero body copy.</p><img src="/wp-content/uploads/2026/hero.jpg" alt="Fixture image"></section>',
  }),
  sectionSpec({
    sectionIndex: 0,
    headings: ['Native text'],
    bodyText: ['Native body copy.'],
    sectionHtml: '<section id="native" class="band text"><h2>Native text</h2><p>Native body copy.</p></section>',
  }),
  sectionSpec({
    sectionIndex: 2,
    headings: ['Converted service'],
    bodyText: ['Converted copy survives intact.'],
    sectionHtml:
      '<section id="converted" class="service"><h2>Converted service</h2><p>Converted copy survives intact.</p></section>',
  }),
  sectionSpec({
    sectionIndex: 3,
    headings: ['Lossy fallback'],
    bodyText: ['Fallback body copy.'],
    images: [sectionImage('https://cdn.example.com/lossy.jpg')],
    sectionHtml:
      '<section id="lossy" class="fallback"><h2>Lossy fallback</h2><p>Fallback body copy.</p><img src="https://cdn.example.com/lossy.jpg" alt="Fixture image"></section>',
  }),
];

const options = {
  sourceUrl: 'https://example.com/strategy',
  slug: 'strategy',
  convertedSections: new Map([[2, { markup: convertedMarkup, wpHtmlResidue: 0 }]]),
};

function frozenAggregate() {
  const aggregate = reconstructNativeAggregate(sections, options);
  return {
    sectionMarkup: aggregate.sectionMarkup,
    heroIsCover: aggregate.heroIsCover,
    provenanceFlags: aggregate.provenanceFlags,
    expectedText: aggregate.expectedText,
    bodyText: aggregate.bodyText,
    expectedAssets: aggregate.expectedAssets,
  };
}

describe('reconstruct strategy seam default path', () => {
  it('exports the classify semantic default strategy from the theme barrel', () => {
    expect(classifySemanticStrategy.name).toBe('classify-semantic');
    expect(typeof classifySemanticStrategy.render).toBe('function');
    expect(classifySemanticStrategy.drainDedup).toBeUndefined();
  });

  it('keeps the pre-seam direct aggregate byte-identical without dedup output', () => {
    const aggregate = reconstructNativeAggregate(sections, options);
    console.info(`Strategy seam default byte-identity cases=${sections.length}`);
    // The preserve-dom default exposes an (empty) dedup channel rather than omitting it.
    expect(aggregate.dedup).toEqual({ cssRules: [] });
    expect(frozenAggregate()).toMatchInlineSnapshot(`
      {
        "bodyText": [
          "Hero body copy.",
          "Native body copy.",
          "Converted copy survives intact.",
          "Fallback body copy.",
        ],
        "expectedAssets": [
          "/wp-content/uploads/2026/hero.jpg",
          "https://cdn.example.com/lossy.jpg",
        ],
        "expectedText": [
          "Cover hero",
          "Native text",
          "Converted service",
          "Lossy fallback",
        ],
        "heroIsCover": false,
        "provenanceFlags": [],
        "sectionMarkup": [
          "<!-- wp:group {"anchor":"hero","tagName":"section","align":"full","className":"hero cover"} -->
      <section id="hero" class="wp-block-group alignfull hero cover"><!-- wp:heading {"level":1} -->
      <h1 class="wp-block-heading">Cover hero</h1>
      <!-- /wp:heading -->
      <!-- wp:paragraph -->
      <p>Hero body copy.</p>
      <!-- /wp:paragraph -->
      <!-- wp:image -->
      <figure class="wp-block-image"><img src="/wp-content/uploads/2026/hero.jpg" alt="Fixture image"/></figure>
      <!-- /wp:image --></section>
      <!-- /wp:group -->",
          "<!-- wp:group {"anchor":"native","tagName":"section","align":"full","className":"band text"} -->
      <section id="native" class="wp-block-group alignfull band text"><!-- wp:heading -->
      <h2 class="wp-block-heading">Native text</h2>
      <!-- /wp:heading -->
      <!-- wp:paragraph -->
      <p>Native body copy.</p>
      <!-- /wp:paragraph --></section>
      <!-- /wp:group -->",
          "<!-- wp:group {"anchor":"converted","tagName":"section","align":"full","className":"service"} -->
      <section id="converted" class="wp-block-group alignfull service"><!-- wp:heading -->
      <h2 class="wp-block-heading">Converted service</h2>
      <!-- /wp:heading -->
      <!-- wp:paragraph -->
      <p>Converted copy survives intact.</p>
      <!-- /wp:paragraph --></section>
      <!-- /wp:group -->",
          "<!-- wp:group {"anchor":"lossy","tagName":"section","align":"full","className":"fallback"} -->
      <section id="lossy" class="wp-block-group alignfull fallback"><!-- wp:heading -->
      <h2 class="wp-block-heading">Lossy fallback</h2>
      <!-- /wp:heading -->
      <!-- wp:paragraph -->
      <p>Fallback body copy.</p>
      <!-- /wp:paragraph -->
      <!-- wp:image -->
      <figure class="wp-block-image"><img src="https://cdn.example.com/lossy.jpg" alt="Fixture image"/></figure>
      <!-- /wp:image --></section>
      <!-- /wp:group -->",
        ],
      }
    `);
  });

  it('keeps reconstruct() routed through the same default aggregate bytes', async () => {
    const aggregate = reconstructNativeAggregate(sections, options);
    const routed = await reconstruct(
      sections,
      stageCtx(options),
      fakePool(),
      {},
      0,
    );
    expect(routed.map((section) => section.blocks)).toEqual(aggregate.sectionMarkup);
  });

  it('passes recovered source identity into custom strategies and drains dedup output', () => {
    const seen: Array<{ sourceId?: string; sourceClasses?: string[] }> = [];
    let drainedState: unknown;
    const strategy: SectionStrategy = {
      name: 'probe-source-identity',
      render(section, _options, _ctx, state) {
        const source = section as SourceIdentitySection;
        seen.push({ sourceId: source.sourceId, sourceClasses: source.sourceClasses });
        state.instanceStyles = { observed: seen.length };
        return null;
      },
      drainDedup(state) {
        drainedState = state.instanceStyles;
        return { cssRules: ['.probe-source-identity{}'] };
      },
    };

    const aggregate = reconstructNativeAggregate(
      [
        sectionSpec({
          sectionIndex: 10,
          headings: ['Identity'],
          sectionHtml: '<article id="source-card" class="alpha beta"><h2>Identity</h2></article>',
        }),
        sectionSpec({
          sectionIndex: 11,
          headings: ['Styled identity'],
          sectionHtml: '<section><h2>Styled identity</h2></section>',
          styledHtml: '<section id="styled-card" class="gamma delta"><h2>Styled identity</h2></section>',
        }),
      ],
      { strategy },
    );

    expect(seen).toEqual([
      { sourceId: 'source-card', sourceClasses: ['alpha', 'beta'] },
      { sourceId: 'styled-card', sourceClasses: ['gamma', 'delta'] },
    ]);
    expect(drainedState).toEqual({ observed: 2 });
    expect(aggregate.sectionMarkup).toEqual([]);
    expect(aggregate.dedup).toEqual({ cssRules: ['.probe-source-identity{}'] });
  });
});

describe('structuredStrategy — interpretive theme-styled reconstruction (no-CSS-carry path)', () => {
  it('is exported from the theme barrel', () => {
    expect(structuredStrategy.name).toBe('structured');
    expect(typeof structuredStrategy.render).toBe('function');
  });

  it('emits native structured blocks (theme-styled) instead of a class-preserving island', () => {
    // A cover-hero section. The default (preserve-dom) strategy keeps the source
    // DOM/classes (needs carried CSS). structuredStrategy must interpret the spec
    // into a native, self-contained block (core/cover) that renders from the theme
    // alone — no verbatim island, no preserved source `class="hero cover"`.
    const hero = sectionSpec({
      sectionIndex: 0,
      interactionModel: 'cover-with-headline',
      height: 720,
      headings: ['Cover hero'],
      bodyText: ['Hero body copy.'],
      fullBleed: true,
      images: [sectionImage('/wp-content/uploads/2026/hero.jpg', { width: 1440, height: 900 })],
      sectionHtml:
        '<section id="hero" class="hero cover"><h1>Cover hero</h1><p>Hero body copy.</p><img src="/wp-content/uploads/2026/hero.jpg" alt="Fixture image"></section>',
    });

    const structured = reconstructNativeAggregate([hero], { ...options, strategy: structuredStrategy });
    const defaulted = reconstructNativeAggregate([hero], options);

    const structuredBody = structured.sectionMarkup.join('\n');
    const defaultedBody = defaulted.sectionMarkup.join('\n');

    // structured → a native canonical block, not a verbatim source island.
    expect(structuredBody).toContain('wp:cover');
    expect(structuredBody).not.toContain('lib-coverage-island');
    expect(structuredBody).not.toContain('class="hero cover"');
    // content still preserved (provenance-faithful copy).
    expect(structured.expectedText).toContain('Cover hero');
    // and it genuinely differs from the default preserve-dom output (regression guard).
    expect(structuredBody).not.toEqual(defaultedBody);
  });
});
