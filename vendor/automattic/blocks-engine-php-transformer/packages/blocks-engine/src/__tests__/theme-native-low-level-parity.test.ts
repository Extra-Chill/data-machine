import { describe, expect, it } from 'vitest';

import expectedGoldens from '../__fixtures__/native-low-level-dla-goldens.json' with { type: 'json' };
import {
  runNativeLowLevelParity,
  type NativeLowLevelImpl,
  type NativeLowLevelParityFile,
} from '../__fixtures__/native-low-level-helper-cases.js';
import {
  buttonBlock,
  column,
  columns,
  ctaButton,
  emptyNativeRenderOut,
  headingBlock,
  imageBlock,
  paragraphBlock,
  sectionButtons,
  typographyStyle,
  visibleText,
  wrapSection,
} from '../theme/native-block-builders.js';
import { brightness, nearestToken } from '../theme/native-color.js';
import { familyMatches, nearestFamily } from '../theme/native-fonts.js';
import {
  buttonJustify,
  centerOf,
  isDarkSection,
  isTintedSection,
  opaqueTintHex,
  responsiveFontSize,
  responsiveSpace,
  sectionPad,
} from '../theme/native-layout.js';
import {
  MISSING_IMAGE_PLACEHOLDER,
  iconImageBlock,
  isWpMediaUrl,
  pickLeadImage,
  recolorSvg,
  resolveImage,
} from '../theme/native-media.js';

const EXPECTED_HELPERS = 30;

const engineImpl: NativeLowLevelImpl = {
  MISSING_IMAGE_PLACEHOLDER,
  nearestToken,
  brightness,
  familyMatches,
  nearestFamily,
  responsiveFontSize,
  responsiveSpace,
  sectionPad,
  centerOf,
  buttonJustify,
  isTintedSection,
  opaqueTintHex,
  isDarkSection,
  pickLeadImage,
  isWpMediaUrl,
  recolorSvg,
  resolveImage,
  iconImageBlock,
  visibleText,
  emptyNativeRenderOut,
  typographyStyle,
  imageBlock,
  headingBlock,
  paragraphBlock,
  buttonBlock,
  ctaButton,
  sectionButtons,
  column,
  columns,
  wrapSection,
};

function stableJson(value: unknown): string {
  return JSON.stringify(value, null, 2);
}

function collectDiffs(actual: NativeLowLevelParityFile, expected: NativeLowLevelParityFile): string[] {
  const diffs: string[] = [];
  if (actual.version !== expected.version) diffs.push(`version: expected ${expected.version}, got ${actual.version}`);

  const actualHelpers = Object.keys(actual.helpers).sort();
  const expectedHelpers = Object.keys(expected.helpers).sort();
  if (stableJson(actualHelpers) !== stableJson(expectedHelpers)) {
    diffs.push(`helper keys: expected ${expectedHelpers.join(',')}, got ${actualHelpers.join(',')}`);
  }

  for (const helper of expectedHelpers) {
    const actualCases = actual.helpers[helper];
    const expectedCases = expected.helpers[helper];
    if (!actualCases) continue;
    if (stableJson(actualCases) !== stableJson(expectedCases)) {
      diffs.push(`${helper}: output changed`);
    }
  }
  return diffs;
}

describe('native low-level helper DLA-today parity', () => {
  it('matches mechanically derived DLA helper outputs byte-for-byte and is stable on rerun', () => {
    const first = runNativeLowLevelParity(engineImpl);
    const second = runNativeLowLevelParity(engineImpl);
    expect(stableJson(first)).toBe(stableJson(second));

    const expected = expectedGoldens as NativeLowLevelParityFile;
    const helperCount = Object.keys(expected.helpers).length;
    const diffs = collectDiffs(first, expected);
    console.info(`native low-level helper parity: helpers=${helperCount} diffs=${diffs.length}`);

    expect(helperCount).toBe(EXPECTED_HELPERS);
    expect(diffs).toEqual([]);
  });
});
