import { describe, expect, it } from 'vitest';
import cases from '../__fixtures__/cases.json' with { type: 'json' };
import { rawConvert } from '../wp/raw-convert';

type RawConvertFixture = {
  id: string;
  op: 'rawConvert';
  input: string;
  expected: { html: string | null; wpHtmlResidue: number };
};

const rawConvertCases = (cases as RawConvertFixture[]).filter(
  (fixture) => fixture.op === 'rawConvert',
);

describe('rawConvert golden fixtures', () => {
  for (const fixture of rawConvertCases) {
    it(`${fixture.id} is byte-identical to the DLA golden`, () => {
      expect(rawConvert(fixture.input)).toEqual(fixture.expected);
    });
  }
});
