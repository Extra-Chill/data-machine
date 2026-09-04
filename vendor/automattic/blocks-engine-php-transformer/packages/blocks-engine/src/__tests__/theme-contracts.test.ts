import { describe, expect, it } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import type { WorkerPool } from '../pool/types.js';
import type { ConversionFinding } from '../report/schema.js';
import {
  assemble,
  applyHoistSwaps,
  buildFallbackDiagnostic,
  buildTriageRemovalDiagnostic,
  extractSourceLandmarksFromHtml,
  foundation,
  formToBlocks,
  hoistVariations,
  HOIST_MIN_INSTANCES,
  ingest,
  landmarkRoleForHtmlRoot,
  planTemplates,
  reconstruct,
  reconcileRegions,
  sectionExtract,
  selectorForHtmlRoot,
  siteToTheme,
  SKIPPED_FIELD_KINDS,
  writeTheme,
  type AssetFile,
  type AssetInventory,
  type AssetVerdicts,
  type CoverageResult,
  type FoundationAggregates,
  type FoundationTokens,
  type FormBlocksResult,
  type HoistedVariation,
  type HoistPage,
  type HoistResult,
  type PlacedRegion,
  type RegionSelectionReport,
  type SectionBlocks,
  type SectionSpec,
  type SectionSpecButton,
  type SectionSpecCell,
  type SectionSpecForm,
  type SectionSpecFormField,
  type SectionSpecIcon,
  type SectionSpecImage,
  type SectionSpecLayout,
  type SectionSpecMotion,
  type SectionRenderOptions,
  type SiteModel,
  type SitePage,
  type SiteToThemeHooks,
  type SiteToThemeOptions,
  type StageCtx,
  type TemplatePlan,
  type ThemeBuildResult,
  type ThemeConversionDiagnostics,
  type ThemeConversionDiagnosticGroup,
  type ThemeConversionPageDiagnostic,
  type ThemeDiagnostics,
  type ThemeMeta,
  type ThemeModel,
  type SourceLandmark,
} from '../theme/index.js';

type Satisfies<T, U extends T> = U;

type ThemeStageSignatures = {
  siteToTheme: (srcDir: string, options?: SiteToThemeOptions) => Promise<ThemeBuildResult>;
  writeTheme: (model: ThemeModel, outDir: string) => Promise<string[]>;
  ingest: (srcDir: string) => SiteModel;
  sectionExtract: (page: SitePage) => SectionSpec[];
  foundation: (site: SiteModel, aggregates?: FoundationAggregates) => FoundationTokens;
  planTemplates: (site: SiteModel) => TemplatePlan;
  reconstruct: (
    specs: SectionSpec[],
    ctx: StageCtx,
    pool: WorkerPool,
    hooks: SiteToThemeHooks,
    coverageFloor: number,
    renderOptions?: SectionRenderOptions
  ) => Promise<SectionBlocks[]>;
  assemble: (parts: {
    site: SiteModel;
    tokens: FoundationTokens;
    pages: Record<string, SectionBlocks[]>;
    meta: ThemeMeta;
  }) => ThemeModel;
  hoistVariations: (pagesIn: HoistPage[], opts?: { minInstances?: number }) => HoistResult;
  applyHoistSwaps: (markup: string, variations: HoistedVariation[]) => string;
  reconcileRegions: (
    census: SourceLandmark[],
    placed: PlacedRegion[],
    page?: string,
    entryUrl?: string
  ) => RegionSelectionReport;
  extractSourceLandmarksFromHtml: (html: string) => SourceLandmark[];
  selectorForHtmlRoot: (html: string) => string | undefined;
  landmarkRoleForHtmlRoot: (html: string) => SourceLandmark['role'] | undefined;
};

const themeStageSignatures: ThemeStageSignatures = {
  siteToTheme,
  writeTheme,
  ingest,
  sectionExtract,
  foundation,
  planTemplates,
  reconstruct,
  assemble,
  hoistVariations,
  applyHoistSwaps,
  reconcileRegions,
  extractSourceLandmarksFromHtml,
  selectorForHtmlRoot,
  landmarkRoleForHtmlRoot,
};

void themeStageSignatures;

type CompileOnlyThemeContractAssignments = {
  siteModel: Satisfies<
    SiteModel,
    {
      root: string;
      pages: SitePage[];
    }
  >;
  sitePage: Satisfies<
    SitePage,
    {
      relPath: string;
      slug: string;
      html: string;
      title: string;
      bodyData: Record<string, string>;
    }
  >;
  sectionSpecImage: Satisfies<
    SectionSpecImage,
    {
      url: string;
      sourceUrl: string;
      alt: string;
      kind: 'img';
      width: number;
      height: number;
    }
  >;
  sectionSpecIcon: Satisfies<
    SectionSpecIcon,
    {
      kind: 'glyph';
      glyph: string;
      width: number;
      height: number;
    }
  >;
  sectionSpecButton: Satisfies<
    SectionSpecButton,
    {
      label: string;
      href: string;
      background: string | null;
      color: string | null;
      icon: SectionSpecIcon | null;
      iconAfter: boolean;
    }
  >;
  sectionSpecFormField: Satisfies<
    SectionSpecFormField,
    {
      kind: 'email';
      label: string;
      required: boolean;
      placeholder: string;
      defaultValue: string;
      options: string[];
      widthPct: 100;
    }
  >;
  sectionSpecForm: Satisfies<
    SectionSpecForm,
    {
      fields: SectionSpecFormField[];
      submitLabel: string;
    }
  >;
  sectionSpecCell: Satisfies<
    SectionSpecCell,
    {
      heading: string | null;
      body: string[];
      image: SectionSpecImage | null;
      icon: SectionSpecIcon | null;
      button: string | null;
    }
  >;
  sectionSpecMotion: Satisfies<
    SectionSpecMotion,
    {
      motionClass: 'none';
      signals: string[];
      animatedElements: number;
    }
  >;
  sectionSpecLayout: Satisfies<
    SectionSpecLayout,
    {
      containerWidth: number;
      padding: string;
      childLayout: 'stack';
      columnCount: number;
      gap: string;
    }
  >;
  sectionSpec: Satisfies<
    SectionSpec,
    {
      sectionIndex: number;
      interactionModel: 'static';
      top: number;
      height: number;
      headings: string[];
      bodyText: string[];
      buttonLabels: string[];
      images: SectionSpecImage[];
      icons: SectionSpecIcon[];
      backgroundBrightness: number;
      backgroundColor: string;
      gradient: string | null;
      gradientSource: null;
      motionProfile: SectionSpecMotion;
      dividerAbove: { color: string; thickness: number } | null;
      dividerBelow: { color: string; thickness: number } | null;
      layout: SectionSpecLayout;
      cells: SectionSpecCell[];
      forms: SectionSpecForm[];
    }
  >;
  foundationTokens: Satisfies<
    FoundationTokens,
    {
      palette: { name: string; color: string }[];
      typography: { body: string; display: string };
      breakpoints: { md: string; lg: string; xl: string };
    }
  >;
  foundationAggregates: Satisfies<
    FoundationAggregates,
    {
      palette: unknown;
      typography: unknown;
      breakpoints: unknown;
    }
  >;
  assetFile: Satisfies<
    AssetFile,
    {
      relPath: string;
      bytes: Uint8Array;
      sourcePath: string;
    }
  >;
  themeModel: Satisfies<
    ThemeModel,
    {
      styleCss: string;
      themeJson: Record<string, unknown>;
      templates: Record<string, string>;
      parts: Record<string, string>;
      patterns: Record<string, string>;
      styleBlocks: Record<string, Record<string, unknown>>;
      assets: AssetFile[];
    }
  >;
  templatePlan: Satisfies<
    TemplatePlan,
    {
      templatesByPage: Record<string, 'front-page' | 'page'>;
    }
  >;
  sectionBlocks: Satisfies<
    SectionBlocks,
    {
      spec: SectionSpec;
      blocks: string;
      coverage: number;
    }
  >;
  assetInventory: Satisfies<
    AssetInventory,
    {
      assets: AssetFile[];
    }
  >;
  assetVerdicts: Satisfies<
    AssetVerdicts,
    {
      keep: string[];
      decoration: string[];
    }
  >;
  stageCtx: Satisfies<
    StageCtx,
    {
      srcDir: string;
      site: SiteModel;
      themeMeta: ThemeMeta;
      warn(msg: string): void;
    }
  >;
  themeMeta: Satisfies<
    ThemeMeta,
    {
      name: string;
      slug: string;
      author: string;
    }
  >;
  themeConversionPageDiagnostic: Satisfies<
    ThemeConversionPageDiagnostic,
    {
      slug: string;
      status: 'success';
      fallbackCount: number;
      degraded: boolean;
      findings: ConversionFinding[];
      findingsTruncated: boolean;
    }
  >;
  themeConversionDiagnostics: Satisfies<
    ThemeConversionDiagnostics,
    {
      pages: ThemeConversionPageDiagnostic[];
      groups: ThemeConversionDiagnosticGroup[];
      occurrenceCount: number;
      repairFamilyCount: number;
      repairFamilyCountTruncated: boolean;
      unrepresentedFallbackOccurrenceCount: number;
      unrepresentedFallbackDistinctCount: number;
      totalFallbacks: number;
      pagesWithFallbacks: number;
      degradedPages: number;
    }
  >;
  themeDiagnostics: Satisfies<
    ThemeDiagnostics,
    {
      conversion: ThemeConversionDiagnostics;
      regionAudit: RegionSelectionReport[];
    }
  >;
  siteToThemeHooks: Satisfies<
    SiteToThemeHooks,
    {
      onFoundation(tokens: FoundationTokens, ctx: StageCtx): Promise<FoundationTokens>;
      onSection(section: SectionBlocks, ctx: StageCtx): Promise<SectionBlocks>;
      onAssets(inventory: AssetInventory, ctx: StageCtx): Promise<AssetVerdicts>;
      onRefine(theme: ThemeModel, ctx: StageCtx): Promise<ThemeModel>;
    }
  >;
  siteToThemeOptions: Satisfies<
    SiteToThemeOptions,
    {
      outDir: string;
      sections: Record<string, SectionSpec[]>;
      renderOptions: Record<string, SectionRenderOptions>;
      variationHoist: false;
      foundationAggregates: FoundationAggregates;
      hooks: SiteToThemeHooks;
      fetchImpl: typeof fetch;
      coverageFloor: number;
      themeMeta: Partial<ThemeMeta>;
    }
  >;
  themeBuildResult: Satisfies<
    ThemeBuildResult,
    {
      outDir: string;
      model: ThemeModel;
      written: string[];
      tallies: Record<string, number>;
      warnings: string[];
      diagnostics: ThemeDiagnostics;
    }
  >;
};

void (null as never as CompileOnlyThemeContractAssignments);

function formField(
  partial: Partial<SectionSpecFormField> & Pick<SectionSpecFormField, 'kind' | 'label'>,
): SectionSpecFormField {
  return { required: false, ...partial };
}

function sectionForm(fields: SectionSpecFormField[], submitLabel = 'Send'): SectionSpecForm {
  return { fields, submitLabel };
}

function assertBalanced(markup: string): void {
  const stack: string[] = [];
  for (const m of markup.matchAll(/<!--\s*(\/)?wp:([a-z0-9/-]+)([^>]*?)(\/)?-->/g)) {
    if (m[1]) expect(stack.pop()).toBe(m[2]);
    else if (m[4] !== '/') stack.push(m[2]);
  }
  expect(stack).toEqual([]);
}

describe('region audit diagnostic surface contract', () => {
  it('exports browser-free region audit helpers for diagnostic consumers', () => {
    expect(typeof reconcileRegions).toBe('function');
    expect(typeof extractSourceLandmarksFromHtml).toBe('function');
    expect(typeof selectorForHtmlRoot).toBe('function');
    expect(typeof landmarkRoleForHtmlRoot).toBe('function');
  });
});

function diagnosticSection(partial: Partial<SectionSpec> = {}): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 100,
    headings: [],
    bodyText: [],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 1,
    backgroundColor: '#ffffff',
    gradient: null,
    gradientSource: null,
    motionProfile: { motionClass: 'none', signals: [], animatedElements: 0 },
    dividerAbove: null,
    dividerBelow: null,
    layout: { containerWidth: 960, padding: '0', childLayout: 'stack', columnCount: 1, gap: '0' },
    cells: [],
    forms: [],
    sectionHtml: '<section></section>',
    ...partial,
  };
}

function diagnosticCoverage(partial: Partial<CoverageResult> = {}): CoverageResult {
  return { textCoverage: 1, missingImages: [], lost: false, ...partial };
}

describe('theme public contract', () => {
  it('exports runtime stage functions with fixed arity', () => {
    const runtimeStages = [
      [siteToTheme, 2],
      [writeTheme, 2],
      [ingest, 1],
      [sectionExtract, 1],
      [foundation, 2],
      [planTemplates, 1],
      [reconstruct, 5],
      [assemble, 1],
      [hoistVariations, 1],
      [applyHoistSwaps, 2],
    ] as const;

    for (const [stage, expectedArity] of runtimeStages) {
      expect(stage).toBeTypeOf('function');
      expect(stage).toHaveLength(expectedArity);
    }
  });

  it('freezes the variation hoist public constants', () => {
    expect(HOIST_MIN_INSTANCES).toBe(3);
  });

  it('writeTheme emits additive block style variation files only when provided', async () => {
    const dir = await mkdtemp(join(tmpdir(), 'blocks-engine-theme-contract-'));
    try {
      const written = await writeTheme(
        {
          styleCss: '',
          themeJson: { version: 3 },
          templates: {},
          parts: {},
          patterns: {},
          styleBlocks: {
            'lib-paragraph-color.json': {
              version: 3,
              title: 'Paragraph color',
              blockTypes: ['core/paragraph'],
              styles: { color: { text: '#111111' } },
            },
          },
          assets: [],
        },
        dir
      );

      expect(written).toContain('styles/blocks/lib-paragraph-color.json');
      expect(
        JSON.parse(readFileSync(join(dir, 'styles', 'blocks', 'lib-paragraph-color.json'), 'utf8'))
      ).toEqual({
        version: 3,
        title: 'Paragraph color',
        blockTypes: ['core/paragraph'],
        styles: { color: { text: '#111111' } },
      });
    } finally {
      await rm(dir, { force: true, recursive: true });
    }
  });

  it('writeTheme preserves existing output shape when styleBlocks is absent', async () => {
    const dir = await mkdtemp(join(tmpdir(), 'blocks-engine-theme-contract-'));
    try {
      const written = await writeTheme(
        {
          styleCss: '',
          themeJson: { version: 3 },
          templates: {},
          parts: {},
          patterns: {},
          assets: [],
        },
        dir
      );

      expect(written).toEqual(['style.css', 'theme.json']);
      expect(existsSync(join(dir, 'styles'))).toBe(false);
    } finally {
      await rm(dir, { force: true, recursive: true });
    }
  });

});

describe('fallback diagnostics DLA parity', () => {
  it('emits dropped_images reasonCode with media-first precedence when text coverage is also below floor', () => {
    const section = diagnosticSection({
      sectionIndex: 7,
      interactionModel: 'media-text',
      selector: '#hero',
      sectionHtml: '<section class="hero-source"><h1>Launch faster</h1><p>Proof copy</p></section>',
      styledHtml: '<section class="hero"><h1>Launch faster</h1><p>Proof copy</p></section>',
    });

    expect(
      buildFallbackDiagnostic({
        page: '/home',
        slug: 'home',
        section,
        coverage: diagnosticCoverage({
          textCoverage: 0.333,
          missingImages: ['https://cdn.example.com/hero.jpg'],
          lost: true,
        }),
        islandKind: 'styled',
        islandMarkup: '<!-- wp:html --><div><p>Launch</p></div><!-- /wp:html -->',
      }),
    ).toEqual({
      id: 'home-s7-dropped_images',
      page: '/home',
      sectionIndex: 7,
      interactionModel: 'media-text',
      selector: '#hero',
      severity: 'warning',
      reasonCode: 'dropped_images',
      islandKind: 'styled',
      droppedImages: ['https://cdn.example.com/hero.jpg'],
      textCoverage: 0.33,
      suggestedRepairClass: 'recover_dropped_media',
      sourceHtmlPreview: '<section class="hero"><h1>Launch faster</h1><p>Proof copy</p></section>',
      emittedBlockPreview: '<!-- wp:html --><div><p>Launch</p></div><!-- /wp:html -->',
    });
  });

  it('emits text_coverage_below_floor reasonCode when text is below the DLA floor without dropped media', () => {
    const section = diagnosticSection({
      sectionIndex: 3,
      interactionModel: 'cta',
      selector: '.cta',
      sectionHtml: '<section><h2>Book now</h2><p>Schedule a repair.</p></section>',
    });

    expect(
      buildFallbackDiagnostic({
        page: '/service',
        slug: 'service',
        section,
        coverage: diagnosticCoverage({
          textCoverage: 0.494,
          lost: true,
        }),
        islandKind: 'verbatim',
        islandMarkup: '<!-- wp:paragraph --><p>Book now</p><!-- /wp:paragraph -->',
      }),
    ).toEqual({
      id: 'service-s3-text_coverage_below_floor',
      page: '/service',
      sectionIndex: 3,
      interactionModel: 'cta',
      selector: '.cta',
      severity: 'warning',
      reasonCode: 'text_coverage_below_floor',
      islandKind: 'verbatim',
      droppedImages: [],
      textCoverage: 0.49,
      suggestedRepairClass: 'restructure_section_blocks',
      sourceHtmlPreview: '<section><h2>Book now</h2><p>Schedule a repair.</p></section>',
      emittedBlockPreview: '<!-- wp:paragraph --><p>Book now</p><!-- /wp:paragraph -->',
    });
  });

  it('passes through verbatim, styled, and responsive islandKind classifications', () => {
    const islandKinds = ['verbatim', 'styled', 'responsive'] as const;

    expect(
      islandKinds.map((islandKind, index) =>
        buildFallbackDiagnostic({
          page: '/landing',
          slug: 'landing',
          section: diagnosticSection({
            sectionIndex: index,
            interactionModel: 'static',
            selector: `.section-${index}`,
            sectionHtml: `<section><p>Section ${index}</p></section>`,
          }),
          coverage: diagnosticCoverage({ textCoverage: 0.25, lost: true }),
          islandKind,
          islandMarkup: `<p>Island ${index}</p>`,
        }),
      ),
    ).toEqual([
      {
        id: 'landing-s0-text_coverage_below_floor',
        page: '/landing',
        sectionIndex: 0,
        interactionModel: 'static',
        selector: '.section-0',
        severity: 'warning',
        reasonCode: 'text_coverage_below_floor',
        islandKind: 'verbatim',
        droppedImages: [],
        textCoverage: 0.25,
        suggestedRepairClass: 'restructure_section_blocks',
        sourceHtmlPreview: '<section><p>Section 0</p></section>',
        emittedBlockPreview: '<p>Island 0</p>',
      },
      {
        id: 'landing-s1-text_coverage_below_floor',
        page: '/landing',
        sectionIndex: 1,
        interactionModel: 'static',
        selector: '.section-1',
        severity: 'warning',
        reasonCode: 'text_coverage_below_floor',
        islandKind: 'styled',
        droppedImages: [],
        textCoverage: 0.25,
        suggestedRepairClass: 'restructure_section_blocks',
        sourceHtmlPreview: '<section><p>Section 1</p></section>',
        emittedBlockPreview: '<p>Island 1</p>',
      },
      {
        id: 'landing-s2-text_coverage_below_floor',
        page: '/landing',
        sectionIndex: 2,
        interactionModel: 'static',
        selector: '.section-2',
        severity: 'warning',
        reasonCode: 'text_coverage_below_floor',
        islandKind: 'responsive',
        droppedImages: [],
        textCoverage: 0.25,
        suggestedRepairClass: 'restructure_section_blocks',
        sourceHtmlPreview: '<section><p>Section 2</p></section>',
        emittedBlockPreview: '<p>Island 2</p>',
      },
    ]);
  });

  it('matches the DLA decorative_asset_triaged diagnostic record shape', () => {
    expect(
      buildTriageRemovalDiagnostic({
        page: '/about',
        slug: 'about',
        sectionIndex: 2,
        interactionModel: 'static',
        removal: {
          url: 'https://cdn.example.com/divider.png',
          sectionSelector: 'section:nth-of-type(3)',
          description: 'Thin gold divider line between testimonials.',
        },
        ordinal: 1,
      }),
    ).toEqual({
      id: 'about-s2-decorative_asset_triaged-1',
      page: '/about',
      sectionIndex: 2,
      interactionModel: 'static',
      selector: 'section:nth-of-type(3)',
      severity: 'warning',
      reasonCode: 'decorative_asset_triaged',
      islandKind: 'none',
      droppedImages: ['https://cdn.example.com/divider.png'],
      textCoverage: 1,
      suggestedRepairClass: 'replace_with_structural_block',
      sourceHtmlPreview: 'Thin gold divider line between testimonials.',
      emittedBlockPreview: '',
    });
  });
});

describe('formToBlocks DLA parity', () => {
  it('exports the DLA form-blocks surface and emits jetpack/field-* blocks for every mapped field kind', () => {
    const formToBlocksType: (form: SectionSpecForm) => FormBlocksResult = formToBlocks;
    void formToBlocksType;

    expect(formToBlocks).toBeTypeOf('function');
    expect(SKIPPED_FIELD_KINDS.has('file')).toBe(true);
    expect(SKIPPED_FIELD_KINDS.has('hidden')).toBe(true);

    const expected: Array<[SectionSpecFormField['kind'], string]> = [
      ['text', '<!-- wp:jetpack/field-text {"label":"Fictional label"} /-->'],
      ['name', '<!-- wp:jetpack/field-name {"label":"Fictional label"} /-->'],
      ['email', '<!-- wp:jetpack/field-email {"label":"Fictional label"} /-->'],
      ['tel', '<!-- wp:jetpack/field-telephone {"label":"Fictional label"} /-->'],
      ['url', '<!-- wp:jetpack/field-url {"label":"Fictional label"} /-->'],
      ['number', '<!-- wp:jetpack/field-number {"label":"Fictional label"} /-->'],
      ['date', '<!-- wp:jetpack/field-date {"label":"Fictional label"} /-->'],
      ['textarea', '<!-- wp:jetpack/field-textarea {"label":"Fictional label"} /-->'],
      ['checkbox', '<!-- wp:jetpack/field-checkbox {"label":"Fictional label"} /-->'],
      ['consent', '<!-- wp:jetpack/field-consent {"label":"Fictional label","consentType":"implicit"} /-->'],
    ];

    for (const [kind, golden] of expected) {
      expect(formToBlocks(sectionForm([formField({ kind, label: 'Fictional label' })])).markup).toContain(golden);
    }
  });

  it('keeps authored options in DLA attr grammar for radio, select, and checkbox groups', () => {
    const radio = formToBlocks(
      sectionForm([formField({ kind: 'radio', label: 'Size', options: ['Small', 'Large', 'Medium'] })]),
    );
    const select = formToBlocks(
      sectionForm([formField({ kind: 'select', label: 'Topic', options: ['Billing', 'Support', 'Other'] })]),
    );
    const checkbox = formToBlocks(
      sectionForm([formField({ kind: 'checkbox', label: 'Classes', options: ['Yoga', 'Pilates'] })]),
    );

    expect(radio.markup).toContain(
      '<!-- wp:jetpack/field-radio {"label":"Size","options":["Small","Large","Medium"]} /-->',
    );
    expect(select.markup).toContain(
      '<!-- wp:jetpack/field-select {"label":"Topic","options":["Billing","Support","Other"]} /-->',
    );
    expect(checkbox.markup).toContain(
      '<!-- wp:jetpack/field-checkbox-multiple {"label":"Classes","options":["Yoga","Pilates"]} /-->',
    );
    expect(checkbox.markup).not.toContain('wp:jetpack/field-checkbox {');
  });

  it('emits the Jetpack wrapper and canonical core/button submit with the label in inner HTML', () => {
    const result = formToBlocks(sectionForm([formField({ kind: 'email', label: 'Email' })], 'Request a Quote'));

    expect(result.markup.startsWith('<!-- wp:jetpack/contact-form ')).toBe(true);
    expect(result.markup).toContain('<div class="wp-block-jetpack-contact-form">');
    expect(result.markup).toContain(
      '<!-- wp:button {"tagName":"button","type":"submit","lock":{"remove":true},"className":"form-button-submit is-submit","metadata":{"name":"Submit button"}} -->\n' +
        '<div class="wp-block-button form-button-submit is-submit"><button type="submit" class="wp-block-button__link wp-element-button">Request a Quote</button></div>\n' +
        '<!-- /wp:button -->',
    );
    expect(result.markup).not.toContain('jetpack/button');
    expect(result.markup).not.toContain('wp:columns');
    assertBalanced(result.markup);
  });

  it('matches the DLA multi-field golden, including stable attr order and skipped fields', () => {
    const result = formToBlocks(
      sectionForm(
        [
          formField({ kind: 'name', label: 'Name', required: true, widthPct: 50 }),
          formField({ kind: 'email', label: 'Email', required: true, placeholder: 'you@example.com', widthPct: 50 }),
          formField({ kind: 'select', label: 'Topic', options: ['Billing', 'Support'] }),
          formField({ kind: 'textarea', label: 'Message', defaultValue: 'Hello' }),
          formField({ kind: 'consent', label: 'I agree to the privacy policy' }),
          formField({ kind: 'file', label: 'Resume upload' }),
          formField({ kind: 'hidden', label: 'Campaign id' }),
        ],
        'Send Message',
      ),
    );

    expect(result.markup).toBe(
      '<!-- wp:jetpack/contact-form {"style":{"spacing":{"padding":{"top":"16px","right":"16px","bottom":"16px","left":"16px"}}}} -->\n' +
        '<div class="wp-block-jetpack-contact-form">\n' +
        '<!-- wp:jetpack/field-name {"label":"Name","required":true,"width":50} /-->\n' +
        '<!-- wp:jetpack/field-email {"label":"Email","required":true,"placeholder":"you@example.com","width":50} /-->\n' +
        '<!-- wp:jetpack/field-select {"label":"Topic","options":["Billing","Support"]} /-->\n' +
        '<!-- wp:jetpack/field-textarea {"label":"Message","defaultValue":"Hello"} /-->\n' +
        '<!-- wp:jetpack/field-consent {"label":"I agree to the privacy policy","consentType":"implicit"} /-->\n' +
        '<!-- wp:button {"tagName":"button","type":"submit","lock":{"remove":true},"className":"form-button-submit is-submit","metadata":{"name":"Submit button"}} -->\n' +
        '<div class="wp-block-button form-button-submit is-submit"><button type="submit" class="wp-block-button__link wp-element-button">Send Message</button></div>\n' +
        '<!-- /wp:button -->\n' +
        '</div>\n' +
        '<!-- /wp:jetpack/contact-form -->',
    );
    expect(result.skipped).toEqual([
      { kind: 'file', label: 'Resume upload' },
      { kind: 'hidden', label: 'Campaign id' },
    ]);
    expect(result.markup).not.toContain('Resume upload');
    expect(result.markup).not.toContain('Campaign id');
  });

  it('matches the DLA zero-field golden and escapes hostile labels like the DLA serializer', () => {
    const result = formToBlocks(sectionForm([], 'Continue'));

    expect(result.markup).toBe(
      '<!-- wp:jetpack/contact-form {"style":{"spacing":{"padding":{"top":"16px","right":"16px","bottom":"16px","left":"16px"}}}} -->\n' +
        '<div class="wp-block-jetpack-contact-form">\n' +
        '<!-- wp:button {"tagName":"button","type":"submit","lock":{"remove":true},"className":"form-button-submit is-submit","metadata":{"name":"Submit button"}} -->\n' +
        '<div class="wp-block-button form-button-submit is-submit"><button type="submit" class="wp-block-button__link wp-element-button">Continue</button></div>\n' +
        '<!-- /wp:button -->\n' +
        '</div>\n' +
        '<!-- /wp:jetpack/contact-form -->',
    );
    expect(result.markup).not.toContain('wp:jetpack/field-');
    expect(result.skipped).toEqual([]);
    assertBalanced(result.markup);

    const escaped = formToBlocks(
      sectionForm([formField({ kind: 'text', label: 'a --> b & <c> "d"' })], 'Send --> & <go>'),
    );

    expect(escaped.markup).toContain(
      '<!-- wp:jetpack/field-text {"label":"a \\u002d\\u002d\\u003e b \\u0026 \\u003cc\\u003e \\u0022d\\u0022"} /-->',
    );
    expect(escaped.markup).toContain('>Send --&gt; &amp; &lt;go&gt;</button>');
    expect(escaped.markup).not.toContain('--\\u003e');
    assertBalanced(escaped.markup);
  });
});
