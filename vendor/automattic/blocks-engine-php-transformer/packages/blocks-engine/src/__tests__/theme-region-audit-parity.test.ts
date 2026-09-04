import expectedGoldens from '../__fixtures__/region-audit-dla-goldens.json' with { type: 'json' };
import {
  regionAuditCases,
  runRegionAuditParity,
  type RegionAuditParityFile,
} from '../__fixtures__/region-audit-corpus.js';
import {
  extractSourceLandmarksFromHtml,
  landmarkRoleForHtmlRoot,
  reconcileRegions,
  selectorForHtmlRoot,
} from '../theme/index.js';
import { describe, expect, it } from 'vitest';

function stableJson(value: unknown): string {
  return JSON.stringify(value, null, 2);
}

function collectDiffs(actual: RegionAuditParityFile, expected: RegionAuditParityFile): string[] {
  const diffs: string[] = [];
  if (actual.version !== expected.version) diffs.push(`version: expected ${expected.version}, got ${actual.version}`);
  if (actual.derivation !== expected.derivation) diffs.push('derivation: changed');
  if (stableJson(actual.oracle) !== stableJson(expected.oracle)) diffs.push('oracle: changed');

  const expectedById = new Map(expected.cases.map((record) => [record.id, record]));
  for (const actualCase of actual.cases) {
    const expectedCase = expectedById.get(actualCase.id);
    if (!expectedCase) {
      diffs.push(`${actualCase.id}: missing from checked-in DLA goldens`);
      continue;
    }
    if (stableJson(actualCase.input) !== stableJson(expectedCase.input)) {
      diffs.push(`${actualCase.id}: input changed`);
    }
    if (actualCase.rootSelector !== expectedCase.rootSelector) {
      diffs.push(`${actualCase.id}: root selector changed`);
    }
    if (actualCase.rootRole !== expectedCase.rootRole) {
      diffs.push(`${actualCase.id}: root role changed`);
    }
    if (stableJson(actualCase.census) !== stableJson(expectedCase.census)) {
      diffs.push(`${actualCase.id}: census changed`);
    }
    if (stableJson(actualCase.report) !== stableJson(expectedCase.report)) {
      diffs.push(`${actualCase.id}: report changed`);
    }
  }

  const actualIds = new Set(actual.cases.map((record) => record.id));
  for (const expectedCase of expected.cases) {
    if (!actualIds.has(expectedCase.id)) diffs.push(`${expectedCase.id}: golden has no corpus case`);
  }
  return diffs;
}

describe('region audit DLA parity', () => {
  it('matches checked-in DLA region census and reconciliation goldens byte-for-byte', () => {
    const first = runRegionAuditParity({
      extractSourceLandmarksFromHtml,
      selectorForHtmlRoot,
      landmarkRoleForHtmlRoot,
      reconcileRegions,
    });
    const second = runRegionAuditParity({
      extractSourceLandmarksFromHtml,
      selectorForHtmlRoot,
      landmarkRoleForHtmlRoot,
      reconcileRegions,
    });
    expect(collectDiffs(first, second)).toEqual([]);

    const expected = expectedGoldens as unknown as RegionAuditParityFile;
    const diffs = collectDiffs(first, expected);
    console.info(`region audit parity: cases=${first.cases.length} diffs=${diffs.length}`);

    expect(first.cases.length).toBe(5);
    expect(regionAuditCases()).toHaveLength(5);
    expect(diffs).toEqual([]);
  });
});
