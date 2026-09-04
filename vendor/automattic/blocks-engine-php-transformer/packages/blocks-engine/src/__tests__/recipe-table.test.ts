import * as cheerio from 'cheerio';
import { describe, expect, it } from 'vitest';

import { composeFromRecipes, emitRecipeBlock, type RecipeElement } from '../recipe-table';
import type { RecipeRule } from '../types';

const ctx = { url: 'https://x.test/p' };
const marker = (html: string) =>
  `<!-- wp:html {"metadata":{"name":"X"}} -->${html}<!-- /wp:html -->`;

describe('recipe-table', () => {
  it('converts matched elements and islands unmatched siblings', () => {
    const recipes: RecipeRule[] = [{ match: 'h2', block: 'core/heading', inner: 'text' }];
    const out = composeFromRecipes('<h2>Title</h2><weird-el>z</weird-el>', recipes, ctx, marker);

    expect(out).toContain('<!-- wp:heading -->');
    expect(out).toContain('Title');
    expect(out).toContain('<!-- wp:html {"metadata":{"name":"X"}} --><weird-el>z</weird-el><!-- /wp:html -->');
  });

  it('escapes comment closers in attribute JSON', () => {
    const $ = cheerio.load('<div class="card">Body</div>', null, false);
    const el = $.root().children().first().get(0) as RecipeElement;
    const rule: RecipeRule = {
      match: '.card',
      block: 'core/group',
      attrs: { label: 'a-->b' },
      inner: 'text',
    };

    const out = emitRecipeBlock($, el, rule, ctx, marker);

    expect(out).toContain('a--\\u003eb');
    expect(out).not.toContain('a-->b');
  });
});
