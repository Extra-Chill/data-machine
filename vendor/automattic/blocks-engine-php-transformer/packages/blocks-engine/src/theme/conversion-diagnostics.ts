import { performance } from 'node:perf_hooks';
import { createHash } from 'node:crypto';

import { buildReport } from '../report/findings.js';
import type { WorkerPool } from '../pool/types.js';
import { FALLBACK_INVENTORY_CAP, type ConversionFinding } from '../report/schema.js';
import type { HtmlIslandOccurrence } from '../wp/canonicalize.js';
import type {
  SectionBlocks,
  ThemeConversionDiagnostics,
  ThemeConversionDiagnosticGroup,
  ThemeConversionPageDiagnostic,
} from './types.js';

const SOURCE_EXAMPLE_CAP = 5;

export interface ThemeConversionDiagnosticsInputPage {
  slug: string;
  inputHtml?: string;
  sections: SectionBlocks[];
}

export async function deriveThemeConversionDiagnostics(
  pages: Record<string, SectionBlocks[]>,
  pool: WorkerPool,
): Promise<ThemeConversionDiagnostics> {
  return buildThemeConversionDiagnostics(
    Object.entries(pages).map(([slug, sections]) => ({ slug, sections })),
    pool,
  );
}

export async function buildThemeConversionDiagnostics(
  pages: ThemeConversionDiagnosticsInputPage[],
  pool: WorkerPool,
): Promise<ThemeConversionDiagnostics> {
  if (pages.length === 0) {
    return emptyConversionDiagnostics();
  }

  const pageBlockMarkup = pages.map((page) => joinSectionBlocks(page.sections));
  const startedAt = performance.now();
  const fixResults = await pool.canonicalize(pageBlockMarkup);
  const transformDurationMs = (performance.now() - startedAt) / pages.length;

  const reports = pages.map((page, index) => {
    const blockMarkup = pageBlockMarkup[index] ?? '';
    const fixResult = fixResults[index];
    if (!fixResult) {
      throw new Error(`Missing canonicalize result for page "${page.slug}"`);
    }

    const report = buildReport({
      inputHtml: page.inputHtml ?? blockMarkup,
      blockMarkup: fixResult.html,
      fixResult,
      transformDurationMs,
    });

    const degraded = report.diagnostics.some(
      (finding) => finding.code === 'conversion_degraded',
    );

    return { slug: page.slug, report };
  });

  const diagnostics = reports.map(({ slug, report }): ThemeConversionPageDiagnostic => {
    const findings = [...report.fallbacks, ...report.diagnostics];
    const keptFindings = findings.slice(0, FALLBACK_INVENTORY_CAP);

    return {
      slug,
      status: report.status,
      fallbackCount: report.metrics.fallbackCount,
      degraded: report.diagnostics.some(
        (finding) => finding.code === 'conversion_degraded',
      ),
      findings: keptFindings,
      findingsTruncated: findings.length > keptFindings.length,
    };
  });

  return {
    pages: diagnostics,
    ...buildDiagnosticGroups(reports, fixResults),
    totalFallbacks: diagnostics.reduce((total, page) => total + page.fallbackCount, 0),
    pagesWithFallbacks: diagnostics.filter((page) => page.fallbackCount > 0).length,
    degradedPages: diagnostics.filter((page) => page.degraded).length,
  };
}

function emptyConversionDiagnostics(): ThemeConversionDiagnostics {
  return {
    pages: [],
    groups: [],
    occurrenceCount: 0,
    repairFamilyCount: 0,
    repairFamilyCountTruncated: false,
    unrepresentedFallbackOccurrenceCount: 0,
    unrepresentedFallbackDistinctCount: 0,
    totalFallbacks: 0,
    pagesWithFallbacks: 0,
    degradedPages: 0,
  };
}

function buildDiagnosticGroups(
  reports: Array<{ slug: string; report: { fallbacks: ConversionFinding[]; diagnostics: ConversionFinding[] } }>,
  fixResults: Array<{
    htmlIslandCount: number;
    htmlIslandOccurrences?: HtmlIslandOccurrence[];
    htmlIslandDistinctCount?: number;
    htmlIslandOccurrencesTruncated?: boolean;
  }>,
): Pick<ThemeConversionDiagnostics, 'groups' | 'occurrenceCount' | 'repairFamilyCount' | 'repairFamilyCountTruncated' | 'unrepresentedFallbackOccurrenceCount' | 'unrepresentedFallbackDistinctCount'> {
  const groups = new Map<string, ThemeConversionDiagnosticGroup & { sources: Set<string> }>();
  let fallbackOccurrenceCount = 0;
  let unrepresentedFallbackOccurrenceCount = 0;
  let unrepresentedFallbackDistinctCount = 0;

  for (const [{ slug, report }, fixResult] of reports.map((report, index) => [report, fixResults[index]] as const)) {
    const fallbackOccurrences = fixResult?.htmlIslandOccurrences;
    if (fallbackOccurrences) {
      fallbackOccurrenceCount += fixResult.htmlIslandCount;
      const sampledOccurrenceCount = fallbackOccurrences.reduce((total, occurrence) => total + occurrence.count, 0);
      unrepresentedFallbackOccurrenceCount += fixResult.htmlIslandCount - sampledOccurrenceCount;
      unrepresentedFallbackDistinctCount += (fixResult.htmlIslandDistinctCount ?? fallbackOccurrences.length) - fallbackOccurrences.length;
      for (const occurrence of fallbackOccurrences) {
        addFindingToGroup(groups, slug, {
          code: 'unconverted_html',
          severity: 'warning',
          snippet: occurrence.html,
          structuralFingerprint: occurrence.fingerprint,
        }, 'fallback', occurrence.count);
      }
    } else {
      fallbackOccurrenceCount += report.fallbacks.length;
      for (const finding of report.fallbacks) {
        addFindingToGroup(groups, slug, finding, 'fallback');
      }
    }
    for (const finding of report.diagnostics) {
      addFindingToGroup(groups, slug, finding, `diagnostic:${finding.code}`);
    }
  }

  const grouped = [...groups.values()]
    .map(({ sources, ...group }) => ({
      ...group,
      sourceExamples: [...sources].sort().slice(0, SOURCE_EXAMPLE_CAP),
      sharedShell: group.affectedSourceCount > 1,
    }))
    .sort((a, b) => a.fingerprint.localeCompare(b.fingerprint));

  return {
    groups: grouped,
    occurrenceCount: fallbackOccurrenceCount + reports.reduce((total, { report }) => total + report.diagnostics.length, 0),
    repairFamilyCount: grouped.length,
    repairFamilyCountTruncated: unrepresentedFallbackDistinctCount > 0,
    unrepresentedFallbackOccurrenceCount,
    unrepresentedFallbackDistinctCount,
  };
}

function addFindingToGroup(
  groups: Map<string, ThemeConversionDiagnosticGroup & { sources: Set<string> }>,
  slug: string,
  finding: ConversionFinding,
  repairBucket: string,
  occurrenceCount = 1,
): void {
  const fingerprint = findingFingerprint(finding, repairBucket);
  const group = groups.get(fingerprint);
  if (group) {
    group.occurrenceCount += occurrenceCount;
    if (!group.sources.has(slug)) {
      group.affectedSourceCount += 1;
      group.sources.add(slug);
      group.truncated = group.affectedSourceCount > SOURCE_EXAMPLE_CAP;
    }
    return;
  }

  groups.set(fingerprint, {
    fingerprint,
    repairBucket,
    code: finding.code,
    occurrenceCount,
    affectedSourceCount: 1,
    sourceExamples: [slug],
    sharedShell: false,
    truncated: false,
    sources: new Set([slug]),
  });
}

function findingFingerprint(finding: ConversionFinding, repairBucket: string): string {
  const { message: _message, snippet, ...sourceAttributes } = finding;
  const structure = JSON.stringify({
    repairBucket,
    sourceAttributes: sortObject(sourceAttributes),
    snippet: normalizeStructure(snippet),
  });
  return createHash('sha256').update(structure).digest('hex');
}

function normalizeStructure(snippet: unknown): string | undefined {
  if (typeof snippet !== 'string') return undefined;
  return snippet
    .replace(/\s+/gu, ' ')
    .replace(/(\b(?:href|data-page-path))=(['"]).*?\2/giu, '$1="{route}"')
    .replace(/\b(?:current-menu-item|current_page_item|active)\b/giu, '{active}')
    .trim();
}

function sortObject(value: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(value)
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([key, item]) => [key, item && typeof item === 'object' && !Array.isArray(item) ? sortObject(item as Record<string, unknown>) : item]),
  );
}

function joinSectionBlocks(sections: SectionBlocks[]): string {
  return sections
    .map((section) => section.blocks.trim())
    .filter(Boolean)
    .join('\n\n');
}
