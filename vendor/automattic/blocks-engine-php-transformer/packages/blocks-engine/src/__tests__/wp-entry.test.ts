import { describe, expect, it } from 'vitest';
import cases from '../__fixtures__/cases.json' with { type: 'json' };
import { bootstrap, canonicalize, convert, rawConvert } from '../wp';

type Fixture = {
  id: string;
  op: 'rawConvert' | 'canonicalize' | 'compose';
  input: string;
  expected: unknown;
};

const fixtures = cases as Fixture[];

function fixtureFor(op: 'rawConvert' | 'canonicalize'): Fixture {
  const fixture = fixtures.find((candidate) => candidate.op === op);
  if (!fixture) {
    throw new Error(`Missing ${op} fixture`);
  }
  return fixture;
}

describe('/wp entry', () => {
  it('exports the frozen public surface', () => {
    expect(typeof rawConvert).toBe('function');
    expect(typeof canonicalize).toBe('function');
    expect(typeof bootstrap).toBe('function');
    expect(typeof convert).toBe('function');
  });

  it('resolves rawConvert and canonicalize in-process', () => {
    const rawFixture = fixtureFor('rawConvert');
    const canonicalizeFixture = fixtureFor('canonicalize');

    expect(rawConvert(rawFixture.input)).toEqual(rawFixture.expected);
    expect(canonicalize(canonicalizeFixture.input)).toMatchObject(
      canonicalizeFixture.expected as Record<string, unknown>,
    );
  });
});
