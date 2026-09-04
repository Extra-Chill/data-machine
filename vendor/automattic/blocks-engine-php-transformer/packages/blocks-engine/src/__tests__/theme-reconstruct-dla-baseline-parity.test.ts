import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import {
  reconstructBaselineCases,
  type FrozenConvertedSection,
  type FrozenReconstructCase,
} from '../__fixtures__/page-reconstruct-dla-baseline.corpus.js';
import { reconstructNativeAggregate } from '../theme/reconstruct.js';
import type { SectionRenderOptions } from '../theme/native-reconstruct-types.js';

interface BaselineOutput {
  body: string;
  expectedText: string[];
  bodyText: string[];
  expectedAssets: string[];
  provenanceFlags: string[];
  fallbackDiagnostics: unknown[];
  iconAssets: Array<{ path: string; svg: string }>;
  heroIsCover: boolean;
}

interface BaselineRecord {
  id: string;
  input: {
    convertedSections: FrozenConvertedSection[];
  };
  output: BaselineOutput;
}

interface BaselineFile {
  version: 1;
  cases: BaselineRecord[];
}

const GOLDEN_PATH = fileURLToPath(
  new URL('../__fixtures__/page-reconstruct-dla-baseline.goldens.json', import.meta.url),
);

function hydrateOptions(testCase: FrozenReconstructCase): SectionRenderOptions {
  const { convertedSections, ...rest } = testCase.options;
  return {
    ...rest,
    ...(convertedSections
      ? {
          convertedSections: new Map(
            convertedSections.map((entry) => [
              entry.sectionIndex,
              { markup: entry.markup, wpHtmlResidue: entry.wpHtmlResidue },
            ]),
          ),
        }
      : {}),
  };
}

function freezeResult(testCase: FrozenReconstructCase): BaselineOutput {
  const aggregate = reconstructNativeAggregate(testCase.sections, hydrateOptions(testCase));
  return {
    body: aggregate.sectionMarkup.join('\n\n') + '\n',
    expectedText: aggregate.expectedText,
    bodyText: aggregate.bodyText,
    expectedAssets: aggregate.expectedAssets,
    provenanceFlags: aggregate.provenanceFlags,
    fallbackDiagnostics: aggregate.fallbackDiagnostics,
    iconAssets: aggregate.iconAssets,
    heroIsCover: aggregate.heroIsCover,
  };
}

function runCorpus(): BaselineFile {
  return {
    version: 1,
    cases: reconstructBaselineCases.map((testCase) => ({
      id: testCase.id,
      input: {
        convertedSections: testCase.options.convertedSections ?? [],
      },
      output: freezeResult(testCase),
    })),
  };
}

function readGolden(): BaselineFile {
  if (!existsSync(GOLDEN_PATH)) {
    throw new Error(`Missing DLA reconstruct baseline golden: ${GOLDEN_PATH}`);
  }
  return JSON.parse(readFileSync(GOLDEN_PATH, 'utf8')) as BaselineFile;
}

function stableJson(value: unknown): string {
  return JSON.stringify(value, null, 2);
}

function collectDiffs(actual: BaselineFile, expected: BaselineFile): string[] {
  const diffs: string[] = [];
  if (actual.version !== expected.version) diffs.push(`version: expected ${expected.version}, got ${actual.version}`);

  const expectedById = new Map(expected.cases.map((record) => [record.id, record]));
  for (const actualCase of actual.cases) {
    const expectedCase = expectedById.get(actualCase.id);
    if (!expectedCase) {
      diffs.push(`${actualCase.id}: missing from checked-in goldens`);
      continue;
    }
    if (stableJson(actualCase.input) !== stableJson(expectedCase.input)) {
      diffs.push(`${actualCase.id}: frozen input changed`);
    }
    if (stableJson(actualCase.output) !== stableJson(expectedCase.output)) {
      diffs.push(`${actualCase.id}: output changed`);
    }
  }

  const actualIds = new Set(actual.cases.map((record) => record.id));
  for (const expectedCase of expected.cases) {
    if (!actualIds.has(expectedCase.id)) diffs.push(`${expectedCase.id}: golden has no corpus case`);
  }
  return diffs;
}

describe('theme reconstruct DLA-today baseline parity', () => {
  it('matches the 35-case DLA baseline and is byte-stable across two runs', () => {
    const first = runCorpus();
    const second = runCorpus();
    expect(collectDiffs(first, second)).toEqual([]);

    const diffs = collectDiffs(first, readGolden());
    console.info(`Engine reconstruct DLA baseline parity: cases=${first.cases.length} diffs=${diffs.length}`);
    expect(diffs).toEqual([]);
  });
});
