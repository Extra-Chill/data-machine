import { describe, expect, it } from 'vitest';

import expectedGoldens from '../__fixtures__/native-dispatch-renderer-dla-goldens.json' with { type: 'json' };
import {
  ALL_RENDER_SECTION_INTERACTION_MODELS,
  nativeDispatchRenderCtx,
  nativeDispatchRendererCaseGroups,
  runNativeDispatchRendererParity,
  type DispatchRendererName,
  type NativeDispatchRendererImpl,
  type NativeDispatchRendererParityFile,
  type NativeRenderSectionCase,
} from '../__fixtures__/native-dispatch-renderer-cases.js';
import { renderImageRow, renderReviewGrid } from '../theme/native-renderers-section.js';
import { renderCover, renderMediaText, renderTextBand } from '../theme/native-renderers-text.js';
import { cardGroup, renderCardGrid, renderCellGrid, renderFaq } from '../theme/native-renderers-grid.js';
import { renderSection } from '../theme/native-renderers-dispatch.js';
import type { NativeRenderOut } from '../theme/native-reconstruct-types.js';
import type { SectionSpec } from '../theme/section-spec.js';

const EXPECTED_RENDERERS = 5;
const EXPECTED_CASES = 36;
const PORTED_DISPATCH_TARGETS: DispatchRendererName[] = [
  'renderTextBand',
  'renderCover',
  'renderMediaText',
  'renderCardGrid',
  'renderReviewGrid',
  'renderImageRow',
  'renderFaq',
  'renderCellGrid',
];

const engineImpl: NativeDispatchRendererImpl = {
  renderCardGrid,
  renderFaq,
  renderCellGrid,
  cardGroup,
  renderSection,
};

function stableJson(value: unknown): string {
  return JSON.stringify(value, null, 2);
}

function clone<T>(value: T): T {
  if (value === undefined) return value;
  return JSON.parse(JSON.stringify(value)) as T;
}

function rendererCaseCount(file: NativeDispatchRendererParityFile): number {
  return Object.values(file.renderers).reduce((sum, cases) => sum + cases.length, 0);
}

function collectDiffs(actual: NativeDispatchRendererParityFile, expected: NativeDispatchRendererParityFile): string[] {
  const diffs: string[] = [];
  if (actual.version !== expected.version) diffs.push(`version: expected ${expected.version}, got ${actual.version}`);
  if (actual.derivation !== expected.derivation) diffs.push('derivation: changed');

  const actualRenderers = Object.keys(actual.renderers).sort();
  const expectedRenderers = Object.keys(expected.renderers).sort();
  if (stableJson(actualRenderers) !== stableJson(expectedRenderers)) {
    diffs.push(`renderer keys: expected ${expectedRenderers.join(',')}, got ${actualRenderers.join(',')}`);
  }

  for (const renderer of expectedRenderers) {
    const actualCases = actual.renderers[renderer as keyof NativeDispatchRendererParityFile['renderers']];
    const expectedCases = expected.renderers[renderer as keyof NativeDispatchRendererParityFile['renderers']];
    if (!actualCases) continue;
    if (stableJson(actualCases) !== stableJson(expectedCases)) {
      diffs.push(`${renderer}: output changed`);
    }
  }
  return diffs;
}

function renderDirectTarget(entry: NativeRenderSectionCase): NativeRenderOut {
  const section = clone(entry.section);
  const ctx = nativeDispatchRenderCtx();
  switch (entry.expectedRenderer) {
    case 'renderTextBand':
      return renderTextBand(section, ctx);
    case 'renderCover':
      return renderCover(section, ctx);
    case 'renderMediaText':
      return renderMediaText(section, entry.expectedFlip ?? false, ctx);
    case 'renderCardGrid':
      return renderCardGrid(section, section.interactionModel === 'product-card-row');
    case 'renderReviewGrid':
      return renderReviewGrid(section);
    case 'renderImageRow':
      return renderImageRow(section);
    case 'renderFaq':
      return renderFaq(section);
    case 'renderCellGrid':
      return renderCellGrid(section, ctx);
  }
}

describe('native dispatch renderer DLA-today parity', () => {
  it('matches mechanically derived DLA renderer outputs byte-for-byte and is stable on rerun', () => {
    const first = runNativeDispatchRendererParity(engineImpl);
    const second = runNativeDispatchRendererParity(engineImpl);
    expect(stableJson(first)).toBe(stableJson(second));

    const expected = expectedGoldens as NativeDispatchRendererParityFile;
    const rendererCount = Object.keys(expected.renderers).length;
    const caseCount = rendererCaseCount(expected);
    const diffs = collectDiffs(first, expected);
    console.info(`native dispatch renderer parity: renderers=${rendererCount} cases=${caseCount} diffs=${diffs.length}`);

    expect(rendererCount).toBe(EXPECTED_RENDERERS);
    expect(caseCount).toBe(EXPECTED_CASES);
    expect(diffs).toEqual([]);
  });

  it('dispatches every interactionModel to a ported engine renderer', () => {
    const cases = nativeDispatchRendererCaseGroups().renderSection;
    const coveredModels = [...new Set(cases.map((entry) => entry.section.interactionModel))].sort();
    expect(coveredModels).toEqual([...ALL_RENDER_SECTION_INTERACTION_MODELS].sort());

    for (const entry of cases) {
      expect(PORTED_DISPATCH_TARGETS).toContain(entry.expectedRenderer);
      const actual = renderSection(clone(entry.section), nativeDispatchRenderCtx());
      const direct = renderDirectTarget(entry);
      expect(stableJson(actual), `${entry.id} should dispatch to ${entry.expectedRenderer}`).toBe(stableJson(direct));
    }
  });

  it('keeps renderSection cases free of out-of-scope form and converted wiring', () => {
    const cases = nativeDispatchRendererCaseGroups().renderSection;
    expect(cases.every((entry) => !(entry.section as SectionSpec).forms?.length)).toBe(true);
    expect(cases.every((entry) => !entry.section.sectionHtml && !entry.section.styledHtml)).toBe(true);
  });
});
