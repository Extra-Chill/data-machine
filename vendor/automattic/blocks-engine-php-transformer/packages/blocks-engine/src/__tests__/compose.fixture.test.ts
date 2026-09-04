import { describe, expect, it } from 'vitest';

import cases from '../__fixtures__/cases.json' with { type: 'json' };
import { compose } from '../compose';

type ComposeFixture = {
  id: string;
  op: 'compose';
  input: string;
  expected: string;
};

const composeCases = (cases as ComposeFixture[]).filter((fixture) => fixture.op === 'compose');

describe('compose golden fixtures', () => {
  for (const fixture of composeCases) {
    it(`${fixture.id} is byte-identical to the DLA golden`, () => {
      expect(compose(fixture.input, { url: 'https://x.test/' }, {})).toBe(fixture.expected);
    });
  }
});
