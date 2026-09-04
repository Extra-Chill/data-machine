import { describe, expect, it } from 'vitest';

import expectedGoldens from '../__fixtures__/native-section-renderer-dla-goldens.json' with { type: 'json' };
import {
  runNativeSectionRendererParity,
  type NativeSectionRendererImpl,
  type NativeSectionRendererParityFile,
} from '../__fixtures__/native-section-renderer-cases.js';
import { renderImageRow, renderReviewGrid } from '../theme/native-renderers-section.js';

const EXPECTED_RENDERERS = 2;
const EXPECTED_CASES = 5;

const engineImpl: NativeSectionRendererImpl = {
  renderReviewGrid,
  renderImageRow,
};

function stableJson(value: unknown): string {
  return JSON.stringify(value, null, 2);
}

function rendererCaseCount(file: NativeSectionRendererParityFile): number {
  return Object.values(file.renderers).reduce((sum, cases) => sum + cases.length, 0);
}

function collectDiffs(actual: NativeSectionRendererParityFile, expected: NativeSectionRendererParityFile): string[] {
  const diffs: string[] = [];
  if (actual.version !== expected.version) diffs.push(`version: expected ${expected.version}, got ${actual.version}`);
  if (actual.derivation !== expected.derivation) diffs.push('derivation: changed');

  const actualRenderers = Object.keys(actual.renderers).sort();
  const expectedRenderers = Object.keys(expected.renderers).sort();
  if (stableJson(actualRenderers) !== stableJson(expectedRenderers)) {
    diffs.push(`renderer keys: expected ${expectedRenderers.join(',')}, got ${actualRenderers.join(',')}`);
  }

  for (const renderer of expectedRenderers) {
    const actualCases = actual.renderers[renderer as keyof NativeSectionRendererParityFile['renderers']];
    const expectedCases = expected.renderers[renderer as keyof NativeSectionRendererParityFile['renderers']];
    if (!actualCases) continue;
    if (stableJson(actualCases) !== stableJson(expectedCases)) {
      diffs.push(`${renderer}: output changed`);
    }
  }
  return diffs;
}

describe('native section renderer DLA-today parity', () => {
  it('matches mechanically derived DLA renderer outputs byte-for-byte and is stable on rerun', () => {
    const first = runNativeSectionRendererParity(engineImpl);
    const second = runNativeSectionRendererParity(engineImpl);
    expect(stableJson(first)).toBe(stableJson(second));

    const expected = expectedGoldens as NativeSectionRendererParityFile;
    const rendererCount = Object.keys(expected.renderers).length;
    const caseCount = rendererCaseCount(expected);
    const diffs = collectDiffs(first, expected);
    console.info(`native section renderer parity: renderers=${rendererCount} cases=${caseCount} diffs=${diffs.length}`);

    expect(rendererCount).toBe(EXPECTED_RENDERERS);
    expect(caseCount).toBe(EXPECTED_CASES);
    expect(diffs).toEqual([]);
  });
});
