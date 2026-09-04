import { describe, expect, it } from 'vitest';

import type { WorkerPool } from '../pool/types.js';
import { canonicalize } from '../wp/canonicalize.js';
import {
  captureSectionContent,
  classifySemanticStrategy,
  hasUnmigratedRemoteAsset,
  measureSectionCoverage,
  reconstruct,
  type SectionSpec,
  type StageCtx,
} from '../theme/index.js';

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
  overrides: Partial<SectionSpec['images'][number]> = {}
): SectionSpec['images'][number] {
  return {
    url,
    sourceUrl: url,
    alt: 'Hero image',
    kind: 'img',
    width: 1200,
    height: 800,
    ...overrides,
  };
}

function fakePool(markupForInput: (html: string) => string): WorkerPool {
  return {
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
    async stop() {},
  };
}

function stageCtx(overrides: Partial<StageCtx> & Record<string, unknown> = {}): StageCtx {
  return {
    srcDir: '/tmp/site',
    site: { root: '/tmp/site', pages: [] },
    themeMeta: { name: 'Fixture Theme', slug: 'fixture-theme' },
    warn() {},
    ...overrides,
  };
}

function outerGroupAttrs(markup: string): Record<string, unknown> {
  const match = markup.match(/^\s*<!-- wp:group (\{[^\n]+}) -->/);
  expect(match?.[1]).toBeTruthy();
  return JSON.parse(match![1]);
}

function sectionClassTokens(markup: string): string[] {
  const match = markup.match(/<section\b[^>]*\bclass="([^"]*)"/);
  expect(match?.[1]).toBeTruthy();
  return match![1].split(/\s+/).filter(Boolean);
}

function expectNoDuplicateTokens(tokens: string[]): void {
  expect(new Set(tokens).size).toBe(tokens.length);
}

describe('theme reconstruct coverage gate', () => {
  it('keeps native placeholder output when only an image is unrecoverable', async () => {
    const remoteImage = 'https://cdn.example.com/uploads/hero.jpg';
    // No sectionHtml: the verbatim-island path is bypassed so the native renderer
    // runs and emits its image-lost placeholder (the path this test pins).
    const spec = sectionSpec({
      headings: ['Build calmer block themes'],
      bodyText: ['Static source pages become a structured theme pipeline.'],
      images: [sectionImage(remoteImage)],
    });

    const [section] = await reconstruct(
      [spec],
      stageCtx(),
      fakePool(() => ''),
      {},
      0,
      { strategy: classifySemanticStrategy }
    );

    expect(section.blocks).toContain('Build calmer block themes');
    expect(section.blocks).toContain('Static source pages become a structured theme pipeline.');
    expect(section.blocks).toContain('[image unavailable');
    expect(section.blocks).not.toContain(remoteImage);
    expect(section.blocks).not.toContain('lib-coverage-island');
    expect(measureSectionCoverage(captureSectionContent(spec), section.blocks).lost).toBe(true);
    expect(section.coverage).toBe(0);
  });

  it('carries source section identity on image-lost native placeholder groups with canonical classes', async () => {
    const remoteImage = 'https://cdn.example.com/uploads/lossy.jpg';
    // Source identity is supplied directly (sourceId/sourceClasses) rather than
    // recovered from sectionHtml; omitting sectionHtml routes the section to the
    // native image-lost placeholder path this test pins, while still exercising
    // the wp-block-group-stripping + dedup of the carried source classes.
    const spec = {
      ...sectionSpec({
        headings: ['Lossy source identity'],
        bodyText: ['Source CSS targeting survives.'],
        images: [sectionImage(remoteImage)],
      }),
      sourceId: 'lossy',
      sourceClasses: ['wp-block-group', 'fallback', 'fallback'],
    } as SectionSpec;

    const [section] = await reconstruct(
      [spec],
      stageCtx(),
      fakePool(() => ''),
      {},
      0,
      { strategy: classifySemanticStrategy }
    );

    const attrs = outerGroupAttrs(section.blocks);
    expect(attrs.anchor).toBe('lossy');
    expect(attrs.className).toBe('fallback');
    expectNoDuplicateTokens(String(attrs.className).split(/\s+/).filter(Boolean));

    const tokens = sectionClassTokens(section.blocks);
    expect(tokens).toEqual(expect.arrayContaining(['wp-block-group', 'alignfull', 'fallback']));
    expectNoDuplicateTokens(tokens);
    expect(section.blocks).toContain('<section id="lossy" class="');
    expect(section.blocks).not.toContain('inner-title');
    expect(section.blocks).not.toContain('inner-copy');
    expect(section.blocks).not.toContain('inner-image');

    const fixed = canonicalize(section.blocks);
    expect(fixed.fixedIssues).toEqual([]);
    const fixedAttrs = outerGroupAttrs(fixed.html);
    expect(fixedAttrs.anchor).toBe('lossy');
    expect(fixedAttrs.className).toBe('fallback');
    expectNoDuplicateTokens(String(fixedAttrs.className).split(/\s+/).filter(Boolean));
    expectNoDuplicateTokens(sectionClassTokens(fixed.html));
  });

  it('does not carry source section identity onto resolved-image or ordinary native sections', async () => {
    const resolvedImage = '/wp-content/uploads/2026/06/hero.jpg';
    // Identity is present on both specs (sourceId/sourceClasses) but must NOT be
    // carried onto a resolved-image or ordinary native section — only onto
    // image-lost placeholders. Identity is supplied directly so the native path
    // runs (no sectionHtml island interception).
    const [resolved, ordinary] = await reconstruct(
      [
        {
          ...sectionSpec({
            sectionIndex: 0,
            headings: ['Resolved image'],
            bodyText: ['The source image resolves as a native block.'],
            images: [sectionImage(resolvedImage)],
          }),
          sourceId: 'resolved',
          sourceClasses: ['resolved-section'],
        } as SectionSpec,
        {
          ...sectionSpec({
            sectionIndex: 1,
            headings: ['Ordinary native'],
            bodyText: ['No image is lost here.'],
          }),
          sourceId: 'ordinary',
          sourceClasses: ['ordinary-section'],
        } as SectionSpec,
      ],
      stageCtx(),
      fakePool(() => ''),
      {},
      0,
      { strategy: classifySemanticStrategy }
    );

    expect(resolved.blocks).toContain('<!-- wp:image ');
    expect(resolved.blocks).not.toContain('"anchor":"resolved"');
    expect(resolved.blocks).not.toContain('"className":"resolved-section"');
    expect(resolved.blocks).not.toContain('id="resolved"');
    expect(resolved.blocks).not.toContain('resolved-section');

    expect(ordinary.blocks).not.toContain('"anchor":"ordinary"');
    expect(ordinary.blocks).not.toContain('"className":"ordinary-section"');
    expect(ordinary.blocks).not.toContain('id="ordinary"');
    expect(ordinary.blocks).not.toContain('ordinary-section');
  });

  it('keeps full-coverage native dispatch blocks', async () => {
    const spec = sectionSpec({
      headings: ['Native heading'],
      bodyText: ['Native body copy.'],
      sectionHtml: '<section><h2>Native heading</h2><p>Native body copy.</p></section>',
    });

    const [section] = await reconstruct(
      [spec],
      stageCtx(),
      fakePool(() => ''),
      {},
      0
    );

    expect(section.blocks).toContain('<!-- wp:group');
    expect(section.blocks).toContain('Native heading');
    expect(section.blocks).toContain('Native body copy.');
    expect(section.blocks).not.toContain('lib-coverage-island');
    expect(section.coverage).toBe(1);
  });

  it('rejects injected converted native blocks with unmigrated remote assets', async () => {
    const remoteImage = 'https://cdn.example.com/uploads/hero.jpg';
    // No sectionHtml: when the injected converted block is rejected the section
    // falls through to the native renderer (not the verbatim island), so the
    // unmigratable remote image becomes an image-lost placeholder.
    const spec = sectionSpec({
      headings: ['Remote asset hero'],
      bodyText: ['Coverage can pass while asset provenance fails.'],
      images: [sectionImage(remoteImage)],
    });
    const convertedNative = [
      '<!-- wp:heading -->',
      '<h2>Remote asset hero</h2>',
      '<!-- /wp:heading -->',
      '<!-- wp:paragraph -->',
      '<p>Coverage can pass while asset provenance fails.</p>',
      '<!-- /wp:paragraph -->',
      '<!-- wp:image -->',
      `<figure class="wp-block-image"><img src="${remoteImage}" alt="Hero image"></figure>`,
      '<!-- /wp:image -->',
    ].join('\n');

    expect(hasUnmigratedRemoteAsset(convertedNative)).toBe(true);

    const [section] = await reconstruct(
      [spec],
      stageCtx({
        convertedSections: new Map([[spec.sectionIndex, { markup: convertedNative, wpHtmlResidue: 0 }]]),
      }),
      fakePool(() => ''),
      {},
      0,
      { strategy: classifySemanticStrategy }
    );

    expect(section.blocks).toContain('[image unavailable');
    expect(section.blocks).not.toContain(remoteImage);
    expect(section.blocks).not.toContain('lib-coverage-island');
    expect(section.blocks).not.toBe(convertedNative);
    expect(section.coverage).toBe(0);
  });

  it('rejects injected converted native blocks with injection or placeholders', async () => {
    // No sectionHtml: a rejected converted block (injection / placeholder) falls
    // through to the native renderer, which rebuilds clean blocks from the spec.
    const injectionSpec = sectionSpec({
      headings: ['Safe hero'],
      bodyText: ['Fallback copy survives.'],
    });
    const injectedNative = [
      '<!-- wp:heading -->',
      '<h2>Safe hero</h2>',
      '<!-- /wp:heading -->',
      '<!-- wp:paragraph -->',
      '<p>Fallback copy survives.</p>',
      '<!-- /wp:paragraph -->',
      '<script>alert(1)</script>',
    ].join('\n');

    const [injectedSection] = await reconstruct(
      [injectionSpec],
      stageCtx({
        convertedSections: new Map([[injectionSpec.sectionIndex, { markup: injectedNative, wpHtmlResidue: 0 }]]),
      }),
      fakePool(() => ''),
      {},
      0,
      { strategy: classifySemanticStrategy }
    );

    expect(injectedSection.blocks).toContain('Fallback copy survives.');
    expect(injectedSection.blocks).not.toContain('<script');
    expect(injectedSection.blocks).not.toBe(injectedNative);
    expect(injectedSection.coverage).toBe(1);

    const placeholderSpec = sectionSpec({
      headings: ['Personalized hero'],
      bodyText: ['Clean fallback copy.'],
    });
    const placeholderNative = [
      '<!-- wp:heading -->',
      '<h2>Personalized hero</h2>',
      '<!-- /wp:heading -->',
      '<!-- wp:paragraph -->',
      '<p>Clean fallback copy.</p>',
      '<!-- /wp:paragraph -->',
      '<!-- wp:paragraph -->',
      '<p>{{first-name}}</p>',
      '<!-- /wp:paragraph -->',
    ].join('\n');

    const [placeholderSection] = await reconstruct(
      [placeholderSpec],
      stageCtx({
        convertedSections: new Map([[placeholderSpec.sectionIndex, { markup: placeholderNative, wpHtmlResidue: 0 }]]),
      }),
      fakePool(() => ''),
      {},
      0,
      { strategy: classifySemanticStrategy }
    );

    expect(placeholderSection.blocks).toContain('Clean fallback copy.');
    expect(placeholderSection.blocks).not.toContain('{{first-name}}');
    expect(placeholderSection.blocks).not.toBe(placeholderNative);
    expect(placeholderSection.coverage).toBe(1);
  });

  it('keeps injected converted native blocks when converted coverage matches image basename', async () => {
    const remoteImage = 'https://cdn.example.com/uploads/hero.jpg?width=1200';
    const uploadsImage = '/wp-content/uploads/2026/06/hero.jpg';
    const spec = sectionSpec({
      headings: ['Native image hero'],
      bodyText: ['Converted image basename is enough.'],
      images: [sectionImage(remoteImage)],
      sectionHtml: [
        '<section>',
        '<h2>Native image hero</h2>',
        '<p>Converted image basename is enough.</p>',
        `<img src="${remoteImage}" alt="Hero image">`,
        '</section>',
      ].join(''),
    });
    const nativeBlocks = [
      '<!-- wp:heading -->',
      '<h2>Native image hero</h2>',
      '<!-- /wp:heading -->',
      '<!-- wp:paragraph -->',
      '<p>Converted image basename is enough.</p>',
      '<!-- /wp:paragraph -->',
      '<!-- wp:image -->',
      `<figure class="wp-block-image"><img src="${uploadsImage}" alt="Hero image"></figure>`,
      '<!-- /wp:image -->',
    ].join('\n');

    const [section] = await reconstruct(
      [spec],
      stageCtx({
        mediaUrlMap: new Map([[remoteImage, uploadsImage]]),
        convertedSections: new Map([[spec.sectionIndex, { markup: nativeBlocks, wpHtmlResidue: 0 }]]),
      }),
      fakePool(() => ''),
      {},
      0,
      { strategy: classifySemanticStrategy }
    );

    expect(hasUnmigratedRemoteAsset(nativeBlocks)).toBe(false);
    expect(section.blocks).toBe(nativeBlocks);
    expect(section.blocks).not.toContain('lib-coverage-island');
    expect(section.coverage).toBe(1);
  });

  it('fires onSection for low coverage and form remainder, not full-coverage non-form sections', async () => {
    // No sectionHtml: the native renderer runs and emits an image-lost placeholder
    // (coverage 0), so onSection fires for this low-coverage section.
    const lowCoverage = sectionSpec({
      sectionIndex: 0,
      headings: ['Hooked hero'],
      images: [sectionImage('https://cdn.example.com/uploads/missing.jpg')],
    });
    const formSection = sectionSpec({
      sectionIndex: 1,
      headings: ['Contact us'],
      bodyText: ['Send a note.'],
      forms: [
        {
          fields: [{ kind: 'email', label: 'Email', required: true }],
          submitLabel: 'Send',
        },
      ],
    });
    const fullCoverage = sectionSpec({
      sectionIndex: 2,
      headings: ['Plain section'],
      bodyText: ['No hook needed.'],
    });
    const seen: Array<{ sectionIndex: number; coverage: number; forms: number }> = [];

    const sections = await reconstruct(
      [lowCoverage, formSection, fullCoverage],
      stageCtx(),
      fakePool(() => ''),
      {
        async onSection(section) {
          seen.push({
            sectionIndex: section.spec.sectionIndex,
            coverage: section.coverage,
            forms: section.remainder?.forms.length ?? 0,
          });
          return {
            ...section,
            blocks: `${section.blocks}\n<!-- hooked -->`,
          };
        },
      },
      0,
      { strategy: classifySemanticStrategy }
    );

    expect(seen).toEqual([
      { sectionIndex: 0, coverage: 0, forms: 0 },
      { sectionIndex: 1, coverage: 1, forms: 1 },
    ]);
    expect(sections[0].blocks).toContain('[image unavailable');
    expect(sections[0].blocks).not.toContain('https://cdn.example.com/uploads/missing.jpg');
    expect(sections[0].blocks).toContain('<!-- hooked -->');
    expect(sections[1].blocks).toContain('jetpack/contact-form');
    expect(sections[1].blocks).toContain('<!-- hooked -->');
    expect(sections[2].coverage).toBe(1);
    expect(sections[2].blocks).not.toContain('<!-- hooked -->');
  });
});
