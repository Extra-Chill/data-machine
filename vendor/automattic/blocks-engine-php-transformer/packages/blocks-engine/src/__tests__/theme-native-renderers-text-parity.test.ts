import { describe, expect, it } from 'vitest';

import expectedGoldens from '../__fixtures__/native-text-renderer-dla-goldens.json' with { type: 'json' };
import {
  runNativeTextRendererParity,
  type NativeTextRendererImpl,
  type NativeTextRendererParityFile,
} from '../__fixtures__/native-text-renderer-cases.js';
import {
  galleryBlock,
  renderCover,
  renderMediaText,
  renderTextBand,
} from '../theme/native-renderers-text.js';

const EXPECTED_RENDERERS = 4;
const EXPECTED_CASES = 6;

const engineImpl: NativeTextRendererImpl = {
  renderTextBand,
  renderCover,
  renderMediaText,
  galleryBlock,
};

function stableJson(value: unknown): string {
  return JSON.stringify(value, null, 2);
}

function rendererCaseCount(file: NativeTextRendererParityFile): number {
  return Object.values(file.renderers).reduce((sum, cases) => sum + cases.length, 0);
}

function collectDiffs(actual: NativeTextRendererParityFile, expected: NativeTextRendererParityFile): string[] {
  const diffs: string[] = [];
  if (actual.version !== expected.version) diffs.push(`version: expected ${expected.version}, got ${actual.version}`);
  if (actual.derivation !== expected.derivation) diffs.push('derivation: changed');

  const actualRenderers = Object.keys(actual.renderers).sort();
  const expectedRenderers = Object.keys(expected.renderers).sort();
  if (stableJson(actualRenderers) !== stableJson(expectedRenderers)) {
    diffs.push(`renderer keys: expected ${expectedRenderers.join(',')}, got ${actualRenderers.join(',')}`);
  }

  for (const renderer of expectedRenderers) {
    const actualCases = actual.renderers[renderer as keyof NativeTextRendererParityFile['renderers']];
    const expectedCases = expected.renderers[renderer as keyof NativeTextRendererParityFile['renderers']];
    if (!actualCases) continue;
    if (stableJson(actualCases) !== stableJson(expectedCases)) {
      diffs.push(`${renderer}: output changed`);
    }
  }
  return diffs;
}

describe('native text renderer DLA-today parity', () => {
  it('matches mechanically derived DLA renderer outputs byte-for-byte and is stable on rerun', () => {
    const first = runNativeTextRendererParity(engineImpl);
    const second = runNativeTextRendererParity(engineImpl);
    expect(stableJson(first)).toBe(stableJson(second));

    const expected = expectedGoldens as NativeTextRendererParityFile;
    const rendererCount = Object.keys(expected.renderers).length;
    const caseCount = rendererCaseCount(expected);
    const diffs = collectDiffs(first, expected);
    console.info(`native text renderer parity: renderers=${rendererCount} cases=${caseCount} diffs=${diffs.length}`);

    expect(rendererCount).toBe(EXPECTED_RENDERERS);
    expect(caseCount).toBe(EXPECTED_CASES);
    expect(diffs).toEqual([]);
  });
});
