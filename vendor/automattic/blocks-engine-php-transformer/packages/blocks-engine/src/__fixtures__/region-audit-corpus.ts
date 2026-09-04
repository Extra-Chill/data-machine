import type {
  PlacedRegion,
  RegionSelectionReport,
  SourceLandmark,
} from '../theme/index.js';

export const DLA_REGION_AUDIT_COMMIT = '1e393c535850ee1a9482f83459f779d0e225b027';
export const DLA_REGION_AUDIT_PATH = 'src/lib/replicate/region-audit.ts';
export const DLA_REGION_CENSUS_PATH = 'src/lib/replicate/region-census.ts';
export const DLA_SECTION_SELECTOR_PATH = 'src/lib/replicate/section-selector.ts';
export const DLA_REGION_AUDIT_BLOB = '3e4bff0205056183fb3b09612df6caf5018a844f';
export const DLA_REGION_CENSUS_BLOB = 'c447895fe50d42395ea4a4b8786467bef9eeabda';
export const DLA_SECTION_SELECTOR_BLOB = 'a900bd3d4fe719af039faf9c586b17ae19403c38';

export const REGION_AUDIT_DERIVATION =
  'DLA region-audit + region-census over source HTML and explicit placed-region corpus';

export interface RegionAuditCorpusCase {
  id: string;
  page: string;
  entryUrl: string;
  sourceHtml: string;
  placed: PlacedRegion[];
}

export interface RegionAuditImpl {
  extractSourceLandmarksFromHtml(html: string): SourceLandmark[];
  selectorForHtmlRoot(html: string): string | undefined;
  landmarkRoleForHtmlRoot(html: string): SourceLandmark['role'] | undefined;
  reconcileRegions(
    census: SourceLandmark[],
    placed: PlacedRegion[],
    page?: string,
    entryUrl?: string
  ): RegionSelectionReport;
}

export interface RegionAuditCaseRecord {
  id: string;
  input: RegionAuditCorpusCase;
  rootSelector?: string;
  rootRole?: SourceLandmark['role'];
  census: SourceLandmark[];
  report: RegionSelectionReport;
}

export interface RegionAuditParityFile {
  version: 1;
  derivation: string;
  oracle: {
    commit: string;
    paths: {
      regionAudit: string;
      regionCensus: string;
      sectionSelector: string;
    };
    blobs: {
      regionAudit: string;
      regionCensus: string;
      sectionSelector: string;
    };
  };
  cases: RegionAuditCaseRecord[];
}

export function regionAuditCases(): RegionAuditCorpusCase[] {
  return [
    {
      id: 'home-header-body-footer',
      page: 'home',
      entryUrl: 'index.html',
      sourceHtml: [
        '<!doctype html><html><body>',
        '<header id="mast"><nav class="main-menu"><a href="/">Home</a><a href="/shop">Shop</a></nav></header>',
        '<main id="content"><section class="hero"><h1>Welcome</h1><p>Useful body copy long enough for actionability.</p><img src="hero.jpg"></section></main>',
        '<footer><a href="/privacy">Privacy</a><a href="/contact">Contact</a></footer>',
        '</body></html>',
      ].join(''),
      placed: [
        { kind: 'header_part', role: 'header', selector: 'header#mast' },
        { kind: 'page_body_section', selector: 'main#content' },
        { kind: 'footer_part', role: 'footer', selector: 'footer:nth-of-type(1)' },
      ],
    },
    {
      id: 'dropped-complementary-rail',
      page: 'home',
      entryUrl: 'index.html',
      sourceHtml: [
        '<html><body>',
        '<main id="content"><p>Main article text is present and placed in the page body.</p></main>',
        '<aside class="rail"><a href="/a">Alpha</a><a href="/b">Beta</a><p>Related links with enough rail text.</p></aside>',
        '</body></html>',
      ].join(''),
      placed: [{ kind: 'page_body_section', selector: 'main#content' }],
    },
    {
      id: 'non-actionable-skip-nav',
      page: 'home',
      entryUrl: 'index.html',
      sourceHtml: [
        '<html><body>',
        '<nav class="skip"><a href="#main">Skip</a></nav>',
        '<main id="main"><p>Primary copy remains long enough to be actionable.</p></main>',
        '</body></html>',
      ].join(''),
      placed: [{ kind: 'page_body_section', selector: 'main#main' }],
    },
    {
      id: 'repeated-section-selector-exactness',
      page: 'services',
      entryUrl: 'services/index.html',
      sourceHtml: [
        '<html><body>',
        '<section><h2>First</h2><p>First section copy long enough to audit.</p></section>',
        '<section><h2>Second</h2><p>Second section copy long enough to audit.</p></section>',
        '</body></html>',
      ].join(''),
      placed: [{ kind: 'page_body_section', selector: 'section:nth-of-type(1)' }],
    },
    {
      id: 'role-qualified-aside-match',
      page: 'about',
      entryUrl: 'about/index.html',
      sourceHtml: [
        '<html><body>',
        '<aside class="rail"><a href="/team">Team</a><a href="/careers">Careers</a><p>People rail content.</p></aside>',
        '<main><p>About page body content remains in the page template.</p></main>',
        '</body></html>',
      ].join(''),
      placed: [
        { kind: 'header_part', role: 'aside', selector: 'aside.rail' },
        { kind: 'page_body_section', selector: 'main:nth-of-type(1)' },
      ],
    },
  ];
}

export function runRegionAuditParity(impl: RegionAuditImpl): RegionAuditParityFile {
  return {
    version: 1,
    derivation: REGION_AUDIT_DERIVATION,
    oracle: {
      commit: DLA_REGION_AUDIT_COMMIT,
      paths: {
        regionAudit: DLA_REGION_AUDIT_PATH,
        regionCensus: DLA_REGION_CENSUS_PATH,
        sectionSelector: DLA_SECTION_SELECTOR_PATH,
      },
      blobs: {
        regionAudit: DLA_REGION_AUDIT_BLOB,
        regionCensus: DLA_REGION_CENSUS_BLOB,
        sectionSelector: DLA_SECTION_SELECTOR_BLOB,
      },
    },
    cases: regionAuditCases().map((testCase) => {
      const census = impl.extractSourceLandmarksFromHtml(testCase.sourceHtml);
      return {
        id: testCase.id,
        input: testCase,
        rootSelector: impl.selectorForHtmlRoot(testCase.sourceHtml),
        rootRole: impl.landmarkRoleForHtmlRoot(testCase.sourceHtml),
        census,
        report: impl.reconcileRegions(census, testCase.placed, testCase.page, testCase.entryUrl),
      };
    }),
  };
}
