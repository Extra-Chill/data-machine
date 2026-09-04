import { describe, expect, it } from 'vitest';

import expectedGoldens from './fixtures/variation-hoist-dla-goldens.json' with { type: 'json' };
import {
  runVariationHoistParity,
  variationHoistCases,
} from './fixtures/variation-hoist-corpus.js';
import {
  applyHoistSwaps,
  hoistVariations,
  type HoistedVariation,
  type HoistPage,
  type HoistResult,
} from '../theme/index.js';

interface VariationHoistImpl {
  hoistVariations(pagesIn: HoistPage[], opts?: { minInstances?: number }): HoistResult;
  applyHoistSwaps(markup: string, variations: HoistedVariation[]): string;
}

interface VariationHoistCaseRecord {
  id: string;
  options: Record<string, unknown>;
  variations: HoistedVariation[];
  pages: HoistPage[];
  swappedMarkup: string;
}

interface VariationHoistParityFile {
  version: 1;
  derivation: string;
  oracle: {
    commit: string;
    path: string;
    blob: string;
  };
  cases: VariationHoistCaseRecord[];
}

const EXPECTED_CASES = 4;

const engineImpl: VariationHoistImpl = {
  hoistVariations,
  applyHoistSwaps,
};

function stableJson(value: unknown): string {
  return JSON.stringify(value, null, 2);
}

function collectDiffs(actual: VariationHoistParityFile, expected: VariationHoistParityFile): string[] {
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
    if (stableJson(actualCase.options) !== stableJson(expectedCase.options)) {
      diffs.push(`${actualCase.id}: options changed`);
    }
    if (stableJson(actualCase.variations) !== stableJson(expectedCase.variations)) {
      diffs.push(`${actualCase.id}: variations changed`);
    }
    if (stableJson(actualCase.pages) !== stableJson(expectedCase.pages)) {
      diffs.push(`${actualCase.id}: hoisted page markup changed`);
    }
    if (actualCase.swappedMarkup !== expectedCase.swappedMarkup) {
      diffs.push(`${actualCase.id}: applyHoistSwaps markup changed`);
    }
  }

  const actualIds = new Set(actual.cases.map((record) => record.id));
  for (const expectedCase of expected.cases) {
    if (!actualIds.has(expectedCase.id)) diffs.push(`${expectedCase.id}: golden has no corpus case`);
  }
  return diffs;
}

describe('variation hoist DLA parity', () => {
  it('matches checked-in DLA hoistVariations and applyHoistSwaps goldens byte-for-byte', () => {
    const first = runVariationHoistParity(engineImpl) as VariationHoistParityFile;
    const second = runVariationHoistParity(engineImpl) as VariationHoistParityFile;
    expect(stableJson(first)).toBe(stableJson(second));

    const expected = expectedGoldens as VariationHoistParityFile;
    const diffs = collectDiffs(first, expected);
    console.info(
      `variation hoist parity: cases=${first.cases.length} corpus=${variationHoistCases().length} diffs=${diffs.length}`
    );

    expect(first.cases.length).toBe(EXPECTED_CASES);
    expect(variationHoistCases()).toHaveLength(EXPECTED_CASES);
    expect(diffs).toEqual([]);
  });
});
