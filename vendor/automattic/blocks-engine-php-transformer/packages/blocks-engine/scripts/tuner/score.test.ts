import { describe, expect, test } from 'vitest';
import {
  blockText,
  coverage,
  matchNode,
  parseToTree,
  parsedToExpected,
  scoreFromAxes,
  scoreTree,
  suiteHash,
  type ExpectedNode,
  type Tally,
} from './score.js';

function emptyTally(): Tally {
  return { structureTotal: 0, structureMatched: 0, contentTotal: 0, contentMatched: 0, misses: [] };
}

describe('parseToTree', () => {
  test('drops inter-block whitespace and nests inner blocks', () => {
    const markup = [
      '<!-- wp:columns -->',
      '<div class="wp-block-columns">',
      '<!-- wp:column -->',
      '<div class="wp-block-column"><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --></div>',
      '<!-- /wp:column -->',
      '</div>',
      '<!-- /wp:columns -->',
    ].join('\n');

    const tree = parseToTree(markup);

    expect(tree).toHaveLength(1);
    expect(tree[0].name).toBe('core/columns');
    expect(tree[0].children).toHaveLength(1);
    expect(tree[0].children[0].name).toBe('core/column');
    expect(tree[0].children[0].children[0].name).toBe('core/paragraph');
  });

  test('exposes parsed attributes', () => {
    const tree = parseToTree('<!-- wp:heading {"level":3} -->\n<h3>T</h3>\n<!-- /wp:heading -->');
    expect(tree[0].attrs.level).toBe(3);
  });
});

describe('blockText', () => {
  test('includes attribute values and leaf inner html', () => {
    const tree = parseToTree('<!-- wp:paragraph -->\n<p>Hello world</p>\n<!-- /wp:paragraph -->');
    expect(blockText(tree[0])).toContain('Hello world');
  });

  test('includes a container block own text (e.g. a details summary)', () => {
    const tree = parseToTree(
      '<!-- wp:details --><details class="wp-block-details"><summary>My question</summary><!-- wp:paragraph --><p>Answer</p><!-- /wp:paragraph --></details><!-- /wp:details -->',
    );
    expect(blockText(tree[0])).toContain('My question');
  });

  test('does not leak descendant block text into a container', () => {
    const tree = parseToTree(
      '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>ChildText</h2><!-- /wp:heading --></div><!-- /wp:group -->',
    );
    expect(blockText(tree[0])).not.toContain('ChildText');
  });
});

describe('matchNode', () => {
  test('counts a present block as structure-matched', () => {
    const tree = parseToTree('<!-- wp:heading -->\n<h2>T</h2>\n<!-- /wp:heading -->');
    const tally = emptyTally();
    const exp: ExpectedNode = { block: 'core/heading' };

    matchNode(exp, tree, tally, 'fix');

    expect(tally.structureTotal).toBe(1);
    expect(tally.structureMatched).toBe(1);
    expect(tally.misses).toHaveLength(0);
  });

  test('records a miss and counts the whole missing subtree', () => {
    const tree = parseToTree('<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->');
    const tally = emptyTally();
    const exp: ExpectedNode = { block: 'core/cover', children: [{ block: 'core/heading' }] };

    matchNode(exp, tree, tally, 'fix');

    expect(tally.structureMatched).toBe(0);
    expect(tally.structureTotal).toBe(2); // cover + its heading child both counted
    expect(tally.misses[0]).toMatch(/core\/cover/);
  });

  test('content assertion matches on substring', () => {
    const tree = parseToTree('<!-- wp:heading -->\n<h2>Pricing</h2>\n<!-- /wp:heading -->');
    const tally = emptyTally();
    matchNode({ block: 'core/heading', contains: 'Pricing' }, tree, tally, 'fix');
    expect(tally.contentTotal).toBe(1);
    expect(tally.contentMatched).toBe(1);
  });

  test('image asset assertion is satisfied by any image source, not exact filename', () => {
    const tree = parseToTree('<!-- wp:image {"url":"https://x/founder-2.jpg"} -->\n<figure class="wp-block-image"><img src="https://x/founder-2.jpg"/></figure>\n<!-- /wp:image -->');
    const tally = emptyTally();
    matchNode({ block: 'core/image', contains: 'founder.jpg' }, tree, tally, 'fix');
    expect(tally.contentMatched).toBe(1);
  });
});

describe('coverage', () => {
  test('is the fraction of expected words present in the output', () => {
    const expectedText = ['Alpha beta', 'Gamma'];
    const output = '<!-- wp:paragraph -->\n<p>Alpha beta delta</p>\n<!-- /wp:paragraph -->';
    // words: alpha, beta, gamma -> alpha+beta present, gamma missing => 2/3
    expect(coverage(expectedText, output)).toBeCloseTo(2 / 3, 5);
  });

  test('returns 1 when there is no expected text', () => {
    expect(coverage([], '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->')).toBe(1);
  });
});

describe('scoreFromAxes', () => {
  test('weights structure 0.75 and content 0.25', () => {
    expect(scoreFromAxes({ structurePct: 1, contentPct: 0, valid: true })).toBe(75);
    expect(scoreFromAxes({ structurePct: 1, contentPct: 1, valid: true })).toBe(100);
  });

  test('halves the score when invalid', () => {
    expect(scoreFromAxes({ structurePct: 1, contentPct: 1, valid: false })).toBe(50);
  });
});

describe('scoreTree', () => {
  test('matches multiple top-level expected nodes in order', () => {
    const output = parseToTree(
      '<!-- wp:heading --><h2>A</h2><!-- /wp:heading -->\n<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->',
    );
    const expected: ExpectedNode[] = [{ block: 'core/heading' }, { block: 'core/paragraph' }];
    const tally = scoreTree(expected, output);
    expect(tally.structureMatched).toBe(2);
    expect(tally.structureTotal).toBe(2);
  });

  test('a fully-derived (structure-only) spec scores its own output at 100', () => {
    const output = parseToTree('<!-- wp:group --><div><!-- wp:heading --><h2>A</h2><!-- /wp:heading --></div><!-- /wp:group -->');
    const expected = parsedToExpected(output);
    const tally = scoreTree(expected, output);
    expect(tally.structureMatched).toBe(tally.structureTotal);
    expect(tally.contentTotal).toBe(0); // structure-only → content axis trivially full
  });
});

describe('parsedToExpected', () => {
  test('maps names + nesting, no content assertions', () => {
    const output = parseToTree('<!-- wp:columns --><div><!-- wp:column --><div></div><!-- /wp:column --></div><!-- /wp:columns -->');
    const expected = parsedToExpected(output);
    expect(expected).toEqual([{ block: 'core/columns', children: [{ block: 'core/column' }] }]);
  });
});

describe('suiteHash', () => {
  test('is stable for the same inputs and order-sensitive', () => {
    const a = suiteHash(['spec:hero', 'spec:cta']);
    const b = suiteHash(['spec:hero', 'spec:cta']);
    const c = suiteHash(['spec:cta', 'spec:hero']);
    expect(a).toBe(b);
    expect(a).not.toBe(c);
    expect(a).toMatch(/^sha256:[0-9a-f]{12}$/);
  });
});
