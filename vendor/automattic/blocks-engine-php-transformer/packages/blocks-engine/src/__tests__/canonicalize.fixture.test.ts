import { describe, expect, it } from 'vitest';
import cases from '../__fixtures__/cases.json' with { type: 'json' };
import { canonicalize } from '../wp/canonicalize';

type CanonicalizeFixture = {
  id: string;
  op: 'canonicalize';
  input: string;
  expected: { html: string; changed: boolean; fixedIssues: string[] };
};

const canonicalizeCases = (cases as CanonicalizeFixture[]).filter(
  (fixture) => fixture.op === 'canonicalize',
);

describe('canonicalize golden fixtures', () => {
  for (const fixture of canonicalizeCases) {
    it(`${fixture.id} is byte-identical to the DLA golden`, () => {
      expect(canonicalize(fixture.input)).toMatchObject(fixture.expected);
    });
  }
});
